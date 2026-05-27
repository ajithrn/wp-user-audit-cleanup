<?php
/**
 * User list filters — date ranges, login status, high risk, and disposable email.
 *
 * Renders filter controls above the users table and modifies WP_User_Query
 * to apply the selected filters.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Filters {

    /**
     * Validation errors collected during the current request.
     *
     * @var string[]
     */
    private array $errors = array();

    /**
     * Minimum risk score for custom filtering.
     */
    private int $filter_risk_min = 0;

    /**
     * Maximum risk score for custom filtering.
     */
    private int $filter_risk_max = 100;

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'restrict_manage_users', array( $this, 'render_filters' ) );
        add_action( 'pre_get_users', array( $this, 'apply_filters' ) );
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
    }

    /**
     * Render the filter row HTML above the users table.
     *
     * Only renders on the "top" position to avoid duplicate controls.
     *
     * @param string $which Position — "top" or "bottom".
     */
    public function render_filters( string $which ): void {
        if ( 'top' !== $which ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter values read for display; nonce not applicable to GET filters.
        $reg_from     = isset( $_REQUEST['wuac_reg_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_reg_from'] ) ) : '';
        $reg_to       = isset( $_REQUEST['wuac_reg_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_reg_to'] ) ) : '';
        $login_from   = isset( $_REQUEST['wuac_login_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_from'] ) ) : '';
        $login_to     = isset( $_REQUEST['wuac_login_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_to'] ) ) : '';
        $login_status = isset( $_REQUEST['wuac_login_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_status'] ) ) : 'all';

        $status_options = array(
            'all'              => __( 'All Users', 'wp-user-audit-cleanup' ),
            'never'            => __( 'Never Logged In', 'wp-user-audit-cleanup' ),
            'has_logged_in'    => __( 'Has Logged In', 'wp-user-audit-cleanup' ),
            'high_risk'        => __( 'High Risk (Score ≥ 70)', 'wp-user-audit-cleanup' ),
            'medium_risk'      => __( 'Medium Risk (Score 40-69)', 'wp-user-audit-cleanup' ),
            'low_risk'         => __( 'Low Risk (Score < 40)', 'wp-user-audit-cleanup' ),
            'disposable_email' => __( 'Disposable Email', 'wp-user-audit-cleanup' ),
        );

        ?>
        </div><!-- close default WP filter area to start our own row -->
        <div class="wuac-filter-row">
            <div class="wuac-filter-group">
                <label><?php esc_html_e( 'Registered', 'wp-user-audit-cleanup' ); ?></label>
                <div class="wuac-filter-date-range">
                    <input type="date" id="wuac_reg_from" name="wuac_reg_from" value="<?php echo esc_attr( $reg_from ); ?>" placeholder="<?php esc_attr_e( 'From', 'wp-user-audit-cleanup' ); ?>" />
                    <span class="wuac-date-separator">&ndash;</span>
                    <input type="date" id="wuac_reg_to" name="wuac_reg_to" value="<?php echo esc_attr( $reg_to ); ?>" placeholder="<?php esc_attr_e( 'To', 'wp-user-audit-cleanup' ); ?>" />
                </div>
            </div>

            <div class="wuac-filter-group">
                <label><?php esc_html_e( 'Last Login', 'wp-user-audit-cleanup' ); ?></label>
                <div class="wuac-filter-date-range">
                    <input type="date" id="wuac_login_from" name="wuac_login_from" value="<?php echo esc_attr( $login_from ); ?>" placeholder="<?php esc_attr_e( 'From', 'wp-user-audit-cleanup' ); ?>" />
                    <span class="wuac-date-separator">&ndash;</span>
                    <input type="date" id="wuac_login_to" name="wuac_login_to" value="<?php echo esc_attr( $login_to ); ?>" placeholder="<?php esc_attr_e( 'To', 'wp-user-audit-cleanup' ); ?>" />
                </div>
            </div>

            <div class="wuac-filter-group">
                <label for="wuac_login_status"><?php esc_html_e( 'Status', 'wp-user-audit-cleanup' ); ?></label>
                <select id="wuac_login_status" name="wuac_login_status">
                    <?php foreach ( $status_options as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $login_status, $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php submit_button( __( 'Filter', 'wp-user-audit-cleanup' ), 'secondary', 'wuac_filter_submit', false ); ?>
        </div>
        <div style="display:none;"><!-- reopen a div so WP's closing tag doesn't break -->
        <?php
    }

    /**
     * Modify WP_User_Query based on submitted filter values.
     *
     * @param WP_User_Query $query The user query being executed.
     */
    public function apply_filters( WP_User_Query $query ): void {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'users' !== $screen->id ) {
            return;
        }

        $this->apply_registration_date_filter( $query );
        $this->apply_last_login_filter( $query );
        $this->apply_login_status_filter( $query );
    }

    /**
     * Validate that a "from" date is not later than a "to" date.
     *
     * @param string $from The start date (Y-m-d).
     * @param string $to   The end date (Y-m-d).
     * @return bool|string True if valid, or an error message string.
     */
    public function validate_date_range( string $from, string $to ): bool|string {
        if ( '' === $from || '' === $to ) {
            return true;
        }

        if ( $from > $to ) {
            return sprintf(
                /* translators: %1$s: from date, %2$s: to date */
                __( "The 'From' date (%1\$s) must be earlier than or equal to the 'To' date (%2\$s).", 'wp-user-audit-cleanup' ),
                $from,
                $to
            );
        }

        return true;
    }

    /**
     * Display admin notices for any validation errors.
     */
    public function show_admin_notices(): void {
        foreach ( $this->errors as $message ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Apply registration date range filter.
     *
     * Uses WP_User_Query's date_query on the user_registered column.
     *
     * @param WP_User_Query $query The user query.
     */
    private function apply_registration_date_filter( WP_User_Query $query ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter values; nonce not applicable to GET filters.
        $from = isset( $_REQUEST['wuac_reg_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_reg_from'] ) ) : '';
        $to   = isset( $_REQUEST['wuac_reg_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_reg_to'] ) ) : '';

        if ( '' === $from && '' === $to ) {
            return;
        }

        $validation = $this->validate_date_range( $from, $to );
        if ( true !== $validation ) {
            $this->errors[] = __( "The 'Registered From' date must be earlier than or equal to the 'Registered To' date.", 'wp-user-audit-cleanup' );
            return;
        }

        $date_query = array();

        if ( '' !== $from ) {
            $date_query[] = array(
                'column'    => 'user_registered',
                'after'     => $from,
                'inclusive' => true,
            );
        }

        if ( '' !== $to ) {
            $date_query[] = array(
                'column'    => 'user_registered',
                'before'    => $to . ' 23:59:59',
                'inclusive' => true,
            );
        }

        $query->set( 'date_query', $date_query );
    }

    /**
     * Apply last login date range filter via meta_query.
     *
     * @param WP_User_Query $query The user query.
     */
    private function apply_last_login_filter( WP_User_Query $query ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter values; nonce not applicable to GET filters.
        $from = isset( $_REQUEST['wuac_login_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_from'] ) ) : '';
        $to   = isset( $_REQUEST['wuac_login_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_to'] ) ) : '';

        if ( '' === $from && '' === $to ) {
            return;
        }

        $validation = $this->validate_date_range( $from, $to );
        if ( true !== $validation ) {
            $this->errors[] = __( "The 'Last Login From' date must be earlier than or equal to the 'Last Login To' date.", 'wp-user-audit-cleanup' );
            return;
        }

        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }

        if ( '' !== $from ) {
            $meta_query[] = array(
                'key'     => '_wuac_last_login',
                'value'   => $from . ' 00:00:00',
                'compare' => '>=',
                'type'    => 'DATETIME',
            );
        }

        if ( '' !== $to ) {
            $meta_query[] = array(
                'key'     => '_wuac_last_login',
                'value'   => $to . ' 23:59:59',
                'compare' => '<=',
                'type'    => 'DATETIME',
            );
        }

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Apply login status dropdown filter.
     *
     * Handles: never, has_logged_in, high_risk, disposable_email.
     *
     * @param WP_User_Query $query The user query.
     */
    private function apply_login_status_filter( WP_User_Query $query ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter values; nonce not applicable to GET filters.
        $status = isset( $_REQUEST['wuac_login_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wuac_login_status'] ) ) : 'all';

        if ( 'all' === $status ) {
            return;
        }

        switch ( $status ) {
            case 'never':
                $this->filter_never_logged_in( $query );
                break;

            case 'has_logged_in':
                $this->filter_has_logged_in( $query );
                break;

            case 'high_risk':
                $this->filter_by_risk_score( $query, 70, 100 );
                break;

            case 'medium_risk':
                $this->filter_by_risk_score( $query, 40, 69 );
                break;

            case 'low_risk':
                $this->filter_by_risk_score( $query, 0, 39 );
                break;

            case 'disposable_email':
                $this->filter_disposable_email( $query );
                break;
        }
    }

    /**
     * Filter to users who have never logged in (meta does not exist).
     *
     * @param WP_User_Query $query The user query.
     */
    private function filter_never_logged_in( WP_User_Query $query ): void {
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }

        $meta_query[] = array(
            'key'     => '_wuac_last_login',
            'compare' => 'NOT EXISTS',
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Filter to users who have logged in at least once (meta exists).
     *
     * @param WP_User_Query $query The user query.
     */
    private function filter_has_logged_in( WP_User_Query $query ): void {
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }

        $meta_query[] = array(
            'key'     => '_wuac_last_login',
            'compare' => 'EXISTS',
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Set limits and hook risk score query modifier.
     *
     * @param WP_User_Query $query The user query.
     * @param int           $min   Minimum score.
     * @param int           $max   Maximum score.
     */
    private function filter_by_risk_score( WP_User_Query $query, int $min, int $max ): void {
        $this->filter_risk_min = $min;
        $this->filter_risk_max = $max;
        add_action( 'pre_user_query', array( $this, 'modify_query_for_risk_score' ) );
    }

    /**
     * Modify the SQL query to restrict results to users within the chosen risk score range.
     *
     * Processes users in batches to avoid memory exhaustion on large sites,
     * computes spam scores, and injects an IN clause.
     *
     * @param WP_User_Query $query The user query (passed by reference).
     */
    public function modify_query_for_risk_score( WP_User_Query $query ): void {
        // Remove this callback to prevent recursion.
        remove_action( 'pre_user_query', array( $this, 'modify_query_for_risk_score' ) );

        if ( ! class_exists( 'WUAC_Spam_Score' ) ) {
            return;
        }

        global $wpdb;

        // 1. Get user IDs matching the range from the cached meta table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $meta_matching_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wuac_spam_score' AND CAST(meta_value AS SIGNED) BETWEEN %d AND %d",
            $this->filter_risk_min,
            $this->filter_risk_max
        ) );

        $matching_ids = array_map( 'intval', $meta_matching_ids );

        // 2. Find any users who don't have a cached score yet.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $uncached_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->users} WHERE ID NOT IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_wuac_spam_score')"
        );

        if ( ! empty( $uncached_ids ) ) {
            foreach ( $uncached_ids as $user_id ) {
                $user = get_userdata( (int) $user_id );
                if ( ! $user ) {
                    continue;
                }
                $score = WUAC_Spam_Score::calculate( $user );
                update_user_meta( $user_id, '_wuac_spam_score', $score );
                if ( $score >= $this->filter_risk_min && $score <= $this->filter_risk_max ) {
                    $matching_ids[] = (int) $user_id;
                }
            }
        }

        if ( empty( $matching_ids ) ) {
            // Force empty result set.
            $query->query_where .= ' AND 1=0';
            return;
        }

        $ids_placeholder = implode( ',', $matching_ids );
        $query->query_where .= " AND {$wpdb->users}.ID IN ({$ids_placeholder})";
    }

    /**
     * Filter to users with disposable email domains.
     *
     * Uses pre_user_query to add LIKE conditions matching disposable domains.
     *
     * @param WP_User_Query $query The user query.
     */
    private function filter_disposable_email( WP_User_Query $query ): void {
        add_action( 'pre_user_query', array( $this, 'modify_query_for_disposable_email' ) );
    }

    /**
     * Modify the SQL query to restrict results to users with disposable email domains.
     *
     * @param WP_User_Query $query The user query (passed by reference).
     */
    public function modify_query_for_disposable_email( WP_User_Query $query ): void {
        // Remove this callback to prevent recursion.
        remove_action( 'pre_user_query', array( $this, 'modify_query_for_disposable_email' ) );

        $domains = $this->get_disposable_domains();

        if ( empty( $domains ) ) {
            return;
        }

        global $wpdb;

        $like_clauses = array();
        foreach ( $domains as $domain ) {
            $like_clauses[] = $wpdb->prepare(
                "{$wpdb->users}.user_email LIKE %s",
                '%@' . $wpdb->esc_like( $domain )
            );
        }

        $query->query_where .= ' AND (' . implode( ' OR ', $like_clauses ) . ')';
    }

    /**
     * Get the effective list of disposable email domains.
     *
     * Delegates to WUAC_Disposable_Domains when available, otherwise
     * falls back to the bundled list.
     *
     * @return string[]
     */
    private function get_disposable_domains(): array {
        if ( class_exists( 'WUAC_Disposable_Domains' ) && method_exists( 'WUAC_Disposable_Domains', 'get_domains' ) ) {
            return WUAC_Disposable_Domains::get_domains();
        }

        $file = WUAC_PLUGIN_DIR . 'data/disposable-domains.php';
        return file_exists( $file ) ? include $file : array();
    }
}
