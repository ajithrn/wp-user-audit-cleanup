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
     * and who have no _wuac_last_login meta recorded.
     *
     * @param int $days Number of days since registration.
     * @return array Array of WP_User objects matching the criteria.
     */
    public function find_inactive_users( int $days ): array {
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $query = new WP_User_Query(
            array(
                'date_query'  => array(
                    array(
                        'column' => 'user_registered',
                        'before' => $cutoff,
                    ),
                ),
                'meta_query'  => array(
                    array(
                        'key'     => '_wuac_last_login',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
                'number'      => -1,
            )
        );

        return $query->get_results();
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
