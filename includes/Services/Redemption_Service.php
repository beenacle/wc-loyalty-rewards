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
    }

    /**
     * Process incoming redeem form submission.
     */
    public function maybe_capture_redeem_request(): void {
        if ( is_admin() ) {
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
        $use_auto = isset( $_POST['wclr_use_auto'] ) && ! empty( $_POST['wclr_use_auto'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( $use_auto ) {
            // User wants to re-enable auto mode
            WC()->session->__unset( 'wclr_manual_override' );
        } else {
            // User manually set points - disable auto mode
            WC()->session->set( 'wclr_manual_override', true );
        }

        WC()->session->set( 'wclr_points_to_redeem', max( 0, $points ) );
        wc_add_notice( esc_html__( 'Your points preference has been updated.', 'wc-loyalty-rewards' ), 'success' );
    }

    /**
     * Render UI in cart.
     */
    public function render_cart_ui(): void {
        $settings = get_option( 'wclr_settings', [] );
        if ( ! empty( $settings['display']['show_cart'] ) ) {
            echo $this->render_redeem_block();
        }
    }

    /**
     * Render UI in checkout.
     */
    public function render_checkout_ui(): void {
        $settings = get_option( 'wclr_settings', [] );
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
            $excluded_coupons = isset( $config['exclude_coupons'] ) && is_array( $config['exclude_coupons'] ) ? $config['exclude_coupons'] : [];
            if ( ! empty( $excluded_coupons ) ) {
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
        $ratio_points = isset( $config['points_per_unit'] ) ? (int) $config['points_per_unit'] : 100;
        $ratio_value  = isset( $config['unit_value'] ) ? (float) $config['unit_value'] : 1.0;
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

        if ( $points_to_redeem <= 0 ) {
            return;
        }

        $points_to_redeem = min( $points_to_redeem, $balance );
        if ( $points_to_redeem <= 0 ) {
            return;
        }

        $discount = ( $points_to_redeem / $ratio_points ) * $ratio_value;

        $max_percent = isset( $config['max_percent'] ) ? (float) $config['max_percent'] : 0;
        if ( $max_percent > 0 ) {
            $max_discount = ( $cart->get_subtotal() ) * ( $max_percent / 100 );
            $discount     = min( $discount, $max_discount );
            $points_to_redeem = (int) floor( $discount / $ratio_value * $ratio_points );
        }

        if ( $discount <= 0 ) {
            return;
        }

        WC()->session->set( 'wclr_points_to_redeem', $points_to_redeem );
        $cart->add_fee( __( 'Loyalty Points', 'wc-loyalty-rewards' ), -1 * $discount );
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
    private function render_redeem_block(): string {
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
        $balance         = $this->points->get_user_balance( $user_id )->balance;
        $current         = (int) WC()->session->get( 'wclr_points_to_redeem', 0 );
        $manual_override = WC()->session->get( 'wclr_manual_override', false );
        $auto_mode       = ! empty( $config['auto_mode'] ) && 'disabled' !== $config['auto_mode'];
        $show_auto_check = $auto_mode; // Show checkbox only if auto mode is enabled
        $cart            = WC()->cart;
        $estimated       = ( $cart && ! $cart->is_empty() ) ? $this->estimate_points_for_cart( $cart, $user_id ) : null;
        $redemption_blocked_by_coupon = false;
        if ( ! empty( $config['exclude_coupons_enabled'] ) ) {
            $excluded_coupons = isset( $config['exclude_coupons'] ) && is_array( $config['exclude_coupons'] ) ? $config['exclude_coupons'] : [];
            $applied_coupons = $cart ? $cart->get_applied_coupons() : [];
            if ( ! empty( $excluded_coupons ) && ! empty( $applied_coupons ) ) {
                foreach ( $applied_coupons as $applied_code ) {
                    if ( in_array( $applied_code, $excluded_coupons, true ) ) {
                        $redemption_blocked_by_coupon = true;
                        break;
                    }
                }
            }
        }
        ob_start();
        ?>
        <div class="wclr-redeem-block-wrapper">
        <div class="wclr-redeem-block">
            <p><strong><?php esc_html_e( 'Use your loyalty points', 'wc-loyalty-rewards' ); ?></strong></p>
            <p><?php echo esc_html( sprintf( __( 'You have %d points.', 'wc-loyalty-rewards' ), $balance ) ); ?></p>
            <?php if ( null !== $estimated ) : ?>
                <p class="wclr-auto-notice"><?php echo esc_html( sprintf( __( 'Estimated points you will earn for this order: %d', 'wc-loyalty-rewards' ), $estimated ) ); ?></p>
            <?php endif; ?>
            <?php if ( $redemption_blocked_by_coupon ) : ?>
                <p class="wclr-auto-notice" style="background: #fff3cd; border-color: #ffc107;"><em><?php esc_html_e( 'Point redemption is disabled when coupons are applied.', 'wc-loyalty-rewards' ); ?></em></p>
            <?php elseif ( $auto_mode && ! $manual_override ) : ?>
                <p class="wclr-auto-notice"><em><?php esc_html_e( 'Auto redeem is active. Enter a custom amount below to override.', 'wc-loyalty-rewards' ); ?></em></p>
            <?php endif; ?>
            <?php if ( ! empty( $config['allow_manual_input'] ) && ! $redemption_blocked_by_coupon ) : ?>
                <form method="post">
                    <?php wp_nonce_field( 'wclr_redeem_points', 'wclr_redeem_nonce' ); ?>
                    <label for="wclr_points_to_redeem"><?php esc_html_e( 'Points to redeem', 'wc-loyalty-rewards' ); ?></label>
                    <input type="number" min="0" step="1" id="wclr_points_to_redeem" name="wclr_points_to_redeem" value="<?php echo esc_attr( $current ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Apply', 'wc-loyalty-rewards' ); ?></button>
                    <?php if ( $show_auto_check && $manual_override ) : ?>
                        <label style="display: block; margin-top: 10px;">
                            <input type="checkbox" name="wclr_use_auto" value="1" />
                            <?php esc_html_e( 'Re-enable auto redeem', 'wc-loyalty-rewards' ); ?>
                        </label>
                    <?php endif; ?>
                </form>
            <?php else : ?>
                <p class="wclr-auto-notice"><em><?php esc_html_e( 'Manual point entry is disabled. Auto redemption will be applied if enabled.', 'wc-loyalty-rewards' ); ?></em></p>
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
     * Estimate points for current cart (used for display only).
     */
    private function estimate_points_for_cart( WC_Cart $cart, int $user_id ): ?int {
        $settings = get_option( 'wclr_settings', [] );
        if ( empty( $settings['order_earning']['enabled'] ) ) {
            return null;
        }

        // If any applied coupon is in the exclusion list, return 0 (only if exclusion is enabled).
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

        $include_tax      = ! empty( $settings['order_earning']['include_tax'] );
        $include_shipping = ! empty( $settings['order_earning']['include_shipping'] );
        $min_order        = isset( $settings['order_earning']['min_order'] ) ? (float) $settings['order_earning']['min_order'] : 0;

        $subtotal = (float) $cart->get_subtotal();
        if ( $include_tax ) {
            $subtotal += (float) $cart->get_subtotal_tax();
        }
        if ( $include_shipping ) {
            $subtotal += (float) $cart->get_shipping_total();
        }

        if ( $subtotal < $min_order ) {
            return 0;
        }

        $rate       = isset( $settings['base_rate'] ) ? (float) $settings['base_rate'] : 1.0;
        $base_mult  = isset( $settings['base_multiplier'] ) ? (float) $settings['base_multiplier'] : 1.0;
        $tier_mult  = $this->tiers->get_multiplier_for_user( $user_id );
        $multiplier = $base_mult * $tier_mult;

        $points = (int) floor( $subtotal * $rate * $multiplier );
        return max( 0, $points );
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
}

