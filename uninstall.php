<?php
/**
 * Uninstall script for As the Crow Flies.
 *
 * Runs when the plugin is deleted via the WordPress admin. Plugin settings are
 * always removed. The location tables and centroid data are only dropped when
 * the user has explicitly enabled "Delete all data on uninstall" in Settings →
 * As the Crow Flies → Advanced.
 *
 * @package AsCrowFlies
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function atcf_uninstall_cleanup() {
	global $wpdb;

	$delete_data = (bool) get_option( 'atcf_delete_on_uninstall', false );

	// Always remove plugin settings and version.
	$options = array(
		'atcf_db_version',
		'atcf_us_centroids_imported',
		'atcf_us_centroids_enabled',
		'atcf_country_centroids',
		'atcf_enabled_taxonomies',
		'atcf_distance_units',
		'atcf_location_match_radius',
		'atcf_find_terms_radius',
		'atcf_location_match_mode',
		'atcf_match_require_label_address',
		'atcf_match_require_label_coords',
		'atcf_ip_geo_provider',
		'atcf_ip_geo_enabled',
		'atcf_ip_geo_ttl',
		'atcf_fallback_location',
		'atcf_conflict_address',
		'atcf_conflict_label',
		'atcf_conflict_coords',
		'atcf_expose_locations_api',
		'atcf_directions_provider',
		'atcf_delete_on_uninstall',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Always clear the geocoding cron.
	wp_clear_scheduled_hook( 'atcf_geocode_pending' );

	// Always remove all ATCF transients (IP geolocation cache, country list cache).
	$t  = $wpdb->esc_like( '_transient_atcf_' )         . '%';
	$tt = $wpdb->esc_like( '_transient_timeout_atcf_' ) . '%';
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$t, $tt
	) );

	// Only drop tables and centroid data if the user opted in.
	if ( $delete_data ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}atcf_locations" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}atcf_postal_centroids" );
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		atcf_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	atcf_uninstall_cleanup();
}
