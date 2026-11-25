<?php
/**
 * HyperTaxonomy integration — term merges and conversions, and group expansion.
 *
 * Location rows belong to a term ID, and both operations replace terms: a merge deletes its
 * source terms, and a conversion creates a new term and either deletes the old one or keeps it
 * as a redirect stub. ATCF deletes a deleted term's locations, so they are snapshotted first
 * and put back where the operation's options say:
 *
 *  - stub: left on the redirect stub (the default when stubs are created).
 *  - move: moved to the target or new term (the default without stubs). Locations it already
 *          has (by ATCF's Location Matching settings) aren't added again.
 *  - copy: copied to the target or new term, and left on the stub.
 *  - drop: deleted.
 *
 * The Merge Terms and Convert Terms pages offer these in their check step (htax_merge_terms_check,
 * htax_convert_terms_check), under the 'as-the-crow-flies' section, with HyperTaxonomy's shared
 * choice (htax_carry_field(), htax_carry_choice()). Merges and conversions made without them,
 * including external converters such as Categories to Tags, get HyperTaxonomy's operation-wide
 * preference, if any, or the defaults. A conversion to a taxonomy that isn't enabled for
 * locations leaves them on the stub, or deletes them without one.
 *
 * The hooks are no-ops when HyperTaxonomy is not active — the htax_* actions and filters are
 * fired only by HyperTaxonomy, so registration is always safe.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_HT_Integration {

	/** Section ID of ATCF's choices in merge and conversion options. */
	const SECTION = 'as-the-crow-flies';

	/**
	 * Location rows snapshotted from source terms before an HT merge, keyed by
	 * source term ID.
	 *
	 * @var array<int, object[]>
	 */
	private static array $location_snapshots = array();

	/**
	 * Location rows of each term being converted, keyed by term ID, kept before the conversion
	 * for one that deletes the term (without a stub, or by another tool).
	 *
	 * @var array<int, object[]>
	 */
	private static array $snapshots = array();

	/**
	 * The operation in progress: { options: array (outside the pages, only what the caller
	 * passed), reverse: int[] (term IDs converted back to their stubs) }.
	 *
	 * @var array
	 */
	private static array $operation = array(
		'options' => array(),
		'reverse' => array(),
	);

	/**
	 * Location counts of the operation in progress, for its result message.
	 *
	 * @var array{added: int, on_stubs: int}
	 */
	private static array $counts = array(
		'added' => 0,
		'on_stubs' => 0,
	);

	/**
	 * Result message of the last operation, or ''.
	 *
	 * @var string
	 */
	private static string $message = '';

	/**
	 * Hook the HyperTaxonomy merge and conversion actions and check steps.
	 *
	 * @since 1.0.0
	 */
	public static function register(): void {
		add_filter( 'htax_merge_terms_check', array( __CLASS__, 'merge_check' ), 10, 5 );
		add_action( 'htax_before_merge_terms', array( __CLASS__, 'snapshot_before_merge' ), 10, 5 );
		add_action( 'htax_after_merge_terms', array( __CLASS__, 'sync_after_merge' ), 10, 4 );
		add_filter( 'htax_merge_terms_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'htax_convert_terms_check', array( __CLASS__, 'convert_check' ), 10, 5 );
		add_action( 'htax_before_convert_terms', array( __CLASS__, 'before_convert' ), 10, 5 );
		add_action( 'htax_before_convert_term', array( __CLASS__, 'before_convert_term' ) );
		add_action( 'htax_after_convert_term', array( __CLASS__, 'after_convert_term' ), 10, 4 );
		add_action( 'htax_after_convert_terms', array( __CLASS__, 'after_convert' ) );
		add_filter( 'htax_convert_terms_messages', array( __CLASS__, 'messages' ) );
	}

	// --------------------------------------------------------------------------
	// Check step
	// --------------------------------------------------------------------------

	/**
	 * The check step's choice of where locations go (htax_carry_field()).
	 *
	 * @param int    $count   Number of locations.
	 * @param string $to_name Merge target's name, or '' for a conversion's new terms.
	 * @param bool   $movable Whether the target or new terms can take them.
	 * @return array Field.
	 */
	private static function locations_field( int $count, string $to_name, bool $movable ): array {
		$dedupe = '' !== $to_name
			/* translators: %s: target term name */
			? sprintf( __( 'Locations that %s already has aren\'t added again.', 'as-the-crow-flies' ), $to_name )
			: '';
		return htax_carry_field( array(
			'name'         => 'locations',
			/* translators: %d: number of locations */
			'label'        => sprintf( _n( '%d location', '%d locations', $count, 'as-the-crow-flies' ), $count ),
			'target_name'  => $to_name,
			'movable'      => $movable,
			'descriptions' => array(
				'stub' => __( 'Each stub keeps the locations of the term it replaces.', 'as-the-crow-flies' ),
				'move' => $dedupe,
				'copy' => $dedupe,
			),
		) );
	}

	/**
	 * A check step section, or $sections unchanged when there are no locations.
	 *
	 * @param array      $sections Sections so far.
	 * @param int        $count    Number of locations.
	 * @param array      $field    locations_field() result.
	 * @param array|null $notice   Notice, or null.
	 * @return array
	 */
	private static function add_section( array $sections, int $count, array $field, ?array $notice = null ): array {
		if ( $count ) {
			$sections[] = array(
				'id'      => self::SECTION,
				'title'   => __( 'As the Crow Flies', 'as-the-crow-flies' ),
				'notices' => $notice ? array( $notice ) : array(),
				'fields'  => array( $field ),
			);
		}
		return $sections;
	}

	/**
	 * Filter htax_merge_terms_check: where the merged terms' locations go.
	 *
	 * @since 1.0.0
	 * @param array  $sections        Sections so far.
	 * @param int[]  $source_ids      Source term IDs.
	 * @param int    $target_id       Target term ID.
	 * @param string $taxonomy        Taxonomy.
	 * @param bool   $create_redirect Whether redirect stubs are selected (unused here).
	 * @return array
	 */
	public static function merge_check( $sections, $source_ids, $target_id, $taxonomy, $create_redirect ) {
		$target = get_term( (int) $target_id, (string) $taxonomy );
		if ( ! $target instanceof WP_Term ) {
			return $sections;
		}
		$count = 0;
		foreach ( (array) $source_ids as $source_id ) {
			$count += count( ATCF_Locations::get_by_term_id( (int) $source_id ) );
		}
		return self::add_section( (array) $sections, $count, self::locations_field( $count, $target->name, true ) );
	}

	/**
	 * Filter htax_convert_terms_check: where the converted terms' locations go.
	 *
	 * @since 1.0.0
	 * @param array  $sections        Sections so far.
	 * @param int[]  $term_ids        Terms to convert.
	 * @param string $from_taxonomy   Their taxonomy.
	 * @param string $to_taxonomy     Taxonomy to convert them to.
	 * @param bool   $create_redirect Whether redirect stubs are selected (unused here).
	 * @return array
	 */
	public static function convert_check( $sections, $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect ) {
		$count = 0;
		foreach ( (array) $term_ids as $term_id ) {
			if ( ! self::is_reverse( (int) $term_id, (string) $to_taxonomy ) ) {
				$count += count( ATCF_Locations::get_by_term_id( (int) $term_id ) );
			}
		}
		$movable = ATCF_Options::is_taxonomy_enabled( (string) $to_taxonomy );
		$notice  = null;
		if ( ! $movable ) {
			$object = get_taxonomy( (string) $to_taxonomy );
			$notice = array(
				'type' => 'info',
				/* translators: %s: taxonomy name */
				'text' => sprintf( __( 'Locations aren\'t enabled for %s, so the new terms can\'t take them.', 'as-the-crow-flies' ), $object ? $object->labels->name : $to_taxonomy ),
			);
		}
		return self::add_section( (array) $sections, $count, self::locations_field( $count, '', $movable ), $notice );
	}

	/**
	 * Filter htax_merge_terms_messages and htax_convert_terms_messages: where the locations
	 * went.
	 *
	 * @since 1.0.0
	 * @param string[] $messages Messages.
	 * @return string[]
	 */
	public static function messages( $messages ) {
		$messages = (array) $messages;
		if ( '' !== self::$message ) {
			$messages[]    = self::$message;
			self::$message = '';
		}
		return $messages;
	}

	// --------------------------------------------------------------------------
	// Merges
	// --------------------------------------------------------------------------

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
	 * @param array  $options         Merge options; ATCF's choice is under self::SECTION, 'locations'.
	 */
	public static function snapshot_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect, array $options = array() ): void {
		self::start( $options );
		self::$location_snapshots = array();
		foreach ( $source_ids as $source_id ) {
			self::$location_snapshots[ (int) $source_id ] = ATCF_Locations::get_by_term_id( (int) $source_id );
		}
	}

	/**
	 * Put the snapshotted locations where the merge options say, after HT commits.
	 *
	 * htax_after_merge_terms fires after HT's COMMIT. Source term locations have
	 * already been deleted by ATCF's deleted_term handler (as part of HT's own merge
	 * transaction, which this function cannot retroactively affect).
	 *
	 * @since 1.0.0
	 * @param int[]  $source_ids Source term IDs that were merged.
	 * @param int    $target_id  Target term ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param array  $result     HT merge result, with redirect_stubs.
	 */
	public static function sync_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result ): void {
		$stubs = (array) ( $result['redirect_stubs'] ?? array() );
		foreach ( $source_ids as $source_id ) {
			$source_id = (int) $source_id;
			self::carry(
				self::$location_snapshots[ $source_id ] ?? array(),
				(int) ( $stubs[ $source_id ] ?? 0 ),
				false,
				$taxonomy,
				$target_id,
				$taxonomy,
				htax_carry_choice( self::$operation['options'], self::SECTION, 'locations', ! empty( $stubs[ $source_id ] ) )
			);
			unset( self::$location_snapshots[ $source_id ] );
		}
		$target = get_term( $target_id );
		self::finish( $target instanceof WP_Term ? $target->name : '' );
	}

	// --------------------------------------------------------------------------
	// Conversions
	// --------------------------------------------------------------------------

	/**
	 * Action htax_before_convert_terms: remember the choice made, and which terms are being
	 * converted back to their stubs (their locations go back to the stub).
	 *
	 * @since 1.0.0
	 * @param int[]  $term_ids        Terms to convert.
	 * @param string $from_taxonomy   Their taxonomy.
	 * @param string $to_taxonomy     Taxonomy to convert them to.
	 * @param bool   $create_redirect Whether redirect stubs will be left (unused here).
	 * @param array  $options         Conversion options; ATCF's choice is under self::SECTION, 'locations'.
	 */
	public static function before_convert( $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect, $options = array() ): void {
		self::start( (array) $options );
		foreach ( (array) $term_ids as $term_id ) {
			if ( self::is_reverse( (int) $term_id, (string) $to_taxonomy ) ) {
				self::$operation['reverse'][] = (int) $term_id;
			}
		}
	}

	/**
	 * Action htax_before_convert_term: keep a term's locations, which ATCF deletes with the
	 * term when the conversion leaves no stub.
	 *
	 * @since 1.0.0
	 * @param int $term_id Term being converted.
	 */
	public static function before_convert_term( $term_id ): void {
		$rows = ATCF_Locations::get_by_term_id( (int) $term_id );
		if ( $rows ) {
			self::$snapshots[ (int) $term_id ] = $rows;
		}
	}

	/**
	 * Action htax_after_convert_term: put the converted term's locations where the
	 * conversion's options say. A term converted back to its stub gives them back to the stub.
	 *
	 * @since 1.0.0
	 * @param int    $new_term_id  New term ID.
	 * @param string $old_taxonomy Old taxonomy.
	 * @param string $new_taxonomy New taxonomy.
	 * @param int    $old_term_id  Old term ID (the stub, or deleted).
	 */
	public static function after_convert_term( $new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id ): void {
		$old_term_id = (int) $old_term_id;
		$alive       = get_term( $old_term_id, (string) $old_taxonomy ) instanceof WP_Term;
		$rows        = $alive ? ATCF_Locations::get_by_term_id( $old_term_id ) : ( self::$snapshots[ $old_term_id ] ?? array() );
		unset( self::$snapshots[ $old_term_id ] );

		$options = in_array( $old_term_id, self::$operation['reverse'], true )
			? array( 'htax' => array( 'carry' => 'move' ) )
			: self::$operation['options'];
		// A new term in a taxonomy without locations can't take them: they stay on the stub, or go.
		$choice = htax_carry_choice( $options, self::SECTION, 'locations', $alive, ATCF_Options::is_taxonomy_enabled( (string) $new_taxonomy ) );
		self::carry( $rows, $alive ? $old_term_id : 0, true, (string) $old_taxonomy, (int) $new_term_id, (string) $new_taxonomy, $choice );
	}

	/**
	 * Action htax_after_convert_terms: report where the locations went.
	 *
	 * @since 1.0.0
	 */
	public static function after_convert(): void {
		self::finish( '' );
	}

	// --------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------

	/**
	 * Start an operation: its options, no counts yet.
	 *
	 * @param array $options Operation options.
	 */
	private static function start( array $options ): void {
		self::$operation = array(
			'options' => $options,
			'reverse' => array(),
		);
		self::$counts    = array(
			'added' => 0,
			'on_stubs' => 0,
		);
		self::$message   = '';
	}

	/**
	 * End an operation: its result message, if any location was kept, and forget its options.
	 *
	 * @param string $target_name Merge target's name, or '' for a conversion.
	 */
	private static function finish( string $target_name ): void {
		if ( self::$counts['added'] || self::$counts['on_stubs'] ) {
			self::$message = '' !== $target_name
				/* translators: 1: number of locations, 2: target term name, 3: number of locations */
				? sprintf( __( 'Locations: %1$d added to %2$s, %3$d on redirect stubs.', 'as-the-crow-flies' ), self::$counts['added'], $target_name, self::$counts['on_stubs'] )
				/* translators: 1: number of locations, 2: number of locations */
				: sprintf( __( 'Locations: %1$d added to the new terms, %2$d on redirect stubs.', 'as-the-crow-flies' ), self::$counts['added'], self::$counts['on_stubs'] );
		}
		self::$operation = array(
			'options' => array(),
			'reverse' => array(),
		);
	}

	/**
	 * Put one term's locations on its stub and/or the term that can take them.
	 *
	 * Locations the receiving term already has (per ATCF's Location Matching settings) are
	 * not added again; locations inserted for one source join the pool, so several sources
	 * deduplicate against each other. If inserting fails, the rows this call inserted on the
	 * receiving term are deleted again (via ATCF_Locations::delete(), so caches and change
	 * hooks stay consistent), and the source rows are left as they were. A DB-level
	 * transaction isn't used: other plugins' callbacks on the atcf_location_changed hooks
	 * fired by ATCF_Locations::insert() can run their own queries that break an open
	 * transaction.
	 *
	 * @param object[] $rows           The term's location rows.
	 * @param int      $stub_id        Redirect stub term ID, or 0 for none.
	 * @param bool     $stub_has_rows  Whether the stub already has the rows (the old term, in
	 *                                 a conversion) or gets them only from here.
	 * @param string   $stub_taxonomy  The stub's taxonomy.
	 * @param int      $to_id          The term that can take them (merge target, new term).
	 * @param string   $to_taxonomy    Its taxonomy.
	 * @param string   $choice         'stub', 'move', 'copy' or 'drop', as htax_carry_choice()
	 *                                 resolves it for this term.
	 */
	private static function carry( array $rows, int $stub_id, bool $stub_has_rows, string $stub_taxonomy, int $to_id, string $to_taxonomy, string $choice ): void {
		if ( ! $rows ) {
			return;
		}

		if ( in_array( $choice, array( 'move', 'copy' ), true ) ) {
			$pool     = ATCF_Locations::get_by_term_id( $to_id );
			$inserted = array();
			foreach ( $rows as $loc ) {
				if ( self::location_matches_any( $loc, $pool ) ) {
					continue; // The receiving term already has this location.
				}
				$new_id = self::insert_copy( $loc, $to_id, $to_taxonomy );
				if ( is_wp_error( $new_id ) ) {
					foreach ( $inserted as $id ) {
						ATCF_Locations::delete( $id );
					}
					if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						error_log( '[ATCF HT] Location copy failed and was rolled back for term ' . $to_id );
					}
					if ( $stub_id && ! $stub_has_rows ) {
						$choice = 'stub'; // Nothing was moved: keep the rows on the stub.
					} else {
						return;
					}
					break;
				}
				$inserted[]     = $new_id;
				$added          = clone $loc;
				$added->term_id = $to_id;
				$pool[]         = $added;
			}
			if ( 'stub' !== $choice ) {
				self::$counts['added'] += count( $inserted );
			}
		}

		if ( ! $stub_id ) {
			return;
		}
		$keep = 'stub' === $choice || 'copy' === $choice;
		if ( $stub_has_rows && ! $keep ) {
			foreach ( $rows as $loc ) {
				ATCF_Locations::delete( (int) $loc->id );
			}
		} elseif ( ! $stub_has_rows && $keep ) {
			foreach ( $rows as $loc ) {
				if ( is_wp_error( self::insert_copy( $loc, $stub_id, $stub_taxonomy ) ) ) {
					return;
				}
			}
		}
		if ( $keep ) {
			self::$counts['on_stubs'] += count( $rows );
		}
	}

	/**
	 * Insert a copy of a location row for another term.
	 *
	 * @param object $loc      Location row.
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Its taxonomy.
	 * @return int|WP_Error New location ID.
	 */
	private static function insert_copy( object $loc, int $term_id, string $taxonomy ) {
		$data = (array) $loc;
		unset( $data['id'] ); // Let insert() generate a new ID.
		$data['term_id']  = $term_id;
		$data['taxonomy'] = $taxonomy;
		return ATCF_Locations::insert( $data );
	}

	/**
	 * Whether a term is being converted back to the redirect stub left by an earlier
	 * conversion.
	 *
	 * @param int    $term_id     Term ID.
	 * @param string $to_taxonomy Taxonomy it is converted to.
	 * @return bool
	 */
	private static function is_reverse( int $term_id, string $to_taxonomy ): bool {
		return function_exists( 'htax_find_auto_redirect_to_term' ) && (bool) htax_find_auto_redirect_to_term( $term_id, $to_taxonomy );
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
	 * suite plugin (HyperTaxonomy's htax-terms or TermConnect's tconn-terms), else null.
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
		$known = array( self::TERMS_MENU_SLUG, 'tconn-terms' );
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
			$after = array_key_exists( 'tconn_ext_links', $columns ) ? 'tconn_ext_links' : 'htax_hidden';
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
	foreach ( ATCF_Options::enabled_taxonomies() as $tax ) {
		if ( 'group' === $tax ) {
			continue;
		}
		$term_ids = array_merge( $term_ids, array_keys( htax_get_terms_in_groups( $group_ids, $tax ) ) );
	}
	return array_values( array_unique( $term_ids ) );
}
