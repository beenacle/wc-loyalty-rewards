<?php

namespace WCLR\Services;

use WCLR\Helpers\Settings_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Handles scheduled tasks like anniversary bonuses.
 */
class Cron_Service {

    private Points_Service $points;

    public function __construct( Points_Service $points ) {
        $this->points = $points;
    }

    public function register(): void {
        add_action( 'wclr_daily_events', [ $this, 'run_daily' ] );
    }

    /**
     * Daily cron callback to process anniversaries.
     */
    public function run_daily(): void {
        $settings = Settings_Cache::get();
        if ( empty( $settings['anniversary']['enabled'] ) ) {
            return;
        }

        $users = get_users(
            [
                'fields' => [ 'ID', 'user_registered' ],
                'number' => 500,
            ]
        );

        $today_month = gmdate( 'm' );
        $today_day   = gmdate( 'd' );

        foreach ( $users as $user ) {
            $registered = strtotime( $user->user_registered );
            if ( ! $registered ) {
                continue;
            }
            if ( gmdate( 'm', $registered ) === $today_month && gmdate( 'd', $registered ) === $today_day ) {
                $this->points->earn_for_anniversary( $user->ID );
            }
        }
    }
}

