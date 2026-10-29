<?php
/**
 * Global Locations admin page — shown under Terms menu.
 *
 * Displays all locations for a single taxonomy at a time, with a taxonomy
 * selector. Import CSV supports multiple taxonomies in one file.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Locations_Global {

	const PAGE_SLUG = 'atcf-locations-global';

	public static function register(): void {
		// Priority 15 so we run after HT's admin_menu at priority 10.
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 15 );

		add_action( 'admin_post_atcf_export_all_csv',           array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_atcf_import_global_csv',        array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_atcf_download_global_template', array( __CLASS__, 'handle_template' ) );
		add_action( 'admin_post_atcf_geocode_now',              array( __CLASS__, 'handle_geocode' ) );
		add_action( 'admin_post_atcf_gl_delete_location',       array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_atcf_gl_bulk_delete',           array( __CLASS__, 'handle_bulk_delete' ) );
	}

	// --------------------------------------------------------------------------
	// Menu registration
	// --------------------------------------------------------------------------

	public static function add_menu(): void {
		// Reuse HyperTaxonomy's shared Terms top-level menu when present.
		$parent = ATCF_HT_Integration::terms_menu_slug();

		// No Terms menu found — create our own with the same appearance. The parent
		// capability is standardized across the suite (HT, TC, ATCF) at manage_options;
		// the Term Locations submenu keeps its own manage_categories cap, and WordPress
		// still surfaces the parent for any user who can reach an accessible submenu.
		if ( null === $parent ) {
			$parent = 'atcf-terms';
			add_menu_page(
				__( 'Terms', 'as-the-crow-flies' ),
				__( 'Terms', 'as-the-crow-flies' ),
				'manage_options',
				'atcf-terms',
				'__return_empty_string',
				'dashicons-tag',
				6
			);
			remove_submenu_page( 'atcf-terms', 'atcf-terms' );
		}

		$hookname = add_submenu_page(
			$parent,
			__( 'Term Locations', 'as-the-crow-flies' ),
			__( 'Term Locations', 'as-the-crow-flies' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		add_action( "load-{$hookname}", array( 'ATCF_Locations_Page', 'register_screen_options' ) );
	}

	// --------------------------------------------------------------------------
	// Page render
	// --------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		// ── State ──────────────────────────────────────────────────────────────
		$per_page = 50;
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby  = sanitize_key( $_GET['orderby'] ?? 'id' );
		$order    = strtoupper( sanitize_key( $_GET['order'] ?? 'asc' ) ) === 'DESC' ? 'DESC' : 'ASC';

		$filter_taxonomy       = sanitize_key( $_GET['filter_taxonomy'] ?? '' );
		$filter_coords         = $_GET['filter_coords'] ?? '';
		$filter_geocode_status = sanitize_key( $_GET['filter_geocode_status'] ?? '' );
		$filter_locality       = sanitize_text_field( $_GET['filter_locality'] ?? '' );
		$filter_region         = sanitize_text_field( $_GET['filter_region'] ?? '' );
		$filter_postal_code    = sanitize_text_field( $_GET['filter_postal_code'] ?? '' );
		$filter_country        = strtoupper( sanitize_text_field( $_GET['filter_country'] ?? '' ) );

		$enabled_taxonomies = (array) get_option( 'atcf_enabled_taxonomies', array() );

		// Default to first enabled taxonomy only on initial page load (no explicit taxonomy selection).
		// When the user explicitly selects "All taxonomies", filter_taxonomy is '' but is present in GET.
		if ( '' === $filter_taxonomy && ! isset( $_GET['filter_taxonomy'] ) && ! empty( $enabled_taxonomies ) ) {
			$filter_taxonomy = $enabled_taxonomies[0];
		}

		// US-only detection for the currently filtered taxonomy.
		global $wpdb;
		$countries_in_tax = array();
		if ( $filter_taxonomy ) {
			$countries_in_tax = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT country FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s ORDER BY country",
					$filter_taxonomy
				)
			);
		} else {
			$countries_in_tax = $wpdb->get_col(
				"SELECT DISTINCT country FROM {$wpdb->prefix}atcf_locations ORDER BY country"
			);
		}
		$multiple_countries = count( $countries_in_tax ) > 1;
		$us_only            = ! $multiple_countries && ( empty( $countries_in_tax ) || $countries_in_tax[0] === 'US' );

		$statuses_in_view = $filter_taxonomy
			? $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT geocode_status FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s ORDER BY geocode_status",
				$filter_taxonomy
			) )
			: $wpdb->get_col(
				"SELECT DISTINCT geocode_status FROM {$wpdb->prefix}atcf_locations ORDER BY geocode_status"
			);

		$query_args = array(
			'taxonomy'        => $filter_taxonomy,
			'country'         => $filter_country,
			'locality'        => $filter_locality,
			'region'          => $filter_region,
			'postal_code'     => $filter_postal_code,
			'has_coordinates' => '' !== $filter_coords ? ( 'yes' === $filter_coords ) : null,
			'orderby'         => $orderby,
			'order'           => $order,
			'per_page'        => $per_page,
			'page'            => $page,
		);

		$result = ATCF_Locations::get_all( $query_args );
		$rows   = $result['rows'];
		$total  = $result['total'];

		if ( ! empty( $rows ) ) {
			_prime_term_caches( array_unique( array_column( (array) $rows, 'term_id' ) ) );
		}
		$pages  = (int) ceil( $total / $per_page );

		$message = sanitize_key( $_GET['atcf_msg'] ?? '' );
		$info    = sanitize_text_field( wp_unslash( $_GET['atcf_info'] ?? '' ) );

		$base_url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );

		$template_url = add_query_arg( array(
			'action'   => 'atcf_download_global_template',
			'_wpnonce' => wp_create_nonce( 'atcf_global_template' ),
		), admin_url( 'admin-post.php' ) );

		$geocode_url = wp_nonce_url( admin_url( 'admin-post.php?action=atcf_geocode_now' ), 'atcf_geocode_now' );

		// Hidden columns (Screen Options).
		$hidden_cols = array_values( get_hidden_columns( get_current_screen() ) );
		$h           = static function ( string $col ) use ( &$hidden_cols ): string {
			return in_array( $col, $hidden_cols, true ) ? ' hidden' : '';
		};

		?>
		<div class="wrap atcf-locations-page atcf-global-locations-page">
			<h1><?php esc_html_e( 'Term Locations', 'as-the-crow-flies' ); ?></h1>
			<hr class="wp-header-end" />

			<?php self::render_message( $message, $info ); ?>
			<?php
			$non_us = absint( $_GET['atcf_non_us'] ?? 0 );
			if ( $non_us > 0 ) :
				/* translators: %d: number of locations */
				$non_us_msg = sprintf( _n( '%d non-US address has no coordinates.', '%d non-US addresses have no coordinates.', $non_us, 'as-the-crow-flies' ), $non_us );
				$non_us_msg .= ' ' . __( 'Export them, add coordinates via Geocodio or OpenCage, and re-import with lat/lng filled in.', 'as-the-crow-flies' );
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $non_us_msg ) . '</p></div>';
			endif;
			?>

			<div id="col-container" class="wp-clearfix">

				<!-- Left column: Import -->
				<div id="col-left">
					<div class="col-wrap">
						<div class="form-wrap">
							<h2><?php esc_html_e( 'Bulk Import CSV', 'as-the-crow-flies' ); ?></h2>
							<p class="description"><?php esc_html_e( 'The global CSV format includes taxonomy and term slug columns so a single file can contain locations for multiple taxonomies and terms.', 'as-the-crow-flies' ); ?></p>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
								<?php wp_nonce_field( 'atcf_import_global' ); ?>
								<input type="hidden" name="action" value="atcf_import_global_csv" />
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
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'as-the-crow-flies' ); ?></button>
								</p>
								<div class="atcf-upload-result" style="display:none;"></div>
							</form>
						</div>
					</div>
				</div><!-- #col-left -->

				<!-- Right column: Locations table -->
				<div id="col-right">
					<div class="col-wrap">

						<!-- Taxonomy selector + filters -->
						<form method="get" class="atcf-filters" style="margin-top:8px;">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

							<!-- Taxonomy selector (determines which table to show) -->
							<select name="filter_taxonomy" style="font-weight:600;">
								<option value=""><?php esc_html_e( 'All taxonomies', 'as-the-crow-flies' ); ?></option>
								<?php foreach ( $enabled_taxonomies as $slug ) :
									$tax_obj = get_taxonomy( $slug );
									$label   = $tax_obj ? $tax_obj->labels->name . ' (' . $slug . ')' : $slug;
									?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filter_taxonomy, $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<?php if ( $multiple_countries ) : ?>
								<select name="filter_country" data-atcf-filter-col="country">
									<option value=""><?php esc_html_e( 'All countries', 'as-the-crow-flies' ); ?></option>
									<?php foreach ( $countries_in_tax as $code ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filter_country, $code ); ?>><?php echo esc_html( $code ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>

							<input type="text" name="filter_locality"    class="atcf-filter-input atcf-filter-locality" data-atcf-filter-col="locality" value="<?php echo esc_attr( $filter_locality ); ?>"    placeholder="<?php echo esc_attr( $us_only ? __( 'City', 'as-the-crow-flies' ) : __( 'Locality', 'as-the-crow-flies' ) ); ?>" />
							<input type="text" name="filter_region"      class="atcf-filter-input atcf-filter-region"   data-atcf-filter-col="region"   value="<?php echo esc_attr( $filter_region ); ?>"      placeholder="<?php echo esc_attr( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ) ); ?>" />
							<input type="text" name="filter_postal_code" class="atcf-filter-input atcf-filter-postal"   data-atcf-filter-col="postal_code"   value="<?php echo esc_attr( $filter_postal_code ); ?>" placeholder="<?php echo esc_attr( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal code', 'as-the-crow-flies' ) ); ?>" />
							<select name="filter_coords" data-atcf-filter-col="coords">
								<option value=""><?php esc_html_e( 'Coordinates', 'as-the-crow-flies' ); ?></option>
								<option value="yes" <?php selected( $filter_coords, 'yes' ); ?>><?php esc_html_e( 'Has coords', 'as-the-crow-flies' ); ?></option>
								<option value="no"  <?php selected( $filter_coords, 'no' ); ?> ><?php esc_html_e( 'No coords', 'as-the-crow-flies' ); ?></option>
							</select>
							<?php if ( ! empty( $statuses_in_view ) ) : ?>
								<select name="filter_geocode_status" data-atcf-filter-col="geocode_status">
									<option value=""><?php esc_html_e( 'Status', 'as-the-crow-flies' ); ?></option>
									<?php foreach ( $statuses_in_view as $s ) :
										$key   = null === $s ? 'user_provided' : $s;
										$label = ATCF_Locations_Page::geocode_status_label( $s );
										?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_geocode_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							<button type="submit" class="button"><?php esc_html_e( 'Filter', 'as-the-crow-flies' ); ?></button>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button button-link"><?php esc_html_e( 'Reset', 'as-the-crow-flies' ); ?></a>
						</form>

						<?php if ( $total > 0 ) : ?>
							<p class="atcf-count" style="margin:6px 0;">
								<?php printf( esc_html( _n( '%d location', '%d locations', $total, 'as-the-crow-flies' ) ), (int) $total ); ?>
							</p>
						<?php endif; ?>

						<?php if ( $us_only ) : ?>
					<datalist id="atcf_us_states">
						<?php foreach ( ATCF_Locations_Page::us_states() as $abbr => $name ) : ?>
							<option value="<?php echo esc_attr( $abbr ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<?php endif; ?>
					<div class="tablenav top">
						<div class="alignleft actions bulkactions">
							<select name="bulk_action" form="atcf-gl-bulk-form">
								<option value="-1"><?php esc_html_e( 'Bulk actions', 'as-the-crow-flies' ); ?></option>
								<option value="geocode"><?php esc_html_e( 'Geocode', 'as-the-crow-flies' ); ?></option>
								<option value="clear_coords"><?php esc_html_e( 'Clear coords', 'as-the-crow-flies' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'as-the-crow-flies' ); ?></option>
							</select>
							<input type="submit" class="button action" form="atcf-gl-bulk-form" value="<?php esc_attr_e( 'Apply', 'as-the-crow-flies' ); ?>"
							       onclick="if(this.closest('.bulkactions').querySelector('select').value==='-1'){return false;}" />
						</div>
						<?php if ( $pages > 1 ) : ?>
						<div class="tablenav-pages">
							<span class="displaying-num"><?php printf( esc_html( _n( '%d item', '%d items', $total, 'as-the-crow-flies' ) ), (int) $total ); ?></span>
							<span class="pagination-links">
								<?php if ( $page > 1 ) : ?>
									<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>"><span aria-hidden="true">&laquo;</span></a>
									<a class="prev-page button"  href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>"><span aria-hidden="true">&lsaquo;</span></a>
								<?php else : ?>
									<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
									<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
								<?php endif; ?>
								<span class="paging-input">
									<input class="current-page" type="text" name="paged" value="<?php echo esc_attr( $page ); ?>" size="<?php echo esc_attr( strlen( (string) $pages ) ); ?>" form="atcf-gl-pager-top" />
									<span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'as-the-crow-flies' ); ?> <span class="total-pages"><?php echo (int) $pages; ?></span></span>
								</span>
								<?php if ( $page < $pages ) : ?>
									<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><span aria-hidden="true">&rsaquo;</span></a>
									<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages ) ); ?>"><span aria-hidden="true">&raquo;</span></a>
								<?php else : ?>
									<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
									<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
								<?php endif; ?>
							</span>
						</div>
						<?php endif; ?>
						<br class="clear" />
					</div>
					<?php if ( $pages > 1 ) : ?>
					<form id="atcf-gl-pager-top" method="get" action="" style="display:none;">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
						<?php foreach ( array( 'filter_taxonomy', 'filter_country', 'filter_locality', 'filter_region', 'filter_postal_code', 'filter_coords', 'filter_geocode_status', 'orderby', 'order' ) as $k ) :
							$v = wp_unslash( $_GET[ $k ] ?? '' );
							if ( '' !== $v ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
						<?php endif; endforeach; ?>
					</form>
					<?php endif; ?>
					<table class="wp-list-table widefat fixed atcf-locations-table">
							<?php
							// Build header row — same for thead and tfoot.
							ob_start();
							?>
							<td class="manage-column column-cb check-column">
								<input id="cb-gl-select-all" type="checkbox" />
							</td>
							<th class="column-term"><?php esc_html_e( 'Term', 'as-the-crow-flies' ); ?></th>
							<?php echo wp_kses( ATCF_Locations_Page::sortable_th( __( 'Label', 'as-the-crow-flies' ), 'label', $orderby, $order, $h('label') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<th class="column-address<?php echo $h( 'address' ); ?>"><?php esc_html_e( 'Address', 'as-the-crow-flies' ); ?></th>
							<?php echo wp_kses( ATCF_Locations_Page::sortable_th( $us_only ? __( 'City', 'as-the-crow-flies' ) : __( 'Locality', 'as-the-crow-flies' ), 'locality', $orderby, $order, $h('locality') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<?php echo wp_kses( ATCF_Locations_Page::sortable_th( $us_only ? __( 'State', 'as-the-crow-flies' ) : __( 'Region', 'as-the-crow-flies' ), 'region', $orderby, $order, $h('region') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<?php echo wp_kses( ATCF_Locations_Page::sortable_th( $us_only ? __( 'Zip', 'as-the-crow-flies' ) : __( 'Postal', 'as-the-crow-flies' ), 'postal_code', $orderby, $order, $h('postal_code') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<?php if ( $multiple_countries ) : ?>
								<?php echo wp_kses( ATCF_Locations_Page::sortable_th( __( 'Country', 'as-the-crow-flies' ), 'country', $orderby, $order, $h('country') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<?php endif; ?>
							<th class="column-coords<?php echo $h( 'coords' ); ?>"><?php esc_html_e( 'Coords', 'as-the-crow-flies' ); ?></th>
							<?php echo wp_kses( ATCF_Locations_Page::sortable_th( __( 'Status', 'as-the-crow-flies' ), 'geocode_status', $orderby, $order, $h('geocode_status') !== '' ), ATCF_Locations_Page::kses_th() ); ?>
							<th class="column-actions<?php echo $h( 'actions' ); ?>"><?php esc_html_e( 'Actions', 'as-the-crow-flies' ); ?></th>
							<?php $header_row = ob_get_clean(); ?>

							<thead><tr><?php echo wp_kses_post( $header_row ); ?></tr></thead>
							<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr class="no-items">
									<td class="colspanchange" colspan="<?php echo esc_attr( 10 + ( $multiple_countries ? 1 : 0 ) ); ?>">
										<?php esc_html_e( 'No locations found.', 'as-the-crow-flies' ); ?>
									</td>
								</tr>
							<?php endif; ?>
							<?php $row_alt = 0; ?>
								<?php foreach ( $rows as $loc ) :
								$term     = get_term( $loc->term_id );
								$term_name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $loc->term_id;
								$term_url  = admin_url( 'admin.php?page=atcf-locations&term_id=' . $loc->term_id );
								?>
								<tr id="atcf-loc-<?php echo esc_attr( $loc->id ); ?>"<?php echo 1 === ++$row_alt % 2 ? ' class="alternate"' : ''; ?>>
									<th scope="row" class="check-column">
										<input type="checkbox" name="location_ids[]" value="<?php echo esc_attr( $loc->id ); ?>" form="atcf-gl-bulk-form" />
									</th>
									<td>
										<a href="<?php echo esc_url( $term_url ); ?>"><?php echo esc_html( $term_name ); ?></a>
										<?php if ( ! $filter_taxonomy ) : ?>
											<br><small class="atcf-taxonomy"><?php echo esc_html( $loc->taxonomy ); ?></small>
										<?php endif; ?>
									</td>
									<td class="atcf-col-label"><?php echo esc_html( $loc->label ); ?></td>
									<td class="atcf-col-address">
										<?php echo esc_html( $loc->address_line ); ?>
										<?php if ( $loc->address_line2 ) : ?><br><small><?php echo esc_html( $loc->address_line2 ); ?></small><?php endif; ?>
									</td>
									<td class="atcf-col-locality"><?php echo esc_html( $loc->locality ); ?></td>
									<td class="atcf-col-region"><?php echo esc_html( $loc->region ); ?></td>
									<td class="atcf-col-postal"><?php echo esc_html( $loc->postal_code ); ?></td>
									<?php if ( $multiple_countries ) : ?><td class="atcf-col-country"><?php echo esc_html( $loc->country ); ?></td><?php endif; ?>
									<td class="atcf-col-coords"><?php echo null !== $loc->lat ? '<span class="dashicons dashicons-yes-alt" title="' . esc_attr( $loc->lat . ',' . $loc->lng ) . '"></span>' : '—'; ?></td>
									<td class="atcf-col-geocode"><?php echo esc_html( ATCF_Locations_Page::geocode_status_label( $loc->geocode_status ) ); ?></td>
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
										<a href="<?php echo esc_url( self::delete_location_url( $loc->id ) ); ?>"
										   onclick="return confirm('<?php esc_attr_e( 'Delete this location?', 'as-the-crow-flies' ); ?>')">
											<?php esc_html_e( 'Delete', 'as-the-crow-flies' ); ?>
										</a>
									</td>
								</tr>
								<tr id="atcf-inline-<?php echo esc_attr( $loc->id ); ?>" class="atcf-inline-edit-row" style="display:none">
									<td colspan="<?php echo esc_attr( 10 + ( $multiple_countries ? 1 : 0 ) ); ?>" style="padding:8px 10px;background:#f9f9f9;border-top:1px solid #e0e0e0;">
										<?php
										echo ATCF_Locations_Page::render_inline_edit_fieldset( $loc, (string) $loc->term_id, $loc->taxonomy, $us_only, $multiple_countries, $countries_in_tax );
										?>
									</td>
								</tr>
							<?php endforeach; ?>

							</tbody>
							<tfoot><tr><?php echo wp_kses_post( $header_row ); ?></tr></tfoot>
						</table>

												<!-- Bulk delete form -->
						<form id="atcf-gl-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'atcf_gl_bulk_delete' ); ?>
							<input type="hidden" name="action" value="atcf_gl_bulk_delete" />
						</form>

						<?php if ( $pages > 1 ) : ?>
						<div class="tablenav bottom">
							<div class="tablenav-pages">
								<span class="displaying-num"><?php printf( esc_html( _n( '%d item', '%d items', $total, 'as-the-crow-flies' ) ), (int) $total ); ?></span>
								<span class="pagination-links">
									<?php if ( $page > 1 ) : ?>
										<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>"><span aria-hidden="true">&laquo;</span></a>
										<a class="prev-page button"  href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>"><span aria-hidden="true">&lsaquo;</span></a>
									<?php else : ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
									<?php endif; ?>
									<span class="paging-input">
										<input class="current-page" type="text" name="paged" value="<?php echo esc_attr( $page ); ?>" size="<?php echo esc_attr( strlen( (string) $pages ) ); ?>" form="atcf-gl-pager" />
										<span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'as-the-crow-flies' ); ?> <span class="total-pages"><?php echo (int) $pages; ?></span></span>
									</span>
									<?php if ( $page < $pages ) : ?>
										<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><span aria-hidden="true">&rsaquo;</span></a>
										<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pages ) ); ?>"><span aria-hidden="true">&raquo;</span></a>
									<?php else : ?>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
										<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
									<?php endif; ?>
								</span>
							</div>
							<br class="clear" />
						</div>
						<form id="atcf-gl-pager" method="get" action="" style="display:none;">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
							<?php foreach ( array( 'filter_taxonomy', 'filter_country', 'filter_locality', 'filter_region', 'filter_postal_code', 'filter_coords', 'filter_geocode_status', 'orderby', 'order' ) as $k ) :
								$v = wp_unslash( $_GET[ $k ] ?? '' );
								if ( '' !== $v ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
							<?php endif; endforeach; ?>
						</form>
						<?php endif; ?>

						<!-- Action buttons -->
						<?php
						$export_url = add_query_arg( array(
							'action'          => 'atcf_export_all_csv',
							'filter_taxonomy' => $filter_taxonomy,
							'_wpnonce'        => wp_create_nonce( 'atcf_export_all' ),
						), admin_url( 'admin-post.php' ) );
						?>
						<div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<a href="<?php echo esc_url( $geocode_url ); ?>" class="button">
								<?php esc_html_e( 'Geocode all', 'as-the-crow-flies' ); ?>
							</a>
							<button type="button"
								class="button atcf-gl-clear-all-coords"
								data-taxonomy="<?php echo esc_attr( $filter_taxonomy ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>">
								<?php esc_html_e( 'Clear all coords', 'as-the-crow-flies' ); ?>
							</button>
							<button type="button"
								class="button atcf-gl-delete-all"
								data-taxonomy="<?php echo esc_attr( $filter_taxonomy ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'atcf_admin' ) ); ?>">
								<?php esc_html_e( 'Delete all locations', 'as-the-crow-flies' ); ?>
							</button>
							<a href="<?php echo esc_url( $export_url ); ?>" class="button">
								<?php esc_html_e( '↓ Export CSV', 'as-the-crow-flies' ); ?>
							</a>
							<span class="atcf-gl-action-status" style="color:#444;font-size:13px;"></span>
						</div>

					<?php ATCF_Locations_Page::render_geocode_info(); ?>

					</div>
				</div><!-- #col-right -->

			</div><!-- #col-container -->
		</div>
		<?php
	}

	// --------------------------------------------------------------------------
	// Handlers
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

	public static function handle_export(): void {
		check_admin_referer( 'atcf_export_all' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}

		$filter_taxonomy = sanitize_key( $_GET['filter_taxonomy'] ?? '' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atcf-locations-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'taxonomy', 'term_slug', 'country', 'label', 'address_line', 'address_line2', 'locality', 'region', 'postal_code', 'lat', 'lng' ) );

		$page = 1;
		do {
			$result = ATCF_Locations::get_all( array(
				'taxonomy' => $filter_taxonomy,
				'per_page' => 500,
				'page'     => $page,
			) );
			foreach ( $result['rows'] as $loc ) {
				$term = get_term( $loc->term_id );
				$slug = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
				fputcsv( $out, array(
					$loc->taxonomy,
					self::atcf_csv_safe( $slug ),
					self::atcf_csv_safe( $loc->country ),
					self::atcf_csv_safe( $loc->label ),
					self::atcf_csv_safe( $loc->address_line ),
					self::atcf_csv_safe( $loc->address_line2 ),
					self::atcf_csv_safe( $loc->locality ),
					self::atcf_csv_safe( $loc->region ),
					self::atcf_csv_safe( $loc->postal_code ),
					$loc->lat ?? '', $loc->lng ?? '',
				) );
			}
			$page++;
		} while ( count( $result['rows'] ) === 500 );

		fclose( $out );
		exit;
	}

	public static function handle_import(): void {
		check_admin_referer( 'atcf_import_global' );
		if ( ! current_user_can( 'manage_categories' ) || empty( $_FILES['atcf_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['atcf_csv']['error'] ) {
			wp_die( esc_html__( 'Permission denied or no file.', 'as-the-crow-flies' ) );
		}
		if ( ! is_uploaded_file( $_FILES['atcf_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'Invalid file upload.', 'as-the-crow-flies' ) );
		}
		$upload_mode = sanitize_key( $_POST['upload_mode'] ?? 'update' );
		$conflicts   = array(
			'address'       => ! empty( $_POST['conflict_address'] ),
			'label'         => ! empty( $_POST['conflict_label'] ),
			'coords'        => ! empty( $_POST['conflict_coords'] ),
			'coords_mode'   => sanitize_key( $_POST['conflict_coords_mode'] ?? 'exact' ),
			'coords_radius' => (float) ( $_POST['conflict_coords_radius'] ?? 0 ),
		);
		update_option( 'atcf_conflict_address', $conflicts['address'] );
		update_option( 'atcf_conflict_label',   $conflicts['label'] );
		update_option( 'atcf_conflict_coords',  $conflicts['coords'] );
		$result = ATCF_CSV::import_global( $_FILES['atcf_csv']['tmp_name'], $upload_mode, $conflicts );
		$args = array(
			'page'      => self::PAGE_SLUG,
			'atcf_msg'  => is_wp_error( $result ) ? 'error' : 'imported',
			'atcf_info' => is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : implode( ':', array_values( $result ) ),
		);
		if ( ! is_wp_error( $result ) ) {
			$non_us = ATCF_Locations::count_non_us_without_coords();
			if ( $non_us > 0 ) {
				$args['atcf_non_us'] = $non_us;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_template(): void {
		check_admin_referer( 'atcf_global_template' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atcf-global-template.csv"' );
		header( 'Pragma: no-cache' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'taxonomy', 'term_slug', 'country', 'label', 'address_line', 'address_line2', 'locality', 'region', 'postal_code', 'lat', 'lng' ) );
		fclose( $out );
		exit;
	}

	public static function handle_geocode(): void {
		check_admin_referer( 'atcf_geocode_now' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		$result = ATCF_Geocoder::retry_and_run();
		wp_safe_redirect( add_query_arg( array(
			'page'      => self::PAGE_SLUG,
			'atcf_msg'  => 'geocoded',
			'atcf_info' => $result['requeued'] . ':' . $result['processed'],
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		$id = absint( $_GET['location_id'] ?? 0 );
		check_admin_referer( 'atcf_gl_delete_location_' . $id );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		ATCF_Locations::delete( $id );
		wp_safe_redirect( add_query_arg( array(
			'page'     => self::PAGE_SLUG,
			'atcf_msg' => 'deleted',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_bulk_delete(): void {
		check_admin_referer( 'atcf_gl_bulk_delete' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'as-the-crow-flies' ) );
		}
		$action      = sanitize_key( $_POST['bulk_action'] ?? '-1' );
		$location_ids = array_map( 'absint', (array) ( $_POST['location_ids'] ?? array() ) );
		$location_ids = array_values( array_filter(
			$location_ids,
			static function( int $id ): bool {
				return null !== ATCF_Locations::get( $id );
			}
		) );
		if ( 'delete' === $action ) {
			foreach ( $location_ids as $id ) {
				ATCF_Locations::delete( $id );
			}
		} elseif ( 'geocode' === $action && ! empty( $location_ids ) ) {
			ATCF_Geocoder::run_for_ids( $location_ids );
		} elseif ( 'clear_coords' === $action && ! empty( $location_ids ) ) {
			ATCF_Locations::bulk_clear_coords( $location_ids );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Return the parent menu slug (htax-terms if HT is active, atcf-terms otherwise).
	 * Safe to call after admin_menu has fired.
	 */
	public static function get_parent_slug(): string {
		return ATCF_HT_Integration::terms_menu_slug() ?? 'atcf-terms';
	}

	private static function delete_location_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg( array(
				'action'      => 'atcf_gl_delete_location',
				'location_id' => $id,
			), admin_url( 'admin-post.php' ) ),
			'atcf_gl_delete_location_' . $id
		);
	}

	private static function render_message( string $msg, string $info ): void {
		if ( '' === $msg ) return;
		$geo_count = (int) ( explode( ':', $info )[1] ?? 0 );
		$import_parts = explode( ':', $info );
		$imported_text = sprintf(
			/* translators: 1: inserted count, 2: updated count, 3: skipped (conflict) count */
			__( 'Import complete: %1$d inserted, %2$d updated, %3$d skipped.', 'as-the-crow-flies' ),
			(int) ( $import_parts[0] ?? 0 ),
			(int) ( $import_parts[1] ?? 0 ),
			(int) ( $import_parts[2] ?? 0 )
		);
		$unknown_skipped = (int) ( $import_parts[4] ?? 0 );
		if ( $unknown_skipped > 0 ) {
			$imported_text .= ' ' . sprintf(
				/* translators: %d: number of rows skipped for an unrecognized taxonomy/term */
				_n( '%d row skipped (unknown term).', '%d rows skipped (unknown term).', $unknown_skipped, 'as-the-crow-flies' ),
				$unknown_skipped
			);
		}
		$map = array(
			'imported' => $imported_text,
			/* translators: %s: number of locations processed */
			'geocoded' => sprintf( _n( 'Geocoding complete: %s location processed.', 'Geocoding complete: %s locations processed.', $geo_count, 'as-the-crow-flies' ), number_format_i18n( $geo_count ) ),
			'deleted'  => __( 'Location deleted.', 'as-the-crow-flies' ),
		);
		if ( 'error' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $info ) . '</p></div>';
		} elseif ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $map[ $msg ] ) . '</p></div>';
		}
	}
}
