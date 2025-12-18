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
                    try {
                        // Parse user_registered date explicitly as UTC to avoid timezone issues.
                        // WordPress stores dates in UTC format 'Y-m-d H:i:s'.
                        $registered_dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $user->user_registered, new \DateTimeZone( 'UTC' ) );
                        if ( $registered_dt ) {
                            $reg_month = $registered_dt->format( 'm' );
                            $reg_day   = $registered_dt->format( 'd' );

                            // Check for exact match.
                            $is_match = ( $reg_month === $today_month && $reg_day === $today_day );

                            // Handle leap year: if user registered on Feb 29, also match on Feb 28 in non-leap years.
                            if ( ! $is_match && '02' === $reg_month && '29' === $reg_day && '02' === $today_month && '28' === $today_day ) {
                                // Check if current year is a non-leap year.
                                $current_year = (int) gmdate( 'Y' );
                                if ( ! ( ( 0 === $current_year % 4 && 0 !== $current_year % 100 ) || 0 === $current_year % 400 ) ) {
                                    $is_match = true;
                                }
                            }

                            if ( $is_match ) {
                                $this->points->earn_for_anniversary( $user->ID );
                            }
                        }
                    } catch ( \Exception $e ) {
                        // Log error but continue processing other users.
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'WCLR: Anniversary reward error for user ' . $user->ID . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        }
                    }
                }

                if ( $birthday_enabled ) {
                    try {
                        $birthday_value = get_user_meta( $user->ID, $birthday_meta_key, true );
                        if ( ! empty( $birthday_value ) ) {
                            $this->points->earn_for_birthday( $user->ID, (string) $birthday_value, $birthday_meta_key, $birthday_format );
                        }
                    } catch ( \Exception $e ) {
                        // Log error but continue processing other users.
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'WCLR: Birthday reward error for user ' . $user->ID . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        }
                    }
                }
            }

            $paged++;
        } while ( count( $users ) === $per_page );
    }
}

