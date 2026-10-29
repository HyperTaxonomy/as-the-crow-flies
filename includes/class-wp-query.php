<?php
/**
 * WP_Query integration — nested atcf_query parameter support.
 *
 * Hooks pre_get_posts to detect an atcf_query array, resolve the reference
 * point to a set of eligible term IDs, and inject a tax_query clause.
 * Supports orderby => 'atcf_distance' via posts_orderby.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_WP_Query {

	/**
	 * Distance result stored during pre_get_posts for use in posts_orderby.
	 * Keyed by WP_Query object id (spl_object_id).
	 *
	 * @var array[]
	 */
	private static array $query_distances = array();

	public static function register(): void {
		add_filter( 'pre_get_posts',  array( __CLASS__, 'handle_atcf_query' ), 10, 1 );
		add_filter( 'posts_orderby',  array( __CLASS__, 'handle_orderby' ), 10, 2 );
		add_action( 'the_posts',      array( __CLASS__, 'cleanup' ), 10, 2 );
	}

	/**
	 * Detect atcf_query, resolve term IDs, inject tax_query clause.
	 *
	 * @param WP_Query $query Query object.
	 */
	public static function handle_atcf_query( WP_Query $query ): void {
		$atcf_query = $query->get( 'atcf_query' );
		if ( empty( $atcf_query ) || ! is_array( $atcf_query ) ) {
			return;
		}

		$aq = wp_parse_args( $atcf_query, array(
			'postal_code' => '',
			'radius'      => 10,
			'unit'        => get_option( 'atcf_distance_units', 'miles' ),
			'country'     => 'US',
			'taxonomy'    => '',
			'lat'         => null,
			'lng'         => null,
		) );

		$radius = max( 0.1, (float) $aq['radius'] );
		$unit   = in_array( $aq['unit'], array( 'miles', 'km' ), true ) ? $aq['unit'] : (string) get_option( 'atcf_distance_units', 'miles' );

		// Resolve reference point.
		$lat = $aq['lat'] !== null && $aq['lng'] !== null
			&& ATCF_Distance::is_valid_coordinate( (float) $aq['lat'], (float) $aq['lng'] )
			? (float) $aq['lat'] : null;
		$lng = null !== $lat ? (float) $aq['lng'] : null;

		if ( null !== $lat ) {
			// Coordinate mode.
			$locations = ATCF_Distance::get_locations_within_radius(
				'',
				$radius,
				$unit,
				array(
					'lat'      => $lat,
					'lng'      => $lng,
					'taxonomy' => $aq['taxonomy'],
					'limit'    => 9999,
				)
			);
		} else {
			// Postal code mode.
			$postal_code = ATCF_Distance::normalize_postal_code( $aq['postal_code'], $aq['country'] );
			if ( '' === $postal_code ) {
				$query->set( 'post__in', array( 0 ) );
				return;
			}

			$locations = ATCF_Distance::get_locations_within_radius(
				$postal_code,
				$radius,
				$unit,
				array(
					'country'  => $aq['country'],
					'taxonomy' => $aq['taxonomy'],
					'limit'    => 9999,
				)
			);
		}

		if ( empty( $locations ) ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$term_ids = array_values(
			array_unique( array_map( 'intval', array_column( $locations, 'term_id' ) ) )
		);

		// Determine taxonomy for the tax_query clause.
		$taxonomy = $aq['taxonomy'];
		if ( '' === $taxonomy ) {
			// Multiple taxonomies possible — build one clause per taxonomy.
			$by_tax = array();
			foreach ( $locations as $loc ) {
				$by_tax[ $loc['taxonomy'] ][] = (int) $loc['term_id'];
			}

			if ( 1 === count( $by_tax ) ) {
				$taxonomy = key( $by_tax );
			} else {
				// Multiple taxonomies: wrap in OR.
				$relation_clauses = array( 'relation' => 'OR' );
				foreach ( $by_tax as $tax => $tids ) {
					$relation_clauses[] = array(
						'taxonomy'         => $tax,
						'field'            => 'term_id',
						'terms'            => array_unique( $tids ),
						'include_children' => false,
						'operator'         => 'IN',
					);
				}

				$existing = $query->get( 'tax_query' ) ?: array();
				if ( ! empty( $existing ) ) {
					$query->set( 'tax_query', array(
						'relation' => 'AND',
						$existing,
						$relation_clauses,
					) );
				} else {
					$query->set( 'tax_query', $relation_clauses );
				}

				// Store distances keyed by term_id for possible orderby use.
				self::store_distances( $query, $locations );
				return;
			}
		}

		$tax_clause = array(
			'taxonomy'         => $taxonomy,
			'field'            => 'term_id',
			'terms'            => $term_ids,
			'include_children' => false,
			'operator'         => 'IN',
		);

		$existing = $query->get( 'tax_query' ) ?: array();
		if ( ! empty( $existing ) ) {
			$query->set( 'tax_query', array(
				'relation' => 'AND',
				$existing,
				$tax_clause,
			) );
		} else {
			$query->set( 'tax_query', array( $tax_clause ) );
		}

		self::store_distances( $query, $locations );
	}

	/**
	 * Apply distance-based ORDER BY when orderby = 'atcf_distance'.
	 *
	 * Sorts posts by the minimum distance of any of their matched term locations.
	 * Falls back to post ID if distance data is unavailable.
	 *
	 * @param string   $orderby Current ORDER BY SQL.
	 * @param WP_Query $query   Query object.
	 * @return string Modified ORDER BY SQL.
	 */
	public static function handle_orderby( string $orderby, WP_Query $query ): string {
		if ( 'atcf_distance' !== $query->get( 'orderby' ) ) {
			return $orderby;
		}

		$distances = self::get_distances( $query );
		if ( empty( $distances ) ) {
			return $orderby;
		}

		// Build a CASE expression: CASE {$wpdb->posts}.ID WHEN id1 THEN dist1 ... END.
		global $wpdb;

		// Map post IDs to minimum distance via their term assignments.
		// We can't do this purely in SQL without knowing which posts match which terms,
		// so we order by term_id distance — posts with closer terms sort first.
		// Build a FIELD()-based expression ordering term IDs by distance.
		$term_ids_ordered = array_keys( $distances );
		if ( empty( $term_ids_ordered ) ) {
			return $orderby;
		}

		$placeholders = implode( ',', array_fill( 0, count( $term_ids_ordered ), '%d' ) );

		// Join term_relationships → term_taxonomy to resolve term_id, then order
		// posts by the minimum FIELD() rank of their associated terms.
		// term_relationships stores term_taxonomy_id (not term_id), so the JOIN
		// is required — passing term_ids to FIELD(term_taxonomy_id, ...) silently
		// produces wrong ordering.
		// NULLIF(FIELD(...), 0) makes terms not in the distance list rank as NULL,
		// which MIN() ignores — so a post carrying an unrelated term is ranked only
		// by its matched terms instead of jumping to the front with rank 0.
		$order_expr = $wpdb->prepare(

			"(SELECT MIN(NULLIF(FIELD(tt.term_id, {$placeholders}), 0))
			  FROM {$wpdb->term_relationships} tr
			  INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			  WHERE tr.object_id = {$wpdb->posts}.ID) ASC",
			$term_ids_ordered
		);

		return '' !== $orderby ? $order_expr . ', ' . $orderby : $order_expr;
	}

	/**
	 * Clean up stored distance data after query completes.
	 *
	 * @param WP_Post[] $posts Array of post objects.
	 * @param WP_Query  $query Query object.
	 * @return WP_Post[]
	 */
	public static function cleanup( array $posts, WP_Query $query ): array {
		$key = spl_object_id( $query );
		unset( self::$query_distances[ $key ] );
		return $posts;
	}

	// --------------------------------------------------------------------------
	// Internal helpers
	// --------------------------------------------------------------------------

	/**
	 * Store location distances keyed by term_id for a query.
	 *
	 * @param WP_Query $query     Query object.
	 * @param array[]  $locations Location rows with term_id and distance_km.
	 */
	private static function store_distances( WP_Query $query, array $locations ): void {
		$key       = spl_object_id( $query );
		$distances = array();

		foreach ( $locations as $loc ) {
			$tid = (int) $loc['term_id'];
			$km  = (float) $loc['distance_km'];
			if ( ! isset( $distances[ $tid ] ) || $km < $distances[ $tid ] ) {
				$distances[ $tid ] = $km;
			}
		}

		// Sort by distance ascending so FIELD() order maps to proximity.
		asort( $distances );
		self::$query_distances[ $key ] = $distances;
	}

	/**
	 * Retrieve stored distances for a query.
	 *
	 * @param WP_Query $query Query object.
	 * @return float[] term_id => distance_km, sorted ascending.
	 */
	private static function get_distances( WP_Query $query ): array {
		$key = spl_object_id( $query );
		return self::$query_distances[ $key ] ?? array();
	}
}
