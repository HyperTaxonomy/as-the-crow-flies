<?php
/**
 * Distance calculation, centroid lookup, coordinate validation, and public API.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Distance {

	const EARTH_RADIUS_KM    = 6371.0;
	const KM_PER_MILE        = 1.60934;

	// --------------------------------------------------------------------------
	// Public API
	// --------------------------------------------------------------------------

	/**
	 * Return term IDs that have at least one location within radius of a postal code.
	 *
	 * This is the primary detection function — HT and other plugins call this.
	 *
	 * @since 1.0.0
	 * @param string $postal_code Reference postal code.
	 * @param float  $radius      Search radius.
	 * @param string $unit        'miles' or 'km'.
	 * @param string $taxonomy    Taxonomy slug to limit results; empty = all taxonomies.
	 * @param string $country     ISO 3166-1 alpha-2 country code. Default 'US'.
	 * @return int[]
	 */
	public static function get_term_ids_within_radius(
		string $postal_code,
		float  $radius,
		string $unit = 'miles',
		string $taxonomy = '',
		string $country = 'US'
	): array {
		$postal_code = self::normalize_postal_code( $postal_code, $country );
		if ( '' === $postal_code ) {
			return array();
		}

		$centroid = self::get_centroid( $postal_code, $country );
		if ( null === $centroid ) {
			return array();
		}

		$locations = self::query_locations_near(
			$centroid['lat'],
			$centroid['lng'],
			$radius,
			$unit,
			array( 'taxonomy' => $taxonomy, 'country' => $country )
		);

		$term_ids = array_values(
			array_unique( array_map( 'intval', array_column( $locations, 'term_id' ) ) )
		);

		/**
		 * Filters the resolved term ID list.
		 *
		 * @since 1.0.0
		 * @param int[]  $term_ids    Resolved term IDs.
		 * @param string $postal_code Reference postal code.
		 * @param float  $radius      Search radius.
		 * @param string $unit        Unit of measurement.
		 * @param string $taxonomy    Taxonomy slug or empty.
		 * @param string $country     Country code.
		 */
		return apply_filters( 'atcf_term_ids_within_radius', $term_ids, $postal_code, $radius, $unit, $taxonomy, $country );
	}

	/**
	 * Return full location rows within radius of a reference point.
	 *
	 * @since 1.0.0
	 * @param string $postal_code Reference postal code (ignored when lat/lng provided).
	 * @param float  $radius      Search radius.
	 * @param string $unit        'miles' or 'km'.
	 * @param array  $args {
	 *   @type string  $taxonomy  Limit to this taxonomy slug.
	 *   @type int     $term_id   Limit to a single term ID.
	 *   @type int[]   $term_ids  Limit to these term IDs (takes precedence over term_id when non-empty).
	 *   @type string  $country   Country scope for centroid lookup.
	 *   @type int     $limit     Max rows to return. Default 20.
	 *   @type string  $orderby   'distance' (default), 'label', 'term_name'.
	 *   @type float   $lat       Reference latitude (bypasses centroid lookup).
	 *   @type float   $lng       Reference longitude (bypasses centroid lookup).
	 * }
	 * @return array[] Location rows with distance_km and distance_miles appended.
	 */
	public static function get_locations_within_radius(
		string $postal_code,
		float  $radius,
		string $unit = 'miles',
		array  $args = array()
	): array {
		$lat = $args['lat'] ?? null;
		$lng = $args['lng'] ?? null;

		if ( null !== $lat && null !== $lng && self::is_valid_coordinate( (float) $lat, (float) $lng ) ) {
			$ref_lat = (float) $lat;
			$ref_lng = (float) $lng;
		} else {
			$country = $args['country'] ?? 'US';
			$postal_code = self::normalize_postal_code( $postal_code, $country );
			if ( '' === $postal_code ) {
				return array();
			}
			$centroid = self::get_centroid( $postal_code, $country );
			if ( null === $centroid ) {
				return array();
			}
			$ref_lat = $centroid['lat'];
			$ref_lng = $centroid['lng'];
		}

		return self::query_locations_near( $ref_lat, $ref_lng, $radius, $unit, $args );
	}

	/**
	 * Check whether a single location row is within radius of a coordinate pair.
	 *
	 * @since 1.0.0
	 * @param object|array $location Location row with lat/lng or postal_code/country fields.
	 * @param float        $ref_lat  Reference latitude.
	 * @param float        $ref_lng  Reference longitude.
	 * @param float        $radius   Search radius.
	 * @param string       $unit     'miles' or 'km'.
	 * @return bool
	 */
	public static function is_within_radius( $location, float $ref_lat, float $ref_lng, float $radius, string $unit = 'miles' ): bool {
		$location = (object) $location;
		$loc_lat  = null;
		$loc_lng  = null;

		if ( isset( $location->lat ) && null !== $location->lat && null !== $location->lng ) {
			$loc_lat = (float) $location->lat;
			$loc_lng = (float) $location->lng;
		} elseif ( isset( $location->postal_code ) && isset( $location->country ) ) {
			$centroid = self::get_centroid( $location->postal_code, $location->country );
			if ( $centroid ) {
				$loc_lat = $centroid['lat'];
				$loc_lng = $centroid['lng'];
			}
		}

		if ( null === $loc_lat ) {
			return false;
		}

		$distance_km = self::great_circle_distance( $ref_lat, $ref_lng, $loc_lat, $loc_lng );
		$limit_km    = 'miles' === $unit ? $radius * self::KM_PER_MILE : $radius;

		return $distance_km <= $limit_km;
	}

	/**
	 * Resolve a directions provider slug to a valid value.
	 *
	 * Accepts an explicit provider slug or empty string. Empty string (or any
	 * unrecognized value) falls back to the site-level atcf_directions_provider
	 * setting, which itself defaults to 'google'.
	 *
	 * @since 1.0.0
	 * @param string $pref Explicit provider slug, or '' to use the site setting.
	 * @return string One of: 'google', 'apple', 'waze', 'osm', 'geo'.
	 */
	public static function resolve_provider( string $pref = '' ): string {
		$valid = array( 'google', 'apple', 'waze', 'osm', 'geo' );
		if ( in_array( $pref, $valid, true ) ) {
			return $pref;
		}
		$site = (string) get_option( 'atcf_directions_provider', 'google' );
		return in_array( $site, $valid, true ) ? $site : 'google';
	}

	/**
	 * Return a directions URL for a location.
	 *
	 * @since 1.0.0
	 * @param object|array $location Location row.
	 * @param string       $provider 'google' (default), 'apple', 'osm', 'waze', 'geo'.
	 * @return string URL, or empty string if no usable coordinates or address.
	 */
	public static function get_directions_url( $location, string $provider = 'google' ): string {
		$location = (object) $location;

		$has_coords = isset( $location->lat ) && null !== $location->lat
			&& isset( $location->lng ) && null !== $location->lng;

		$lat = $has_coords ? (float) $location->lat : null;
		$lng = $has_coords ? (float) $location->lng : null;

		// Build address string from normalized line 1.
		$address_parts = array_filter( array(
			$location->address_line_normalized ?? $location->address_line ?? '',
			$location->locality ?? '',
			$location->region   ?? '',
			$location->postal_code ?? '',
		) );
		$address_str = implode( ', ', $address_parts );

		switch ( $provider ) {
			case 'apple':
				if ( $has_coords ) {
					return "https://maps.apple.com/?daddr={$lat},{$lng}";
				}
				return 'https://maps.apple.com/?daddr=' . rawurlencode( $address_str );

			case 'osm':
				if ( $has_coords ) {
					return "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}";
				}
				return '';

			case 'waze':
				if ( $has_coords ) {
					return "https://waze.com/ul?ll={$lat},{$lng}&navigate=yes";
				}
				return '';

			case 'geo':
				if ( $has_coords ) {
					$label = rawurlencode( $location->label ?? '' );
					return "geo:{$lat},{$lng}?q={$label}";
				}
				return '';

			case 'google':
			default:
				if ( $has_coords ) {
					return "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}";
				}
				return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $address_str );
		}
	}

	/**
	 * Return numeric distance from a location row (must have distance_km or distance_miles set).
	 *
	 * @since 1.0.0
	 * @param object|array $location Location row returned by get_locations_within_radius().
	 * @param string       $unit     'miles' or 'km'.
	 * @return float|null
	 */
	public static function get_the_distance( $location, string $unit = 'miles' ): ?float {
		$location = (object) $location;
		if ( 'km' === $unit ) {
			return isset( $location->distance_km ) ? (float) $location->distance_km : null;
		}
		return isset( $location->distance_miles ) ? (float) $location->distance_miles : null;
	}

	/**
	 * Return a human-readable distance string.
	 *
	 * @since 1.0.0
	 * @param object|array $location       Location row with distance_km/distance_miles.
	 * @param string       $unit           'miles' or 'km'.
	 * @param string       $less_than_one  String for < 1 unit. Default 'less than a mile away'.
	 * @param string       $one            String for ~1 unit (1 to 1.5 units). Default 'about a mile away'.
	 * @param string       $more_than_one  sprintf format for > 1. Default 'About %s miles away'.
	 * @return string
	 */
	public static function the_distance(
		$location,
		string $unit           = 'miles',
		string $less_than_one  = '',
		string $one            = '',
		string $more_than_one  = ''
	): string {
		if ( '' === $less_than_one ) {
			$less_than_one = 'km' === $unit
				? __( 'less than a kilometer away', 'as-the-crow-flies' )
				: __( 'less than a mile away', 'as-the-crow-flies' );
		}
		if ( '' === $one ) {
			$one = 'km' === $unit
				? __( 'about a kilometer away', 'as-the-crow-flies' )
				: __( 'about a mile away', 'as-the-crow-flies' );
		}
		if ( '' === $more_than_one ) {
			$more_than_one = 'km' === $unit
				/* translators: %s: distance in km */
				? __( 'About %s km away', 'as-the-crow-flies' )
				/* translators: %s: distance in miles */
				: __( 'About %s miles away', 'as-the-crow-flies' );
		}

		$distance = self::get_the_distance( $location, $unit );
		if ( null === $distance ) {
			return '';
		}

		if ( $distance < 1 ) {
			return $less_than_one;
		}
		if ( $distance < 1.5 ) {
			return $one;
		}
		return sprintf( $more_than_one, number_format( $distance, 0 ) );
	}

	/**
	 * Find the nearest postal code to a coordinate pair.
	 *
	 * Returns the single closest centroid in the country. There is no implicit
	 * distance limit; the returned `distance_km` lets callers judge proximity, and
	 * the optional `$max_km` bound makes the method return null when the nearest
	 * centroid is farther than that.
	 *
	 * @since 1.0.0
	 * @param float  $lat     Latitude.
	 * @param float  $lng     Longitude.
	 * @param string $country Country code. Default 'US'.
	 * @param float  $max_km  Optional maximum distance in km; 0 (default) = no limit.
	 * @return array|null Array with postal_code, country, distance_km; or null if not found
	 *                    (or beyond $max_km).
	 */
	public static function get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 ): ?array {
		global $wpdb;

		if ( ! self::is_valid_coordinate( $lat, $lng ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT postal_code, country,
				 ( %f * acos( LEAST( 1, GREATEST( -1,
				     cos(radians(%f)) * cos(radians(lat)) *
				     cos(radians(lng) - radians(%f)) +
				     sin(radians(%f)) * sin(radians(lat))
				 ) ) ) ) AS distance_km
				 FROM {$wpdb->prefix}atcf_postal_centroids
				 WHERE country = %s
				 ORDER BY distance_km ASC
				 LIMIT 1",
				self::EARTH_RADIUS_KM,
				$lat, $lng, $lat,
				$country
			)
		);

		if ( ! $row ) {
			return null;
		}

		if ( $max_km > 0 && (float) $row->distance_km > $max_km ) {
			return null;
		}

		return array(
			'postal_code' => $row->postal_code,
			'country'     => $row->country,
			'distance_km' => (float) $row->distance_km,
		);
	}

	// --------------------------------------------------------------------------
	// Normalization
	// --------------------------------------------------------------------------

	/**
	 * Normalize a postal code for a given country.
	 *
	 * @since 1.0.0
	 * @param string $postal_code Raw postal code.
	 * @param string $country     ISO 3166-1 alpha-2 country code.
	 * @return string Normalized postal code, or empty string if invalid.
	 */
	public static function normalize_postal_code( string $postal_code, string $country = 'US' ): string {
		$postal_code = trim( $postal_code );

		switch ( strtoupper( $country ) ) {
			case 'US':
				// Strip Zip+4 suffix.
				$postal_code = preg_replace( '/^(\d{5})-?\d{4}$/', '$1', $postal_code );
				if ( ! preg_match( '/^\d{5}$/', $postal_code ) ) {
					return '';
				}
				return $postal_code;

			case 'CA':
				// Uppercase, strip space, keep first 3 chars (FSA) or full 6.
				$postal_code = strtoupper( str_replace( ' ', '', $postal_code ) );
				if ( preg_match( '/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal_code ) ) {
					return substr( $postal_code, 0, 3 ) . ' ' . substr( $postal_code, 3 );
				}
				if ( preg_match( '/^[A-Z]\d[A-Z]$/', $postal_code ) ) {
					return $postal_code;
				}
				return '';

			case 'GB':
				$postal_code = strtoupper( preg_replace( '/\s+/', '', $postal_code ) );
				$pattern     = atcf_get_postal_code_format( 'GB' );
				return ( $pattern && preg_match( $pattern, $postal_code ) ) ? $postal_code : '';

			default:
				return $postal_code;
		}
	}

	// --------------------------------------------------------------------------
	// Coordinate validation
	// --------------------------------------------------------------------------

	/**
	 * Check that lat/lng values are within valid ranges.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool
	 */
	public static function is_valid_coordinate( float $lat, float $lng ): bool {
		return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0
			&& ! ( 0.0 === $lat && 0.0 === $lng ); // Null Island guard.
	}

	// --------------------------------------------------------------------------
	// Internal helpers
	// --------------------------------------------------------------------------

	/**
	 * Look up a centroid by postal code and country.
	 *
	 * @param string $postal_code Normalized postal code.
	 * @param string $country     Country code.
	 * @return array{lat: float, lng: float}|null
	 */
	public static function get_centroid( string $postal_code, string $country ): ?array {
		global $wpdb;

		static $cache = array();
		$key = $country . ':' . $postal_code;

		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}

		if ( count( $cache ) >= 1000 ) {
			$cache = array_slice( $cache, 500, null, true );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lat, lng FROM {$wpdb->prefix}atcf_postal_centroids WHERE country = %s AND postal_code = %s LIMIT 1",
				$country,
				$postal_code
			)
		);

		$cache[ $key ] = $row ? array( 'lat' => (float) $row->lat, 'lng' => (float) $row->lng ) : null;
		return $cache[ $key ];
	}

	/**
	 * Run the core distance query and return augmented location rows.
	 *
	 * @param float  $ref_lat Reference latitude.
	 * @param float  $ref_lng Reference longitude.
	 * @param float  $radius  Search radius.
	 * @param string $unit    'miles' or 'km'.
	 * @param array  $args    taxonomy, term_id, country, limit, orderby.
	 * @return array[]
	 */
	private static function query_locations_near(
		float  $ref_lat,
		float  $ref_lng,
		float  $radius,
		string $unit,
		array  $args
	): array {
		global $wpdb;

		$radius_km = 'miles' === $unit ? $radius * self::KM_PER_MILE : $radius;
		$limit     = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;
		$orderby   = $args['orderby'] ?? 'distance';

		$where  = array();
		$params = array(
			self::EARTH_RADIUS_KM,
			$ref_lat, $ref_lng, $ref_lat,
		);

		if ( ! empty( $args['taxonomy'] ) ) {
			$where[]  = 'l.taxonomy = %s';
			$params[] = $args['taxonomy'];
		}

		$term_ids_filter = array_values( array_filter( array_map( 'intval', (array) ( $args['term_ids'] ?? array() ) ) ) );
		if ( ! empty( $term_ids_filter ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $term_ids_filter ), '%d' ) );
			$where[]      = "l.term_id IN ({$placeholders})";
			$params       = array_merge( $params, $term_ids_filter );
		} elseif ( ! empty( $args['term_id'] ) ) {
			$where[]  = 'l.term_id = %d';
			$params[] = (int) $args['term_id'];
		}

		if ( ! empty( $args['country'] ) ) {
			$where[]  = 'l.country = %s';
			$params[] = $args['country'];
		}

		// Disabled countries excluded.
		$disabled_countries = self::get_disabled_countries();
		if ( ! empty( $disabled_countries ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $disabled_countries ), '%s' ) );
			$where[]      = "l.country NOT IN ({$placeholders})";
			$params       = array_merge( $params, $disabled_countries );
		}

		// Bounding-box pre-filter: skip the expensive haversine for rows clearly
		// outside the radius. The box is a superset of the radius circle, so it
		// never drops a true match (the HAVING still filters precisely). Applied to
		// the coalesced coords so centroid-fallback rows are preserved.
		//
		// The latitude box is always a valid superset. The longitude box is only
		// safe when it cannot wrap the antimeridian or span a pole; otherwise it
		// could wrongly exclude in-range points, so it is skipped (HAVING still
		// catches them).
		$lat_delta = $radius_km / 110.574;
		$where[]   = 'COALESCE(l.lat, c.lat) BETWEEN %f AND %f';
		$params[]  = $ref_lat - $lat_delta;
		$params[]  = $ref_lat + $lat_delta;

		$cos = cos( deg2rad( $ref_lat ) );
		if ( $cos > 0.0001 ) {
			$lng_delta = $radius_km / ( 111.320 * $cos );
			if ( $lng_delta < 180.0
				&& ( $ref_lat + $lat_delta ) < 90.0 && ( $ref_lat - $lat_delta ) > -90.0
				&& ( $ref_lng - $lng_delta ) >= -180.0 && ( $ref_lng + $lng_delta ) <= 180.0 ) {
				$where[]  = 'COALESCE(l.lng, c.lng) BETWEEN %f AND %f';
				$params[] = $ref_lng - $lng_delta;
				$params[] = $ref_lng + $lng_delta;
			}
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		switch ( $orderby ) {
			case 'label':
				$order_sql = 'l.label ASC';
				break;
			case 'term_name':
				$order_sql = 'l.term_id ASC';
				break;
			default:
				$order_sql = 'distance_km ASC';
				break;
		}

		$params[] = $radius_km;
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(

				"SELECT l.*,
				 ( %f * acos( LEAST( 1, GREATEST( -1,
				     cos(radians(%f)) * cos(radians(COALESCE(l.lat, c.lat))) *
				     cos(radians(COALESCE(l.lng, c.lng)) - radians(%f)) +
				     sin(radians(%f)) * sin(radians(COALESCE(l.lat, c.lat)))
				 ) ) ) ) AS distance_km
				 FROM {$wpdb->prefix}atcf_locations l
				 LEFT JOIN {$wpdb->prefix}atcf_postal_centroids c
				        ON c.country = l.country AND c.postal_code = l.postal_code
				 {$where_sql}
				 HAVING distance_km <= %f AND distance_km IS NOT NULL
				 ORDER BY {$order_sql}
				 LIMIT %d",
				$params
			),
			ARRAY_A
		);

		// Append computed fields to each row.
		foreach ( $rows as &$row ) {
			$row['distance_km']    = round( (float) $row['distance_km'], 2 );
			$row['distance_miles'] = round( (float) $row['distance_km'] / self::KM_PER_MILE, 2 );
			$parts                 = array_filter( array( $row['address_line'] ?? '', $row['address_line2'] ?? '' ) );
			$row['street_address'] = implode( ', ', $parts );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Spherical law of cosines — great-circle distance in km between two coordinates.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float Distance in km.
	 */
	public static function great_circle_distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$lat1_r = deg2rad( $lat1 );
		$lat2_r = deg2rad( $lat2 );
		$dlng_r = deg2rad( $lng2 - $lng1 );

		// Clamp to [-1, 1] before acos() to guard against floating-point
		// rounding that produces values like 1.0000000000000002 on identical
		// coordinates, which would make acos() return NaN.
		$cos_val = cos( $lat1_r ) * cos( $lat2_r ) * cos( $dlng_r )
			+ sin( $lat1_r ) * sin( $lat2_r );
		$cos_val = max( -1.0, min( 1.0, $cos_val ) );

		return self::EARTH_RADIUS_KM * acos( $cos_val );
	}

	/**
	 * Return country codes for which centroid data is disabled.
	 *
	 * @return string[]
	 */
	private static function get_disabled_countries(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$disabled = array();

		if ( ! get_option( 'atcf_us_centroids_enabled', 1 ) ) {
			$disabled[] = 'US';
		}

		$country_data = get_option( 'atcf_country_centroids', array() );
		foreach ( $country_data as $code => $data ) {
			if ( empty( $data['enabled'] ) ) {
				$disabled[] = $code;
			}
		}

		$cache = $disabled;
		return $cache;
	}
}

