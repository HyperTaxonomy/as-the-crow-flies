<?php
/**
 * Plugin activation: table creation and initial data import.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();
		self::import_us_centroids();
		self::schedule_events();
		ATCF_Locations::delete_orphans();
	}

	/**
	 * Create plugin database tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$locations_sql = "CREATE TABLE {$wpdb->prefix}atcf_locations (
			id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id                  BIGINT UNSIGNED NOT NULL,
			taxonomy                 VARCHAR(32)     NOT NULL,
			country                  CHAR(2)         NOT NULL DEFAULT 'US',
			label                    VARCHAR(200)    NOT NULL DEFAULT '',
			address_line             VARCHAR(255)    NOT NULL DEFAULT '',
			address_line2            VARCHAR(255)    NOT NULL DEFAULT '',
			address_line_normalized  VARCHAR(255)    NOT NULL DEFAULT '',
			address_line2_normalized VARCHAR(255)    NOT NULL DEFAULT '',
			locality                 VARCHAR(100)    NOT NULL DEFAULT '',
			region                   VARCHAR(50)     NOT NULL DEFAULT '',
			postal_code              VARCHAR(10)     NOT NULL DEFAULT '',
			lat                      DECIMAL(10,7)   DEFAULT NULL,
			lng                      DECIMAL(10,7)   DEFAULT NULL,
			geocode_status           VARCHAR(20)     DEFAULT NULL,
			PRIMARY KEY (id),
			KEY term_taxonomy (term_id, taxonomy),
			KEY country_postal (country, postal_code)
		) $charset_collate;";

		$centroids_sql = "CREATE TABLE {$wpdb->prefix}atcf_postal_centroids (
			country      CHAR(2)       NOT NULL DEFAULT 'US',
			postal_code  VARCHAR(10)   NOT NULL,
			lat          DECIMAL(10,7) NOT NULL,
			lng          DECIMAL(10,7) NOT NULL,
			PRIMARY KEY (country, postal_code)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $locations_sql );
		dbDelta( $centroids_sql );

		update_option( 'atcf_db_version', ATCF_VERSION );

		add_option( 'atcf_distance_units',        'miles' );
		add_option( 'atcf_location_match_mode',  'either' );
		add_option( 'atcf_ip_geo_enabled',        1 );
		add_option( 'atcf_ip_geo_ttl',            86400 );
		add_option( 'atcf_expose_locations_api',  1 );
	}

	/**
	 * Import bundled US Zip code centroid data on first activation.
	 *
	 * Reads data/postal-centroids.csv (postal_code, lat, lng) using the same
	 * CSV importer as the admin upload, avoiding raw SQL execution.
	 */
	public static function import_us_centroids(): void {
		global $wpdb;

		// Skip if US rows already exist.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atcf_postal_centroids WHERE country = %s LIMIT 1",
				'US'
			)
		);
		if ( $count > 0 ) {
			return;
		}

		$csv_file = ATCF_PLUGIN_DIR . 'data/postal-centroids.csv';
		if ( ! file_exists( $csv_file ) ) {
			return;
		}

		self::import_zcta_file( $csv_file );
	}

	/**
	 * Re-import US centroids from the bundled CSV file, replacing existing rows.
	 */
	public static function reimport_us_centroids(): void {
		$csv_file = ATCF_PLUGIN_DIR . 'data/postal-centroids.csv';
		if ( file_exists( $csv_file ) ) {
			self::import_zcta_file( $csv_file );
		}
		update_option( 'atcf_us_centroids_imported', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Schedule the geocoding cron event if not already scheduled.
	 */
	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'atcf_geocode_pending' ) ) {
			wp_schedule_event( time(), 'hourly', 'atcf_geocode_pending' );
		}
	}

	/**
	 * Clean up on deactivation (cron only; tables and data preserved).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'atcf_geocode_pending' );
	}

	/**
	 * Return the count of rows in the centroids table for a given country.
	 *
	 * @param string $country ISO 3166-1 alpha-2 country code.
	 * @return int
	 */
	public static function get_centroid_count( string $country ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atcf_postal_centroids WHERE country = %s",
				$country
			)
		);
	}

	/**
	 * Import centroids from a GeoNames TSV file for a given country.
	 * Replaces all existing rows for that country.
	 *
	 * GeoNames format columns (tab-separated):
	 * 0: country_code, 1: postal_code, 2: place_name, 3: admin_name1, 4: admin_code1,
	 * 5: admin_name2, 6: admin_code2, 7: admin_name3, 8: admin_code3,
	 * 9: latitude, 10: longitude, 11: accuracy
	 *
	 * @param string $country  ISO 3166-1 alpha-2 country code.
	 * @param string $tmp_path Path to the uploaded TSV file.
	 * @return int|WP_Error Number of rows imported, or WP_Error on failure.
	 */
	public static function import_geonames_file( string $country, string $tmp_path ) {
		global $wpdb;

		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open uploaded file.', 'as-the-crow-flies' ) );
		}

		$batch        = array();
		$imported     = 0;
		$batch_max    = 500;
		$did_truncate = false;

		while ( ( $row = fgetcsv( $handle, 1000, "\t" ) ) !== false ) {
			if ( count( $row ) < 11 ) {
				continue;
			}

			$postal_code = ATCF_Distance::normalize_postal_code( trim( $row[1] ), $country );
			$lat         = (float) $row[9];
			$lng         = (float) $row[10];

			if ( '' === $postal_code || ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
				continue;
			}

			$batch[] = $wpdb->prepare( '(%s, %s, %.7f, %.7f)', $country, $postal_code, $lat, $lng );
			$imported++;

			if ( count( $batch ) >= $batch_max ) {
				// Delete existing data only on the first successful batch so that a
				// bad file never wipes data before any valid rows are confirmed.
				if ( ! $did_truncate ) {
					$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => $country ) );
					$did_truncate = true;
				}
				$wpdb->query(
					"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
				);
				$batch = array();
			}
		}

		fclose( $handle );

		if ( ! empty( $batch ) ) {
			if ( ! $did_truncate ) {
				$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => $country ) );
			}
			$wpdb->query(
				"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
			);
		}

		if ( 0 === $imported ) {
			return new WP_Error( 'atcf_no_rows', __( 'No valid rows found in the uploaded file.', 'as-the-crow-flies' ) );
		}

		update_option(
			'atcf_country_centroids',
			array_merge(
				get_option( 'atcf_country_centroids', array() ),
				array(
					$country => array(
						'count'    => $imported,
						'imported' => gmdate( 'Y-m-d H:i:s' ),
						'enabled'  => true,
					),
				)
			)
		);

		return $imported;
	}

	/**
	 * Download the Census ZCTA Gazetteer zip from census.gov, extract the TSV,
	 * and import into the centroids table.
	 *
	 * The 2020 Gazetteer has these tab-delimited columns:
	 *   GEOID  ALAND  AWATER  ALAND_SQMI  AWATER_SQMI  INTPTLAT  INTPTLONG
	 * Column detection in import_zcta_file() recognises these names automatically.
	 *
	 * @return int|WP_Error Number of rows imported.
	 */
	public static function download_and_import_census_zcta() {
		$url = 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2020_Gazetteer/2020_Gaz_zcta_national.zip';

		$response = wp_remote_get( $url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'atcf_census_http',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Census server returned HTTP %d.', 'as-the-crow-flies' ), $code )
			);
		}

		// Save zip to a temp file.
		$zip_tmp = wp_tempnam( 'atcf_zcta' );

		if ( false === file_put_contents( $zip_tmp, wp_remote_retrieve_body( $response ) ) ) {
			return new WP_Error( 'atcf_write_failed', __( 'Could not write temporary zip file.', 'as-the-crow-flies' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			unlink( $zip_tmp );
			return new WP_Error( 'atcf_no_zip', __( 'PHP ZipArchive extension is required to extract the Census file.', 'as-the-crow-flies' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_tmp ) ) {
			unlink( $zip_tmp );
			return new WP_Error( 'atcf_zip_open', __( 'Could not open the downloaded zip file.', 'as-the-crow-flies' ) );
		}

		// Find the .txt TSV inside the zip.
		$tsv_content = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( $stat && substr( $stat['name'], -4 ) === '.txt' ) {
				$tsv_content = $zip->getFromIndex( $i );
				break;
			}
		}
		$zip->close();
		unlink( $zip_tmp );

		if ( null === $tsv_content ) {
			return new WP_Error( 'atcf_zip_content', __( 'Could not find the TSV file inside the Census zip.', 'as-the-crow-flies' ) );
		}

		// Write TSV to a temp file, import, then clean up.
		$tsv_tmp = wp_tempnam( 'atcf_zcta_tsv' );

		if ( false === file_put_contents( $tsv_tmp, $tsv_content ) ) {
			return new WP_Error( 'atcf_write_failed', __( 'Could not write temporary TSV file.', 'as-the-crow-flies' ) );
		}

		// The Gazetteer is tab-delimited, so it's parsed by the dedicated TSV reader
		// below (import_zcta_from_tsv()) rather than the comma-delimited CSV importer.
		$result = self::import_zcta_from_tsv( $tsv_tmp );
		unlink( $tsv_tmp );

		return $result;
	}

	/**
	 * Import US centroid data from a Census Gazetteer tab-delimited file.
	 *
	 * @param string $tmp_path Path to the TSV file.
	 * @return int|WP_Error Number of rows imported.
	 */
	public static function import_zcta_from_tsv( string $tmp_path ) {
		global $wpdb;


		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open file.', 'as-the-crow-flies' ) );
		}

		// Read header row (tab-delimited).
		$header_raw = fgets( $handle );
		if ( false === $header_raw ) {
			fclose( $handle );
			return new WP_Error( 'atcf_empty_file', __( 'File is empty.', 'as-the-crow-flies' ) );
		}

		$headers = array_map( 'strtolower', array_map( 'trim', explode( "\t", $header_raw ) ) );
		$zip_col = false;
		$lat_col = false;
		$lng_col = false;

		foreach ( array( 'geoid', 'postal_code', 'zcta', 'zip' ) as $name ) {
			$idx = array_search( $name, $headers, true );
			if ( false !== $idx ) {
				$zip_col = $idx;
				break;
			}
		}
		foreach ( array( 'intptlat', 'lat', 'latitude' ) as $name ) {
			$idx = array_search( $name, $headers, true );
			if ( false !== $idx ) {
				$lat_col = $idx;
				break;
			}
		}
		foreach ( array( 'intptlong', 'lng', 'longitude' ) as $name ) {
			$idx = array_search( $name, $headers, true );
			if ( false !== $idx ) {
				$lng_col = $idx;
				break;
			}
		}

		if ( false === $zip_col || false === $lat_col || false === $lng_col ) {
			fclose( $handle );
			return new WP_Error( 'atcf_bad_columns', __( 'Could not identify required columns in the Census file.', 'as-the-crow-flies' ) );
		}

		$batch        = array();
		$imported     = 0;
		$batch_max    = 500;
		$did_truncate = false;

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$cols        = explode( "\t", trim( $line ) );
			$postal_code = ATCF_Distance::normalize_postal_code( trim( $cols[ $zip_col ] ?? '' ), 'US' );
			$lat         = (float) ( $cols[ $lat_col ] ?? 0 );
			$lng         = (float) ( $cols[ $lng_col ] ?? 0 );

			if ( '' === $postal_code || ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
				continue;
			}

			$batch[] = $wpdb->prepare( '(%s, %s, %.7f, %.7f)', 'US', $postal_code, $lat, $lng );
			$imported++;

			if ( count( $batch ) >= $batch_max ) {
				if ( ! $did_truncate ) {
					$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => 'US' ) );
					$did_truncate = true;
				}
				$wpdb->query(
					"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
				);
				$batch = array();
			}
		}

		fclose( $handle );

		if ( ! empty( $batch ) ) {
			if ( ! $did_truncate ) {
				$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => 'US' ) );
			}
			$wpdb->query(
				"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
			);
		}

		if ( 0 === $imported ) {
			return new WP_Error( 'atcf_no_rows', __( 'No valid rows found in the uploaded file.', 'as-the-crow-flies' ) );
		}

		update_option( 'atcf_us_centroids_imported', gmdate( 'Y-m-d H:i:s' ) );
		return $imported;
	}

	/**
	 * Import US centroid data from an uploaded ZCTA CSV file.
	 * Expected columns (with or without header): postal_code, lat, lng
	 *
	 * @param string $tmp_path Path to the uploaded file.
	 * @return int|WP_Error Number of rows imported.
	 */
	public static function import_zcta_file( string $tmp_path ) {
		global $wpdb;

		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open uploaded file.', 'as-the-crow-flies' ) );
		}

		// Detect header row.
		$first_row    = fgetcsv( $handle );
		$header_map   = array();
		$has_header   = false;

		$zip_col = false;

		if ( is_array( $first_row ) ) {
			$lower = array_map( 'strtolower', $first_row );

			// Accepted Zip code column names — includes Census Gazetteer 'geoid'.
			foreach ( array( 'postal_code', 'zcta', 'zip', 'geoid' ) as $name ) {
				$zip_col = array_search( $name, $lower, true );
				if ( false !== $zip_col ) {
					break;
				}
			}

			if ( false !== $zip_col ) {
				$has_header        = true;
				$header_map['zip'] = $zip_col;

				// Accepted lat column names — includes Census Gazetteer 'intptlat'.
				$header_map['lat'] = false;
				foreach ( array( 'lat', 'latitude', 'intptlat' ) as $name ) {
					$col = array_search( $name, $lower, true );
					if ( false !== $col ) {
						$header_map['lat'] = $col;
						break;
					}
				}

				// Accepted lng column names — includes Census Gazetteer 'intptlong'.
				$header_map['lng'] = false;
				foreach ( array( 'lng', 'longitude', 'intptlong' ) as $name ) {
					$col = array_search( $name, $lower, true );
					if ( false !== $col ) {
						$header_map['lng'] = $col;
						break;
					}
				}
			}
		}

		if ( ! $has_header ) {
			// Assume fixed order: postal_code, lat, lng.
			$header_map = array( 'zip' => 0, 'lat' => 1, 'lng' => 2 );
			rewind( $handle );
		} elseif ( false === $header_map['lat'] || false === $header_map['lng'] ) {
			fclose( $handle );
			return new WP_Error( 'atcf_bad_columns', __( 'Could not identify lat/lng columns in the uploaded file.', 'as-the-crow-flies' ) );
		}

		$batch        = array();
		$imported     = 0;
		$batch_max    = 500;
		$did_truncate = false;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$postal_code = ATCF_Distance::normalize_postal_code( trim( $row[ $header_map['zip'] ] ?? '' ), 'US' );
			$lat         = (float) ( $row[ $header_map['lat'] ] ?? 0 );
			$lng         = (float) ( $row[ $header_map['lng'] ] ?? 0 );

			if ( '' === $postal_code || ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
				continue;
			}

			$batch[] = $wpdb->prepare( '(%s, %s, %.7f, %.7f)', 'US', $postal_code, $lat, $lng );
			$imported++;

			if ( count( $batch ) >= $batch_max ) {
				if ( ! $did_truncate ) {
					$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => 'US' ) );
					$did_truncate = true;
				}
				$wpdb->query(
					"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
				);
				$batch = array();
			}
		}

		fclose( $handle );

		if ( ! empty( $batch ) ) {
			if ( ! $did_truncate ) {
				$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => 'US' ) );
			}
			$wpdb->query(
				"INSERT IGNORE INTO {$wpdb->prefix}atcf_postal_centroids (country, postal_code, lat, lng) VALUES " . implode( ',', $batch )
			);
		}

		if ( 0 === $imported ) {
			return new WP_Error( 'atcf_no_rows', __( 'No valid rows found in the uploaded file.', 'as-the-crow-flies' ) );
		}

		update_option( 'atcf_us_centroids_imported', gmdate( 'Y-m-d H:i:s' ) );
		return $imported;
	}
}
