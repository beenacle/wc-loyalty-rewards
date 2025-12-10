<?php

namespace WCLR\Services;

use WC_Cart;
use WC_Order;
use WCLR\Helpers\Settings_Cache;
use WCLR\Models\Points_Balance;
use WCLR\Models\Points_Ledger;
use WCLR\Models\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * Service responsible for points balance mutations and ledger logging.
 */
class Points_Service {

    /**
     * Tier service instance (injected).
     *
     * @var Tier_Service|null
     */
    private ?Tier_Service $tier_service = null;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( 'updated_option', [ $this, 'maybe_clear_settings_cache' ], 10, 1 );
    }

    /**
     * Set tier service (dependency injection).
     *
     * @param Tier_Service $tier_service Tier service.
     */
    public function set_tier_service( Tier_Service $tier_service ): void {
        $this->tier_service = $tier_service;
    }

    /**
     * Clear settings cache when wclr_settings is updated.
     *
     * @param string $option_name Option name.
     */
    public function maybe_clear_settings_cache( string $option_name ): void {
        if ( 'wclr_settings' === $option_name ) {
            Settings_Cache::clear();
        }
    }

    /**
     * Earn points for a completed order.
     */
    public function earn_for_order( WC_Order $order, float $multiplier = 1.0, float $rate = 1.0 ): int {
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $settings = Settings_Cache::get();
        if ( empty( $settings['order_earning']['enabled'] ) ) {
            return 0;
        }

        if ( $order->get_meta( '_wclr_points_awarded', true ) ) {
            return 0;
        }

        // Skip earning if any applied coupon is in the exclusion list (only if exclusion is enabled).
        if ( ! empty( $settings['order_earning']['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = isset( $settings['order_earning']['exclude_coupons'] ) && is_array( $settings['order_earning']['exclude_coupons'] ) ? $settings['order_earning']['exclude_coupons'] : [];
            if ( ! empty( $excluded_coupons ) ) {
                $applied_coupons = $order->get_coupon_codes();
                foreach ( $applied_coupons as $applied_code ) {
                    if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                        return 0;
                    }
                }
            }
        }

        $include_tax      = ! empty( $settings['order_earning']['include_tax'] );
        $include_shipping = ! empty( $settings['order_earning']['include_shipping'] );
        $min_order        = isset( $settings['order_earning']['min_order'] ) ? (float) $settings['order_earning']['min_order'] : 0;

        $subtotal = (float) $order->get_subtotal();
        if ( $include_tax ) {
            $subtotal += (float) $order->get_total_tax();
        }
        if ( $include_shipping ) {
            $subtotal += (float) $order->get_shipping_total();
        }

        if ( $subtotal < $min_order ) {
            return 0;
        }

        $points = (int) floor( $subtotal * $rate * $multiplier );
        $points = apply_filters( 'wc_loyalty_rewards_earn_rate', $points, $order );

        if ( $points > 0 ) {
            $this->add_points(
                $user_id,
                $points,
                'earn',
                [
                    'context'  => 'order',
                    'order_id' => $order->get_id(),
                ]
            );
            $order->update_meta_data( '_wclr_points_awarded', $points );
            $order->save();
        }

        return $points;
    }

    /**
     * Signup bonus.
     */
    public function earn_for_signup( int $user_id ): int {
        $settings = Settings_Cache::get();
        if ( empty( $settings['signup_bonus']['enabled'] ) ) {
            return 0;
        }
        $points = isset( $settings['signup_bonus']['points'] ) ? (int) $settings['signup_bonus']['points'] : 0;
        $awarded = (bool) get_user_meta( $user_id, '_wclr_signup_awarded', true );
        if ( $awarded || $points <= 0 ) {
            return 0;
        }
        $this->add_points(
            $user_id,
            $points,
            'earn',
            [
                'context' => 'signup',
            ]
        );
        update_user_meta( $user_id, '_wclr_signup_awarded', 1 );
        return $points;
    }

    /**
     * Estimate how many points the current cart would earn.
     *
     * @param WC_Cart|null $cart Woo cart instance.
     */
    public function estimate_cart_points( ?WC_Cart $cart = null ): int {
        $cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
        if ( ! $cart instanceof WC_Cart ) {
            return 0;
        }

        $settings = Settings_Cache::get();
        if ( empty( $settings['order_earning']['enabled'] ) ) {
            return 0;
        }

        $subtotal = (float) $cart->get_subtotal();
        if ( ! empty( $settings['order_earning']['include_tax'] ) ) {
            $subtotal += (float) $cart->get_subtotal_tax();
        }
        if ( ! empty( $settings['order_earning']['include_shipping'] ) && method_exists( $cart, 'get_shipping_total' ) ) {
            $subtotal += (float) $cart->get_shipping_total();
        }

        $min_order = isset( $settings['order_earning']['min_order'] ) ? (float) $settings['order_earning']['min_order'] : 0;
        if ( $subtotal < $min_order ) {
            return 0;
        }

        // Skip earning if any applied coupon is in the exclusion list (only if exclusion is enabled).
        if ( ! empty( $settings['order_earning']['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = isset( $settings['order_earning']['exclude_coupons'] ) && is_array( $settings['order_earning']['exclude_coupons'] ) ? $settings['order_earning']['exclude_coupons'] : [];
            if ( ! empty( $excluded_coupons ) ) {
                $applied_coupons = $cart->get_applied_coupons();
                foreach ( $applied_coupons as $applied_code ) {
                    if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                        return 0;
                    }
                }
            }
        }

        $rate      = isset( $settings['base_rate'] ) ? (float) $settings['base_rate'] : 1.0;
        $base_mult = isset( $settings['base_multiplier'] ) ? (float) $settings['base_multiplier'] : 1.0;

        $user_id   = get_current_user_id();
        $multiplier = $base_mult;
        if ( $user_id && null !== $this->tier_service ) {
            $multiplier *= $this->tier_service->get_multiplier_for_user( $user_id );
        }

        $points = (int) floor( $subtotal * $rate * $multiplier );
        $points = max( 0, $points );

        return (int) apply_filters( 'wc_loyalty_rewards_cart_points_preview', $points, $cart, $user_id );
    }

    /**
     * Login activity earning.
     */
    public function earn_for_login_activity( int $user_id ): int {
        $settings = Settings_Cache::get();
        $rule     = $settings['login'] ?? [];
        if ( empty( $rule['enabled'] ) ) {
            return 0;
        }

        $threshold = isset( $rule['threshold'] ) ? (int) $rule['threshold'] : 0;
        $points    = isset( $rule['points'] ) ? (int) $rule['points'] : 0;

        if ( $threshold <= 0 || $points <= 0 ) {
            return 0;
        }

        $week_key = gmdate( 'o-W' ); // Year-week.
        $logins   = get_user_meta( $user_id, '_wclr_weekly_logins', true );
        if ( ! is_array( $logins ) ) {
            $logins = [];
        }
        $logins[ $week_key ] = isset( $logins[ $week_key ] ) ? (int) $logins[ $week_key ] + 1 : 1;
        update_user_meta( $user_id, '_wclr_weekly_logins', $logins );

        $rewarded = get_user_meta( $user_id, '_wclr_weekly_rewarded', true );
        if ( ! is_array( $rewarded ) ) {
            $rewarded = [];
        }

        if ( $logins[ $week_key ] >= $threshold && empty( $rewarded[ $week_key ] ) ) {
            $this->add_points(
                $user_id,
                $points,
                'earn',
                [
                    'context' => 'login_reward',
                ]
            );
            $rewarded[ $week_key ] = 1;
            update_user_meta( $user_id, '_wclr_weekly_rewarded', $rewarded );
            return $points;
        }

        return 0;
    }

    /**
     * Anniversary bonus.
     */
    public function earn_for_anniversary( int $user_id ): int {
        $settings = Settings_Cache::get();
        if ( empty( $settings['anniversary']['enabled'] ) ) {
            return 0;
        }
        $points = isset( $settings['anniversary']['points'] ) ? (int) $settings['anniversary']['points'] : 0;
        if ( $points <= 0 ) {
            return 0;
        }
        $year = (int) gmdate( 'Y' );
        $key  = '_wclr_anniversary_year';
        $last = (int) get_user_meta( $user_id, $key, true );
        if ( $last === $year ) {
            return 0;
        }
        $this->add_points(
            $user_id,
            $points,
            'earn',
            [
                'context' => 'anniversary',
            ]
        );
        update_user_meta( $user_id, $key, $year );
        return $points;
    }

    /**
     * Referral reward for first order.
     */
    public function earn_for_referral( int $referrer_id, int $referred_user_id, WC_Order $order, int $referrer_points, int $referred_points ): void {
        if ( $referrer_points > 0 ) {
            $this->add_points(
                $referrer_id,
                $referrer_points,
                'earn',
                [
                    'context'          => 'referral',
                    'order_id'         => $order->get_id(),
                    'referred_user_id' => $referred_user_id,
                ]
            );
        }

        if ( $referred_points > 0 ) {
            $this->add_points(
                $referred_user_id,
                $referred_points,
                'earn',
                [
                    'context'  => 'referral',
                    'order_id' => $order->get_id(),
                ]
            );
        }
    }

    /**
     * Admin adjustment.
     */
    public function adjust_points( int $user_id, int $amount, string $reason, ?int $admin_id ): int {
        $this->add_points(
            $user_id,
            $amount,
            'adjustment',
            [
                'context'   => 'admin_adjustment',
                'admin_id'  => $admin_id,
                'reason'    => $reason,
            ]
        );
        do_action( 'wc_loyalty_rewards_after_admin_adjustment', $user_id, $amount, (int) $admin_id, [ 'reason' => $reason ] );
        return $amount;
    }

    /**
     * Redeem points for an order.
     */
    public function redeem_points_for_order( WC_Order $order, int $points_to_redeem ): int {
        $user_id = $order->get_user_id();
        if ( ! $user_id || $points_to_redeem <= 0 ) {
            return 0;
        }

        $balance = $this->get_user_balance( $user_id )->balance;
        $points  = min( $points_to_redeem, $balance );
        if ( $points <= 0 ) {
            return 0;
        }

        do_action( 'wc_loyalty_rewards_before_redeem_points', $user_id, $points, $order );

        $this->add_points(
            $user_id,
            -1 * $points,
            'spend',
            [
                'context'  => 'order',
                'order_id' => $order->get_id(),
            ]
        );

        do_action( 'wc_loyalty_rewards_after_redeem_points', $user_id, $points, $order );
        return $points;
    }

    /**
     * Add points and log ledger entry.
     */
    public function add_points( int $user_id, int $amount, string $type, array $data ): Points_Ledger {
        $balance          = $this->get_user_balance( $user_id );
        $new_balance      = $balance->balance + $amount;
        $new_lifetime     = $balance->lifetime_points;
        if ( $type === 'earn' && $amount > 0 ) {
            $new_lifetime += $amount;
        }

        do_action( 'wc_loyalty_rewards_before_earn_points', $user_id, $amount, $data );

        // Batch update user meta to reduce queries.
        update_user_meta( $user_id, '_wclr_points_balance', $new_balance );
        update_user_meta( $user_id, '_wclr_lifetime_points', $new_lifetime );

        // Clear user meta cache for this user.
        wp_cache_delete( $user_id, 'user_meta' );

        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';
        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'user_id'       => $user_id,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $new_balance,
                'context'       => $data['context'] ?? '',
                'order_id'      => $data['order_id'] ?? null,
                'admin_id'      => $data['admin_id'] ?? null,
                'meta'          => ! empty( $data ) ? wp_json_encode( $data ) : null,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
        );

        // Log error if insert failed.
        if ( false === $result ) {
            error_log( 'WCLR: Failed to insert points ledger entry. Error: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        // Store pending reward notice for next page load (only for earn and positive amounts).
        if ( 'earn' === $type && $amount > 0 ) {
            update_user_meta(
                $user_id,
                '_wclr_pending_reward_notice',
                [
                    'amount'  => $amount,
                    'balance' => $new_balance,
                    'context' => $data['context'] ?? '',
                    'time'    => time(),
                ]
            );
        }

        do_action( 'wc_loyalty_rewards_after_earn_points', $user_id, $amount, $data );

        $ledger_id = $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : 0;

        return new Points_Ledger(
            [
                'id'            => $ledger_id,
                'user_id'       => $user_id,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $new_balance,
                'context'       => $data['context'] ?? '',
                'order_id'      => $data['order_id'] ?? null,
                'admin_id'      => $data['admin_id'] ?? null,
                'meta'          => ! empty( $data ) ? wp_json_encode( $data ) : null,
                'created_at'    => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Get user balance and lifetime points.
     */
    public function get_user_balance( int $user_id ): Points_Balance {
        // Use single meta query to reduce DB calls.
        $meta = get_user_meta( $user_id );
        $balance  = isset( $meta['_wclr_points_balance'] ) ? (int) $meta['_wclr_points_balance'][0] : 0;
        $lifetime = isset( $meta['_wclr_lifetime_points'] ) ? (int) $meta['_wclr_lifetime_points'][0] : 0;
        return new Points_Balance( $user_id, $balance, $lifetime );
    }

    /**
     * Get user tier through Tier_Service.
     */
    public function get_user_tier( int $user_id ): ?Tier {
        if ( null === $this->tier_service ) {
            $this->tier_service = new Tier_Service();
        }
        return $this->tier_service->get_user_tier( $user_id );
    }

    /**
     * Get recent ledger entries for a user.
     *
     * @param int $user_id User ID.
     * @param int $limit   Number of entries to return.
     * @return array<int, Points_Ledger>
     */
    public function get_recent_ledger_entries( int $user_id, int $limit = 10 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';
        $limit = max( 1, min( 100, $limit ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $user_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( empty( $rows ) ) {
            return [];
        }
        return array_map(
            static function ( array $row ): Points_Ledger {
                return new Points_Ledger( $row );
            },
            $rows
        );
    }

    /**
     * Recalculate lifetime points for all users based on positive earn ledger entries.
     *
     * @return int Number of users updated.
     */
    public function recalc_lifetime_points_all(): int {
        global $wpdb;
        $table   = $wpdb->prefix . 'wclr_points_ledger';
        $results = $wpdb->get_results( "SELECT user_id, SUM(amount) AS total FROM {$table} WHERE type = 'earn' AND amount > 0 GROUP BY user_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( empty( $results ) ) {
            return 0;
        }
        $updated = 0;
        foreach ( $results as $row ) {
            $user_id  = (int) $row['user_id'];
            $lifetime = (int) $row['total'];
            update_user_meta( $user_id, '_wclr_lifetime_points', $lifetime );
            wp_cache_delete( $user_id, 'user_meta' );
            $updated++;
        }
        return $updated;
    }
}

