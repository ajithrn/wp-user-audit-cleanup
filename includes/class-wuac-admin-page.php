<?php
/**
 * WUAC_Admin_Page
 *
 * Unified admin page with client-side tab navigation. Renders a minimal
 * HTML shell; all interactivity is handled by assets/js/admin.js via AJAX.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Admin_Page {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ) );
    }

    /**
     * Register the unified admin page under the Users menu.
     *
     * @return void
     */
    public function register_page(): void {
        add_users_page(
            __( 'User Audit & Cleanup', 'wp-user-audit-cleanup' ),
            __( 'User Audit', 'wp-user-audit-cleanup' ),
            'manage_options',
            'wuac-dashboard',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the admin page shell.
     *
     * The JS module reads data-* attributes and populates content via AJAX.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-user-audit-cleanup' ) );
        }
        ?>
        <div class="wrap" id="wuac-app">
            <h1><?php esc_html_e( 'User Audit & Cleanup', 'wp-user-audit-cleanup' ); ?></h1>

            <!-- Toast container for AJAX feedback -->
            <div id="wuac-toast" class="wuac-toast" role="alert" aria-live="polite" hidden></div>

            <!-- Tab navigation -->
            <nav class="wuac-tabs" role="tablist">
                <button class="wuac-tab wuac-tab--active" role="tab" aria-selected="true" data-tab="lookup">
                    <?php esc_html_e( 'Email Lookup', 'wp-user-audit-cleanup' ); ?>
                </button>
                <button class="wuac-tab" role="tab" aria-selected="false" data-tab="cleanup">
                    <?php esc_html_e( 'Inactive Cleanup', 'wp-user-audit-cleanup' ); ?>
                </button>
                <button class="wuac-tab" role="tab" aria-selected="false" data-tab="settings">
                    <?php esc_html_e( 'Settings', 'wp-user-audit-cleanup' ); ?>
                </button>
            </nav>

            <!-- Tab: Email Lookup -->
            <div class="wuac-panel" id="wuac-panel-lookup" role="tabpanel" data-tab="lookup">
                <div class="wuac-card">
                    <h2><?php esc_html_e( 'Email Lookup', 'wp-user-audit-cleanup' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Paste email addresses or use wildcard patterns to find matching user accounts.', 'wp-user-audit-cleanup' ); ?></p>
                    <label for="wuac-email-list"><?php esc_html_e( 'Email addresses or patterns (one per line):', 'wp-user-audit-cleanup' ); ?></label>
                    <textarea id="wuac-email-list" rows="10" class="large-text" placeholder="user1@example.com&#10;*.ru&#10;*@tempmail*&#10;*casino*@*"></textarea>
                    <p class="description wuac-pattern-hint">
                        <?php esc_html_e( 'Patterns: Use * as wildcard. Examples: *.ru (all .ru domains), *@yandex.* (Yandex emails), *casino*@* (casino in email).', 'wp-user-audit-cleanup' ); ?>
                    </p>
                    <button type="button" id="wuac-lookup-btn" class="button button-primary"><?php esc_html_e( 'Look Up', 'wp-user-audit-cleanup' ); ?></button>
                </div>
                <div id="wuac-lookup-results" hidden></div>
            </div>

            <!-- Tab: Inactive Cleanup -->
            <div class="wuac-panel" id="wuac-panel-cleanup" role="tabpanel" data-tab="cleanup" hidden>
                <div class="wuac-card">
                    <h2><?php esc_html_e( 'Inactive Cleanup', 'wp-user-audit-cleanup' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Find and remove users who registered but never logged in.', 'wp-user-audit-cleanup' ); ?></p>
                    <div class="wuac-inline-form">
                        <div class="wuac-filter-group">
                            <label for="wuac-inactive-days"><?php esc_html_e( 'Days since registration', 'wp-user-audit-cleanup' ); ?></label>
                            <input type="number" id="wuac-inactive-days" value="30" min="1" class="small-text" />
                        </div>
                        <button type="button" id="wuac-find-inactive-btn" class="button button-primary"><?php esc_html_e( 'Find Inactive Users', 'wp-user-audit-cleanup' ); ?></button>
                    </div>
                </div>
                <div id="wuac-inactive-results" hidden></div>
            </div>

            <!-- Tab: Settings -->
            <div class="wuac-panel" id="wuac-panel-settings" role="tabpanel" data-tab="settings" hidden>
                <!-- Domain Management -->
                <div class="wuac-card">
                    <h2><?php esc_html_e( 'Disposable Domain Management', 'wp-user-audit-cleanup' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Manage disposable email domains used for spam detection.', 'wp-user-audit-cleanup' ); ?></p>
                    <div class="wuac-inline-form">
                        <div class="wuac-filter-group">
                            <label for="wuac-new-domain"><?php esc_html_e( 'Add Domain', 'wp-user-audit-cleanup' ); ?></label>
                            <input type="text" id="wuac-new-domain" placeholder="example.com" class="regular-text" />
                        </div>
                        <button type="button" id="wuac-add-domain-btn" class="button button-primary"><?php esc_html_e( 'Add', 'wp-user-audit-cleanup' ); ?></button>
                    </div>
                    <div id="wuac-domain-list-wrap">
                        <p class="wuac-loading"><?php esc_html_e( 'Loading domains…', 'wp-user-audit-cleanup' ); ?></p>
                    </div>
                </div>

                <!-- Scan Users -->
                <div class="wuac-card">
                    <h2><?php esc_html_e( 'Scan Existing Users', 'wp-user-audit-cleanup' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Check session tokens to backfill login data and auto-flag users scoring 70+.', 'wp-user-audit-cleanup' ); ?></p>
                    <button type="button" id="wuac-scan-btn" class="button button-primary"><?php esc_html_e( 'Scan Users', 'wp-user-audit-cleanup' ); ?></button>
                </div>

                <!-- Erase Data -->
                <div class="wuac-card wuac-danger-zone">
                    <h2><?php esc_html_e( 'Erase Plugin Data', 'wp-user-audit-cleanup' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Remove all login timestamps and spam flags from the database. This cannot be undone.', 'wp-user-audit-cleanup' ); ?></p>
                    <button type="button" id="wuac-erase-btn" class="button button-link-delete"><?php esc_html_e( 'Erase All Data', 'wp-user-audit-cleanup' ); ?></button>
                </div>
            </div><!-- #wuac-panel-settings -->

        </div><!-- #wuac-app -->
        <?php
    }
}
