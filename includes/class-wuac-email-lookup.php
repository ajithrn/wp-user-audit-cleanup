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
            $parts = explode( '*', $pattern );
            $escaped_parts = array_map( array( $wpdb, 'esc_like' ), $parts );
            $sql_pattern = implode( '%', $escaped_parts );

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
