<?php
/**
 * Frontend shortcodes.
 *
 * [atcf_locations] — location rows near a point
 * [atcf_terms]     — terms with locations near a point
 * [atcf_nearby]    — terms near another term's locations
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Shortcodes {

	/** @var bool Whether the shortcode being rendered used the site's fallback location. */
	private static $used_fallback_location = false;

	public static function register(): void {
		add_shortcode( 'atcf_locations', static fn( $atts ) => self::render_with_location_note( 'shortcode_locations', $atts ) );
		add_shortcode( 'atcf_terms',     static fn( $atts ) => self::render_with_location_note( 'shortcode_terms', $atts ) );
		add_shortcode( 'atcf_nearby',    static fn( $atts ) => self::render_with_location_note( 'shortcode_nearby', $atts ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Render a shortcode, telling the visitor when its results are near the site's fallback
	 * location rather than theirs (their location couldn't be determined).
	 *
	 * @since 1.0.0
	 * @param string       $method Shortcode method.
	 * @param array|string $atts   Shortcode attributes ('' when none).
	 * @return string
	 */
	private static function render_with_location_note( string $method, $atts ): string {
		self::$used_fallback_location = false;
		$output = (string) self::$method( (array) $atts );
		// Machine-readable output (format="json") stays as-is.
		if ( self::$used_fallback_location && '' !== $output && 0 !== strpos( $output, '<script' ) ) {
			$output = '<p class="atcf-location-note" role="status">' . esc_html( self::fallback_note() ) . '</p>' . $output;
		}
		return $output;
	}

	/**
	 * The visitor's location for a "near me" shortcode (see atcf_get_visitor_location()),
	 * noting when it is the site's fallback location.
	 *
	 * @since 1.0.0
	 * @return array|null
	 */
	private static function visitor_location(): ?array {
		$loc = atcf_get_visitor_location();
		if ( $loc && 'fallback' === $loc['source'] ) {
			self::$used_fallback_location = true;
		}
		return $loc;
	}

	/**
	 * Message shown when results are near the fallback location.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function fallback_note(): string {
		return __( "Showing results near the default location — we couldn't determine yours.", 'as-the-crow-flies' );
	}

	public static function enqueue_frontend_assets(): void {
		wp_register_style(
			'atcf-frontend',
			ATCF_PLUGIN_URL . 'assets/frontend.css',
			array(),
			ATCF_VERSION
		);

		wp_register_script(
			'atcf-frontend',
			ATCF_PLUGIN_URL . 'assets/frontend.js',
			array( 'jquery' ),
			ATCF_VERSION,
			true
		);

		wp_localize_script( 'atcf-frontend', 'atcfFrontend', array(
			'restUrl'            => esc_url_raw( rest_url( 'atcf/v1/' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'directionsProvider' => ATCF_Distance::resolve_provider(),
			'ipGeoEnabled'       => (bool) get_option( 'atcf_ip_geo_enabled', true ),
			'i18n'       => array(
				'locating'          => __( 'Finding your location…', 'as-the-crow-flies' ),
				'denied'            => __( 'Location access denied.', 'as-the-crow-flies' ),
				'fallbackNote'      => self::fallback_note(),
				'loading'           => __( 'Loading…', 'as-the-crow-flies' ),
				'noResults'         => __( 'No locations found in this area.', 'as-the-crow-flies' ),
				'error'             => __( 'An error occurred. Please try again.', 'as-the-crow-flies' ),
				'invalidPostalCode'  => __( 'Please enter a valid postal code.', 'as-the-crow-flies' ),
				'currentLocation'    => __( 'Current Location', 'as-the-crow-flies' ),
				'colLabel'           => __( 'Label', 'as-the-crow-flies' ),
				'colAddress'         => __( 'Address', 'as-the-crow-flies' ),
				'colDistance'        => __( 'Distance', 'as-the-crow-flies' ),
				'lessThanOne' => __( 'less than a mile away', 'as-the-crow-flies' ),
				'aboutOne'    => __( 'about a mile away', 'as-the-crow-flies' ),
				/* translators: %s: distance in miles */
				'aboutMiles'  => __( 'About %s miles away', 'as-the-crow-flies' ),
				'lessThanOneKm' => __( 'less than a kilometer away', 'as-the-crow-flies' ),
				'aboutOneKm'    => __( 'about a kilometer away', 'as-the-crow-flies' ),
				/* translators: %s: distance in km */
				'aboutKm'       => __( 'About %s km away', 'as-the-crow-flies' ),
			),
		) );
	}

	// --------------------------------------------------------------------------
	// [atcf_locations]
	// --------------------------------------------------------------------------

	/**
	 * Render location rows near a point.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_locations( array $atts ): string {
		$a = shortcode_atts( array(
			'postal_code'   => '',
			'lat'           => '',
			'lng'           => '',
			'country'       => 'US',
			'radius'        => 10,
			'unit'          => get_option( 'atcf_distance_units', 'miles' ),
			'taxonomy'      => '',
			'term'          => '',
			'group'         => '',
			'limit'         => 20,
			'orderby'       => 'distance',
			'format'             => 'ul',
			'item_template'      => '',
			'form'               => '',
			'input'              => 'zip,geolocation',
			'class'              => '',
			'directions_provider' => '',
		), $atts, 'atcf_locations' );

		$use_coords = '' !== $a['lat'] && '' !== $a['lng']
			&& ATCF_Distance::is_valid_coordinate( (float) $a['lat'], (float) $a['lng'] );

		// Expand HT group members into the term list before anything (including the
		// interactive-form path) branches on it, so a term/group restriction is never lost.
		$a['term'] = ATCF_HT_Integration::merge_group_terms_into_string( $a['group'], $a['term'], sanitize_key( $a['taxonomy'] ) );

		$term_ids_filter = array();
		if ( '' !== $a['term'] ) {
			$tax = sanitize_key( $a['taxonomy'] );
			foreach ( array_map( 'trim', explode( ',', $a['term'] ) ) as $t ) {
				if ( '' === $t ) {
					continue;
				}
				if ( is_numeric( $t ) ) {
					$term_ids_filter[] = (int) $t;
				} else {
					$resolved = get_term_by( 'slug', $t, $tax );
					if ( $resolved && ! is_wp_error( $resolved ) ) {
						$term_ids_filter[] = $resolved->term_id;
					}
				}
			}
		}

		if ( $a['form'] ) {
			return self::render_input_form( $a, 'locations', $a['input'], $a['postal_code'], $term_ids_filter );
		}

		if ( ! $use_coords && '' === $a['postal_code'] ) {
			$ip_loc = self::visitor_location();
			if ( $ip_loc ) {
				$use_coords   = true;
				$a['lat']     = (string) $ip_loc['lat'];
				$a['lng']     = (string) $ip_loc['lng'];
				$a['country'] = $ip_loc['country'] ?? $a['country'];
			} else {
				return '';
			}
		}

		$args = array(
			'taxonomy' => sanitize_key( $a['taxonomy'] ),
			'country'  => strtoupper( $a['country'] ),
			'limit'    => max( 1, (int) $a['limit'] ),
			'orderby'  => sanitize_key( $a['orderby'] ),
		);
		if ( 1 === count( $term_ids_filter ) ) {
			$args['term_id'] = $term_ids_filter[0];
		} elseif ( count( $term_ids_filter ) > 1 ) {
			$args['term_ids'] = $term_ids_filter;
		}

		if ( $use_coords ) {
			$args['lat'] = (float) $a['lat'];
			$args['lng'] = (float) $a['lng'];
		}

		$locations = ATCF_Distance::get_locations_within_radius(
			$a['postal_code'],
			(float) $a['radius'],
			$a['unit'],
			$args
		);

		wp_enqueue_style( 'atcf-frontend' );

		if ( 'json' === $a['format'] ) {
			return '<script type="application/json" class="atcf-locations-data">' . wp_json_encode( $locations ) . '</script>';
		}

		if ( empty( $locations ) ) {
			return '<p class="atcf-no-results">' . esc_html__( 'No locations found.', 'as-the-crow-flies' ) . '</p>';
		}

		if ( 'table' === $a['format'] ) {
			return self::render_locations_table( $locations, $a['unit'], self::sanitize_classes( $a['class'] ), $a['directions_provider'] );
		}

		return self::render_locations_list( $locations, $a['unit'], $a['format'], $a['item_template'], self::sanitize_classes( $a['class'] ), $a['directions_provider'] );
	}

	// --------------------------------------------------------------------------
	// [atcf_terms]
	// --------------------------------------------------------------------------

	/**
	 * Render terms with locations near a point.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_terms( array $atts ): string {
		$a = shortcode_atts( array(
			'postal_code'   => '',
			'lat'           => '',
			'lng'           => '',
			'country'       => 'US',
			'radius'        => 10,
			'unit'          => get_option( 'atcf_distance_units', 'miles' ),
			'taxonomy'      => '',
			'post_type'     => '',
			'output'        => 'terms',
			'format'              => 'ul',
			'item_template'       => '',
			'limit'               => 20,
			'orderby'             => 'distance',
			'form'                => '',
			'input'               => 'zip,geolocation',
			'class'               => '',
			'directions_provider' => '',
		), $atts, 'atcf_terms' );

		$use_coords = '' !== $a['lat'] && '' !== $a['lng']
			&& ATCF_Distance::is_valid_coordinate( (float) $a['lat'], (float) $a['lng'] );

		if ( $a['form'] ) {
			// [atcf_terms] has no term/group attribute to expand (it scopes purely by
			// taxonomy, already carried via data-taxonomy below), so no term_ids filter applies here.
			return self::render_input_form( $a, 'terms', $a['input'], $a['postal_code'] );
		}

		if ( ! $use_coords && '' === $a['postal_code'] ) {
			$ip_loc = self::visitor_location();
			if ( $ip_loc ) {
				$use_coords   = true;
				$a['lat']     = (string) $ip_loc['lat'];
				$a['lng']     = (string) $ip_loc['lng'];
				$a['country'] = $ip_loc['country'] ?? $a['country'];
			} else {
				return '';
			}
		}

		$args = array(
			'taxonomy' => sanitize_key( $a['taxonomy'] ),
			'country'  => strtoupper( $a['country'] ),
			'limit'    => max( 1, (int) $a['limit'] ),
			'orderby'  => sanitize_key( $a['orderby'] ),
		);

		if ( $use_coords ) {
			$args['lat'] = (float) $a['lat'];
			$args['lng'] = (float) $a['lng'];
		}

		$locations = ATCF_Distance::get_locations_within_radius(
			$a['postal_code'],
			(float) $a['radius'],
			$a['unit'],
			$args
		);

		if ( empty( $locations ) ) {
			return '<p class="atcf-no-results">' . esc_html__( 'No results found.', 'as-the-crow-flies' ) . '</p>';
		}

		// Deduplicate term IDs, preserving closest-location order.
		$seen     = array();
		$term_ids = array();
		foreach ( $locations as $loc ) {
			$tid = (int) $loc['term_id'];
			if ( ! in_array( $tid, $seen, true ) ) {
				$seen[]     = $tid;
				$term_ids[] = $tid;
			}
		}

		if ( 'posts' === $a['output'] ) {
			return self::render_posts_for_terms( $term_ids, $a );
		}

		wp_enqueue_style( 'atcf-frontend' );

		return self::render_term_list( $term_ids, $locations, $a['unit'], $a['format'], $a['item_template'], self::sanitize_classes( $a['class'] ), $a['directions_provider'] );
	}

	// --------------------------------------------------------------------------
	// [atcf_nearby]
	// --------------------------------------------------------------------------

	/**
	 * Render terms with locations near a reference term's locations.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function shortcode_nearby( array $atts ): string {
		$a = shortcode_atts( array(
			'term'             => '',
			'taxonomy'         => '',
			'group'            => '',
			'postal_code'      => '',
			'country'          => 'US',
			'nearby_taxonomy'  => '',
			'radius'           => 10,
			'unit'             => get_option( 'atcf_distance_units', 'miles' ),
			'limit'            => 20,
			'orderby'          => 'distance',
			'output'              => 'terms',
			'format'              => 'ul',
			'item_template'       => '',
			'class'               => '',
			'directions_provider' => '',
		), $atts, 'atcf_nearby' );

		// Expand HT group members into the term list.
		$a['term'] = ATCF_HT_Integration::merge_group_terms_into_string( $a['group'], $a['term'], sanitize_key( $a['taxonomy'] ) );

		// Build reference point(s): either from named term(s)' locations or the visitor's IP location.
		$ref_points       = array();
		$skip_term_ids    = array();
		$effective_nearby = sanitize_key( $a['nearby_taxonomy'] );

		if ( '' !== $a['term'] ) {
			$tax = sanitize_key( $a['taxonomy'] );
			foreach ( array_map( 'trim', explode( ',', $a['term'] ) ) as $t ) {
				if ( '' === $t ) {
					continue;
				}
				$ref_term = is_numeric( $t )
					? get_term( (int) $t )
					: get_term_by( 'slug', $t, $tax );

				if ( ! $ref_term || is_wp_error( $ref_term ) ) {
					continue;
				}

				$ref_locations = ATCF_Locations::get_for_term( $ref_term->term_id, $ref_term->taxonomy );
				if ( empty( $ref_locations ) ) {
					continue;
				}

				$skip_term_ids[] = $ref_term->term_id;

				// Default nearby_taxonomy to the first resolved reference term's taxonomy.
				if ( '' === $effective_nearby ) {
					$effective_nearby = $ref_term->taxonomy;
				}

				foreach ( $ref_locations as $ref_loc ) {
					if ( null !== $ref_loc->lat ) {
						$ref_points[] = array( 'lat' => (float) $ref_loc->lat, 'lng' => (float) $ref_loc->lng );
					} else {
						$centroid = ATCF_Distance::get_centroid( $ref_loc->postal_code, $ref_loc->country );
						if ( $centroid ) {
							$ref_points[] = array( 'lat' => $centroid['lat'], 'lng' => $centroid['lng'] );
						}
					}
				}
			}

			if ( empty( $skip_term_ids ) ) {
				return '';
			}
		} else {
			// postal_code / IP mode requires an explicit nearby_taxonomy — without one there is nothing to scope the search.
			if ( '' === $effective_nearby ) {
				return '';
			}
			$postal_code = ATCF_Distance::normalize_postal_code( sanitize_text_field( $a['postal_code'] ), strtoupper( sanitize_text_field( $a['country'] ) ) );
			if ( '' !== $postal_code ) {
				$centroid = ATCF_Distance::get_centroid( $postal_code, strtoupper( sanitize_text_field( $a['country'] ) ) );
				if ( $centroid ) {
					$ref_points[] = array( 'lat' => $centroid['lat'], 'lng' => $centroid['lng'] );
				}
			} else {
				$ip_loc = self::visitor_location();
				if ( $ip_loc ) {
					$ref_points[] = array( 'lat' => $ip_loc['lat'], 'lng' => $ip_loc['lng'] );
				}
			}
		}

		if ( empty( $ref_points ) ) {
			return '';
		}

		// Collect nearby term IDs from all reference points.
		$seen     = array();
		$term_ids = array();
		$loc_map  = array();

		foreach ( $ref_points as $point ) {
			$nearby = ATCF_Distance::get_locations_within_radius( '', (float) $a['radius'], $a['unit'], array(
				'lat'      => $point['lat'],
				'lng'      => $point['lng'],
				'taxonomy' => $effective_nearby,
				'limit'    => (int) $a['limit'],
				'orderby'  => 'distance',
			) );

			foreach ( $nearby as $loc ) {
				$tid = (int) $loc['term_id'];
				if ( in_array( $tid, $skip_term_ids, true ) ) {
					continue;
				}
				if ( ! in_array( $tid, $seen, true ) ) {
					$seen[]        = $tid;
					$term_ids[]    = $tid;
					$loc_map[$tid] = $loc;
				}
			}
		}

		if ( empty( $term_ids ) ) {
			return '<p class="atcf-no-results">' . esc_html__( 'No nearby results found.', 'as-the-crow-flies' ) . '</p>';
		}

		if ( 'posts' === $a['output'] ) {
			return self::render_posts_for_terms( $term_ids, $a );
		}

		wp_enqueue_style( 'atcf-frontend' );

		return self::render_term_list( $term_ids, array_values( $loc_map ), $a['unit'], $a['format'], $a['item_template'], self::sanitize_classes( $a['class'] ), $a['directions_provider'] );
	}

	// --------------------------------------------------------------------------
	// Render helpers
	// --------------------------------------------------------------------------

	/**
	 * Format a city/state/zip line with no comma before the postal code.
	 * "Portland, OR 97201" — state and zip share the same comma-group.
	 *
	 * @param array $loc Location row array.
	 * @return string Formatted city line.
	 */
	private static function format_city_line( array $loc ): string {
		$city_state = array_filter( array( $loc['locality'] ?? '', $loc['region'] ?? '' ) );
		$line       = implode( ', ', $city_state );
		if ( ! empty( $loc['postal_code'] ) ) {
			$line .= ( $line ? ' ' : '' ) . $loc['postal_code'];
		}
		return $line;
	}

	private static function sanitize_classes( string $class ): string {
		$parts = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $class ) ) ) );
		return implode( ' ', $parts );
	}

	private static function render_locations_list( array $locations, string $unit, string $format = 'ul', string $item_template = '', string $extra_class = '', string $provider = '' ): string {
		$tag      = 'ol' === $format ? 'ol' : ( 'div' === $format ? 'div' : 'ul' );
		$item_tag = 'div' === $format ? 'div' : 'li';
		$css      = 'atcf-locations-list' . ( $extra_class ? ' ' . $extra_class : '' );
		$effective = ATCF_Distance::resolve_provider( $provider );

		$html = '<' . $tag . ' class="' . esc_attr( $css ) . '">';
		foreach ( $locations as $loc ) {
			$html .= '<' . $item_tag . ' class="atcf-location">';

			if ( $item_template ) {
				$html .= self::apply_location_template( $item_template, $loc, $unit, $effective );
			} else {
				$distance = ATCF_Distance::the_distance( (object) $loc, $unit );
				$dir_url  = ATCF_Distance::get_directions_url( (object) $loc, $effective );

				if ( $loc['label'] ) {
					$html .= '<strong class="atcf-location-label">' . esc_html( $loc['label'] ) . '</strong>';
				}
				$html .= '<address class="atcf-location-address">';
				if ( $loc['address_line'] ) {
					$html .= esc_html( $loc['address_line'] );
					if ( $loc['address_line2'] ) {
						$html .= ', ' . esc_html( $loc['address_line2'] );
					}
					$html .= '<br/>';
				}
				$city_line = self::format_city_line( $loc );
				if ( $city_line ) {
					$html .= esc_html( $city_line );
				}
				$html .= '</address>';
				if ( $distance ) {
					$html .= '<span class="atcf-distance">' . esc_html( $distance ) . '</span>';
				}
				if ( $dir_url ) {
					$html .= ' <a href="' . esc_url( $dir_url ) . '" class="atcf-directions" target="_blank" rel="noopener">'
						. esc_html__( 'Directions', 'as-the-crow-flies' ) . '</a>';
				}
			}

			$html .= '</' . $item_tag . '>';
		}
		$html .= '</' . $tag . '>';
		return $html;
	}

	private static function render_locations_table( array $locations, string $unit, string $extra_class = '', string $provider = '' ): string {
		$css       = 'atcf-locations-table' . ( $extra_class ? ' ' . $extra_class : '' );
		$effective = ATCF_Distance::resolve_provider( $provider );
		$html  = '<table class="' . esc_attr( $css ) . '">';
		$html .= '<thead><tr>';
		$html .= '<th>' . esc_html__( 'Label', 'as-the-crow-flies' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Address', 'as-the-crow-flies' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Distance', 'as-the-crow-flies' ) . '</th>';
		$html .= '<th></th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $locations as $loc ) {
			$distance  = ATCF_Distance::the_distance( (object) $loc, $unit );
			$dir_url   = ATCF_Distance::get_directions_url( (object) $loc, $effective );
			$city_line = self::format_city_line( $loc );
			$address   = array_filter( array(
				$loc['address_line'],
				$loc['address_line2'],
				$city_line,
			) );

			$html .= '<tr class="atcf-location">';
			$html .= '<td>' . esc_html( $loc['label'] ) . '</td>';
			$html .= '<td>' . esc_html( implode( ', ', $address ) ) . '</td>';
			$html .= '<td>' . esc_html( $distance ) . '</td>';
			$html .= '<td>';
			if ( $dir_url ) {
				$html .= '<a href="' . esc_url( $dir_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Directions', 'as-the-crow-flies' ) . '</a>';
			}
			$html .= '</td></tr>';
		}

		$html .= '</tbody></table>';
		return $html;
	}

	private static function render_term_list( array $term_ids, array $locations, string $unit, string $format = 'ul', string $item_template = '', string $extra_class = '', string $provider = '' ): string {
		// Build per-term lookups: nearest distance and nearest full location.
		$dist_map = array();
		$loc_map  = array();
		foreach ( $locations as $loc ) {
			$tid = (int) $loc['term_id'];
			$km  = (float) $loc['distance_km'];
			if ( ! isset( $dist_map[ $tid ] ) || $km < $dist_map[ $tid ] ) {
				$dist_map[ $tid ] = $km;
				$loc_map[ $tid ]  = $loc;
			}
		}

		$tag      = 'ol' === $format ? 'ol' : ( 'div' === $format ? 'div' : 'ul' );
		$item_tag = 'div' === $format ? 'div' : 'li';
		$css      = 'atcf-terms-list' . ( $extra_class ? ' ' . $extra_class : '' );
		$effective = ATCF_Distance::resolve_provider( $provider );

		$html = '<' . $tag . ' class="' . esc_attr( $css ) . '">';
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$km       = $dist_map[ $tid ] ?? null;
			$dist_str = '';
			if ( null !== $km ) {
				$fake_loc = (object) array(
					'distance_km'    => $km,
					'distance_miles' => $km / ATCF_Distance::KM_PER_MILE,
				);
				$dist_str = ATCF_Distance::the_distance( $fake_loc, $unit );
			}

			$html .= '<' . $item_tag . ' class="atcf-term">';
			if ( $item_template ) {
				// When an item_template is set and nearest location data is available,
				// merge term fields into the location array so both {name}/{url} and
				// location placeholders ({directions_url}, {address_line}, etc.) work.
				if ( isset( $loc_map[ $tid ] ) ) {
					$merged = array_merge( $loc_map[ $tid ], array(
						'term_name' => $term->name,
						'term_url'  => get_term_link( $term ),
					) );
					$html .= self::apply_location_template( $item_template, $merged, $unit, $effective );
				} else {
					$html .= self::apply_term_template( $item_template, $term, $dist_str );
				}
			} else {
				$html .= '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>';
				if ( $dist_str ) {
					$html .= ' <span class="atcf-distance">(' . esc_html( $dist_str ) . ')</span>';
				}
			}
			$html .= '</' . $item_tag . '>';
		}
		$html .= '</' . $tag . '>';
		return $html;
	}

	/**
	 * Substitute {placeholder} tokens in a location item template.
	 *
	 * Available placeholders: {label}, {address_line}, {address_line2}, {address},
	 * {city}, {region}, {postal_code}, {country}, {distance}, {directions_url},
	 * {directions_url_google}, {directions_url_apple}, {directions_url_waze},
	 * {directions_url_osm}, {directions}.
	 *
	 * @param string $tpl  Template string.
	 * @param array  $loc  Location row (array or cast from object).
	 * @param string $unit 'miles' or 'km'.
	 * @return string Rendered HTML.
	 */
	public static function apply_location_template( string $tpl, array $loc, string $unit, string $provider = '' ): string {
		// Auto-close: if a {directions_link_start*} appears without {directions_link_end},
		// inject {directions_link_end} after the first placeholder that follows each start.
		if ( strpos( $tpl, '{directions_link_start' ) !== false && strpos( $tpl, '{directions_link_end}' ) === false ) {
			$tpl = preg_replace( '/(\{directions_link_start[^}]*\})([^{]*\{[^}]+\})/', '$1$2{directions_link_end}', $tpl );
		}

		$loc_obj    = (object) $loc;
		$distance   = ATCF_Distance::the_distance( $loc_obj, $unit );
		$effective  = ATCF_Distance::resolve_provider( $provider );
		$dir_google = ATCF_Distance::get_directions_url( $loc_obj, 'google' );
		$dir_apple  = ATCF_Distance::get_directions_url( $loc_obj, 'apple' );
		$dir_waze   = ATCF_Distance::get_directions_url( $loc_obj, 'waze' );
		$dir_osm    = ATCF_Distance::get_directions_url( $loc_obj, 'osm' );
		$dir_url    = ATCF_Distance::get_directions_url( $loc_obj, $effective );
		$addr_parts = array_filter( array( $loc['address_line'] ?? '', $loc['address_line2'] ?? '' ) );
		// The geo: scheme is not in WordPress's default allowed protocols, so it
		// must be whitelisted here or esc_url() strips geo provider links.
		$geo_protocols = array_merge( wp_allowed_protocols(), array( 'geo' ) );
		$directions    = $dir_url
			? '<a href="' . esc_url( $dir_url, $geo_protocols ) . '" class="atcf-directions" target="_blank" rel="noopener">' . esc_html__( 'Directions', 'as-the-crow-flies' ) . '</a>'
			: '';

		$link_open = ' class="atcf-directions" target="_blank" rel="noopener">';

		return strtr( $tpl, array(
			'{term_name}'                   => esc_html( $loc['term_name'] ?? '' ),
			'{term_url}'                    => isset( $loc['term_url'] ) ? esc_url( $loc['term_url'] ) : '',
			'{label}'                       => esc_html( $loc['label'] ?? '' ),
			'{address_line}'                => esc_html( $loc['address_line'] ?? '' ),
			'{address_line2}'               => esc_html( $loc['address_line2'] ?? '' ),
			'{address}'                     => esc_html( implode( ', ', $addr_parts ) ),
			'{city}'                        => esc_html( $loc['locality'] ?? '' ),
			'{region}'                      => esc_html( $loc['region'] ?? '' ),
			'{state}'                       => esc_html( $loc['region'] ?? '' ),
			'{postal_code}'                 => esc_html( $loc['postal_code'] ?? '' ),
			'{zip}'                         => esc_html( $loc['postal_code'] ?? '' ),
			'{country}'                     => esc_html( $loc['country'] ?? '' ),
			'{distance}'                    => esc_html( $distance ),
			'{directions_url}'              => esc_url( $dir_url, $geo_protocols ),
			'{directions_url_google}'       => esc_url( $dir_google ),
			'{directions_url_apple}'        => esc_url( $dir_apple ),
			'{directions_url_waze}'         => esc_url( $dir_waze ),
			'{directions_url_osm}'          => esc_url( $dir_osm ),
			'{directions}'                  => $directions,
			'{directions_link_start_google}'=> $dir_google ? '<a href="' . esc_url( $dir_google ) . '"' . $link_open : '',
			'{directions_link_start_apple}' => $dir_apple  ? '<a href="' . esc_url( $dir_apple  ) . '"' . $link_open : '',
			'{directions_link_start_waze}'  => $dir_waze   ? '<a href="' . esc_url( $dir_waze   ) . '"' . $link_open : '',
			'{directions_link_start_osm}'   => $dir_osm    ? '<a href="' . esc_url( $dir_osm    ) . '"' . $link_open : '',
			'{directions_link_start}'       => $dir_url    ? '<a href="' . esc_url( $dir_url, $geo_protocols ) . '"' . $link_open : '',
			'{directions_link_end}'         => '</a>',
		) );
	}

	/**
	 * Substitute {placeholder} tokens in a term item template.
	 *
	 * Available placeholders: {name}, {url}, {distance}.
	 *
	 * @param string   $tpl      Template string.
	 * @param \WP_Term $term     Term object.
	 * @param string   $dist_str Formatted distance string.
	 * @return string Rendered HTML.
	 */
	private static function apply_term_template( string $tpl, \WP_Term $term, string $dist_str ): string {
		return strtr( $tpl, array(
			'{name}'     => esc_html( $term->name ),
			'{url}'      => esc_url( get_term_link( $term ) ),
			'{distance}' => esc_html( $dist_str ),
		) );
	}

	private static function render_posts_for_terms( array $term_ids, array $a ): string {
		if ( empty( $term_ids ) ) {
			return '';
		}

		$taxonomy = sanitize_key( $a['taxonomy'] ?? '' );
		if ( '' === $taxonomy && isset( $a['nearby_taxonomy'] ) ) {
			$taxonomy = sanitize_key( $a['nearby_taxonomy'] );
		}

		$query_args = array(
			'post_type'        => '' !== ( $a['post_type'] ?? '' ) ? sanitize_key( $a['post_type'] ) : 'post',
			'posts_per_page'   => max( 1, (int) ( $a['limit'] ?? 20 ) ),
			'post_status'      => 'publish',
		);

		if ( $taxonomy ) {
			$query_args['tax_query'] = array( array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'include_children' => false,
			) );
		}

		$posts = get_posts( $query_args );
		if ( empty( $posts ) ) {
			return '<p class="atcf-no-results">' . esc_html__( 'No posts found.', 'as-the-crow-flies' ) . '</p>';
		}

		$extra_class = self::sanitize_classes( $a['class'] ?? '' );
		$html = '<ul class="' . esc_attr( 'atcf-posts-list' . ( $extra_class ? ' ' . $extra_class : '' ) ) . '">';
		foreach ( $posts as $post ) {
			$html .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * Render an interactive search input form.
	 *
	 * @param array  $a        Shortcode attributes.
	 * @param string $type     Shortcode type ('locations' or 'terms').
	 * @param string $input    Input mode: 'zip', 'geolocation', or 'zip,geolocation'.
	 * @param string $prefill  Postal code to pre-fill the input with.
	 * @param array  $term_ids Term IDs to restrict the AJAX search to (already resolved from
	 *                         the 'term'/'group' attributes, if any — empty means unrestricted).
	 * @return string HTML.
	 */
	private static function render_input_form( array $a, string $type, string $input = 'zip,geolocation', string $prefill = '', array $term_ids = array() ): string {
		wp_enqueue_style( 'atcf-frontend' );
		wp_enqueue_script( 'atcf-frontend' );

		$unit        = in_array( $a['unit'], array( 'miles', 'km' ), true ) ? $a['unit'] : (string) get_option( 'atcf_distance_units', 'miles' );
		$radius      = max( 1, (int) $a['radius'] );
		$taxonomy    = sanitize_key( $a['taxonomy'] ?? '' );
		$country     = strtoupper( $a['country'] ?? 'US' );
		$input_modes       = array_map( 'trim', explode( ',', $input ) );
		$show_zip          = in_array( 'zip', $input_modes, true );
		$show_geo          = in_array( 'geolocation', $input_modes, true );
		$extra_class       = self::sanitize_classes( $a['class'] ?? '' );
		$effective_provider = ATCF_Distance::resolve_provider( $a['directions_provider'] ?? '' );

		// Coordinate mode: geolocation-only input passes coords directly to REST,
		// bypassing the nearest-postal-code step.
		$geo_mode = ( ! $show_zip && $show_geo ) ? 'coords' : 'zip';

		ob_start();
		?>
		<div class="atcf-finder-form<?php echo $extra_class ? ' ' . esc_attr( $extra_class ) : ''; ?>"
		     data-directions-provider="<?php echo esc_attr( $effective_provider ); ?>"
		     data-type="<?php echo esc_attr( $type ); ?>"
		     data-unit="<?php echo esc_attr( $unit ); ?>"
		     data-radius="<?php echo esc_attr( $radius ); ?>"
		     data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
		     data-term-ids="<?php echo esc_attr( wp_json_encode( array_values( array_unique( array_map( 'absint', $term_ids ) ) ) ) ); ?>"
		     data-country="<?php echo esc_attr( $country ); ?>"
		     data-limit="<?php echo esc_attr( max( 1, (int) ( $a['limit'] ?? 20 ) ) ); ?>"
		     data-format="<?php echo esc_attr( in_array( $a['format'] ?? 'ul', array( 'ul', 'ol', 'div' ), true ) ? ( $a['format'] ?? 'ul' ) : 'ul' ); ?>"
		     data-item-template="<?php echo esc_attr( $a['item_template'] ?? '' ); ?>"
		     data-geo-mode="<?php echo esc_attr( $geo_mode ); ?>">

			<div class="atcf-input-row">
				<?php if ( $show_zip ) : ?>
					<div class="atcf-postal-wrap">
						<input type="text"
						       class="atcf-postal-input"
						       placeholder="<?php esc_attr_e( 'Enter postal code', 'as-the-crow-flies' ); ?>"
						       aria-label="<?php esc_attr_e( 'Postal code', 'as-the-crow-flies' ); ?>"
						       autocomplete="off"
						       <?php if ( '' !== $prefill ) : ?>value="<?php echo esc_attr( $prefill ); ?>"<?php else : ?>data-atcf-prefill="1"<?php endif; ?> />
						<?php if ( $show_geo ) : ?>
							<button type="button"
							        class="atcf-geo-btn"
							        title="<?php esc_attr_e( 'Share current location', 'as-the-crow-flies' ); ?>"
							        aria-label="<?php esc_attr_e( 'Share current location', 'as-the-crow-flies' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<polygon points="3 11 22 2 13 21 11 13 3 11"/>
								</svg>
							</button>
						<?php endif; ?>
					</div>
					<button type="button" class="atcf-search button button-primary">
						<?php esc_html_e( 'Search', 'as-the-crow-flies' ); ?>
					</button>
				<?php elseif ( $show_geo ) : ?>
					<button type="button" class="atcf-use-location button" aria-label="<?php esc_attr_e( 'Find nearby', 'as-the-crow-flies' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" style="vertical-align:middle;margin-right:4px">
							<circle cx="7.5" cy="7.5" r="2.75"/>
							<line x1="7.5" y1="1"    x2="7.5" y2="4.25"/>
							<line x1="7.5" y1="10.75" x2="7.5" y2="14"/>
							<line x1="1"   y1="7.5"  x2="4.25" y2="7.5"/>
							<line x1="10.75" y1="7.5" x2="14"  y2="7.5"/>
						</svg>
						<?php esc_html_e( 'Find nearby', 'as-the-crow-flies' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="atcf-results" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}


}
