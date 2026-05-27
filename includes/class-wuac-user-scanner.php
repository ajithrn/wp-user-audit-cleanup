<?php
/**
 * WUAC_User_Scanner
 *
 * Scans existing users on activation (or manually) to backfill login data
 * using WordPress session tokens and auto-flag high-risk accounts.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_User_Scanner {

    /**
     * Spam score threshold for auto-flagging.
     */
    const AUTO_FLAG_THRESHOLD = 70;

    /**
     * Number of users to process per batch.
     */
    const BATCH_SIZE = 200;

    /**
     * Run the full scan: backfill login data, then auto-flag high-risk users.
     *
     * @return array{backfilled: int, flagged: int, scanned: int}
     */
    public static function scan(): array {
        $backfilled = self::backfill_login_data();
        $flag_result = self::auto_flag_spam();

        return array(
            'backfilled' => $backfilled,
            'flagged'    => $flag_result['flagged'],
            'scanned'    => $flag_result['scanned'],
        );
    }

    /**
     * Backfill _wuac_last_login for existing users using session tokens.
     *
     * Users who have session_tokens in usermeta have logged in at some point.
     * We set their _wuac_last_login to their registration date as a baseline
     * so they don't get penalized by the "no login" spam score factor.
     *
     * Users without session_tokens are left alone (genuinely never logged in).
     *
     * Only processes users who don't already have _wuac_last_login set.
     *
     * @return int Number of users backfilled.
     */
    public static function backfill_login_data(): int {
        global $wpdb;

        // Find users who:
        // 1. Have session_tokens (have logged in before)
        // 2. Do NOT have _wuac_last_login set yet
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT u.ID
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} sm
                 ON u.ID = sm.user_id AND sm.meta_key = 'session_tokens'
             LEFT JOIN {$wpdb->usermeta} lm
                 ON u.ID = lm.user_id AND lm.meta_key = '_wuac_last_login'
             WHERE lm.umeta_id IS NULL"
        );

        $count = 0;

        foreach ( $user_ids as $user_id ) {
            $user = get_userdata( (int) $user_id );
            if ( ! $user ) {
                continue;
            }

            // Use registration date as the baseline "last login".
            update_user_meta( (int) $user_id, '_wuac_last_login', $user->user_registered );
            $count++;
        }

        return $count;
    }

    /**
     * Auto-flag users with spam score at or above the threshold.
     *
     * Processes users in batches to avoid memory exhaustion on large sites.
     * Skips users who are already flagged and never flags the current user.
     *
     * @return array{scanned: int, flagged: int}
     */
    public static function auto_flag_spam(): array {
        $current_user_id = get_current_user_id();
        $scanned         = 0;
        $flagged         = 0;
        $offset          = 0;

        do {
            $users = get_users( array(
                'fields'     => 'all',
                'number'     => self::BATCH_SIZE,
                'offset'     => $offset,
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_wuac_spam_flag',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_wuac_spam_flag',
                        'value'   => '1',
                        'compare' => '!=',
                    ),
                ),
            ) );

            foreach ( $users as $user ) {
                // Never auto-flag the current admin.
                if ( $user->ID === $current_user_id ) {
                    continue;
                }

                $scanned++;

                if ( class_exists( 'WUAC_Spam_Score' ) ) {
                    $score = WUAC_Spam_Score::calculate( $user );
                    update_user_meta( $user->ID, '_wuac_spam_score', $score );
                    if ( $score >= self::AUTO_FLAG_THRESHOLD ) {
                        update_user_meta( $user->ID, '_wuac_spam_flag', '1' );
                        $flagged++;
                    }
                }
            }

            $offset += self::BATCH_SIZE;
        } while ( count( $users ) === self::BATCH_SIZE );

        return array(
            'scanned' => $scanned,
            'flagged' => $flagged,
        );
    }
}
