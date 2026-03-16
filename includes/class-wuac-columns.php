<?php
/**
 * WUAC_Columns
 *
 * Adds "Last Login" and "Spam Score" custom columns to the Admin Users list,
 * renders their values, and makes them sortable.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Columns {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_filter( 'manage_users_columns', array( $this, 'add_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
        add_filter( 'manage_users_sortable_columns', array( $this, 'sortable_columns' ) );
    }

    /**
     * Add "Last Login" and "Spam Score" columns to the users table.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_columns( array $columns ): array {
        $columns['wuac_last_login'] = __( 'Last Login', 'wp-user-audit-cleanup' );
        $columns['wuac_spam_score'] = __( 'Spam Score', 'wp-user-audit-cleanup' );
        return $columns;
    }

    /**
     * Render the value for custom columns.
     *
     * @param string $value       Current column value (empty for custom columns).
     * @param string $column_name The column slug.
     * @param int    $user_id     The user ID for the current row.
     * @return string The rendered column content.
     */
    public function render_column( string $value, string $column_name, int $user_id ): string {
        if ( 'wuac_last_login' === $column_name ) {
            $last_login = get_user_meta( $user_id, '_wuac_last_login', true );

            if ( empty( $last_login ) ) {
                return '<span class="wuac-last-login--never">' . esc_html__( 'Never', 'wp-user-audit-cleanup' ) . '</span>';
            }

            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
            return date_i18n( $format, strtotime( $last_login ) );
        }

        if ( 'wuac_spam_score' === $column_name ) {
            if ( class_exists( 'WUAC_Spam_Score' ) ) {
                $user = get_userdata( $user_id );
                if ( $user instanceof WP_User ) {
                    $score = WUAC_Spam_Score::calculate( $user );
                    if ( $score >= 70 ) {
                        $level = 'high';
                    } elseif ( $score >= 40 ) {
                        $level = 'medium';
                    } else {
                        $level = 'low';
                    }
                    return '<span class="wuac-score wuac-score--' . esc_attr( $level ) . '">' . esc_html( $score ) . '</span>';
                }
            }
            return __( 'N/A', 'wp-user-audit-cleanup' );
        }

        return $value;
    }

    /**
     * Make "Last Login" and "Spam Score" columns sortable.
     *
     * @param array $columns Existing sortable columns.
     * @return array Modified sortable columns.
     */
    public function sortable_columns( array $columns ): array {
        $columns['wuac_last_login'] = 'wuac_last_login';
        $columns['wuac_spam_score'] = 'wuac_spam_score';
        return $columns;
    }
}
