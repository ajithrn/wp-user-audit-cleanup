<?php
/**
 * CSV export for flagged spam users.
 *
 * Intercepts a CSV download request on admin_init, queries spam-flagged users,
 * and streams a CSV file to the browser.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Export {

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_export' ) );
    }

    /**
     * Handle the CSV export request.
     *
     * Checks for the `wuac_export_csv` GET param and a valid nonce,
     * then either streams the CSV or redirects back with a notice.
     */
    public function handle_export(): void {
        if ( ! isset( $_GET['wuac_export_csv'] ) ) {
            return;
        }

        if ( ! isset( $_GET['_wuac_export_nonce'] ) ||
             ! wp_verify_nonce( $_GET['_wuac_export_nonce'], 'wuac_export_csv' ) ) {
            wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
        }

        $users = $this->get_spam_users();

        if ( empty( $users ) ) {
            set_transient( 'wuac_export_notice', __( 'No spam users to export.', 'wp-user-audit-cleanup' ), 30 );
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'users.php?wuac_view=spam' ) );
            exit;
        }

        $csv      = $this->generate_csv( $users );
        $filename = 'spam-users-report-' . gmdate( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Query users flagged as spam.
     *
     * @return WP_User[] Array of WP_User objects with _wuac_spam_flag = 1.
     */
    public function get_spam_users(): array {
        $query = new WP_User_Query( array(
            'meta_key'   => '_wuac_spam_flag', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'number'     => 0,
        ) );

        return $query->get_results();
    }

    /**
     * Build a CSV string from an array of WP_User objects.
     *
     * Columns: User ID, Username, Email, Display Name, Registration Date,
     * Last Login, Spam Score.
     *
     * @param WP_User[] $users Array of user objects.
     * @return string Complete CSV content.
     */
    public function generate_csv( array $users ): string {
        $handle = fopen( 'php://temp', 'r+' );

        fputcsv( $handle, array(
            'User ID',
            'Username',
            'Email',
            'Display Name',
            'Registration Date',
            'Last Login',
            'Spam Score',
        ) );

        foreach ( $users as $user ) {
            $last_login = get_user_meta( $user->ID, '_wuac_last_login', true );
            $last_login_display = '' !== $last_login
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_login ) )
                : 'Never';

            $spam_score = class_exists( 'WUAC_Spam_Score' )
                ? WUAC_Spam_Score::calculate( $user )
                : 0;

            fputcsv( $handle, array(
                $user->ID,
                $user->user_login,
                $user->user_email,
                $user->display_name,
                $user->user_registered,
                $last_login_display,
                $spam_score,
            ) );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }
}
