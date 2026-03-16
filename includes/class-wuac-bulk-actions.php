<?php
/**
 * Bulk actions for spam flagging/unflagging and the "Spam" view on the users list.
 *
 * Adds "Flag as Spam" and "Unflag Spam" bulk actions, a "Spam (N)" view link,
 * spam view filtering, and a "Delete Spam Users" button with JS confirmation.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Bulk_Actions {

    /**
     * Register hooks.
     */
    public function init(): void {
        add_filter( 'bulk_actions-users', array( $this, 'add_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'views_users', array( $this, 'add_spam_view' ) );
        add_action( 'pre_get_users', array( $this, 'filter_spam_view' ) );
        add_action( 'load-users.php', array( $this, 'handle_delete_all_spam' ) );
        add_action( 'restrict_manage_users', array( $this, 'render_delete_spam_button' ) );
        add_action( 'admin_footer-users.php', array( $this, 'render_confirmation_script' ) );
    }

    /**
     * Add "Flag as Spam" and "Unflag Spam" to the bulk actions dropdown.
     *
     * @param array $actions Existing bulk actions.
     * @return array Modified bulk actions.
     */
    public function add_bulk_actions( array $actions ): array {
        $actions['wuac_flag_spam']   = __( 'Flag as Spam', 'wp-user-audit-cleanup' );
        $actions['wuac_unflag_spam'] = __( 'Unflag Spam', 'wp-user-audit-cleanup' );
        return $actions;
    }

    /**
     * Process "Flag as Spam" and "Unflag Spam" bulk actions.
     *
     * @param string $redirect_to The redirect URL.
     * @param string $doaction    The action being taken.
     * @param array  $user_ids    The users to act on.
     * @return string The redirect URL.
     */
    public function handle_bulk_actions( string $redirect_to, string $doaction, array $user_ids ): string {
        if ( 'wuac_flag_spam' === $doaction ) {
            foreach ( $user_ids as $user_id ) {
                update_user_meta( (int) $user_id, '_wuac_spam_flag', '1' );
            }
            $redirect_to = add_query_arg( 'wuac_flagged', count( $user_ids ), $redirect_to );
        }

        if ( 'wuac_unflag_spam' === $doaction ) {
            foreach ( $user_ids as $user_id ) {
                delete_user_meta( (int) $user_id, '_wuac_spam_flag' );
            }
            $redirect_to = add_query_arg( 'wuac_unflagged', count( $user_ids ), $redirect_to );
        }

        return $redirect_to;
    }

    /**
     * Add "Spam (N)" view link to the users list views.
     *
     * @param array $views Existing view links.
     * @return array Modified view links.
     */
    public function add_spam_view( array $views ): array {
        $count   = $this->get_spam_user_count();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View link check, no data modification.
        $current = ( isset( $_GET['wuac_view'] ) && 'spam' === $_GET['wuac_view'] ) ? 'current' : '';
        $url     = add_query_arg( 'wuac_view', 'spam', admin_url( 'users.php' ) );

        $views['wuac_spam'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url( $url ),
            esc_attr( $current ),
            esc_html__( 'Spam', 'wp-user-audit-cleanup' ),
            $count
        );

        return $views;
    }

    /**
     * Get the count of users flagged as spam.
     *
     * @return int Number of spam-flagged users.
     */
    public function get_spam_user_count(): int {
        $query = new WP_User_Query( array(
            'meta_key'    => '_wuac_spam_flag', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'count_total' => true,
            'number'      => 0,
            'fields'      => 'ID',
        ) );

        return (int) $query->get_total();
    }

    /**
     * Filter the user query when viewing the spam view.
     *
     * @param WP_User_Query $query The user query.
     */
    public function filter_spam_view( WP_User_Query $query ): void {
        if ( ! is_admin() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View filter check, no data modification.
        if ( ! isset( $_GET['wuac_view'] ) || 'spam' !== $_GET['wuac_view'] ) {
            return;
        }

        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }

        $meta_query[] = array(
            'key'     => '_wuac_spam_flag',
            'value'   => '1',
            'compare' => '=',
        );

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Handle the "Delete Spam Users" POST action.
     *
     * Hooked to `load-users.php` so it runs before any output.
     * Verifies nonce, deletes all flagged users, reassigns content to current admin.
     */
    public function handle_delete_all_spam(): void {
        if ( ! isset( $_POST['wuac_delete_all_spam'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
            return;
        }

        if ( ! isset( $_POST['_wuac_delete_spam_nonce'] ) ||
             ! wp_verify_nonce( $_POST['_wuac_delete_spam_nonce'], 'wuac_delete_all_spam' ) ) {
            wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
        }

        $reassign_to = get_current_user_id();

        $spam_users = new WP_User_Query( array(
            'meta_key'   => '_wuac_spam_flag', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'fields'     => 'ID',
            'number'     => 0,
        ) );

        $deleted = 0;
        if ( ! empty( $spam_users->get_results() ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ( $spam_users->get_results() as $user_id ) {
                if ( (int) $user_id !== $reassign_to ) {
                    wp_delete_user( (int) $user_id, $reassign_to );
                    $deleted++;
                }
            }
        }

        $redirect = add_query_arg(
            array(
                'wuac_view'    => 'spam',
                'wuac_deleted' => $deleted,
            ),
            admin_url( 'users.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render the "Delete Spam Users" button when viewing the spam view.
     *
     * @param string $which Position — "top" or "bottom".
     */
    public function render_delete_spam_button( string $which ): void {
        if ( 'top' !== $which ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View check for button display.
        if ( ! isset( $_GET['wuac_view'] ) || 'spam' !== $_GET['wuac_view'] ) {
            return;
        }

        $count = $this->get_spam_user_count();
        ?>
        <div class="wuac-spam-actions">
            <form method="post" style="display:inline-block;">
                <?php wp_nonce_field( 'wuac_delete_all_spam', '_wuac_delete_spam_nonce' ); ?>
                <button type="submit"
                        name="wuac_delete_all_spam"
                        value="1"
                        class="button button-link-delete wuac-delete-spam-btn"
                        data-count="<?php echo esc_attr( $count ); ?>">
                    <?php esc_html_e( 'Delete Spam Users', 'wp-user-audit-cleanup' ); ?>
                </button>
            </form>
            <?php
            $export_url = wp_nonce_url(
                add_query_arg( 'wuac_export_csv', '1', admin_url( 'users.php' ) ),
                'wuac_export_csv',
                '_wuac_export_nonce'
            );
            ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">
                <?php esc_html_e( 'Export CSV', 'wp-user-audit-cleanup' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render JavaScript confirmation dialog for the "Delete Spam Users" button.
     */
    public function render_confirmation_script(): void {
        ?>
        <script>
        (function() {
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.wuac-delete-spam-btn');
                if (!btn) return;
                var count = btn.getAttribute('data-count') || '0';
                var msg = 'Are you sure you want to delete all ' + count + ' flagged spam users? This action cannot be undone.';
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            });
        })();
        </script>
        <?php
    }
}
