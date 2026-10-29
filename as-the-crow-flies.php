<?php
/**
 * Plugin Name: As the Crow Flies
 * Plugin URI:  https://github.com/HyperTaxonomy/as-the-crow-flies
 * Description: Proximity-based filtering of taxonomy-tagged content. Attach physical locations to taxonomy terms and let visitors filter by distance.
 * Version:     1.0.0
 * Author:      Eugene Weinstein
 * Author URI:  https://hypertaxonomy.org
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: as-the-crow-flies
 * Domain Path: /languages
 *
 * @package AsCrowFlies
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATCF_VERSION',   '1.0.0' );
define( 'ATCF_PLUGIN_FILE', __FILE__ );
define( 'ATCF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATCF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load translations. Hooked on init (not plugins_loaded) per WordPress 6.7+ guidance.
add_action( 'init', static function () {
	load_plugin_textdomain( 'as-the-crow-flies', false, dirname( plugin_basename( ATCF_PLUGIN_FILE ) ) . '/languages' );
} );

require_once ATCF_PLUGIN_DIR . 'includes/class-activator.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-locations.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-distance.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-geocoder.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-wp-query.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-admin.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-locations-page.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-settings-page.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-csv.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-blocks.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-locations-global.php';
require_once ATCF_PLUGIN_DIR . 'includes/class-ht-integration.php';

register_activation_hook( __FILE__, array( 'ATCF_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ATCF_Activator', 'deactivate' ) );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'atcf_plugin_action_links' );

function atcf_plugin_action_links( array $links ): array {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=as-the-crow-flies' ) ) . '">'
		. esc_html__( 'Settings', 'as-the-crow-flies' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'plugins_loaded', 'atcf_init' );

function atcf_init(): void {
	ATCF_WP_Query::register();
	ATCF_REST_API::register();
	ATCF_Shortcodes::register();
	ATCF_Blocks::register();
	ATCF_HT_Integration::register();

	if ( is_admin() ) {
		ATCF_Admin::register();
		ATCF_Locations_Page::register();
		ATCF_Settings_Page::register();
		ATCF_Locations_Global::register();
	}

	ATCF_Geocoder::register_cron();

	// Delete location rows when a term is permanently deleted.
	add_action( 'deleted_term', static function ( int $term_id, int $tt_id, string $taxonomy ): void {
		ATCF_Locations::delete_for_term( $term_id, $taxonomy );
	}, 10, 3 );

	// delete_term / deleted_term do not fire during bulk taxonomy operations.
	// delete_term_taxonomy fires before the term_taxonomy row is removed, so the
	// term still exists and term_id / taxonomy can be resolved from $tt_id.
	add_action( 'delete_term_taxonomy', static function ( int $tt_id ): void {
		$term = get_term_by( 'term_taxonomy_id', $tt_id );
		if ( $term instanceof WP_Term ) {
			ATCF_Locations::delete_for_term( $term->term_id, $term->taxonomy );
		}
	}, 10, 1 );
}
