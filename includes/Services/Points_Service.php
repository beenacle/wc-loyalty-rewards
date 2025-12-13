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
            $excluded_coupons = $settings['order_earning']['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
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
        $min_order        = (float) ( $settings['order_earning']['min_order'] ?? 0 );

        $subtotal = (float) $order->get_subtotal();
        if ( $include_tax ) {
            $subtotal += (float) $order->get_total_tax();
        }
        if ( $include_shipping ) {
            $subtotal += (float) $order->get_shipping_total();
        }

        // Subtract redemption discount - points are earned on amount actually paid
        // Check for loyalty points fee (negative fee = discount)
        $fees = $order->get_fees();
        foreach ( $fees as $fee ) {
            $fee_name = $fee->get_name();
            // Check if this is our loyalty points discount fee
            // Match both old format ('Loyalty Points') and new format ('🎁 Points Redeemed: X')
            $is_old_format = ( __( 'Loyalty Points', 'wc-loyalty-rewards' ) === $fee_name || 'Loyalty Points' === $fee_name );
            // New format uses sprintf with translated string '🎁 Points Redeemed: %s'
            // Check if fee name starts with the translated prefix (without the %s placeholder)
            $points_redeemed_prefix = str_replace( '%s', '', __( '🎁 Points Redeemed: %s', 'wc-loyalty-rewards' ) );
            $is_new_format = ( false !== strpos( $fee_name, $points_redeemed_prefix ) || false !== strpos( $fee_name, 'Points Redeemed' ) );
            if ( $is_old_format || $is_new_format ) {
                $fee_amount = (float) $fee->get_total();
                // Fee amount is negative (discount), so subtract it (which adds to subtotal)
                $subtotal += $fee_amount; // Adding negative number = subtracting
                break;
            }
        }

        if ( $subtotal < $min_order ) {
            return 0;
        }

        // Flash multiplier (time-bound, optional product scope).
        $flash_multiplier = $this->get_flash_multiplier_for_order( $order );

        $points = (int) floor( $subtotal * $rate * $multiplier * $flash_multiplier );
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
        $points = (int) ( $settings['signup_bonus']['points'] ?? 0 );
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
     * Accounts for redemption discounts - points are earned on the amount actually paid.
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

        // Calculate redemption discount that will be applied
        $redemption_discount = 0.0;
        $config = $settings['redemption'] ?? [];
        if ( ! empty( $config['enabled'] ) && get_current_user_id() ) {
            $user_id = get_current_user_id();
            $balance = $this->get_user_balance( $user_id )->balance;
            $ratio_points = (int) ( $config['points_per_unit'] ?? 100 );
            $ratio_value  = (float) ( $config['unit_value'] ?? 1.0 );

            if ( $ratio_points > 0 && $ratio_value > 0 ) {
                $points_to_redeem = (int) WC()->session->get( 'wclr_points_to_redeem', 0 );
                $manual_override  = WC()->session->get( 'wclr_manual_override', false );

                // If auto mode is enabled and user hasn't manually set points, calculate auto points
                if ( ! $manual_override && ! empty( $config['auto_mode'] ) && 'disabled' !== $config['auto_mode'] ) {
                    if ( 'max' === $config['auto_mode'] ) {
                        $points_to_redeem = $balance;
                    } elseif ( 'percent' === $config['auto_mode'] ) {
                        $percent = isset( $config['auto_percent'] ) ? (int) $config['auto_percent'] : 0;
                        $points_to_redeem = (int) floor( $balance * ( $percent / 100 ) );
                    }
                }

                $points_to_redeem = min( $points_to_redeem, $balance );

                if ( $points_to_redeem > 0 ) {
                    $redemption_discount = ( $points_to_redeem / $ratio_points ) * $ratio_value;

                    // Apply max_percent limit if set
                    $max_percent = (float) ( $config['max_percent'] ?? 0 );
                    if ( $max_percent > 0 ) {
                        $max_discount = $cart->get_subtotal() * ( $max_percent / 100 );
                        $redemption_discount = min( $redemption_discount, $max_discount );
                    }
                }
            }
        }

        // Subtract redemption discount from subtotal - points are earned on amount actually paid
        $subtotal -= $redemption_discount;
        $subtotal = max( 0, $subtotal ); // Ensure non-negative

        $min_order = isset( $settings['order_earning']['min_order'] ) ? (float) $settings['order_earning']['min_order'] : 0;
        if ( $subtotal < $min_order ) {
            return 0;
        }

        $flash_multiplier = $this->get_flash_multiplier_for_cart( $cart );

        // Skip earning if any applied coupon is in the exclusion list (only if exclusion is enabled).
        if ( ! empty( $settings['order_earning']['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = $settings['order_earning']['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
                $applied_coupons = $cart->get_applied_coupons();
                foreach ( $applied_coupons as $applied_code ) {
                    if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                        return 0;
                    }
                }
            }
        }

        $rate      = (float) ( $settings['base_rate'] ?? 1.0 );
        $base_mult = (float) ( $settings['base_multiplier'] ?? 1.0 );

        $user_id   = get_current_user_id();
        $multiplier = $base_mult;
        if ( $user_id && null !== $this->tier_service ) {
            $multiplier *= $this->tier_service->get_multiplier_for_user( $user_id );
        }

        $points = (int) floor( $subtotal * $rate * $multiplier * $flash_multiplier );
        $points = max( 0, $points );

        return (int) apply_filters( 'wc_loyalty_rewards_cart_points_preview', $points, $cart, $user_id );
    }

    /**
     * Daily visit activity earning.
     */
    public function earn_for_daily_visit( int $user_id ): int {
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

        $today = gmdate( 'Y-m-d' );

        // Check if we've already rewarded for today (prevents multiple rewards on same day after reset).
        $last_reward_date = get_user_meta( $user_id, '_wclr_last_visit_reward_date', true );
        if ( $last_reward_date === $today ) {
            return 0;
        }

        // Get visited days for this user.
        $visited_days = get_user_meta( $user_id, '_wclr_daily_visits', true );
        if ( ! is_array( $visited_days ) ) {
            $visited_days = [];
        }

        // Track today's visit (only once per day).
        if ( ! isset( $visited_days[ $today ] ) ) {
            $visited_days[ $today ] = 1;

            // Clean up old dates (keep only last 30 days for efficiency).
            $thirty_days_ago = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
            foreach ( $visited_days as $date => $value ) {
                if ( $date < $thirty_days_ago ) {
                    unset( $visited_days[ $date ] );
                }
            }

            update_user_meta( $user_id, '_wclr_daily_visits', $visited_days );
        }

        // Count unique days visited.
        $days_visited = count( $visited_days );

        // Check if threshold is reached.
        if ( $days_visited >= $threshold ) {
            $this->add_points(
                $user_id,
                $points,
                'earn',
                [
                    'context' => 'daily_visit_reward',
                ]
            );

            // Mark today as rewarded and reset visited days after reward to allow earning again.
            update_user_meta( $user_id, '_wclr_last_visit_reward_date', $today );
            $visited_days = [];
            update_user_meta( $user_id, '_wclr_daily_visits', $visited_days );

            return $points;
        }

        return 0;
    }

    /**
     * Birthday bonus.
     */
    public function earn_for_birthday( int $user_id, string $birthday_value, string $meta_key, ?string $preferred_format = null ): int {
        $settings = Settings_Cache::get();
        $rule     = $settings['birthday'] ?? [];
        if ( empty( $rule['enabled'] ) ) {
            return 0;
        }

        $points = (int) ( $rule['points'] ?? 0 );
        if ( $points <= 0 ) {
            return 0;
        }

        $date_parts = $this->parse_birthday_month_day( $birthday_value, $preferred_format );
        if ( null === $date_parts ) {
            return 0;
        }

        $today_month = gmdate( 'm' );
        $today_day   = gmdate( 'd' );
        if ( $date_parts['month'] !== $today_month || $date_parts['day'] !== $today_day ) {
            return 0;
        }

        $year = (int) gmdate( 'Y' );
        $key  = '_wclr_birthday_year';
        $last = (int) get_user_meta( $user_id, $key, true );
        if ( $last === $year ) {
            return 0;
        }

        $this->add_points(
            $user_id,
            $points,
            'earn',
            [
                'context'        => 'birthday',
                'meta_key'       => $meta_key,
                'birthday_value' => $birthday_value,
            ]
        );
        update_user_meta( $user_id, $key, $year );

        return $points;
    }

    /**
     * Anniversary bonus.
     */
    public function earn_for_anniversary( int $user_id ): int {
        $settings = Settings_Cache::get();
        if ( empty( $settings['anniversary']['enabled'] ) ) {
            return 0;
        }
        $points = (int) ( $settings['anniversary']['points'] ?? 0 );
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
     * Parse a birthday string into month/day components.
     *
     * Supports common formats: Y-m-d, Y/m/d, Y.m.d, m-d, m/d, m.d, d-m, d/m, d.m.
     *
     * @return array{month:string,day:string}|null
     */
    private function parse_birthday_month_day( string $value, ?string $preferred_format = null ): ?array {
        $value = trim( $value );
        if ( '' === $value ) {
            return null;
        }

        $formats = [
            'Y-m-d',
            'Y/m/d',
            'Y.m.d',
            'm-d',
            'm/d',
            'm.d',
            'd-m',
            'd/m',
            'd.m',
        ];

        if ( $preferred_format ) {
            $formats = array_values( array_unique( array_merge( [ $preferred_format ], $formats ) ) );
        }

        foreach ( $formats as $format ) {
            $dt = \DateTime::createFromFormat( $format, $value, new \DateTimeZone( 'UTC' ) );
            if ( $dt instanceof \DateTime ) {
                $month = (int) $dt->format( 'm' );
                $day   = (int) $dt->format( 'd' );
                if ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 ) {
                    return [
                        'month' => str_pad( (string) $month, 2, '0', STR_PAD_LEFT ),
                        'day'   => str_pad( (string) $day, 2, '0', STR_PAD_LEFT ),
                    ];
                }
            }
        }

        $timestamp = strtotime( $value );
        if ( false === $timestamp ) {
            return null;
        }

        return [
            'month' => gmdate( 'm', $timestamp ),
            'day'   => gmdate( 'd', $timestamp ),
        ];
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

        // Prevent duplicate redemptions if points were already redeemed for this order.
        if ( $order->get_meta( '_wclr_points_redeemed', true ) ) {
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

        // Mark order as having points redeemed to prevent duplicate processing.
        $order->update_meta_data( '_wclr_points_redeemed', $points );
        $order->save();

        do_action( 'wc_loyalty_rewards_after_redeem_points', $user_id, $points, $order );
        return $points;
    }

    /**
     * Add points and log ledger entry.
     * Uses transient-based locking to prevent race conditions.
     */
    public function add_points( int $user_id, int $amount, string $type, array $data ): Points_Ledger {
        // Use atomic cache-based locking to prevent concurrent updates (race condition protection).
        // wp_cache_add() is atomic - it only sets the value if it doesn't exist, preventing race conditions.
        $lock_key = 'wclr_points_lock_' . $user_id;
        $lock_timeout = 5; // 5 seconds max wait
        $lock_acquired = false;

        // Try to acquire lock atomically (max 5 attempts with 100ms delay to avoid blocking).
        $attempts = 0;
        while ( ! $lock_acquired && $attempts < $lock_timeout ) {
            // wp_cache_add() is atomic: returns true if lock was acquired, false if already exists.
            $lock_acquired = wp_cache_add( $lock_key, time(), '', 10 );
            if ( ! $lock_acquired ) {
                usleep( 100000 ); // 100ms delay (non-blocking for short waits).
                $attempts++;
            }
        }

        // If we couldn't acquire the lock after timeout, throw an exception.
        if ( ! $lock_acquired ) {
            throw new \RuntimeException( 'WCLR: Unable to acquire lock for points update after timeout. User ID: ' . $user_id );
        }

        // Also set transient as backup for cross-request persistence (if object cache is not persistent).
        set_transient( $lock_key, time(), 10 );

        try {
            $balance          = $this->get_user_balance( $user_id );
            $new_balance      = $balance->balance + $amount;
            $new_lifetime     = $balance->lifetime_points;
            if ( $type === 'earn' && $amount > 0 ) {
                $new_lifetime += $amount;
            }
            if ( $type === 'adjustment' && $amount > 0 ) {
                $new_lifetime += $amount; // Count positive admin adjustments toward lifetime.
            }

            $old_tier = null;
            if ( null !== $this->tier_service ) {
                $old_tier = $this->tier_service->get_user_tier( $user_id );
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

            // Log error if insert failed and throw exception for critical failures.
            if ( false === $result ) {
                $error_msg = 'WCLR: Failed to insert points ledger entry. Error: ' . $wpdb->last_error;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( $error_msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
                // Revert balance changes if ledger insert failed.
                update_user_meta( $user_id, '_wclr_points_balance', $balance->balance );
                update_user_meta( $user_id, '_wclr_lifetime_points', $balance->lifetime_points );
                wp_cache_delete( $user_id, 'user_meta' );
                throw new \RuntimeException( $error_msg );
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

            // Detect tier changes (after meta write).
            if ( null === $this->tier_service ) {
                $this->tier_service = new Tier_Service();
            }
            $new_tier = $this->tier_service->get_user_tier( $user_id );
            if ( ( $old_tier && ( ! $new_tier || $old_tier->id !== $new_tier->id ) ) || ( ! $old_tier && $new_tier ) ) {
                do_action( 'wc_loyalty_rewards_user_tier_changed', $user_id, $old_tier, $new_tier );
            }

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
        } finally {
            // Always release lock (both cache and transient).
            if ( $lock_acquired ) {
                wp_cache_delete( $lock_key, '' );
                delete_transient( $lock_key );
            }
        }
    }

    /**
     * Get user balance and lifetime points.
     */
    public function get_user_balance( int $user_id ): Points_Balance {
        // Use specific meta keys to avoid loading all user meta.
        $balance  = (int) get_user_meta( $user_id, '_wclr_points_balance', true );
        $lifetime = (int) get_user_meta( $user_id, '_wclr_lifetime_points', true );
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
     * Get total count of ledger entries for a user.
     *
     * @param int $user_id User ID.
     * @return int Total count of entries.
     */
    public function get_ledger_entries_count( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $count;
    }

    /**
     * Get recent ledger entries for a user.
     *
     * @param int $user_id User ID.
     * @param int $limit   Number of entries to return.
     * @param int $offset  Number of entries to skip (for pagination).
     * @return array<int, Points_Ledger>
     */
    public function get_recent_ledger_entries( int $user_id, int $limit = 10, int $offset = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';
        $limit = max( 1, min( 100, $limit ) );
        $offset = max( 0, $offset );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $user_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
        $results = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(amount) AS total FROM {$table} WHERE type = %s AND amount > 0 GROUP BY user_id", 'earn' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Map of user_id => lifetime from ledger.
        $ledger_totals = [];
        foreach ( (array) $results as $row ) {
            $ledger_totals[ (int) $row['user_id'] ] = (int) $row['total'];
        }

        // Users who currently have lifetime meta set (so we can zero those with no earns).
        $meta_users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", '_wclr_lifetime_points' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $meta_users = array_map( 'intval', (array) $meta_users );

        $updated = 0;
        $tier_service = $this->tier_service ?? new Tier_Service();

        // Update all users with ledger totals.
        foreach ( $ledger_totals as $user_id => $lifetime ) {
            $old_tier = $tier_service->get_user_tier( $user_id );
            update_user_meta( $user_id, '_wclr_lifetime_points', $lifetime );
            wp_cache_delete( $user_id, 'user_meta' );
            $new_tier = $tier_service->get_user_tier( $user_id );
            if ( ( $old_tier && ( ! $new_tier || $old_tier->id !== $new_tier->id ) ) || ( ! $old_tier && $new_tier ) ) {
                /**
                 * Fires when a user's tier changes after lifetime recalculation.
                 *
                 * @param int       $user_id  User ID.
                 * @param Tier|null $old_tier Previous tier.
                 * @param Tier|null $new_tier New tier.
                 */
                do_action( 'wc_loyalty_rewards_user_tier_changed', $user_id, $old_tier, $new_tier );
            }
            $updated++;
        }

        // For users that had lifetime meta but no earns, set to zero for consistency.
        foreach ( $meta_users as $user_id ) {
            if ( isset( $ledger_totals[ $user_id ] ) ) {
                continue;
            }
            $old_tier = $tier_service->get_user_tier( $user_id );
            update_user_meta( $user_id, '_wclr_lifetime_points', 0 );
            wp_cache_delete( $user_id, 'user_meta' );
            $new_tier = $tier_service->get_user_tier( $user_id );
            if ( ( $old_tier && ( ! $new_tier || $old_tier->id !== $new_tier->id ) ) || ( ! $old_tier && $new_tier ) ) {
                do_action( 'wc_loyalty_rewards_user_tier_changed', $user_id, $old_tier, $new_tier );
            }
            $updated++;
        }

        return $updated;
    }

    /**
     * Determine flash multiplier for an order (time-bound, optional product scoping).
     */
    private function get_flash_multiplier_for_order( WC_Order $order ): float {
        $settings = Settings_Cache::get();
        $flash    = $settings['flash_earning'] ?? [];
        if ( empty( $flash['enabled'] ) ) {
            return 1.0;
        }
        $product_ids = [];
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( $product_id ) {
                $product_ids[] = (int) $product_id;
            }
        }
        return $this->compute_flash_multiplier( $flash, $product_ids );
    }

    /**
     * Determine flash multiplier for cart estimation.
     */
    private function get_flash_multiplier_for_cart( WC_Cart $cart ): float {
        $settings = Settings_Cache::get();
        $flash    = $settings['flash_earning'] ?? [];
        if ( empty( $flash['enabled'] ) ) {
            return 1.0;
        }
        $product_ids = [];
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['product_id'] ) ) {
                $product_ids[] = (int) $item['product_id'];
            }
        }
        return $this->compute_flash_multiplier( $flash, $product_ids );
    }

    /**
     * Core flash multiplier logic shared by cart and order.
     *
     * @param array<int> $product_ids Product IDs involved.
     */
    private function compute_flash_multiplier( array $flash, array $product_ids ): float {
        $multiplier = isset( $flash['multiplier'] ) ? (float) $flash['multiplier'] : 1.0;
        if ( $multiplier <= 1 ) {
            return 1.0;
        }
        $now = current_time( 'timestamp' );

        if ( ! empty( $flash['start'] ) ) {
            $start_ts = strtotime( $flash['start'] );
            if ( $start_ts && $now < $start_ts ) {
                return 1.0;
            }
        }

        if ( ! empty( $flash['end'] ) ) {
            $end_ts = strtotime( $flash['end'] );
            if ( $end_ts && $now > $end_ts ) {
                return 1.0;
            }
        }

        $scoped_products = isset( $flash['product_ids'] ) ? (array) $flash['product_ids'] : [];
        $scoped_products = array_filter( array_map( 'intval', $scoped_products ) );

        if ( ! empty( $scoped_products ) ) {
            // Only apply if any product in cart/order is in scope.
            $intersection = array_intersect( $scoped_products, $product_ids );
            if ( empty( $intersection ) ) {
                return 1.0;
            }
        }

        return $multiplier;
    }
}

