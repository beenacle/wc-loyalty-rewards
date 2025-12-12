<?php

namespace WCLR\Frontend;

use WCLR\Helpers\Settings_Cache;
use WCLR\Services\Points_Service;
use WCLR\Services\Referral_Service;
use WCLR\Services\Tier_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend rendering: my account endpoint, cart/checkout notices.
 */
class Frontend_Service {

    private Points_Service $points;
    private Tier_Service $tiers;
    private Referral_Service $referrals;
    private ?array $pending_notice = null;
    private string $pending_message = '';

    public function __construct( Points_Service $points, Tier_Service $tiers, Referral_Service $referrals ) {
        $this->points    = $points;
        $this->tiers     = $tiers;
        $this->referrals = $referrals;
    }

    public function register(): void {
        add_action( 'init', [ $this, 'add_account_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_account_menu_item' ] );
        add_action( 'woocommerce_account_wclr-loyalty_endpoint', [ $this, 'render_account_page' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp', [ $this, 'maybe_queue_reward_notice' ] );
        add_action( 'wp_footer', [ $this, 'render_reward_notice_and_confetti' ] );
        add_shortcode( 'wclr_points_balance', [ $this, 'shortcode_points_balance' ] );
        add_shortcode( 'wclr_tier_info', [ $this, 'shortcode_tier_info' ] );
        add_shortcode( 'wclr_referral_block', [ $this, 'shortcode_referral_block' ] );
        add_shortcode( 'wclr_recent_ledger', [ $this, 'shortcode_recent_ledger' ] );

        if ( $this->is_funnelkit_cart_active() ) {
            add_action( 'fkcart_after_order_summary', [ $this, 'render_funnelkit_cart_points' ] );
        }
    }

    /**
     * Register endpoint.
     */
    public function add_account_endpoint(): void {
        $settings = Settings_Cache::get();
        if ( empty( $settings['display']['show_my_account'] ) ) {
            return;
        }
        add_rewrite_endpoint( 'wclr-loyalty', EP_ROOT | EP_PAGES );
    }

    /**
     * Whether FunnelKit Cart plugin is available.
     */
    private function is_funnelkit_cart_active(): bool {
        return defined( 'FKCART_VERSION' ) || class_exists( '\FKCart\Plugin' );
    }

    /**
     * Add menu item.
     */
    public function add_account_menu_item( array $items ): array {
        $settings = Settings_Cache::get();
        if ( ! empty( $settings['display']['show_my_account'] ) ) {
            $items['wclr-loyalty'] = __( 'Loyalty & Rewards', 'wc-loyalty-rewards' );
        }
        return $items;
    }

    /**
     * Enqueue minimal assets.
     */
    public function enqueue_assets(): void {
        wp_enqueue_style( 'wclr-frontend', WCLR_PLUGIN_URL . 'assets/css/frontend.css', [], '1.0.0' );
    }

    /**
     * Queue reward notice if there is a pending reward from last action.
     */
    public function maybe_queue_reward_notice(): void {
        if ( is_admin() || ! is_user_logged_in() ) {
            return;
        }
        $user_id = get_current_user_id();
        $pending = get_user_meta( $user_id, '_wclr_pending_reward_notice', true );
        if ( empty( $pending ) || ! is_array( $pending ) ) {
            return;
        }
        $this->pending_notice = $pending;
        delete_user_meta( $user_id, '_wclr_pending_reward_notice' );

        if ( function_exists( 'wc_add_notice' ) ) {
            $amount  = (int) ( $pending['amount'] ?? 0 );
            $balance = (int) ( $pending['balance'] ?? 0 );
            $this->pending_message = sprintf(
                /* translators: 1: points earned, 2: balance */
                __( 'You earned %1$d points! New balance: %2$d.', 'wc-loyalty-rewards' ),
                $amount,
                $balance
            );
            wc_add_notice( $this->pending_message, 'success' );
        }
    }

    /**
     * Format context string to Title Case.
     *
     * @param string $context The context string (e.g., "daily_visit_reward").
     * @return string Formatted context (e.g., "Daily Visit Reward").
     */
    private function format_context( string $context ): string {
        if ( empty( $context ) ) {
            return '';
        }
        // Replace underscores with spaces and convert to title case
        return ucwords( str_replace( '_', ' ', strtolower( $context ) ) );
    }

    /**
     * Render reward notice once per pending reward.
     */
    public function render_reward_notice_and_confetti(): void {
        if ( is_admin() || empty( $this->pending_notice ) ) {
            return;
        }
        $amount  = isset( $this->pending_notice['amount'] ) ? (int) $this->pending_notice['amount'] : 0;
        $balance = isset( $this->pending_notice['balance'] ) ? (int) $this->pending_notice['balance'] : 0;
        $context = isset( $this->pending_notice['context'] ) ? sanitize_text_field( $this->pending_notice['context'] ) : '';
        $message = ! empty( $this->pending_message ) ? $this->pending_message : __( 'You earned points!', 'wc-loyalty-rewards' );
        ?>
        <div class="wclr-reward-flash-wrapper">
            <div class="wclr-reward-flash">
                <button type="button" class="wclr-reward-close" aria-label="<?php esc_attr_e( 'Close', 'wc-loyalty-rewards' ); ?>">&times;</button>
                <div class="wclr-reward-content">
                    <p class="wclr-reward-title"><strong><?php echo esc_html( $message ); ?></strong></p>
                    <p class="wclr-reward-detail"><?php echo esc_html( sprintf( __( 'Points earned: %d', 'wc-loyalty-rewards' ), $amount ) ); ?></p>
                    <p class="wclr-reward-detail"><?php echo esc_html( sprintf( __( 'New balance: %d', 'wc-loyalty-rewards' ), $balance ) ); ?></p>
                    <?php if ( $context ) : ?>
                        <p class="wclr-reward-context"><?php echo esc_html( sprintf( __( 'Source: %s', 'wc-loyalty-rewards' ), $this->format_context( $context ) ) ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var notice = document.querySelector('.wclr-reward-flash-wrapper');
            if (notice) {
                var closeBtn = notice.querySelector('.wclr-reward-close');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function() {
                        notice.style.display = 'none';
                    });
                }
                // Auto-hide after 8 seconds
                setTimeout(function() {
                    if (notice) {
                        notice.style.opacity = '0';
                        notice.style.transform = 'translateY(-20px)';
                        setTimeout(function() {
                            if (notice) notice.style.display = 'none';
                        }, 300);
                    }
                }, 8000);
            }
        })();
        </script>
        <?php
    }

    /**
     * Output points info inside FunnelKit cart drawer.
     */
    public function render_funnelkit_cart_points(): void {
        if ( is_admin() || ! function_exists( 'WC' ) || is_null( WC()->cart ) || WC()->cart->is_empty() ) {
            return;
        }

        $user_id       = get_current_user_id();
        $balance       = $user_id ? $this->points->get_user_balance( $user_id )->balance : null;
        $earnable      = $this->points->estimate_cart_points( WC()->cart );
        $login_prompt  = ( ! $user_id && $earnable > 0 ) ? __( 'Log in to earn points on this order.', 'wc-loyalty-rewards' ) : '';
        $should_render = ( $balance !== null ) || $earnable > 0 || $login_prompt;

        if ( ! $should_render ) {
            return;
        }
        ?>
        <div class="fkcart-order-summary fkcart-panel wclr-fkcart-points">
            <div class="fkcart-order-summary-container">
                <?php if ( null !== $balance ) : ?>
                    <div class="fkcart-summary-line-item">
                        <div class="fkcart-summary-text"><strong><?php esc_html_e( 'Your points', 'wc-loyalty-rewards' ); ?></strong></div>
                        <div class="fkcart-summary-amount"><strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong></div>
                    </div>
                <?php endif; ?>

                <?php if ( $earnable > 0 ) : ?>
                    <div class="fkcart-summary-line-item">
                        <div class="fkcart-summary-text"><?php esc_html_e( 'Earn on this order', 'wc-loyalty-rewards' ); ?></div>
                        <div class="fkcart-summary-amount"><?php echo esc_html( number_format_i18n( $earnable ) ); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ( $login_prompt ) : ?>
                    <div class="fkcart-summary-line-item wclr-fkcart-points-note">
                        <div class="fkcart-summary-text"><?php echo esc_html( $login_prompt ); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render account page content.
     */
    public function render_account_page(): void {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            echo esc_html__( 'You need an account to view loyalty details.', 'wc-loyalty-rewards' );
            return;
        }
        $balance   = $this->points->get_user_balance( $user_id );
        $tier      = $this->tiers->get_user_tier( $user_id );
        $referrals = $this->referrals->get_referrals_for_user( $user_id );
        $code      = $this->referrals->get_referral_code( $user_id );
        $link      = $code ? add_query_arg( 'ref', $code, home_url() ) : '';

        // Pagination setup
        $per_page = 10;
        $current_page = isset( $_GET['wclr_page'] ) ? max( 1, (int) $_GET['wclr_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $offset = ( $current_page - 1 ) * $per_page;
        $total_entries = $this->points->get_ledger_entries_count( $user_id );
        $total_pages = max( 1, (int) ceil( $total_entries / $per_page ) );

        $recent = $this->points->get_recent_ledger_entries( $user_id, $per_page, $offset );
        ?>
        <div class="wclr-account">
            <h2><?php esc_html_e( 'Your Loyalty & Rewards', 'wc-loyalty-rewards' ); ?></h2>
            <div class="wclr-stats">
                <p><?php echo esc_html( sprintf( __( 'Balance: %d points', 'wc-loyalty-rewards' ), $balance->balance ) ); ?></p>
                <p><?php echo esc_html( sprintf( __( 'Lifetime: %d points', 'wc-loyalty-rewards' ), $balance->lifetime_points ) ); ?></p>
                <p><?php echo esc_html( sprintf( __( 'Tier: %s', 'wc-loyalty-rewards' ), $tier ? $tier->name : __( 'None', 'wc-loyalty-rewards' ) ) ); ?></p>
            </div>
            <div class="wclr-referrals">
                <h3><?php esc_html_e( 'Refer a friend', 'wc-loyalty-rewards' ); ?></h3>
                <p><?php esc_html_e( 'Share your code or link:', 'wc-loyalty-rewards' ); ?></p>
                <code><?php echo esc_html( $code ); ?></code>
                <?php if ( $link ) : ?>
                    <p><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $link ); ?></a></p>
                <?php endif; ?>
                <h4><?php esc_html_e( 'Recent referrals', 'wc-loyalty-rewards' ); ?></h4>
                <ul>
                    <?php foreach ( $referrals as $ref ) : ?>
                        <li><?php echo esc_html( sprintf( __( 'Code %s – Status: %s', 'wc-loyalty-rewards' ), $ref->referral_code, $ref->status ) ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="wclr-recent-ledger">
                <h3><?php esc_html_e( 'Recent Points Activity', 'wc-loyalty-rewards' ); ?></h3>
                <?php if ( empty( $recent ) ) : ?>
                    <p><?php esc_html_e( 'No recent activity.', 'wc-loyalty-rewards' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped wclr-ledger-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'wc-loyalty-rewards' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'wc-loyalty-rewards' ); ?></th>
                                <th><?php esc_html_e( 'Amount', 'wc-loyalty-rewards' ); ?></th>
                                <th><?php esc_html_e( 'Balance After', 'wc-loyalty-rewards' ); ?></th>
                                <th><?php esc_html_e( 'Context', 'wc-loyalty-rewards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->created_at ) ) ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $entry->type ) ); ?></td>
                                    <td><?php echo esc_html( $entry->amount ); ?></td>
                                    <td><?php echo esc_html( $entry->balance_after ); ?></td>
                                    <td><?php echo esc_html( $this->format_context( $entry->context ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="wclr-pagination">
                            <?php
                            $base_url = wc_get_endpoint_url( 'wclr-loyalty', '', wc_get_page_permalink( 'myaccount' ) );

                            // Previous page link
                            if ( $current_page > 1 ) :
                                $prev_url = add_query_arg( 'wclr_page', $current_page - 1, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $prev_url ); ?>" class="button"><?php esc_html_e( '← Previous', 'wc-loyalty-rewards' ); ?></a>
                            <?php endif; ?>

                            <?php
                            // Page number links
                            $start_page = max( 1, $current_page - 2 );
                            $end_page = min( $total_pages, $current_page + 2 );

                            if ( $start_page > 1 ) :
                                $first_url = add_query_arg( 'wclr_page', 1, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $first_url ); ?>" class="button">1</a>
                                <?php if ( $start_page > 2 ) : ?>
                                    <span>...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ( $i = $start_page; $i <= $end_page; $i++ ) : ?>
                                <?php if ( $i === $current_page ) : ?>
                                    <span class="button active"><?php echo esc_html( $i ); ?></span>
                                <?php else : ?>
                                    <?php $page_url = add_query_arg( 'wclr_page', $i, $base_url ); ?>
                                    <a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php echo esc_html( $i ); ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ( $end_page < $total_pages ) : ?>
                                <?php if ( $end_page < $total_pages - 1 ) : ?>
                                    <span>...</span>
                                <?php endif; ?>
                                <?php $last_url = add_query_arg( 'wclr_page', $total_pages, $base_url ); ?>
                                <a href="<?php echo esc_url( $last_url ); ?>" class="button"><?php echo esc_html( $total_pages ); ?></a>
                            <?php endif; ?>

                            <?php
                            // Next page link
                            if ( $current_page < $total_pages ) :
                                $next_url = add_query_arg( 'wclr_page', $current_page + 1, $base_url );
                                ?>
                                <a href="<?php echo esc_url( $next_url ); ?>" class="button"><?php esc_html_e( 'Next →', 'wc-loyalty-rewards' ); ?></a>
                            <?php endif; ?>

                            <p class="wclr-pagination-info">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: current page, 2: total pages, 3: total entries */
                                        __( 'Page %1$d of %2$d (%3$d total entries)', 'wc-loyalty-rewards' ),
                                        $current_page,
                                        $total_pages,
                                        $total_entries
                                    )
                                );
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Shortcode: points balance and lifetime.
     */
    public function shortcode_points_balance(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return esc_html__( 'Please log in to view your points.', 'wc-loyalty-rewards' );
        }
        $balance = $this->points->get_user_balance( $user_id );
        ob_start();
        ?>
        <div class="wclr-shortcode wclr-points-balance">
            <p><?php echo esc_html( sprintf( __( 'Balance: %d points', 'wc-loyalty-rewards' ), $balance->balance ) ); ?></p>
            <p><?php echo esc_html( sprintf( __( 'Lifetime: %d points', 'wc-loyalty-rewards' ), $balance->lifetime_points ) ); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: tier info.
     */
    public function shortcode_tier_info(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return esc_html__( 'Please log in to view your tier.', 'wc-loyalty-rewards' );
        }
        $tier = $this->tiers->get_user_tier( $user_id );
        if ( ! $tier ) {
            return esc_html__( 'You are not in a tier yet.', 'wc-loyalty-rewards' );
        }
        ob_start();
        ?>
        <div class="wclr-shortcode wclr-tier-info">
            <p><?php echo esc_html( sprintf( __( 'Tier: %s', 'wc-loyalty-rewards' ), $tier->name ) ); ?></p>
            <p><?php echo esc_html( sprintf( __( 'Multiplier: x%s', 'wc-loyalty-rewards' ), $tier->multiplier ) ); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: referral code and link block.
     */
    public function shortcode_referral_block(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return esc_html__( 'Please log in to view your referral link.', 'wc-loyalty-rewards' );
        }
        $code = $this->referrals->get_referral_code( $user_id );
        $link = $code ? add_query_arg( 'ref', $code, home_url() ) : '';
        ob_start();
        ?>
        <div class="wclr-shortcode wclr-referral-block">
            <p><strong><?php esc_html_e( 'Your referral code', 'wc-loyalty-rewards' ); ?>:</strong> <code><?php echo esc_html( $code ); ?></code></p>
            <?php if ( $link ) : ?>
                <p><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $link ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: recent ledger entries.
     *
     * @param array<string,string> $atts Shortcode attributes.
     */
    public function shortcode_recent_ledger( array $atts ): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return esc_html__( 'Please log in to view your points activity.', 'wc-loyalty-rewards' );
        }
        $atts  = shortcode_atts(
            [
                'limit' => 10,
            ],
            $atts,
            'wclr_recent_ledger'
        );
        $limit  = max( 1, min( 50, (int) $atts['limit'] ) );
        $recent = $this->points->get_recent_ledger_entries( $user_id, $limit );

        if ( empty( $recent ) ) {
            return esc_html__( 'No recent activity.', 'wc-loyalty-rewards' );
        }

        ob_start();
        ?>
        <div class="wclr-shortcode wclr-recent-ledger">
            <table class="widefat striped wclr-ledger-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Balance After', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Context', 'wc-loyalty-rewards' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $recent as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->created_at ) ) ); ?></td>
                        <td><?php echo esc_html( ucfirst( $entry->type ) ); ?></td>
                        <td><?php echo esc_html( $entry->amount ); ?></td>
                        <td><?php echo esc_html( $entry->balance_after ); ?></td>
                        <td><?php echo esc_html( $this->format_context( $entry->context ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

