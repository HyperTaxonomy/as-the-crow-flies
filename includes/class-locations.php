<?php
/**
 * CRUD operations for term locations.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Locations {

	// --------------------------------------------------------------------------
	// Retrieval
	// --------------------------------------------------------------------------

	/**
	 * Get all locations for a single term.
	 *
	 * @since 1.0.0
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Optional: country, limit.
	 * @return object[]
	 */
	public static function get_for_term( int $term_id, string $taxonomy, array $args = array() ): array {
		if ( empty( $args ) ) {
			return static::get_by_term_id( $term_id );
		}
		$rows = static::get_by_term_id( $term_id );
		if ( ! empty( $args['country'] ) ) {
			$country = strtoupper( $args['country'] );
			$rows    = array_values( array_filter( $rows, static fn( $r ) => strtoupper( $r->country ?? '' ) === $country ) );
		}
		if ( ! empty( $args['limit'] ) ) {
			$rows = array_slice( $rows, 0, max( 1, (int) $args['limit'] ) );
		}
		return $rows;
	}

	/**
	 * Get locations for a single term by ID only, without requiring the taxonomy.
	 *
	 * WordPress term IDs are globally unique across all taxonomies, so the taxonomy
	 * is not needed for a single-term ID lookup.
	 *
	 * @since 1.0.0
	 * @param int $term_id Term ID.
	 * @return object[]
	 */
	public static function get_by_term_id( int $term_id ): array {
		$cached = get_transient( 'atcf_locs_' . $term_id );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$wpdb->prefix}atcf_locations l WHERE l.term_id = %d ORDER BY l.id ASC",
				$term_id
			)
		);
		$ttl = wp_using_ext_object_cache() ? YEAR_IN_SECONDS : 0;
		set_transient( 'atcf_locs_' . $term_id, $rows, $ttl );
		return $rows;
	}

	/**
	 * Normalized address identities for a term's locations.
	 *
	 * Each entry encodes the same address identity locations_match() uses for the
	 * address mode — country, postal code, and the normalized address line. A row
	 * is only included when that identity is **complete** (all three present) AND
	 * the location was **successfully geocoded** (has coordinates) — geocoding is
	 * what produces the authoritative normalized address, so requiring coordinates
	 * keeps unvalidated or partial entries from matching. Empty/incomplete or
	 * coordinate-only rows are skipped. Two locations on different sites produce
	 * the same parts only when they are the same validated address, which lets
	 * callers match terms across sites by shared location without exposing
	 * coordinates.
	 *
	 * @since 1.0.0
	 * @param int $term_id Term ID.
	 * @return array[] List of [ 'country' => string, 'postal_code' => string, 'address' => string ], de-duplicated.
	 */
	public static function get_normalized_addresses( int $term_id ): array {
		$out  = array();
		$seen = array();
		foreach ( self::get_by_term_id( $term_id ) as $loc ) {
			$country = strtoupper( trim( (string) ( $loc->country ?? '' ) ) );
			$postal  = trim( (string) ( $loc->postal_code ?? '' ) );
			$address = self::normalize_address_line1( (string) ( $loc->address_line_normalized ?? '' ) );
			$lat     = $loc->lat ?? null;
			$lng     = $loc->lng ?? null;
			// Require a complete identity and successful geocoding (coordinates).
			if ( '' === $country || '' === $postal || '' === $address
				|| ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}
			$key = $country . '|' . $postal . '|' . $address;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'country'     => $country,
				'postal_code' => $postal,
				'address'     => $address,
			);
		}
		return $out;
	}

	/**
	 * Invalidate the cached locations for a single term.
	 *
	 * @since 1.0.0
	 * @param int $term_id Term ID.
	 */
	public static function flush_term_cache( int $term_id ): void {
		delete_transient( 'atcf_locs_' . $term_id );
		do_action( 'atcf_term_location_changed', $term_id );
	}

	/**
	 * Invalidate all per-term location caches.
	 * Use for operations that affect many terms without a known term ID set.
	 *
	 * @since 1.0.0
	 */
	public static function flush_all_location_caches(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_atcf_locs_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_atcf_locs_' ) . '%'
			)
		);
	}

	/**
	 * Get locations for a set of terms in one query.
	 *
	 * @since 1.0.0
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Optional: country, orderby ('label'|'locality'|'id'), limit.
	 * @return object[]
	 */
	public static function get_for_terms( array $term_ids, string $taxonomy, array $args = array() ): array {
		if ( empty( $term_ids ) ) {
			return array();
		}
		global $wpdb;

		$id_ph  = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$where  = array( "l.term_id IN ($id_ph)", 'l.taxonomy = %s' );
		$params = array_merge( $term_ids, array( $taxonomy ) );

		if ( ! empty( $args['country'] ) ) {
			$where[]  = 'l.country = %s';
			$params[] = $args['country'];
		}

		$limit     = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 0;
		$orderby   = $args['orderby'] ?? 'id';
		switch ( $orderby ) {
			case 'label':
				$order_sql = 'l.label ASC, l.id ASC';
				break;
			case 'locality':
				$order_sql = 'l.locality ASC, l.id ASC';
				break;
			default:
				$order_sql = 'l.id ASC';
				break;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$limit_sql = $limit > 0 ? $wpdb->prepare( 'LIMIT %d', $limit ) : '';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$wpdb->prefix}atcf_locations l {$where_sql} ORDER BY {$order_sql} {$limit_sql}",
				$params
			)
		);
	}

	/**
	 * Get locations for a term and its full descendant/inclusion scope.
	 *
	 * Expands the term set to include all WP taxonomy descendants (via
	 * get_term_children), applies caller-supplied exclusion and addition lists,
	 * then returns locations for the resulting set in one query.
	 *
	 * $exclude_ids and $additional_ids are intentionally separate so callers
	 * (e.g. HT) can pass HT-hidden term IDs and HT inclusion term IDs without
	 * ATCF needing to know anything about either concept.
	 *
	 * @since 1.0.0
	 * @param int    $term_id        Root term ID.
	 * @param string $taxonomy       Taxonomy slug.
	 * @param int[]  $exclude_ids    Term IDs to remove from the scope (e.g. hidden terms).
	 * @param int[]  $additional_ids Extra term IDs to add to the scope (e.g. HT inclusions).
	 * @return object[]
	 */
	public static function get_aggregated(
		int    $term_id,
		string $taxonomy,
		array  $exclude_ids    = array(),
		array  $additional_ids = array()
	): array {
		$children = get_term_children( $term_id, $taxonomy );
		$ids      = array_merge(
			array( $term_id ),
			is_array( $children ) ? $children : array()
		);
		$ids = array_values(
			array_unique(
				array_map( 'intval', array_merge( array_diff( $ids, $exclude_ids ), $additional_ids ) )
			)
		);
		return self::get_for_terms( $ids, $taxonomy );
	}

	/**
	 * Get a single location by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Location ID.
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atcf_locations WHERE id = %d", $id )
		) ?: null;
	}

	/**
	 * Count locations for a term.
	 *
	 * @since 1.0.0
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int
	 */
	public static function count_for_term( int $term_id, string $taxonomy ): int {
		$locations = static::get_by_term_id( $term_id );
		return count( array_filter( $locations, fn( $l ) => $l->taxonomy === $taxonomy ) );
	}

	/**
	 * Get paginated locations across all terms/taxonomies.
	 *
	 * @since 1.0.0
	 * @param array $args taxonomy, term_id, country, locality, region, postal_code,
	 *                    has_coordinates (true|false|null), geocode_status,
	 *                    page, per_page, orderby.
	 * @return array{rows: object[], total: int}
	 */
	public static function get_all( array $args = array() ): array {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( ! empty( $args['taxonomy'] ) ) {
			$where[]  = 'taxonomy = %s';
			$params[] = $args['taxonomy'];
		}
		if ( ! empty( $args['term_id'] ) ) {
			$where[]  = 'term_id = %d';
			$params[] = (int) $args['term_id'];
		}
		if ( ! empty( $args['country'] ) ) {
			$where[]  = 'country = %s';
			$params[] = $args['country'];
		}
		if ( ! empty( $args['locality'] ) ) {
			$where[]  = 'locality LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['locality'] ) . '%';
		}
		if ( ! empty( $args['region'] ) ) {
			$where[]  = 'region = %s';
			$params[] = $args['region'];
		}
		if ( ! empty( $args['postal_code'] ) ) {
			$where[]  = 'postal_code = %s';
			$params[] = $args['postal_code'];
		}
		if ( isset( $args['has_coordinates'] ) && null !== $args['has_coordinates'] ) {
			$where[] = $args['has_coordinates'] ? '(lat IS NOT NULL AND lng IS NOT NULL)' : '(lat IS NULL OR lng IS NULL)';
		}
		if ( isset( $args['geocode_status'] ) && '' !== $args['geocode_status'] ) {
			if ( 'user_provided' === $args['geocode_status'] ) {
				$where[] = 'geocode_status IS NULL';
			} else {
				$where[]  = 'geocode_status = %s';
				$params[] = $args['geocode_status'];
			}
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$per_page  = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset    = ( $page - 1 ) * $per_page;
		$orderby   = $args['orderby'] ?? 'id';
		$dir       = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		switch ( $orderby ) {
			case 'label':
				$order_sql = "label {$dir}, id {$dir}";
				break;
			case 'locality':
				$order_sql = "locality {$dir}, id {$dir}";
				break;
			case 'region':
				$order_sql = "region {$dir}, id {$dir}";
				break;
			case 'postal_code':
				$order_sql = "postal_code {$dir}, id {$dir}";
				break;
			case 'country':
				$order_sql = "country {$dir}, id {$dir}";
				break;
			case 'geocode_status':
				$order_sql = "geocode_status {$dir}, id {$dir}";
				break;
			default:
				$order_sql = "id {$dir}";
				break;
		}

		$count_params = $params;
		$count_sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}atcf_locations {$where_sql}";
		$total        = (int) $wpdb->get_var(
			empty( $count_params )
				? $count_sql
				: $wpdb->prepare( $count_sql, $count_params )
		);

		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(

				"SELECT * FROM {$wpdb->prefix}atcf_locations {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				$params
			)
		);

		return array( 'rows' => $rows, 'total' => $total );
	}

	// --------------------------------------------------------------------------
	// Write
	// --------------------------------------------------------------------------

	/**
	 * Insert a new location. Returns the new ID or WP_Error.
	 *
	 * @since 1.0.0
	 * @param array $data Location data (term_id, taxonomy, and field values).
	 * @return int|WP_Error New location ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$prepared = self::prepare_data( $data );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$result = $wpdb->insert( $wpdb->prefix . 'atcf_locations', $prepared );
		if ( false === $result ) {
			return new WP_Error( 'atcf_db_error', __( 'Could not save location.', 'as-the-crow-flies' ) );
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'atcf_location_changed', $prepared['taxonomy'] ?? '' );
		static::flush_term_cache( (int) ( $prepared['term_id'] ?? 0 ) );

		// Queue for geocoding if US, no coordinates.
		if ( 'US' === ( $prepared['country'] ?? 'US' )
			&& null === ( $prepared['lat'] ?? null )
			&& ! empty( $prepared['postal_code'] ) ) {
			self::queue_for_geocoding( $id );
		}

		return $id;
	}

	/**
	 * Update an existing location. Returns true or WP_Error.
	 *
	 * @since 1.0.0
	 * @param int   $id   Location ID.
	 * @param array $data Field values to update.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->prefix}atcf_locations WHERE id = %d", $id )
		);
		$existing_taxonomy = (string) ( $existing->taxonomy ?? '' );
		$existing_term_id  = (int) ( $existing->term_id ?? 0 );

		$prepared = self::prepare_data( $data, $id );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'atcf_locations',
			$prepared,
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return new WP_Error( 'atcf_db_error', __( 'Could not update location.', 'as-the-crow-flies' ) );
		}

		do_action( 'atcf_location_changed', $existing_taxonomy ?: ( $prepared['taxonomy'] ?? '' ) );

		// Re-queue before flushing the cache so the cache reflects the new geocode_status.
		if ( 'US' === ( $prepared['country'] ?? 'US' )
			&& null === ( $prepared['lat'] ?? null )
			&& ( isset( $prepared['address_line'] ) || isset( $prepared['postal_code'] ) ) ) {
			self::queue_for_geocoding( $id );
		}

		if ( $existing_term_id > 0 ) {
			static::flush_term_cache( $existing_term_id );
		}

		return true;
	}

	/**
	 * Clear coordinates and re-queue geocoding for a batch of location IDs.
	 * Runs a single UPDATE instead of per-row queries.
	 *
	 * @param int[] $ids Location IDs to clear.
	 */
	public static function bulk_clear_coords( array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$term_ids = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE id IN ({$placeholders})",
				$ids
			)
		) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}atcf_locations
				 SET lat = NULL, lng = NULL,
				     geocode_status = CASE WHEN country = 'US' THEN 'pending' ELSE NULL END
				 WHERE id IN ({$placeholders})",
				$ids
			)
		);
		do_action( 'atcf_location_changed', '' );
		foreach ( $term_ids as $tid ) {
			static::flush_term_cache( $tid );
			// Per-term signal so listeners (e.g. TC's normalized-address URI index) refresh.
			do_action( 'atcf_term_location_changed', $tid );
		}
	}

	/**
	 * Delete a single location by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Location ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->prefix}atcf_locations WHERE id = %d", $id )
		);
		$taxonomy = (string) ( $existing->taxonomy ?? '' );
		$term_id  = (int) ( $existing->term_id ?? 0 );
		$deleted = (bool) $wpdb->delete( $wpdb->prefix . 'atcf_locations', array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) {
			if ( $taxonomy ) {
				do_action( 'atcf_location_changed', $taxonomy );
			}
			if ( $term_id > 0 ) {
				static::flush_term_cache( $term_id );
			}
		}
		return $deleted;
	}

	/**
	 * Delete all locations for a term.
	 *
	 * @since 1.0.0
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_term( int $term_id, string $taxonomy ): int {
		global $wpdb;
		$deleted = (int) $wpdb->delete(
			$wpdb->prefix . 'atcf_locations',
			array( 'term_id' => $term_id, 'taxonomy' => $taxonomy ),
			array( '%d', '%s' )
		);
		if ( $deleted > 0 ) {
			do_action( 'atcf_location_changed', $taxonomy );
			static::flush_term_cache( $term_id );
		}
		return $deleted;
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Sanitize, normalize, and auto-split address fields for a location row.
	 *
	 * @param array    $data Raw input data.
	 * @param int|null $id   Existing location ID (for updates; null for inserts).
	 * @return array|WP_Error Prepared data array.
	 */
	private static function prepare_data( array $data, ?int $id = null ) {
		$required = array( 'term_id', 'taxonomy' );
		if ( null === $id ) {
			foreach ( $required as $field ) {
				if ( empty( $data[ $field ] ) ) {
					return new WP_Error(
						'atcf_missing_field',
						/* translators: %s: field name */
						sprintf( __( 'Missing required field: %s', 'as-the-crow-flies' ), $field )
					);
				}
			}
		}

		$out = array();

		if ( isset( $data['term_id'] ) ) {
			$out['term_id'] = (int) $data['term_id'];
		}
		if ( isset( $data['taxonomy'] ) ) {
			$out['taxonomy'] = sanitize_key( $data['taxonomy'] );
		}

		$country = strtoupper( sanitize_text_field( $data['country'] ?? 'US' ) );
		if ( '' === $country ) {
			$country = 'US';
		}
		$out['country'] = $country;

		$out['label'] = sanitize_text_field( $data['label'] ?? '' );

		// Auto-split: move secondary component from address_line to address_line2.
		$address_line  = sanitize_text_field( $data['address_line'] ?? '' );
		$address_line2 = sanitize_text_field( $data['address_line2'] ?? '' );

		if ( '' === $address_line2 && '' !== $address_line ) {
			list( $address_line, $address_line2 ) = self::split_secondary_address( $address_line );
		}

		$out['address_line']  = $address_line;
		$out['address_line2'] = $address_line2;

		// Build normalized forms.
		$out['address_line_normalized']  = self::normalize_address_line1( $address_line );
		$out['address_line2_normalized'] = self::normalize_address_line2( $address_line2 );

		$out['locality']    = sanitize_text_field( $data['locality'] ?? '' );
		$out['region']      = sanitize_text_field( $data['region'] ?? '' );
		$out['postal_code'] = ATCF_Distance::normalize_postal_code(
			sanitize_text_field( $data['postal_code'] ?? '' ),
			$country
		);

		// Coordinates.
		$lat = isset( $data['lat'] ) && '' !== (string) $data['lat'] ? (float) $data['lat'] : null;
		$lng = isset( $data['lng'] ) && '' !== (string) $data['lng'] ? (float) $data['lng'] : null;

		if ( ( null !== $lat ) !== ( null !== $lng ) ) {
			return new WP_Error( 'atcf_partial_coords', __( 'Both latitude and longitude are required.', 'as-the-crow-flies' ) );
		}

		if ( null !== $lat && null !== $lng ) {
			if ( ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
				return new WP_Error( 'atcf_invalid_coords', __( 'Invalid coordinates.', 'as-the-crow-flies' ) );
			}
			$out['lat'] = $lat;
			$out['lng'] = $lng;
			// Honor explicit geocode_status from caller; otherwise mark as user-provided (null).
			$out['geocode_status'] = array_key_exists( 'geocode_status', $data ) ? $data['geocode_status'] : null;
		} else {
			$out['lat'] = null;
			$out['lng'] = null;
		}

		return $out;
	}

	/**
	 * Detect and split a secondary address component out of address_line.
	 *
	 * Returns [ address_line, address_line2 ]. address_line2 may be empty if no
	 * secondary component was found.
	 *
	 * Pattern: keyword followed by an identifier (letter/digit), optionally preceded
	 * by a comma. Building names (e.g. "Empire State Building") do NOT trigger this
	 * because "Building" is not followed by an identifier.
	 *
	 * @param string $address_line Raw address_line value.
	 * @return string[] [ $address_line, $address_line2 ]
	 */
	public static function split_secondary_address( string $address_line ): array {
		$pattern = '/,?\s*(Suite|Ste\.?|Apartment|Apt\.?|Building|Bldg\.?|Floor|Fl\.?|Room|Rm\.?|Unit|Dept\.?|Department|#)\s+\S+.*$/i';

		if ( preg_match( $pattern, $address_line, $matches, PREG_OFFSET_CAPTURE ) ) {
			$offset       = $matches[0][1];
			$address_line2 = trim( preg_replace( '/^,\s*/', '', $matches[0][0] ) );
			$address_line  = trim( substr( $address_line, 0, $offset ) );
			return array( $address_line, $address_line2 );
		}

		return array( $address_line, '' );
	}

	/**
	 * DIY normalization for address_line (line 1).
	 * Used when the Census-returned form is not yet available.
	 *
	 * @param string $line Raw address line 1.
	 * @return string
	 */
	public static function normalize_address_line1( string $line ): string {
		if ( '' === $line ) {
			return '';
		}

		$line = strtolower( trim( $line ) );
		$line = preg_replace( '/\s+/', ' ', $line );
		$line = preg_replace( '/[.,]/', '', $line );

		// Expand common street abbreviations.
		$abbrevs = array(
			'\bst\b'   => 'street',
			'\bave\b'  => 'avenue',
			'\bblvd\b' => 'boulevard',
			'\bdr\b'   => 'drive',
			'\brd\b'   => 'road',
			'\bln\b'   => 'lane',
			'\bct\b'   => 'court',
			'\bpl\b'   => 'place',
			'\bpkwy\b' => 'parkway',
			'\bhwy\b'  => 'highway',
			'\bn\b'    => 'north',
			'\bs\b'    => 'south',
			'\be\b'    => 'east',
			'\bw\b'    => 'west',
			'\bnw\b'   => 'northwest',
			'\bne\b'   => 'northeast',
			'\bsw\b'   => 'southwest',
			'\bse\b'   => 'southeast',
		);

		foreach ( $abbrevs as $pattern => $replacement ) {
			$line = preg_replace( '/' . $pattern . '/i', $replacement, $line );
		}

		return trim( $line );
	}

	/**
	 * DIY normalization for address_line2 — expand secondary address abbreviations.
	 *
	 * @param string $line Raw address line 2.
	 * @return string
	 */
	public static function normalize_address_line2( string $line ): string {
		if ( '' === $line ) {
			return '';
		}

		$line = strtolower( trim( $line ) );
		$line = preg_replace( '/\s+/', ' ', $line );
		$line = preg_replace( '/[.,]/', '', $line );

		$abbrevs = array(
			'\bste\b'        => 'suite',
			'\bapt\b'        => 'apt',
			'\bapartment\b'  => 'apt',
			'\bbldg\b'       => 'building',
			'\bfl\b'         => 'floor',
			'\brm\b'         => 'room',
			'#'              => 'unit',
			'\bdept\b'       => 'dept',
			'\bdepartment\b' => 'dept',
		);

		foreach ( $abbrevs as $pattern => $replacement ) {
			if ( '#' === $pattern ) {
				$line = str_replace( '#', 'unit ', $line );
			} else {
				$line = preg_replace( '/' . $pattern . '/i', $replacement, $line );
			}
		}

		return preg_replace( '/\s+/', ' ', trim( $line ) );
	}

	/**
	 * Update a location's normalized address_line from the Census-returned street form.
	 *
	 * Called by the geocoder after a successful match.
	 *
	 * @param int    $id               Location ID.
	 * @param string $normalized_street Census-returned street portion (e.g. "350 5TH AVE").
	 */
	public static function set_normalized_address( int $id, string $normalized_street ): void {
		global $wpdb;
		$term_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT term_id FROM {$wpdb->prefix}atcf_locations WHERE id = %d", $id )
		);
		$wpdb->update(
			$wpdb->prefix . 'atcf_locations',
			// Normalize the geocoder's returned street the same way user-entered
			// lines are normalized, so both sources compare consistently.
			array( 'address_line_normalized' => self::normalize_address_line1( $normalized_street ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( $term_id > 0 ) {
			static::flush_term_cache( $term_id );
		}
	}

	/**
	 * Mark a location's geocode_status and optionally set coordinates.
	 *
	 * @param int        $id     Location ID.
	 * @param string     $status New geocode_status value.
	 * @param float|null $lat    Latitude (null to leave unchanged).
	 * @param float|null $lng    Longitude (null to leave unchanged).
	 */
	public static function set_geocode_result( int $id, string $status, ?float $lat = null, ?float $lng = null ): void {
		global $wpdb;

		$term_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT term_id FROM {$wpdb->prefix}atcf_locations WHERE id = %d", $id )
		);

		$data   = array( 'geocode_status' => $status );
		$format = array( '%s' );

		if ( null !== $lat && null !== $lng ) {
			$data['lat'] = $lat;
			$data['lng'] = $lng;
			$format[]    = '%.7f';
			$format[]    = '%.7f';
		}

		$wpdb->update(
			$wpdb->prefix . 'atcf_locations',
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( $term_id > 0 ) {
			static::flush_term_cache( $term_id );
		}
	}

	/**
	 * Queue a location for Census geocoding.
	 *
	 * @param int $id Location ID.
	 */
	public static function queue_for_geocoding( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'atcf_locations',
			array( 'geocode_status' => 'pending' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Return IDs of pending US locations (no lat/lng, geocode_status = 'pending').
	 *
	 * @param int $limit Max rows to return.
	 * @return int[]
	 */
	public static function get_pending_geocode_ids( int $limit = 9999 ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atcf_locations
				 WHERE country = 'US' AND geocode_status = 'pending' AND lat IS NULL
				 LIMIT %d",
				$limit
			)
		);
		return array_map( 'intval', $rows );
	}

	/**
	 * Count non-US locations that have no coordinates.
	 * Used to generate the post-import admin notice.
	 *
	 * @return int
	 */
	public static function count_non_us_without_coords(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atcf_locations
				 WHERE country != %s AND lat IS NULL",
				'US'
			)
		);
	}

	// --------------------------------------------------------------------------
	// Term lifecycle
	// --------------------------------------------------------------------------

	/**
	 * Compare two location rows and determine whether they represent the same place.
	 *
	 * Uses the site's Location Matching settings (Settings → General → Location Matching):
	 *   - atcf_location_match_mode:          'address' | 'coords' | 'either' | 'both'
	 *   - atcf_location_match_radius:        numeric radius, in feet (miles mode) or meters (km mode)
	 *   - atcf_match_require_label_address:  if true, labels must also agree for an address hit
	 *   - atcf_match_require_label_coords:   if true, labels must also agree for a coordinate hit
	 *
	 * Address match: same country, postal_code, and address_line_normalized.
	 * Coordinate match: both rows have coordinates and they fall within the configured radius.
	 *
	 * @since 1.0.0
	 * @param object $a First location row.
	 * @param object $b Second location row.
	 * @return bool
	 */
	public static function locations_match( object $a, object $b ): bool {
		$mode                = (string) get_option( 'atcf_location_match_mode', 'either' );
		$radius              = (float)  get_option( 'atcf_location_match_radius', 82.0 );
		$unit                = (string) get_option( 'atcf_distance_units', 'miles' );
		$require_label_addr  = (bool)   get_option( 'atcf_match_require_label_address', false );
		$require_label_coord = (bool)   get_option( 'atcf_match_require_label_coords', false );

		$addr_match = false;
		if ( 'coords' !== $mode ) {
			$addr_match = '' !== ( $a->country ?? '' )
				&& strtoupper( $a->country ) === strtoupper( $b->country ?? '' )
				&& '' !== ( $a->postal_code ?? '' )
				&& $a->postal_code === ( $b->postal_code ?? '' )
				&& '' !== ( $a->address_line_normalized ?? '' )
				&& $a->address_line_normalized === ( $b->address_line_normalized ?? '' );

			if ( $addr_match && $require_label_addr ) {
				$addr_match = ( $a->label ?? '' ) === ( $b->label ?? '' );
			}
		}

		$coord_match = false;
		if ( 'address' !== $mode ) {
			$a_lat = isset( $a->lat ) && null !== $a->lat ? (float) $a->lat : null;
			$a_lng = isset( $a->lng ) && null !== $a->lng ? (float) $a->lng : null;
			$b_lat = isset( $b->lat ) && null !== $b->lat ? (float) $b->lat : null;
			$b_lng = isset( $b->lng ) && null !== $b->lng ? (float) $b->lng : null;

			if ( null !== $a_lat && null !== $a_lng && null !== $b_lat && null !== $b_lng ) {
				$dist_km     = ATCF_Distance::great_circle_distance( $a_lat, $a_lng, $b_lat, $b_lng );
				$limit_km    = 'miles' === $unit ? $radius / 3280.84 : $radius / 1000.0;
				$coord_match = $dist_km <= $limit_km;

				if ( $coord_match && $require_label_coord ) {
					$coord_match = ( $a->label ?? '' ) === ( $b->label ?? '' );
				}
			}
		}

		switch ( $mode ) {
			case 'address':
				return $addr_match;
			case 'coords':
				return $coord_match;
			case 'both':
				return $addr_match && $coord_match;
			default:
				return $addr_match || $coord_match;
		}
	}

	/**
	 * Delete location rows whose term_id no longer corresponds to a WordPress term.
	 *
	 * Intended to run on plugin activation to remove rows left behind by term
	 * deletions that occurred before this integration was in place.
	 *
	 * @since 1.0.0
	 * @return int Total number of rows deleted.
	 */
	public static function delete_orphans(): int {
		global $wpdb;

		$term_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations"
			)
		);

		if ( empty( $term_ids ) ) {
			return 0;
		}

		// Resolve which of these term IDs still exist in a single query; the rest are
		// orphans. Avoids one get_term() per distinct term_id.
		$existing = array_map(
			'intval',
			(array) get_terms(
				array(
					'include'    => $term_ids,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);
		$orphans = array_values( array_diff( $term_ids, $existing ) );
		if ( empty( $orphans ) ) {
			return 0;
		}

		// Delete all orphan rows in one statement.
		$placeholders = implode( ',', array_fill( 0, count( $orphans ), '%d' ) );
		$deleted      = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}atcf_locations WHERE term_id IN ({$placeholders})",
				$orphans
			)
		);

		// Spans multiple (possibly all) taxonomies, so signal unscoped, same as
		// bulk_clear_coords() — any atcf_query result cache must be flushed too.
		do_action( 'atcf_location_changed', '' );
		foreach ( $orphans as $term_id ) {
			static::flush_term_cache( $term_id );
		}

		return $deleted;
	}
}