// --------------------------------------------------------------------------
// Public API functions
// --------------------------------------------------------------------------

/**
 * Return term IDs within radius of a postal code.
 *
 * @since 1.0.0
 * @param string $postal_code Reference postal code.
 * @param float  $radius      Search radius.
 * @param string $unit        'miles' or 'km'.
 * @param string $taxonomy    Taxonomy slug to limit; empty = all.
 * @param string $country     Country code. Default 'US'.
 * @return int[]
 */
function atcf_get_term_ids_within_radius(
	string $postal_code,
	float  $radius,
	string $unit = 'miles',
	string $taxonomy = '',
	string $country = 'US'
): array {
	return ATCF_Distance::get_term_ids_within_radius( $postal_code, $radius, $unit, $taxonomy, $country );
}

/**
 * Return full location rows within radius of a reference point.
 *
 * @since 1.0.0
 * @param string $postal_code Reference postal code (ignored when lat/lng in $args).
 * @param float  $radius      Search radius.
 * @param string $unit        'miles' or 'km'.
 * @param array  $args        Optional: taxonomy, term_id, country, limit, orderby, lat, lng.
 * @return array[]
 */
function atcf_get_locations_within_radius(
	string $postal_code,
	float  $radius,
	string $unit = 'miles',
	array  $args = array()
): array {
	return ATCF_Distance::get_locations_within_radius( $postal_code, $radius, $unit, $args );
}

