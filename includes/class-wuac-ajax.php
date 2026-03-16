<?php
/**
 * WUAC_Ajax
 *
 * Handles all AJAX endpoints for the unified admin dashboard.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Ajax {

    /**
     * Register AJAX hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_ajax_wuac_email_lookup', array( $this, 'handle_email_lookup' ) );
        add_action( 'wp_ajax_wuac_delete_users', array( $this, 'handle_delete_users' ) );
        add_action( 'wp_ajax_wuac_find_inactive', array( $this, 'handle_find_inactive' ) );
        add_action( 'wp_ajax_wuac_delete_inactive', array( $this, 'handle_delete_inactive' ) );
        add_action( 'wp_ajax_wuac_add_domain', array( $this, 'handle_add_domain' ) );
        add_action( 'wp_ajax_wuac_remove_domain', array( $this, 'handle_remove_domain' ) );
        add_action( 'wp_ajax_wuac_get_domains', array( $this, 'handle_get_domains' ) );
        add_action( 'wp_ajax_wuac_scan_users', array( $this, 'handle_scan_users' ) );
        add_action( 'wp_ajax_wuac_erase_data', array( $this, 'handle_erase_data' ) );
    }

    /**
     * Verify nonce and capability for AJAX requests.
     *
     * @return void Sends JSON error and dies if verification fails.
     */
    private function verify_request(): void {
        if ( ! check_ajax_referer( 'wuac_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wp-user-audit-cleanup' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-user-audit-cleanup' ) ), 403 );
        }
    }

    /**
     * Handle email lookup AJAX request.
     *
     * @return void
     */
    public function handle_email_lookup(): void {
        $this->verify_request();

        $raw_input = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';
        $lookup    = new WUAC_Email_Lookup();
        $result    = $lookup->process_email_lookup( $raw_input );

        if ( is_string( $result ) ) {
            wp_send_json_error( array( 'message' => $result ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handle delete selected users AJAX request.
     *
     * @return void
     */
    public function handle_delete_users(): void {
        $this->verify_request();

        $user_ids = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) $_POST['user_ids'] ) : array();

        if ( empty( $user_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No users selected.', 'wp-user-audit-cleanup' ) ) );
        }

        $lookup  = new WUAC_Email_Lookup();
        $deleted = $lookup->delete_users( $user_ids, get_current_user_id() );

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted users */
                __( 'Successfully deleted %d user(s).', 'wp-user-audit-cleanup' ),
                $deleted
            ),
        ) );
    }

    /**
     * Handle find inactive users AJAX request.
     *
     * @return void
     */
    public function handle_find_inactive(): void {
        $this->verify_request();

        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 30;

        if ( $days < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a value of at least 1 day.', 'wp-user-audit-cleanup' ) ) );
        }

        $cleanup = new WUAC_Inactive_Cleanup();
        $users   = $cleanup->find_inactive_users( $days );

        $data = array_map( function ( $user ) {
            return array(
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'user_email'      => $user->user_email,
                'user_registered' => $user->user_registered,
            );
        }, $users );

        wp_send_json_success( array( 'users' => $data, 'count' => count( $data ) ) );
    }

    /**
     * Handle delete inactive users AJAX request.
     *
     * @return void
     */
    public function handle_delete_inactive(): void {
        $this->verify_request();

        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 30;

        if ( $days < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a value of at least 1 day.', 'wp-user-audit-cleanup' ) ) );
        }

        $cleanup = new WUAC_Inactive_Cleanup();
        $deleted = $cleanup->delete_inactive_users( $days, get_current_user_id() );

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted users */
                __( 'Successfully deleted %d inactive user(s).', 'wp-user-audit-cleanup' ),
                $deleted
            ),
        ) );
    }

    /**
     * Handle add domain AJAX request.
     *
     * @return void
     */
    public function handle_add_domain(): void {
        $this->verify_request();

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

        if ( '' === $domain ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a domain name.', 'wp-user-audit-cleanup' ) ) );
        }

        if ( WUAC_Disposable_Domains::add_domain( $domain ) ) {
            wp_send_json_success( array(
                'message' => sprintf( __( 'Domain "%s" added.', 'wp-user-audit-cleanup' ), $domain ),
                'domains' => $this->get_sorted_domains(),
            ) );
        }

        wp_send_json_error( array(
            'message' => sprintf( __( 'Domain "%s" already exists.', 'wp-user-audit-cleanup' ), $domain ),
        ) );
    }

    /**
     * Handle remove domain AJAX request.
     *
     * @return void
     */
    public function handle_remove_domain(): void {
        $this->verify_request();

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

        if ( WUAC_Disposable_Domains::remove_domain( $domain ) ) {
            wp_send_json_success( array(
                'message' => sprintf( __( 'Domain "%s" removed.', 'wp-user-audit-cleanup' ), $domain ),
                'domains' => $this->get_sorted_domains(),
            ) );
        }

        wp_send_json_error( array(
            'message' => sprintf( __( 'Could not remove "%s".', 'wp-user-audit-cleanup' ), $domain ),
        ) );
    }

    /**
     * Handle get domains AJAX request.
     *
     * @return void
     */
    public function handle_get_domains(): void {
        $this->verify_request();

        wp_send_json_success( array( 'domains' => $this->get_sorted_domains() ) );
    }

    /**
     * Handle scan users AJAX request.
     *
     * @return void
     */
    public function handle_scan_users(): void {
        $this->verify_request();

        $result = WUAC_User_Scanner::scan();

        wp_send_json_success( array(
            'backfilled' => $result['backfilled'],
            'flagged'    => $result['flagged'],
            'scanned'    => $result['scanned'],
            'message'    => sprintf(
                __( 'Scan complete. %1$d users scanned, %2$d login records backfilled, %3$d users auto-flagged.', 'wp-user-audit-cleanup' ),
                $result['scanned'],
                $result['backfilled'],
                $result['flagged']
            ),
        ) );
    }

    /**
     * Handle erase plugin data AJAX request.
     *
     * @return void
     */
    public function handle_erase_data(): void {
        $this->verify_request();

        global $wpdb;

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_last_login' ) );
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_spam_flag' ) );

        wp_send_json_success( array(
            'message' => __( 'All plugin data has been erased.', 'wp-user-audit-cleanup' ),
        ) );
    }

    /**
     * Get sorted domain list.
     *
     * @return array Sorted array of domain strings.
     */
    private function get_sorted_domains(): array {
        $domains = WUAC_Disposable_Domains::get_domains();
        sort( $domains );
        return $domains;
    }
}
