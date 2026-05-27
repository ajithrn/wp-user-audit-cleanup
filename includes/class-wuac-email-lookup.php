<?php
/**
 * Spam Email Lookup admin page.
 *
 * Provides a dedicated admin page under Users for bulk email matching
 * against the wp_users table, with options to delete matched accounts.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Email_Lookup {

    /**
     * Maximum number of email addresses allowed per lookup.
     */
    const MAX_EMAILS = 5000;

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ) );
    }

    /**
     * Register the "Spam Email Lookup" page under the Users menu.
     */
    public function register_page(): void {
        add_users_page(
            __( 'Spam Email Lookup', 'wp-user-audit-cleanup' ),
            __( 'Spam Email Lookup', 'wp-user-audit-cleanup' ),
            'manage_options',
            'wuac-email-lookup',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the Spam Email Lookup admin page.
     *
     * Handles form display, email lookup results, and delete actions.
     * Structured to allow additional sections (e.g., Inactive Cleanup in Task 10.2).
     */
    public function render_page(): void {
        $notice  = '';
        $type    = 'error';
        $results = null;

        // --- Inactive Cleanup variables ---
        $inactive_notice  = '';
        $inactive_type    = 'error';
        $inactive_users   = null;
        $inactive_days    = isset( $_POST['wuac_inactive_days'] ) ? intval( $_POST['wuac_inactive_days'] ) : 30;

        // Handle "Delete All Inactive" POST action.
        if ( isset( $_POST['wuac_delete_inactive'] ) ) {
            if ( ! isset( $_POST['_wuac_inactive_cleanup_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_inactive_cleanup_nonce'], 'wuac_inactive_cleanup' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
            }

            if ( $inactive_days < 1 ) {
                $inactive_notice = __( 'Please enter a value of at least 1 day.', 'wp-user-audit-cleanup' );
            } else {
                $cleanup = new WUAC_Inactive_Cleanup();
                $deleted = $cleanup->delete_inactive_users( $inactive_days, get_current_user_id() );
                $inactive_notice = sprintf(
                    /* translators: %d: number of deleted users */
                    __( 'Successfully deleted %d inactive user(s).', 'wp-user-audit-cleanup' ),
                    $deleted
                );
                $inactive_type = 'success';
            }
        }

        // Handle "Find Inactive Users" POST action.
        if ( isset( $_POST['wuac_find_inactive'] ) ) {
            if ( ! isset( $_POST['_wuac_inactive_cleanup_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_inactive_cleanup_nonce'], 'wuac_inactive_cleanup' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            if ( $inactive_days < 1 ) {
                $inactive_notice = __( 'Please enter a value of at least 1 day.', 'wp-user-audit-cleanup' );
            } else {
                $cleanup        = new WUAC_Inactive_Cleanup();
                $inactive_users = $cleanup->find_inactive_users( $inactive_days );
            }
        }

        // Handle "Delete Selected" POST action.
        if ( isset( $_POST['wuac_delete_selected'] ) ) {
            if ( ! isset( $_POST['_wuac_email_lookup_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_email_lookup_nonce'], 'wuac_email_lookup' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'You do not have permission to perform this action.', 'wp-user-audit-cleanup' ) );
            }

            $selected = isset( $_POST['wuac_selected_users'] ) ? array_map( 'intval', $_POST['wuac_selected_users'] ) : array();

            if ( ! empty( $selected ) ) {
                $deleted = $this->delete_users( $selected, get_current_user_id() );
                $notice  = sprintf(
                    /* translators: %d: number of deleted users */
                    __( 'Successfully deleted %d user(s).', 'wp-user-audit-cleanup' ),
                    $deleted
                );
                $type = 'success';
            } else {
                $notice = __( 'No users were selected for deletion.', 'wp-user-audit-cleanup' );
            }
        }

        // Handle email lookup POST action.
        $raw_input = '';
        if ( isset( $_POST['wuac_email_lookup'] ) ) {
            if ( ! isset( $_POST['_wuac_email_lookup_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['_wuac_email_lookup_nonce'], 'wuac_email_lookup' ) ) {
                wp_die( __( 'Security check failed.', 'wp-user-audit-cleanup' ) );
            }

            $raw_input = isset( $_POST['wuac_email_list'] ) ? sanitize_textarea_field( $_POST['wuac_email_list'] ) : '';
            $results   = $this->process_email_lookup( $raw_input );

            if ( is_string( $results ) ) {
                $notice  = $results;
                $results = null;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Spam Email Lookup', 'wp-user-audit-cleanup' ); ?></h1>

            <?php if ( ! empty( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Email Lookup Section -->
            <div class="wuac-card wuac-email-lookup-section">
                <h2><?php esc_html_e( 'Email Lookup', 'wp-user-audit-cleanup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Paste a list of suspected spam email addresses to find matching user accounts.', 'wp-user-audit-cleanup' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'wuac_email_lookup', '_wuac_email_lookup_nonce' ); ?>
                    <p>
                        <label for="wuac_email_list">
                            <?php esc_html_e( 'Enter email addresses (one per line):', 'wp-user-audit-cleanup' ); ?>
                        </label>
                    </p>
                    <textarea id="wuac_email_list"
                              name="wuac_email_list"
                              rows="10"
                              cols="60"
                              class="large-text"
                              placeholder="<?php esc_attr_e( 'user1@example.com&#10;user2@example.com', 'wp-user-audit-cleanup' ); ?>"
                    ><?php echo esc_textarea( $raw_input ); ?></textarea>
                    <p>
                        <?php
                        submit_button(
                            __( 'Look Up Emails', 'wp-user-audit-cleanup' ),
                            'primary',
                            'wuac_email_lookup',
                            false
                        );
                        ?>
                    </p>
                </form>

                <?php if ( null !== $results ) : ?>
                    <div class="wuac-results-summary">
                        <span class="wuac-stat wuac-stat--matched">
                            <?php
                            printf(
                                /* translators: %d: matched count */
                                esc_html__( '%d matched', 'wp-user-audit-cleanup' ),
                                count( $results['matched'] )
                            );
                            ?>
                        </span>
                        <span class="wuac-stat wuac-stat--unmatched">
                            <?php
                            printf(
                                /* translators: %d: unmatched count */
                                esc_html__( '%d unmatched', 'wp-user-audit-cleanup' ),
                                $results['unmatched_count']
                            );
                            ?>
                        </span>
                    </div>

                    <?php if ( ! empty( $results['matched'] ) ) : ?>
                        <form method="post">
                            <?php wp_nonce_field( 'wuac_email_lookup', '_wuac_email_lookup_nonce' ); ?>
                            <table class="wp-list-table widefat fixed striped users">
                                <thead>
                                    <tr>
                                        <td class="manage-column column-cb check-column">
                                            <input type="checkbox" id="wuac_select_all" />
                                        </td>
                                        <th><?php esc_html_e( 'ID', 'wp-user-audit-cleanup' ); ?></th>
                                        <th><?php esc_html_e( 'Username', 'wp-user-audit-cleanup' ); ?></th>
                                        <th><?php esc_html_e( 'Email', 'wp-user-audit-cleanup' ); ?></th>
                                        <th><?php esc_html_e( 'Registration Date', 'wp-user-audit-cleanup' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $results['matched'] as $user ) : ?>
                                        <tr>
                                            <th class="check-column">
                                                <input type="checkbox"
                                                       name="wuac_selected_users[]"
                                                       value="<?php echo esc_attr( $user['ID'] ); ?>" />
                                            </th>
                                            <td><?php echo esc_html( $user['ID'] ); ?></td>
                                            <td><?php echo esc_html( $user['user_login'] ); ?></td>
                                            <td><?php echo esc_html( $user['user_email'] ); ?></td>
                                            <td><?php echo esc_html( $user['user_registered'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p>
                                <?php
                                submit_button(
                                    __( 'Delete Selected', 'wp-user-audit-cleanup' ),
                                    'delete',
                                    'wuac_delete_selected',
                                    false,
                                    array( 'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to delete the selected users? This action cannot be undone.', 'wp-user-audit-cleanup' ) ) . '");' )
                                );
                                ?>
                            </p>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div><!-- .wuac-card .wuac-email-lookup-section -->

            <!-- Inactive Cleanup Section -->
            <div class="wuac-card wuac-inactive-cleanup-section">
                <h2><?php esc_html_e( 'Inactive Cleanup', 'wp-user-audit-cleanup' ); ?></h2>

                <?php if ( ! empty( $inactive_notice ) ) : ?>
                    <div class="notice notice-<?php echo esc_attr( $inactive_type ); ?> is-dismissible">
                        <p><?php echo esc_html( $inactive_notice ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'wuac_inactive_cleanup', '_wuac_inactive_cleanup_nonce' ); ?>
                    <div class="wuac-inactive-form">
                        <div class="wuac-filter-group">
                            <label for="wuac_inactive_days">
                                <?php esc_html_e( 'Days since registration:', 'wp-user-audit-cleanup' ); ?>
                            </label>
                            <input type="number"
                                   id="wuac_inactive_days"
                                   name="wuac_inactive_days"
                                   value="<?php echo esc_attr( $inactive_days ); ?>"
                                   min="1"
                                   class="small-text" />
                        </div>
                        <?php
                        submit_button(
                            __( 'Find Inactive Users', 'wp-user-audit-cleanup' ),
                            'primary',
                            'wuac_find_inactive',
                            false
                        );
                        ?>
                    </div>
                </form>

                <?php if ( null !== $inactive_users ) : ?>
                    <h3>
                        <?php
                        printf(
                            /* translators: %d: number of inactive users found */
                            esc_html__( 'Found %d inactive user(s)', 'wp-user-audit-cleanup' ),
                            count( $inactive_users )
                        );
                        ?>
                    </h3>

                    <?php if ( ! empty( $inactive_users ) ) : ?>
                        <table class="wp-list-table widefat fixed striped users">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'ID', 'wp-user-audit-cleanup' ); ?></th>
                                    <th><?php esc_html_e( 'Username', 'wp-user-audit-cleanup' ); ?></th>
                                    <th><?php esc_html_e( 'Email', 'wp-user-audit-cleanup' ); ?></th>
                                    <th><?php esc_html_e( 'Registration Date', 'wp-user-audit-cleanup' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $inactive_users as $user ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $user->ID ); ?></td>
                                        <td><?php echo esc_html( $user->user_login ); ?></td>
                                        <td><?php echo esc_html( $user->user_email ); ?></td>
                                        <td><?php echo esc_html( $user->user_registered ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <form method="post">
                            <?php wp_nonce_field( 'wuac_inactive_cleanup', '_wuac_inactive_cleanup_nonce' ); ?>
                            <input type="hidden" name="wuac_inactive_days" value="<?php echo esc_attr( $inactive_days ); ?>" />
                            <p>
                                <?php
                                $delete_confirm_msg = sprintf(
                                    /* translators: %d: number of inactive users */
                                    __( 'Are you sure you want to delete %d inactive users? This action cannot be undone.', 'wp-user-audit-cleanup' ),
                                    count( $inactive_users )
                                );
                                submit_button(
                                    __( 'Delete All Inactive', 'wp-user-audit-cleanup' ),
                                    'delete',
                                    'wuac_delete_inactive',
                                    false,
                                    array( 'onclick' => 'return confirm("' . esc_js( $delete_confirm_msg ) . '");' )
                                );
                                ?>
                            </p>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div><!-- .wuac-card .wuac-inactive-cleanup-section -->

        </div><!-- .wrap -->

        <script>
        (function() {
            var selectAll = document.getElementById('wuac_select_all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    var checkboxes = document.querySelectorAll('input[name="wuac_selected_users[]"]');
                    for (var i = 0; i < checkboxes.length; i++) {
                        checkboxes[i].checked = selectAll.checked;
                    }
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Process an email lookup request.
     *
     * Validates input, parses the email list (including wildcard patterns),
     * and finds matching users.
     *
     * Supports mixed input:
     * - Lines with `@` and no `*` are treated as exact email matches.
     * - Lines with `*` are treated as wildcard patterns (e.g. `*.ru`, `*@tempmail*`).
     *
     * @param string $raw_input Raw textarea input.
     * @return array|string Results array with 'matched' and 'unmatched_count', or error message string.
     */
    public function process_email_lookup( string $raw_input ): array|string {
        if ( '' === trim( $raw_input ) ) {
            return __( 'Please enter at least one email address or pattern (e.g. *.ru).', 'wp-user-audit-cleanup' );
        }

        $parsed = $this->parse_mixed_input( $raw_input );

        $total_entries = count( $parsed['emails'] ) + count( $parsed['patterns'] );

        if ( $total_entries > self::MAX_EMAILS ) {
            return __( 'The input exceeds the maximum of 5000 entries. Please reduce the list and try again.', 'wp-user-audit-cleanup' );
        }

        if ( 0 === $total_entries ) {
            return __( 'Please enter at least one email address or pattern (e.g. *.ru).', 'wp-user-audit-cleanup' );
        }

        $matched = array();

        // Exact email matches.
        if ( ! empty( $parsed['emails'] ) ) {
            $matched = $this->find_matching_users( $parsed['emails'] );
        }

        // Wildcard pattern matches.
        if ( ! empty( $parsed['patterns'] ) ) {
            $pattern_matches = $this->find_users_by_patterns( $parsed['patterns'] );
            // Merge and deduplicate by user ID.
            $seen_ids = array_column( $matched, 'ID' );
            foreach ( $pattern_matches as $user ) {
                if ( ! in_array( $user['ID'], $seen_ids, true ) ) {
                    $matched[]  = $user;
                    $seen_ids[] = $user['ID'];
                }
            }
        }

        // Unmatched count only applies to exact emails, not patterns.
        $matched_emails  = array_map( function ( $user ) {
            return strtolower( $user['user_email'] );
        }, $matched );
        $unmatched_count = 0;
        if ( ! empty( $parsed['emails'] ) ) {
            $unmatched_count = count( array_diff( array_map( 'strtolower', $parsed['emails'] ), $matched_emails ) );
        }

        return array(
            'matched'         => $matched,
            'unmatched_count' => $unmatched_count,
            'patterns_used'   => count( $parsed['patterns'] ),
        );
    }

    /**
     * Parse raw input into separate email and pattern arrays.
     *
     * Lines containing `*` are treated as wildcard patterns.
     * Lines with `@` and no `*` are treated as exact email addresses.
     * Lines without `@` or `*` are skipped.
     *
     * @param string $raw_input Raw textarea input.
     * @return array{emails: string[], patterns: string[]} Parsed input.
     */
    public function parse_mixed_input( string $raw_input ): array {
        $lines    = preg_split( '/\r\n|\r|\n/', $raw_input );
        $emails   = array();
        $patterns = array();
        $seen     = array();

        foreach ( $lines as $line ) {
            $entry = trim( $line );

            if ( '' === $entry ) {
                continue;
            }

            $lower = strtolower( $entry );
            if ( isset( $seen[ $lower ] ) ) {
                continue;
            }
            $seen[ $lower ] = true;

            // Lines with * are wildcard patterns.
            if ( false !== strpos( $entry, '*' ) ) {
                $patterns[] = $entry;
                continue;
            }

            // Otherwise validate as email.
            if ( is_email( $entry ) ) {
                $emails[] = $entry;
            }
        }

        return array(
            'emails'   => $emails,
            'patterns' => $patterns,
        );
    }

    /**
     * Parse a raw email list string into a validated, deduplicated array.
     *
     * Splits by newlines, trims whitespace, validates email format,
     * and removes duplicates (case-insensitive).
     *
     * @param string $raw_input Raw textarea input.
     * @return array Array of valid, unique email addresses.
     */
    public function parse_email_list( string $raw_input ): array {
        $lines  = preg_split( '/\r\n|\r|\n/', $raw_input );
        $emails = array();
        $seen   = array();

        foreach ( $lines as $line ) {
            $email = trim( $line );

            if ( '' === $email ) {
                continue;
            }

            if ( ! is_email( $email ) ) {
                continue;
            }

            $lower = strtolower( $email );
            if ( isset( $seen[ $lower ] ) ) {
                continue;
            }

            $seen[ $lower ] = true;
            $emails[]       = $email;
        }

        return $emails;
    }

    /**
     * Find users matching the given email addresses.
     *
     * Queries wp_users by email and returns user data for matches.
     * Includes user role in the returned data.
     *
     * @param array $emails Array of email addresses to look up.
     * @return array Array of matched user data arrays.
     */
    public function find_matching_users( array $emails ): array {
        if ( empty( $emails ) ) {
            return array();
        }

        global $wpdb;

        // Build placeholders for the IN clause.
        $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $query = $wpdb->prepare(
            "SELECT ID, user_login, user_email, user_registered FROM {$wpdb->users} WHERE user_email IN ($placeholders)",
            $emails
        );

        $results = $wpdb->get_results( $query, ARRAY_A );

        if ( ! $results ) {
            return array();
        }

        // Append role to each user.
        return array_map( array( $this, 'append_user_role' ), $results );
    }

    /**
     * Find users whose emails match wildcard patterns.
     *
     * Converts `*` to SQL `%` for LIKE matching.
     * Supports patterns like `*.ru`, `*@tempmail*`, `*casino*@*`.
     *
     * @param array $patterns Array of wildcard pattern strings.
     * @return array Array of matched user data arrays.
     */
    public function find_users_by_patterns( array $patterns ): array {
        if ( empty( $patterns ) ) {
            return array();
        }

        global $wpdb;

        $like_clauses = array();
        foreach ( $patterns as $pattern ) {
            // Sanitize: escape SQL LIKE special chars first, then convert * to %.
            $escaped = $wpdb->esc_like( str_replace( '*', '', $pattern ) );

            // Rebuild with % where * was.
            $sql_pattern = '';
            $raw = $pattern;
            $pos = 0;
            for ( $i = 0, $len = strlen( $raw ); $i < $len; $i++ ) {
                if ( '*' === $raw[ $i ] ) {
                    $sql_pattern .= '%';
                } else {
                    if ( $pos < strlen( $escaped ) ) {
                        $sql_pattern .= $escaped[ $pos ];
                        $pos++;
                    }
                }
            }

            $like_clauses[] = $wpdb->prepare(
                "{$wpdb->users}.user_email LIKE %s",
                $sql_pattern
            );
        }

        if ( empty( $like_clauses ) ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clauses built with $wpdb->prepare above.
        $sql = "SELECT ID, user_login, user_email, user_registered FROM {$wpdb->users} WHERE (" . implode( ' OR ', $like_clauses ) . ')  ORDER BY user_registered DESC LIMIT 5000';

        $results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ( ! $results ) {
            return array();
        }

        return array_map( array( $this, 'append_user_role' ), $results );
    }

    /**
     * Append the user's primary role to a user data array.
     *
     * @param array $user_data User data array with 'ID' key.
     * @return array User data with 'role' appended.
     */
    private function append_user_role( array $user_data ): array {
        $user = get_userdata( (int) $user_data['ID'] );
        if ( $user && ! empty( $user->roles ) ) {
            $user_data['role'] = reset( $user->roles );
        } else {
            $user_data['role'] = __( 'none', 'wp-user-audit-cleanup' );
        }
        return $user_data;
    }

    /**
     * Delete users by ID and reassign their content.
     *
     * @param array $user_ids    Array of user IDs to delete.
     * @param int   $reassign_to User ID to reassign content to.
     * @return int Number of users successfully deleted.
     */
    public function delete_users( array $user_ids, int $reassign_to ): int {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $deleted = 0;

        foreach ( $user_ids as $user_id ) {
            $user_id = (int) $user_id;

            // Never delete the user we're reassigning content to.
            if ( $user_id === $reassign_to ) {
                continue;
            }

            if ( wp_delete_user( $user_id, $reassign_to ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
