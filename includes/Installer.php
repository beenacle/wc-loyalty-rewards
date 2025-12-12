<?php

namespace WCLR;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation tasks such as creating tables and default options.
 */
class Installer {

    /**
     * Run on plugin activation.
     */
    public function activate(): void {
        $this->maybe_create_tables();
        $this->maybe_seed_options();
        $this->maybe_schedule_cron();
    }

    /**
     * Create required database tables using dbDelta.
     */
    private function maybe_create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $points_table = "{$wpdb->prefix}wclr_points_ledger";
        $referrals    = "{$wpdb->prefix}wclr_referrals";
        $tiers        = "{$wpdb->prefix}wclr_tiers";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "
        CREATE TABLE {$points_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            amount INT NOT NULL,
            balance_after INT NOT NULL,
            context VARCHAR(50) NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            admin_id BIGINT UNSIGNED NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY type (type),
            KEY created_at (created_at),
            KEY user_type (user_id, type)
        ) {$charset_collate};

        CREATE TABLE {$referrals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_id BIGINT UNSIGNED NOT NULL,
            referred_user_id BIGINT UNSIGNED NULL,
            referral_code VARCHAR(64) NOT NULL,
            first_order_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY referral_code (referral_code),
            KEY referrer (referrer_id),
            KEY referred (referred_user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};

        CREATE TABLE {$tiers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            min_lifetime_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_lifetime_points BIGINT UNSIGNED NULL,
            multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.0,
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sort_order (sort_order),
            KEY enabled (enabled),
            KEY min_points (min_lifetime_points),
            KEY max_points (max_lifetime_points)
        ) {$charset_collate};
        ";

        dbDelta( $sql );
    }

    /**
     * Seed default options and tiers.
     */
    private function maybe_seed_options(): void {
        $defaults = [
            'enabled'               => true,
            'delete_on_uninstall'   => false,
            'base_rate'             => 1,
            'base_multiplier'       => 1,
            'order_earning'         => [
                'enabled'          => true,
                'include_tax'      => false,
                'include_shipping' => false,
                'min_order'        => 0,
                'refund_behavior'  => 'reverse', // reverse|prorate|ignore.
                'exclude_coupons'  => [], // Array of coupon codes to exclude.
            ],
            'signup_bonus'          => [
                'enabled' => true,
                'points'  => 100,
            ],
            'referral'              => [
                'enabled'        => true,
                'referrer_bonus' => 200,
                'referred_bonus' => 100,
            ],
            'login'                 => [
                'enabled'   => true,
                'threshold' => 3,
                'points'    => 50,
            ],
            'birthday'             => [
                'enabled'  => false,
                'points'   => 150,
                'meta_key' => 'birthday',
                'format'   => 'Y-m-d',
            ],
            'anniversary'           => [
                'enabled' => true,
                'points'  => 150,
            ],
            'redemption'            => [
                'enabled'         => true,
                'points_per_unit' => 100,
                'unit_value'      => 1.0,
                'max_percent'     => 50,
                'auto_mode'       => 'disabled', // disabled|max|percent
                'auto_percent'    => 50,
                'return_on_refund'=> true,
                'allow_manual_input' => true,
                'exclude_coupons' => [], // Array of coupon codes to exclude.
            ],
            'display'               => [
                'show_my_account' => true,
                'show_cart'       => true,
                'show_checkout'   => true,
            ],
        ];

        add_option( 'wclr_settings', $defaults );

        $this->maybe_seed_tiers();
    }

    /**
     * Seed default tiers if none exist.
     */
    private function maybe_seed_tiers(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_tiers';
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}", array() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $count > 0 ) {
            return;
        }

        $tiers = [
            [ 'Silver', 500, null, 1.2, 1 ],
            [ 'Gold', 1500, null, 1.5, 2 ],
            [ 'Platinum', 2500, null, 2.0, 3 ],
        ];

        foreach ( $tiers as $tier ) {
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'name'                => $tier[0],
                    'min_lifetime_points' => $tier[1],
                    'max_lifetime_points' => $tier[2],
                    'multiplier'          => $tier[3],
                    'sort_order'          => $tier[4],
                    'enabled'             => 1,
                ],
                [ '%s', '%d', '%d', '%f', '%d', '%d' ]
            );
        }
    }

    /**
     * Schedule cron for anniversaries if not already set.
     */
    private function maybe_schedule_cron(): void {
        if ( ! wp_next_scheduled( 'wclr_daily_events' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wclr_daily_events' );
        }
    }
}

