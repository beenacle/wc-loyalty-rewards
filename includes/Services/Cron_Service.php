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
     * Daily cron callback to process anniversaries and birthdays.
     */
    public function run_daily(): void {
        $settings = Settings_Cache::get();
        $anniversary_enabled = ! empty( $settings['anniversary']['enabled'] );

        $birthday_settings = $settings['birthday'] ?? [];
        $birthday_enabled  = ! empty( $birthday_settings['enabled'] );
        $birthday_meta_key = isset( $birthday_settings['meta_key'] ) ? sanitize_key( $birthday_settings['meta_key'] ) : '';
        $birthday_format   = isset( $birthday_settings['format'] ) ? sanitize_text_field( $birthday_settings['format'] ) : null;
        if ( $birthday_enabled && '' === $birthday_meta_key ) {
            $birthday_enabled = false;
        }

        if ( ! $anniversary_enabled && ! $birthday_enabled ) {
            return;
        }

        $today_month = gmdate( 'm' );
        $today_day   = gmdate( 'd' );
        $paged       = 1;
        $per_page    = 500;

        // Batch through all users to avoid skipping anniversaries on large sites.
        do {
            $users = get_users(
                [
                    'fields' => [ 'ID', 'user_registered' ],
                    'number' => $per_page,
                    'paged'  => $paged,
                ]
            );

            if ( empty( $users ) ) {
                break;
            }

            foreach ( $users as $user ) {
                if ( $anniversary_enabled ) {
                    $registered = strtotime( $user->user_registered );
                    if ( $registered && gmdate( 'm', $registered ) === $today_month && gmdate( 'd', $registered ) === $today_day ) {
                        $this->points->earn_for_anniversary( $user->ID );
                    }
                }

                if ( $birthday_enabled ) {
                    $birthday_value = get_user_meta( $user->ID, $birthday_meta_key, true );
                    if ( ! empty( $birthday_value ) ) {
                        $this->points->earn_for_birthday( $user->ID, (string) $birthday_value, $birthday_meta_key, $birthday_format );
                    }
                }
            }

            $paged++;
        } while ( count( $users ) === $per_page );
    }
}

