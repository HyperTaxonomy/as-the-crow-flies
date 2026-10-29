<?php
/**
 * Admin integration: term edit panel and term list column.
 *
 * Hooks at priority 99 so ATCF's additions appear last, after any other
 * plugin's additions, without requiring awareness of other plugins.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Admin {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'atcf_location_changed', static function ( string $taxonomy ): void {
			if ( $taxonomy ) {
				delete_transient( 'atcf_countries_' . md5( $taxonomy ) );
				wp_cache_delete( 'term_ids_with_locations_' . $taxonomy, 'atcf' );
			}
		} );

		// Term edit panel (Edit Term only, not Add Term).
		add_action( 'registered_taxonomy', array( __CLASS__, 'hook_term_edit' ), 10, 1 );

		// Term list column, filter, and sortable — hooked per-taxonomy after registration.
		add_action( 'registered_taxonomy', array( __CLASS__, 'hook_term_list' ), 10, 1 );

		// Single terms_clauses hook handles all taxonomies for location-count sorting.
		add_filter( 'terms_clauses', array( __CLASS__, 'sort_terms_by_locations' ), 10, 3 );

		// AJAX handlers.
		add_action( 'wp_ajax_atcf_clear_term_locations',  array( __CLASS__, 'ajax_clear_term_locations' ) );
		add_action( 'wp_ajax_atcf_upload_term_csv',       array( __CLASS__, 'ajax_upload_term_csv' ) );
		add_action( 'wp_ajax_atcf_geocode_location',      array( __CLASS__, 'ajax_geocode_location' ) );
		add_action( 'wp_ajax_atcf_geocode_term',          array( __CLASS__, 'ajax_geocode_term' ) );
		add_action( 'wp_ajax_atcf_save_location_inline',  array( __CLASS__, 'ajax_save_location_inline' ) );
		add_action( 'wp_ajax_atcf_clear_location_coords', array( __CLASS__, 'ajax_clear_location_coords' ) );
		add_action( 'wp_ajax_atcf_clear_term_coords',     array( __CLASS__, 'ajax_clear_term_coords' ) );
		add_action( 'wp_ajax_atcf_gl_clear_all_coords',  array( __CLASS__, 'ajax_gl_clear_all_coords' ) );
		add_action( 'wp_ajax_atcf_gl_delete_all',        array( __CLASS__, 'ajax_gl_delete_all' ) );
	}

	// --------------------------------------------------------------------------
	// Assets
	// --------------------------------------------------------------------------

	public static function enqueue_assets( string $hook ): void {
		$screens = array( 'edit-tags.php', 'term.php', 'admin_page_atcf-locations', 'settings_page_as-the-crow-flies' );
		if ( ! in_array( $hook, $screens, true ) && false === strpos( $hook, '_page_atcf-locations' ) ) {
			return;
		}

		wp_enqueue_style(
			'atcf-admin',
			ATCF_PLUGIN_URL . 'assets/admin.css',
			array(),
			ATCF_VERSION
		);

		wp_enqueue_script(
			'atcf-admin',
			ATCF_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			ATCF_VERSION,
			true
		);

		$screen = get_current_screen();

		// Build location filter config — includes country breakdown if locations span
		// multiple countries. Only computed on a TC-enabled taxonomy term list screen.
		$location_filter_config = null;
		if ( $screen && 'edit-tags' === $screen->base && self::is_taxonomy_enabled( $screen->taxonomy ) ) {
			$atcf_taxonomy = $screen->taxonomy;
			$countries     = self::get_distinct_countries( $atcf_taxonomy );
			$location_filter_config = array(
				'current'   => sanitize_text_field( wp_unslash( $_GET['atcf_has_locations'] ?? '' ) ),
				'all'       => __( 'All Locations', 'as-the-crow-flies' ),
				'yes'       => __( 'Has Locations', 'as-the-crow-flies' ),
				'no'        => __( 'No Locations', 'as-the-crow-flies' ),
				'countries' => count( $countries ) > 1 ? array_values( $countries ) : array(),
				'byCountry' => __( 'By Country', 'as-the-crow-flies' ),
			);
		}

		wp_localize_script( 'atcf-admin', 'atcfAdmin', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'atcf_admin' ),
			'confirmClear'        => __( 'Delete all locations for this term? This cannot be undone.', 'as-the-crow-flies' ),
			'confirmClearCoords'  => __( 'Clear all coordinates for this term? Locations will be re-queued for geocoding.', 'as-the-crow-flies' ),
			'confirmDeleteAll'       => __( 'Delete all locations for this term? This cannot be undone.', 'as-the-crow-flies' ),
			'confirmGlClearCoords'   => __( 'Clear all coordinates? Locations will be re-queued for geocoding.', 'as-the-crow-flies' ),
			'confirmGlDeleteAll'     => __( 'Delete all locations? This cannot be undone.', 'as-the-crow-flies' ),
			'i18n'           => array(
				'saving'      => __( 'Saving…', 'as-the-crow-flies' ),
				'deleting'      => __( 'Deleting…', 'as-the-crow-flies' ),
				'clearingCoords' => __( 'Clearing…', 'as-the-crow-flies' ),
				'geocode'     => __( 'Geocode', 'as-the-crow-flies' ),
				'clearCoords' => __( 'Clear', 'as-the-crow-flies' ),
				'error'       => __( 'An error occurred. Please try again.', 'as-the-crow-flies' ),
			),
			'geocodeLabels'  => array(
				'geocoding'         => __( 'Geocoding…', 'as-the-crow-flies' ),
				'user_provided'     => __( 'User-provided', 'as-the-crow-flies' ),
				'pending'           => __( 'Pending', 'as-the-crow-flies' ),
				'matched'           => __( 'Census', 'as-the-crow-flies' ),
				'tie'               => __( 'Census (tie)', 'as-the-crow-flies' ),
				'matched_nominatim' => __( 'OpenStreetMap', 'as-the-crow-flies' ),
				'no_match'          => __( 'No match', 'as-the-crow-flies' ),
				'failed'            => __( 'Failed', 'as-the-crow-flies' ),
			),
			'locationFilter'      => $location_filter_config,
			'hiddenColumns'       => array_values( get_hidden_columns( get_current_screen() ) ),
			'locationMatchRadius' => (float) get_option( 'atcf_location_match_radius', 82.0 ),
			'distanceUnits'       => (string) get_option( 'atcf_distance_units', 'miles' ),
			'distanceSubUnit'     => 'km' === get_option( 'atcf_distance_units', 'miles' )
				? __( 'meters', 'as-the-crow-flies' )
				: __( 'feet', 'as-the-crow-flies' ),
		) );
	}

	// --------------------------------------------------------------------------
	// Term edit panel
	// --------------------------------------------------------------------------

	/**
	 * Hook the term edit section for a taxonomy when it is registered.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function hook_term_edit( string $taxonomy ): void {
		add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'render_term_edit_panel' ), 99, 1 );
	}

	/**
	 * Render the ATCF section on the Edit Term screen.
	 *
	 * @param WP_Term $term Current term.
	 */
	public static function render_term_edit_panel( WP_Term $term ): void {
		if ( ! self::is_taxonomy_enabled( $term->taxonomy ) ) {
			return;
		}

		$count    = ATCF_Locations::count_for_term( $term->term_id, $term->taxonomy );
		$page_url = admin_url( 'admin.php?page=atcf-locations&term_id=' . $term->term_id );

		?>
		<tr class="form-field atcf-term-locations-row">
			<th scope="row"><?php esc_html_e( 'Locations', 'as-the-crow-flies' ); ?></th>
			<td>
				<a href="<?php echo esc_url( $page_url ); ?>">
					<?php
					if ( $count > 0 ) {
						/* translators: %d: number of locations */
						echo esc_html( sprintf( _n( 'Manage %d location →', 'Manage %d locations →', $count, 'as-the-crow-flies' ), $count ) );
					} else {
						esc_html_e( 'Add locations →', 'as-the-crow-flies' );
					}
					?>
				</a>
			</td>
		</tr>
		<?php
	}

	// --------------------------------------------------------------------------
	// Term list column + filter
	// --------------------------------------------------------------------------

	/**
	 * Hook the term list column, sortable registration, and filter for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function hook_term_list( string $taxonomy ): void {
		add_filter( "manage_edit-{$taxonomy}_columns",          array( __CLASS__, 'add_locations_column' ), 99 );
		add_filter( "manage_{$taxonomy}_custom_column",         array( __CLASS__, 'render_locations_column' ), 99, 3 );
		add_filter( "manage_edit-{$taxonomy}_sortable_columns", array( __CLASS__, 'make_locations_column_sortable' ), 99 );
		add_action( "restrict_manage_{$taxonomy}",              array( __CLASS__, 'render_locations_filter' ), 99 );

		// Hide Locations column by default.
		add_filter( 'default_hidden_columns', static function ( $hidden, $screen ) use ( $taxonomy ) {
			if ( $screen && "edit-{$taxonomy}" === $screen->id ) {
				$hidden[] = 'atcf_locations';
			}
			return $hidden;
		}, 10, 2 );
		if ( ! has_action( 'parse_term_query', array( __CLASS__, 'apply_locations_filter' ) ) ) {
			add_action( 'parse_term_query', array( __CLASS__, 'apply_locations_filter' ) );
		}
	}

	/**
	 * @param string[] $columns Existing columns.
	 * @return string[]
	 */
	public static function add_locations_column( array $columns ): array {
		// Only for enabled taxonomies.
		$screen = get_current_screen();
		if ( ! $screen || ! self::is_taxonomy_enabled( str_replace( 'edit-', '', $screen->id ) ) ) {
			return $columns;
		}

		return ATCF_HT_Integration::position_term_column( $columns, 'atcf_locations', __( 'Locations', 'as-the-crow-flies' ) );
	}

	/**
	 * Register the Locations column as sortable.
	 *
	 * @param string[] $sortable Existing sortable columns.
	 * @return string[]
	 */
	public static function make_locations_column_sortable( array $sortable ): array {
		$sortable['atcf_locations'] = 'atcf_locations';
		return $sortable;
	}

	/**
	 * Inject an ORDER BY subquery when the Locations column is sorted.
	 *
	 * WordPress's WP_Term_Query does not natively support unknown orderby values,
	 * so we check the raw query parameter and override the clause directly.
	 *
	 * @param array    $clauses    SQL clause parts (fields, join, where, orderby, ...).
	 * @param string[] $taxonomies Taxonomies being queried.
	 * @param array    $args       Parsed query args.
	 * @return array
	 */
	public static function sort_terms_by_locations( array $clauses, array $taxonomies, array $args ): array {
		if ( ! is_admin() ) {
			return $clauses;
		}

		if ( ( $args['orderby'] ?? '' ) !== 'atcf_locations' ) {
			return $clauses;
		}

		$order = strtoupper( $args['order'] ?? 'ASC' );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		global $wpdb;
		$taxonomy = ! empty( $taxonomies[0] ) ? $taxonomies[0] : '';

		$clauses['orderby'] = $wpdb->prepare(

			"ORDER BY (
				SELECT COUNT(*)
				FROM {$wpdb->prefix}atcf_locations al
				WHERE al.term_id = t.term_id
				AND al.taxonomy = %s
			) {$order}",
			$taxonomy
		);
		$clauses['order'] = ''; // Direction already embedded above; prevent WordPress appending it again.

		return $clauses;
	}

	/**
	 * Render the Locations column cell for a term.
	 *
	 * @param string $output   Current output.
	 * @param string $column   Column name.
	 * @param int    $term_id  Term ID.
	 * @return string
	 */
	public static function render_locations_column( string $output, string $column, int $term_id ): string {
		if ( 'atcf_locations' !== $column ) {
			return $output;
		}

		$screen   = get_current_screen();
		$taxonomy = $screen ? str_replace( 'edit-', '', $screen->id ) : '';

		if ( ! self::is_taxonomy_enabled( $taxonomy ) ) {
			return '—';
		}

		$count    = ATCF_Locations::count_for_term( $term_id, $taxonomy );
		$page_url = admin_url( 'admin.php?page=atcf-locations&term_id=' . $term_id );

		if ( $count > 0 ) {
			return '<a href="' . esc_url( $page_url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
		}

		return '<a href="' . esc_url( $page_url ) . '" class="atcf-add-link">+</a>';
	}

	/**
	 * Render the locations filter dropdown above the term list.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function render_locations_filter( string $taxonomy ): void {
		if ( ! self::is_taxonomy_enabled( $taxonomy ) ) {
			return;
		}

		// Only show the filter when the Locations column is visible (i.e. not
		// unchecked in Screen Options). get_hidden_columns() returns columns the
		// user has hidden; if 'atcf_locations' is in that list, skip the filter.
		$screen = get_current_screen();
		if ( $screen ) {
			$hidden = get_hidden_columns( $screen );
			if ( in_array( 'atcf_locations', $hidden, true ) ) {
				return;
			}
		}

		$current   = sanitize_text_field( wp_unslash( $_GET['atcf_has_locations'] ?? '' ) );
		$countries = self::get_distinct_countries( $taxonomy );
		?>
		<select name="atcf_has_locations">
			<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'All Locations', 'as-the-crow-flies' ); ?></option>
			<option value="yes" <?php selected( $current, 'yes' ); ?>><?php esc_html_e( 'Has Locations', 'as-the-crow-flies' ); ?></option>
			<option value="no" <?php selected( $current, 'no' ); ?>><?php esc_html_e( 'No Locations', 'as-the-crow-flies' ); ?></option>
			<?php if ( count( $countries ) > 1 ) : ?>
				<optgroup label="<?php esc_attr_e( 'By Country', 'as-the-crow-flies' ); ?>">
					<?php foreach ( $countries as $code ) : ?>
						<option value="country:<?php echo esc_attr( $code ); ?>" <?php selected( $current, 'country:' . $code ); ?>>
							<?php echo esc_html( $code ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
		</select>
		<?php
	}

	/**
	 * Filter terms by location presence when the dropdown filter is applied.
	 *
	 * @param WP_Term_Query $query Term query object.
	 */
	public static function apply_locations_filter( WP_Term_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}

		$filter = sanitize_text_field( wp_unslash( $_GET['atcf_has_locations'] ?? '' ) );
		if ( '' === $filter ) {
			return;
		}

		$taxonomy_var = $query->query_vars['taxonomy'] ?? '';
		$taxonomy     = is_array( $taxonomy_var ) ? ( $taxonomy_var[0] ?? '' ) : (string) $taxonomy_var;

		global $wpdb;

		if ( 'yes' === $filter ) {
			$ids = $taxonomy
				? array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s",
					$taxonomy
				) ) )
				: array_map( 'intval', $wpdb->get_col(
					"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations"
				) );
			$query->query_vars['include'] = $ids ?: array( 0 );
		} elseif ( 'no' === $filter ) {
			$ids = $taxonomy
				? array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s",
					$taxonomy
				) ) )
				: array_map( 'intval', $wpdb->get_col(
					"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations"
				) );
			$query->query_vars['exclude'] = $ids ?: array( 0 );
		} elseif ( 0 === strpos( $filter, 'country:' ) ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', substr( $filter, 8 ) ) );
			if ( 2 === strlen( $code ) ) {
				$ids = $taxonomy
					? array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
						"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE country = %s AND taxonomy = %s",
						$code,
						$taxonomy
					) ) )
					: array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
						"SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE country = %s",
						$code
					) ) );
				$query->query_vars['include'] = $ids ?: array( 0 );
			}
		}
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Return distinct country codes that have locations for a given taxonomy.
	 * Cached with TTL=0 (explicit invalidation on location changes).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[] Sorted array of ISO 3166-1 alpha-2 country codes.
	 */
	private static function get_distinct_countries( string $taxonomy ): array {
		$cache_key = 'atcf_countries_' . md5( $taxonomy );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (array) $cached;
		}
		global $wpdb;
		$countries = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT country FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s ORDER BY country",
				$taxonomy
			)
		);
		$countries = is_array( $countries ) ? $countries : array();
		set_transient( $cache_key, $countries, 0 );
		return $countries;
	}

	// --------------------------------------------------------------------------
	// AJAX handlers
	// --------------------------------------------------------------------------

	/**
	 * AJAX: clear all locations for a term.
	 */
	public static function ajax_clear_term_locations(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}

		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );

		if ( ! $term_id || ! $taxonomy ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'as-the-crow-flies' ) ), 400 );
		}

		$deleted = ATCF_Locations::delete_for_term( $term_id, $taxonomy );
		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * AJAX: upload a term-scoped CSV.
	 */
	public static function ajax_upload_term_csv(): void {
		$term_id = absint( $_POST['term_id'] ?? 0 );
		check_ajax_referer( 'atcf_upload_term_' . $term_id, 'atcf_nonce' );

		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ) );
		}

		$taxonomy    = sanitize_key( $_POST['taxonomy'] ?? '' );
		$upload_mode = sanitize_key( $_POST['upload_mode'] ?? 'update' );
		$conflicts   = array(
			'address'       => ! empty( $_POST['conflict_address'] ),
			'label'         => ! empty( $_POST['conflict_label'] ),
			'coords'        => ! empty( $_POST['conflict_coords'] ),
			'coords_mode'   => sanitize_key( $_POST['conflict_coords_mode'] ?? 'exact' ),
			'coords_radius' => (float) ( $_POST['conflict_coords_radius'] ?? 0 ),
		);

		if ( ! $term_id || ! $taxonomy || empty( $_FILES['atcf_csv'] ) || UPLOAD_ERR_OK !== (int) $_FILES['atcf_csv']['error'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'as-the-crow-flies' ) ) );
		}
		if ( ! isset( $_FILES['atcf_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['atcf_csv']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file upload.', 'as-the-crow-flies' ) ) );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Term not found.', 'as-the-crow-flies' ) ), 400 );
		}

		$result = ATCF_CSV::import_term_scoped(
			$_FILES['atcf_csv']['tmp_name'],
			$term_id,
			$taxonomy,
			$upload_mode,
			$conflicts
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['non_us_without_coords'] = ATCF_Locations::count_non_us_without_coords();
		wp_send_json_success( $result );
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Check whether ATCF is enabled for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function is_taxonomy_enabled( string $taxonomy ): bool {
		if ( '' === $taxonomy ) {
			return false;
		}

		$enabled = get_option( 'atcf_enabled_taxonomies', array() );

		/**
		 * Filters whether ATCF UI is enabled for a taxonomy.
		 *
		 * @since 1.0.0
		 * @param bool   $enabled  Whether the taxonomy is enabled.
		 * @param string $taxonomy Taxonomy slug.
		 */
		return (bool) apply_filters( 'atcf_taxonomy_enabled', in_array( $taxonomy, (array) $enabled, true ), $taxonomy );
	}

	// --------------------------------------------------------------------------
	// AJAX — geocode single location
	// --------------------------------------------------------------------------

	public static function ajax_geocode_location(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$id = absint( $_POST['location_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'as-the-crow-flies' ) ) );
		}
		ATCF_Geocoder::run_for_ids( array( $id ) );
		$loc = ATCF_Locations::get( $id );
		if ( ! $loc ) {
			wp_send_json_error( array( 'message' => __( 'Not found.', 'as-the-crow-flies' ) ) );
		}
		wp_send_json_success( array(
			'geocode_status' => $loc->geocode_status,
			'lat'            => $loc->lat,
			'lng'            => $loc->lng,
		) );
	}

	// --------------------------------------------------------------------------
	// AJAX — clear coordinates for one location
	// --------------------------------------------------------------------------

	public static function ajax_clear_location_coords(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$id = absint( $_POST['location_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'as-the-crow-flies' ) ) );
		}
		ATCF_Locations::bulk_clear_coords( array( $id ) );
		wp_send_json_success( array( 'geocode_status' => 'pending' ) );
	}

	// --------------------------------------------------------------------------
	// AJAX — global page: clear all coords (optionally filtered by taxonomy)
	// --------------------------------------------------------------------------

	public static function ajax_gl_clear_all_coords(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		global $wpdb;
		// Collect affected terms before the change so per-term listeners (e.g. TC's
		// normalized-address URI index) can refresh.
		$affected = $taxonomy
			? $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s", $taxonomy ) )
			: $wpdb->get_col( "SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations" );
		if ( $taxonomy ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}atcf_locations
				 SET lat = NULL, lng = NULL,
				     geocode_status = CASE WHEN country = 'US' THEN 'pending' ELSE NULL END
				 WHERE taxonomy = %s",
				$taxonomy
			) );
		} else {
			$wpdb->query(
				"UPDATE {$wpdb->prefix}atcf_locations
				 SET lat = NULL, lng = NULL,
				     geocode_status = CASE WHEN country = 'US' THEN 'pending' ELSE NULL END"
			);
		}
		do_action( 'atcf_location_changed', $taxonomy );
		foreach ( $affected as $tid ) {
			do_action( 'atcf_term_location_changed', (int) $tid );
		}
		ATCF_Locations::flush_all_location_caches();
		wp_send_json_success();
	}

	// --------------------------------------------------------------------------
	// AJAX — global page: delete all locations (optionally filtered by taxonomy)
	// --------------------------------------------------------------------------

	public static function ajax_gl_delete_all(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		global $wpdb;
		// Collect affected terms before the delete so per-term listeners (e.g. TC's
		// normalized-address URI index) can refresh.
		$affected = $taxonomy
			? $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations WHERE taxonomy = %s", $taxonomy ) )
			: $wpdb->get_col( "SELECT DISTINCT term_id FROM {$wpdb->prefix}atcf_locations" );
		if ( $taxonomy ) {
			$deleted = $wpdb->delete( $wpdb->prefix . 'atcf_locations', array( 'taxonomy' => $taxonomy ), array( '%s' ) );
		} else {
			$deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}atcf_locations" );
		}
		do_action( 'atcf_location_changed', $taxonomy );
		foreach ( $affected as $tid ) {
			do_action( 'atcf_term_location_changed', (int) $tid );
		}
		ATCF_Locations::flush_all_location_caches();
		wp_send_json_success( array( 'deleted' => (int) $deleted ) );
	}

	// --------------------------------------------------------------------------
	// AJAX — clear coordinates for all locations of a term
	// --------------------------------------------------------------------------

	public static function ajax_clear_term_coords(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		if ( ! $term_id || ! $taxonomy ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'as-the-crow-flies' ) ), 400 );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}atcf_locations
			 SET lat = NULL, lng = NULL,
			     geocode_status = CASE WHEN country = 'US' THEN 'pending' ELSE NULL END
			 WHERE term_id = %d AND taxonomy = %s",
			$term_id,
			$taxonomy
		) );
		do_action( 'atcf_location_changed', $taxonomy );
		ATCF_Locations::flush_term_cache( $term_id );
		wp_send_json_success();
	}

	// --------------------------------------------------------------------------
	// AJAX — geocode all locations for a term
	// --------------------------------------------------------------------------

	public static function ajax_geocode_term(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		if ( ! $term_id || ! $taxonomy ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'as-the-crow-flies' ) ), 400 );
		}
		global $wpdb;
		// Geocode all US locations except those with user-provided coordinates
		// (geocode_status IS NULL with lat already set means the admin manually
		// entered coordinates and does not want them overwritten).
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atcf_locations
			 WHERE term_id = %d AND taxonomy = %s AND country = 'US'
			   AND NOT ( geocode_status IS NULL AND lat IS NOT NULL )",
			$term_id, $taxonomy
		) ) );
		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'processed' => 0 ) );
		}
		$processed = ATCF_Geocoder::run_for_ids( $ids );
		wp_send_json_success( array( 'processed' => $processed ) );
	}

	// --------------------------------------------------------------------------
	// AJAX — inline save a location
	// --------------------------------------------------------------------------

	public static function ajax_save_location_inline(): void {
		check_ajax_referer( 'atcf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'as-the-crow-flies' ) ), 403 );
		}
		$id       = absint( $_POST['location_id'] ?? 0 );
		$term_id  = absint( $_POST['term_id'] ?? 0 );
		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		if ( ! $id || ! $term_id || ! $taxonomy ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'as-the-crow-flies' ) ), 400 );
		}
		$existing = ATCF_Locations::get( $id );
		if ( ! $existing || (int) $existing->term_id !== $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Location not found or does not belong to this term.', 'as-the-crow-flies' ) ), 403 );
		}
		$raw_lat       = trim( wp_unslash( $_POST['lat'] ?? '' ) );
		$raw_lng       = trim( wp_unslash( $_POST['lng'] ?? '' ) );
		$submitted_lat = '' !== $raw_lat ? (float) $raw_lat : null;
		$submitted_lng = '' !== $raw_lng ? (float) $raw_lng : null;

		$data = array(
			'term_id'       => $term_id,
			'taxonomy'      => $taxonomy,
			'label'         => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'address_line'  => sanitize_text_field( wp_unslash( $_POST['address_line'] ?? '' ) ),
			'address_line2' => sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) ),
			'locality'      => sanitize_text_field( wp_unslash( $_POST['locality'] ?? '' ) ),
			'region'        => sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) ),
			'postal_code'   => sanitize_text_field( wp_unslash( $_POST['postal_code'] ?? '' ) ),
			'country'       => strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? 'US' ) ) ),
			'lat'           => $submitted_lat,
			'lng'           => $submitted_lng,
		);

		// If coordinates are unchanged, preserve the existing geocode_status rather than
		// resetting it to null (user-provided). Coords are considered unchanged when both
		// submitted values match the stored values within floating-point precision.
		$existing_lat = null !== $existing->lat ? (float) $existing->lat : null;
		$existing_lng = null !== $existing->lng ? (float) $existing->lng : null;
		if ( $submitted_lat === $existing_lat && $submitted_lng === $existing_lng ) {
			$data['geocode_status'] = $existing->geocode_status;
		}

		$result = ATCF_Locations::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$loc = ATCF_Locations::get( $id );
		wp_send_json_success( array(
			'label'          => $loc->label,
			'address_line'   => $loc->address_line,
			'address_line2'  => $loc->address_line2,
			'locality'       => $loc->locality,
			'region'         => $loc->region,
			'postal_code'    => $loc->postal_code,
			'country'        => $loc->country,
			'lat'            => $loc->lat,
			'lng'            => $loc->lng,
			'geocode_status' => $loc->geocode_status,
		) );
	}
}
