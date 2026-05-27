<?php
/**
 * WUAC_Settings
 *
 * Settings page with disposable domain management and plugin data erasure.
 * Registered under the Users menu in the WordPress admin sidebar.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Settings {

    /**
     * NOTE: Settings UI is now handled by WUAC_Admin_Page (unified dashboard).
     * This class retains its form-handling methods for backward compatibility.
     */

    /**
     * Register the settings page under the Users menu.
     *
     * @return void
     */
    public function register_page(): void {
        add_users_page(
            __( 'User Audit Settings', 'wp-user-audit-cleanup' ),
            __( 'Audit Settings', 'wp-user-audit-cleanup' ),
            'manage_options',
            'wuac-settings',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the settings page.
     *
     * Contains two sections:
     * 1. Disposable Domain Management
     * 2. Erase Plugin Data
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'wp-user-audit-cleanup' ) );
        }

        $notice = '';
        $type   = 'success';

        // Handle Add Domain.
        if ( isset( $_POST['wuac_add_domain_submit'] ) ) {
            if ( ! isset( $_POST['_wuac_add_domain_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_add_domain_nonce'], 'wuac_add_domain' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            $domain = isset( $_POST['wuac_new_domain'] ) ? sanitize_text_field( $_POST['wuac_new_domain'] ) : '';

            if ( '' === $domain ) {
                $notice = __( 'Please enter a domain name.', 'wp-user-audit-cleanup' );
                $type   = 'error';
            } elseif ( WUAC_Disposable_Domains::add_domain( $domain ) ) {
                $notice = sprintf(
                    /* translators: %s: domain name */
                    __( 'Domain "%s" added successfully.', 'wp-user-audit-cleanup' ),
                    esc_html( $domain )
                );
            } else {
                $notice = sprintf(
                    /* translators: %s: domain name */
                    __( 'Domain "%s" already exists in the list.', 'wp-user-audit-cleanup' ),
                    esc_html( $domain )
                );
                $type = 'warning';
            }
        }

        // Handle Remove Domain.
        if ( isset( $_POST['wuac_remove_domain_submit'] ) ) {
            if ( ! isset( $_POST['_wuac_remove_domain_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_remove_domain_nonce'], 'wuac_remove_domain' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            $domain = isset( $_POST['wuac_remove_domain'] ) ? sanitize_text_field( $_POST['wuac_remove_domain'] ) : '';

            if ( WUAC_Disposable_Domains::remove_domain( $domain ) ) {
                $notice = sprintf(
                    /* translators: %s: domain name */
                    __( 'Domain "%s" removed successfully.', 'wp-user-audit-cleanup' ),
                    esc_html( $domain )
                );
            } else {
                $notice = sprintf(
                    /* translators: %s: domain name */
                    __( 'Could not remove domain "%s".', 'wp-user-audit-cleanup' ),
                    esc_html( $domain )
                );
                $type = 'error';
            }
        }

        // Handle Scan Existing Users.
        if ( isset( $_POST['wuac_scan_users_submit'] ) ) {
            $this->handle_scan_users();
            return; // handle_scan_users redirects after completion.
        }

        // Handle Erase Plugin Data.
        if ( isset( $_POST['wuac_erase_data_submit'] ) ) {
            $this->handle_erase_data();
            return; // handle_erase_data redirects after completion.
        }

        // Check for erase success message via query param.
        $erase_notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for success message.
        if ( isset( $_GET['wuac_erased'] ) && '1' === $_GET['wuac_erased'] ) {
            $erase_notice = __( 'All plugin data has been erased successfully.', 'wp-user-audit-cleanup' );
        }

        // Check for scan success message via query params.
        $scan_notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for success message.
        if ( isset( $_GET['wuac_scanned'] ) && '1' === $_GET['wuac_scanned'] ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $backfilled = isset( $_GET['wuac_backfilled'] ) ? intval( $_GET['wuac_backfilled'] ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $flagged    = isset( $_GET['wuac_flagged'] ) ? intval( $_GET['wuac_flagged'] ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $scanned    = isset( $_GET['wuac_scan_total'] ) ? intval( $_GET['wuac_scan_total'] ) : 0;
            $scan_notice = sprintf(
                /* translators: 1: scanned count, 2: backfilled count, 3: flagged count */
                __( 'Scan complete. %1$d users scanned, %2$d login records backfilled, %3$d users auto-flagged as spam.', 'wp-user-audit-cleanup' ),
                $scanned,
                $backfilled,
                $flagged
            );
        }

        $domains = WUAC_Disposable_Domains::get_domains();
        sort( $domains );

        // Pagination for domain list.
        $per_page     = 50;
        $total        = count( $domains );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination parameter, no data modification.
        $current_page = isset( $_GET['domain_paged'] ) ? max( 1, intval( $_GET['domain_paged'] ) ) : 1;
        $total_pages  = max( 1, (int) ceil( $total / $per_page ) );
        $current_page = min( $current_page, $total_pages );
        $offset       = ( $current_page - 1 ) * $per_page;
        $paged_domains = array_slice( $domains, $offset, $per_page );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'User Audit & Cleanup Settings', 'wp-user-audit-cleanup' ); ?></h1>

            <?php if ( '' !== $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( '' !== $erase_notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $erase_notice ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( '' !== $scan_notice ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $scan_notice ); ?></p>
                </div>
            <?php endif; ?>


            <!-- Section 1: Disposable Domain Management -->
            <div class="wuac-card">
                <h2><?php esc_html_e( 'Disposable Domain Management', 'wp-user-audit-cleanup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Manage the list of disposable email domains used for spam detection. Users registering with these domains are automatically flagged as spam.', 'wp-user-audit-cleanup' ); ?></p>

                <!-- Add Domain Form -->
                <form method="post" class="wuac-domain-add-form">
                    <?php wp_nonce_field( 'wuac_add_domain', '_wuac_add_domain_nonce' ); ?>
                    <div class="wuac-filter-group">
                        <label for="wuac_new_domain"><?php esc_html_e( 'Add Domain', 'wp-user-audit-cleanup' ); ?></label>
                        <input type="text" id="wuac_new_domain" name="wuac_new_domain" placeholder="example.com" class="regular-text" />
                    </div>
                    <input type="submit" name="wuac_add_domain_submit" class="button button-primary" value="<?php esc_attr_e( 'Add Domain', 'wp-user-audit-cleanup' ); ?>" />
                </form>

                <!-- Domain List -->
                <p>
                    <?php
                    printf(
                        /* translators: %d: total number of domains */
                        esc_html__( 'Total domains: %d', 'wp-user-audit-cleanup' ),
                        $total
                    );
                    ?>
                </p>

                <?php if ( $total > 0 ) : ?>
                    <div class="wuac-domain-list-container">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Domain', 'wp-user-audit-cleanup' ); ?></th>
                                    <th style="width: 120px;"><?php esc_html_e( 'Action', 'wp-user-audit-cleanup' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $paged_domains as $d ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $d ); ?></td>
                                        <td>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field( 'wuac_remove_domain', '_wuac_remove_domain_nonce' ); ?>
                                                <input type="hidden" name="wuac_remove_domain" value="<?php echo esc_attr( $d ); ?>" />
                                                <input type="submit" name="wuac_remove_domain_submit" class="button button-small" value="<?php esc_attr_e( 'Remove', 'wp-user-audit-cleanup' ); ?>" />
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="tablenav" style="margin-top: 10px;">
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php
                                    printf(
                                        /* translators: %d: total number of domains */
                                        esc_html__( '%d items', 'wp-user-audit-cleanup' ),
                                        $total
                                    );
                                    ?>
                                </span>
                                <?php
                                $page_links = paginate_links( array(
                                    'base'      => add_query_arg( 'domain_paged', '%#%' ),
                                    'format'    => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total'     => $total_pages,
                                    'current'   => $current_page,
                                ) );
                                if ( $page_links ) {
                                    echo $page_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe HTML.
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <p><em><?php esc_html_e( 'No disposable domains configured.', 'wp-user-audit-cleanup' ); ?></em></p>
                <?php endif; ?>
            </div><!-- .wuac-card -->

            <!-- Section 2: Scan Existing Users -->
            <div class="wuac-card">
                <h2><?php esc_html_e( 'Scan Existing Users', 'wp-user-audit-cleanup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Scan all existing users to detect spam accounts. This checks WordPress session tokens to determine if users have ever logged in, backfills login data for those who have, and auto-flags users with a spam score of 70 or higher.', 'wp-user-audit-cleanup' ); ?></p>

                <form method="post">
                    <?php wp_nonce_field( 'wuac_scan_users', '_wuac_scan_users_nonce' ); ?>
                    <input
                        type="submit"
                        name="wuac_scan_users_submit"
                        class="button button-primary"
                        value="<?php esc_attr_e( 'Scan Users', 'wp-user-audit-cleanup' ); ?>"
                        onclick="return confirm('<?php echo esc_js( __( 'This will scan all users, backfill login data from session tokens, and auto-flag high-risk accounts. Continue?', 'wp-user-audit-cleanup' ) ); ?>');"
                    />
                </form>
            </div><!-- .wuac-card -->

            <!-- Section 3: Erase Plugin Data -->
            <div class="wuac-card wuac-danger-zone">
                <h2><?php esc_html_e( 'Erase Plugin Data', 'wp-user-audit-cleanup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Remove all data created by this plugin from the database. This includes all last login timestamps and spam flags stored in user meta. This action cannot be undone.', 'wp-user-audit-cleanup' ); ?></p>

                <form method="post" id="wuac-erase-form">
                    <?php wp_nonce_field( 'wuac_erase_data', '_wuac_erase_data_nonce' ); ?>
                    <input
                        type="submit"
                        name="wuac_erase_data_submit"
                        class="button button-link-delete"
                        value="<?php esc_attr_e( 'Erase Plugin Data', 'wp-user-audit-cleanup' ); ?>"
                        onclick="return confirm('<?php echo esc_js( __( 'This will permanently remove all WordPress User Audit & Cleanup data. Continue?', 'wp-user-audit-cleanup' ) ); ?>');"
                    />
                </form>
            </div><!-- .wuac-card .wuac-danger-zone -->
        </div>
        <?php
    }

    /**
     * Handle the "Scan Existing Users" action.
     *
     * Runs the user scanner to backfill login data and auto-flag spam.
     * Redirects back with result counts.
     *
     * @return void
     */
    public function handle_scan_users(): void {
        if ( ! isset( $_POST['_wuac_scan_users_nonce'] ) ||
             ! wp_verify_nonce( $_POST['_wuac_scan_users_nonce'], 'wuac_scan_users' ) ) {
            wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
        }

        $result = WUAC_User_Scanner::scan();

        wp_safe_redirect( add_query_arg(
            array(
                'wuac_scanned'    => '1',
                'wuac_backfilled' => $result['backfilled'],
                'wuac_flagged'    => $result['flagged'],
                'wuac_scan_total' => $result['scanned'],
            ),
            admin_url( 'users.php?page=wuac-settings' )
        ) );
        exit;
    }

    /**
     * Handle the "Erase Plugin Data" action.
     *
     * Deletes all _wuac_last_login and _wuac_spam_flag meta entries from wp_usermeta.
     * Verifies nonce and capability before proceeding, then redirects back with a success flag.
     *
     * @return void
     */
    public function handle_erase_data(): void {
        if ( ! isset( $_POST['_wuac_erase_data_nonce'] ) ||
             ! wp_verify_nonce( $_POST['_wuac_erase_data_nonce'], 'wuac_erase_data' ) ) {
            wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
        }

        global $wpdb;

        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_last_login' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_wuac_spam_flag' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

        wp_safe_redirect( add_query_arg( 'wuac_erased', '1', admin_url( 'users.php?page=wuac-settings' ) ) );
        exit;
    }
}