/**
 * Check whether a single location is within radius of a coordinate pair.
 *
 * @since 1.0.0
 * @param object|array $location Location row.
 * @param float        $lat      Reference latitude.
 * @param float        $lng      Reference longitude.
 * @param float        $radius   Search radius.
 * @param string       $unit     'miles' or 'km'.
 * @return bool
 */
function atcf_is_within_radius( $location, float $lat, float $lng, float $radius, string $unit = 'miles' ): bool {
	return ATCF_Distance::is_within_radius( $location, $lat, $lng, $radius, $unit );
}

/**
 * Return a directions URL for a location.
 *
 * @since 1.0.0
 * @param object|array $location Location row.
 * @param string       $provider 'google' (default), 'apple', 'osm', 'waze', 'geo'.
 * @return string
 */
function atcf_get_directions_url( $location, string $provider = 'google' ): string {
	return ATCF_Distance::get_directions_url( $location, $provider );
}

/**
 * Resolve a directions provider slug to a valid value.
 *
 * @since 1.0.0
 * @param string $pref Explicit provider slug, or '' to use the site setting.
 * @return string One of: 'google', 'apple', 'waze', 'osm', 'geo'.
 */
function atcf_resolve_directions_provider( string $pref = '' ): string {
	return ATCF_Distance::resolve_provider( $pref );
}

