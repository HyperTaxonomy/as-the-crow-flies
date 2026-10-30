<?php
/**
 * US Census Geocoder batch integration and cron scheduling.
 *
 * Queues pending US locations for geocoding via the free Census batch API.
 * No API key required. Runs asynchronously via WP Cron.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Geocoder {

	const CENSUS_API_URL  = 'https://geocoding.geo.census.gov/geocoder/locations/addressbatch';
	const BATCH_SIZE      = 9999;
	const HTTP_TIMEOUT    = 60;

	/**
	 * Wall-clock budget, in seconds, for the serial, rate-limited (1 req/sec) Nominatim
	 * fallback loop inside apply_results(). The Census batch call itself is one request
	 * for the whole batch and isn't bounded by this; only the per-row Nominatim retries
	 * (needed for addresses Census couldn't match) are, since a large no_match count
	 * could otherwise block the request running the cron tick for minutes.
	 */
	const MAX_NOMINATIM_FALLBACK_SECONDS = 20;

	/**
	 * Register cron hook.
	 */
	public static function register_cron(): void {
		add_action( 'atcf_geocode_pending', array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Run a geocoding batch for all pending US locations.
	 * Called by WP Cron and by the admin "Geocode now" button.
	 *
	 * If the Nominatim fallback's time budget (MAX_NOMINATIM_FALLBACK_SECONDS) runs out
	 * partway through, any locations not yet reached are left with geocode_status still
	 * 'pending' and naturally get picked up by get_pending_geocode_ids() on the next run.
	 *
	 * @return int Number of locations actually finalized (written a terminal result) this run.
	 */
	public static function run_batch(): int {
		$ids = ATCF_Locations::get_pending_geocode_ids( self::BATCH_SIZE );
		if ( empty( $ids ) ) {
			return 0;
		}

		$locations = self::fetch_locations( $ids );
		if ( empty( $locations ) ) {
			return 0;
		}

		$csv     = self::build_request_csv( $locations );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[ATCF geocoder] Sending CSV to Census API:' . "\n" . $csv );
		}

		$results = self::call_census_api( $csv );

		if ( is_wp_error( $results ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[ATCF geocoder] Census API error: ' . $results->get_error_message() );
			}
			// Revert all to pending so the next cron run retries.
			self::mark_all( $ids, 'pending' );
			return 0;
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[ATCF geocoder] Census API results: ' . wp_json_encode( $results ) );
		}

		return self::apply_results( $locations, $results );
	}

	/**
	 * Geocode a specific set of location IDs immediately.
	 * Used by per-row, bulk, and per-term geocode actions.
	 * Only US locations are sent to the Census API; others are marked no_match.
	 *
	 * Subject to the same Nominatim fallback time budget as run_batch() — see its docblock.
	 *
	 * @param int[] $ids Location IDs to geocode.
	 * @return int Number of locations actually finalized (written a terminal result).
	 */
	public static function run_for_ids( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		$locations = self::fetch_locations( $ids );
		if ( empty( $locations ) ) {
			return 0;
		}

		// Filter to US locations only — Census API only covers the US.
		$us_locations = array_filter( $locations, static function ( $loc ) {
			return 'US' === strtoupper( $loc->country ?? 'US' );
		} );
		$non_us_ids   = array_diff( array_keys( $locations ), array_keys( $us_locations ) );
		foreach ( $non_us_ids as $id ) {
			ATCF_Locations::set_geocode_result( $id, 'no_match' );
		}

		if ( empty( $us_locations ) ) {
			return count( $non_us_ids );
		}

		$csv     = self::build_request_csv( $us_locations );
		$results = self::call_census_api( $csv );

		if ( is_wp_error( $results ) ) {
			self::mark_all( array_keys( $us_locations ), 'pending' );
			return count( $non_us_ids );
		}

		return count( $non_us_ids ) + self::apply_results( $us_locations, $results );
	}

	// --------------------------------------------------------------------------
	// Internal
	// --------------------------------------------------------------------------

	/**
	 * Fetch full location rows for the given IDs.
	 *
	 * @param int[] $ids Location IDs.
	 * @return object[]  Keyed by ID.
	 */
	private static function fetch_locations( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(

				"SELECT id, country, address_line, address_line2, locality, region, postal_code
				 FROM {$wpdb->prefix}atcf_locations
				 WHERE id IN ({$placeholders})",
				$ids
			)
		);

		$keyed = array();
		foreach ( $rows as $row ) {
			$keyed[ (int) $row->id ] = $row;
		}
		return $keyed;
	}

	/**
	 * Build the CSV payload for the Census batch API.
	 *
	 * Census format: id, street, city, state, zip (no header row).
	 * address_line2 is not sent — Census geocodes to building level.
	 *
	 * @param object[] $locations Keyed by ID.
	 * @return string CSV string.
	 */
	private static function build_request_csv( array $locations ): string {
		$mem = fopen( 'php://memory', 'r+' );
		foreach ( $locations as $id => $loc ) {
			fputcsv( $mem, array(
				$id,
				$loc->address_line ?? '',
				$loc->locality     ?? '',
				$loc->region       ?? '',
				$loc->postal_code  ?? '',
			) );
		}
		rewind( $mem );
		$csv = stream_get_contents( $mem );
		fclose( $mem );
		return $csv;
	}

	/**
	 * POST the CSV to the Census Geocoder and return parsed results.
	 *
	 * @param string $csv CSV payload.
	 * @return array[]|WP_Error Array keyed by location ID; each value has status and optionally lat/lng/normalized_street.
	 */
	private static function call_census_api( string $csv ) {
		$boundary = 'ATCF' . wp_generate_password( 12, false );
		$body     = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"addressFile\"; filename=\"addresses.csv\"\r\n"
			. "Content-Type: text/csv\r\n\r\n"
			. $csv . "\r\n"
			. "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"benchmark\"\r\n\r\n"
			. "Public_AR_Current\r\n"
			. "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"returntype\"\r\n\r\n"
			. "locations\r\n"
			. "--{$boundary}--\r\n";

		$response = wp_remote_post(
			self::CENSUS_API_URL,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'atcf_census_http', "Census Geocoder returned HTTP {$code}" );
		}

		return self::parse_response( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Parse the Census Geocoder CSV response.
	 *
	 * Current Census batch API response columns (all fields are individually quoted):
	 * 0: ID, 1: Original input address, 2: Match indicator (Match/No_Match/Tie),
	 * 3: Match type (Exact/Non_Exact/Tie), 4: Matched address,
	 * 5: Coordinates "lng,lat" (quoted together), 6: TIGER Line ID, 7: Side
	 *
	 * @param string $body Response body.
	 * @return array[] Keyed by location ID.
	 */
	private static function parse_response( string $body ): array {
		$results = array();
		$lines   = array_filter( array_map( 'trim', explode( "\n", $body ) ) );

		foreach ( $lines as $line ) {
			$cols = str_getcsv( $line );
			if ( count( $cols ) < 3 ) {
				continue;
			}

			$id = (int) trim( $cols[0] );
			if ( $id <= 0 ) {
				continue;
			}

			// Column 2 is the match indicator; column 1 is the echoed input address.
			$match_indicator = strtoupper( trim( $cols[2] ?? '' ) );

			if ( 'MATCH' !== $match_indicator ) {
				$results[ $id ] = array( 'status' => 'no_match' );
				continue;
			}

			$match_type      = strtolower( trim( $cols[3] ?? '' ) );
			$matched_address = trim( $cols[4] ?? '' );
			$coords_str      = trim( $cols[5] ?? '' );

			$status = ( 'tie' === $match_type ) ? 'tie' : 'matched';

			$lat = null;
			$lng = null;

			if ( '' !== $coords_str ) {
				$coords = explode( ',', $coords_str, 2 );
				if ( 2 === count( $coords ) ) {
					// Census returns x,y = lng,lat.
					$lng = (float) trim( $coords[0] );
					$lat = (float) trim( $coords[1] );
					if ( ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
						$lat = null;
						$lng = null;
					}
				}
			}

			if ( null === $lat || null === $lng ) {
				$results[ $id ] = array( 'status' => 'no_match' );
				continue;
			}

			// Extract street portion from matched address "STREET, CITY, STATE, Zip".
			$normalized_street = '';
			if ( '' !== $matched_address ) {
				$address_parts    = explode( ',', $matched_address, 2 );
				$normalized_street = trim( $address_parts[0] );
			}

			$results[ $id ] = array(
				'status'           => $status,
				'lat'              => $lat,
				'lng'              => $lng,
				'normalized_street' => $normalized_street,
			);
		}

		return $results;
	}

	/**
	 * Write geocoding results back to the database.
	 * For no_match results, attempts the Nominatim (OpenStreetMap) geocoder as a
	 * fallback — it handles some addresses the Census batch API misses.
	 *
	 * The Nominatim fallback is rate-limited to 1 request/second (enforced inside
	 * call_nominatim_api()), so a batch with many no_match rows could otherwise block
	 * for minutes. To bound that, this method stops making new Nominatim attempts once
	 * MAX_NOMINATIM_FALLBACK_SECONDS has elapsed; any location not yet reached is left
	 * untouched (its geocode_status stays 'pending') so the next run retries it — it is
	 * never incorrectly finalized as 'no_match' just because its turn didn't come up yet.
	 *
	 * @param object[] $locations Keyed by ID.
	 * @param array[]  $results   Parsed Census results keyed by ID.
	 * @return int Number of locations actually finalized (written a terminal result).
	 */
	private static function apply_results( array $locations, array $results ): int {
		$deadline     = microtime( true ) + self::MAX_NOMINATIM_FALLBACK_SECONDS;
		$processed    = 0;
		$any_geocoded = false;

		foreach ( $locations as $id => $loc ) {
			if ( ! isset( $results[ $id ] ) ) {
				ATCF_Locations::set_geocode_result( $id, 'failed' );
				$processed++;
				continue;
			}

			$result = $results[ $id ];
			$status = $result['status'];

			if ( in_array( $status, array( 'matched', 'tie' ), true ) ) {
				ATCF_Locations::set_geocode_result( $id, $status, $result['lat'] ?? null, $result['lng'] ?? null );
				if ( ! empty( $result['normalized_street'] ) ) {
					ATCF_Locations::set_normalized_address( $id, $result['normalized_street'] );
				}
				$any_geocoded = true;
				$processed++;
				continue;
			}

			// Census batch API returned no_match — fall back to Nominatim (OpenStreetMap),
			// which draws on different data than Census TIGER and can match addresses the
			// batch API missed. Rate limiting is enforced inside call_nominatim_api().
			if ( microtime( true ) >= $deadline ) {
				// Out of time this run; leave geocode_status as 'pending' and let the
				// next scheduled run retry Census + Nominatim for this location.
				continue;
			}

			$nominatim = self::call_nominatim_api( $loc );
			if ( null !== $nominatim ) {
				ATCF_Locations::set_geocode_result( $id, 'matched_nominatim', $nominatim['lat'], $nominatim['lng'] );
				$any_geocoded = true;
			} else {
				ATCF_Locations::set_geocode_result( $id, 'no_match' );
			}
			$processed++;
		}

		// set_geocode_result() only flushes the per-term cache; it deliberately doesn't
		// fire atcf_location_changed itself (this runs per-row, and a batch can be
		// thousands of rows — firing it per-row would mean that many redundant
		// atcf_query cache flushes). Fire it once here instead, and only when a location
		// actually went from "no coordinates" to "has coordinates", since that's the one
		// outcome that can change which locations an atcf_query radius search finds.
		if ( $any_geocoded ) {
			do_action( 'atcf_location_changed', '' );
		}

		return $processed;
	}

	/**
	 * Try the Nominatim (OpenStreetMap) geocoder for one location.
	 * Used as a final fallback when the Census API cannot match the address.
	 * Nominatim has broader urban coverage than Census TIGER data.
	 *
	 * @param object $loc Location row.
	 * @return array|null ['lat', 'lng', 'normalized_street'] on success, null on failure.
	 */
	private static function call_nominatim_api( object $loc ): ?array {
		$url = add_query_arg(
			array(
				'street'       => $loc->address_line,
				'city'         => $loc->locality,
				'state'        => $loc->region,
				'postalcode'   => $loc->postal_code,
				'countrycodes' => 'us',
				'format'       => 'json',
				'limit'        => 1,
			),
			'https://nominatim.openstreetmap.org/search'
		);

		// Nominatim ToS: no more than 1 request per second.
		// The static variable persists across calls within the same PHP process,
		// so the limit is enforced regardless of which code path reaches this function.
		static $last_request_time = 0;
		$now = microtime( true );
		if ( $now - $last_request_time < 1.0 ) {
			usleep( (int) ( ( 1.0 - ( $now - $last_request_time ) ) * 1000000 ) );
		}
		$last_request_time = microtime( true );

		$response = wp_remote_get( $url, array(
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'As-the-Crow-Flies-WordPress-Plugin/1.0',
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body[0] ) || ! is_array( $body[0] ) ) {
			return null;
		}

		$match = $body[0];
		$lat   = isset( $match['lat'] ) ? (float) $match['lat'] : null;
		$lng   = isset( $match['lon'] ) ? (float) $match['lon'] : null;

		if ( null === $lat || null === $lng || ! ATCF_Distance::is_valid_coordinate( $lat, $lng ) ) {
			return null;
		}

		return array( 'lat' => $lat, 'lng' => $lng, 'normalized_street' => '' );
	}

	/**
	 * Set all given location IDs to a specific geocode_status.
	 *
	 * @param int[]  $ids    Location IDs.
	 * @param string $status Status value.
	 */
	private static function mark_all( array $ids, string $status ): void {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $status ), $ids );

		$wpdb->query(
			$wpdb->prepare(

				"UPDATE {$wpdb->prefix}atcf_locations SET geocode_status = %s WHERE id IN ({$placeholders})",
				$params
			)
		);
	}

	/**
	 * Re-queue API failures and run geocoding immediately.
	 *
	 * Only re-queues rows with geocode_status = 'failed' (network/API errors —
	 * worth retrying unchanged). Rows with 'no_match' are left as-is because
	 * the address itself was not recognized by Census; they need to be corrected
	 * first. Editing a location auto-re-queues it as 'pending', after which this
	 * method will pick it up.
	 *
	 * @return array{requeued: int, processed: int}
	 */
	public static function retry_and_run(): array {
		global $wpdb;

		$requeued = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}atcf_locations
				 SET geocode_status = %s
				 WHERE country = %s AND lat IS NULL AND geocode_status = %s",
				'pending', 'US', 'failed'
			)
		);

		$processed = self::run_batch();

		return array( 'requeued' => $requeued, 'processed' => $processed );
	}

}
