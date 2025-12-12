<?php

/**
 * Uninstall handler.
 *
 * @package WCLR
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'wclr_settings', [] );
if ( empty( $settings['delete_on_uninstall'] ) ) {
    return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wclr_points_ledger" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wclr_referrals" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wclr_tiers" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'wclr_settings' );

// Batch delete user meta for better performance.
$meta_keys = [
    '_wclr_points_balance',
    '_wclr_lifetime_points',
    '_wclr_referral_code',
    '_wclr_signup_awarded',
    '_wclr_daily_visits',
    '_wclr_last_visit_reward_date',
    '_wclr_last_visit_reward_count', // Legacy - keep for cleanup of old data.
    '_wclr_weekly_logins', // Legacy - keep for cleanup of old data.
    '_wclr_weekly_rewarded', // Legacy - keep for cleanup of old data.
    '_wclr_anniversary_year',
    '_wclr_birthday_year',
    '_wclr_pending_reward_notice',
];

// Use direct query for better performance on large sites.
global $wpdb;
foreach ( $meta_keys as $meta_key ) {
    $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->usermeta,
        [ 'meta_key' => $meta_key ],
        [ '%s' ]
    );
}