/**
 * Return numeric distance from a location row.
 *
 * @since 1.0.0
 * @param object|array $location Location row with distance_km/distance_miles set.
 * @param string       $unit     'miles' or 'km'.
 * @return float|null
 */
function atcf_get_the_distance( $location, string $unit = 'miles' ): ?float {
	return ATCF_Distance::get_the_distance( $location, $unit );
}

/**
 * Echo a human-readable distance string.
 *
 * @since 1.0.0
 * @param object|array $location      Location row.
 * @param string       $unit          'miles' or 'km'.
 * @param string       $less_than_one String for < 1 unit.
 * @param string       $one           String for ~1 unit.
 * @param string       $more_than_one sprintf pattern for > 1 unit.
 */
function atcf_the_distance(
	$location,
	string $unit          = 'miles',
	string $less_than_one = '',
	string $one           = '',
	string $more_than_one = ''
): void {
	echo esc_html( ATCF_Distance::the_distance( $location, $unit, $less_than_one, $one, $more_than_one ) );
}

/**
 * Find the nearest postal code to a coordinate pair.
 *
 * @since 1.0.0
 * @param float  $lat     Latitude.
 * @param float  $lng     Longitude.
 * @param string $country Country code. Default 'US'.
 * @param float  $max_km  Optional maximum distance in km; 0 (default) = no limit.
 * @return array|null Array with postal_code, country, distance_km; or null.
 */
