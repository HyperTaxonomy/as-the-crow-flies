<?php
/**
 * Options read in several places, and safe changes to options that several requests may
 * change at once.
 *
 * @package AsCrowFlies
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATCF_Options {

	/**
	 * The taxonomies enabled for locations in Settings → General.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	public static function enabled_taxonomies(): array {
		return array_values( array_filter( (array) get_option( 'atcf_enabled_taxonomies', array() ), 'is_string' ) );
	}

	/**
	 * Whether As the Crow Flies' location features are enabled for a taxonomy: enabled in
	 * Settings, unless the atcf_taxonomy_enabled filter says otherwise.
	 *
	 * @since 1.0.0
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function is_taxonomy_enabled( string $taxonomy ): bool {
		if ( '' === $taxonomy ) {
			return false;
		}

		/**
		 * Filters whether As the Crow Flies' location features are enabled for a taxonomy.
		 *
		 * @since 1.0.0
		 * @param bool   $enabled  Whether the taxonomy is enabled in Settings.
		 * @param string $taxonomy Taxonomy slug.
		 */
		return (bool) apply_filters( 'atcf_taxonomy_enabled', in_array( $taxonomy, self::enabled_taxonomies(), true ), $taxonomy );
	}

	/**
	 * An option's current value in the database. get_option() serves the value cached when the
	 * request first read it (all autoloaded options load at its start), which another request
	 * may have changed since; a change based on that value would undo theirs.
	 *
	 * @since 1.0.0
	 * @param string $option  Option name.
	 * @param mixed  $default Value when the option doesn't exist.
	 * @return mixed
	 */
	public static function get_fresh( string $option, $default = false ) {
		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		if ( array_key_exists( $option, wp_load_alloptions() ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
		return get_option( $option, $default );
	}

	/**
	 * Change an option safely when other requests may change it too: under a lock (a MySQL
	 * user lock, one per option and site), read its current value, apply $change, and save
	 * the result. Without the lock (another request kept it for 10 seconds), it goes ahead
	 * anyway, from the current value.
	 *
	 * @since 1.0.0
	 * @param string   $option  Option name.
	 * @param callable $change  Receives the current value; returns the new value, or null to
	 *                          leave the option as it is.
	 * @param mixed    $default Value when the option doesn't exist.
	 */
	public static function update_locked( string $option, callable $change, $default = array() ): void {
		global $wpdb;
		$lock   = substr( 'atcf_' . md5( DB_NAME . '|' . $wpdb->prefix ) . '_' . $option, 0, 64 );
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) );
		try {
			$value = $change( self::get_fresh( $option, $default ) );
			if ( null !== $value ) {
				update_option( $option, $value );
			}
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}
}
