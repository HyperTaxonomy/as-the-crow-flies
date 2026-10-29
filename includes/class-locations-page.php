<?php
/**
 * Per-term locations management page.
 *
 * Accessed via admin.php?page=atcf-locations&term_id=N
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Locations_Page {

	public static function register(): void {
		add_action( 'admin_menu',                       array( __CLASS__, 'register_page' ) );
		add_action( 'load-admin_page_atcf-locations',   array( __CLASS__, 'set_menu_state' ) );
		add_action( 'admin_post_atcf_save_location',    array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_atcf_delete_location',  array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_atcf_download_term_template', array( __CLASS__, 'handle_template_download' ) );
		add_action( 'admin_post_atcf_export_term_csv',   array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_atcf_bulk_locations',    array( __CLASS__, 'handle_bulk' ) );
	}

	public static function register_page(): void {
		// Null parent gives hookname admin_page_atcf-locations, which is what WP looks for
		// when the user browses admin.php?page=atcf-locations. Any non-null parent changes
		// the hookname to {parent}_page_atcf-locations, which WP can't find and blocks access.
		add_submenu_page(
			null,
			__( 'Locations', 'as-the-crow-flies' ),
			__( 'Locations', 'as-the-crow-flies' ),
			'manage_categories',
			'atcf-locations',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Keep the Terms top-level menu expanded and the Locations submenu item highlighted
	 * while viewing an individual term's locations page.
	 *
	 * The page is registered with parent=null so its hookname stays admin_page_atcf-locations
	 * (a non-null parent would change the hookname and block capability checks). WordPress
	 * stores it in $submenu[''], and get_admin_page_parent() would normally find it there
	 * and set $parent_file = ''. We override that by returning the correct parent from
	 * parent_file filter — WordPress then sets $parent_file to our value, and any subsequent
	 * get_admin_page_parent() call short-circuits on the non-empty $parent_file before it
	 * searches $submenu. We leave $submenu[''] untouched so get_admin_page_title() can
	 * still find the page and set $title correctly (avoids strip_tags(null) deprecation).
	 */
	public static function set_menu_state(): void {
		global $title;
		// get_admin_page_title() (called from admin-header.php) short-circuits when
		// $title is already set. Without this, it calls get_admin_page_parent() which
		// returns '' for our null-parent page, then searches $menu (not $submenu) and
		// finds nothing, leaving $title null — causing strip_tags(null) deprecation.
		if ( empty( $title ) ) {
			$title = __( 'Locations', 'as-the-crow-flies' );
		}
		add_filter( 'parent_file', fn() => ATCF_Locations_Global::get_parent_slug(), 999 );
		add_filter( 'submenu_file', fn() => ATCF_Locations_Global::PAGE_SLUG, 999 );
		self::register_screen_options();
	}

	// --------------------------------------------------------------------------
	// Page render
	// --------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$term_id = absint( $_GET['term_id'] ?? 0 );
		if ( ! $term_id ) {
			wp_die( esc_html__( 'No term specified.', 'as-the-crow-flies' ) );
		}

		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Term not found.', 'as-the-crow-flies' ) );
		}

		$taxonomy     = $term->taxonomy;
		$taxonomy_obj = get_taxonomy( $taxonomy );
		$edit_url     = get_edit_term_link( $term_id, $taxonomy );

		// Sorting.
		$orderby = sanitize_key( $_GET['orderby'] ?? 'id' );
		$order   = strtoupper( sanitize_key( $_GET['order'] ?? 'asc' ) ) === 'DESC' ? 'DESC' : 'ASC';

		// Pagination.
		$per_page = 25;
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );

		// Filters.
		$filter_country        = strtoupper( sanitize_text_field( $_GET['filter_country'] ?? '' ) );
		$filter_coords         = $_GET['filter_coords'] ?? '';
		$filter_geocode_status = sanitize_key( $_GET['filter_geocode_status'] ?? '' );
		$query_args            = array(
			'term_id'         => $term_id,
			'taxonomy'        => $taxonomy,
			'country'         => $filter_country,
			'locality'        => sanitize_text_field( $_GET['filter_locality'] ?? '' ),
			'region'          => sanitize_text_field( $_GET['filter_region'] ?? '' ),
			'postal_code'     => sanitize_text_field( $_GET['filter_postal_code'] ?? '' ),
			'has_coordinates' => '' !== $filter_coords ? ( 'yes' === $filter_coords ) : null,
			'geocode_status'  => '' !== $filter_geocode_status ? $filter_geocode_status : null,
			'orderby'         => $orderby,
			'order'           => $order,
			'per_page'        => $per_page,
			'page'            => $page,
		);

		$result   = ATCF_Locations::get_all( $query_args );
		$rows     = $result['rows'];
		$total    = $result['total'];
		$pages    = (int) ceil( $total / $per_page );

		// Distinct countries and geocode statuses across ALL locations for this term.
		global $wpdb;
		$term_countries      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT country FROM {$wpdb->prefix}atcf_locations
				 WHERE term_id = %d AND taxonomy = %s ORDER BY country",
				$term_id,
				$taxonomy
			)
		);
		$term_statuses = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT geocode_status FROM {$wpdb->prefix}atcf_locations
				 WHERE term_id = %d AND taxonomy = %s ORDER BY geocode_status",
				$term_id,
				$taxonomy
			)
		);
		$multiple_countries = count( $term_countries ) > 1;
		$us_only            = ! $multiple_countries && ( empty( $term_countries ) || $term_countries[0] === 'US' );

		// Hidden columns (Screen Options).
		$hidden_cols = array_values( get_hidden_columns( get_current_screen() ) );
		$h           = static function ( string $col ) use ( &$hidden_cols ): string {
			return in_array( $col, $hidden_cols, true ) ? ' hidden' : '';
		};

		// Editing an existing location?
		$editing_id = absint( $_GET['edit_id'] ?? 0 );
		$editing    = $editing_id ? ATCF_Locations::get( $editing_id ) : null;
		if ( $editing && (int) $editing->term_id !== $term_id ) {
			$editing    = null;
			$editing_id = 0;
		}

		// Success/error messages.
		$message = sanitize_key( $_GET['atcf_msg'] ?? '' );

		$term_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $taxonomy;
		$back_url   = $edit_url ?: admin_url( "edit-tags.php?taxonomy={$taxonomy}" );

		$base_url = admin_url( 'admin.php?page=atcf-locations&term_id=' . $term_id );
		?>
		<div class="wrap atcf-locations-page">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: 1: term name, 2: taxonomy label */
				printf( esc_html__( 'Locations: %1$s (%2$s)', 'as-the-crow-flies' ), esc_html( $term->name ), esc_html( $term_label ) );
				?>
			</h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="atcf-back-link">
				← <?php esc_html_e( 'Back to term', 'as-the-crow-flies' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'page', ATCF_Locations_Global::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>" class="atcf-back-link" style="margin-left:14px;">
				<?php esc_html_e( 'All Locations', 'as-the-crow-flies' ); ?> →
			</a>
			<hr class="wp-header-end" />

			<?php if ( 'saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location saved.', 'as-the-crow-flies' ); ?></p></div>
			<?php elseif ( 'deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location deleted.', 'as-the-crow-flies' ); ?></p></div>
			<?php elseif ( 'error' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['atcf_err'] ?? '' ) ) ); ?></p></div>
			<?php endif; ?>

			<div id="col-container" class="wp-clearfix">

				<!-- Left column: Add / Edit Location form -->
				<div id="col-left">
					<div class="col-wrap">
						<div class="form-wrap">
							<h2><?php echo $editing_id ? esc_html__( 'Edit Location', 'as-the-crow-flies' ) : esc_html__( 'Add Location', 'as-the-crow-flies' ); ?></h2>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'atcf_save_location_' . $term_id, 'atcf_nonce' ); ?>
								<input type="hidden" name="action" value="atcf_save_location" />
								<input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>" />
								<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
								<?php if ( $editing_id ) : ?>
									<input type="hidden" name="location_id" value="<?php echo esc_attr( $editing_id ); ?>" />
								<?php endif; ?>

								<div class="form-field">
									<input type="text" id="atcf_label" name="label" value="<?php echo esc_attr( $editing->label ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label (optional)', 'as-the-crow-flies' ); ?>" />
								</div>
								<div class="form-field">
									<input type="text" id="atcf_address_line" name="address_line" value="<?php echo esc_attr( $editing->address_line ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Address line 1', 'as-the-crow-flies' ); ?>" />
								</div>
								<div class="form-field">
									<input type="text" id="atcf_address_line2" name="address_line2" value="<?php echo esc_attr( $editing->address_line2 ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Address line 2 (optional) — suite, apt, floor, etc.', 'as-the-crow-flies' ); ?>" />
								</div>
								<div class="form-field">
									<div style="display:flex;gap:6px;width:95%;">
										<input type="text" id="atcf_locality" name="locality" value="<?php echo esc_attr( $editing->locality ?? '' ); ?>" placeholder="<?php echo esc_attr( $us_only ? __( 'City', 'as-the-crow-flies' ) : __( 'City / Locality', 'as-the-crow-flies' ) ); ?>" style="flex:3;" />
										<input type="text" id="atcf_region" name="region" value="<?php echo esc_attr( $editing->region ?? '' ); ?>" placeholder="<?php echo esc_attr( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ) ); ?>" style="flex:1;min-width:0;" <?php echo $us_only ? 'list="atcf_us_states"' : ''; ?> />
										<?php if ( $us_only ) : ?>
										<datalist id="atcf_us_states">
											<?php foreach ( self::us_states() as $abbr => $name ) : ?>
												<option value="<?php echo esc_attr( $abbr ); ?>"><?php echo esc_html( $name ); ?></option>
											<?php endforeach; ?>
										</datalist>
										<?php endif; ?>
										<input type="text" id="atcf_postal_code" name="postal_code" value="<?php echo esc_attr( $editing->postal_code ?? '' ); ?>" placeholder="<?php echo esc_attr( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal', 'as-the-crow-flies' ) ); ?>" style="width:80px;flex-shrink:0;" />
										<?php if ( $multiple_countries ) : ?>
											<select id="atcf_country" name="country" style="width:72px;flex-shrink:0;">
												<?php foreach ( $term_countries as $code ) : ?>
													<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $editing->country ?? 'US', $code ); ?>><?php echo esc_html( $code ); ?></option>
												<?php endforeach; ?>
											</select>
										<?php elseif ( ! $us_only ) : ?>
											<input type="text" id="atcf_country" name="country" value="<?php echo esc_attr( $editing->country ?? 'US' ); ?>" maxlength="2" placeholder="<?php esc_attr_e( 'CC', 'as-the-crow-flies' ); ?>" title="<?php esc_attr_e( 'ISO country code (US, CA, GB, etc.)', 'as-the-crow-flies' ); ?>" style="width:46px;flex-shrink:0;" />
										<?php endif; ?>
									</div>
								</div>
								<div class="form-field">
									<div style="display:flex;gap:6px;align-items:center;width:95%;">
										<input type="number" id="atcf_lat" name="lat" step="any" value="<?php echo esc_attr( $editing->lat ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Latitude', 'as-the-crow-flies' ); ?>" style="flex:1;min-width:0;" />
										<input type="number" id="atcf_lng" name="lng" step="any" value="<?php echo esc_attr( $editing->lng ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Longitude', 'as-the-crow-flies' ); ?>" style="flex:1;min-width:0;" />
									</div>
									<p style="font-size:11px;color:#646970;margin:3px 0 0;"><?php esc_html_e( 'Optional. When blank, the address is automatically converted to coordinates. If that fails, the postal code centroid is used.', 'as-the-crow-flies' ); ?></p>
								</div>

								<p class="submit">
									<input type="submit" class="button button-primary" value="<?php echo esc_attr( $editing_id ? __( 'Update Location', 'as-the-crow-flies' ) : __( 'Add Location', 'as-the-crow-flies' ) ); ?>" />
									<?php if ( $editing_id ) : ?>
										<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'as-the-crow-flies' ); ?></a>
									<?php endif; ?>
								</p>
							</form>

							<?php
							$template_url = add_query_arg( array(
								'action'   => 'atcf_download_term_template',
								'term_id'  => $term_id,
								'taxonomy' => $taxonomy,
								'_wpnonce' => wp_create_nonce( 'atcf_template_' . $term_id ),
							), admin_url( 'admin-post.php' ) );
							?>
							<hr style="margin:10px 0 6px;" />
							<h2><?php esc_html_e( 'Bulk Import CSV', 'as-the-crow-flies' ); ?></h2>
							<form class="atcf-upload-form" data-term-id="<?php echo esc_attr( $term_id ); ?>" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
								<?php wp_nonce_field( 'atcf_upload_term_' . $term_id, 'atcf_nonce' ); ?>
								<div class="form-field">
									<label><input type="file" name="atcf_csv" accept=".csv" required /></label>
									<p><a href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( '↓ Download CSV template', 'as-the-crow-flies' ); ?></a></p>
								</div>
								<fieldset class="atcf-conflict-detection">
									<legend><?php esc_html_e( 'Match existing rows by:', 'as-the-crow-flies' ); ?></legend>
									<label><input type="checkbox" name="conflict_address" value="1" class="atcf-conflict-address" <?php checked( (bool) get_option( 'atcf_conflict_address', true ) ); ?> /> <?php esc_html_e( 'Address + postal code', 'as-the-crow-flies' ); ?></label>
									<label><input type="checkbox" name="conflict_label" value="1" <?php checked( (bool) get_option( 'atcf_conflict_label', false ) ); ?> /> <?php esc_html_e( 'Label', 'as-the-crow-flies' ); ?></label>
									<label><input type="checkbox" name="conflict_coords" value="1" class="atcf-conflict-coords" <?php checked( (bool) get_option( 'atcf_conflict_coords', false ) ); ?> /> <?php esc_html_e( 'Coordinates', 'as-the-crow-flies' ); ?></label>
								<div class="atcf-coords-proximity-row" style="display:none;margin-left:22px;margin-top:2px;">
									<label><input type="radio" name="conflict_coords_mode" value="exact" class="atcf-coords-mode" checked /> <?php esc_html_e( 'Exact match', 'as-the-crow-flies' ); ?></label>
									<label>
										<input type="radio" name="conflict_coords_mode" value="within" class="atcf-coords-mode" />
										<?php esc_html_e( 'Within', 'as-the-crow-flies' ); ?>
										<input type="number" name="conflict_coords_radius" class="atcf-coords-radius-input small-text" step="1" min="0" disabled />
										<span class="atcf-coords-subunit"></span>
										<?php esc_html_e( 'of each other', 'as-the-crow-flies' ); ?>
									</label>
								</div>
								</fieldset>
								<fieldset class="atcf-upload-mode">
									<legend><?php esc_html_e( 'On conflict:', 'as-the-crow-flies' ); ?></legend>
									<label><input type="radio" name="upload_mode" value="update" checked /> <?php esc_html_e( 'Update', 'as-the-crow-flies' ); ?></label>
									<label><input type="radio" name="upload_mode" value="skip" /> <?php esc_html_e( 'Skip', 'as-the-crow-flies' ); ?></label>
									<label><input type="radio" name="upload_mode" value="reject" /> <?php esc_html_e( 'Reject all', 'as-the-crow-flies' ); ?></label>
								</fieldset>
								<p class="submit">
									<button type="submit" class="button"><?php esc_html_e( 'Import', 'as-the-crow-flies' ); ?></button>
								</p>
								<div class="atcf-upload-result" style="display:none;"></div>
							</form>
						</div>
					</div>
				</div><!-- #col-left -->

				<!-- Right column: Locations table with filters -->
				<div id="col-right">
					<div class="col-wrap">

						<!-- Filter form — plain form so it left-aligns with the table -->
						<form method="get">
							<input type="hidden" name="page" value="atcf-locations" />
							<input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>" />
							<div class="atcf-filters" style="margin-top:8px;">
								<?php if ( $multiple_countries ) : ?>
								<select name="filter_country">
									<option value=""><?php esc_html_e( 'All countries', 'as-the-crow-flies' ); ?></option>
									<?php foreach ( $term_countries as $code ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filter_country, $code ); ?>><?php echo esc_html( $code ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php endif; ?>
								<input type="text" name="filter_locality"    class="atcf-filter-input atcf-filter-locality" data-atcf-filter-col="locality" value="<?php echo esc_attr( $query_args['locality'] ); ?>"    placeholder="<?php echo esc_attr( $us_only ? __( 'City', 'as-the-crow-flies' ) : __( 'Locality', 'as-the-crow-flies' ) ); ?>" />
								<input type="text" name="filter_region"      class="atcf-filter-input atcf-filter-region"   data-atcf-filter-col="region"   value="<?php echo esc_attr( $query_args['region'] ); ?>"      placeholder="<?php echo esc_attr( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ) ); ?>" />
								<input type="text" name="filter_postal_code" class="atcf-filter-input atcf-filter-postal"   data-atcf-filter-col="postal_code"   value="<?php echo esc_attr( $query_args['postal_code'] ); ?>" placeholder="<?php echo esc_attr( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal', 'as-the-crow-flies' ) ); ?>" />
								<select name="filter_coords" data-atcf-filter-col="coords">
									<option value=""><?php esc_html_e( 'Coordinates', 'as-the-crow-flies' ); ?></option>
									<option value="yes" <?php selected( $filter_coords, 'yes' ); ?>><?php esc_html_e( 'Has coords', 'as-the-crow-flies' ); ?></option>
									<option value="no" <?php selected( $filter_coords, 'no' ); ?>><?php esc_html_e( 'No coords', 'as-the-crow-flies' ); ?></option>
								</select>
								<?php if ( ! empty( $term_statuses ) ) : ?>
									<select name="filter_geocode_status">
										<option value=""><?php esc_html_e( 'Status', 'as-the-crow-flies' ); ?></option>
										<?php foreach ( $term_statuses as $s ) :
											$key   = null === $s ? 'user_provided' : $s;
											$label = self::geocode_status_label( $s );
											?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_geocode_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
								<button type="submit" class="button"><?php esc_html_e( 'Filter', 'as-the-crow-flies' ); ?></button>
								<a href="<?php echo esc_url( $base_url ); ?>" class="button button-link"><?php esc_html_e( 'Reset', 'as-the-crow-flies' ); ?></a>
							</div>
						</form>

						<?php if ( $total > 0 ) : ?>
							<p class="atcf-count" style="margin:6px 0;">
								<?php
								/* translators: %d: total number of locations */
								printf( esc_html( _n( '%d location', '%d locations', $total, 'as-the-crow-flies' ) ), (int) $total );
								?>
							</p>
						<?php endif; ?>

						<?php
						$bulk_action_url = add_query_arg( array(
							'paged'   => $page,
							'orderby' => $orderby,
							'order'   => strtolower( $order ),
						), $base_url );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="atcf-locations-form">
							<?php wp_nonce_field( 'atcf_bulk_locations_' . $term_id, 'atcf_bulk_nonce' ); ?>
							<input type="hidden" name="action" value="atcf_bulk_locations" />
							<input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>" />
							<input type="hidden" name="redirect_url" value="<?php echo esc_url( $bulk_action_url ); ?>" />

							<!-- Top tablenav: Bulk Actions -->
							<div class="tablenav top">
								<div class="alignleft actions bulkactions">
									<select name="bulk_action">
										<option value="-1"><?php esc_html_e( 'Bulk actions', 'as-the-crow-flies' ); ?></option>
										<option value="geocode"><?php esc_html_e( 'Geocode', 'as-the-crow-flies' ); ?></option>
										<option value="clear_coords"><?php esc_html_e( 'Clear coords', 'as-the-crow-flies' ); ?></option>
										<option value="delete"><?php esc_html_e( 'Delete', 'as-the-crow-flies' ); ?></option>
									</select>
									<input type="submit" id="atcf-doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'as-the-crow-flies' ); ?>"
										   onclick="if(this.closest('.bulkactions').querySelector('select').value==='-1'){return false;}" />
								</div>
								<?php if ( $pages > 1 ) : ?>
								<div class="tablenav-pages">
									<span class="displaying-num">
										<?php printf( esc_html( _n( '%d item', '%d items', $total, 'as-the-crow-flies' ) ), (int) $total ); ?>
									</span>
									<span class="pagination-links">
										<?php if ( $page > 1 ) : ?>
											<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'First page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">«</span>
											</a>
											<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">‹</span>
											</a>
										<?php else : ?>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
										<?php endif; ?>
										<span class="paging-input">
											<label for="atcf-current-page-top" class="screen-reader-text"><?php esc_html_e( 'Current page', 'as-the-crow-flies' ); ?></label>
											<input class="current-page" id="atcf-current-page-top" type="text"
											       name="paged" value="<?php echo esc_attr( $page ); ?>" size="<?php echo esc_attr( strlen( (string) $pages ) ); ?>"
											       form="atcf-pagination-form-top" />
											<span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'as-the-crow-flies' ); ?>
												<span class="total-pages"><?php echo (int) $pages; ?></span>
											</span>
										</span>
										<?php if ( $page < $pages ) : ?>
											<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">›</span>
											</a>
											<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">»</span>
											</a>
										<?php else : ?>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
										<?php endif; ?>
									</span>
								</div>
								<?php endif; ?>
								<br class="clear" />
							</div>

							<?php
							// Capture the header row once; used in both <thead> and <tfoot>.
							ob_start();
							?>
								<td class="manage-column column-cb check-column">
									<input id="cb-select-all-1" type="checkbox" />
								</td>
								<?php echo wp_kses( self::sortable_th( __( 'Label', 'as-the-crow-flies' ), 'label', $orderby, $order, $h('label') !== '' ), self::kses_th() ); ?>
								<th class="column-address<?php echo $h( 'address' ); ?>"><?php esc_html_e( 'Address', 'as-the-crow-flies' ); ?></th>
								<?php echo wp_kses( self::sortable_th( $us_only ? __( 'City', 'as-the-crow-flies' ) : __( 'Locality', 'as-the-crow-flies' ), 'locality', $orderby, $order, $h('locality') !== '' ), self::kses_th() ); ?>
								<?php echo wp_kses( self::sortable_th( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ), 'region', $orderby, $order, $h('region') !== '' ), self::kses_th() ); ?>
								<?php echo wp_kses( self::sortable_th( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal', 'as-the-crow-flies' ), 'postal_code', $orderby, $order, $h('postal_code') !== '' ), self::kses_th() ); ?>
								<?php if ( $multiple_countries ) : ?>
									<?php echo wp_kses( self::sortable_th( __( 'Country', 'as-the-crow-flies' ), 'country', $orderby, $order, $h('country') !== '' ), self::kses_th() ); ?>
								<?php endif; ?>
								<th class="column-coords<?php echo $h( 'coords' ); ?>"><?php esc_html_e( 'Coords', 'as-the-crow-flies' ); ?></th>
								<?php echo wp_kses( self::sortable_th( __( 'Status', 'as-the-crow-flies' ), 'geocode_status', $orderby, $order, $h('geocode_status') !== '' ), self::kses_th() ); ?>
								<th class="column-actions<?php echo $h( 'actions' ); ?>"><?php esc_html_e( 'Actions', 'as-the-crow-flies' ); ?></th>
							<?php $header_row = ob_get_clean(); ?>

							<table class="wp-list-table widefat fixed atcf-locations-table">
								<thead><tr><?php echo wp_kses_post( $header_row ); ?></tr></thead>
								<tbody>
								<?php $row_alt = 0; ?>
								<?php foreach ( $rows as $loc ) : $row_alt++; ?>
									<tr id="atcf-loc-<?php echo esc_attr( $loc->id ); ?>"<?php echo 1 === $row_alt % 2 ? ' class="alternate"' : ''; ?>>
										<th scope="row" class="check-column">
											<input type="checkbox" name="location_ids[]" value="<?php echo esc_attr( $loc->id ); ?>" />
										</th>
										<td class="atcf-col-label"><?php echo esc_html( $loc->label ); ?></td>
										<td class="atcf-col-address">
											<?php echo esc_html( $loc->address_line ); ?>
											<?php if ( $loc->address_line2 ) : ?>
												<br><small><?php echo esc_html( $loc->address_line2 ); ?></small>
											<?php endif; ?>
										</td>
										<td class="atcf-col-locality"><?php echo esc_html( $loc->locality ); ?></td>
										<td class="atcf-col-region"><?php echo esc_html( $loc->region ); ?></td>
										<td class="atcf-col-postal"><?php echo esc_html( $loc->postal_code ); ?></td>
										<?php if ( $multiple_countries ) : ?><td class="atcf-col-country"><?php echo esc_html( $loc->country ); ?></td><?php endif; ?>
										<td class="atcf-col-coords">
											<?php echo null !== $loc->lat ? '<span class="dashicons dashicons-yes-alt" title="' . esc_attr( $loc->lat . ',' . $loc->lng ) . '"></span>' : '—'; ?>
										</td>
										<td class="atcf-col-geocode">
											<?php echo esc_html( self::geocode_status_label( $loc->geocode_status ) ); ?>
										</td>
										<td>
											<a href="#" class="atcf-quick-edit-link"
											   data-id="<?php echo esc_attr( $loc->id ); ?>"
											   data-label="<?php echo esc_attr( $loc->label ); ?>"
											   data-address="<?php echo esc_attr( $loc->address_line ); ?>"
											   data-address2="<?php echo esc_attr( $loc->address_line2 ); ?>"
											   data-locality="<?php echo esc_attr( $loc->locality ); ?>"
											   data-region="<?php echo esc_attr( $loc->region ); ?>"
											   data-postal="<?php echo esc_attr( $loc->postal_code ); ?>"
											   data-country="<?php echo esc_attr( $loc->country ?: 'US' ); ?>"
											   data-lat="<?php echo esc_attr( $loc->lat ?? '' ); ?>"
											   data-lng="<?php echo esc_attr( $loc->lng ?? '' ); ?>"
											><?php esc_html_e( 'Edit', 'as-the-crow-flies' ); ?></a>
											|
											<a href="#" class="atcf-geocode-link"
											   data-id="<?php echo esc_attr( $loc->id ); ?>"
											   data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>"
											><?php esc_html_e( 'Geocode', 'as-the-crow-flies' ); ?></a>
											|
											<a href="#" class="atcf-clear-coords-link"
											   data-id="<?php echo esc_attr( $loc->id ); ?>"
											   data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>"
											><?php esc_html_e( 'Clear', 'as-the-crow-flies' ); ?></a>
											|
											<a href="<?php echo esc_url( self::delete_url( $loc->id, $term_id ) ); ?>"
											   onclick="return confirm('<?php esc_attr_e( 'Delete this location?', 'as-the-crow-flies' ); ?>')">
												<?php esc_html_e( 'Delete', 'as-the-crow-flies' ); ?>
											</a>
										</td>
									</tr>
									<tr id="atcf-inline-<?php echo esc_attr( $loc->id ); ?>" class="atcf-inline-edit-row" style="display:none">
										<td colspan="<?php echo esc_attr( 9 + ( $multiple_countries ? 1 : 0 ) ); ?>" style="padding:8px 10px;background:#f9f9f9;border-top:1px solid #e0e0e0;">
											<?php
											echo self::render_inline_edit_fieldset( $loc, (string) $term_id, $taxonomy, $us_only, $multiple_countries, $term_countries );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
								<tfoot><tr><?php echo wp_kses_post( $header_row ); ?></tr></tfoot>
							</table>

							<?php if ( $pages > 1 ) : ?>
							<div class="tablenav bottom">
								<div class="tablenav-pages">
									<span class="displaying-num">
										<?php
										/* translators: %d: total number of locations */
										printf( esc_html( _n( '%d item', '%d items', $total, 'as-the-crow-flies' ) ), (int) $total );
										?>
									</span>
									<span class="pagination-links">
										<?php if ( $page > 1 ) : ?>
											<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'First page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">«</span>
											</a>
											<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">‹</span>
											</a>
										<?php else : ?>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
										<?php endif; ?>

										<span class="paging-input">
											<label for="atcf-current-page" class="screen-reader-text"><?php esc_html_e( 'Current page', 'as-the-crow-flies' ); ?></label>
											<input class="current-page" id="atcf-current-page" type="text"
											       name="paged" value="<?php echo esc_attr( $page ); ?>" size="<?php echo esc_attr( strlen( (string) $pages ) ); ?>"
											       form="atcf-pagination-form" />
											<span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'as-the-crow-flies' ); ?>
												<span class="total-pages"><?php echo (int) $pages; ?></span>
											</span>
										</span>

										<?php if ( $page < $pages ) : ?>
											<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">›</span>
											</a>
											<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages ) ); ?>">
												<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'as-the-crow-flies' ); ?></span>
												<span aria-hidden="true">»</span>
											</a>
										<?php else : ?>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
											<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
										<?php endif; ?>
									</span>
								</div>
								<br class="clear" />
							</div>
							<?php endif; ?>

						</form><!-- #atcf-locations-form -->
						<?php if ( $pages > 1 ) : ?>
						<?php /* Hidden forms so page-number inputs submit on Enter, preserving all filters. */ ?>
						<form id="atcf-pagination-form" method="get" action="" style="display:none;">
							<?php foreach ( array( 'page', 'term_id', 'orderby', 'order', 'filter_country', 'filter_locality', 'filter_region', 'filter_postal_code', 'filter_coords', 'filter_geocode_status' ) as $key ) :
								$val = wp_unslash( $_GET[ $key ] ?? '' );
								if ( '' !== $val ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>
						</form>
						<form id="atcf-pagination-form-top" method="get" action="" style="display:none;">
							<?php foreach ( array( 'page', 'term_id', 'orderby', 'order', 'filter_country', 'filter_locality', 'filter_region', 'filter_postal_code', 'filter_coords', 'filter_geocode_status' ) as $key ) :
								$val = wp_unslash( $_GET[ $key ] ?? '' );
								if ( '' !== $val ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>
						</form>
						<?php endif; ?>

					<?php
					$export_url = add_query_arg( array(
						'action'   => 'atcf_export_term_csv',
						'term_id'  => $term_id,
						'taxonomy' => $taxonomy,
						'_wpnonce' => wp_create_nonce( 'atcf_export_term_' . $term_id ),
					), admin_url( 'admin-post.php' ) );
					?>
					<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<button type="button" class="button atcf-geocode-term-btn"
						        data-term-id="<?php echo esc_attr( $term_id ); ?>"
						        data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
						        data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>">
							<?php esc_html_e( 'Geocode all', 'as-the-crow-flies' ); ?>
						</button>
						<button type="button"
							class="button atcf-clear-locations"
							data-term-id="<?php echo esc_attr( $term_id ); ?>"
							data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
							<?php esc_html_e( 'Clear all coords', 'as-the-crow-flies' ); ?>
						</button>
						<button type="button"
							class="button atcf-delete-all-locations"
							data-term-id="<?php echo esc_attr( $term_id ); ?>"
							data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
							<?php esc_html_e( 'Delete all locations', 'as-the-crow-flies' ); ?>
						</button>
						<a href="<?php echo esc_url( $export_url ); ?>" class="button">
							<?php esc_html_e( '↓ Export CSV', 'as-the-crow-flies' ); ?>
						</a>
						<span class="atcf-geocode-term-status" style="color:#444;font-size:13px;"></span>
					</div>

				<?php self::render_geocode_info(); ?>

				</div>
			</div><!-- #col-right -->

			</div><!-- #col-container -->
		</div>
		<?php
	}

	// --------------------------------------------------------------------------
	// Form handlers
	// --------------------------------------------------------------------------

	public static function handle_bulk(): void {
		$term_id = absint( $_POST['term_id'] ?? 0 );
		check_admin_referer( 'atcf_bulk_locations_' . $term_id, 'atcf_bulk_nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$action       = sanitize_key( $_POST['bulk_action'] ?? '-1' );
		$location_ids = array_map( 'absint', (array) ( $_POST['location_ids'] ?? array() ) );
		$location_ids = array_values( array_filter(
			$location_ids,
			static function( int $id ) use ( $term_id ): bool {
				$loc = ATCF_Locations::get( $id );
				return null !== $loc && (int) $loc->term_id === $term_id;
			}
		) );
		$redirect     = esc_url_raw( $_POST['redirect_url'] ?? admin_url( 'admin.php?page=atcf-locations&term_id=' . $term_id ) );

		if ( 'delete' === $action && ! empty( $location_ids ) ) {
			foreach ( $location_ids as $id ) {
				ATCF_Locations::delete( $id );
			}
		} elseif ( 'geocode' === $action && ! empty( $location_ids ) ) {
			ATCF_Geocoder::run_for_ids( $location_ids );
		} elseif ( 'clear_coords' === $action && ! empty( $location_ids ) ) {
			ATCF_Locations::bulk_clear_coords( $location_ids );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_save(): void {
		$term_id = absint( $_POST['term_id'] ?? 0 );
		check_admin_referer( 'atcf_save_location_' . $term_id, 'atcf_nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$taxonomy    = sanitize_key( $_POST['taxonomy'] ?? '' );
		$location_id = absint( $_POST['location_id'] ?? 0 );

		$data = array(
			'term_id'      => $term_id,
			'taxonomy'     => $taxonomy,
			'label'        => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'address_line' => sanitize_text_field( wp_unslash( $_POST['address_line'] ?? '' ) ),
			'address_line2'=> sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) ),
			'locality'     => sanitize_text_field( wp_unslash( $_POST['locality'] ?? '' ) ),
			'region'       => sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) ),
			'postal_code'  => sanitize_text_field( wp_unslash( $_POST['postal_code'] ?? '' ) ),
			'country'      => strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? 'US' ) ) ),
			'lat'          => '' !== trim( wp_unslash( $_POST['lat'] ?? '' ) ) ? (float) wp_unslash( $_POST['lat'] ) : null,
			'lng'          => '' !== trim( wp_unslash( $_POST['lng'] ?? '' ) ) ? (float) wp_unslash( $_POST['lng'] ) : null,
		);

		if ( $location_id ) {
			$existing = ATCF_Locations::get( $location_id );
			if ( ! $existing || (int) $existing->term_id !== $term_id ) {
				wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
			}
			$result = ATCF_Locations::update( $location_id, $data );
		} else {
			$result = ATCF_Locations::insert( $data );
		}

		$redirect = admin_url( 'admin.php?page=atcf-locations&term_id=' . $term_id );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array(
				'atcf_msg' => 'error',
				'atcf_err' => rawurlencode( $result->get_error_message() ),
			), $redirect ) );
		} else {
			wp_safe_redirect( add_query_arg( 'atcf_msg', 'saved', $redirect ) );
		}
		exit;
	}

	public static function handle_delete(): void {
		$term_id     = absint( $_GET['term_id'] ?? 0 );
		$location_id = absint( $_GET['location_id'] ?? 0 );
		check_admin_referer( 'atcf_delete_location_' . $location_id );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$existing = ATCF_Locations::get( $location_id );
		if ( ! $existing || (int) $existing->term_id !== $term_id ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		ATCF_Locations::delete( $location_id );

		wp_safe_redirect( add_query_arg( 'atcf_msg', 'deleted', admin_url( 'admin.php?page=atcf-locations&term_id=' . $term_id ) ) );
		exit;
	}

	public static function handle_template_download(): void {
		$term_id = absint( $_GET['term_id'] ?? 0 );
		check_admin_referer( 'atcf_template_' . $term_id );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$term = get_term( $term_id );
		$slug = ( $term && ! is_wp_error( $term ) ) ? $term->slug : 'locations';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atcf-' . sanitize_file_name( $slug ) . '-template.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'label', 'address_line', 'address_line2', 'locality', 'region', 'postal_code', 'country', 'lat', 'lng' ) );
		fclose( $out );
		exit;
	}

	public static function handle_export(): void {
		$term_id  = absint( $_GET['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $_GET['taxonomy'] ?? '' );
		check_admin_referer( 'atcf_export_term_' . $term_id );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$term = get_term( $term_id, $taxonomy );
		$slug = ( $term && ! is_wp_error( $term ) ) ? $term->slug : 'export';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atcf-' . sanitize_file_name( $slug ) . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'label', 'address_line', 'address_line2', 'locality', 'region', 'postal_code', 'country', 'lat', 'lng' ) );

		$locations = ATCF_Locations::get_for_term( $term_id, $taxonomy );
		foreach ( $locations as $loc ) {
			fputcsv( $out, array(
				self::atcf_csv_safe( $loc->label ),
				self::atcf_csv_safe( $loc->address_line ),
				self::atcf_csv_safe( $loc->address_line2 ),
				self::atcf_csv_safe( $loc->locality ),
				self::atcf_csv_safe( $loc->region ),
				self::atcf_csv_safe( $loc->postal_code ),
				self::atcf_csv_safe( $loc->country ),
				$loc->lat ?? '',
				$loc->lng ?? '',
			) );
		}

		fclose( $out );
		exit;
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Neutralize CSV formula injection in a user-supplied text field.
	 *
	 * Spreadsheet apps treat cells beginning with =, +, -, @, tab, or CR as
	 * formulas; prefixing a single quote forces them to be read as text.
	 *
	 * @since 1.0.0
	 * @param mixed $value Field value.
	 * @return string Sanitized value safe for CSV output.
	 */
	private static function atcf_csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function delete_url( int $location_id, int $term_id ): string {
		return wp_nonce_url(
			add_query_arg( array(
				'action'      => 'atcf_delete_location',
				'location_id' => $location_id,
				'term_id'     => $term_id,
			), admin_url( 'admin-post.php' ) ),
			'atcf_delete_location_' . $location_id
		);
	}

	public static function us_states(): array {
		return array(
			'AL' => 'Alabama',        'AK' => 'Alaska',         'AZ' => 'Arizona',
			'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
			'CT' => 'Connecticut',    'DE' => 'Delaware',       'DC' => 'District of Columbia',
			'FL' => 'Florida',        'GA' => 'Georgia',        'HI' => 'Hawaii',
			'ID' => 'Idaho',          'IL' => 'Illinois',       'IN' => 'Indiana',
			'IA' => 'Iowa',           'KS' => 'Kansas',         'KY' => 'Kentucky',
			'LA' => 'Louisiana',      'ME' => 'Maine',          'MD' => 'Maryland',
			'MA' => 'Massachusetts',  'MI' => 'Michigan',       'MN' => 'Minnesota',
			'MS' => 'Mississippi',    'MO' => 'Missouri',       'MT' => 'Montana',
			'NE' => 'Nebraska',       'NV' => 'Nevada',         'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',     'NM' => 'New Mexico',     'NY' => 'New York',
			'NC' => 'North Carolina', 'ND' => 'North Dakota',   'OH' => 'Ohio',
			'OK' => 'Oklahoma',       'OR' => 'Oregon',         'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',   'SC' => 'South Carolina', 'SD' => 'South Dakota',
			'TN' => 'Tennessee',      'TX' => 'Texas',          'UT' => 'Utah',
			'VT' => 'Vermont',        'VA' => 'Virginia',       'WA' => 'Washington',
			'WV' => 'West Virginia',  'WI' => 'Wisconsin',      'WY' => 'Wyoming',
			'AS' => 'American Samoa', 'GU' => 'Guam',           'MP' => 'Northern Mariana Islands',
			'PR' => 'Puerto Rico',    'VI' => 'U.S. Virgin Islands',
			'AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific',
		);
	}

	/**
	 * Column definitions shared by the per-term and global All Locations pages.
	 * Used to register Screen Options and to determine which columns can be hidden.
	 *
	 * @return array<string,string> Column key → label.
	 */
	public static function screen_columns(): array {
		return array(
			'label'          => __( 'Label', 'as-the-crow-flies' ),
			'address'        => __( 'Address', 'as-the-crow-flies' ),
			'locality'       => __( 'City / Locality', 'as-the-crow-flies' ),
			'region'         => __( 'State / Region', 'as-the-crow-flies' ),
			'postal_code'    => __( 'Zip / Postal', 'as-the-crow-flies' ),
			'country'        => __( 'Country', 'as-the-crow-flies' ),
			'coords'         => __( 'Coords', 'as-the-crow-flies' ),
			'geocode_status' => __( 'Status', 'as-the-crow-flies' ),
			'actions'        => __( 'Actions', 'as-the-crow-flies' ),
		);
	}

	/**
	 * Register Screen Options (column checkboxes) and the Help tab for a
	 * locations screen. Call from the load-{hookname} action.
	 */
	public static function register_screen_options(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		add_filter( "manage_{$screen->id}_columns", array( __CLASS__, 'screen_columns' ) );

		$screen->add_help_tab( array(
			'id'      => 'atcf-locations-help',
			'title'   => __( 'Locations', 'as-the-crow-flies' ),
			'content' =>
				'<p>' . esc_html__( 'Each location row stores a physical address that can be used for distance-based filtering and proximity search.', 'as-the-crow-flies' ) . '</p>' .
				'<p>' . esc_html__( 'Click Edit to open an inline form and update the fields without leaving the page. Press Escape to cancel.', 'as-the-crow-flies' ) . '</p>' .
				'<h3>' . esc_html__( 'Status values', 'as-the-crow-flies' ) . '</h3>' .
				'<ul>' .
				'<li><strong>' . esc_html__( 'Pending', 'as-the-crow-flies' ) . '</strong> — ' . esc_html__( 'Queued for automatic geocoding on the next hourly run.', 'as-the-crow-flies' ) . '</li>' .
				'<li><strong>' . esc_html__( 'Census', 'as-the-crow-flies' ) . '</strong> — ' . esc_html__( 'Coordinates resolved via the US Census Geocoder.', 'as-the-crow-flies' ) . '</li>' .
				'<li><strong>' . esc_html__( 'OpenStreetMap', 'as-the-crow-flies' ) . '</strong> — ' . esc_html__( 'Coordinates resolved via Nominatim (Census could not match the address).', 'as-the-crow-flies' ) . '</li>' .
				'<li><strong>' . esc_html__( 'No match', 'as-the-crow-flies' ) . '</strong> — ' . esc_html__( 'Neither geocoder could resolve the address. Edit the address or enter coordinates manually.', 'as-the-crow-flies' ) . '</li>' .
				'<li><strong>' . esc_html__( 'User-provided', 'as-the-crow-flies' ) . '</strong> — ' . esc_html__( 'Coordinates were entered manually and will not be overwritten by geocoding.', 'as-the-crow-flies' ) . '</li>' .
				'</ul>',
		) );
	}

	public static function sortable_th( string $label, string $column, string $current, string $order, bool $hidden = false ): string {
		$is_current = $current === $column;
		$next_order = ( $is_current && $order === 'ASC' ) ? 'desc' : 'asc';
		// Use 'sorted' (not 'sortable') for the active column — WP's CSS shows the
		// filled directional arrow only on 'sorted' columns.
		$class = $is_current
			? 'manage-column column-' . $column . ' sorted ' . strtolower( $order )
			: 'manage-column column-' . $column . ' sortable asc';
		if ( $hidden ) {
			$class .= ' hidden';
		}
		$url   = add_query_arg( array( 'orderby' => $column, 'order' => $next_order ) );
		// WP uses two spans inside .sorting-indicators — one .asc and one .desc.
		// The active direction is highlighted via th.sorted.asc/.desc CSS rules.
		return sprintf(
			'<th class="%s"><a href="%s"><span>%s</span>'
			. '<span class="sorting-indicators">'
			. '<span class="sorting-indicator asc" aria-hidden="true"></span>'
			. '<span class="sorting-indicator desc" aria-hidden="true"></span>'
			. '</span></a></th>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	public static function kses_th(): array {
		return array(
			'th'   => array( 'class' => true ),
			'a'    => array( 'href' => true ),
			'span' => array( 'class' => true ),
		);
	}

	public static function geocode_status_label( ?string $status ): string {
		if ( null === $status ) {
			return __( 'User-provided', 'as-the-crow-flies' );
		}
		switch ( $status ) {
			case 'pending':
				return __( 'Pending', 'as-the-crow-flies' );
			case 'matched':
				return __( 'Census', 'as-the-crow-flies' );
			case 'tie':
				return __( 'Census (tie)', 'as-the-crow-flies' );
			case 'matched_nominatim':
				return __( 'OpenStreetMap', 'as-the-crow-flies' );
			case 'no_match':
				return __( 'No match', 'as-the-crow-flies' );
			case 'failed':
				return __( 'Failed', 'as-the-crow-flies' );
			default:
				return $status;
		}
	}

	/**
	 * Render the inline-edit fieldset for one location row.
	 * Used by both the per-term page and the global All Locations page.
	 *
	 * @param object   $loc              Location row object.
	 * @param string   $save_term_id     Value for Save button data-term-id.
	 * @param string   $save_taxonomy    Value for Save button data-taxonomy.
	 * @param bool     $us_only          Whether all locations in this context are US-only.
	 * @param bool     $multiple_countries Whether multiple countries are present.
	 * @param string[] $countries        Available country codes for the dropdown.
	 * @return string HTML string.
	 */
	public static function render_inline_edit_fieldset(
		object $loc,
		string $save_term_id,
		string $save_taxonomy,
		bool   $us_only,
		bool   $multiple_countries,
		array  $countries
	): string {
		ob_start();
		?>
		<fieldset style="margin:0;padding:0;border:none;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:flex-end;">
			<label style="flex:1 1 140px">
				<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Label', 'as-the-crow-flies' ); ?></span>
				<input type="text" class="atcf-ie-label regular-text" style="width:100%" />
			</label>
			<label style="flex:2 1 200px">
				<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Street', 'as-the-crow-flies' ); ?></span>
				<input type="text" class="atcf-ie-address regular-text" style="width:100%" />
			</label>
			<label style="flex:1 1 140px">
				<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Unit / Suite', 'as-the-crow-flies' ); ?></span>
				<input type="text" class="atcf-ie-address2 regular-text" style="width:100%" />
			</label>
			<div style="flex:3 1 280px;display:flex;gap:6px;align-items:flex-end;min-width:0;">
				<label style="flex:3;min-width:0;">
					<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'City', 'as-the-crow-flies' ); ?></span>
					<input type="text" class="atcf-ie-locality" style="width:100%" />
				</label>
				<label style="flex:1;min-width:0;">
					<span style="display:block;font-weight:600;margin-bottom:3px"><?php echo esc_html( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ) ); ?></span>
					<input type="text" class="atcf-ie-region" style="width:100%"<?php echo $us_only ? ' list="atcf_us_states"' : ''; ?> />
				</label>
				<label style="flex:0 0 65px">
					<span style="display:block;font-weight:600;margin-bottom:3px"><?php echo esc_html( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal', 'as-the-crow-flies' ) ); ?></span>
					<input type="text" class="atcf-ie-postal" style="width:65px" />
				</label>
				<?php if ( $us_only ) : ?>
					<input type="hidden" class="atcf-ie-country" value="US" />
				<?php elseif ( $multiple_countries ) : ?>
					<label style="flex:0 0 auto">
						<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Country', 'as-the-crow-flies' ); ?></span>
						<select class="atcf-ie-country">
							<?php foreach ( $countries as $code ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php else : ?>
					<label style="flex:0 0 46px">
						<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'CC', 'as-the-crow-flies' ); ?></span>
						<input type="text" class="atcf-ie-country" maxlength="2" style="width:46px" />
					</label>
				<?php endif; ?>
			</div>
			<label style="flex:0 1 90px">
				<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Lat', 'as-the-crow-flies' ); ?></span>
				<input type="number" step="any" class="atcf-ie-lat" style="width:100%" />
			</label>
			<label style="flex:0 1 90px">
				<span style="display:block;font-weight:600;margin-bottom:3px"><?php esc_html_e( 'Lng', 'as-the-crow-flies' ); ?></span>
				<input type="number" step="any" class="atcf-ie-lng" style="width:100%" />
			</label>
			<div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end;">
				<button type="button" class="button button-primary atcf-ie-save"
				        data-id="<?php echo esc_attr( $loc->id ); ?>"
				        data-term-id="<?php echo esc_attr( $save_term_id ); ?>"
				        data-taxonomy="<?php echo esc_attr( $save_taxonomy ); ?>"
				        data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>"
				><?php esc_html_e( 'Save', 'as-the-crow-flies' ); ?></button>
				<button type="button" class="button atcf-ie-cancel"
				        data-id="<?php echo esc_attr( $loc->id ); ?>"
				><?php esc_html_e( 'Cancel', 'as-the-crow-flies' ); ?></button>
				<span class="atcf-ie-status" style="color:#444;font-size:13px"></span>
			</div>
		</fieldset>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the "About coordinates" informational block.
	 * Used by both the per-term page and the global All Locations page.
	 */
	public static function render_geocode_info(): void {
		?>
		<div class="atcf-geocode-info" style="margin-top:16px;padding:12px 14px;background:#f9f9f9;border:1px solid #dcdcde;border-radius:3px;font-size:13px;">
			<strong><?php esc_html_e( 'About coordinates', 'as-the-crow-flies' ); ?></strong>
			<p style="margin:6px 0 0;"><?php esc_html_e( 'Distance calculations between locations with known coordinates are supported worldwide. When coordinates are not available, the centroid of the postal code area is used instead. Entered US addresses are automatically geocoded via the Census Geocoder or OpenStreetMap — this runs in the background hourly after import.', 'as-the-crow-flies' ); ?></p>
		</div>
		<?php
	}
}
