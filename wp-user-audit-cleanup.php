<?php
/**
 * Plugin Name:       WordPress User Audit & Cleanup
 * Plugin URI:        https://developer.wordpress.org/plugins/
 * Description:       Enhances the WordPress admin Users screen with advanced filtering, spam detection, and bulk management capabilities.
 * Version:           1.3.0
 * Author:            Ajith R N
 * Author URI:        https://developer.wordpress.org/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-user-audit-cleanup
 * Domain Path:       /languages
 * Requires at least: 5.9
 * Requires PHP:      7.4
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WUAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WUAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WUAC_VERSION', '1.3.0' );

/**
 * Require class files from includes/ directory.
 */
$wuac_includes = array(
    'class-wuac-login-tracker',
    'class-wuac-columns',
    'class-wuac-filters',
    'class-wuac-bulk-actions',
    'class-wuac-spam-score',
    'class-wuac-email-lookup',
    'class-wuac-disposable-domains',
    'class-wuac-inactive-cleanup',
    'class-wuac-export',
    'class-wuac-settings',
    'class-wuac-user-scanner',
    'class-wuac-ajax',
    'class-wuac-admin-page',
);

foreach ( $wuac_includes as $file ) {
    $filepath = WUAC_PLUGIN_DIR . 'includes/' . $file . '.php';
    if ( file_exists( $filepath ) ) {
        require_once $filepath;
    }
}

/**
 * Initialize plugin classes.
 *
 * Login tracking and disposable-domain flagging run for ALL users so that
 * every login and registration is captured regardless of role.
 *
 * Admin-only UI classes are gated behind the manage_options capability
 * per Requirement 11.5.
 */
function wuac_init() {
    // --- Classes that must run for ALL users (not capability-gated) ---

    // Track every successful login (Requirement 1.1).
    $login_tracker = new WUAC_Login_Tracker();
    $login_tracker->init();

    // Auto-flag disposable-email registrations (Requirement 8.3).
    $disposable_domains = new WUAC_Disposable_Domains();
    $disposable_domains->init();

    // --- Admin-only classes (Requirement 11.5) ---
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $columns = new WUAC_Columns();
    $columns->init();

    $filters = new WUAC_Filters();
    $filters->init();

    $bulk_actions = new WUAC_Bulk_Actions();
    $bulk_actions->init();

    // WUAC_Spam_Score — all static methods, no init() needed.

    $admin_page = new WUAC_Admin_Page();
    $admin_page->init();

    $ajax = new WUAC_Ajax();
    $ajax->init();

    // WUAC_Inactive_Cleanup — no init(), instantiated on-demand by AJAX handler.

    $export = new WUAC_Export();
    $export->init();
}
add_action( 'plugins_loaded', 'wuac_init' );

/**
 * Enqueue admin styles on relevant screens.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 *
 * @since 1.1.0
 */
function wuac_enqueue_admin_assets( $hook_suffix ) {
    // CSS on users list and dashboard page.
    $css_screens = array( 'users.php', 'users_page_wuac-dashboard' );

    if ( in_array( $hook_suffix, $css_screens, true ) ) {
        wp_enqueue_style(
            'wuac-admin',
            WUAC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WUAC_VERSION
        );
    }

    // JS only on the dashboard page.
    if ( 'users_page_wuac-dashboard' === $hook_suffix ) {
        wp_enqueue_script(
            'wuac-admin-js',
            WUAC_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            WUAC_VERSION,
            true
        );

        wp_localize_script( 'wuac-admin-js', 'wuacData', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wuac_ajax_nonce' ),
        ) );
    }
}
add_action( 'admin_enqueue_scripts', 'wuac_enqueue_admin_assets' );

/**
 * Plugin activation hook.
 *
 * Registers the plugin's user meta keys without modifying existing user data.
 * Requirement 11.1.
 */
function wuac_activate() {
    register_meta( 'user', '_wuac_last_login', array(
        'type'              => 'string',
        'description'       => 'UTC timestamp of the user\'s last successful login.',
        'single'            => true,
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest'      => false,
    ) );

    register_meta( 'user', '_wuac_spam_flag', array(
        'type'              => 'string',
        'description'       => 'Spam flag indicator (1 = flagged).',
        'single'            => true,
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest'      => false,
    ) );

    // Scan existing users: backfill login data from session tokens
    // and auto-flag high-risk accounts.
    if ( class_exists( 'WUAC_User_Scanner' ) ) {
        WUAC_User_Scanner::scan();
    }
}
register_activation_hook( __FILE__, 'wuac_activate' );

/**
 * Plugin deactivation hook.
 *
 * Intentionally retains all user meta data created by the plugin so that
 * data is preserved if the plugin is reactivated later.
 * Requirement 11.2.
 */
function wuac_deactivate() {
    // No-op: retain all plugin data on deactivation.
}
register_deactivation_hook( __FILE__, 'wuac_deactivate' );
