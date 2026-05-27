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
        add_action( 'wp_ajax_wuac_flag_users', array( $this, 'handle_flag_users' ) );
        add_action( 'wp_ajax_wuac_find_inactive', array( $this, 'handle_find_inactive' ) );
        add_action( 'wp_ajax_wuac_delete_all_inactive', array( $this, 'handle_delete_all_inactive' ) );
        add_action( 'wp_ajax_wuac_find_high_risk', array( $this, 'handle_find_high_risk' ) );
        add_action( 'wp_ajax_wuac_delete_all_high_risk', array( $this, 'handle_delete_all_high_risk' ) );
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

        // Append spam score and factors to each matched user.
        if ( ! empty( $result['matched'] ) ) {
            $result['matched'] = array_map( function ( $user_data ) {
                $user = get_userdata( (int) $user_data['ID'] );
                if ( $user ) {
                    $spam_data = $this->get_user_spam_data( $user );
                    $user_data['spam_score']   = $spam_data['score'];
                    $user_data['spam_factors'] = $spam_data['factors'];
                } else {
                    $user_data['spam_score']   = 0;
                    $user_data['spam_factors'] = array();
                }
                return $user_data;
            }, $result['matched'] );
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
        $role = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'all';
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'both';

        if ( $days < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a value of at least 1 day.', 'wp-user-audit-cleanup' ) ) );
        }

        $cleanup = new WUAC_Inactive_Cleanup();
        $users   = $cleanup->find_inactive_users( $days, $role, $type );

        $data = array_map( function ( $user ) {
            $user_obj   = get_userdata( $user->ID );
            $role       = ( $user_obj && ! empty( $user_obj->roles ) ) ? reset( $user_obj->roles ) : 'none';
            $last_login = get_user_meta( $user->ID, '_wuac_last_login', true );
            
            if ( $user_obj ) {
                $spam_data    = $this->get_user_spam_data( $user_obj );
                $spam_score   = $spam_data['score'];
                $spam_factors = $spam_data['factors'];
            } else {
                $spam_score   = 0;
                $spam_factors = array();
            }

            return array(
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'user_email'      => $user->user_email,
                'user_registered' => $user->user_registered,
                'role'            => $role,
                'last_login'      => $last_login ? $last_login : '',
                'spam_score'      => $spam_score,
                'spam_factors'    => $spam_factors,
            );
        }, $users );

        wp_send_json_success( array( 'users' => $data, 'count' => count( $data ) ) );
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
     * Handle find high risk users AJAX request.
     *
     * @return void
     */
    public function handle_find_high_risk(): void {
        $this->verify_request();

        if ( ! class_exists( 'WUAC_Spam_Score' ) ) {
            wp_send_json_error( array( 'message' => __( 'Spam scoring engine not available.', 'wp-user-audit-cleanup' ) ) );
        }

        $min_score = isset( $_POST['min_score'] ) ? intval( $_POST['min_score'] ) : 70;
        $role      = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'all';

        $args = array(
            'fields' => 'ID',
            'number' => -1,
        );
        if ( 'all' !== $role ) {
            $args['role'] = $role;
        }

        $user_query = new WP_User_Query( $args );
        $user_ids   = $user_query->get_results();
        $high_risk_users = array();

        if ( ! empty( $user_ids ) ) {
            $chunks = array_chunk( $user_ids, 200 );
            foreach ( $chunks as $chunk ) {
                foreach ( $chunk as $user_id ) {
                    $user = get_userdata( (int) $user_id );
                    if ( ! $user ) {
                        continue;
                    }

                    $spam_data = $this->get_user_spam_data( $user );
                    if ( $spam_data['score'] >= $min_score ) {
                        $user_role  = ! empty( $user->roles ) ? reset( $user->roles ) : 'none';
                        $last_login = get_user_meta( $user->ID, '_wuac_last_login', true );
                        $high_risk_users[] = array(
                            'ID'              => $user->ID,
                            'user_login'      => $user->user_login,
                            'user_email'      => $user->user_email,
                            'user_registered' => $user->user_registered,
                            'role'            => $user_role,
                            'spam_score'      => $spam_data['score'],
                            'spam_factors'    => $spam_data['factors'],
                            'last_login'      => $last_login ? $last_login : '',
                        );
                    }
                }
            }
        }

        wp_send_json_success( array(
            'users' => $high_risk_users,
            'count' => count( $high_risk_users ),
        ) );
    }

    /**
     * Handle flag selected users as spam AJAX request.
     *
     * @return void
     */
    public function handle_flag_users(): void {
        $this->verify_request();

        $user_ids = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) $_POST['user_ids'] ) : array();

        if ( empty( $user_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No users selected.', 'wp-user-audit-cleanup' ) ) );
        }

        $flagged = 0;
        foreach ( $user_ids as $user_id ) {
            update_user_meta( (int) $user_id, '_wuac_spam_flag', '1' );
            $flagged++;
        }

        wp_send_json_success( array(
            'flagged' => $flagged,
            'message' => sprintf(
                /* translators: %d: number of flagged users */
                __( 'Successfully flagged %d user(s) as spam.', 'wp-user-audit-cleanup' ),
                $flagged
            ),
        ) );
    }

    /**
     * Handle delete ALL inactive users server-side.
     *
     * Re-queries using the same filters instead of receiving IDs,
     * which avoids PHP max_input_vars limits on large result sets.
     *
     * @return void
     */
    public function handle_delete_all_inactive(): void {
        $this->verify_request();

        $days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 30;
        $role = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'all';
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'both';

        if ( $days < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid days value.', 'wp-user-audit-cleanup' ) ) );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $cleanup     = new WUAC_Inactive_Cleanup();
        $users       = $cleanup->find_inactive_users( $days, $role, $type );
        $reassign_to = get_current_user_id();
        $deleted     = 0;

        foreach ( $users as $user ) {
            if ( (int) $user->ID !== $reassign_to ) {
                wp_delete_user( (int) $user->ID, $reassign_to );
                $deleted++;
            }
        }

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
     * Handle delete ALL high-risk users server-side.
     *
     * Scans users in batches and deletes those scoring above the threshold.
     *
     * @return void
     */
    public function handle_delete_all_high_risk(): void {
        $this->verify_request();

        if ( ! class_exists( 'WUAC_Spam_Score' ) ) {
            wp_send_json_error( array( 'message' => __( 'Spam scoring engine not available.', 'wp-user-audit-cleanup' ) ) );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $min_score   = isset( $_POST['min_score'] ) ? intval( $_POST['min_score'] ) : 70;
        $role        = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'all';
        $reassign_to = get_current_user_id();
        $deleted     = 0;

        $args = array(
            'fields' => 'ID',
            'number' => -1,
        );
        if ( 'all' !== $role ) {
            $args['role'] = $role;
        }

        $user_query = new WP_User_Query( $args );
        $user_ids   = $user_query->get_results();

        if ( ! empty( $user_ids ) ) {
            foreach ( $user_ids as $user_id ) {
                $user_id = (int) $user_id;
                if ( $user_id === $reassign_to ) {
                    continue;
                }

                $user = get_userdata( $user_id );
                if ( ! $user ) {
                    continue;
                }

                if ( WUAC_Spam_Score::calculate( $user ) >= $min_score ) {
                    wp_delete_user( $user_id, $reassign_to );
                    $deleted++;
                }
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted users */
                __( 'Successfully deleted %d high risk user(s).', 'wp-user-audit-cleanup' ),
                $deleted
            ),
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

    /**
     * Get user spam score and breakdown factors.
     *
     * @param WP_User $user The WordPress user object.
     * @return array Array containing 'score' and 'factors'.
     */
    private function get_user_spam_data( WP_User $user ): array {
        if ( ! class_exists( 'WUAC_Spam_Score' ) ) {
            return array( 'score' => 0, 'factors' => array() );
        }
        $score     = WUAC_Spam_Score::calculate( $user );
        $breakdown = WUAC_Spam_Score::get_breakdown( $user );
        $factors   = array();
        foreach ( $breakdown as $factor ) {
            if ( $factor['triggered'] ) {
                $factors[] = array(
                    'label'  => $factor['label'],
                    'points' => $factor['points'],
                );
            }
        }
        return array( 'score' => $score, 'factors' => $factors );
    }
}
