<?php
/**
 * HyperTaxonomy integration — term merge lifecycle.
 *
 * When HyperTaxonomy merges several source terms into a target term, the source
 * terms are deleted. ATCF's deleted_term handler removes their location rows as
 * part of that deletion. This class preserves locations that are distinct from
 * the target's existing locations: it snapshots each source term's locations
 * before the merge transaction (htax_before_merge_terms) and re-creates the
 * distinct ones on the target after the merge commits (htax_after_merge_terms).
 *
 * The hooks are no-ops when HyperTaxonomy is not active — the htax_* merge
 * actions are fired only by HyperTaxonomy, so registration is always safe.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_HT_Integration {

	/**
	 * Location rows snapshotted from source terms before an HT merge, keyed by
	 * source term ID.
	 *
	 * Populated in snapshot_before_merge() (fires before the merge transaction).
	 * ATCF deletes source term locations when deleted_term fires for each source
	 * during the transaction; the snapshot lets sync_after_merge() re-create the
	 * distinct ones on the target after commit.
	 *
	 * @var array<int, object[]>
	 */
	private static array $location_snapshots = array();

	public static function register(): void {
		add_action( 'htax_before_merge_terms', array( __CLASS__, 'snapshot_before_merge' ), 10, 4 );
		add_action( 'htax_after_merge_terms',  array( __CLASS__, 'sync_after_merge' ),       10, 4 );
	}

	/**
	 * Snapshot ATCF location rows for each source term before the merge transaction.
	 *
	 * htax_before_merge_terms fires before HT's START TRANSACTION. ATCF deletes
	 * source term locations when deleted_term fires for each source during the
	 * transaction, so the rows must be captured here.
	 *
	 * @since 1.0.0
	 * @param int[]  $source_ids      Source term IDs being merged.
	 * @param int    $target_id       Target term ID (unused here).
	 * @param string $taxonomy        Taxonomy slug (unused here).
	 * @param bool   $create_redirect Whether redirect stubs will be created (unused here).
	 */
	public static function snapshot_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect ): void {
		foreach ( $source_ids as $source_id ) {
			self::$location_snapshots[ (int) $source_id ] = ATCF_Locations::get_by_term_id( (int) $source_id );
		}
	}

	/**
	 * Copy snapshotted ATCF locations to the merge target after HT commits.
	 *
	 * htax_after_merge_terms fires after HT's COMMIT. Source term locations have
	 * already been deleted by ATCF's deleted_term handler (as part of HT's own merge
	 * transaction, which this function cannot retroactively affect). For each
	 * snapshotted location, if the target already has a matching location (per ATCF
	 * Location Matching settings) it is skipped; otherwise a new row is inserted for
	 * the target. Rows inserted during iteration join the running $target_locs pool so
	 * multiple source terms deduplicate against each other.
	 *
	 * Insertion is applied atomically at the application level: if any row fails to
	 * insert, every row this call already inserted is deleted again (via
	 * ATCF_Locations::delete(), so caches and change hooks stay consistent) before
	 * returning, rather than leaving the target with an arbitrary subset of the
	 * snapshotted locations. A DB-level transaction was tried first but proved
	 * unreliable here: other plugins' callbacks on the atcf_location_changed /
	 * atcf_term_location_changed hooks fired mid-loop by ATCF_Locations::insert()
	 * can run their own queries that break an open transaction out from under this
	 * function, so correctness cannot depend on no other hooked code doing that.
	 *
	 * @since 1.0.0
	 * @param int[]  $source_ids Source term IDs that were merged.
	 * @param int    $target_id  Target term ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param array  $result     HT merge result data (unused here).
	 */
	public static function sync_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result ): void {
		$target_locs = ATCF_Locations::get_by_term_id( $target_id );
		$inserted_ids = array();
		$failed       = false;

		foreach ( $source_ids as $source_id ) {
			$source_id   = (int) $source_id;
			$source_locs = self::$location_snapshots[ $source_id ] ?? array();

			foreach ( $source_locs as $loc ) {
				if ( self::location_matches_any( $loc, $target_locs ) ) {
					continue; // Target already has this location; source already deleted.
				}
				$data             = (array) $loc;
				unset( $data['id'] );          // Let insert() generate a new ID.
				$data['term_id']  = $target_id;
				$data['taxonomy'] = $taxonomy;
				$new_id           = ATCF_Locations::insert( $data );
				if ( is_wp_error( $new_id ) ) {
					$failed = true;
					break 2;
				}
				$inserted_ids[]  = $new_id;
				$added           = clone $loc;
				$added->term_id  = $target_id;
				$added->taxonomy = $taxonomy;
				$target_locs[]   = $added;
			}
		}

		if ( $failed ) {
			foreach ( $inserted_ids as $id ) {
				ATCF_Locations::delete( $id );
			}
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[ATCF HT merge] Location sync failed and was rolled back for target term ' . $target_id );
			}
			return;
		}

		foreach ( $source_ids as $source_id ) {
			unset( self::$location_snapshots[ (int) $source_id ] );
		}
	}

	/**
	 * Return true if $candidate matches any location in $pool per ATCF settings.
	 *
	 * @param object   $candidate Location row to test.
	 * @param object[] $pool      Existing location rows to compare against.
	 * @return bool
	 */
	private static function location_matches_any( object $candidate, array $pool ): bool {
		foreach ( $pool as $existing ) {
			if ( ATCF_Locations::locations_match( $candidate, $existing ) ) {
				return true;
			}
		}
		return false;
	}

	// --------------------------------------------------------------------------
	// Term group expansion (shortcodes + blocks)
	// --------------------------------------------------------------------------

	/**
	 * Merge a shortcode `group` attribute's expanded members into a `term` string.
	 *
	 * Both values are comma-separated strings as they arrive from shortcode_atts().
	 * Returns the combined term list as a comma-separated string. When the group
	 * attribute is empty or HyperTaxonomy is inactive (no members resolved), the
	 * original term string is returned unchanged.
	 *
	 * @since 1.0.0
	 * @param string $group_attr Comma-separated group term IDs.
	 * @param string $term_attr  Comma-separated term IDs already specified.
	 * @param string $taxonomy   Taxonomy slug to expand within ('' = all enabled).
	 * @return string Comma-separated term IDs.
	 */
	public static function merge_group_terms_into_string( string $group_attr, string $term_attr, string $taxonomy ): string {
		if ( '' === trim( $group_attr ) ) {
			return $term_attr;
		}
		$group_ids  = array_values( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $group_attr ) ) ) ) );
		$member_ids = atcf_expand_htax_groups( $group_ids, $taxonomy );
		if ( empty( $member_ids ) ) {
			return $term_attr;
		}
		$existing = '' !== trim( $term_attr )
			? array_map( 'trim', explode( ',', trim( $term_attr ) ) )
			: array();
		return implode( ',', array_merge( $existing, array_map( 'strval', $member_ids ) ) );
	}

	/**
	 * Merge expanded HyperTaxonomy group members into a list of term IDs.
	 *
	 * For block render callbacks, where terms and groups arrive as integer arrays.
	 * Returns $term_ids unchanged when $group_ids is empty.
	 *
	 * @since 1.0.0
	 * @param int[]  $term_ids  Term IDs already selected.
	 * @param int[]  $group_ids Group term IDs to expand.
	 * @param string $taxonomy  Taxonomy slug to expand within ('' = all enabled).
	 * @return int[] Combined, de-duplicated term IDs.
	 */
	public static function merge_group_term_ids( array $term_ids, array $group_ids, string $taxonomy ): array {
		if ( empty( $group_ids ) ) {
			return $term_ids;
		}
		return array_values( array_unique( array_merge( $term_ids, atcf_expand_htax_groups( $group_ids, $taxonomy ) ) ) );
	}

	// --------------------------------------------------------------------------
	// Admin: shared Terms menu + term-table column placement
	// --------------------------------------------------------------------------

	/**
	 * HyperTaxonomy's shared "Terms" top-level admin menu slug.
	 */
	const TERMS_MENU_SLUG = 'htax-terms';

	/**
	 * Return the shared "Terms" top-level menu slug if one is already registered by a
	 * suite plugin (HyperTaxonomy's htax-terms or TermConnect's tc-terms), else null.
	 *
	 * ATCF attaches its Term Locations page under the shared Terms menu when one
	 * exists, and falls back to its own top-level menu otherwise. Checking both known
	 * slugs makes consolidation independent of plugin load order. Must be called after
	 * the admin_menu hook has run (so the $menu global is populated).
	 *
	 * @since 1.0.0
	 * @return string|null The existing shared menu slug, or null when none is present.
	 */
	public static function terms_menu_slug(): ?string {
		global $menu;
		$known = array( self::TERMS_MENU_SLUG, 'tc-terms' );
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], $known, true ) ) {
				return $item[2];
			}
		}
		return null;
	}

	/**
	 * Insert a term-table column after the columns added by TermConnect or HyperTaxonomy.
	 *
	 * When HyperTaxonomy is active, the column is placed after TermConnect's external
	 * links column (when present) or HT's visibility column — keeping the feature order
	 * consistent with Screen Options. When HT is inactive, the column is appended.
	 *
	 * @since 1.0.0
	 * @param string[] $columns Existing columns.
	 * @param string   $key     Column key to insert.
	 * @param string   $label   Column label.
	 * @return string[]
	 */
	public static function position_term_column( array $columns, string $key, string $label ): array {
		if ( function_exists( 'htax_insert_column_after' ) ) {
			$after = array_key_exists( 'tc_ext_links', $columns ) ? 'tc_ext_links' : 'htax_hidden';
			return htax_insert_column_after( $columns, $after, $key, $label );
		}
		$columns[ $key ] = $label;
		return $columns;
	}
}

/**
 * Expand HyperTaxonomy group IDs to member term IDs.
 *
 * Returns an empty array when HyperTaxonomy is not active.
 *
 * @since 1.0.0
 * @param int[]  $group_ids Group term IDs.
 * @param string $taxonomy  Taxonomy to expand within. Pass '' to expand across all ATCF-enabled taxonomies.
 * @return int[] Member term IDs.
 */
function atcf_expand_htax_groups( array $group_ids, string $taxonomy = '' ): array {
	if ( empty( $group_ids ) || ! function_exists( 'htax_get_terms_in_groups' ) ) {
		return array();
	}
	if ( '' !== $taxonomy ) {
		return array_keys( htax_get_terms_in_groups( $group_ids, $taxonomy ) );
	}
	$term_ids = array();
	foreach ( (array) get_option( 'atcf_enabled_taxonomies', array() ) as $tax ) {
		if ( 'group' === $tax ) {
			continue;
		}
		$term_ids = array_merge( $term_ids, array_keys( htax_get_terms_in_groups( $group_ids, $tax ) ) );
	}
	return array_values( array_unique( $term_ids ) );
}
