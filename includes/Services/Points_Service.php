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

        // Skip earning if excluded coupons are present
        $has_excluded_coupon = false;

        // Check if "exclude all coupons" is enabled
        if ( ! empty( $settings['order_earning']['exclude_all_coupons'] ) ) {
            $applied_coupons = $order->get_coupon_codes();
            if ( ! empty( $applied_coupons ) && is_array( $applied_coupons ) && count( $applied_coupons ) > 0 ) {
                $has_excluded_coupon = true;
            }
        }

        // If not excluding all, check for specific excluded coupons
        if ( ! $has_excluded_coupon && ! empty( $settings['order_earning']['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = $settings['order_earning']['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
                $applied_coupons = $order->get_coupon_codes();
                // Normalize coupon codes to lowercase for case-insensitive comparison
                $excluded_coupons_normalized = array_map( 'strtolower', $excluded_coupons );
                foreach ( $applied_coupons as $applied_code ) {
                    $applied_code_normalized = strtolower( $applied_code );
                    if ( in_array( $applied_code_normalized, $excluded_coupons_normalized, true ) ) {
                        $has_excluded_coupon = true;
                        break;
                    }
                }
            }
        }

        if ( $has_excluded_coupon ) {
            return 0;
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

        // Subtract coupon discounts - points are earned on amount actually paid
        // get_total_discount() returns discount excluding tax
        $coupon_discount = (float) $order->get_total_discount();
        if ( $include_tax ) {
            // Add discount tax if tax is included in earning calculation
            $coupon_discount += (float) $order->get_discount_tax();
        }
        $subtotal -= $coupon_discount;

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
            // Atomically guard against concurrent status-change events (bulk edits,
            // gateway IPN retries, ERP integrations) double-awarding order points.
            // The early get_meta() guard above is a fast path; this re-reads the
            // flag fresh under a per-order lock so exactly one writer awards. On
            // lock-acquire failure we bail (return 0): another worker holds the
            // lock and is awarding, so we must NOT proceed unguarded.
            $order_id = $order->get_id();
            return (int) $this->with_order_lock(
                $order_id,
                'order_award',
                function () use ( $order, $order_id, $user_id, $points ) {
                    $fresh  = wc_get_order( $order_id );
                    $target = $fresh ? $fresh : $order;
                    if ( $target->get_meta( '_wclr_points_awarded', true ) ) {
                        return 0;
                    }
                    $this->add_points(
                        $user_id,
                        $points,
                        'earn',
                        [
                            'context'  => 'order',
                            'order_id' => $order_id,
                        ]
                    );
                    $target->update_meta_data( '_wclr_points_awarded', $points );
                    // Allow a future cancel/refund to reverse this fresh award.
                    $target->delete_meta_data( '_wclr_points_earn_reversed' );
                    $target->save();
                    return $points;
                },
                0
            );
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

        // Durable, identity-stable guard. The per-user `_wclr_signup_awarded` meta
        // is wiped when an account is deleted, so a delete + re-register (which gets
        // a fresh user_id) would otherwise re-earn the bonus. The ledger row
        // persists (even orphaned), so we record the registrant's email hash there
        // and refuse a repeat for the same email.
        $email_hash = $this->signup_email_hash( $user_id );
        if ( '' !== $email_hash && $this->signup_already_awarded_for_email( $email_hash ) ) {
            update_user_meta( $user_id, '_wclr_signup_awarded', 1 );
            return 0;
        }

        $this->add_points(
            $user_id,
            $points,
            'earn',
            [
                'context'    => 'signup',
                'email_hash' => $email_hash,
            ]
        );
        update_user_meta( $user_id, '_wclr_signup_awarded', 1 );
        return $points;
    }

    /**
     * Stable hash of a user's email, used for once-per-identity signup dedup.
     */
    private function signup_email_hash( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return '';
        }
        return md5( strtolower( trim( $user->user_email ) ) );
    }

    /**
     * Whether a signup bonus was ever awarded to this email hash.
     *
     * Reads the ledger (which is not removed when a WordPress user is deleted), so
     * the guard survives account deletion and re-registration.
     */
    private function signup_already_awarded_for_email( string $email_hash ): bool {
        if ( '' === $email_hash ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';
        $like  = '%' . $wpdb->esc_like( '"email_hash":"' . $email_hash . '"' ) . '%';
        $found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE context = 'signup' AND meta LIKE %s LIMIT 1",
                $like
            )
        );
        return (bool) $found;
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

        // Subtract coupon discounts - points are earned on amount actually paid
        $coupon_discount = (float) $cart->get_discount_total();
        if ( ! empty( $settings['order_earning']['include_tax'] ) ) {
            $coupon_discount += (float) $cart->get_discount_tax();
        }
        $subtotal -= $coupon_discount;

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

        // Skip earning if excluded coupons are present
        $has_excluded_coupon = false;

        // Check if "exclude all coupons" is enabled
        if ( ! empty( $settings['order_earning']['exclude_all_coupons'] ) ) {
            $applied_coupons = $cart->get_applied_coupons();
            if ( ! empty( $applied_coupons ) && is_array( $applied_coupons ) && count( $applied_coupons ) > 0 ) {
                $has_excluded_coupon = true;
            }
        }

        // If not excluding all, check for specific excluded coupons
        if ( ! $has_excluded_coupon && ! empty( $settings['order_earning']['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = $settings['order_earning']['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
                $applied_coupons = $cart->get_applied_coupons();
                // Normalize coupon codes to lowercase for case-insensitive comparison
                $excluded_coupons_normalized = array_map( 'strtolower', $excluded_coupons );
                foreach ( $applied_coupons as $applied_code ) {
                    $applied_code_normalized = strtolower( $applied_code );
                    if ( in_array( $applied_code_normalized, $excluded_coupons_normalized, true ) ) {
                        $has_excluded_coupon = true;
                        break;
                    }
                }
            }
        }

        if ( $has_excluded_coupon ) {
            return 0;
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

        // Get user's signup date to exclude it from daily visit counting (prevents double rewards on signup day).
        $user_data = get_userdata( $user_id );
        $signup_date = null;
        if ( $user_data && ! empty( $user_data->user_registered ) ) {
            $registered_dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $user_data->user_registered, new \DateTimeZone( 'UTC' ) );
            if ( $registered_dt ) {
                $signup_date = $registered_dt->format( 'Y-m-d' );
            }
        }

        // Get visited days for this user.
        $visited_days = get_user_meta( $user_id, '_wclr_daily_visits', true );
        if ( ! is_array( $visited_days ) ) {
            $visited_days = [];
        }

        // Remove signup day from visited_days if it exists (legacy data from before this fix).
        // This prevents signup day from counting towards daily visit threshold.
        $removed_signup_date = false;
        if ( $signup_date && isset( $visited_days[ $signup_date ] ) ) {
            unset( $visited_days[ $signup_date ] );
            $removed_signup_date = true;
        }

        // Track today's visit (only once per day), but exclude signup day from counting.
        $should_update_meta = $removed_signup_date;
        if ( ! isset( $visited_days[ $today ] ) && $signup_date !== $today ) {
            $visited_days[ $today ] = 1;
            $should_update_meta = true;
        }

        // Update meta if we made changes, and clean up old dates while we're at it.
        if ( $should_update_meta ) {
            // Clean up old dates (keep only last 30 days for efficiency).
            $thirty_days_ago = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
            foreach ( $visited_days as $date => $value ) {
                if ( $date < $thirty_days_ago ) {
                    unset( $visited_days[ $date ] );
                }
            }

            update_user_meta( $user_id, '_wclr_daily_visits', $visited_days );
        }

        // Count unique days visited, excluding the signup day.
        $days_visited = 0;
        foreach ( $visited_days as $date => $value ) {
            if ( $date !== $signup_date ) {
                $days_visited++;
            }
        }

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
     *
     * Dedupes on the membership-anniversary ordinal (the number of full years
     * elapsed since registration) rather than the calendar year. This guarantees
     * a user can never be paid twice for the same anniversary even if an award
     * landed early (e.g. legacy day-0 awards stamped with the calendar year), and
     * it cannot pay a "0th" anniversary for an account younger than one year.
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

        // Allow filtering of anniversary bonus points.
        $points = (int) apply_filters( 'wc_loyalty_rewards_anniversary_bonus', $points, $user_id );
        if ( $points <= 0 ) {
            return 0;
        }

        // The anniversary being paid = number of full years since registration.
        $ordinal = $this->anniversary_ordinal( $user_id );
        if ( $ordinal <= 0 ) {
            // Defence in depth: never pay an anniversary for an account that has not
            // completed a full year, even if a caller bypasses the cron date-guard.
            return 0;
        }

        $ordinal_key = '_wclr_anniversary_last_ordinal';
        $last_paid   = (int) get_user_meta( $user_id, $ordinal_key, true );
        if ( $ordinal <= $last_paid ) {
            // This anniversary (or an earlier one) was already paid.
            return 0;
        }

        // Transition safety: the legacy guard stored the calendar year of the last
        // award. If a payout already happened this calendar year under the old
        // scheme, do not pay again during the upgrade window.
        $year        = (int) gmdate( 'Y' );
        $legacy_year = (int) get_user_meta( $user_id, '_wclr_anniversary_year', true );
        if ( $legacy_year === $year ) {
            return 0;
        }

        try {
            $this->add_points(
                $user_id,
                $points,
                'earn',
                [
                    'context' => 'anniversary',
                    'ordinal' => $ordinal,
                ]
            );
            // Only update guards if points were successfully added.
            update_user_meta( $user_id, $ordinal_key, $ordinal );
            update_user_meta( $user_id, '_wclr_anniversary_year', $year ); // Keep legacy guard in sync.
            return $points;
        } catch ( \Exception $e ) {
            // If add_points fails, don't update meta so user can retry.
            // Re-throw to allow caller to handle if needed.
            throw $e;
        }
    }

    /**
     * Number of full years elapsed since a user registered (their anniversary ordinal).
     *
     * Mirrors Cron_Service's start-of-day date-guard and its Feb-29 -> Feb-28
     * leap-year substitution so the ordinal matches the day the cron actually fires.
     *
     * @param int $user_id User ID.
     * @return int Completed anniversaries as of today (0 if under one year).
     */
    private function anniversary_ordinal( int $user_id ): int {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_registered ) ) {
            return 0;
        }
        $reg = \DateTime::createFromFormat( 'Y-m-d H:i:s', $user->user_registered, new \DateTimeZone( 'UTC' ) );
        if ( ! $reg ) {
            return 0;
        }
        $reg->setTime( 0, 0, 0 );
        $today = new \DateTime( 'today', new \DateTimeZone( 'UTC' ) );
        if ( $today < $reg ) {
            return 0;
        }

        $years     = (int) $today->format( 'Y' ) - (int) $reg->format( 'Y' );
        $reg_month = (int) $reg->format( 'm' );
        $reg_day   = (int) $reg->format( 'd' );
        $cur_year  = (int) $today->format( 'Y' );
        $is_leap   = ( 0 === $cur_year % 4 && 0 !== $cur_year % 100 ) || 0 === $cur_year % 400;

        // A Feb-29 registrant's anniversary falls on Feb-28 in non-leap years.
        $anniv_day = ( 2 === $reg_month && 29 === $reg_day && ! $is_leap ) ? 28 : $reg_day;
        $today_md  = (int) $today->format( 'md' );
        $anniv_md  = ( $reg_month * 100 ) + $anniv_day;
        if ( $today_md < $anniv_md ) {
            // This calendar year's anniversary has not arrived yet.
            $years--;
        }

        return max( 0, $years );
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

        // Reject relative/textual values (e.g. "today", "now", "+0 days", "next monday").
        // strtotime() would resolve these to the current date, which would always match
        // the cron-run day and hand a birthday bonus to someone with no real birthday set.
        // Only strictly numeric date strings are allowed past this point.
        if ( preg_match( '/[A-Za-z]/', $value ) ) {
            return null;
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
     *
     * Now finalized from server-side hooks (payment complete / paid status) as well
     * as the thankyou page, so the deduction is serialized under a per-order lock and
     * the duplicate guard is re-read fresh inside the lock to prevent double-deducts
     * across concurrent requests (e.g. webhook + thankyou).
     */
    public function redeem_points_for_order( WC_Order $order, int $points_to_redeem ): int {
        $user_id = $order->get_user_id();
        if ( ! $user_id || $points_to_redeem <= 0 ) {
            return 0;
        }

        $order_id = $order->get_id();
        return (int) $this->with_order_lock(
            $order_id,
            'order_redeem',
            function () use ( $order, $order_id, $user_id, $points_to_redeem ) {
                $fresh = wc_get_order( $order_id );
                $target = $fresh ? $fresh : $order;

                // Prevent duplicate redemptions (re-read fresh under the lock).
                if ( $target->get_meta( '_wclr_points_redeemed', true ) ) {
                    return 0;
                }

                $balance = $this->get_user_balance( $user_id )->balance;
                $points  = min( $points_to_redeem, $balance );
                if ( $points <= 0 ) {
                    return 0;
                }

                do_action( 'wc_loyalty_rewards_before_redeem_points', $user_id, $points, $target );

                $this->add_points(
                    $user_id,
                    -1 * $points,
                    'spend',
                    [
                        'context'  => 'order',
                        'order_id' => $order_id,
                    ]
                );

                // Mark order as redeemed; allow a later refund to restore these points.
                $target->update_meta_data( '_wclr_points_redeemed', $points );
                $target->delete_meta_data( '_wclr_redeem_restored' );
                $target->save();

                do_action( 'wc_loyalty_rewards_after_redeem_points', $user_id, $points, $target );
                return $points;
            },
            0
        );
    }

    /**
     * Reverse points earned for an order when it is refunded or cancelled.
     *
     * Honors the order_earning.refund_behavior setting (reverse|prorate|ignore).
     * Idempotent via the _wclr_points_earn_reversed order flag. Balance is reduced;
     * lifetime is intentionally left unchanged (lifetime = total ever earned).
     *
     * @param WC_Order $order Order leaving an earning status.
     * @return int Points reversed.
     */
    public function reverse_order_earnings( WC_Order $order ): int {
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $settings = Settings_Cache::get();
        $behavior = $settings['order_earning']['refund_behavior'] ?? 'reverse';
        if ( 'ignore' === $behavior ) {
            return 0;
        }

        $order_id = $order->get_id();
        return (int) $this->with_order_lock(
            $order_id,
            'order_award',
            function () use ( $order, $order_id, $user_id ) {
                $fresh   = wc_get_order( $order_id );
                $target  = $fresh ? $fresh : $order;
                $awarded = (int) $target->get_meta( '_wclr_points_awarded', true );
                if ( $awarded <= 0 ) {
                    return 0;
                }

                // Do not drive the balance negative: only claw back what the user
                // still holds. The actually-reversed amount is recorded for audit.
                $balance = $this->get_user_balance( $user_id )->balance;
                $reverse = (int) min( $awarded, max( 0, $balance ) );
                if ( $reverse > 0 ) {
                    $this->add_points(
                        $user_id,
                        -1 * $reverse,
                        'adjustment',
                        [
                            'context'  => 'order_refund_reversal',
                            'order_id' => $order_id,
                        ]
                    );
                }

                $target->update_meta_data( '_wclr_points_earn_reversed', $reverse );
                // Clear the award flag so reactivating the order can re-award once.
                $target->delete_meta_data( '_wclr_points_awarded' );
                $target->save();
                return $reverse;
            },
            0
        );
    }

    /**
     * Restore points a customer redeemed on an order when it is refunded/cancelled.
     *
     * Honors the redemption.return_on_refund setting. Idempotent via the
     * _wclr_redeem_restored order flag.
     *
     * @param WC_Order $order Order leaving a paid status.
     * @return int Points restored.
     */
    public function restore_redeemed_points( WC_Order $order ): int {
        $settings = Settings_Cache::get();
        if ( empty( $settings['redemption']['return_on_refund'] ) ) {
            return 0;
        }
        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $order_id = $order->get_id();
        return (int) $this->with_order_lock(
            $order_id,
            'order_redeem',
            function () use ( $order, $order_id, $user_id ) {
                $fresh    = wc_get_order( $order_id );
                $target   = $fresh ? $fresh : $order;
                $redeemed = (int) $target->get_meta( '_wclr_points_redeemed', true );
                if ( $redeemed <= 0 ) {
                    return 0;
                }

                $this->add_points(
                    $user_id,
                    $redeemed,
                    'adjustment',
                    [
                        'context'  => 'redeem_refund',
                        'order_id' => $order_id,
                    ]
                );

                $target->update_meta_data( '_wclr_redeem_restored', $redeemed );
                // Clear the redeemed flag so reactivating the order re-deducts the spend.
                $target->delete_meta_data( '_wclr_points_redeemed' );
                $target->save();
                return $redeemed;
            },
            0
        );
    }

    /**
     * Acquire a cross-process advisory lock via MySQL GET_LOCK().
     *
     * Unlike wp_cache_add() (which is request-local without a persistent object
     * cache drop-in), a MySQL named lock serializes across separate PHP
     * processes/requests on the same database server.
     *
     * Note: callers may nest a per-order lock around the per-user lock taken here,
     * which relies on multiple simultaneous named locks per session (MySQL >= 5.7.5
     * / MariaDB >= 10.0.2). The plugin already requires WooCommerce 8.0+, so every
     * supported database satisfies this.
     *
     * @param string $name    Logical lock name.
     * @param int    $timeout Seconds to wait for the lock.
     * @return bool True if the lock was granted.
     */
    private function db_lock( string $name, int $timeout = 5 ): bool {
        global $wpdb;
        $res = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->db_lock_key( $name ), $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return '1' === (string) $res;
    }

    /**
     * Release a lock previously acquired with db_lock().
     */
    private function db_unlock( string $name ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->db_lock_key( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Build a stable lock name (<=64 chars) namespaced by table prefix.
     */
    private function db_lock_key( string $name ): string {
        global $wpdb;
        return 'wclr_' . md5( $wpdb->prefix . $name );
    }

    /**
     * Run a callback while holding a per-order cross-process lock.
     *
     * Returns the callback result, or $default if the lock could not be acquired
     * (which callers treat as "another worker holds it"). The lock is always
     * released, including when the callback throws.
     *
     * @param int      $order_id Order ID the lock is scoped to.
     * @param string   $suffix   Lock purpose (e.g. 'order_award', 'order_redeem').
     * @param callable $fn       Critical section.
     * @param mixed    $default  Value returned when the lock is not acquired.
     * @return mixed
     */
    private function with_order_lock( int $order_id, string $suffix, callable $fn, $default = 0 ) {
        $name = $suffix . '_' . $order_id;
        if ( ! $this->db_lock( $name, 10 ) ) {
            return $default;
        }
        try {
            return $fn();
        } finally {
            $this->db_unlock( $name );
        }
    }

    /**
     * Add points and log ledger entry.
     *
     * Serializes the read-modify-write of the user balance with a MySQL named lock
     * (cross-process) plus a best-effort object-cache lock, preventing lost updates
     * and duplicate awards under concurrent requests.
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

        // Authoritative cross-process lock: serializes the balance read-modify-write
        // across separate PHP requests even without a persistent object cache. Fail
        // closed (like the cache-lock timeout above) so the balance is never updated
        // without serialization, which is exactly when a double-award would occur.
        $db_locked = $this->db_lock( 'points_' . $user_id, $lock_timeout );
        if ( ! $db_locked ) {
            wp_cache_delete( $lock_key, '' );
            delete_transient( $lock_key );
            throw new \RuntimeException( 'WCLR: Unable to acquire DB lock for points update. User ID: ' . $user_id );
        }

        try {
            $balance          = $this->get_user_balance( $user_id );
            $new_balance      = $balance->balance + $amount;
            $new_lifetime     = $balance->lifetime_points;
            // Lifetime counts EARNED points only (matches recalc_lifetime_points_all).
            // Admin/manual adjustments and system refund reversals/restorations are
            // deliberately excluded so they cannot inflate tier eligibility with
            // points the customer did not earn.
            if ( $type === 'earn' && $amount > 0 ) {
                $new_lifetime += $amount;
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
            // Always release lock (DB lock, cache and transient).
            if ( $db_locked ) {
                $this->db_unlock( 'points_' . $user_id );
            }
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

