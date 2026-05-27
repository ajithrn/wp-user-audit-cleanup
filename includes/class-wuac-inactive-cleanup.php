<?php
/**
 * Inactive User Cleanup.
 *
 * Finds and deletes users who registered more than a given number of days ago
 * and have never logged in (no _wuac_last_login meta).
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WUAC_Inactive_Cleanup
 *
 * Handles querying and deleting inactive users.
 */
class WUAC_Inactive_Cleanup {

    /**
     * Find users whose registration date is older than $days days
     * and match the inactive and role criteria.
     *
     * @param int    $days Number of days since registration or last login.
     * @param string $role Filter by role (or "all").
     * @param string $type Filter type: "never", "last_login", or "both".
     * @return array Array of WP_User objects matching the criteria.
     */
    public function find_inactive_users( int $days, string $role = 'all', string $type = 'both' ): array {
        $cutoff      = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $role_filter = ( 'all' === $role ) ? '' : $role;

        $results  = array();
        $user_ids = array();

        // 1. Users who never logged in (must have registered before cutoff).
        if ( 'both' === $type || 'never' === $type ) {
            $query_never = new WP_User_Query(
                array(
                    'role'       => $role_filter,
                    'date_query' => array(
                        array(
                            'column' => 'user_registered',
                            'before' => $cutoff,
                        ),
                    ),
                    'meta_query' => array(
                        array(
                            'key'     => '_wuac_last_login',
                            'compare' => 'NOT EXISTS',
                        ),
                    ),
                    'number'     => -1,
                    'fields'     => 'all',
                )
            );
            foreach ( $query_never->get_results() as $user ) {
                if ( ! in_array( $user->ID, $user_ids, true ) ) {
                    $user_ids[] = $user->ID;
                    $results[]  = $user;
                }
            }
        }

        // 2. Users who logged in but last login was before cutoff.
        if ( 'both' === $type || 'last_login' === $type ) {
            $query_logged = new WP_User_Query(
                array(
                    'role'       => $role_filter,
                    'meta_query' => array(
                        array(
                            'key'     => '_wuac_last_login',
                            'value'   => $cutoff,
                            'compare' => '<',
                            'type'    => 'DATETIME',
                        ),
                    ),
                    'number'     => -1,
                    'fields'     => 'all',
                )
            );
            foreach ( $query_logged->get_results() as $user ) {
                if ( ! in_array( $user->ID, $user_ids, true ) ) {
                    $user_ids[] = $user->ID;
                    $results[]  = $user;
                }
            }
        }

        return $results;
    }

    /**
     * Delete inactive users and reassign their content.
     *
     * Finds users matching the inactivity criteria, then deletes each one
     * via wp_delete_user() with content reassigned to $reassign_to.
     * Never deletes the $reassign_to user.
     *
     * @param int $days        Number of days since registration.
     * @param int $reassign_to User ID to reassign content to.
     * @return int Number of users deleted.
     */
    public function delete_inactive_users( int $days, int $reassign_to ): int {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $users   = $this->find_inactive_users( $days );
        $deleted = 0;

        foreach ( $users as $user ) {
            $user_id = (int) $user->ID;

            // Never delete the user we are reassigning content to.
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
