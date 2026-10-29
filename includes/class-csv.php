<?php
/**
 * CSV import and export for location data.
 *
 * Handles both formats:
 * - Term-scoped: no taxonomy/slug columns; term identified by context.
 * - Global: taxonomy + term_slug columns to identify the target term.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_CSV {

	// Column names recognised in CSV headers (lowercase).
	const KNOWN_COLUMNS = array(
		'taxonomy', 'term_slug', 'country', 'label',
		'address_line', 'address_line2', 'locality', 'region',
		'postal_code', 'lat', 'lng',
	);

	// --------------------------------------------------------------------------
	// Term-scoped import
	// --------------------------------------------------------------------------

	/**
	 * Import a term-scoped CSV file.
	 *
	 * @param string $tmp_path    Path to the uploaded file.
	 * @param int    $term_id     Term ID (from context; CSV may optionally include term_slug).
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $mode        'update', 'skip', or 'reject'.
	 * @param array  $conflict    Which criteria to check: address, label, coords.
	 * @return array|WP_Error     Array with inserted, updated, skipped counts.
	 */
	public static function import_term_scoped( string $tmp_path, int $term_id, string $taxonomy, string $mode, array $conflict ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'atcf_invalid_term', __( 'Term not found.', 'as-the-crow-flies' ) );
		}

		$handle = self::open_file( $tmp_path );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		list( $header_map, $first_data_row ) = self::detect_header( $handle );

		$rows    = array();
		$errors  = array();
		$row_num = $header_map ? 2 : 1;

		$first_row = $first_data_row;
		while ( true ) {
			if ( null !== $first_row ) {
				$raw      = $first_row;
				$first_row = null;
			} else {
				$raw = fgetcsv( $handle );
				if ( false === $raw ) {
					break;
				}
			}

			if ( empty( array_filter( $raw ) ) ) {
				$row_num++;
				continue;
			}

			$data = self::map_row( $raw, $header_map, false );

			// Validate term_slug if present.
			if ( ! empty( $data['term_slug'] ) && $data['term_slug'] !== $term->slug ) {
				$errors[] = sprintf(
					/* translators: 1: row number, 2: slug in CSV, 3: expected slug */
					__( 'Row %1$d: term_slug "%2$s" does not match current term "%3$s".', 'as-the-crow-flies' ),
					$row_num, $data['term_slug'], $term->slug
				);
			}

			$data['term_id']  = $term_id;
			$data['taxonomy'] = $taxonomy;
			$rows[]           = array( 'row_num' => $row_num, 'data' => $data );
			$row_num++;
		}

		fclose( $handle );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'atcf_csv_errors', implode( "\n", $errors ) );
		}

		return self::process_rows( $rows, $mode, $conflict );
	}

	// --------------------------------------------------------------------------
	// Global import
	// --------------------------------------------------------------------------

	/**
	 * Import a global CSV file (taxonomy + term_slug columns).
	 *
	 * @param string $tmp_path Path to the uploaded file.
	 * @param string $mode     'update', 'skip', or 'reject'.
	 * @param array  $conflict Which criteria to check.
	 * @return array|WP_Error  Array with inserted, updated, skipped counts; or WP_Error.
	 */
	public static function import_global( string $tmp_path, string $mode, array $conflict ) {
		$handle = self::open_file( $tmp_path );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		list( $header_map, $first_data_row ) = self::detect_header( $handle );

		$rows            = array();
		$errors          = array();
		$unknown_skipped = 0;
		$row_num         = $header_map ? 2 : 1;
		$first_row       = $first_data_row;

		while ( true ) {
			if ( null !== $first_row ) {
				$raw       = $first_row;
				$first_row = null;
			} else {
				$raw = fgetcsv( $handle );
				if ( false === $raw ) {
					break;
				}
			}

			if ( empty( array_filter( $raw ) ) ) {
				$row_num++;
				continue;
			}

			$data = self::map_row( $raw, $header_map, true );

			$taxonomy    = sanitize_key( $data['taxonomy'] ?? '' );
			$term_slug   = sanitize_title( $data['term_slug'] ?? '' );

			if ( '' === $taxonomy || '' === $term_slug ) {
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: taxonomy and term_slug are required.', 'as-the-crow-flies' ),
					$row_num
				);
				$row_num++;
				continue;
			}

			$term = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( ! $term ) {
				// Skip rows for terms that don't exist on this site; count as skipped.
				$unknown_skipped++;
				$row_num++;
				continue;
			}

			$data['term_id']  = $term->term_id;
			$data['taxonomy'] = $term->taxonomy;
			$rows[]           = array( 'row_num' => $row_num, 'data' => $data );
			$row_num++;
		}

		fclose( $handle );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'atcf_csv_errors', implode( "\n", $errors ) );
		}

		$result = self::process_rows( $rows, $mode, $conflict );
		if ( ! is_wp_error( $result ) ) {
			$result['unknown_skipped'] = $unknown_skipped;
		}
		return $result;
	}

	// --------------------------------------------------------------------------
	// Internal: process validated rows
	// --------------------------------------------------------------------------

	/**
	 * Insert/update/skip rows based on conflict detection mode.
	 *
	 * @param array[] $rows     Each element: [ row_num => int, data => array ].
	 * @param string  $mode     'update', 'skip', or 'reject'.
	 * @param array   $conflict Which criteria to check.
	 * @return array|WP_Error   inserted, updated, skipped counts.
	 */
	private static function process_rows( array $rows, string $mode, array $conflict ) {
		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;
		$errors   = 0;

		// Note: the conflict check below is a read-then-write pattern (TOCTOU). A concurrent
		// import that runs between find_conflict() and the subsequent insert/update could
		// produce duplicate rows. This is acceptable because the import UI is single-user
		// and concurrent CSV imports are not a supported use case.

		// In reject mode: scan all rows for conflicts before touching the DB.
		if ( 'reject' === $mode ) {
			$conflicts_found = array();
			foreach ( $rows as $entry ) {
				$data    = $entry['data'];
				$term_id = (int) $data['term_id'];
				$existing = self::find_conflict( $term_id, $data['taxonomy'], $data, $conflict );
				if ( null !== $existing ) {
					$conflicts_found[] = $entry['row_num'];
				}
			}
			if ( ! empty( $conflicts_found ) ) {
				return new WP_Error(
					'atcf_conflicts',
					sprintf(
						/* translators: %s: comma-separated row numbers */
						__( 'Conflicts found on rows: %s. Upload rejected.', 'as-the-crow-flies' ),
						implode( ', ', $conflicts_found )
					)
				);
			}
		}

		foreach ( $rows as $entry ) {
			$data    = $entry['data'];
			$term_id = (int) $data['term_id'];
			$taxonomy = $data['taxonomy'];

			$existing = self::find_conflict( $term_id, $taxonomy, $data, $conflict );

			if ( null !== $existing ) {
				if ( 'skip' === $mode ) {
					$skipped++;
					continue;
				}
				// 'update'.
				$result = ATCF_Locations::update( (int) $existing->id, $data );
				if ( is_wp_error( $result ) ) {
					$errors++;
				} else {
					$updated++;
				}
			} else {
				$result = ATCF_Locations::insert( $data );
				if ( is_wp_error( $result ) ) {
					$errors++;
				} else {
					$inserted++;
				}
			}
		}

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Find an existing location that conflicts with a given data row.
	 *
	 * OR logic: any checked criterion matching triggers a conflict.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $data     Incoming row data.
	 * @param array  $conflict Criteria flags: address, label, coords, coords_mode ('exact'|'within'), coords_radius (feet when units=miles, meters when units=km).
	 * @return object|null Existing location row, or null if no conflict.
	 */
	private static function find_conflict( int $term_id, string $taxonomy, array $data, array $conflict ): ?object {
		$existing = ATCF_Locations::get_for_term( $term_id, $taxonomy );

		foreach ( $existing as $loc ) {
			// Address conflict: same normalized address_line + postal_code + country.
			if ( ! empty( $conflict['address'] ) ) {
				$new_line1 = ATCF_Locations::normalize_address_line1( $data['address_line'] ?? '' );
				$new_pc    = ATCF_Distance::normalize_postal_code( $data['postal_code'] ?? '', $data['country'] ?? 'US' );
				$new_ctry  = strtoupper( $data['country'] ?? 'US' );

				if ( '' !== $new_line1 && '' !== $new_pc
					&& $new_line1  === $loc->address_line_normalized
					&& $new_pc     === $loc->postal_code
					&& $new_ctry   === $loc->country ) {
					return $loc;
				}
			}

			// Label conflict.
			if ( ! empty( $conflict['label'] ) && ! empty( $data['label'] ) ) {
				if ( $data['label'] === $loc->label ) {
					return $loc;
				}
			}

			// Coordinate conflict: both sides must have non-null lat/lng.
			if ( ! empty( $conflict['coords'] )
				&& null !== $loc->lat && null !== $loc->lng
				&& null !== $data['lat'] && null !== $data['lng'] ) {
				if ( 'within' === ( $conflict['coords_mode'] ?? 'exact' ) && ! empty( $conflict['coords_radius'] ) ) {
					$dist_km   = ATCF_Distance::great_circle_distance(
						(float) $data['lat'], (float) $data['lng'],
						(float) $loc->lat, (float) $loc->lng
					);
					$units     = (string) get_option( 'atcf_distance_units', 'miles' );
					// coords_radius is in feet (miles mode) or meters (km mode).
					$radius_km = 'km' === $units
						? (float) $conflict['coords_radius'] / 1000.0
						: (float) $conflict['coords_radius'] / 3280.84;
					if ( $dist_km <= $radius_km ) {
						return $loc;
					}
				} elseif ( abs( round( (float) $data['lat'], 7 ) - round( (float) $loc->lat, 7 ) ) < 1e-7
					&& abs( round( (float) $data['lng'], 7 ) - round( (float) $loc->lng, 7 ) ) < 1e-7 ) {
					return $loc;
				}
			}
		}

		return null;
	}

	// --------------------------------------------------------------------------
	// Header detection and row mapping
	// --------------------------------------------------------------------------

	/**
	 * Detect whether the first row is a header by matching column names.
	 *
	 * @param resource $handle Open file handle (position at start).
	 * @return array [ header_map (array|null), first_data_row (array|null) ]
	 */
	private static function detect_header( $handle ): array {
		$first_row = fgetcsv( $handle );
		if ( false === $first_row ) {
			return array( null, null );
		}

		$lower  = array_map( 'strtolower', array_map( 'trim', $first_row ) );
		$matches = array_intersect( $lower, self::KNOWN_COLUMNS );

		if ( count( $matches ) >= 2 ) {
			// Looks like a header row — build column name → index map.
			$map = array();
			foreach ( $lower as $i => $col ) {
				if ( in_array( $col, self::KNOWN_COLUMNS, true ) ) {
					$map[ $col ] = $i;
				}
			}
			return array( $map, null );
		}

		// No header — fixed order: label, address_line, address_line2, locality, region, postal_code, country, lat, lng
		return array( null, $first_row );
	}

	/**
	 * Map a CSV row to a data array using header map or fixed column order.
	 *
	 * @param string[]   $raw        Raw CSV columns.
	 * @param array|null $header_map Column name → index map; null = fixed order.
	 * @param bool       $global     True if global format (includes taxonomy + term_slug).
	 * @return array
	 */
	private static function map_row( array $raw, ?array $header_map, bool $global ): array {
		if ( null !== $header_map ) {
			$get = fn( string $col ) => isset( $header_map[ $col ] ) ? trim( $raw[ $header_map[ $col ] ] ?? '' ) : '';
		} else {
			// Fixed order for term-scoped: label, address_line, address_line2, locality, region, postal_code, country, lat, lng
			// Fixed order for global: taxonomy, term_slug, country, label, address_line, address_line2, locality, region, postal_code, lat, lng
			if ( $global ) {
				$get = function ( string $col ) use ( $raw ) {
					switch ( $col ) {
						case 'taxonomy':
							$val = $raw[0] ?? '';
							break;
						case 'term_slug':
							$val = $raw[1] ?? '';
							break;
						case 'country':
							$val = $raw[2] ?? '';
							break;
						case 'label':
							$val = $raw[3] ?? '';
							break;
						case 'address_line':
							$val = $raw[4] ?? '';
							break;
						case 'address_line2':
							$val = $raw[5] ?? '';
							break;
						case 'locality':
							$val = $raw[6] ?? '';
							break;
						case 'region':
							$val = $raw[7] ?? '';
							break;
						case 'postal_code':
							$val = $raw[8] ?? '';
							break;
						case 'lat':
							$val = $raw[9] ?? '';
							break;
						case 'lng':
							$val = $raw[10] ?? '';
							break;
						default:
							$val = '';
							break;
					}
					return trim( $val );
				};
			} else {
				$get = function ( string $col ) use ( $raw ) {
					switch ( $col ) {
						case 'label':
							$val = $raw[0] ?? '';
							break;
						case 'address_line':
							$val = $raw[1] ?? '';
							break;
						case 'address_line2':
							$val = $raw[2] ?? '';
							break;
						case 'locality':
							$val = $raw[3] ?? '';
							break;
						case 'region':
							$val = $raw[4] ?? '';
							break;
						case 'postal_code':
							$val = $raw[5] ?? '';
							break;
						case 'country':
							$val = $raw[6] ?? '';
							break;
						case 'lat':
							$val = $raw[7] ?? '';
							break;
						case 'lng':
							$val = $raw[8] ?? '';
							break;
						default:
							$val = '';
							break;
					}
					return trim( $val );
				};
			}
		}

		$data = array(
			'label'         => sanitize_text_field( wp_unslash( $get( 'label' ) ) ),
			'address_line'  => sanitize_text_field( wp_unslash( $get( 'address_line' ) ) ),
			'address_line2' => sanitize_text_field( wp_unslash( $get( 'address_line2' ) ) ),
			'locality'      => sanitize_text_field( wp_unslash( $get( 'locality' ) ) ),
			'region'        => sanitize_text_field( wp_unslash( $get( 'region' ) ) ),
			'postal_code'   => sanitize_text_field( wp_unslash( $get( 'postal_code' ) ) ),
			'country'       => strtoupper( sanitize_text_field( $get( 'country' ) ) ) ?: 'US',
			'lat'           => '' !== $get( 'lat' ) ? (float) $get( 'lat' ) : null,
			'lng'           => '' !== $get( 'lng' ) ? (float) $get( 'lng' ) : null,
		);

		// Always extract term_slug so import_term_scoped() can validate it when present in the header.
		$data['term_slug'] = sanitize_title( $get( 'term_slug' ) );

		if ( $global ) {
			$data['taxonomy'] = sanitize_key( $get( 'taxonomy' ) );
		}

		return $data;
	}

	// --------------------------------------------------------------------------
	// Utilities
	// --------------------------------------------------------------------------

	/**
	 * Open a file for reading, returning WP_Error on failure.
	 *
	 * @param string $path File path.
	 * @return resource|WP_Error
	 */
	private static function open_file( string $path ) {
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open file for reading.', 'as-the-crow-flies' ) );
		}
		return $handle;
	}
}
