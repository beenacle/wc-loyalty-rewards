<?php

namespace WCLR\Services;

use WC_Order;
use WCLR\Helpers\Settings_Cache;
use WCLR\Models\Referral;

defined( 'ABSPATH' ) || exit;

/**
 * Manages referral codes and rewards.
 */
class Referral_Service {

    private Points_Service $points_service;

    public function __construct( Points_Service $points_service ) {
        $this->points_service = $points_service;
    }

    /**
     * Register hooks for referral tracking.
     */
    public function register(): void {
        add_action( 'user_register', [ $this, 'assign_referral_code' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'attach_referrer_meta' ], 10, 2 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_reward_referral' ], 20, 1 );
    }

    /**
     * Create referral code for new users.
     */
    public function assign_referral_code( int $user_id ): void {
        $code = get_user_meta( $user_id, '_wclr_referral_code', true );
        if ( $code ) {
            return;
        }

        // Ensure uniqueness by retrying a few times before a deterministic fallback.
        $code = $this->generate_unique_code( $user_id );
        update_user_meta( $user_id, '_wclr_referral_code', $code );
    }

    /**
     * Get or create the user's referral code.
     */
    public function get_referral_code( int $user_id ): string {
        $code = get_user_meta( $user_id, '_wclr_referral_code', true );
        if ( $code ) {
            return $code;
        }
        $this->assign_referral_code( $user_id );
        return (string) get_user_meta( $user_id, '_wclr_referral_code', true );
    }

    /**
     * Capture ref parameter during checkout and store in order meta.
     */
    public function attach_referrer_meta( int $order_id, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        if ( empty( $_COOKIE['wclr_ref'] ) ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables
            return;
        }
        $code     = sanitize_text_field( wp_unslash( $_COOKIE['wclr_ref'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $referrer = $this->get_user_by_code( $code );
        if ( $referrer ) {
            // Write via the order object so attribution is stored in the correct
            // place under HPOS (the order is read back with $order->get_meta()).
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_wclr_referrer_id', $referrer );
                $order->save();
            }
        }
    }

    /**
     * Reward referrer on first completed order.
     */
    public function maybe_reward_referral( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $referrer_id = (int) $order->get_meta( '_wclr_referrer_id', true );
        $user_id     = $order->get_user_id();
        if ( ! $user_id ) {
            return;
        }

        $settings = Settings_Cache::get();
        if ( empty( $settings['referral']['enabled'] ) ) {
            return;
        }

        $table  = $GLOBALS['wpdb']->prefix . 'wclr_referrals';
        $record = $GLOBALS['wpdb']->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $GLOBALS['wpdb']->prepare( "SELECT * FROM {$table} WHERE referred_user_id = %d", $user_id ),
            ARRAY_A
        );

        // If order meta is missing, fall back to existing referral record.
        if ( ! $referrer_id && $record ) {
            $referrer_id = (int) $record['referrer_id'];
        }

        if ( ! $referrer_id ) {
            return;
        }

        // Prevent self-referral, including a second account opened by the same person
        // (matched on normalized account/billing email, which defeats the gmail
        // dot/+alias trick used to farm referral bonuses).
        if ( $this->is_self_referral( $referrer_id, $user_id, $order ) ) {
            return;
        }

        // Guard: ensure this is the referred user's first order (exclude current).
        $prior_orders = wc_get_orders(
            [
                'customer_id' => $user_id,
                'exclude'     => [ $order_id ],
                'limit'       => 1,
                'return'      => 'ids',
                'status'      => [ 'processing', 'completed', 'on-hold' ],
            ]
        );
        if ( ! empty( $prior_orders ) || (int) get_user_meta( $user_id, '_wclr_referral_rewarded', true ) === 1 ) {
            return;
        }

        if ( $record && $record['status'] === 'completed' ) {
            return;
        }

        $referrer_points = (int) ( $settings['referral']['referrer_bonus'] ?? 0 );
        $referred_points = (int) ( $settings['referral']['referred_bonus'] ?? 0 );

        $this->points_service->earn_for_referral( $referrer_id, $user_id, $order, $referrer_points, $referred_points );

        // Mark referred user as rewarded to prevent repeats.
        update_user_meta( $user_id, '_wclr_referral_rewarded', 1 );

        if ( $record ) {
            $GLOBALS['wpdb']->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'first_order_id' => $order_id,
                    'status'         => 'completed',
                ],
                [ 'id' => $record['id'] ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
            wp_cache_delete( 'wclr_referrals_' . $referrer_id, 'wclr' );
        } else {
            $GLOBALS['wpdb']->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'referrer_id'      => $referrer_id,
                    'referred_user_id' => $user_id,
                    'referral_code'    => get_user_meta( $referrer_id, '_wclr_referral_code', true ),
                    'first_order_id'   => $order_id,
                    'status'           => 'completed',
                    'created_at'       => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%d', '%s', '%s' ]
            );
            wp_cache_delete( 'wclr_referrals_' . $referrer_id, 'wclr' );
        }
    }

    /**
     * Detect a self-referral, including a second account opened by the same person.
     *
     * Blocks when the referrer and referred are the same user id, or when their
     * normalized emails match (referrer account email vs the referred account email
     * and, when available, the order's billing email).
     *
     * @param int           $referrer_id Referrer user id.
     * @param int           $referred_id Referred user id.
     * @param WC_Order|null $order       Referred user's order (optional).
     * @return bool
     */
    private function is_self_referral( int $referrer_id, int $referred_id, ?WC_Order $order = null ): bool {
        if ( $referrer_id === $referred_id ) {
            return true;
        }
        $referrer = get_userdata( $referrer_id );
        if ( ! $referrer || empty( $referrer->user_email ) ) {
            return false;
        }
        $ref_email = $this->normalize_email( (string) $referrer->user_email );
        if ( '' === $ref_email ) {
            return false;
        }

        $candidates = [];
        $referred   = get_userdata( $referred_id );
        if ( $referred && ! empty( $referred->user_email ) ) {
            $candidates[] = $this->normalize_email( (string) $referred->user_email );
        }
        if ( $order instanceof WC_Order && $order->get_billing_email() ) {
            $candidates[] = $this->normalize_email( (string) $order->get_billing_email() );
        }

        return in_array( $ref_email, array_filter( $candidates ), true );
    }

    /**
     * Normalize an email for same-person comparison.
     *
     * Lowercases and trims, and for Gmail/Googlemail removes dots and any +alias in
     * the local part (which all deliver to the same inbox).
     */
    private function normalize_email( string $email ): string {
        $email = strtolower( trim( $email ) );
        if ( '' === $email || false === strpos( $email, '@' ) ) {
            return $email;
        }
        list( $local, $domain ) = explode( '@', $email, 2 );
        $plus = strpos( $local, '+' );
        if ( false !== $plus ) {
            $local = substr( $local, 0, $plus );
        }
        if ( in_array( $domain, [ 'gmail.com', 'googlemail.com' ], true ) ) {
            $local = str_replace( '.', '', $local );
        }
        return $local . '@' . $domain;
    }

    /**
     * Get user id by referral code.
     */
    public function get_user_by_code( string $code ): ?int {
        $user = get_users(
            [
                'meta_key'   => '_wclr_referral_code',
                'meta_value' => $code,
                'number'     => 1,
                'fields'     => 'ID',
            ]
        );
        if ( empty( $user ) ) {
            return null;
        }
        return (int) $user[0];
    }

    /**
     * Ensure referral cookie is set on visit.
     */
    public function capture_ref_param(): void {
        if ( isset( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $code = sanitize_text_field( wp_unslash( $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            // Set secure cookie with httponly flag for better security.
            $secure = is_ssl();
            setcookie( 'wclr_ref', $code, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
        }
    }

    /**
     * Create referral entry on registration with ref param.
     */
    public function maybe_log_referral_on_signup( int $user_id ): void {
        if ( empty( $_COOKIE['wclr_ref'] ) ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables
            return;
        }
        $code        = sanitize_text_field( wp_unslash( $_COOKIE['wclr_ref'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $referrer_id = $this->get_user_by_code( $code );
        if ( ! $referrer_id ) {
            return;
        }
        if ( $this->is_self_referral( $referrer_id, $user_id ) ) {
            return; // Block self-referral on signup (same id or same person's email).
        }

        $settings = Settings_Cache::get();
        if ( empty( $settings['referral']['enabled'] ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wclr_referrals';
        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'referrer_id'      => $referrer_id,
                'referred_user_id' => $user_id,
                'referral_code'    => $code,
                'status'           => 'pending',
                'created_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );

        // Clear referral cache on insert.
        if ( false !== $result ) {
            wp_cache_delete( 'wclr_referrals_' . $referrer_id, 'wclr' );
        }
    }

    /**
     * Get referral info for a user.
     */
    public function get_referrals_for_user( int $user_id ): array {
        // Try cache first.
        $cache_key = 'wclr_referrals_' . $user_id;
        $cached = wp_cache_get( $cache_key, 'wclr' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wclr_referrals';
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE referrer_id = %d ORDER BY created_at DESC LIMIT 20", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $referrals = array_map(
            static function ( array $row ): Referral {
                return new Referral( $row );
            },
            $rows
        );

        wp_cache_set( $cache_key, $referrals, 'wclr', HOUR_IN_SECONDS );
        return $referrals;
    }

    /**
     * Generate a unique referral code.
     */
    private function generate_unique_code( int $user_id ): string {
        $attempts = 0;
        do {
            $attempts++;
            $candidate = wp_generate_password( 10, false, false );
            $exists    = get_users(
                [
                    'meta_key'   => '_wclr_referral_code',
                    'meta_value' => $candidate,
                    'fields'     => 'ID',
                    'number'     => 1,
                ]
            );
            if ( empty( $exists ) ) {
                return $candidate;
            }
        } while ( $attempts < 5 );

        // Deterministic fallback to avoid collisions.
        return 'wclr-' . $user_id . '-' . wp_generate_password( 6, false, false );
    }
}

