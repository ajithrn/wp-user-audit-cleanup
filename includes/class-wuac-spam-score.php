<?php
/**
 * Spam score calculation.
 *
 * Computes a 0–100 spam likelihood score for a user based on weighted factors.
 * The score is calculated on-the-fly and NOT stored in the database.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Spam_Score {

    /**
     * Scoring weight constants.
     */
    const WEIGHT_NO_LOGIN       = 30;
    const WEIGHT_RECENT_REG     = 10;
    const WEIGHT_DISPOSABLE     = 30;
    const WEIGHT_DIGIT_USERNAME = 10;
    const WEIGHT_NAME_IS_EMAIL  = 20;
    const WEIGHT_NO_COMMENTS    = 10;
    const WEIGHT_NO_ORDERS      = 10;

    /**
     * Calculate the spam score for a given user.
     *
     * Sums applicable weights and caps the result at 100.
     *
     * @param WP_User $user The user object.
     * @return int Score between 0 and 100 inclusive.
     */
    public static function calculate( WP_User $user ): int {
        $score = 0;

        if ( self::has_no_login( $user->ID ) ) {
            $score += self::WEIGHT_NO_LOGIN;
        }

        if ( self::is_recent_registration( $user->user_registered ) ) {
            $score += self::WEIGHT_RECENT_REG;
        }

        if ( self::is_disposable_email( $user->user_email ) ) {
            $score += self::WEIGHT_DISPOSABLE;
        }

        if ( self::has_digit_sequence( $user->user_login ) ) {
            $score += self::WEIGHT_DIGIT_USERNAME;
        }

        if ( self::display_name_is_email( $user ) ) {
            $score += self::WEIGHT_NAME_IS_EMAIL;
        }

        if ( self::has_no_comments( $user->ID ) ) {
            $score += self::WEIGHT_NO_COMMENTS;
        }

        if ( self::has_no_orders( $user->ID ) ) {
            $score += self::WEIGHT_NO_ORDERS;
        }

        return min( $score, 100 );
    }

    /**
     * Check if a user has never logged in.
     *
     * @param int $user_id The user ID.
     * @return bool True if no _wuac_last_login meta exists.
     */
    public static function has_no_login( int $user_id ): bool {
        $last_login = get_user_meta( $user_id, '_wuac_last_login', true );

        return '' === $last_login;
    }

    /**
     * Check if the user registered within the given number of hours.
     *
     * @param string $registered The user_registered datetime string (UTC).
     * @param int    $hours      Number of hours to consider "recent". Default 24.
     * @return bool True if registration is within the threshold.
     */
    public static function is_recent_registration( string $registered, int $hours = 24 ): bool {
        $registered_time = strtotime( $registered );

        if ( false === $registered_time ) {
            return false;
        }

        $threshold = time() - ( $hours * HOUR_IN_SECONDS );

        return $registered_time >= $threshold;
    }

    /**
     * Check if an email address uses a disposable domain.
     *
     * Uses WUAC_Disposable_Domains::get_domains() when available (merges
     * bundled + custom − removed). Falls back to the bundled list directly.
     *
     * @param string $email The email address.
     * @return bool True if the domain is disposable.
     */
    public static function is_disposable_email( string $email ): bool {
        $domain = self::extract_domain( $email );

        if ( '' === $domain ) {
            return false;
        }

        if ( class_exists( 'WUAC_Disposable_Domains' ) && method_exists( 'WUAC_Disposable_Domains', 'get_domains' ) ) {
            $domains = WUAC_Disposable_Domains::get_domains();
        } else {
            $file = WUAC_PLUGIN_DIR . 'data/disposable-domains.php';
            $domains = file_exists( $file ) ? include $file : array();
        }

        return in_array( strtolower( $domain ), array_map( 'strtolower', $domains ), true );
    }

    /**
     * Check if a username contains a sequence of consecutive digits.
     *
     * @param string $username   The username to check.
     * @param int    $min_digits Minimum number of consecutive digits. Default 5.
     * @return bool True if the username contains the digit sequence.
     */
    public static function has_digit_sequence( string $username, int $min_digits = 5 ): bool {
        return 1 === preg_match( '/\d{' . $min_digits . ',}/', $username );
    }

    /**
     * Check if the user's display name is identical to their email address.
     *
     * @param WP_User $user The user object.
     * @return bool True if display_name equals user_email.
     */
    public static function display_name_is_email( WP_User $user ): bool {
        return $user->display_name === $user->user_email;
    }

    /**
     * Check if a user has zero approved comments.
     *
     * Queries wp_comments for any approved comment by this user ID.
     *
     * @param int $user_id The user ID.
     * @return bool True if the user has no approved comments.
     */
    public static function has_no_comments( int $user_id ): bool {
        global $wpdb;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved = '1' LIMIT 1",
            $user_id
        ) );

        return 0 === $count;
    }

    /**
     * Check if a user has zero WooCommerce orders.
     *
     * Supports WooCommerce HPOS (High-Performance Order Storage) via the
     * wc_orders table when available, and falls back to wp_posts for
     * legacy order storage. Returns false (no penalty) if WooCommerce
     * is not active.
     *
     * @param int $user_id The user ID.
     * @return bool True if WooCommerce is active and the user has no orders.
     */
    public static function has_no_orders( int $user_id ): bool {
        // Only penalize if WooCommerce is active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        global $wpdb;

        // Check HPOS table first (WooCommerce 8.2+).
        $hpos_table = $wpdb->prefix . 'wc_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) );

        if ( $table_exists ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$hpos_table} WHERE customer_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $user_id
            ) );
            return 0 === $count;
        }

        // Fallback: legacy post-based orders.
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_order'
               AND pm.meta_key = '_customer_user'
               AND pm.meta_value = %d
             LIMIT 1",
            $user_id
        ) );

        return 0 === $count;
    }

    /**
     * Extract the domain portion from an email address.
     *
     * @param string $email The email address.
     * @return string The domain, or empty string if invalid.
     */
    private static function extract_domain( string $email ): string {
        $at_pos = strrpos( $email, '@' );

        if ( false === $at_pos ) {
            return '';
        }

        return substr( $email, $at_pos + 1 );
    }
}
