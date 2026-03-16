<?php
/**
 * Uninstall handler for WordPress User Audit & Cleanup.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * via the WordPress admin Plugins screen.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all user meta created by the plugin.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_last_login' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_spam_flag' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Remove plugin options.
delete_option( 'wuac_custom_domains' );
delete_option( 'wuac_removed_domains' );

// Clean up any transients.
delete_transient( 'wuac_export_notice' );
