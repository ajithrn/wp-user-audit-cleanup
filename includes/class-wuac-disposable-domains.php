<?php
/**
 * WUAC_Disposable_Domains
 *
 * Manages the disposable email domain list and auto-flags users who register
 * with a disposable email address.
 *
 * Domain storage:
 * - Bundled list: data/disposable-domains.php (returns a PHP array)
 * - Admin additions: wuac_custom_domains option (array)
 * - Admin removals: wuac_removed_domains option (array)
 * - Effective list = (bundled + custom) - removed
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Disposable_Domains {

    /**
     * Static cache for the effective domain list.
     *
     * @var array|null
     */
    private static ?array $cached_domains = null;

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'user_register', array( $this, 'on_user_register' ) );
    }

    /**
     * Auto-flag a newly registered user if their email uses a disposable domain.
     *
     * Callback for the user_register action.
     *
     * @param int $user_id The ID of the newly registered user.
     * @return void
     */
    public function on_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user || empty( $user->user_email ) ) {
            return;
        }

        if ( self::is_disposable( $user->user_email ) ) {
            update_user_meta( $user_id, '_wuac_spam_flag', '1' );
        }
    }

    /**
     * Get the effective list of disposable email domains.
     *
     * Merges the bundled list with admin-added custom domains, then subtracts
     * any domains the admin has removed.
     *
     * @return array List of disposable domain strings.
     */
    public static function get_domains(): array {
        if ( null !== self::$cached_domains ) {
            return self::$cached_domains;
        }

        $bundled_file = WUAC_PLUGIN_DIR . 'data/disposable-domains.php';
        $bundled      = file_exists( $bundled_file ) ? include $bundled_file : array();

        if ( ! is_array( $bundled ) ) {
            $bundled = array();
        }

        $custom  = get_option( 'wuac_custom_domains', array() );
        $removed = get_option( 'wuac_removed_domains', array() );

        if ( ! is_array( $custom ) ) {
            $custom = array();
        }
        if ( ! is_array( $removed ) ) {
            $removed = array();
        }

        // Normalize all domains to lowercase.
        $bundled = array_map( 'strtolower', $bundled );
        $custom  = array_map( 'strtolower', $custom );
        $removed = array_map( 'strtolower', $removed );

        // Effective list = (bundled + custom) - removed.
        $merged = array_unique( array_merge( $bundled, $custom ) );

        self::$cached_domains = array_values( array_diff( $merged, $removed ) );

        return self::$cached_domains;
    }

    /**
     * Invalidate the static domain cache.
     *
     * Called after add/remove operations to ensure fresh data.
     *
     * @return void
     */
    public static function invalidate_cache(): void {
        self::$cached_domains = null;
    }

    /**
     * Add a domain to the custom domains list.
     *
     * @param string $domain The domain to add.
     * @return bool True on success, false if the domain is empty or already present.
     */
    public static function add_domain( string $domain ): bool {
        $domain = strtolower( trim( $domain ) );

        if ( '' === $domain ) {
            return false;
        }

        $custom = get_option( 'wuac_custom_domains', array() );

        if ( ! is_array( $custom ) ) {
            $custom = array();
        }

        $custom = array_map( 'strtolower', $custom );

        if ( in_array( $domain, $custom, true ) ) {
            return false;
        }

        $custom[] = $domain;
        update_option( 'wuac_custom_domains', $custom );

        // Invalidate static cache.
        self::invalidate_cache();
        // If this domain was previously removed, un-remove it.
        $removed = get_option( 'wuac_removed_domains', array() );

        if ( is_array( $removed ) ) {
            $removed = array_map( 'strtolower', $removed );
            $key     = array_search( $domain, $removed, true );

            if ( false !== $key ) {
                unset( $removed[ $key ] );
                update_option( 'wuac_removed_domains', array_values( $removed ) );
            }
        }

        return true;
    }

    /**
     * Remove a domain from the effective list.
     *
     * If the domain is a custom addition, it is removed from the custom list.
     * If the domain is bundled, it is added to the removed list.
     *
     * @param string $domain The domain to remove.
     * @return bool True on success, false if the domain is empty or not in the effective list.
     */
    public static function remove_domain( string $domain ): bool {
        $domain = strtolower( trim( $domain ) );

        if ( '' === $domain ) {
            return false;
        }

        $effective = self::get_domains();

        if ( ! in_array( $domain, $effective, true ) ) {
            return false;
        }

        // Remove from custom list if present.
        $custom = get_option( 'wuac_custom_domains', array() );

        if ( is_array( $custom ) ) {
            $custom = array_map( 'strtolower', $custom );
            $key    = array_search( $domain, $custom, true );

            if ( false !== $key ) {
                unset( $custom[ $key ] );
                update_option( 'wuac_custom_domains', array_values( $custom ) );
            }
        }

        // Add to removed list so bundled domains stay suppressed.
        $removed = get_option( 'wuac_removed_domains', array() );

        if ( ! is_array( $removed ) ) {
            $removed = array();
        }

        $removed = array_map( 'strtolower', $removed );

        if ( ! in_array( $domain, $removed, true ) ) {
            $removed[] = $domain;
            update_option( 'wuac_removed_domains', array_values( $removed ) );
        }

        // Invalidate static cache.
        self::invalidate_cache();

        return true;
    }

    /**
     * Check if an email address uses a disposable domain.
     *
     * @param string $email The email address to check.
     * @return bool True if the domain is in the effective disposable list.
     */
    public static function is_disposable( string $email ): bool {
        $at_pos = strrpos( $email, '@' );

        if ( false === $at_pos ) {
            return false;
        }

        $domain = strtolower( substr( $email, $at_pos + 1 ) );

        if ( '' === $domain ) {
            return false;
        }

        $domains = self::get_domains();

        return in_array( $domain, $domains, true );
    }
}
