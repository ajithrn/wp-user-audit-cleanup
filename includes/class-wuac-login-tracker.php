<?php
/**
 * WUAC_Login_Tracker
 *
 * Records the last login timestamp for each user as UTC in user meta.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WUAC_Login_Tracker {

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_login', array( $this, 'record_login' ), 10, 2 );
    }

    /**
     * Store the current UTC timestamp in _wuac_last_login user meta.
     *
     * Callback for the wp_login action.
     *
     * @param string  $user_login Username of the authenticated user.
     * @param WP_User $user       WP_User object of the authenticated user.
     * @return void
     */
    public function record_login( string $user_login, WP_User $user ): void {
        update_user_meta( $user->ID, '_wuac_last_login', current_time( 'mysql', true ) );
    }
}
