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
     *
     * @since 1.4.0 Rebalanced weights and added new factors.
     */
    const WEIGHT_NO_LOGIN          = 25;
    const WEIGHT_RECENT_REG        = 5;
    const WEIGHT_DISPOSABLE        = 30;
    const WEIGHT_DIGIT_USERNAME    = 10;
    const WEIGHT_GIBBERISH_USER    = 15;
    const WEIGHT_BOT_PATTERN_USER  = 15;
    const WEIGHT_NAME_IS_EMAIL     = 15;
    const WEIGHT_NAME_IS_LOGIN     = 10;
    const WEIGHT_NAME_HAS_SPAM     = 25;
    const WEIGHT_NO_COMMENTS       = 5;
    const WEIGHT_NO_ORDERS         = 5;
    const WEIGHT_SUSPICIOUS_EMAIL  = 15;
    const WEIGHT_PLUS_ADDRESSING   = 5;
    const WEIGHT_SPAM_URL          = 15;

    /**
     * Spam keywords found in display names and URLs.
     *
     * @var string[]
     */
    private static array $spam_keywords = array(
        'casino', 'poker', 'slot', 'blackjack', 'roulette', 'betting', 'gamble',
        'crypto', 'bitcoin', 'ethereum', 'blockchain', 'nft', 'forex', 'trading',
        'pharmacy', 'viagra', 'cialis', 'pills', 'drug', 'medication',
        'seo', 'backlink', 'rank', 'traffic',
        'porn', 'xxx', 'sex', 'nude', 'adult', 'webcam', 'escort',
        'loan', 'payday', 'mortgage', 'debt',
        'replica', 'counterfeit', 'fake',
        'hack', 'crack', 'keygen', 'torrent', 'warez',
        'diet', 'weight loss', 'keto',
    );

    /**
     * Spam TLDs commonly used by bots.
     *
     * @var string[]
     */
    private static array $spam_tlds = array(
        '.xyz', '.top', '.click', '.loan', '.tk', '.ml', '.ga', '.cf', '.gq',
        '.work', '.date', '.review', '.stream', '.download', '.racing',
        '.win', '.bid', '.trade', '.party', '.science', '.accountant',
        '.cricket', '.faith', '.men', '.webcam',
    );

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

        if ( self::is_gibberish_string( $user->user_login ) ) {
            $score += self::WEIGHT_GIBBERISH_USER;
        }

        if ( self::matches_bot_username_pattern( $user->user_login ) ) {
            $score += self::WEIGHT_BOT_PATTERN_USER;
        }

        if ( self::display_name_is_email( $user ) ) {
            $score += self::WEIGHT_NAME_IS_EMAIL;
        }

        if ( self::display_name_is_login( $user ) ) {
            $score += self::WEIGHT_NAME_IS_LOGIN;
        }

        if ( self::display_name_has_spam( $user ) ) {
            $score += self::WEIGHT_NAME_HAS_SPAM;
        }

        if ( self::has_suspicious_email( $user->user_email ) ) {
            $score += self::WEIGHT_SUSPICIOUS_EMAIL;
        }

        if ( self::has_plus_addressing( $user->user_email ) ) {
            $score += self::WEIGHT_PLUS_ADDRESSING;
        }

        if ( self::has_spam_url( $user->user_url ) ) {
            $score += self::WEIGHT_SPAM_URL;
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
     * Get a detailed breakdown of which factors contributed to a score.
     *
     * Returns an associative array of factor_key => array( 'label', 'points', 'triggered' ).
     *
     * @param WP_User $user The user object.
     * @return array[] Breakdown of scoring factors.
     */
    public static function get_breakdown( WP_User $user ): array {
        $factors = array(
            'no_login'         => array(
                'label'     => __( 'No login recorded', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NO_LOGIN,
                'triggered' => self::has_no_login( $user->ID ),
            ),
            'recent_reg'       => array(
                'label'     => __( 'Recent registration', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_RECENT_REG,
                'triggered' => self::is_recent_registration( $user->user_registered ),
            ),
            'disposable'       => array(
                'label'     => __( 'Disposable email domain', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_DISPOSABLE,
                'triggered' => self::is_disposable_email( $user->user_email ),
            ),
            'digit_username'   => array(
                'label'     => __( 'Digit-heavy username', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_DIGIT_USERNAME,
                'triggered' => self::has_digit_sequence( $user->user_login ),
            ),
            'gibberish_user'   => array(
                'label'     => __( 'Gibberish username', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_GIBBERISH_USER,
                'triggered' => self::is_gibberish_string( $user->user_login ),
            ),
            'bot_pattern'      => array(
                'label'     => __( 'Bot username pattern', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_BOT_PATTERN_USER,
                'triggered' => self::matches_bot_username_pattern( $user->user_login ),
            ),
            'name_is_email'    => array(
                'label'     => __( 'Display name is email', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NAME_IS_EMAIL,
                'triggered' => self::display_name_is_email( $user ),
            ),
            'name_is_login'    => array(
                'label'     => __( 'Display name matches username', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NAME_IS_LOGIN,
                'triggered' => self::display_name_is_login( $user ),
            ),
            'name_has_spam'    => array(
                'label'     => __( 'Display name contains spam/URL', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NAME_HAS_SPAM,
                'triggered' => self::display_name_has_spam( $user ),
            ),
            'suspicious_email' => array(
                'label'     => __( 'Suspicious email pattern', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_SUSPICIOUS_EMAIL,
                'triggered' => self::has_suspicious_email( $user->user_email ),
            ),
            'plus_addressing'  => array(
                'label'     => __( 'Plus-addressing in email', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_PLUS_ADDRESSING,
                'triggered' => self::has_plus_addressing( $user->user_email ),
            ),
            'spam_url'         => array(
                'label'     => __( 'Spam URL in profile', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_SPAM_URL,
                'triggered' => self::has_spam_url( $user->user_url ),
            ),
            'no_comments'      => array(
                'label'     => __( 'No approved comments', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NO_COMMENTS,
                'triggered' => self::has_no_comments( $user->ID ),
            ),
            'no_orders'        => array(
                'label'     => __( 'No WooCommerce orders', 'wp-user-audit-cleanup' ),
                'points'    => self::WEIGHT_NO_ORDERS,
                'triggered' => self::has_no_orders( $user->ID ),
            ),
        );

        return $factors;
    }

    // ------------------------------------------------------------------
    // Factor checks
    // ------------------------------------------------------------------

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
     * Check if a string appears to be gibberish/random characters.
     *
     * Uses Shannon entropy and vowel-consonant ratio analysis.
     * Real names and words have lower entropy and reasonable vowel ratios.
     *
     * @param string $input The string to check (username or email local part).
     * @return bool True if the string appears to be gibberish.
     */
    public static function is_gibberish_string( string $input ): bool {
        // Strip common prefixes/suffixes that are legitimate.
        $cleaned = preg_replace( '/^(user|admin|test|info|the|my)/i', '', $input );
        $cleaned = preg_replace( '/\d+$/', '', $cleaned ); // strip trailing digits.

        // Too short to judge after cleaning.
        if ( strlen( $cleaned ) < 4 ) {
            return false;
        }

        $alpha_only = preg_replace( '/[^a-zA-Z]/', '', $cleaned );

        if ( strlen( $alpha_only ) < 4 ) {
            return false;
        }

        // Check vowel ratio — real names/words have 25-60% vowels.
        $vowel_count = preg_match_all( '/[aeiouAEIOU]/', $alpha_only );
        $vowel_ratio = $vowel_count / strlen( $alpha_only );

        // Very low vowel ratio (<15%) strongly suggests gibberish.
        if ( $vowel_ratio < 0.15 && strlen( $alpha_only ) >= 5 ) {
            return true;
        }

        // Shannon entropy check — random strings have high entropy.
        $entropy = self::calculate_entropy( strtolower( $alpha_only ) );

        // High entropy (>3.5) with low vowels (<25%) = likely gibberish.
        if ( $entropy > 3.5 && $vowel_ratio < 0.25 ) {
            return true;
        }

        // Check for consonant clusters (3+ consonants in a row, multiple times).
        $cluster_count = preg_match_all( '/[bcdfghjklmnpqrstvwxyz]{4,}/i', $alpha_only );
        if ( $cluster_count >= 2 ) {
            return true;
        }

        return false;
    }

    /**
     * Check if a username matches known bot registration patterns.
     *
     * @param string $username The username to check.
     * @return bool True if the username matches a bot pattern.
     */
    public static function matches_bot_username_pattern( string $username ): bool {
        $patterns = array(
            // firstname.lastname + long digit suffix.
            '/^[a-z]+\.[a-z]+\d{4,}$/i',
            // Short alpha + long digit suffix.
            '/^[a-z]{2,6}\d{5,}$/i',
            // Repeating character groups.
            '/(.)\1{3,}/',
            // All-digit username.
            '/^\d+$/',
            // Mixed with excessive underscores/hyphens and digits.
            '/^[a-z]{1,3}[_\-]\d{4,}$/i',
            // Random looking: alternating single consonant-digit patterns.
            '/^(?:[bcdfghjklmnpqrstvwxyz]\d){3,}$/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $username ) ) {
                return true;
            }
        }

        // Keyboard walk detection.
        $keyboard_walks = array(
            'qwerty', 'qwert', 'asdfgh', 'asdfg', 'zxcvbn', 'zxcvb',
            'qazwsx', 'abcdef', '123456', 'aaaaaa',
        );

        $lower = strtolower( $username );
        foreach ( $keyboard_walks as $walk ) {
            if ( false !== strpos( $lower, $walk ) ) {
                return true;
            }
        }

        return false;
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
     * Check if the display name is identical to the username.
     *
     * WordPress sets display_name to user_login by default. Bots rarely
     * customize this; legitimate users usually set a real name.
     *
     * @param WP_User $user The user object.
     * @return bool True if display_name equals user_login.
     */
    public static function display_name_is_login( WP_User $user ): bool {
        // Don't double-penalize if display_name is already the email.
        if ( $user->display_name === $user->user_email ) {
            return false;
        }

        return strtolower( $user->display_name ) === strtolower( $user->user_login );
    }

    /**
     * Check if the display name contains URLs or spam keywords.
     *
     * @param WP_User $user The user object.
     * @return bool True if spam content is detected in the display name.
     */
    public static function display_name_has_spam( WP_User $user ): bool {
        $name = strtolower( $user->display_name );

        // Check for URLs in display name.
        if ( preg_match( '/https?:\/\/|www\.|\.com|\.net|\.org|\.ru|\.cn/i', $name ) ) {
            return true;
        }

        // Check for spam keywords.
        foreach ( self::$spam_keywords as $keyword ) {
            if ( false !== strpos( $name, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an email address has suspicious patterns.
     *
     * Detects: excessive dots in local part, high digit ratio,
     * gibberish local part, all-numeric local part, excessive length.
     *
     * @param string $email The email address.
     * @return bool True if suspicious patterns detected.
     */
    public static function has_suspicious_email( string $email ): bool {
        $local = self::extract_local_part( $email );

        if ( '' === $local ) {
            return false;
        }

        // All-numeric local part (e.g. 928374651@gmail.com).
        if ( preg_match( '/^\d+$/', $local ) ) {
            return true;
        }

        // Excessive dots (3+ dots in local part).
        if ( substr_count( $local, '.' ) >= 3 ) {
            return true;
        }

        // High digit ratio (>50% digits when local part has 5+ chars).
        if ( strlen( $local ) >= 5 ) {
            $digit_count = preg_match_all( '/\d/', $local );
            $digit_ratio = $digit_count / strlen( $local );
            if ( $digit_ratio > 0.5 ) {
                return true;
            }
        }

        // Excessive length (>30 chars).
        if ( strlen( $local ) > 30 ) {
            return true;
        }

        // Gibberish check on the local part (strip digits first).
        $alpha_part = preg_replace( '/[^a-zA-Z]/', '', $local );
        if ( strlen( $alpha_part ) >= 5 && self::is_gibberish_string( $alpha_part ) ) {
            return true;
        }

        // Multiple consecutive special characters.
        if ( preg_match( '/[._\-]{2,}/', $local ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if an email uses plus-addressing (user+tag@domain.com).
     *
     * @param string $email The email address.
     * @return bool True if plus-addressing is detected.
     */
    public static function has_plus_addressing( string $email ): bool {
        $local = self::extract_local_part( $email );

        return '' !== $local && false !== strpos( $local, '+' );
    }

    /**
     * Check if the user's website URL contains spam indicators.
     *
     * @param string $url The user's website URL.
     * @return bool True if spam indicators found.
     */
    public static function has_spam_url( string $url ): bool {
        if ( '' === $url ) {
            return false;
        }

        $url_lower = strtolower( $url );

        // Check for spam TLDs.
        foreach ( self::$spam_tlds as $tld ) {
            // Match TLD at end of domain (before path or end of string).
            if ( preg_match( '/' . preg_quote( $tld, '/' ) . '(\/|$)/', $url_lower ) ) {
                return true;
            }
        }

        // Check for spam keywords in URL.
        foreach ( self::$spam_keywords as $keyword ) {
            if ( false !== strpos( $url_lower, $keyword ) ) {
                return true;
            }
        }

        return false;
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

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Calculate Shannon entropy of a string.
     *
     * Higher values indicate more randomness/less structure.
     *
     * @param string $string The string to analyze.
     * @return float Entropy value (typically 0-5 for text).
     */
    private static function calculate_entropy( string $string ): float {
        $len = strlen( $string );
        if ( $len === 0 ) {
            return 0.0;
        }

        $freq = array_count_values( str_split( $string ) );
        $entropy = 0.0;

        foreach ( $freq as $count ) {
            $p = $count / $len;
            $entropy -= $p * log( $p, 2 );
        }

        return $entropy;
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

    /**
     * Extract the local part (before @) from an email address.
     *
     * @param string $email The email address.
     * @return string The local part, or empty string if invalid.
     */
    private static function extract_local_part( string $email ): string {
        $at_pos = strrpos( $email, '@' );

        if ( false === $at_pos ) {
            return '';
        }

        return substr( $email, 0, $at_pos );
    }
}
