<?php

namespace WCLR\Services;

use WC_Order;
use WCLR\Helpers\Settings_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Registers earning triggers.
 */
class Earning_Rules_Manager {

    private Points_Service $points;
    private Tier_Service $tiers;
    private Referral_Service $referrals;

    public function __construct( Points_Service $points, Tier_Service $tiers, Referral_Service $referrals ) {
        $this->points    = $points;
        $this->tiers     = $tiers;
        $this->referrals = $referrals;
    }

    public function register(): void {
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 20, 4 );
        add_action( 'user_register', [ $this, 'handle_signup_bonus' ] );
        add_action( 'wp', [ $this, 'handle_daily_visit' ] );
        add_action( 'init', [ $this, 'capture_ref_param' ] );
        add_action( 'user_register', [ $this->referrals, 'maybe_log_referral_on_signup' ] );
    }

    /**
     * Order status changed earning.
     * Awards points when order reaches a configured status.
     *
     * @param int    $order_id Order ID.
     * @param string $old_status Old order status.
     * @param string $new_status New order status.
     * @param WC_Order $order Order object.
     */
    public function handle_order_status_changed( $order_id, string $old_status, string $new_status, $order ): void {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $settings = Settings_Cache::get();

        // Check if order earning is enabled
        if ( empty( $settings['order_earning']['enabled'] ) ) {
            return;
        }

        // Get configured order statuses (default to 'wc-completed' to match stored format)
        $allowed_statuses = isset( $settings['order_earning']['order_statuses'] ) && is_array( $settings['order_earning']['order_statuses'] )
            ? $settings['order_earning']['order_statuses']
            : [ 'wc-completed' ]; // Default matches wc_get_order_statuses() format

        // Normalize statuses: wc_get_order_statuses() returns keys with 'wc-' prefix (e.g., 'wc-completed'),
        // but the hook passes status without prefix (e.g., 'completed'), so we remove prefix for comparison
        // Handle both formats for backward compatibility
        $normalized_allowed = array_map(
            function( $status ) {
                // Remove 'wc-' prefix if present for comparison
                return str_replace( 'wc-', '', $status );
            },
            $allowed_statuses
        );
        $normalized_new = str_replace( 'wc-', '', $new_status );

        // Only award points if the new status is in the allowed list
        if ( ! in_array( $normalized_new, $normalized_allowed, true ) ) {
            return;
        }

        $user_id = $order->get_user_id();
        $mult    = $user_id ? $this->tiers->get_multiplier_for_user( $user_id ) : 1.0;
        $rate     = isset( $settings['base_rate'] ) ? (float) $settings['base_rate'] : 1.0;
        $base_mult = isset( $settings['base_multiplier'] ) ? (float) $settings['base_multiplier'] : 1.0;

        $this->points->earn_for_order( $order, $mult * $base_mult, $rate );
    }

    /**
     * Signup bonus handler.
     */
    public function handle_signup_bonus( int $user_id ): void {
        $this->points->earn_for_signup( $user_id );
    }

    /**
     * Daily visit handler - tracks visits for logged-in users.
     */
    public function handle_daily_visit(): void {
        // Only track frontend visits, not admin or AJAX requests.
        if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( $user_id ) {
            $this->points->earn_for_daily_visit( $user_id );
        }
    }

    /**
     * Capture ref param for referrals.
     */
    public function capture_ref_param(): void {
        $this->referrals->capture_ref_param();
    }
}

