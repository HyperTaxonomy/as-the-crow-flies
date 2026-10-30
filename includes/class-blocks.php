<?php
/**
 * Gutenberg block registration.
 *
 * Registers the custom block category and all three dynamic blocks.
 * Block JS/CSS is loaded from the blocks/ directory.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Blocks {

	public static function register(): void {
		add_filter( 'block_categories_all',    array( __CLASS__, 'add_block_category' ), 10, 1 );
		add_action( 'init',                    array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_data' ) );
	}

	/**
	 * Inject enabled-country list into the editor so the Location Finder block
	 * can render a dropdown instead of a free-text ISO code field.
	 */
	public static function enqueue_editor_data(): void {
		wp_enqueue_style(
			'atcf-block-editor',
			ATCF_PLUGIN_URL . 'assets/editor.css',
			array(),
			ATCF_VERSION
		);

		$htax_active = function_exists( 'htax_get_all_term_groups' );
		wp_add_inline_script(
			'wp-blocks',
			'var atcfEditorData = ' . wp_json_encode( array(
				'countries'  => self::get_enabled_countries(),
				'htaxActive' => $htax_active,
				'htaxGroups' => $htax_active ? self::get_htax_groups() : array(),
			) ) . ';',
			'after'
		);
	}

	/**
	 * Return all HyperTaxonomy term groups as { id, name } objects for the block editor.
	 *
	 * @return array[]
	 */
	private static function get_htax_groups(): array {
		$result = array();
		foreach ( htax_get_all_term_groups() as $id => $term ) {
			$result[] = array( 'id' => (int) $id, 'name' => $term->name, 'parent' => (int) $term->parent );
		}
		return $result;
	}

	/**
	 * Return an array of enabled countries as { code, label } objects.
	 *
	 * @return array[]
	 */
	private static function get_enabled_countries(): array {
		static $names = array(
			'AF' => 'Afghanistan',    'AL' => 'Albania',        'DZ' => 'Algeria',
			'AR' => 'Argentina',      'AT' => 'Austria',        'AU' => 'Australia',
			'BE' => 'Belgium',        'BR' => 'Brazil',         'BG' => 'Bulgaria',
			'CA' => 'Canada',         'CL' => 'Chile',          'CN' => 'China',
			'CO' => 'Colombia',       'HR' => 'Croatia',        'CZ' => 'Czechia',
			'DK' => 'Denmark',        'EG' => 'Egypt',          'FI' => 'Finland',
			'FR' => 'France',         'DE' => 'Germany',        'GR' => 'Greece',
			'HK' => 'Hong Kong',      'HU' => 'Hungary',        'IN' => 'India',
			'ID' => 'Indonesia',      'IE' => 'Ireland',        'IL' => 'Israel',
			'IT' => 'Italy',          'JP' => 'Japan',          'KR' => 'South Korea',
			'MY' => 'Malaysia',       'MX' => 'Mexico',         'NL' => 'Netherlands',
			'NZ' => 'New Zealand',    'NO' => 'Norway',         'PK' => 'Pakistan',
			'PH' => 'Philippines',    'PL' => 'Poland',         'PT' => 'Portugal',
			'RO' => 'Romania',        'RU' => 'Russia',         'SA' => 'Saudi Arabia',
			'RS' => 'Serbia',         'SG' => 'Singapore',      'SK' => 'Slovakia',
			'ZA' => 'South Africa',   'ES' => 'Spain',          'SE' => 'Sweden',
			'CH' => 'Switzerland',    'TW' => 'Taiwan',         'TH' => 'Thailand',
			'TR' => 'Turkey',         'UA' => 'Ukraine',        'AE' => 'UAE',
			'GB' => 'United Kingdom', 'US' => 'United States',
		);

		$list = array();

		if ( get_option( 'atcf_us_centroids_enabled', 1 ) ) {
			$list[] = array( 'code' => 'US', 'label' => 'United States (US)' );
		}

		foreach ( (array) get_option( 'atcf_country_centroids', array() ) as $code => $data ) {
			if ( ! empty( $data['enabled'] ) ) {
				$code   = strtoupper( $code );
				$name   = $names[ $code ] ?? $code;
				$list[] = array( 'code' => $code, 'label' => $name . ' (' . $code . ')' );
			}
		}

		return $list;
	}

	/**
	 * Add "As the Crow Flies" category to the block inserter.
	 *
	 * @param array[] $categories Existing block categories.
	 * @return array[]
	 */
	public static function add_block_category( array $categories ): array {
		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'as-the-crow-flies',
					'title' => __( 'As the Crow Flies', 'as-the-crow-flies' ),
				),
			)
		);
	}

	/**
	 * Register all three dynamic blocks.
	 */
	public static function register_blocks(): void {
		self::register_block(
			'location-finder',
			array( __CLASS__, 'render_location_finder' )
		);

		self::register_block(
			'term-locations',
			array( __CLASS__, 'render_term_locations' )
		);

		self::register_block(
			'nearest-location',
			array( __CLASS__, 'render_nearest_location' )
		);

		// Point each block's editor-script translations at the plugin's bundled
		// languages/ directory. Because block.json declares a textdomain, core
		// already calls wp_set_script_translations() without a path — which only
		// resolves wp-content/languages/. Re-registering with the explicit path
		// makes the bundled <domain>-<locale>-<handle>.json files load.
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( $registry->get_all_registered() as $block_type ) {
			if ( strpos( (string) $block_type->name, 'atcf/' ) !== 0 ) {
				continue;
			}
			foreach ( (array) $block_type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'as-the-crow-flies', ATCF_PLUGIN_DIR . 'languages' );
			}
		}
	}

	/**
	 * Register a single block from its directory.
	 *
	 * @param string   $name     Block slug (without 'atcf/' prefix).
	 * @param callable $callback PHP render callback.
	 */
	private static function register_block( string $name, callable $callback ): void {
		$dir = ATCF_PLUGIN_DIR . 'blocks/' . $name;
		if ( ! file_exists( $dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$dir,
			array( 'render_callback' => $callback )
		);
	}

	// --------------------------------------------------------------------------
	// Render callbacks (PHP-rendered / dynamic blocks)
	// --------------------------------------------------------------------------

	/**
	 * Render atcf/location-finder — interactive postal code finder.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML.
	 */
	public static function render_location_finder( array $attributes ): string {
		$taxonomy      = sanitize_key( $attributes['taxonomy'] ?? '' );
		$term_ids      = ATCF_HT_Integration::merge_group_term_ids(
			array_map( 'absint', $attributes['termIds'] ?? array() ),
			array_map( 'absint', $attributes['groupIds'] ?? array() ),
			$taxonomy
		);
		$country       = strtoupper( sanitize_text_field( $attributes['country'] ?? 'US' ) );
		$unit          = in_array( $attributes['unit'] ?? get_option( 'atcf_distance_units', 'miles' ), array( 'miles', 'km' ), true ) ? (string) $attributes['unit'] : (string) get_option( 'atcf_distance_units', 'miles' );
		$distances     = sanitize_text_field( $attributes['distances'] ?? '5,10,25,50' );
		$limit         = max( 1, min( 100, (int) ( $attributes['limit'] ?? 20 ) ) );
		$orderby       = in_array( $attributes['orderby'] ?? 'distance', array( 'distance', 'label', 'term_name' ), true ) ? $attributes['orderby'] : 'distance';
		$show_geo      = ! empty( $attributes['showGeolocation'] );
		$format              = in_array( $attributes['format'] ?? 'ul', array( 'ul', 'ol', 'div', 'table' ), true ) ? $attributes['format'] : 'ul';
		$item_template       = sanitize_text_field( $attributes['itemTemplate'] ?? '' );
		$extra_class         = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $attributes['className'] ?? '' ) ) ) ) );
		$effective_provider  = ATCF_Distance::resolve_provider( sanitize_key( $attributes['directionsProvider'] ?? '' ) );

		wp_enqueue_style( 'atcf-frontend' );
		wp_enqueue_script( 'atcf-frontend' );

		$radius_options = array_filter( array_map( 'absint', explode( ',', $distances ) ) );
		if ( empty( $radius_options ) ) {
			$radius_options = array( 5, 10, 25, 50 );
		}

		ob_start();
		?>
		<div class="atcf-location-finder wp-block-atcf-location-finder<?php echo $extra_class ? ' ' . esc_attr( $extra_class ) : ''; ?>"
		     data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
		     data-term-ids="<?php echo esc_attr( wp_json_encode( $term_ids ) ); ?>"
		     data-country="<?php echo esc_attr( $country ); ?>"
		     data-unit="<?php echo esc_attr( $unit ); ?>"
		     data-format="<?php echo esc_attr( $format ); ?>"
		     data-item-template="<?php echo esc_attr( $item_template ); ?>"
		     data-limit="<?php echo esc_attr( $limit ); ?>"
		     data-orderby="<?php echo esc_attr( $orderby ); ?>"
		     data-directions-provider="<?php echo esc_attr( $effective_provider ); ?>">

			<div class="atcf-finder-controls">
				<div class="atcf-postal-wrap">
					<input type="text"
					       class="atcf-postal-input"
					       placeholder="<?php esc_attr_e( 'Postal code', 'as-the-crow-flies' ); ?>"
					       aria-label="<?php esc_attr_e( 'Enter postal code', 'as-the-crow-flies' ); ?>" />
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

				<select class="atcf-radius-select" aria-label="<?php esc_attr_e( 'Search radius', 'as-the-crow-flies' ); ?>">
					<?php foreach ( $radius_options as $r ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>">
							<?php
							/* translators: 1: distance number, 2: unit */
							echo esc_html( sprintf( __( '%1$d %2$s', 'as-the-crow-flies' ), $r, $unit ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="atcf-search wp-element-button">
					<?php esc_html_e( 'Search', 'as-the-crow-flies' ); ?>
				</button>
			</div>

			<div class="atcf-results" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render atcf/term-locations — static location list for a specific term.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML.
	 */
	public static function render_term_locations( array $attributes ): string {
		$term_ids         = array_values( array_filter( array_map( 'absint', (array) ( $attributes['termIds'] ?? array() ) ) ) );
		$taxonomy         = sanitize_key( $attributes['taxonomy'] ?? '' );
		$country          = strtoupper( sanitize_text_field( $attributes['country'] ?? '' ) );
		$sort_postal      = sanitize_text_field( $attributes['sortPostalCode'] ?? '' );
		$show_address     = (bool) ( $attributes['showAddressLine'] ?? true );
		$show_locality    = (bool) ( $attributes['showLocality']    ?? true );
		$show_region      = (bool) ( $attributes['showRegion']      ?? true );
		$show_postal_code = (bool) ( $attributes['showPostalCode']  ?? true );
		$show_country     = (bool) ( $attributes['showCountry']     ?? false );
		$unit             = in_array( $attributes['unit'] ?? get_option( 'atcf_distance_units', 'miles' ), array( 'miles', 'km' ), true ) ? (string) $attributes['unit'] : (string) get_option( 'atcf_distance_units', 'miles' );
		$format              = in_array( $attributes['format'] ?? 'ul', array( 'ul', 'ol', 'table', 'div' ), true ) ? $attributes['format'] : 'ul';
		$item_template       = sanitize_text_field( $attributes['itemTemplate'] ?? '' );
		$extra_class         = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $attributes['className'] ?? '' ) ) ) ) );
		$effective_provider  = ATCF_Distance::resolve_provider( sanitize_key( $attributes['directionsProvider'] ?? '' ) );

		if ( ! $taxonomy ) {
			if ( is_admin() ) {
				return '<p class="atcf-block-placeholder">' . esc_html__( 'Select a term in the block settings.', 'as-the-crow-flies' ) . '</p>';
			}
			return '';
		}

		// Leaving terms empty with a taxonomy selected means "all terms' locations"
		// (documented behavior), not "nothing" — resolve to every term in this
		// taxonomy that currently has at least one location.
		if ( empty( $term_ids ) ) {
			$term_ids = atcf_get_term_ids_with_locations( $taxonomy );
			if ( empty( $term_ids ) ) {
				return '';
			}
		}

		$limit = max( 0, (int) ( $attributes['limit'] ?? 0 ) );

		$args = array( 'orderby' => 'label' );
		if ( $country ) {
			$args['country'] = $country;
		}

		$locations = array();
		foreach ( $term_ids as $term_id ) {
			$locations = array_merge( $locations, ATCF_Locations::get_for_term( $term_id, $taxonomy, $args ) );
		}
		if ( count( $term_ids ) > 1 ) {
			usort( $locations, fn( $a, $b ) => strcmp( $a->label ?? '', $b->label ?? '' ) );
		}
		if ( $limit > 0 ) {
			$locations = array_slice( $locations, 0, $limit );
		}

		if ( empty( $locations ) ) {
			return '';
		}

		// If sort_postal provided, sort by distance and attach distance_km for display.
		if ( $sort_postal ) {
			$sort_country = $country ?: 'US';
			$centroid     = ATCF_Distance::get_centroid(
				ATCF_Distance::normalize_postal_code( $sort_postal, $sort_country ),
				$sort_country
			);
			if ( $centroid ) {
				$ref_lat = $centroid['lat'];
				$ref_lng = $centroid['lng'];
				foreach ( $locations as $loc ) {
					$loc_lat = null !== $loc->lat ? (float) $loc->lat : null;
					$loc_lng = null !== $loc->lng ? (float) $loc->lng : null;
					if ( null !== $loc_lat ) {
						$loc->distance_km    = ATCF_Distance::great_circle_distance( $ref_lat, $ref_lng, $loc_lat, $loc_lng );
						$loc->distance_miles = $loc->distance_km / ATCF_Distance::KM_PER_MILE;
					} else {
						$loc->distance_km    = PHP_FLOAT_MAX;
						$loc->distance_miles = PHP_FLOAT_MAX;
					}
				}
				usort( $locations, function ( $a, $b ) {
					return $a->distance_km <=> $b->distance_km;
				} );
			}
		}

		wp_enqueue_style( 'atcf-frontend' );

		$tag      = 'ol' === $format ? 'ol' : ( 'div' === $format ? 'div' : ( 'table' === $format ? 'table' : 'ul' ) );
		$item_tag = 'table' === $format ? 'tr' : ( 'div' === $format ? 'div' : 'li' );
		$css      = ( 'table' === $format ? 'atcf-locations-table' : 'atcf-locations-list' ) . ' wp-block-atcf-term-locations' . ( $extra_class ? ' ' . $extra_class : '' );

		ob_start();

		if ( 'table' === $format ) {
			echo '<table class="' . esc_attr( $css ) . '">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Label', 'as-the-crow-flies' ) . '</th>';
			echo '<th>' . esc_html__( 'Address', 'as-the-crow-flies' ) . '</th>';
			if ( $sort_postal ) {
				echo '<th>' . esc_html__( 'Distance', 'as-the-crow-flies' ) . '</th>';
			}
			echo '<th></th></tr></thead><tbody>';
		} else {
			echo '<' . esc_attr( $tag ) . ' class="' . esc_attr( $css ) . '">';
		}

		foreach ( $locations as $loc ) {
			$dir_url = ATCF_Distance::get_directions_url( $loc, $effective_provider );
			echo '<' . esc_attr( $item_tag ) . ' class="atcf-location">';

			if ( $item_template ) {
				echo ATCF_Shortcodes::apply_location_template( $item_template, (array) $loc, $unit, $effective_provider );
			} elseif ( 'table' === $format ) {
				// Build address string for the table cell.
				$addr_line = '';
				if ( $show_address && $loc->address_line ) {
					$addr_line = $loc->address_line;
					if ( $loc->address_line2 ) {
						$addr_line .= ', ' . $loc->address_line2;
					}
				}
				$city_state = array_filter( array(
					$show_locality ? ( $loc->locality ?? '' ) : '',
					$show_region   ? ( $loc->region   ?? '' ) : '',
				) );
				$city_line  = implode( ', ', $city_state );
				if ( $show_postal_code && ! empty( $loc->postal_code ) ) {
					$city_line .= ( $city_line ? ' ' : '' ) . $loc->postal_code;
				}
				if ( $show_country && ! empty( $loc->country ) ) {
					$city_line .= ( $city_line ? ', ' : '' ) . $loc->country;
				}
				$addr_parts = array_filter( array( $addr_line, $city_line ) );

				echo '<td>' . esc_html( $loc->label ?? '' ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $addr_parts ) ) . '</td>';
				if ( $sort_postal ) {
					echo '<td>';
					if ( isset( $loc->distance_km ) && $loc->distance_km < PHP_FLOAT_MAX ) {
						echo esc_html( ATCF_Distance::the_distance( $loc, $unit ) );
					}
					echo '</td>';
				}
				echo '<td>';
				if ( $dir_url ) {
					echo '<a href="' . esc_url( $dir_url, array_merge( wp_allowed_protocols(), array( 'geo' ) ) ) . '" target="_blank" rel="noopener">'
						. esc_html__( 'Directions', 'as-the-crow-flies' ) . '</a>';
				}
				echo '</td>';
			} else {
				if ( $loc->label ) {
					echo '<strong class="atcf-location-label">' . esc_html( $loc->label ) . '</strong>';
				}

				// Build address parts respecting column visibility toggles.
				$address_parts = array();
				if ( $show_address && $loc->address_line ) {
					$line = $loc->address_line;
					if ( $loc->address_line2 ) {
						$line .= ', ' . $loc->address_line2;
					}
					$address_parts[] = $line;
				}

				$city_state = array_filter( array(
					$show_locality ? ( $loc->locality ?? '' ) : '',
					$show_region   ? ( $loc->region   ?? '' ) : '',
				) );
				$city_line  = implode( ', ', $city_state );
				if ( $show_postal_code && ! empty( $loc->postal_code ) ) {
					$city_line .= ( $city_line ? ' ' : '' ) . $loc->postal_code;
				}
				if ( $show_country && ! empty( $loc->country ) ) {
					$city_line .= ( $city_line ? ', ' : '' ) . $loc->country;
				}
				if ( $city_line ) {
					$address_parts[] = $city_line;
				}

				if ( $address_parts ) {
					echo '<address>' . esc_html( implode( "\n", $address_parts ) ) . '</address>';
				}

				// Distance from sort postal code when available and finite.
				if ( $sort_postal && isset( $loc->distance_km ) && $loc->distance_km < PHP_FLOAT_MAX ) {
					echo '<span class="atcf-distance">' . esc_html( ATCF_Distance::the_distance( $loc, $unit ) ) . '</span>';
				}

				if ( $dir_url ) {
					echo '<a href="' . esc_url( $dir_url, array_merge( wp_allowed_protocols(), array( 'geo' ) ) ) . '" class="atcf-directions" target="_blank" rel="noopener">'
						. esc_html__( 'Directions', 'as-the-crow-flies' ) . '</a>';
				}
			}

			echo '</' . esc_attr( $item_tag ) . '>';
		}

		if ( 'table' === $format ) {
			echo '</tbody></table>';
		} else {
			echo '</' . esc_attr( $tag ) . '>';
		}
		return ob_get_clean();
	}

	/**
	 * Render atcf/nearest-location — compact callout for the nearest location.
	 *
	 * Resolved client-side via JS + REST API; PHP outputs a placeholder shell.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML.
	 */
	public static function render_nearest_location( array $attributes ): string {
		$taxonomy      = sanitize_key( $attributes['taxonomy'] ?? '' );
		$term_ids      = ATCF_HT_Integration::merge_group_term_ids(
			array_map( 'absint', $attributes['termIds'] ?? array() ),
			array_map( 'absint', $attributes['groupIds'] ?? array() ),
			$taxonomy
		);
		$country       = strtoupper( sanitize_text_field( $attributes['country'] ?? 'US' ) );
		$unit          = in_array( $attributes['unit'] ?? get_option( 'atcf_distance_units', 'miles' ), array( 'miles', 'km' ), true ) ? (string) $attributes['unit'] : (string) get_option( 'atcf_distance_units', 'miles' );
		$prompt        = sanitize_text_field( $attributes['promptText'] ?? __( 'Finding your nearest location…', 'as-the-crow-flies' ) );
		$show_dist          = ! empty( $attributes['showDistance'] );
		$item_template      = sanitize_text_field( $attributes['itemTemplate'] ?? '' );
		$extra_class        = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $attributes['className'] ?? '' ) ) ) ) );
		$effective_provider = ATCF_Distance::resolve_provider( sanitize_key( $attributes['directionsProvider'] ?? '' ) );

		wp_enqueue_style( 'atcf-frontend' );
		wp_enqueue_script( 'atcf-frontend' );

		$wrapper_class = 'atcf-nearest-location wp-block-atcf-nearest-location' . ( $extra_class ? ' ' . $extra_class : '' );

		return sprintf(
			'<div class="%s"
			      data-taxonomy="%s"
			      data-term-ids="%s"
			      data-country="%s"
			      data-unit="%s"
			      data-show-distance="%s"
			      data-item-template="%s"
			      data-directions-provider="%s">
				<p class="atcf-nearest-prompt">%s</p>
				<div class="atcf-nearest-result" aria-live="polite"></div>
			</div>',
			esc_attr( $wrapper_class ),
			esc_attr( $taxonomy ),
			esc_attr( wp_json_encode( $term_ids ) ),
			esc_attr( $country ),
			esc_attr( $unit ),
			esc_attr( $show_dist ? '1' : '0' ),
			esc_attr( $item_template ),
			esc_attr( $effective_provider ),
			esc_html( $prompt )
		);
	}
}