function atcf_get_nearest_postal_code( float $lat, float $lng, string $country = 'US', float $max_km = 0.0 ): ?array {
	return ATCF_Distance::get_nearest_postal_code( $lat, $lng, $country, $max_km );
}

/**
 * Return the centroid coordinates for a postal code.
 *
 * @since 1.0.0
 * @param string $postal_code Postal code (should already be normalized/uppercased).
 * @param string $country     ISO 3166-1 alpha-2 country code. Default 'US'.
 * @return array{lat: float, lng: float}|null Coordinates, or null if not found.
 */
function atcf_get_postal_centroid( string $postal_code, string $country = 'US' ): ?array {
	return ATCF_Distance::get_centroid( $postal_code, $country );
}

/**
 * Return all term IDs in a taxonomy that have at least one ATCF location.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return int[]
 */
function atcf_get_term_ids_with_locations( string $taxonomy ): array {
	$cache_key = 'term_ids_with_locations_' . $taxonomy;
	$cached    = wp_cache_get( $cache_key, 'atcf' );
	if ( false !== $cached ) {
		return $cached;
	}
	global $wpdb;
	$rows   = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s",
		$taxonomy
	) );
	$result = array_map( 'intval', $rows );
	// Invalidated on atcf_location_changed (see ATCF_Admin::flush_location_caches()), including the
	// unscoped ('') form fired by bulk operations, which flushes every taxonomy's key. The
	// 5-minute TTL is only a safety net.
	wp_cache_set( $cache_key, $result, 'atcf', 5 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * Find local terms whose stored locations match any location in the given list.
 *
 * Compares using the criteria configured in Settings → General → Location Matching.
 * Input locations should be Schema.org Place objects as returned by the htax_locations
 * REST field on HyperTaxonomy term responses.
 *
 * Matching mode:
 *   'address' — normalize and compare combined address (line1 + line2) + postal code + country.
 *   'coords'  — compare coordinates; exact when radius = 0, proximity when radius > 0.
 *   'either'  — address OR coordinates (union of both).
 *   'both'    — address AND coordinates (intersection; local location must satisfy both).
 *
 * Locations missing the fields required by the active mode are silently skipped.
 * Local locations with blank address (when address matching applies) or blank
 * coordinates (when coordinate matching applies) are excluded from results.
 *
 * @since 1.0.0
 * @param array[] $schema_locations Array of Schema.org Place objects from htax_locations.
 * @return int[]  Deduplicated local term IDs with matching locations. Empty on no match.
 */
function atcf_find_terms_by_location( array $schema_locations ): array {
	global $wpdb;

	if ( empty( $schema_locations ) || ! class_exists( 'ATCF_Locations' ) ) {
		return array();
	}

	$mode = (string) get_option( 'atcf_location_match_mode', 'either' );
	if ( ! in_array( $mode, array( 'address', 'coords', 'either', 'both' ), true ) ) {
		$mode = 'either';
	}

	$require_label_address = (bool) get_option( 'atcf_match_require_label_address', false );
	$require_label_coords  = (bool) get_option( 'atcf_match_require_label_coords',  false );

	$table     = $wpdb->prefix . 'atcf_locations';
	$units     = (string) get_option( 'atcf_distance_units', 'miles' );
	$raw_rad   = (float) get_option( 'atcf_find_terms_radius', 50.0 );
	$radius_km = 'km' === $units ? $raw_rad : $raw_rad * ATCF_Distance::KM_PER_MILE;

	$term_ids = array();

	foreach ( $schema_locations as $place ) {
		if ( ! is_array( $place ) ) {
			continue;
		}

		$geo     = $place['schema:geo']     ?? array();
		$addr    = $place['schema:address'] ?? array();
		$label   = sanitize_text_field( $place['schema:name'] ?? '' );
		$country = strtoupper( sanitize_text_field( $addr['schema:addressCountry'] ?? '' ) );
		$postal  = ATCF_Distance::normalize_postal_code(
			sanitize_text_field( $addr['schema:postalCode'] ?? '' ),
			$country
		);

		// schema:streetAddress already combines line1 and line2 with a comma separator.
		$raw_street  = sanitize_text_field( $addr['schema:streetAddress'] ?? '' );
		$street_norm = ATCF_Locations::normalize_address_line1( $raw_street );

		$lat = isset( $geo['schema:latitude'] )  && '' !== (string) $geo['schema:latitude']
			? (float) $geo['schema:latitude']  : null;
		$lng = isset( $geo['schema:longitude'] ) && '' !== (string) $geo['schema:longitude']
			? (float) $geo['schema:longitude'] : null;

		$has_address = '' !== $street_norm && '' !== $postal && '' !== $country;
		$has_coords  = null !== $lat && null !== $lng;

		if ( 'address' === $mode && ! $has_address ) { continue; }
		if ( 'coords'  === $mode && ! $has_coords  ) { continue; }
		if ( 'both'    === $mode && ( ! $has_address || ! $has_coords ) ) { continue; }
		if ( 'either'  === $mode && ! $has_address && ! $has_coords ) { continue; }

		$from_address = array();
		$from_coords  = array();

		// --- Address criterion ---
		if ( $has_address && in_array( $mode, array( 'address', 'either', 'both' ), true ) ) {
			$candidates = $wpdb->get_results( $wpdb->prepare(
				"SELECT term_id, address_line, address_line2, label
				   FROM {$table}
				  WHERE postal_code = %s AND country = %s AND address_line != ''",
				$postal, $country
			) );
			foreach ( $candidates as $row ) {
				$combined = trim( $row->address_line . ' ' . $row->address_line2 );
				$row_norm = ATCF_Locations::normalize_address_line1( $combined );
				if ( '' === $row_norm || $row_norm !== $street_norm ) {
					continue;
				}
				if ( $require_label_address
					&& strtolower( trim( $row->label ) ) !== strtolower( $label ) ) {
					continue;
				}
				$from_address[ (int) $row->term_id ] = true;
			}
		}

		// --- Coordinates criterion ---
		if ( $has_coords && in_array( $mode, array( 'coords', 'either', 'both' ), true ) ) {
			if ( $radius_km <= 0.0 ) {
				// Exact match at the columns' own DECIMAL(10,7) precision. The values are
				// passed as 7-decimal strings because wpdb's %f placeholder formats only
				// 6 decimals, which would never equal a stored 7th decimal digit.
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT term_id, label FROM {$table} WHERE lat = CAST( %s AS DECIMAL(10,7) ) AND lng = CAST( %s AS DECIMAL(10,7) )",
					number_format( (float) $lat, 7, '.', '' ),
					number_format( (float) $lng, 7, '.', '' )
				) );
				foreach ( $rows as $row ) {
					if ( $require_label_coords
						&& strtolower( trim( $row->label ) ) !== strtolower( $label ) ) {
						continue;
					}
					$from_coords[ (int) $row->term_id ] = true;
				}
			} else {
				$lat_delta  = $radius_km / 110.574;
				$lng_delta  = $radius_km / ( 111.320 * max( 0.001, cos( deg2rad( $lat ) ) ) );
				$candidates = $wpdb->get_results( $wpdb->prepare(
					"SELECT term_id, lat, lng, label FROM {$table}
					  WHERE lat IS NOT NULL AND lng IS NOT NULL
					    AND lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f",
					$lat - $lat_delta, $lat + $lat_delta, $lng - $lng_delta, $lng + $lng_delta
				) );
				foreach ( $candidates as $row ) {
					if ( ATCF_Distance::great_circle_distance( $lat, $lng, (float) $row->lat, (float) $row->lng ) > $radius_km ) {
						continue;
					}
					if ( $require_label_coords
						&& strtolower( trim( $row->label ) ) !== strtolower( $label ) ) {
						continue;
					}
					$from_coords[ (int) $row->term_id ] = true;
				}
			}
		}

		// Combine according to mode: AND-mode intersects, all others union.
		$matched = 'both' === $mode
			? array_intersect_key( $from_address, $from_coords )
			: ( $from_address + $from_coords );

		foreach ( array_keys( $matched ) as $tid ) {
			if ( $tid > 0 ) {
				$term_ids[] = $tid;
			}
		}
	}

	return array_values( array_unique( array_filter( $term_ids ) ) );
}

