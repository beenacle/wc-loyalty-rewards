<?php

namespace WCLR\Services;

use WC_Cart;
use WC_Order;
use WCLR\Helpers\Settings_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Handles points redemption integration with WooCommerce cart/checkout.
 */
class Redemption_Service {

    private Points_Service $points;
    private Tier_Service $tiers;

    public function __construct( Points_Service $points, Tier_Service $tiers ) {
        $this->points = $points;
        $this->tiers  = $tiers;
    }

    public function register(): void {
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_cart_discount' ] );
        add_action( 'wp', [ $this, 'maybe_capture_redeem_request' ] );
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_checkout_ui' ] );
        add_action( 'woocommerce_before_cart_totals', [ $this, 'render_cart_ui' ] );
        add_action( 'woocommerce_thankyou', [ $this, 'finalize_redemption_on_order' ], 20, 1 );
        add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'filter_checkout_fragments' ] );
        add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'filter_cart_fragments' ] );
        add_shortcode( 'wclr_redeem_widget', [ $this, 'shortcode_redeem_widget' ] );
        add_action( 'wp_ajax_wclr_redeem_points', [ $this, 'handle_ajax_redeem' ] );
        add_action( 'wp_ajax_nopriv_wclr_redeem_points', [ $this, 'handle_ajax_redeem' ] );
    }

    /**
     * Process incoming redeem form submission (non-AJAX fallback).
     */
    public function maybe_capture_redeem_request(): void {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        $settings = Settings_Cache::get();
        $config   = $settings['redemption'] ?? [];
        if ( empty( $_POST['wclr_redeem_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wclr_redeem_nonce'] ) ), 'wclr_redeem_points' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        // If manual input disabled, ignore submission.
        if ( empty( $config['allow_manual_input'] ) ) {
            return;
        }
        $points = isset( $_POST['wclr_points_to_redeem'] ) ? (int) wp_unslash( $_POST['wclr_points_to_redeem'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // If points are manually entered, set manual override
        if ( $points > 0 ) {
            WC()->session->set( 'wclr_manual_override', true );
            WC()->session->set( 'wclr_points_to_redeem', $points );
        } else {
            // If 0, clear manual override to return to auto mode
            WC()->session->__unset( 'wclr_manual_override' );
            WC()->session->set( 'wclr_points_to_redeem', 0 );
        }

        wc_add_notice( esc_html__( 'Your points preference has been updated.', 'wc-loyalty-rewards' ), 'success' );
    }

    /**
     * Handle AJAX redemption request.
     */
    public function handle_ajax_redeem(): void {
        check_ajax_referer( 'wclr_redeem_points', 'nonce' );

        $settings = Settings_Cache::get();
        $config   = $settings['redemption'] ?? [];
        if ( empty( $config['allow_manual_input'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Manual input is disabled.', 'wc-loyalty-rewards' ) ] );
            return; // Explicit return after wp_send_json_error() for clarity
        }

        $points = isset( $_POST['points'] ) ? (int) wp_unslash( $_POST['points'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // If points are manually entered, set manual override
        if ( $points > 0 ) {
            WC()->session->set( 'wclr_manual_override', true );
            WC()->session->set( 'wclr_points_to_redeem', $points );
        } else {
            // If 0, clear manual override to return to auto mode
            WC()->session->__unset( 'wclr_manual_override' );
            WC()->session->set( 'wclr_points_to_redeem', 0 );
        }

        // Trigger cart recalculation
        if ( WC()->cart && ! WC()->cart->is_empty() ) {
            WC()->cart->calculate_totals();
        }

        // Get updated fragments for checkout/cart
        $fragments = [];

        // Add redemption block fragment
        $html = $this->render_redeem_block();
        if ( $html ) {
            $fragments['.wclr-redeem-block-wrapper'] = $html;
        }

        // Add cart totals fragment for checkout
        if ( is_checkout() ) {
            ob_start();
            woocommerce_checkout_coupon_form();
            woocommerce_checkout_login_form();
            woocommerce_order_review();
            $fragments['.woocommerce-checkout-review-order'] = ob_get_clean();
        }

        // Add cart totals fragment for cart page
        if ( is_cart() ) {
            ob_start();
            woocommerce_cart_totals();
            $fragments['.cart_totals'] = ob_get_clean();
        }

        wp_send_json_success( [
            'message'   => __( 'Points updated successfully.', 'wc-loyalty-rewards' ),
            'fragments' => $fragments,
        ] );
    }

    /**
     * Render UI in cart.
     */
    public function render_cart_ui(): void {
        $settings = Settings_Cache::get();
        if ( ! empty( $settings['display']['show_cart'] ) ) {
            echo $this->render_redeem_block();
        }
    }

    /**
     * Render UI in checkout.
     */
    public function render_checkout_ui(): void {
        $settings = Settings_Cache::get();
        if ( ! empty( $settings['display']['show_checkout'] ) ) {
            echo $this->render_redeem_block();
        }
    }

    /**
     * Apply discount to cart totals.
     */
    public function apply_cart_discount( WC_Cart $cart ): void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        $settings = Settings_Cache::get();
        $config   = $settings['redemption'] ?? [];
        if ( empty( $config['enabled'] ) ) {
            return;
        }

        // Skip redemption if any applied coupon is in the exclusion list (only if exclusion is enabled).
        if ( ! empty( $config['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = $config['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
                $applied_coupons = $cart->get_applied_coupons();
                foreach ( $applied_coupons as $applied_code ) {
                    if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                        WC()->session->__unset( 'wclr_points_to_redeem' );
                        WC()->session->__unset( 'wclr_manual_override' );
                        return;
                    }
                }
            }
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $balance = $this->points->get_user_balance( $user_id )->balance;
        $ratio_points = (int) ( $config['points_per_unit'] ?? 100 );
        $ratio_value  = (float) ( $config['unit_value'] ?? 1.0 );
        if ( $ratio_points <= 0 || $ratio_value <= 0 ) {
            return;
        }

        $points_to_redeem = (int) WC()->session->get( 'wclr_points_to_redeem', 0 );
        $manual_override  = WC()->session->get( 'wclr_manual_override', false );

        // Auto-recalculate on every cart run so qty/total changes are reflected.
        // Only apply auto mode if user hasn't manually set points.
        if ( ! $manual_override && ! empty( $config['auto_mode'] ) && 'disabled' !== $config['auto_mode'] ) {
            $points_to_redeem = $this->calculate_auto_points( $config, $balance );
            WC()->session->set( 'wclr_points_to_redeem', $points_to_redeem );
        }

        $points_to_redeem = min( $points_to_redeem, $balance );
        if ( $points_to_redeem <= 0 ) {
            return;
        }

        $discount = ( $points_to_redeem / $ratio_points ) * $ratio_value;

        $max_percent = (float) ( $config['max_percent'] ?? 0 );
        if ( $max_percent > 0 ) {
            $max_discount = ( $cart->get_subtotal() ) * ( $max_percent / 100 );
            $discount     = min( $discount, $max_discount );
            $points_to_redeem = (int) floor( $discount / $ratio_value * $ratio_points );
        }

        if ( $discount <= 0 ) {
            return;
        }

        WC()->session->set( 'wclr_points_to_redeem', $points_to_redeem );

        // Include points in fee name for clear display
        $fee_name = sprintf(
            /* translators: 1: points amount */
            __( '🎁 Points Redeemed: %s', 'wc-loyalty-rewards' ),
            number_format_i18n( $points_to_redeem )
        );
        $cart->add_fee( $fee_name, -1 * $discount );
    }

    /**
     * Finalize redemption when order completes.
     */
    public function finalize_redemption_on_order( int $order_id ): void {
        $order  = wc_get_order( $order_id );
        $user_id = $order ? $order->get_user_id() : 0;
        if ( ! $order || ! $user_id ) {
            return;
        }
        $points = (int) WC()->session->get( 'wclr_points_to_redeem', 0 );
        if ( $points > 0 ) {
            $this->points->redeem_points_for_order( $order, $points );
            WC()->session->__unset( 'wclr_points_to_redeem' );
            WC()->session->__unset( 'wclr_manual_override' );
        }
    }

    /**
     * Render redemption block shared.
     */
    public function render_redeem_block(): string {
        if ( is_admin() ) {
            return '';
        }
        $settings = Settings_Cache::get();
        $config   = $settings['redemption'] ?? [];
        if ( empty( $config['enabled'] ) ) {
            return '';
        }
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '';
        }

        // Clear manual override on initial page load (not AJAX) to reset to auto redeem on refresh
        if ( ! wp_doing_ajax() && ! isset( $_POST['wclr_redeem_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            WC()->session->__unset( 'wclr_manual_override' );
        }

        $balance         = $this->points->get_user_balance( $user_id )->balance;
        $current         = (int) WC()->session->get( 'wclr_points_to_redeem', 0 );
        $manual_override = WC()->session->get( 'wclr_manual_override', false );
        $auto_mode       = ! empty( $config['auto_mode'] ) && 'disabled' !== $config['auto_mode'];
        $cart            = WC()->cart;
        $estimated       = ( $cart && ! $cart->is_empty() ) ? $this->points->estimate_cart_points( $cart ) : null;

        // Calculate auto-redeemed points for display
        $auto_redeemed_points = 0;
        if ( $auto_mode && ! $manual_override && $current > 0 ) {
            $auto_redeemed_points = $current;
        }
        $redemption_blocked_by_coupon = false;
        if ( ! empty( $config['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = $config['exclude_coupons'] ?? [];
            if ( ! empty( $excluded_coupons ) && is_array( $excluded_coupons ) ) {
                $applied_coupons = $cart ? $cart->get_applied_coupons() : [];
                if ( ! empty( $applied_coupons ) ) {
                    foreach ( $applied_coupons as $applied_code ) {
                        if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                            $redemption_blocked_by_coupon = true;
                            break;
                        }
                    }
                }
            }
        }
        ob_start();
        ?>
        <div class="wclr-redeem-block-wrapper">
        <div class="wclr-redeem-block">
            <div class="wclr-redeem-header">
                <div class="wclr-redeem-title-section">
                    <span class="wclr-redeem-icon">🎁</span>
                    <h3 class="wclr-redeem-title"><?php esc_html_e( 'Use your loyalty points', 'wc-loyalty-rewards' ); ?></h3>
                </div>
                <div class="wclr-redeem-balance">
                    <span class="wclr-balance-label"><?php esc_html_e( 'Available:', 'wc-loyalty-rewards' ); ?></span>
                    <span class="wclr-balance-amount"><?php echo esc_html( number_format_i18n( $balance ) ); ?> <?php esc_html_e( 'points', 'wc-loyalty-rewards' ); ?></span>
                </div>
            </div>

            <?php if ( null !== $estimated ) : ?>
                <div class="wclr-earn-preview">
                    <span class="wclr-earn-icon">✨</span>
                    <span class="wclr-earn-text"><?php echo esc_html( sprintf( __( 'You\'ll earn %d points on this order', 'wc-loyalty-rewards' ), $estimated ) ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( $redemption_blocked_by_coupon ) : ?>
                <div class="wclr-auto-notice wclr-warning-notice">
                    <span class="wclr-notice-icon">⚠️</span>
                    <span class="wclr-notice-text"><?php esc_html_e( 'Point redemption is disabled when coupons are applied.', 'wc-loyalty-rewards' ); ?></span>
                </div>
            <?php elseif ( $auto_mode && ! $manual_override && $auto_redeemed_points > 0 ) : ?>
                <div class="wclr-auto-redeem-info">
                    <div class="wclr-auto-redeem-header">
                        <span class="wclr-notice-icon">⚡</span>
                        <span class="wclr-auto-redeem-text">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: points amount */
                                    __( 'Auto redeemed: %s points', 'wc-loyalty-rewards' ),
                                    number_format_i18n( $auto_redeemed_points )
                                )
                            );
                            ?>
                        </span>
                        <?php if ( ! empty( $config['allow_manual_input'] ) ) : ?>
                            <button type="button" class="wclr-edit-link" aria-expanded="false" aria-controls="wclr-manual-input-section">
                                <?php esc_html_e( 'edit', 'wc-loyalty-rewards' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ( $auto_mode && ! $manual_override ) : ?>
                <div class="wclr-auto-notice">
                    <span class="wclr-notice-icon">⚡</span>
                    <span class="wclr-notice-text"><?php esc_html_e( 'Auto redeem is active.', 'wc-loyalty-rewards' ); ?></span>
                </div>
            <?php elseif ( $manual_override && $current > 0 ) : ?>
                <div class="wclr-manual-redeem-info">
                    <div class="wclr-auto-redeem-header">
                        <span class="wclr-notice-icon">✏️</span>
                        <span class="wclr-auto-redeem-text">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: points amount */
                                    __( 'Redeeming: %s points', 'wc-loyalty-rewards' ),
                                    number_format_i18n( $current )
                                )
                            );
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $config['allow_manual_input'] ) && ! $redemption_blocked_by_coupon ) : ?>
                <div class="wclr-manual-input-section" id="wclr-manual-input-section" style="<?php echo ( $auto_mode && ! $manual_override ) ? 'display: none;' : 'display: block;'; ?>">
                    <form method="post" class="wclr-redeem-form" data-wclr-ajax-redeem>
                        <?php wp_nonce_field( 'wclr_redeem_points', 'wclr_redeem_nonce' ); ?>
                        <div class="wclr-redeem-input-group">
                            <div class="wclr-input-wrapper">
                                <input type="number" min="0" step="1" max="<?php echo esc_attr( $balance ); ?>" id="wclr_points_to_redeem" name="wclr_points_to_redeem" value="<?php echo esc_attr( $current ); ?>" class="wclr-redeem-input" placeholder="0" />
                                <button type="submit" class="wclr-redeem-button"><?php esc_html_e( 'Apply', 'wc-loyalty-rewards' ); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php else : ?>
                <div class="wclr-auto-notice">
                    <span class="wclr-notice-icon">ℹ️</span>
                    <span class="wclr-notice-text"><?php esc_html_e( 'Manual point entry is disabled. Auto redemption will be applied if enabled.', 'wc-loyalty-rewards' ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Compute auto points based on config and balance.
     */
    private function calculate_auto_points( array $config, int $balance ): int {
        if ( 'max' === $config['auto_mode'] ) {
            return $balance;
        }
        if ( 'percent' === $config['auto_mode'] ) {
            $percent = isset( $config['auto_percent'] ) ? (int) $config['auto_percent'] : 0;
            return (int) floor( $balance * ( $percent / 100 ) );
        }
        return 0;
    }


    /**
     * Checkout fragments refresh to update block dynamically.
     *
     * @param array $fragments Fragments.
     * @return array
     */
    public function filter_checkout_fragments( array $fragments ): array {
        $html = $this->render_redeem_block();
        if ( $html ) {
            $fragments['.wclr-redeem-block-wrapper'] = $html;
        }
        return $fragments;
    }

    /**
     * Cart fragments refresh to update block dynamically.
     *
     * @param array $fragments Fragments.
     * @return array
     */
    public function filter_cart_fragments( array $fragments ): array {
        $html = $this->render_redeem_block();
        if ( $html ) {
            $fragments['.wclr-redeem-block-wrapper'] = $html;
        }
        return $fragments;
    }


    /**
     * Shortcode handler for standalone redeem widget.
     */
    public function shortcode_redeem_widget(): string {
        return $this->render_redeem_block();
    }
}

