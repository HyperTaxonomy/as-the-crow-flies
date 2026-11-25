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

/**
 * Plugin activation, database tables and postal centroid imports. Both centroid importers
 * go through the private replace_centroids(), which deletes the country's rows and inserts
 * the file's valid rows in batches of 500 inside one transaction. A failed write rolls
 * back, and a file with no valid rows changes nothing.
 */
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
	 * Create the tables again when the recorded database version isn't the current one,
	 * e.g. after an update or when creating them failed on activation.
	 *
	 * Hook: admin_init.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_upgrade(): void {
		if ( ATCF_VERSION === get_option( 'atcf_db_version' ) ) {
			return;
		}
		if ( self::create_tables() ) {
			self::import_us_centroids();
		}
	}

	/**
	 * Create or update the plugin database tables.
	 *
	 * The database version is recorded only when both tables exist afterwards, so
	 * maybe_upgrade() retries a failed creation on the next admin page load.
	 *
	 * @return bool True when both tables exist.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$locations_sql = "CREATE TABLE {$wpdb->prefix}atcf_locations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_id BIGINT UNSIGNED NOT NULL,
			taxonomy VARCHAR(32) NOT NULL,
			country CHAR(2) NOT NULL DEFAULT 'US',
			label VARCHAR(200) NOT NULL DEFAULT '',
			address_line VARCHAR(255) NOT NULL DEFAULT '',
			address_line2 VARCHAR(255) NOT NULL DEFAULT '',
			address_line_normalized VARCHAR(255) NOT NULL DEFAULT '',
			address_line2_normalized VARCHAR(255) NOT NULL DEFAULT '',
			locality VARCHAR(100) NOT NULL DEFAULT '',
			region VARCHAR(50) NOT NULL DEFAULT '',
			postal_code VARCHAR(10) NOT NULL DEFAULT '',
			lat DECIMAL(10,7) DEFAULT NULL,
			lng DECIMAL(10,7) DEFAULT NULL,
			geocode_status VARCHAR(20) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY term_taxonomy (term_id, taxonomy),
			KEY country_postal (country, postal_code)
		) $charset_collate;";

		$centroids_sql = "CREATE TABLE {$wpdb->prefix}atcf_postal_centroids (
			country CHAR(2) NOT NULL DEFAULT 'US',
			postal_code VARCHAR(10) NOT NULL,
			lat DECIMAL(10,7) NOT NULL,
			lng DECIMAL(10,7) NOT NULL,
			PRIMARY KEY  (country, postal_code)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $locations_sql );
		dbDelta( $centroids_sql );

		$missing = array();
		foreach ( array( 'atcf_locations', 'atcf_postal_centroids' ) as $table ) {
			$name = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
				$missing[] = $name;
			}
		}
		if ( $missing ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[ATCF] Could not create database tables: ' . implode( ', ', $missing ) );
			}
			return false;
		}

		update_option( 'atcf_db_version', ATCF_VERSION );

		add_option( 'atcf_distance_units', 'miles' );
		add_option( 'atcf_location_match_mode', 'either' );
		add_option( 'atcf_ip_geo_enabled', 0 ); // Off until the admin opts in: it sends visitor IPs to a third party.
		add_option( 'atcf_ip_geo_ttl', 86400 );
		add_option( 'atcf_expose_locations_api', 1 );

		return true;
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
	 * Import an uploaded GeoNames postal code file: the TSV itself, or the zip
	 * GeoNames distributes it in ({COUNTRY}.zip holding {COUNTRY}.txt and readme.txt).
	 *
	 * @since 1.0.0
	 *
	 * @param string $country ISO 3166-1 alpha-2 country code.
	 * @param string $path    Path of the uploaded file.
	 * @return int|WP_Error Number of postal codes imported, or an error.
	 */
	public static function import_geonames_upload( string $country, string $path ) {
		if ( "PK\x03\x04" !== file_get_contents( $path, false, null, 0, 4 ) ) {
			return self::import_geonames_file( $country, $path );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'atcf_no_zip', __( 'PHP ZipArchive extension is required to read a .zip file. Upload the extracted .txt file instead.', 'as-the-crow-flies' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'atcf_zip_open', __( 'Could not open the zip file.', 'as-the-crow-flies' ) );
		}
		// Prefer the country's own file over readme.txt.
		$index = $zip->locateName( $country . '.txt', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR );
		if ( false === $index ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( '.txt' === strtolower( substr( $name, -4 ) ) && 'readme.txt' !== strtolower( basename( $name ) ) ) {
					$index = $i;
					break;
				}
			}
		}
		$content = false === $index ? false : $zip->getFromIndex( $index );
		$zip->close();
		if ( false === $content ) {
			return new WP_Error( 'atcf_zip_content', __( 'Could not find the postal code file inside the zip.', 'as-the-crow-flies' ) );
		}

		$tsv_path = wp_tempnam( 'atcf-geonames' );
		if ( ! $tsv_path || false === file_put_contents( $tsv_path, $content ) ) {
			return new WP_Error( 'atcf_write_failed', __( 'Could not write a temporary file.', 'as-the-crow-flies' ) );
		}
		$result = self::import_geonames_file( $country, $tsv_path );
		wp_delete_file( $tsv_path );
		return $result;
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
		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open uploaded file.', 'as-the-crow-flies' ) );
		}

		$imported = self::replace_centroids(
			$country,
			$handle,
			"\t",
			static function ( array $row ) {
				return count( $row ) < 11 ? null : array( $row[1], $row[9], $row[10] );
			}
		);
		fclose( $handle );

		if ( is_wp_error( $imported ) ) {
			return $imported;
		}

		// The import takes a while: add the country to the list as it is now, so changes made
		// on the Postal Codes tab meanwhile aren't undone.
		ATCF_Options::update_locked(
			'atcf_country_centroids',
			static function ( $countries ) use ( $country, $imported ): array {
				return array_merge(
					(array) $countries,
					array(
						$country => array(
							'count'    => $imported,
							'imported' => gmdate( 'Y-m-d H:i:s' ),
							'enabled'  => true,
						),
					)
				);
			}
		);

		return $imported;
	}

	/**
	 * Download the Census ZCTA Gazetteer zip from census.gov, extract the TSV,
	 * and import into the centroids table.
	 *
	 * The 2020 Gazetteer has these tab-delimited columns:
	 *   GEOID  ALAND  AWATER  ALAND_SQMI  AWATER_SQMI  INTPTLAT  INTPTLONG
	 * import_zcta_file() recognizes these column names.
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
			wp_delete_file( $zip_tmp );
			return new WP_Error( 'atcf_no_zip', __( 'PHP ZipArchive extension is required to extract the Census file.', 'as-the-crow-flies' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_tmp ) ) {
			wp_delete_file( $zip_tmp );
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
		wp_delete_file( $zip_tmp );

		if ( null === $tsv_content ) {
			return new WP_Error( 'atcf_zip_content', __( 'Could not find the TSV file inside the Census zip.', 'as-the-crow-flies' ) );
		}

		// Write TSV to a temp file, import, then clean up.
		$tsv_tmp = wp_tempnam( 'atcf_zcta_tsv' );

		if ( false === file_put_contents( $tsv_tmp, $tsv_content ) ) {
			return new WP_Error( 'atcf_write_failed', __( 'Could not write temporary TSV file.', 'as-the-crow-flies' ) );
		}

		$result = self::import_zcta_file( $tsv_tmp, "\t" );
		wp_delete_file( $tsv_tmp );

		return $result;
	}

	/**
	 * Import US centroid data from a Zip code file: the bundled CSV, an uploaded CSV, or the
	 * Census Gazetteer (tab-delimited). Replaces all existing US rows.
	 *
	 * With a header row, the Zip code, latitude and longitude columns are found by name
	 * (including the Gazetteer's GEOID, INTPTLAT and INTPTLONG). Without one, the columns
	 * are taken to be postal_code, lat, lng in that order.
	 *
	 * @param string $tmp_path  Path to the file.
	 * @param string $delimiter Column delimiter: ',' (default) or "\t".
	 * @return int|WP_Error Number of rows imported.
	 */
	public static function import_zcta_file( string $tmp_path, string $delimiter = ',' ) {
		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'atcf_file_open', __( 'Could not open uploaded file.', 'as-the-crow-flies' ) );
		}

		$first_row = fgetcsv( $handle, 0, $delimiter );
		$headers   = is_array( $first_row ) ? array_map( 'strtolower', array_map( 'trim', $first_row ) ) : array();
		$zip_col   = self::find_column( $headers, array( 'postal_code', 'zcta', 'zip', 'geoid' ) );

		if ( false === $zip_col ) {
			// No header: fixed order postal_code, lat, lng.
			$columns = array( 0, 1, 2 );
			rewind( $handle );
		} else {
			$columns = array(
				$zip_col,
				self::find_column( $headers, array( 'lat', 'latitude', 'intptlat' ) ),
				self::find_column( $headers, array( 'lng', 'longitude', 'intptlong' ) ),
			);
			if ( false === $columns[1] || false === $columns[2] ) {
				fclose( $handle );
				return new WP_Error( 'atcf_bad_columns', __( 'Could not identify lat/lng columns in the uploaded file.', 'as-the-crow-flies' ) );
			}
		}

		$imported = self::replace_centroids(
			'US',
			$handle,
			$delimiter,
			static function ( array $row ) use ( $columns ) {
				return array( $row[ $columns[0] ] ?? '', $row[ $columns[1] ] ?? 0, $row[ $columns[2] ] ?? 0 );
			}
		);
		fclose( $handle );

		if ( ! is_wp_error( $imported ) ) {
			update_option( 'atcf_us_centroids_imported', gmdate( 'Y-m-d H:i:s' ) );
		}
		return $imported;
	}

	/**
	 * The index of the first header that matches one of the names.
	 *
	 * @param string[] $headers Lowercased, trimmed headers.
	 * @param string[] $names   Accepted names, in order of preference.
	 * @return int|false Column index, or false when none match.
	 */
	private static function find_column( array $headers, array $names ) {
		foreach ( $names as $name ) {
			$idx = array_search( $name, $headers, true );
			if ( false !== $idx ) {
				return $idx;
			}
		}
		return false;
	}

	/**
	 * Replace a country's centroids with the valid rows of an open file.
	 *
	 * Rows are read with fgetcsv() and mapped to (postal code, lat, lng) by $map_row; rows
	 * with no postal code or invalid coordinates are skipped. The country's existing rows
	 * are deleted and the new ones inserted in one transaction, in batches of 500, so a
	 * file with no valid rows, or a failed write, leaves the existing data untouched.
	 *
	 * @param string   $country   ISO 3166-1 alpha-2 country code.
	 * @param resource $handle    Open file, positioned at the first data row.
	 * @param string   $delimiter Column delimiter.
	 * @param callable $map_row   fn( array $row ): ?array — [postal_code, lat, lng], or null to skip the row.
	 * @return int|WP_Error Number of rows imported.
	 */
	private static function replace_centroids( string $country, $handle, string $delimiter, callable $map_row ) {
		global $wpdb;

		$batch    = array();
		$imported = 0;
		$started  = false;

		try {
			while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
				$fields = $map_row( $row );
				if ( null === $fields ) {
					continue;
				}
				$postal_code = ATCF_Distance::normalize_postal_code( trim( (string) $fields[0] ), $country );
				$lat         = (float) $fields[1];
				$lng         = (float) $fields[2];

				if ( '' === $postal_code || ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
					continue;
				}

				$batch[] = $wpdb->prepare( '(%s, %s, %.7f, %.7f)', $country, $postal_code, $lat, $lng );
				++$imported;

				if ( count( $batch ) >= 500 ) {
					self::write_centroid_batch( $country, $batch, $started );
					$batch = array();
				}
			}

			if ( $batch ) {
				self::write_centroid_batch( $country, $batch, $started );
			}
			if ( $started ) {
				$wpdb->query( 'COMMIT' );
			}
		} catch ( RuntimeException $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'atcf_import_db', __( 'The import failed while saving, so the existing postal codes were kept.', 'as-the-crow-flies' ) );
		}

		if ( 0 === $imported ) {
			return new WP_Error( 'atcf_no_rows', __( 'No valid rows found in the uploaded file.', 'as-the-crow-flies' ) );
		}
		return $imported;
	}

	/**
	 * Insert one batch of centroid rows. Before the first batch, start the transaction and
	 * delete the country's existing rows.
	 *
	 * @param string   $country  Country code.
	 * @param string[] $batch    Prepared "(country, postal_code, lat, lng)" value tuples.
	 * @param bool     $started  Whether the transaction has started; set to true here.
	 * @throws RuntimeException When a write fails.
	 */
	private static function write_centroid_batch( string $country, array $batch, bool &$started ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atcf_postal_centroids';
		if ( ! $started ) {
			$wpdb->query( 'START TRANSACTION' );
			$started = true;
			if ( false === $wpdb->delete( $table, array( 'country' => $country ) ) ) {
				throw new RuntimeException( esc_html( $wpdb->last_error ) );
			}
		}
		if ( false === $wpdb->query( "INSERT IGNORE INTO {$table} (country, postal_code, lat, lng) VALUES " . implode( ',', $batch ) ) ) {
			throw new RuntimeException( esc_html( $wpdb->last_error ) );
		}
	}
}