/**
 * Return the visitor's location as reported by the site's CDN in request headers.
 *
 * Cloudflare adds CF-IPLatitude / CF-IPLongitude (plus CF-IPCountry and
 * CF-Postal-Code) when its "Add visitor location headers" managed transform is on.
 * No third party is contacted, so this is used even when IP geolocation is disabled.
 * Other CDNs can be mapped with the atcf_cdn_location filter.
 *
 * @since 1.0.0
 * @return array{lat: float, lng: float, country: string, postal_code: string}|null
 */
function atcf_get_cdn_location(): ?array {
	$location = null;
	$lat      = isset( $_SERVER['HTTP_CF_IPLATITUDE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPLATITUDE'] ) ) : '';
	$lng      = isset( $_SERVER['HTTP_CF_IPLONGITUDE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPLONGITUDE'] ) ) : '';
	if ( is_numeric( $lat ) && is_numeric( $lng ) && abs( (float) $lat ) <= 90 && abs( (float) $lng ) <= 180 ) {
		$location = array(
			'lat'         => (float) $lat,
			'lng'         => (float) $lng,
			'country'     => strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) ) ),
			'postal_code' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_POSTAL_CODE'] ?? '' ) ),
		);
	}

	/**
	 * Filter the visitor location taken from CDN request headers.
	 *
	 * @since 1.0.0
	 * @param array|null $location { lat, lng, country, postal_code } or null.
	 */
	$location = apply_filters( 'atcf_cdn_location', $location );
	return is_array( $location ) && isset( $location['lat'], $location['lng'] ) ? $location : null;
}

