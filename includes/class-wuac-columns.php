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
        add_action( 'pre_user_query', array( $this, 'handle_column_sorting' ) );
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
                    
                    // Cache score in user meta to enable sorting.
                    update_user_meta( $user_id, '_wuac_spam_score', $score );

                    if ( $score >= 70 ) {
                        $level = 'high';
                    } elseif ( $score >= 40 ) {
                        $level = 'medium';
                    } else {
                        $level = 'low';
                    }

                    // Build tooltip with factor breakdown.
                    $breakdown = WUAC_Spam_Score::get_breakdown( $user );
                    $tooltip   = '';
                    foreach ( $breakdown as $factor ) {
                        if ( $factor['triggered'] ) {
                            $tooltip .= '<span class="wuac-factor--hit">✗ ' . esc_html( $factor['label'] ) . ' (+' . $factor['points'] . ')</span><br>';
                        }
                    }

                    if ( '' === $tooltip ) {
                        $tooltip = '<span class="wuac-factor--miss">✓ No risk factors detected</span>';
                    }

                    return '<span class="wuac-score wuac-score--' . esc_attr( $level ) . '">'
                        . esc_html( $score )
                        . '<span class="wuac-score-tooltip">' . $tooltip . '</span>'
                        . '</span>';
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

    /**
     * Handle sorting of user list by Last Login or Spam Score via pre_user_query.
     *
     * @param WP_User_Query $query The current WP_User_Query object.
     * @return void
     */
    public function handle_column_sorting( WP_User_Query $query ): void {
        global $wpdb;

        if ( ! is_admin() ) {
            return;
        }

        $orderby = $query->get( 'orderby' );
        $order   = strtoupper( $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        if ( 'wuac_last_login' === $orderby ) {
            $query->query_from    .= " LEFT JOIN {$wpdb->usermeta} AS wuac_login_meta ON ({$wpdb->users}.ID = wuac_login_meta.user_id AND wuac_login_meta.meta_key = '_wuac_last_login')";
            $query->query_orderby  = "ORDER BY wuac_login_meta.meta_value {$order}";
        }

        if ( 'wuac_spam_score' === $orderby ) {
            $query->query_from    .= " LEFT JOIN {$wpdb->usermeta} AS wuac_spam_meta ON ({$wpdb->users}.ID = wuac_spam_meta.user_id AND wuac_spam_meta.meta_key = '_wuac_spam_score')";
            $query->query_orderby  = "ORDER BY CAST(COALESCE(wuac_spam_meta.meta_value, 0) AS SIGNED) {$order}";
        }
    }
}
