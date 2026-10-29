<?php
/**
 * Plugin settings page — three tabs: Locations, Postal Codes, Advanced.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Settings_Page {

	public static function register(): void {
		add_action( 'admin_menu',  array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init',  array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_atcf_reimport_us',       array( __CLASS__, 'handle_reimport_us' ) );
		add_action( 'admin_post_atcf_download_census',   array( __CLASS__, 'handle_download_census' ) );
		add_action( 'admin_post_atcf_upload_zcta',       array( __CLASS__, 'handle_upload_zcta' ) );
		add_action( 'admin_post_atcf_upload_geonames',   array( __CLASS__, 'handle_upload_geonames' ) );
		add_action( 'admin_post_atcf_delete_country',    array( __CLASS__, 'handle_delete_country' ) );
		add_action( 'admin_post_atcf_toggle_country',    array( __CLASS__, 'handle_toggle_country' ) );
		add_action( 'admin_post_atcf_toggle_us',         array( __CLASS__, 'handle_toggle_us' ) );
		// Note: atcf_geocode_now, export_all_csv, import_global_csv, download_global_template
		// are handled by ATCF_Locations_Global::register().
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'As the Crow Flies', 'as-the-crow-flies' ),
			__( 'As the Crow Flies', 'as-the-crow-flies' ),
			'manage_categories',
			'as-the-crow-flies',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Sanitize the fallback location: "lat, lng" text → { lat, lng }, or empty.
	 *
	 * @since 1.0.0
	 * @param mixed $value Submitted value.
	 * @return array
	 */
	public static function sanitize_fallback_location( $value ): array {
		if ( is_array( $value ) ) {
			return isset( $value['lat'], $value['lng'] ) ? array( 'lat' => (float) $value['lat'], 'lng' => (float) $value['lng'] ) : array();
		}
		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] )
			|| abs( (float) $parts[0] ) > 90 || abs( (float) $parts[1] ) > 180 ) {
			if ( '' !== trim( (string) $value ) ) {
				add_settings_error( 'atcf_fallback_location', 'atcf_fallback_location', __( 'Fallback location must be "latitude, longitude" (e.g. 40.7128, -74.0060).', 'as-the-crow-flies' ) );
				// Keep the saved location rather than clearing it over a typo.
				return (array) get_option( 'atcf_fallback_location', array() );
			}
			return array();
		}
		return array( 'lat' => (float) $parts[0], 'lng' => (float) $parts[1] );
	}

	public static function register_settings(): void {
		// General tab — only these options are processed when the General tab is saved.
		register_setting( 'atcf_settings_general', 'atcf_enabled_taxonomies',         array( 'type' => 'array',   'default' => array() ) );
		register_setting( 'atcf_settings_general', 'atcf_distance_units',             array( 'type' => 'string',  'default' => 'miles',  'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'atcf_settings_general', 'atcf_location_match_radius',      array( 'type' => 'number',  'default' => 82.0,     'sanitize_callback' => static function( $val ) { $val = (float) $val; return $val >= 0.0 ? $val : 0.0; } ) );
		register_setting( 'atcf_settings_general', 'atcf_find_terms_radius',          array( 'type' => 'number',  'default' => 50.0,     'sanitize_callback' => static function( $val ) { $val = (float) $val; return $val >= 0.0 ? $val : 0.0; } ) );
		register_setting( 'atcf_settings_general', 'atcf_location_match_mode',        array( 'type' => 'string',  'default' => 'either', 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'atcf_settings_general', 'atcf_match_require_label_address', array( 'type' => 'boolean', 'default' => false ) );
		register_setting( 'atcf_settings_general', 'atcf_match_require_label_coords',  array( 'type' => 'boolean', 'default' => false ) );

		// Advanced tab — only these options are processed when the Advanced tab is saved.
		// atcf_conflict_address/label/coords are import-form preferences; keep them outside
		// any settings group so options.php never forces them to false.
		register_setting( 'atcf_settings_advanced', 'atcf_directions_provider',    array( 'type' => 'string',  'default' => 'google',    'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'atcf_settings_advanced', 'atcf_ip_geo_enabled',         array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'atcf_settings_advanced', 'atcf_ip_geo_provider',        array( 'type' => 'string',  'default' => 'freeipapi', 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'atcf_settings_advanced', 'atcf_fallback_location',      array( 'type' => 'array',   'default' => array(),     'sanitize_callback' => array( __CLASS__, 'sanitize_fallback_location' ) ) );
		register_setting( 'atcf_settings_advanced', 'atcf_ip_geo_ttl',             array( 'type' => 'integer', 'default' => 86400,       'sanitize_callback' => static function( $val ) { $val = (int) $val; return $val >= 60 ? $val : 86400; } ) );
		register_setting( 'atcf_settings_advanced', 'atcf_expose_locations_api',   array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'atcf_settings_advanced', 'atcf_delete_on_uninstall',    array( 'type' => 'boolean', 'default' => false ) );
	}

	// --------------------------------------------------------------------------
	// Page render
	// --------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
		$message    = sanitize_key( $_GET['atcf_msg'] ?? '' );
		$tabs       = array(
			'general'      => __( 'General', 'as-the-crow-flies' ),
			'postal-codes' => __( 'Postal Codes', 'as-the-crow-flies' ),
			'advanced'     => __( 'Advanced', 'as-the-crow-flies' ),
		);

		?>
		<div class="wrap atcf-settings-page">
			<h1><?php esc_html_e( 'As the Crow Flies', 'as-the-crow-flies' ); ?></h1>

			<?php self::render_message( $message, sanitize_text_field( wp_unslash( $_GET['atcf_info'] ?? '' ) ) ); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, remove_query_arg( array( 'atcf_msg', 'atcf_info' ) ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php
				switch ( $active_tab ) {
					case 'advanced':
						self::render_tab_advanced();
						break;
					case 'postal-codes':
						self::render_tab_postal_codes();
						break;
					default:
						self::render_tab_general();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}


	// --------------------------------------------------------------------------
	// General tab
	// --------------------------------------------------------------------------

	private static function render_tab_general(): void {
		$enabled_taxonomies    = (array) get_option( 'atcf_enabled_taxonomies', array() );
		$distance_units = (string) get_option( 'atcf_distance_units', 'miles' );
		if ( ! in_array( $distance_units, array( 'miles', 'km' ), true ) ) {
			$distance_units = 'miles';
		}
		$location_match_radius  = (float) get_option( 'atcf_location_match_radius', 82.0 );
		$radius_unit_label      = 'km' === $distance_units
			? __( 'meters', 'as-the-crow-flies' )
			: __( 'feet', 'as-the-crow-flies' );
		$find_terms_radius      = (float) get_option( 'atcf_find_terms_radius', 50.0 );
		$find_terms_radius_label = 'km' === $distance_units
			? __( 'km', 'as-the-crow-flies' )
			: __( 'miles', 'as-the-crow-flies' );
		$location_match_mode = (string) get_option( 'atcf_location_match_mode', 'either' );
		if ( ! in_array( $location_match_mode, array( 'address', 'coords', 'either', 'both' ), true ) ) {
			$location_match_mode = 'either';
		}
		$require_label_address       = (bool) get_option( 'atcf_match_require_label_address', false );
		$require_label_coords        = (bool) get_option( 'atcf_match_require_label_coords', false );

		$taxonomies = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );
		unset( $taxonomies['group'] );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'atcf_settings_general' ); ?>

			<h2><?php esc_html_e( 'Enabled Taxonomies', 'as-the-crow-flies' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Taxonomies', 'as-the-crow-flies' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $taxonomies as $slug => $tax_obj ) : ?>
								<label style="display:block; margin-bottom:4px;">
									<input type="checkbox"
									       name="atcf_enabled_taxonomies[]"
									       value="<?php echo esc_attr( $slug ); ?>"
									       <?php checked( in_array( $slug, $enabled_taxonomies, true ) ); ?> />
									<?php echo esc_html( $tax_obj->labels->name ); ?>
									<small class="atcf-taxonomy-slug">(<?php echo esc_html( $slug ); ?>)</small>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Enabling a taxonomy shows the Locations panel on term edit screens, the Locations column in term lists, and the taxonomy in Gutenberg block dropdowns.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<hr/>

			<h2><?php esc_html_e( 'Units', 'as-the-crow-flies' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Default units', 'as-the-crow-flies' ); ?></th>
					<td>
						<fieldset>
							<label style="margin-right:16px;">
								<input type="radio" name="atcf_distance_units" value="miles" <?php checked( $distance_units, 'miles' ); ?> />
								<?php esc_html_e( 'Miles', 'as-the-crow-flies' ); ?>
							</label>
							<label>
								<input type="radio" name="atcf_distance_units" value="km" <?php checked( $distance_units, 'km' ); ?> />
								<?php esc_html_e( 'Kilometers', 'as-the-crow-flies' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<hr/>

			<h2><?php esc_html_e( 'Location Matching', 'as-the-crow-flies' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Match locations by', 'as-the-crow-flies' ); ?></th>
					<td>
						<fieldset id="atcf-location-match-mode">
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="atcf_location_match_mode" value="address"
								       <?php checked( $location_match_mode, 'address' ); ?> />
								<?php esc_html_e( 'Address (incl. postal code)', 'as-the-crow-flies' ); ?>
							</label>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="atcf_location_match_mode" value="coords"
								       <?php checked( $location_match_mode, 'coords' ); ?> />
								<?php esc_html_e( 'Coordinates', 'as-the-crow-flies' ); ?>
							</label>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="atcf_location_match_mode" value="either"
								       <?php checked( $location_match_mode, 'either' ); ?> />
								<?php esc_html_e( 'Address OR Coordinates', 'as-the-crow-flies' ); ?>
							</label>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="atcf_location_match_mode" value="both"
								       <?php checked( $location_match_mode, 'both' ); ?> />
								<?php esc_html_e( 'Address AND Coordinates', 'as-the-crow-flies' ); ?>
							</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'How locations are compared when identifying equivalent terms across peer sites.', 'as-the-crow-flies' ); ?></p>
						<p style="margin-top:8px;">
							<label>
								<input type="hidden" name="atcf_match_require_label_address" value="0" />
							<input type="checkbox" id="atcf_require_label_address"
							       name="atcf_match_require_label_address"
							       value="1" <?php checked( $require_label_address ); ?> />
								<?php esc_html_e( 'Require labels to match when comparing addresses', 'as-the-crow-flies' ); ?>
							</label>
						</p>
						<p style="margin-top:4px;">
							<label>
								<input type="hidden" name="atcf_match_require_label_coords" value="0" />
							<input type="checkbox" id="atcf_require_label_coords"
							       name="atcf_match_require_label_coords"
							       value="1" <?php checked( $require_label_coords ); ?> />
								<?php esc_html_e( 'Require labels to match when comparing coordinates', 'as-the-crow-flies' ); ?>
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_location_match_radius"><?php esc_html_e( 'Location match radius', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<input type="number" id="atcf_location_match_radius" name="atcf_location_match_radius"
						       value="<?php echo esc_attr( (string) $location_match_radius ); ?>"
						       min="0" step="1" class="small-text" />
						<span id="atcf_radius_unit_label"><?php echo esc_html( $radius_unit_label ); ?></span>
						<p class="description"><?php esc_html_e( 'Two locations whose coordinates fall within this distance of each other will be treated as the same location.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_find_terms_radius"><?php esc_html_e( 'Find-terms search radius', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<input type="number" id="atcf_find_terms_radius" name="atcf_find_terms_radius"
						       value="<?php echo esc_attr( (string) $find_terms_radius ); ?>"
						       min="0" step="1" class="small-text" />
						<span id="atcf_find_terms_radius_unit_label"><?php echo esc_html( $find_terms_radius_label ); ?></span>
						<p class="description"><?php esc_html_e( 'Maximum distance used by atcf_find_terms_by_location() when searching for matching terms.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<script>
		(function($) {
			var FT_PER_M = 3.28084;
			var unitLabels = {
				miles: <?php echo wp_json_encode( __( 'feet', 'as-the-crow-flies' ) ); ?>,
				km:    <?php echo wp_json_encode( __( 'meters', 'as-the-crow-flies' ) ); ?>
			};
			var KM_PER_MILE = 1.60934;
			$('input[name="atcf_distance_units"]').on('change', function() {
				var newUnit = $(this).val();
				var $r      = $('#atcf_location_match_radius');
				var val     = parseFloat($r.val()) || 0;
				if (newUnit === 'km') {
					$r.val(Math.round(val / FT_PER_M));
				} else {
					$r.val(Math.round(val * FT_PER_M));
				}
				$('#atcf_radius_unit_label').text(unitLabels[newUnit] || '');

				var $fr  = $('#atcf_find_terms_radius');
				var frVal = parseFloat($fr.val()) || 0;
				if (newUnit === 'km') {
					$fr.val(Math.round(frVal * KM_PER_MILE));
				} else {
					$fr.val(Math.round(frVal / KM_PER_MILE));
				}
				$('#atcf_find_terms_radius_unit_label').text(newUnit === 'km' ? 'km' : 'miles');
			});

			function syncLocationMatchOptions() {
				var mode = $('input[name="atcf_location_match_mode"]:checked').val() || 'either';
				var hasAddr   = mode === 'address' || mode === 'either' || mode === 'both';
				var hasCoords = mode === 'coords'  || mode === 'either' || mode === 'both';
				var $addrCb   = $('#atcf_require_label_address');
				var $coordsCb = $('#atcf_require_label_coords');
				$addrCb.prop('disabled', !hasAddr);
				$coordsCb.prop('disabled', !hasCoords);
				// Sync hidden inputs so the checked state is preserved on save even
				// when the checkbox is disabled (disabled fields are not submitted).
				$addrCb.prev('input[type="hidden"]').val($addrCb.prop('checked') ? '1' : '0');
				$coordsCb.prev('input[type="hidden"]').val($coordsCb.prop('checked') ? '1' : '0');
			}
			$('input[name="atcf_location_match_mode"]').on('change', syncLocationMatchOptions);
			// Also resync on the checkboxes' own change — otherwise toggling one
			// without also touching the mode radio leaves its hidden fallback
			// stuck at whatever value the last mode change synced, silently
			// reverting the save to that stale state.
			$('#atcf_require_label_address, #atcf_require_label_coords').on('change', syncLocationMatchOptions);
			syncLocationMatchOptions();
		})(jQuery);
		</script>
		<?php
	}

	// --------------------------------------------------------------------------
	// Postal Codes tab
	// --------------------------------------------------------------------------

	private static function render_tab_postal_codes(): void {
		$us_count    = ATCF_Activator::get_centroid_count( 'US' );
		$us_imported = get_option( 'atcf_us_centroids_imported', '' );
		$countries   = get_option( 'atcf_country_centroids', array() );

		$us_enabled    = (bool) get_option( 'atcf_us_centroids_enabled', 1 );
		$reimport_url  = wp_nonce_url( admin_url( 'admin-post.php?action=atcf_reimport_us' ), 'atcf_reimport_us' );
		$download_url  = wp_nonce_url( admin_url( 'admin-post.php?action=atcf_download_census' ), 'atcf_download_census' );
		$toggle_us_url = wp_nonce_url( admin_url( 'admin-post.php?action=atcf_toggle_us' ), 'atcf_toggle_us' );
		?>
		<h2><?php esc_html_e( 'US Zip Code Data', 'as-the-crow-flies' ); ?></h2>

		<table class="atcf-stats-table">
			<tr>
				<th><?php esc_html_e( 'Rows', 'as-the-crow-flies' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $us_count ) ); ?></td>
			</tr>
			<?php if ( $us_imported ) : ?>
			<tr>
				<th><?php esc_html_e( 'Last imported', 'as-the-crow-flies' ); ?></th>
				<td><?php echo esc_html( $us_imported ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Status', 'as-the-crow-flies' ); ?></th>
				<td>
					<?php if ( $us_enabled ) : ?>
						<?php esc_html_e( 'Enabled', 'as-the-crow-flies' ); ?>
						— <a href="<?php echo esc_url( $toggle_us_url ); ?>"><?php esc_html_e( 'Disable', 'as-the-crow-flies' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Disabled', 'as-the-crow-flies' ); ?>
						— <a href="<?php echo esc_url( $toggle_us_url ); ?>"><?php esc_html_e( 'Enable', 'as-the-crow-flies' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'US distances are calculated from Zip code centroid coordinates. Use any one option to populate or refresh this data.', 'as-the-crow-flies' ); ?></p>
		<div class="atcf-centroid-options">

			<div class="atcf-centroid-option">
				<strong><?php esc_html_e( 'Download directly from Census Bureau', 'as-the-crow-flies' ); ?></strong>
				<p><?php esc_html_e( 'Fetches the Census ZCTA dataset (~33,000 Zip codes) and imports it immediately, or save the original zip file to your computer.', 'as-the-crow-flies' ); ?></p>
				<div style="display:flex;gap:6px;flex-wrap:wrap;">
					<a href="<?php echo esc_url( $download_url ); ?>" class="button button-primary"
					   onclick="return confirm('<?php esc_attr_e( 'Download from census.gov and replace existing US centroid data?', 'as-the-crow-flies' ); ?>')">
						<?php esc_html_e( 'Download and Install', 'as-the-crow-flies' ); ?>
					</a>
					<a href="https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2020_Gazetteer/2020_Gaz_zcta_national.zip"
					   class="button" target="_blank" rel="noopener">
						<?php esc_html_e( 'Download Only (.zip)', 'as-the-crow-flies' ); ?>
					</a>
				</div>
			</div>

			<div class="atcf-centroid-option">
				<strong><?php esc_html_e( 'Upload a file', 'as-the-crow-flies' ); ?></strong>
				<p><?php esc_html_e( 'Upload a Census ZCTA CSV/TSV you downloaded separately.', 'as-the-crow-flies' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="atcf-inline-form">
					<?php wp_nonce_field( 'atcf_upload_zcta' ); ?>
					<input type="hidden" name="action" value="atcf_upload_zcta" />
					<input type="file" name="zcta_file" accept=".csv,.txt" required />
					<button type="submit" class="button"
					        onclick="return confirm('<?php esc_attr_e( 'Replace US centroid data with the uploaded file?', 'as-the-crow-flies' ); ?>')"><?php esc_html_e( 'Upload', 'as-the-crow-flies' ); ?></button>
				</form>
			</div>

			<div class="atcf-centroid-option">
				<strong><?php esc_html_e( 'Restore bundled data', 'as-the-crow-flies' ); ?></strong>
				<p><?php esc_html_e( 'Restores the ~33,000-row Census ZCTA dataset bundled with this plugin version.', 'as-the-crow-flies' ); ?></p>
				<a href="<?php echo esc_url( $reimport_url ); ?>" class="button"
				   onclick="return confirm('<?php esc_attr_e( 'Replace US centroid data with the bundled dataset?', 'as-the-crow-flies' ); ?>')">
					<?php esc_html_e( 'Restore', 'as-the-crow-flies' ); ?>
				</a>
			</div>

		</div>

		<hr/>

		<h2><?php esc_html_e( 'Other Countries', 'as-the-crow-flies' ); ?></h2>

		<?php if ( ! empty( $countries ) ) : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Country', 'as-the-crow-flies' ); ?></th>
					<th><?php esc_html_e( 'Rows', 'as-the-crow-flies' ); ?></th>
					<th><?php esc_html_e( 'Imported', 'as-the-crow-flies' ); ?></th>
					<th><?php esc_html_e( 'Enabled', 'as-the-crow-flies' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'as-the-crow-flies' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $countries as $code => $data ) :
				$enabled     = ! empty( $data['enabled'] );
				$toggle_url  = wp_nonce_url( add_query_arg( array( 'action' => 'atcf_toggle_country', 'country' => $code ), admin_url( 'admin-post.php' ) ), 'atcf_toggle_country_' . $code );
				$delete_url  = wp_nonce_url( add_query_arg( array( 'action' => 'atcf_delete_country', 'country' => $code ), admin_url( 'admin-post.php' ) ), 'atcf_delete_country_' . $code );
				?>
				<tr>
					<td><?php echo esc_html( $code ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $data['count'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( $data['imported'] ?? '' ); ?></td>
					<td>
						<a href="<?php echo esc_url( $toggle_url ); ?>">
							<?php if ( $enabled ) : ?>
								<span class="dashicons dashicons-yes" title="<?php esc_attr_e( 'Enabled — click to disable', 'as-the-crow-flies' ); ?>"></span>
							<?php else : ?>
								<span class="dashicons dashicons-no-alt" title="<?php esc_attr_e( 'Disabled — click to enable', 'as-the-crow-flies' ); ?>"></span>
							<?php endif; ?>
						</a>
					</td>
					<td>
						<a href="<?php echo esc_url( $delete_url ); ?>"
						   onclick="return confirm('<?php esc_attr_e( 'Delete centroid data for this country?', 'as-the-crow-flies' ); ?>')">
							<?php esc_html_e( 'Delete', 'as-the-crow-flies' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Add country (GeoNames TSV)', 'as-the-crow-flies' ); ?></h3>
		<p class="description">
			<?php
			printf(
				/* translators: URL to GeoNames */
				esc_html__( 'Download a per-country postal code file from %s (free). Enter the corresponding country code and upload the TSV file.', 'as-the-crow-flies' ),
				'<a href="https://download.geonames.org/export/zip/" target="_blank" rel="noopener">geonames.org</a>'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'atcf_upload_geonames' ); ?>
			<input type="hidden" name="action" value="atcf_upload_geonames" />
			<table class="form-table">
				<tr>
					<th><label for="atcf_geonames_country"><?php esc_html_e( 'Country code', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<input type="text" id="atcf_geonames_country" name="country" maxlength="2" class="small-text" placeholder="GB" required />
						<p class="description"><?php esc_html_e( 'ISO 3166-1 alpha-2 code (e.g. GB, DE, AU)', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_geonames_file"><?php esc_html_e( 'TSV file', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<div class="atcf-inline-form">
							<input type="file" id="atcf_geonames_file" name="geonames_file" accept=".txt,.tsv,.zip" required />
							<button type="submit" class="button"><?php esc_html_e( 'Upload', 'as-the-crow-flies' ); ?></button>
						</div>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}

	// --------------------------------------------------------------------------
	// Advanced tab
	// --------------------------------------------------------------------------

	private static function render_tab_advanced(): void {
		$directions_provider  = ATCF_Distance::resolve_provider();
		$expose_locations_api = (bool) get_option( 'atcf_expose_locations_api', true );
		$ip_geo_enabled       = (bool) get_option( 'atcf_ip_geo_enabled', true );
		$ip_geo_provider      = (string) get_option( 'atcf_ip_geo_provider', 'freeipapi' );
		$ip_geo_ttl           = (int) get_option( 'atcf_ip_geo_ttl', 86400 );
		$fallback_location    = (array) get_option( 'atcf_fallback_location', array() );
		$fallback_value       = isset( $fallback_location['lat'], $fallback_location['lng'] ) ? $fallback_location['lat'] . ', ' . $fallback_location['lng'] : '';
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'atcf_settings_advanced' ); ?>

			<h2><?php esc_html_e( 'Directions', 'as-the-crow-flies' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="atcf_directions_provider"><?php esc_html_e( 'Directions provider', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<select id="atcf_directions_provider" name="atcf_directions_provider">
							<option value="google" <?php selected( $directions_provider, 'google' ); ?>><?php esc_html_e( 'Google Maps', 'as-the-crow-flies' ); ?></option>
							<option value="apple"  <?php selected( $directions_provider, 'apple' ); ?>><?php esc_html_e( 'Apple Maps', 'as-the-crow-flies' ); ?></option>
							<option value="waze"   <?php selected( $directions_provider, 'waze' ); ?>><?php esc_html_e( 'Waze', 'as-the-crow-flies' ); ?></option>
							<option value="osm"    <?php selected( $directions_provider, 'osm' ); ?>><?php esc_html_e( 'OpenStreetMap', 'as-the-crow-flies' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Provider used for the {directions}, {directions_url}, and {directions_link_start} template placeholders, for the default "Directions" link in shortcode and block output, and as the default for the Location Finder and Nearest Location blocks. Per-provider placeholders ({directions_url_google}, {directions_url_apple}, etc.) are always available regardless of this setting.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<hr/>

			<h2><?php esc_html_e( 'IP Geolocation', 'as-the-crow-flies' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When browser geolocation is unavailable or declined, the visitor\'s approximate location is estimated from their IP address. No API key is required for any provider. The visitor\'s IP address is sent to the selected provider\'s servers.', 'as-the-crow-flies' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable IP geolocation', 'as-the-crow-flies' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="atcf_ip_geo_enabled" value="0" />
							<input type="checkbox" name="atcf_ip_geo_enabled" value="1" <?php checked( $ip_geo_enabled ); ?> />
							<?php esc_html_e( 'Use IP geolocation as fallback when browser geolocation is unavailable', 'as-the-crow-flies' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_ip_geo_provider"><?php esc_html_e( 'Provider', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<select id="atcf_ip_geo_provider" name="atcf_ip_geo_provider">
							<option value="freeipapi" <?php selected( $ip_geo_provider, 'freeipapi' ); ?>><?php esc_html_e( 'FreeIPAPI', 'as-the-crow-flies' ); ?></option>
							<option value="ipapi"     <?php selected( $ip_geo_provider, 'ipapi' ); ?>><?php esc_html_e( 'ipapi.co', 'as-the-crow-flies' ); ?></option>
							<option value="ipwho"     <?php selected( $ip_geo_provider, 'ipwho' ); ?>><?php esc_html_e( 'ipwho.is', 'as-the-crow-flies' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'FreeIPAPI: no stated rate limit. ipapi.co: 1,000 requests/day free. ipwho.is: 60 requests/minute free. All lookups are cached server-side per the TTL below, so most sites stay well within free limits.', 'as-the-crow-flies' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_ip_geo_ttl"><?php esc_html_e( 'Cache TTL', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<input type="number" id="atcf_ip_geo_ttl" name="atcf_ip_geo_ttl" value="<?php echo esc_attr( $ip_geo_ttl ); ?>" min="60" step="60" class="small-text" />
						<?php esc_html_e( 'seconds', 'as-the-crow-flies' ); ?>
						<p class="description"><?php esc_html_e( 'IP-to-location mappings change infrequently. Default: 86400 (24 hours).', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="atcf_fallback_location"><?php esc_html_e( 'Fallback location', 'as-the-crow-flies' ); ?></label></th>
					<td>
						<input type="text" id="atcf_fallback_location" name="atcf_fallback_location" value="<?php echo esc_attr( $fallback_value ); ?>" class="regular-text" placeholder="40.7128, -74.0060" />
						<p class="description"><?php esc_html_e( 'Latitude and longitude used by "near me" features — this plugin\'s shortcodes and blocks, and HyperTaxonomy PowerSearch "near:me" searches — when the visitor\'s location can\'t be determined from the browser, the CDN or their IP address; for example, your business location. Visitors are told when results are based on it. Leave empty to use no location in that case.', 'as-the-crow-flies' ); ?></p>
						<p class="description"><?php esc_html_e( 'Behind Cloudflare, turn on the "Add visitor location headers" managed transform: the visitor\'s location then comes from Cloudflare, with no lookup service involved.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<hr/>

			<h2><?php esc_html_e( 'REST API', 'as-the-crow-flies' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Controls whether GET /wp-json/atcf/v1/locations returns actual location data (addresses, coordinates, directions links). The endpoint always responds — this setting only determines whether the locations array is populated. Server-side distance filtering and the search widget are not affected either way.', 'as-the-crow-flies' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Return location data via REST API', 'as-the-crow-flies' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="atcf_expose_locations_api" value="0" />
							<input type="checkbox" name="atcf_expose_locations_api" value="1" <?php checked( $expose_locations_api ); ?> />
							<?php esc_html_e( 'Populate the locations list in REST responses', 'as-the-crow-flies' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When unchecked, /atcf/v1/locations always returns an empty locations array — callers cannot retrieve your location database, but the endpoint itself remains accessible. The Location Finder and Nearest Location blocks will show "no locations found." The Term Locations block is unaffected — it renders server-side and never calls the REST endpoint. Shortcodes and server-side distance filtering continue to work normally.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<hr/>

			<h2><?php esc_html_e( 'Uninstall', 'as-the-crow-flies' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Delete data on uninstall', 'as-the-crow-flies' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="atcf_delete_on_uninstall" value="0" />
							<input type="checkbox" name="atcf_delete_on_uninstall" value="1" <?php checked( (bool) get_option( 'atcf_delete_on_uninstall', false ) ); ?> />
							<?php esc_html_e( 'Delete all location data and centroid tables when the plugin is uninstalled', 'as-the-crow-flies' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When unchecked (default), your location data is preserved if you reinstall. Plugin settings are always removed on uninstall regardless of this option.', 'as-the-crow-flies' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<?php
	}

	// --------------------------------------------------------------------------
	// Action handlers
	// --------------------------------------------------------------------------

	public static function handle_download_census(): void {
		check_admin_referer( 'atcf_download_census' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		$result = ATCF_Activator::download_and_import_census_zcta();
		if ( is_wp_error( $result ) ) {
			self::redirect_tab( 'postal-codes', 'error', $result->get_error_message() );
		} else {
			self::redirect_tab( 'postal-codes', 'census_imported', (string) $result );
		}
	}

	public static function handle_reimport_us(): void {
		check_admin_referer( 'atcf_reimport_us' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		ATCF_Activator::reimport_us_centroids();
		self::redirect_tab( 'postal-codes', 'reimported' );
	}

	public static function handle_upload_zcta(): void {
		global $wpdb;
		check_admin_referer( 'atcf_upload_zcta' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		if ( empty( $_FILES['zcta_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['zcta_file']['error'] ) {
			self::redirect_tab( 'postal-codes', 'error', __( 'No file uploaded.', 'as-the-crow-flies' ) );
			return;
		}
		if ( ! is_uploaded_file( $_FILES['zcta_file']['tmp_name'] ) ) {
			self::redirect_tab( 'postal-codes', 'error', __( 'Invalid file upload.', 'as-the-crow-flies' ) );
			return;
		}
		$previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atcf_postal_centroids WHERE country = %s", 'US' ) );
		$result   = ATCF_Activator::import_zcta_file( $_FILES['zcta_file']['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			self::redirect_tab( 'postal-codes', 'error', $result->get_error_message() );
		} else {
			self::redirect_tab( 'postal-codes', 'zcta_imported', $previous . ':' . (string) $result );
		}
	}

	public static function handle_upload_geonames(): void {
		check_admin_referer( 'atcf_upload_geonames' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
		if ( empty( $country ) || empty( $_FILES['geonames_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['geonames_file']['error'] ) {
			self::redirect_tab( 'postal-codes', 'error', __( 'Country code and file are required.', 'as-the-crow-flies' ) );
			return;
		}
		if ( ! is_uploaded_file( $_FILES['geonames_file']['tmp_name'] ) ) {
			self::redirect_tab( 'postal-codes', 'error', __( 'Invalid file upload.', 'as-the-crow-flies' ) );
			return;
		}
		$result = ATCF_Activator::import_geonames_file( $country, $_FILES['geonames_file']['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			self::redirect_tab( 'postal-codes', 'error', $result->get_error_message() );
		} else {
			self::redirect_tab( 'postal-codes', 'geonames_imported', $country . ':' . $result );
		}
	}

	public static function handle_toggle_us(): void {
		check_admin_referer( 'atcf_toggle_us' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		update_option( 'atcf_us_centroids_enabled', get_option( 'atcf_us_centroids_enabled', 1 ) ? 0 : 1 );
		self::redirect_tab( 'postal-codes', 'saved' );
	}

	public static function handle_toggle_country(): void {
		$country = strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ?? '' ) ) );
		check_admin_referer( 'atcf_toggle_country_' . $country );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		$meta = get_option( 'atcf_country_centroids', array() );
		if ( isset( $meta[ $country ] ) ) {
			$meta[ $country ]['enabled'] = empty( $meta[ $country ]['enabled'] );
			update_option( 'atcf_country_centroids', $meta );
		}
		self::redirect_tab( 'postal-codes', 'saved' );
	}

	public static function handle_delete_country(): void {
		$country = strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ?? '' ) ) );
		check_admin_referer( 'atcf_delete_country_' . $country );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'atcf_postal_centroids', array( 'country' => $country ) );
		$meta = get_option( 'atcf_country_centroids', array() );
		unset( $meta[ $country ] );
		update_option( 'atcf_country_centroids', $meta );
		self::redirect_tab( 'postal-codes', 'country_deleted' );
	}


	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	private static function redirect_tab( string $tab, string $msg, string $info = '' ): void {
		$url = add_query_arg( array_filter( array(
			'page'      => 'as-the-crow-flies',
			'tab'       => $tab,
			'atcf_msg'  => $msg,
			'atcf_info' => rawurlencode( $info ),
		) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function render_message( string $msg, string $info ): void {
		if ( '' === $msg ) {
			return;
		}
		$map = array(
			'census_imported'   => sprintf( /* translators: %s: row count */ _n( 'Census data imported: %s Zip code.', 'Census data imported: %s Zip codes.', (int) $info, 'as-the-crow-flies' ), esc_html( number_format_i18n( (int) $info ) ) ),
			'reimported'        => __( 'US centroids reimported.', 'as-the-crow-flies' ),
			'zcta_imported'     => sprintf(
					/* translators: 1: new row count, 2: previous row count */
					_n( 'ZCTA data imported: %1$s row (replaced %2$s).', 'ZCTA data imported: %1$s rows (replaced %2$s).', (int) ( explode( ':', $info )[1] ?? $info ), 'as-the-crow-flies' ),
					esc_html( number_format_i18n( (int) ( explode( ':', $info )[1] ?? $info ) ) ),
					esc_html( number_format_i18n( (int) ( explode( ':', $info )[0] ?? 0 ) ) )
				),
			'geonames_imported' => sprintf( /* translators: %s: row count */ _n( 'GeoNames data imported (%s row).', 'GeoNames data imported (%s rows).', (int) ( explode( ':', $info )[1] ?? $info ), 'as-the-crow-flies' ), esc_html( number_format_i18n( (int) ( explode( ':', $info )[1] ?? $info ) ) ) ),
			'country_deleted'   => __( 'Country data deleted.', 'as-the-crow-flies' ),
			'geocoded'          => sprintf(
				/* translators: 1: locations processed, 2: API failures requeued */
				__( 'Geocoding complete: %1$s locations processed, %2$s API failure(s) requeued.', 'as-the-crow-flies' ),
				esc_html( explode( ':', $info )[1] ?? '0' ),
				esc_html( explode( ':', $info )[0] ?? '0' )
			),
			'imported'          => sprintf( /* translators: %s: inserted:updated counts */ __( 'Import complete.', 'as-the-crow-flies' ) ),
			'requeued'          => sprintf( /* translators: %s: count */ _n( '%s row re-queued for geocoding.', '%s rows re-queued for geocoding.', (int) $info, 'as-the-crow-flies' ), esc_html( number_format_i18n( (int) $info ) ) ),
			'saved'             => __( 'Settings saved.', 'as-the-crow-flies' ),
		);

		if ( 'error' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $info ) . '</p></div>';
		} elseif ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $map[ $msg ] ) . '</p></div>';
		}
	}

}