/**
 * Return the visitor's location for "near me" features — the one place the location
 * chain is defined, used by ATCF's shortcodes, blocks and REST endpoint and by
 * HyperTaxonomy's near:me searches.
 *
 * Order (most precise / most explicitly given first):
 *   1. 'browser'  — coordinates the page's script sent with the request
 *                   (_atcf_lat / _atcf_lng; see atcfGeo.getBrowserLocation()),
 *   2. 'cdn'      — the site's CDN location headers (no third party involved),
 *   3. 'ip'       — the IP geolocation provider (when enabled in settings),
 *   4. 'fallback' — the "Fallback location" setting.
 * A place the visitor typed (postal code, term) is not part of the chain: callers use it
 * instead of calling this. The 'source' key tells callers which step answered, e.g. to
 * tell the visitor that results are near the fallback location.
 *
 * @since 1.0.0
 * @param bool $include_request Whether to consider coordinates sent with the request.
 * @return array{lat: float, lng: float, country: string, postal_code: string, source: string}|null
 *         null when no step answers.
 */
function atcf_get_visitor_location( bool $include_request = true ): ?array {
	if ( $include_request && isset( $_REQUEST['_atcf_lat'], $_REQUEST['_atcf_lng'] )
		&& is_numeric( $_REQUEST['_atcf_lat'] ) && is_numeric( $_REQUEST['_atcf_lng'] )
		&& ATCF_Distance::is_valid_coordinate( (float) $_REQUEST['_atcf_lat'], (float) $_REQUEST['_atcf_lng'] ) ) {
		return array(
			'lat'         => (float) $_REQUEST['_atcf_lat'],
			'lng'         => (float) $_REQUEST['_atcf_lng'],
			'country'     => '',
			'postal_code' => '',
			'source'      => 'browser',
		);
	}
	$cdn = atcf_get_cdn_location();
	if ( $cdn ) {
		return $cdn + array( 'source' => 'cdn' );
	}
	$ip = atcf_get_ip_location();
	if ( $ip ) {
		return $ip + array( 'source' => 'ip' );
	}
	$fallback = get_option( 'atcf_fallback_location', array() );
	if ( is_array( $fallback ) && isset( $fallback['lat'], $fallback['lng'] ) ) {
		return array(
			'lat'         => (float) $fallback['lat'],
			'lng'         => (float) $fallback['lng'],
			'country'     => '',
			'postal_code' => '',
			'source'      => 'fallback',
		);
	}
	return null;
}

/**
 * Return the visitor's approximate location from their IP address.
 *
 * Uses the site's CDN location headers when present (atcf_get_cdn_location()), otherwise
 * the configured provider (FreeIPAPI by default — free, no key required). Provider
 * results are cached in a WordPress transient keyed by the visitor's IP. Returns null
 * when neither is available: IP geolocation disabled in settings, the IP cannot be
 * determined (e.g. a private address), or the API call fails.
 *
 * @since 1.0.0
 * @return array{lat: float, lng: float, country: string, postal_code: string}|null
 */
