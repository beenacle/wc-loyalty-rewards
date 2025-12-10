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
        add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ], 20, 1 );
        add_action( 'user_register', [ $this, 'handle_signup_bonus' ] );
        add_action( 'wp_login', [ $this, 'handle_login_activity' ], 10, 2 );
        add_action( 'init', [ $this, 'capture_ref_param' ] );
        add_action( 'user_register', [ $this->referrals, 'maybe_log_referral_on_signup' ] );
    }

    /**
     * Order completed earning.
     */
    public function handle_order_completed( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();
        $mult    = $user_id ? $this->tiers->get_multiplier_for_user( $user_id ) : 1.0;
        $settings = Settings_Cache::get();
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
     * Login handler.
     */
    public function handle_login_activity( string $user_login, \WP_User $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $this->points->earn_for_login_activity( $user->ID );
    }

    /**
     * Capture ref param for referrals.
     */
    public function capture_ref_param(): void {
        $this->referrals->capture_ref_param();
    }
}

