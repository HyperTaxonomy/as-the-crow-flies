<?php
/**
 * REST API endpoints.
 *
 * GET /wp-json/atcf/v1/locations          — locations within radius
 * GET /wp-json/atcf/v1/nearest-postal-code — reverse-geocode coordinates to postal code
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_REST_API {

	const NAMESPACE = 'atcf/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * GET /atcf/v1/visitor-location — the visitor's location from the server side of the
	 * chain (CDN headers, IP geolocation, fallback location; see atcf_get_visitor_location()).
	 *
	 * Fetched by atcfGeo.getServerLocation() only when a page needs it, rather than looked
	 * up on every page view and written into the page (which page caches would then serve
	 * to other visitors). The response is per visitor and never cacheable.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response { location: { lat, lng, country, postal_code, source } | null }
	 */
	public static function get_visitor_location(): WP_REST_Response {
		$loc      = atcf_get_visitor_location( false );
		$response = new WP_REST_Response( array(
			'location' => $loc ? array(
				'lat'         => (float) $loc['lat'],
				'lng'         => (float) $loc['lng'],
				'country'     => (string) $loc['country'],
				'postal_code' => (string) $loc['postal_code'],
				'source'      => (string) $loc['source'],
			) : null,
		) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/locations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_locations' ),
			'permission_callback' => '__return_true',
			'args'                => self::locations_args(),
		) );

		register_rest_route( self::NAMESPACE, '/visitor-location', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_visitor_location' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/nearest-postal-code', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_nearest_postal_code' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'lat'     => array(
					'required'          => true,
					'type'              => 'number',
					'sanitize_callback' => fn( $v ) => (float) $v,
					'validate_callback' => array( __CLASS__, 'validate_lat' ),
				),
				'lng'     => array(
					'required'          => true,
					'type'              => 'number',
					'sanitize_callback' => fn( $v ) => (float) $v,
					'validate_callback' => array( __CLASS__, 'validate_lng' ),
				),
				'country' => array(
					'default'           => 'US',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'max_km'  => array(
					'default'           => 0,
					'type'              => 'number',
					'sanitize_callback' => fn( $v ) => max( 0.0, (float) $v ),
				),
			),
		) );
	}

	// --------------------------------------------------------------------------
	// Endpoint handlers
	// --------------------------------------------------------------------------

	/**
	 * GET /atcf/v1/locations
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_locations( WP_REST_Request $request ) {
		$lat = $request->get_param( 'lat' );
		$lng = $request->get_param( 'lng' );

		$use_coords = null !== $lat && null !== $lng
			&& ATCF_Distance::is_valid_coordinate( (float) $lat, (float) $lng );

		$radius   = max( 0.1, (float) $request->get_param( 'radius' ) );
		$unit     = $request->get_param( 'unit' );
		$provider = $request->get_param( 'directions_provider' );

		$args = array(
			'taxonomy' => $request->get_param( 'taxonomy' ) ?? '',
			'term_id'  => $request->get_param( 'term' ) ? (int) $request->get_param( 'term' ) : 0,
			'term_ids' => array_values( array_filter( array_map( 'absint', (array) ( $request->get_param( 'term_ids' ) ?? array() ) ) ) ),
			'country'  => $request->get_param( 'country' ) ?? 'US',
			'limit'    => min( 100, max( 1, (int) $request->get_param( 'limit' ) ) ),
			'orderby'  => $request->get_param( 'orderby' ) ?? 'distance',
		);

		if ( $use_coords ) {
			$args['lat'] = (float) $lat;
			$args['lng'] = (float) $lng;
			$postal_code = '';
			$ref_postal  = null;
			$ref_country = null;
		} else {
			$postal_code = ATCF_Distance::normalize_postal_code(
				$request->get_param( 'postal_code' ) ?? '',
				$args['country']
			);

			if ( '' === $postal_code ) {
				return new WP_Error(
					'atcf_missing_location',
					__( 'Provide postal_code or lat+lng.', 'as-the-crow-flies' ),
					array( 'status' => 400 )
				);
			}

			$ref_postal  = $postal_code;
			$ref_country = $args['country'];
		}

		$locations = get_option( 'atcf_expose_locations_api', true )
			? ATCF_Distance::get_locations_within_radius( $postal_code, $radius, $unit, $args )
			: array();

		// Augment with term names and directions URL.
		$term_name_cache = array();
		foreach ( $locations as &$loc ) {
			// Decode HTML entities in text fields — WP's REST pipeline can encode & as &amp; in the JSON body.
			foreach ( array( 'label', 'address_line', 'address_line2', 'locality', 'region' ) as $_f ) {
				if ( isset( $loc[ $_f ] ) ) {
					$loc[ $_f ] = wp_specialchars_decode( (string) $loc[ $_f ], ENT_QUOTES );
				}
			}

			$tid = (int) $loc['term_id'];
			if ( ! isset( $term_name_cache[ $tid ] ) ) {
				$term = get_term( $tid );
				$term_name_cache[ $tid ] = ( $term && ! is_wp_error( $term ) ) ? wp_specialchars_decode( $term->name, ENT_QUOTES ) : '';
			}
			$loc['term_name']             = $term_name_cache[ $tid ];
			$loc['directions_url']        = ATCF_Distance::get_directions_url( (object) $loc, $provider );
			$loc['directions_url_google'] = ATCF_Distance::get_directions_url( (object) $loc, 'google' );
			$loc['directions_url_apple']  = ATCF_Distance::get_directions_url( (object) $loc, 'apple' );
			$loc['directions_url_waze']   = ATCF_Distance::get_directions_url( (object) $loc, 'waze' );
			$loc['directions_url_osm']    = ATCF_Distance::get_directions_url( (object) $loc, 'osm' );

			// Cast numeric fields.
			$loc['id']             = (int) $loc['id'];
			$loc['term_id']        = (int) $loc['term_id'];
			$loc['lat']            = null !== $loc['lat'] ? (float) $loc['lat'] : null;
			$loc['lng']            = null !== $loc['lng'] ? (float) $loc['lng'] : null;
			$loc['distance_km']    = (float) $loc['distance_km'];
			$loc['distance_miles'] = (float) $loc['distance_miles'];

			// Remove internal normalization columns from response.
			unset( $loc['address_line_normalized'], $loc['address_line2_normalized'], $loc['geocode_status'] );
		}
		unset( $loc );

		$radius_km = 'miles' === $unit
			? $radius * ATCF_Distance::KM_PER_MILE
			: $radius;

		$response_data = array(
			'locations'       => $locations,
			'count'           => count( $locations ),
			'radius_km'       => round( $radius_km, 2 ),
		);

		if ( $use_coords ) {
			$response_data['reference_lat'] = (float) $lat;
			$response_data['reference_lng'] = (float) $lng;
		} else {
			$response_data['reference_postal_code'] = $ref_postal;
			$response_data['reference_country']     = $ref_country;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * GET /atcf/v1/nearest-postal-code
	 *
	 * Returns the nearest postal-code centroid in the requested country,
	 * regardless of distance. Responds 404 only when no centroids exist for
	 * that country.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_nearest_postal_code( WP_REST_Request $request ) {
		$lat     = (float) $request->get_param( 'lat' );
		$lng     = (float) $request->get_param( 'lng' );
		$country = strtoupper( sanitize_text_field( $request->get_param( 'country' ) ?? 'US' ) );
		$max_km  = max( 0.0, (float) $request->get_param( 'max_km' ) );

		$result = ATCF_Distance::get_nearest_postal_code( $lat, $lng, $country, $max_km );

		if ( null === $result ) {
			return new WP_Error(
				'atcf_no_centroid',
				__( 'No postal code found near this location.', 'as-the-crow-flies' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array(
			'postal_code' => $result['postal_code'],
			'country'     => $result['country'],
			'distance_km' => round( $result['distance_km'], 3 ),
		) );
	}

	// --------------------------------------------------------------------------
	// Argument definitions
	// --------------------------------------------------------------------------

	private static function locations_args(): array {
		return array(
			'postal_code'        => array(
				'default'           => '',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'lat'                => array(
				'default'           => null,
				'type'              => 'number',
				'sanitize_callback' => fn( $v ) => (float) $v,
				'validate_callback' => array( __CLASS__, 'validate_lat' ),
			),
			'lng'                => array(
				'default'           => null,
				'type'              => 'number',
				'sanitize_callback' => fn( $v ) => (float) $v,
				'validate_callback' => array( __CLASS__, 'validate_lng' ),
			),
			'country'            => array(
				'default'           => 'US',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'radius'             => array(
				'default'           => 10,
				'type'              => 'number',
				'minimum'           => 0.1,
				'maximum'           => 500,
				'sanitize_callback' => fn( $v ) => (float) $v,
			),
			'unit'               => array(
				'default'           => get_option( 'atcf_distance_units', 'miles' ),
				'type'              => 'string',
				'enum'              => array( 'miles', 'km' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'taxonomy'           => array(
				'default'           => '',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'term'               => array(
				'default'           => 0,
				'type'              => 'integer',
				'sanitize_callback' => fn( $v ) => absint( $v ),
			),
			'term_ids'           => array(
				'default'           => array(),
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => fn( $v ) => array_values( array_filter( array_map( 'absint', (array) $v ) ) ),
			),
			'limit'              => array(
				'default'           => 20,
				'type'              => 'integer',
				'sanitize_callback' => fn( $v ) => absint( $v ),
			),
			'orderby'            => array(
				'default'           => 'distance',
				'type'              => 'string',
				'enum'              => array( 'distance', 'label', 'term_name' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'directions_provider' => array(
				'default'           => 'google',
				'type'              => 'string',
				'enum'              => array( 'google', 'apple', 'osm', 'waze', 'geo' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	// --------------------------------------------------------------------------
	// Validators
	// --------------------------------------------------------------------------

	public static function validate_lat( $value ): bool {
		return null === $value || ( is_numeric( $value ) && (float) $value >= -90.0 && (float) $value <= 90.0 );
	}

	public static function validate_lng( $value ): bool {
		return null === $value || ( is_numeric( $value ) && (float) $value >= -180.0 && (float) $value <= 180.0 );
	}

	// --------------------------------------------------------------------------
	// Term location formatting for HT REST enrichment
	// --------------------------------------------------------------------------

	/**
	 * Return locations for a term formatted as Schema.org Place objects, ready for
	 * the htax_locations REST field on HyperTaxonomy term responses.
	 *
	 * Results are cached per term_id for the lifetime of the request (static cache),
	 * which is effective for peer collection fetches where many terms are enriched
	 * in a single request.
	 *
	 * @since 1.0.0
	 * @param int $term_id Term ID.
	 * @return array[]|null Array of Schema.org Place objects, or null when the term has no locations.
	 */
	public static function format_term_locations( int $term_id ): ?array {
		static $cache = array();
		if ( array_key_exists( $term_id, $cache ) ) {
			return $cache[ $term_id ];
		}

		$locations = ATCF_Locations::get_by_term_id( $term_id );

		/**
		 * Filters the raw location rows used to build the htax_locations REST field.
		 *
		 * Hooked by HyperTaxonomy's ATCF integration to pipe through the htax_term_locations
		 * filter chain, ensuring third-party location providers appear in cross-site REST output.
		 *
		 * @since 1.0.0
		 * @param array[] $locations Raw ATCF location row objects for the term.
		 * @param int     $term_id   Term ID.
		 */
		$locations = (array) apply_filters( 'atcf_term_locations_for_rest', $locations, $term_id );

		if ( empty( $locations ) ) {
			$cache[ $term_id ] = null;
			return null;
		}

		$result = array();
		foreach ( $locations as $loc ) {
			$place = array(
				'@type'          => 'schema:Place',
				'schema:name'    => $loc->label ?? '',
				'schema:address' => array(
					'@type'                  => 'schema:PostalAddress',
					'schema:streetAddress'   => implode( ', ', array_filter( array( $loc->address_line ?? '', $loc->address_line2 ?? '' ) ) ),
					'schema:addressLocality' => $loc->locality ?? '',
					'schema:addressRegion'   => $loc->region ?? '',
					'schema:postalCode'      => $loc->postal_code ?? '',
					'schema:addressCountry'  => $loc->country ?? 'US',
				),
			);
			if ( null !== ( $loc->lat ?? null ) && null !== ( $loc->lng ?? null ) ) {
				$place['schema:geo'] = array(
					'@type'            => 'schema:GeoCoordinates',
					'schema:latitude'  => (float) $loc->lat,
					'schema:longitude' => (float) $loc->lng,
				);
			}
			$result[] = $place;
		}

		$cache[ $term_id ] = $result;
		return $result;
	}
}