function atcf_get_ip_location(): ?array {
	$cdn = atcf_get_cdn_location();
	if ( $cdn ) {
		return $cdn;
	}
	if ( ! get_option( 'atcf_ip_geo_enabled', true ) ) {
		return null;
	}

	$ip_headers = apply_filters( 'atcf_ip_headers', array( 'REMOTE_ADDR' ) );
	$ip = '';
	foreach ( $ip_headers as $header ) {
		$val = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ?? '' ) );
		if ( '' !== $val && filter_var( $val, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$ip = $val;
			break;
		}
	}
	if ( '' === $ip ) {
		return null;
	}

	$provider  = (string) get_option( 'atcf_ip_geo_provider', 'freeipapi' );
	$cache_key = 'atcf_ip_' . md5( $provider . $ip );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached ?: null;
	}

	switch ( $provider ) {
		case 'ipapi':
			$url = 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/';
			break;
		case 'ipwho':
			$url = 'https://ipwho.is/' . rawurlencode( $ip );
			break;
		default:
			$url = 'https://freeipapi.com/api/json/' . rawurlencode( $ip );
			break;
	}

	$response = wp_remote_get( $url, array( 'timeout' => 3 ) );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, array(), 300 );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	// Provider-specific error signals.
	if ( ! empty( $body['error'] ) || ( isset( $body['success'] ) && ! $body['success'] ) ) {
		set_transient( $cache_key, array(), 300 );
		return null;
	}

	if ( ! isset( $body['latitude'], $body['longitude'] )
		|| '' === (string) $body['latitude']
		|| '' === (string) $body['longitude'] ) {
		set_transient( $cache_key, array(), 300 );
		return null;
	}

	// FreeIPAPI uses 'countryCode'/'zipCode'; ipapi.co and ipwho.is use 'country_code'/'postal'.
	$country     = 'freeipapi' === $provider ? ( $body['countryCode'] ?? 'US' ) : ( $body['country_code'] ?? 'US' );
	$postal_code = 'freeipapi' === $provider ? ( $body['zipCode'] ?? '' ) : ( $body['postal'] ?? '' );

	$result = array(
		'lat'         => (float) $body['latitude'],
		'lng'         => (float) $body['longitude'],
		'country'     => strtoupper( sanitize_text_field( $country ) ),
		'postal_code' => sanitize_text_field( $postal_code ),
	);

	$ttl = (int) get_option( 'atcf_ip_geo_ttl', 86400 );
	set_transient( $cache_key, $result, $ttl );
	return $result;
}

/**
 * Return a regex pattern that validates postal codes for a given country.
 *
 * Used by callers (e.g. HyperTaxonomy's geo search field) to validate user
 * input before submission and to distinguish postal codes from term slugs in
 * queries like `near:X`. Returns null for countries without a known format —
 * callers should treat that as "no validation possible."
 *
 * Patterns are intentionally permissive (they signal the correct format, not
 * exhaustively validate every edge case) and match against the uppercase form
 * of the input.
 *
 * @since 1.0.0
 * @param string $country ISO 3166-1 alpha-2 country code (e.g. 'US', 'CA').
 * @return string|null PCRE pattern anchored with ^ and $, or null.
 */
function atcf_get_postal_code_format( string $country ): ?string {
	$formats = array(
		'US' => '/^\d{5}(-\d{4})?$/',
		'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/',
		'GB' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/',
		'AU' => '/^\d{4}$/',
		'NZ' => '/^\d{4}$/',
		'DE' => '/^\d{5}$/',
		'FR' => '/^\d{5}$/',
		'IT' => '/^\d{5}$/',
		'ES' => '/^\d{5}$/',
		'NL' => '/^\d{4}\s?[A-Z]{2}$/',
		'BE' => '/^\d{4}$/',
		'CH' => '/^\d{4}$/',
		'AT' => '/^\d{4}$/',
		'SE' => '/^\d{3}\s?\d{2}$/',
		'NO' => '/^\d{4}$/',
		'DK' => '/^\d{4}$/',
		'FI' => '/^\d{5}$/',
		'PT' => '/^\d{4}(-\d{3})?$/',
		'PL' => '/^\d{2}-\d{3}$/',
		'CZ' => '/^\d{3}\s?\d{2}$/',
		'SK' => '/^\d{3}\s?\d{2}$/',
		'HU' => '/^\d{4}$/',
		'RO' => '/^\d{6}$/',
		'BG' => '/^\d{4}$/',
		'HR' => '/^\d{5}$/',
		'RS' => '/^\d{5}$/',
		'GR' => '/^\d{3}\s?\d{2}$/',
		'TR' => '/^\d{5}$/',
		'JP' => '/^\d{3}-\d{4}$/',
		'CN' => '/^\d{6}$/',
		'IN' => '/^\d{6}$/',
		'BR' => '/^\d{5}-?\d{3}$/',
		'MX' => '/^\d{5}$/',
		'AR' => '/^[A-Z]\d{4}[A-Z]{3}$/',
		'ZA' => '/^\d{4}$/',
		'SG' => '/^\d{6}$/',
		'MY' => '/^\d{5}$/',
		'ID' => '/^\d{5}$/',
		'PH' => '/^\d{4}$/',
		'TH' => '/^\d{5}$/',
		'KR' => '/^\d{5}$/',
		'TW' => '/^\d{3}(\d{2})?$/',
		'HK' => null, // No postal codes.
		'IE' => '/^[A-Z\d]{3}\s?[A-Z\d]{4}$/',
		'IL' => '/^\d{7}$/',
		'RU' => '/^\d{6}$/',
		'UA' => '/^\d{5}$/',
		'SA' => '/^\d{5}(-\d{4})?$/',
		'AE' => null, // No postal codes.
	);

	return $formats[ strtoupper( $country ) ] ?? null;
}
