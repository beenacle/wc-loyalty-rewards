<?php

namespace WCLR\Admin;

use WCLR\Helpers\Settings_Cache;
use WCLR\Services\Points_Service;
use WCLR\Services\Referral_Service;
use WCLR\Services\Tier_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin UI and settings.
 */
class Admin_Service {

    private Points_Service $points;
    private Tier_Service $tiers;
    private Referral_Service $referrals;

    public function __construct( Points_Service $points, Tier_Service $tiers, Referral_Service $referrals ) {
        $this->points    = $points;
        $this->tiers     = $tiers;
        $this->referrals = $referrals;
    }

    public function register(): void {
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_init', [ $this, 'register_settings' ] );
            add_action( 'show_user_profile', [ $this, 'render_user_profile' ] );
            add_action( 'edit_user_profile', [ $this, 'render_user_profile' ] );
            add_action( 'personal_options_update', [ $this, 'handle_user_adjustment' ] );
            add_action( 'edit_user_profile_update', [ $this, 'handle_user_adjustment' ] );
            add_action( 'admin_post_wclr_export_points', [ $this, 'handle_export_points' ] );
            add_action( 'admin_post_wclr_import_points', [ $this, 'handle_import_points' ] );
            add_action( 'admin_post_wclr_recalc_lifetime', [ $this, 'handle_recalc_lifetime' ] );
            // Add points column to users list.
            add_filter( 'manage_users_columns', [ $this, 'add_points_column' ] );
            add_filter( 'manage_users_custom_column', [ $this, 'render_points_column' ], 10, 3 );
            add_filter( 'manage_users_sortable_columns', [ $this, 'make_points_column_sortable' ] );
            add_action( 'pre_get_users', [ $this, 'handle_points_column_sorting' ] );
        }
    }

    /**
     * Register main menu and pages.
     */
    public function register_menu(): void {
        // Top-level menu with subpages.
        add_menu_page(
            __( 'Loyalty & Rewards', 'wc-loyalty-rewards' ),
            __( 'Loyalty & Rewards', 'wc-loyalty-rewards' ),
            'manage_woocommerce',
            'wclr',
            [ $this, 'render_settings_page' ],
            'dashicons-awards',
            56
        );

        add_submenu_page(
            'wclr',
            __( 'Settings', 'wc-loyalty-rewards' ),
            __( 'Settings', 'wc-loyalty-rewards' ),
            'manage_woocommerce',
            'wclr',
            [ $this, 'render_settings_page' ]
        );

        add_submenu_page(
            'wclr',
            __( 'Points Ledger', 'wc-loyalty-rewards' ),
            __( 'Points Ledger', 'wc-loyalty-rewards' ),
            'manage_woocommerce',
            'wclr-ledger',
            [ $this, 'render_ledger_page' ]
        );

        add_submenu_page(
            'wclr',
            __( 'Tiers', 'wc-loyalty-rewards' ),
            __( 'Tiers', 'wc-loyalty-rewards' ),
            'manage_woocommerce',
            'wclr-tiers',
            [ $this, 'render_tiers_page' ]
        );
    }

    /**
     * Settings registration.
     */
    public function register_settings(): void {
        register_setting( 'wclr_settings', 'wclr_settings', [ $this, 'sanitize_settings' ] );
    }

    /**
     * Provide default settings structure.
     */
    private function defaults(): array {
        return [
            'enabled'             => true,
            'delete_on_uninstall' => false,
            'base_rate'           => 1,
            'base_multiplier'     => 1,
            'order_earning'       => [
                'enabled'          => true,
                'include_tax'      => false,
                'include_shipping' => false,
                'min_order'        => 0,
                'refund_behavior'  => 'reverse',
            ],
            'signup_bonus'        => [
                'enabled' => true,
                'points'  => 100,
            ],
            'referral'            => [
                'enabled'        => true,
                'referrer_bonus' => 200,
                'referred_bonus' => 100,
            ],
            'login'               => [
                'enabled'   => true,
                'threshold' => 3,
                'points'    => 50,
            ],
            'anniversary'         => [
                'enabled' => true,
                'points'  => 150,
            ],
            'redemption'          => [
                'enabled'         => true,
                'points_per_unit' => 100,
                'unit_value'      => 1.0,
                'max_percent'     => 50,
                'auto_mode'       => 'disabled',
                'auto_percent'    => 50,
                'return_on_refund'=> true,
                'allow_manual_input' => true,
            ],
            'display'             => [
                'show_my_account' => true,
                'show_cart'       => true,
                'show_checkout'   => true,
            ],
        ];
    }

    /**
     * Merge settings with defaults.
     */
    private function get_settings(): array {
        return wp_parse_args( Settings_Cache::get(), $this->defaults() );
    }

    /**
     * Sanitize settings on save.
     */
    public function sanitize_settings( array $input ): array {
        $defaults                = $this->defaults();
        $input                   = wp_parse_args( $input, $defaults );
        $input['enabled']        = ! empty( $input['enabled'] );
        $input['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );
        $input['base_rate']      = isset( $input['base_rate'] ) ? max( 0, (float) $input['base_rate'] ) : 1;
        $input['base_multiplier']= isset( $input['base_multiplier'] ) ? max( 0, (float) $input['base_multiplier'] ) : 1;

        $oe                       = $input['order_earning'];
        $exclude_coupons_oe       = isset( $oe['exclude_coupons'] ) && is_array( $oe['exclude_coupons'] ) ? $oe['exclude_coupons'] : [];
        $input['order_earning']   = [
            'enabled'                => ! empty( $oe['enabled'] ),
            'include_tax'            => ! empty( $oe['include_tax'] ),
            'include_shipping'       => ! empty( $oe['include_shipping'] ),
            'min_order'              => isset( $oe['min_order'] ) ? max( 0, (float) $oe['min_order'] ) : 0,
            'refund_behavior'        => in_array( $oe['refund_behavior'] ?? 'reverse', [ 'reverse', 'prorate', 'ignore' ], true ) ? $oe['refund_behavior'] : 'reverse',
            'exclude_coupons_enabled'=> ! empty( $oe['exclude_coupons_enabled'] ),
            'exclude_coupons'        => array_map( 'sanitize_text_field', $exclude_coupons_oe ),
        ];

        $signup                    = $input['signup_bonus'];
        $input['signup_bonus']     = [
            'enabled' => ! empty( $signup['enabled'] ),
            'points'  => isset( $signup['points'] ) ? max( 0, (int) $signup['points'] ) : 0,
        ];

        $ref                       = $input['referral'];
        $input['referral']         = [
            'enabled'        => ! empty( $ref['enabled'] ),
            'referrer_bonus' => isset( $ref['referrer_bonus'] ) ? max( 0, (int) $ref['referrer_bonus'] ) : 0,
            'referred_bonus' => isset( $ref['referred_bonus'] ) ? max( 0, (int) $ref['referred_bonus'] ) : 0,
        ];

        $login                     = $input['login'];
        $input['login']            = [
            'enabled'   => ! empty( $login['enabled'] ),
            'threshold' => isset( $login['threshold'] ) ? max( 0, (int) $login['threshold'] ) : 0,
            'points'    => isset( $login['points'] ) ? max( 0, (int) $login['points'] ) : 0,
        ];

        $anniversary               = $input['anniversary'];
        $input['anniversary']      = [
            'enabled' => ! empty( $anniversary['enabled'] ),
            'points'  => isset( $anniversary['points'] ) ? max( 0, (int) $anniversary['points'] ) : 0,
        ];

        $redemption                = $input['redemption'];
        $exclude_coupons_red       = isset( $redemption['exclude_coupons'] ) && is_array( $redemption['exclude_coupons'] ) ? $redemption['exclude_coupons'] : [];
        $input['redemption']      = [
            'enabled'                => ! empty( $redemption['enabled'] ),
            'points_per_unit'        => isset( $redemption['points_per_unit'] ) ? max( 1, (int) $redemption['points_per_unit'] ) : 100,
            'unit_value'             => isset( $redemption['unit_value'] ) ? max( 0, (float) $redemption['unit_value'] ) : 1,
            'max_percent'            => isset( $redemption['max_percent'] ) ? max( 0, (float) $redemption['max_percent'] ) : 0,
            'auto_mode'              => in_array( $redemption['auto_mode'] ?? 'disabled', [ 'disabled', 'max', 'percent' ], true ) ? $redemption['auto_mode'] : 'disabled',
            'auto_percent'           => isset( $redemption['auto_percent'] ) ? max( 0, min( 100, (int) $redemption['auto_percent'] ) ) : 0,
            'return_on_refund'       => ! empty( $redemption['return_on_refund'] ),
            'allow_manual_input'     => ! empty( $redemption['allow_manual_input'] ),
            'exclude_coupons_enabled'=> ! empty( $redemption['exclude_coupons_enabled'] ),
            'exclude_coupons'        => array_map( 'sanitize_text_field', $exclude_coupons_red ),
        ];

        $display                   = $input['display'];
        $input['display']          = [
            'show_my_account' => ! empty( $display['show_my_account'] ),
            'show_cart'       => ! empty( $display['show_cart'] ),
            'show_checkout'   => ! empty( $display['show_checkout'] ),
        ];

        // Clear settings cache after save.
        Settings_Cache::clear();

        return $input;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Loyalty & Rewards Settings', 'wc-loyalty-rewards' ); ?></h1>
            <style>
                .wclr-panel { display: none; }
                .wclr-panel.is-active { display: block; }
                .wclr-panel .postbox { margin-top: 16px; }
                .wclr-panel .postbox:first-of-type { margin-top: 8px; }
            </style>
            <form method="post" action="options.php">
                <?php settings_fields( 'wclr_settings' ); ?>
                <h2 class="nav-tab-wrapper">
                    <?php
                    $panels = [
                        'general'    => __( 'General', 'wc-loyalty-rewards' ),
                        'earning'    => __( 'Earning', 'wc-loyalty-rewards' ),
                        'redemption' => __( 'Redemption', 'wc-loyalty-rewards' ),
                        'display'    => __( 'Display', 'wc-loyalty-rewards' ),
                        'utilities'  => __( 'Shortcodes', 'wc-loyalty-rewards' ),
                    ];
                    $first = true;
                    foreach ( $panels as $id => $label ) :
                        $active_class = $first ? ' nav-tab-active' : '';
                        $first = false;
                        ?>
                        <a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab wclr-tab-button<?php echo esc_attr( $active_class ); ?>" data-target="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </h2>

                <div id="wclr-panel-general" class="wclr-panel is-active">
                    <h2><?php esc_html_e( 'General', 'wc-loyalty-rewards' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Program', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Enable loyalty program', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Base Earn Rate (points per currency unit)', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" step="0.01" name="wclr_settings[base_rate]" value="<?php echo esc_attr( $settings['base_rate'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Base Multiplier', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" step="0.1" name="wclr_settings[base_multiplier]" value="<?php echo esc_attr( $settings['base_multiplier'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[delete_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?> /> <?php esc_html_e( 'Remove plugin data when uninstalling', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                    </table>
                </div>

                <div id="wclr-panel-earning" class="wclr-panel">
                    <h2><?php esc_html_e( 'Earning', 'wc-loyalty-rewards' ); ?></h2>
                    <div class="postbox">
                        <div class="postbox-header"><h3><?php esc_html_e( 'Orders', 'wc-loyalty-rewards' ); ?></h3></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[order_earning][enabled]" value="1" <?php checked( ! empty( $settings['order_earning']['enabled'] ) ); ?> /> <?php esc_html_e( 'Earn points on completed orders', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Include tax', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[order_earning][include_tax]" value="1" <?php checked( ! empty( $settings['order_earning']['include_tax'] ) ); ?> /> <?php esc_html_e( 'Count tax in subtotal', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Include shipping', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[order_earning][include_shipping]" value="1" <?php checked( ! empty( $settings['order_earning']['include_shipping'] ) ); ?> /> <?php esc_html_e( 'Count shipping in subtotal', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Minimum order total', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" step="0.01" name="wclr_settings[order_earning][min_order]" value="<?php echo esc_attr( $settings['order_earning']['min_order'] ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Refund behavior', 'wc-loyalty-rewards' ); ?></th>
                                    <td>
                                        <select name="wclr_settings[order_earning][refund_behavior]">
                                            <option value="reverse" <?php selected( $settings['order_earning']['refund_behavior'], 'reverse' ); ?>><?php esc_html_e( 'Reverse on full refund', 'wc-loyalty-rewards' ); ?></option>
                                            <option value="prorate" <?php selected( $settings['order_earning']['refund_behavior'], 'prorate' ); ?>><?php esc_html_e( 'Prorate on partial refund', 'wc-loyalty-rewards' ); ?></option>
                                            <option value="ignore" <?php selected( $settings['order_earning']['refund_behavior'], 'ignore' ); ?>><?php esc_html_e( 'Do nothing', 'wc-loyalty-rewards' ); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Exclude coupons', 'wc-loyalty-rewards' ); ?></th>
                                    <td>
                                        <?php
                                        $exclude_coupons_enabled_oe = ! empty( $settings['order_earning']['exclude_coupons_enabled'] );
                                        $excluded_coupons_oe = isset( $settings['order_earning']['exclude_coupons'] ) && is_array( $settings['order_earning']['exclude_coupons'] ) ? $settings['order_earning']['exclude_coupons'] : [];
                                        $all_coupons = $this->get_all_coupons();
                                        ?>
                                        <label>
                                            <input type="checkbox" name="wclr_settings[order_earning][exclude_coupons_enabled]" value="1" <?php checked( $exclude_coupons_enabled_oe ); ?> class="wclr-exclude-coupons-toggle" data-target="wclr-exclude-coupons-oe" />
                                            <?php esc_html_e( 'Exclude specific coupons from earning points', 'wc-loyalty-rewards' ); ?>
                                        </label>
                                        <div id="wclr-exclude-coupons-oe" style="margin-top: 10px; <?php echo $exclude_coupons_enabled_oe ? '' : 'display: none;'; ?>">
                                            <input type="text" class="wclr-coupon-search" placeholder="<?php esc_attr_e( 'Search coupons...', 'wc-loyalty-rewards' ); ?>" style="width: 100%; margin-bottom: 5px; padding: 5px;" />
                                            <select name="wclr_settings[order_earning][exclude_coupons][]" multiple="multiple" class="wclr-coupon-select" style="width: 100%; min-height: 150px;">
                                                <?php foreach ( $all_coupons as $code => $name ) : ?>
                                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( in_array( $code, $excluded_coupons_oe, true ) ); ?>><?php echo esc_html( $name ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php esc_html_e( 'Select coupons that should prevent points from being earned. Hold Ctrl/Cmd to select multiple.', 'wc-loyalty-rewards' ); ?></p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h3><?php esc_html_e( 'Signup', 'wc-loyalty-rewards' ); ?></h3></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[signup_bonus][enabled]" value="1" <?php checked( ! empty( $settings['signup_bonus']['enabled'] ) ); ?> /> <?php esc_html_e( 'Grant points on first signup', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Points', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[signup_bonus][points]" value="<?php echo esc_attr( $settings['signup_bonus']['points'] ); ?>" /></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h3><?php esc_html_e( 'Referral', 'wc-loyalty-rewards' ); ?></h3></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[referral][enabled]" value="1" <?php checked( ! empty( $settings['referral']['enabled'] ) ); ?> /> <?php esc_html_e( 'Reward referrers and referred users', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Referrer bonus', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[referral][referrer_bonus]" value="<?php echo esc_attr( $settings['referral']['referrer_bonus'] ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Referred user bonus', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[referral][referred_bonus]" value="<?php echo esc_attr( $settings['referral']['referred_bonus'] ); ?>" /></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h3><?php esc_html_e( 'Login Activity', 'wc-loyalty-rewards' ); ?></h3></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[login][enabled]" value="1" <?php checked( ! empty( $settings['login']['enabled'] ) ); ?> /> <?php esc_html_e( 'Award points after X logins per week', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Required logins (per week)', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[login][threshold]" value="<?php echo esc_attr( $settings['login']['threshold'] ); ?>" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Points when reached', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[login][points]" value="<?php echo esc_attr( $settings['login']['points'] ); ?>" /></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="postbox">
                        <div class="postbox-header"><h3><?php esc_html_e( 'Anniversary', 'wc-loyalty-rewards' ); ?></h3></div>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable', 'wc-loyalty-rewards' ); ?></th>
                                    <td><label><input type="checkbox" name="wclr_settings[anniversary][enabled]" value="1" <?php checked( ! empty( $settings['anniversary']['enabled'] ) ); ?> /> <?php esc_html_e( 'Award yearly anniversary points', 'wc-loyalty-rewards' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Points', 'wc-loyalty-rewards' ); ?></th>
                                    <td><input type="number" name="wclr_settings[anniversary][points]" value="<?php echo esc_attr( $settings['anniversary']['points'] ); ?>" /></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="wclr-panel-redemption" class="wclr-panel">
                    <h2><?php esc_html_e( 'Redemption', 'wc-loyalty-rewards' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable redemption', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[redemption][enabled]" value="1" <?php checked( ! empty( $settings['redemption']['enabled'] ) ); ?> /> <?php esc_html_e( 'Allow customers to redeem points at checkout', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Points per unit', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" name="wclr_settings[redemption][points_per_unit]" value="<?php echo esc_attr( $settings['redemption']['points_per_unit'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Unit value', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" step="0.01" name="wclr_settings[redemption][unit_value]" value="<?php echo esc_attr( $settings['redemption']['unit_value'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Max percent of order', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" step="1" name="wclr_settings[redemption][max_percent]" value="<?php echo esc_attr( $settings['redemption']['max_percent'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto redeem mode', 'wc-loyalty-rewards' ); ?></th>
                            <td>
                                <select name="wclr_settings[redemption][auto_mode]">
                                    <option value="disabled" <?php selected( $settings['redemption']['auto_mode'], 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'wc-loyalty-rewards' ); ?></option>
                                    <option value="max" <?php selected( $settings['redemption']['auto_mode'], 'max' ); ?>><?php esc_html_e( 'Use max points', 'wc-loyalty-rewards' ); ?></option>
                                    <option value="percent" <?php selected( $settings['redemption']['auto_mode'], 'percent' ); ?>><?php esc_html_e( 'Use fixed percent of balance', 'wc-loyalty-rewards' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'If percent mode, set percent below.', 'wc-loyalty-rewards' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto redeem percent of balance', 'wc-loyalty-rewards' ); ?></th>
                            <td><input type="number" step="1" name="wclr_settings[redemption][auto_percent]" value="<?php echo esc_attr( $settings['redemption']['auto_percent'] ); ?>" />%</td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Return spent points on refund', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[redemption][return_on_refund]" value="1" <?php checked( ! empty( $settings['redemption']['return_on_refund'] ) ); ?> /> <?php esc_html_e( 'Restore redeemed points when an order is refunded', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Allow manual input', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[redemption][allow_manual_input]" value="1" <?php checked( ! empty( $settings['redemption']['allow_manual_input'] ) ); ?> /> <?php esc_html_e( 'Show points input and button to customers', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Exclude coupons', 'wc-loyalty-rewards' ); ?></th>
                            <td>
                                <?php
                                $exclude_coupons_enabled_red = ! empty( $settings['redemption']['exclude_coupons_enabled'] );
                                $excluded_coupons_red = isset( $settings['redemption']['exclude_coupons'] ) && is_array( $settings['redemption']['exclude_coupons'] ) ? $settings['redemption']['exclude_coupons'] : [];
                                $all_coupons = $this->get_all_coupons();
                                ?>
                                <label>
                                    <input type="checkbox" name="wclr_settings[redemption][exclude_coupons_enabled]" value="1" <?php checked( $exclude_coupons_enabled_red ); ?> class="wclr-exclude-coupons-toggle" data-target="wclr-exclude-coupons-red" />
                                    <?php esc_html_e( 'Exclude specific coupons from point redemption', 'wc-loyalty-rewards' ); ?>
                                </label>
                                <div id="wclr-exclude-coupons-red" style="margin-top: 10px; <?php echo $exclude_coupons_enabled_red ? '' : 'display: none;'; ?>">
                                    <input type="text" class="wclr-coupon-search" placeholder="<?php esc_attr_e( 'Search coupons...', 'wc-loyalty-rewards' ); ?>" style="width: 100%; margin-bottom: 5px; padding: 5px;" />
                                    <select name="wclr_settings[redemption][exclude_coupons][]" multiple="multiple" class="wclr-coupon-select" style="width: 100%; min-height: 150px;">
                                        <?php foreach ( $all_coupons as $code => $name ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( in_array( $code, $excluded_coupons_red, true ) ); ?>><?php echo esc_html( $name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Select coupons that should prevent point redemption. Hold Ctrl/Cmd to select multiple.', 'wc-loyalty-rewards' ); ?></p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="wclr-panel-display" class="wclr-panel">
                    <h2><?php esc_html_e( 'Display', 'wc-loyalty-rewards' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'My Account tab', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[display][show_my_account]" value="1" <?php checked( ! empty( $settings['display']['show_my_account'] ) ); ?> /> <?php esc_html_e( 'Show loyalty tab in My Account', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Cart widget', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[display][show_cart]" value="1" <?php checked( ! empty( $settings['display']['show_cart'] ) ); ?> /> <?php esc_html_e( 'Show points and redeem UI on cart', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Checkout widget', 'wc-loyalty-rewards' ); ?></th>
                            <td><label><input type="checkbox" name="wclr_settings[display][show_checkout]" value="1" <?php checked( ! empty( $settings['display']['show_checkout'] ) ); ?> /> <?php esc_html_e( 'Show points and redeem UI on checkout', 'wc-loyalty-rewards' ); ?></label></td>
                        </tr>
                    </table>
                </div>

                <div id="wclr-panel-utilities" class="wclr-panel">
                    <h2><?php esc_html_e( 'Shortcodes', 'wc-loyalty-rewards' ); ?></h2>
                    <table class="widefat striped" style="max-width: 900px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Shortcode', 'wc-loyalty-rewards' ); ?></th>
                                <th><?php esc_html_e( 'Description', 'wc-loyalty-rewards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>[wclr_points_balance]</code></td>
                                <td><?php esc_html_e( 'Displays the current user\'s points balance and lifetime points.', 'wc-loyalty-rewards' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>[wclr_tier_info]</code></td>
                                <td><?php esc_html_e( 'Shows the user\'s current tier name and multiplier.', 'wc-loyalty-rewards' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>[wclr_referral_block]</code></td>
                                <td><?php esc_html_e( 'Outputs referral code and link for the logged-in user.', 'wc-loyalty-rewards' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>[wclr_recent_ledger limit="10"]</code></td>
                                <td><?php esc_html_e( 'Shows recent ledger entries (earn/spend/adjustments) for the user. Use the limit attribute to change count.', 'wc-loyalty-rewards' ); ?></td>
                            </tr>
                            <tr>
                                <td><code>[wclr_redeem_widget]</code></td>
                                <td><?php esc_html_e( 'Renders the redeem form/block (same as cart/checkout widget) for inserting elsewhere.', 'wc-loyalty-rewards' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e( 'Place these in pages or templates. Shortcodes output user-specific data when logged in.', 'wc-loyalty-rewards' ); ?></p>
                </div>

                <?php submit_button(); ?>
            </form>
            <script>
                (function() {
                    const tabs = document.querySelectorAll('.wclr-tab-button');
                    const panels = document.querySelectorAll('.wclr-panel');
                    const validIds = ['general','earning','redemption','display','utilities'];

                    function activate(id, skipHash) {
                        if (!validIds.includes(id)) {
                            id = 'general';
                        }
                        tabs.forEach(function(btn) {
                            if (btn.dataset.target === id) {
                                btn.classList.add('nav-tab-active');
                            } else {
                                btn.classList.remove('nav-tab-active');
                            }
                        });
                        panels.forEach(function(panel) {
                            if (panel.id === 'wclr-panel-' + id) {
                                panel.classList.add('is-active');
                            } else {
                                panel.classList.remove('is-active');
                            }
                        });
                        if (!skipHash && window.history && window.location) {
                            const url = new URL(window.location);
                            url.hash = id;
                            window.history.replaceState(null, '', url.toString());
                        }
                    }
                    tabs.forEach(function(btn) {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            activate(btn.dataset.target);
                        });
                    });
                    const hash = (window.location && window.location.hash) ? window.location.hash.replace('#', '') : '';
                    const initial = validIds.includes(hash) ? hash : 'general';
                    activate(initial, true);

                    // Toggle exclude coupons multi-select
                    document.querySelectorAll('.wclr-exclude-coupons-toggle').forEach(function(checkbox) {
                        const targetId = checkbox.dataset.target;
                        const targetDiv = document.getElementById(targetId);
                        if (targetDiv) {
                            checkbox.addEventListener('change', function() {
                                targetDiv.style.display = this.checked ? 'block' : 'none';
                            });
                        }
                    });

                    // Search functionality for coupon selects
                    document.querySelectorAll('.wclr-coupon-search').forEach(function(searchInput) {
                        const select = searchInput.nextElementSibling;
                        if (select && select.classList.contains('wclr-coupon-select')) {
                            searchInput.addEventListener('input', function() {
                                const searchTerm = this.value.toLowerCase();
                                const options = select.querySelectorAll('option');
                                options.forEach(function(option) {
                                    const text = option.textContent.toLowerCase();
                                    option.style.display = text.indexOf(searchTerm) !== -1 ? '' : 'none';
                                });
                            });
                        }
                    });
                })();
            </script>
        </div>
        <?php
    }

    /**
     * Render ledger page (simplified).
     */
    public function render_ledger_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }
        global $wpdb;
        $table   = $wpdb->prefix . 'wclr_points_ledger';
        $entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", 100 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Points Ledger', 'wc-loyalty-rewards' ); ?></h1>

            <?php if ( isset( $_GET['wclr_import_result'] ) && 'success' === $_GET['wclr_import_result'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html( sprintf( __( 'Import completed. Imported: %d. Skipped: %d.', 'wc-loyalty-rewards' ), (int) ( $_GET['imported'] ?? 0 ), (int) ( $_GET['skipped'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
                </div>
            <?php elseif ( isset( $_GET['wclr_import_result'] ) && 'missing' === $_GET['wclr_import_result'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'No file uploaded for import.', 'wc-loyalty-rewards' ); ?></p></div>
            <?php elseif ( isset( $_GET['wclr_import_result'] ) && 'failed_open' === $_GET['wclr_import_result'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Could not open the uploaded file.', 'wc-loyalty-rewards' ); ?></p></div>
            <?php elseif ( isset( $_GET['wclr_recalc'] ) && 'success' === $_GET['wclr_recalc'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html( sprintf( __( 'Lifetime recalculated for %d users.', 'wc-loyalty-rewards' ), (int) ( $_GET['updated'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Import / Export Points', 'wc-loyalty-rewards' ); ?></h2>
            <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wclr_export_points' ); ?>
                    <input type="hidden" name="action" value="wclr_export_points" />
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Export Points CSV', 'wc-loyalty-rewards' ); ?></button></p>
                    <p class="description"><?php esc_html_e( 'Exports user_id, email, balance, lifetime.', 'wc-loyalty-rewards' ); ?></p>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'wclr_import_points' ); ?>
                    <input type="hidden" name="action" value="wclr_import_points" />
                    <p>
                        <label for="wclr_points_file"><strong><?php esc_html_e( 'Import CSV', 'wc-loyalty-rewards' ); ?></strong></label><br/>
                        <input type="file" name="wclr_points_file" id="wclr_points_file" accept=".csv" required />
                    </p>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import Points', 'wc-loyalty-rewards' ); ?></button></p>
                    <p class="description"><?php esc_html_e( 'CSV columns: user_id, user_email, points_balance, lifetime_points', 'wc-loyalty-rewards' ); ?></p>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wclr_recalc_lifetime' ); ?>
                    <input type="hidden" name="action" value="wclr_recalc_lifetime" />
                    <p><button type="submit" class="button"><?php esc_html_e( 'Recalculate Lifetime Points', 'wc-loyalty-rewards' ); ?></button></p>
                    <p class="description"><?php esc_html_e( 'Rebuilds lifetime points from earn ledger entries for all users.', 'wc-loyalty-rewards' ); ?></p>
                </form>
            </div>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'User', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'Balance After', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'Context', 'wc-loyalty-rewards' ); ?></th>
                    <th><?php esc_html_e( 'Order', 'wc-loyalty-rewards' ); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ( $entries as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $entry['created_at'] ); ?></td>
                        <td><?php echo esc_html( get_userdata( (int) $entry['user_id'] )->user_login ?? '' ); ?></td>
                        <td><?php echo esc_html( $entry['type'] ); ?></td>
                        <td><?php echo esc_html( $entry['amount'] ); ?></td>
                        <td><?php echo esc_html( $entry['balance_after'] ); ?></td>
                        <td><?php echo esc_html( $entry['context'] ); ?></td>
                        <td><?php echo esc_html( $entry['order_id'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render tier management page.
     */
    public function render_tiers_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }

        $this->handle_tier_actions();

        global $wpdb;
        $table = $wpdb->prefix . 'wclr_tiers';
        $tiers = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Tiers', 'wc-loyalty-rewards' ); ?></h1>
            <h2 class="title"><?php esc_html_e( 'Existing Tiers', 'wc-loyalty-rewards' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'wclr_save_tiers', 'wclr_save_tiers_nonce' ); ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'Order', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Min Points', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Max Points', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Multiplier', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Enabled', 'wc-loyalty-rewards' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-loyalty-rewards' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $tiers as $tier ) : ?>
                        <tr>
                            <td><input type="number" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][sort_order]" value="<?php echo esc_attr( $tier['sort_order'] ); ?>" /></td>
                            <td><input type="text" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][name]" value="<?php echo esc_attr( $tier['name'] ); ?>" /></td>
                            <td><input type="number" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][min_lifetime_points]" value="<?php echo esc_attr( $tier['min_lifetime_points'] ); ?>" /></td>
                            <td><input type="number" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][max_lifetime_points]" value="<?php echo esc_attr( $tier['max_lifetime_points'] ); ?>" /></td>
                            <td><input type="number" step="0.01" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][multiplier]" value="<?php echo esc_attr( $tier['multiplier'] ); ?>" /></td>
                            <td><input type="checkbox" name="tiers[<?php echo esc_attr( $tier['id'] ); ?>][enabled]" value="1" <?php checked( (int) $tier['enabled'], 1 ); ?> /></td>
                            <td>
                                <button type="submit" name="wclr_tier_action" value="update_<?php echo esc_attr( $tier['id'] ); ?>" class="button"><?php esc_html_e( 'Save', 'wc-loyalty-rewards' ); ?></button>
                                <button type="submit" name="wclr_tier_action" value="delete_<?php echo esc_attr( $tier['id'] ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this tier?', 'wc-loyalty-rewards' ) ); ?>');"><?php esc_html_e( 'Delete', 'wc-loyalty-rewards' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="submit" name="wclr_tier_action" value="bulk_save" class="button button-primary"><?php esc_html_e( 'Save Changes', 'wc-loyalty-rewards' ); ?></button></p>
            </form>

            <h2><?php esc_html_e( 'Add New Tier', 'wc-loyalty-rewards' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'wclr_add_tier', 'wclr_add_tier_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Name', 'wc-loyalty-rewards' ); ?></th>
                        <td><input type="text" name="new_tier[name]" required /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Min lifetime points', 'wc-loyalty-rewards' ); ?></th>
                        <td><input type="number" name="new_tier[min_lifetime_points]" min="0" value="0" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max lifetime points (optional)', 'wc-loyalty-rewards' ); ?></th>
                        <td><input type="number" name="new_tier[max_lifetime_points]" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Multiplier', 'wc-loyalty-rewards' ); ?></th>
                        <td><input type="number" step="0.01" name="new_tier[multiplier]" value="1.0" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sort order', 'wc-loyalty-rewards' ); ?></th>
                        <td><input type="number" name="new_tier[sort_order]" value="<?php echo esc_attr( count( $tiers ) + 1 ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Enabled', 'wc-loyalty-rewards' ); ?></th>
                        <td><label><input type="checkbox" name="new_tier[enabled]" value="1" checked /> <?php esc_html_e( 'Enable this tier', 'wc-loyalty-rewards' ); ?></label></td>
                    </tr>
                </table>
                <p><button type="submit" name="wclr_tier_action" value="add" class="button button-primary"><?php esc_html_e( 'Add Tier', 'wc-loyalty-rewards' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle tier CRUD actions.
     */
    private function handle_tier_actions(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_tiers';

        if ( isset( $_POST['wclr_tier_action'] ) && 'add' === $_POST['wclr_tier_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wclr_add_tier_nonce'] ?? '' ) ), 'wclr_add_tier' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return;
            }
            $tier = wp_parse_args( $_POST['new_tier'] ?? [], [] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $table,
                [
                    'name'                => sanitize_text_field( $tier['name'] ?? '' ),
                    'min_lifetime_points' => isset( $tier['min_lifetime_points'] ) ? (int) $tier['min_lifetime_points'] : 0,
                    'max_lifetime_points' => ( '' === ( $tier['max_lifetime_points'] ?? '' ) ) ? null : (int) $tier['max_lifetime_points'],
                    'multiplier'          => isset( $tier['multiplier'] ) ? (float) $tier['multiplier'] : 1.0,
                    'sort_order'          => isset( $tier['sort_order'] ) ? (int) $tier['sort_order'] : 0,
                    'enabled'             => ! empty( $tier['enabled'] ) ? 1 : 0,
                    'created_at'          => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%d', '%f', '%d', '%d', '%s' ]
            );
            if ( false !== $result ) {
                do_action( 'wclr_tier_updated' );
            }
            return;
        }

        if ( empty( $_POST['wclr_tier_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['wclr_tier_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! empty( $_POST['wclr_save_tiers_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wclr_save_tiers_nonce'] ) ), 'wclr_save_tiers' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( 'bulk_save' === $action && ! empty( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                foreach ( $_POST['tiers'] as $id => $tier_data ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $id = (int) $id;
                    $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $table,
                        [
                            'name'                => sanitize_text_field( $tier_data['name'] ?? '' ),
                            'min_lifetime_points' => isset( $tier_data['min_lifetime_points'] ) ? (int) $tier_data['min_lifetime_points'] : 0,
                            'max_lifetime_points' => ( '' === ( $tier_data['max_lifetime_points'] ?? '' ) ) ? null : (int) $tier_data['max_lifetime_points'],
                            'multiplier'          => isset( $tier_data['multiplier'] ) ? (float) $tier_data['multiplier'] : 1.0,
                            'sort_order'          => isset( $tier_data['sort_order'] ) ? (int) $tier_data['sort_order'] : 0,
                            'enabled'             => ! empty( $tier_data['enabled'] ) ? 1 : 0,
                        ],
                        [ 'id' => $id ],
                        [ '%s', '%d', '%d', '%f', '%d', '%d' ],
                        [ '%d' ]
                    );
                    if ( false !== $result ) {
                        do_action( 'wclr_tier_updated' );
                    }
                }
                return;
            }

            if ( str_starts_with( $action, 'update_' ) ) {
                $id        = (int) str_replace( 'update_', '', $action );
                $tier_data = $_POST['tiers'][ $id ] ?? []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $table,
                    [
                        'name'                => sanitize_text_field( $tier_data['name'] ?? '' ),
                        'min_lifetime_points' => isset( $tier_data['min_lifetime_points'] ) ? (int) $tier_data['min_lifetime_points'] : 0,
                        'max_lifetime_points' => ( '' === ( $tier_data['max_lifetime_points'] ?? '' ) ) ? null : (int) $tier_data['max_lifetime_points'],
                        'multiplier'          => isset( $tier_data['multiplier'] ) ? (float) $tier_data['multiplier'] : 1.0,
                        'sort_order'          => isset( $tier_data['sort_order'] ) ? (int) $tier_data['sort_order'] : 0,
                        'enabled'             => ! empty( $tier_data['enabled'] ) ? 1 : 0,
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%d', '%d', '%f', '%d', '%d' ],
                    [ '%d' ]
                );
                if ( false !== $result ) {
                    do_action( 'wclr_tier_updated' );
                }
                return;
            }

            if ( str_starts_with( $action, 'delete_' ) ) {
                $id = (int) str_replace( 'delete_', '', $action );
                $result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( false !== $result ) {
                    do_action( 'wclr_tier_updated' );
                }
            }
        }
    }

    /**
     * Handle recalculation of lifetime points.
     */
    public function handle_recalc_lifetime(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }
        check_admin_referer( 'wclr_recalc_lifetime' );
        $updated = $this->points->recalc_lifetime_points_all();
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'       => 'wclr-ledger',
                    'wclr_recalc'=> 'success',
                    'updated'    => $updated,
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Export points to CSV.
     */
    public function handle_export_points(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }
        check_admin_referer( 'wclr_export_points' );

        $filename = 'wclr-points-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'user_id', 'user_email', 'points_balance', 'lifetime_points' ] );

        $users = get_users(
            [
                'fields' => [ 'ID', 'user_email' ],
            ]
        );

        foreach ( $users as $user ) {
            $meta     = get_user_meta( $user->ID );
            $balance  = isset( $meta['_wclr_points_balance'][0] ) ? (int) $meta['_wclr_points_balance'][0] : 0;
            $lifetime = isset( $meta['_wclr_lifetime_points'][0] ) ? (int) $meta['_wclr_lifetime_points'][0] : 0;
            fputcsv(
                $output,
                [
                    $user->ID,
                    $user->user_email,
                    $balance,
                    $lifetime,
                ]
            );
        }

        fclose( $output );
        exit;
    }

    /**
     * Import points from CSV.
     */
    public function handle_import_points(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'wc-loyalty-rewards' ) );
        }
        check_admin_referer( 'wclr_import_points' );

        if ( empty( $_FILES['wclr_points_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            wp_safe_redirect(
                add_query_arg(
                    'wclr_import_result',
                    'missing',
                    admin_url( 'admin.php?page=wclr-ledger' )
                )
            );
            exit;
        }

        $file   = $_FILES['wclr_points_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            wp_safe_redirect(
                add_query_arg(
                    'wclr_import_result',
                    'failed_open',
                    admin_url( 'admin.php?page=wclr-ledger' )
                )
            );
            exit;
        }

        // Read header and map columns (case-insensitive).
        $header = fgetcsv( $handle );
        $header = is_array( $header ) ? array_map( 'strtolower', $header ) : [];

        $col_user_id   = array_search( 'user_id', $header, true );
        $col_email     = array_search( 'user_email', $header, true );
        $col_balance   = array_search( 'points_balance', $header, true );
        $col_lifetime  = array_search( 'lifetime_points', $header, true );

        // Fallback for simple 2/3/4-column CSV without headers.
        $has_header = ! empty( $header ) && false !== $col_user_id;
        $imported   = 0;
        $skipped    = 0;

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( empty( $row ) ) {
                continue;
            }

            if ( ! $has_header ) {
                // Expect: user_id, points_balance, [lifetime_points]
                $user_id        = isset( $row[0] ) ? (int) $row[0] : 0;
                $points_balance = isset( $row[1] ) ? (int) $row[1] : 0;
                $lifetime_points = isset( $row[2] ) ? (int) $row[2] : $points_balance;
                $email          = $row[3] ?? '';
            } else {
                $user_id        = ( $col_user_id !== false && isset( $row[ $col_user_id ] ) ) ? (int) $row[ $col_user_id ] : 0;
                $email          = ( $col_email !== false && isset( $row[ $col_email ] ) ) ? sanitize_email( $row[ $col_email ] ) : '';
                $points_balance = ( $col_balance !== false && isset( $row[ $col_balance ] ) ) ? (int) $row[ $col_balance ] : 0;
                $lifetime_points = ( $col_lifetime !== false && isset( $row[ $col_lifetime ] ) ) ? (int) $row[ $col_lifetime ] : $points_balance;
            }

            if ( $user_id <= 0 ) {
                $skipped++;
                continue;
            }

            $user = $this->find_user_for_import( (int) $user_id, $email );
            if ( ! $user ) {
                $skipped++;
                continue;
            }

            $current_balance = $this->points->get_user_balance( $user->ID )->balance;
            $delta           = $points_balance - $current_balance;

            // Replace current balance to match imported balance via delta (ledger-safe).
            if ( 0 !== $delta ) {
                $this->points->add_points(
                    $user->ID,
                    $delta,
                    'adjustment',
                    [
                        'context'  => 'import',
                        'admin_id' => get_current_user_id(),
                        'reason'   => 'CSV import',
                    ]
                );
            }

            update_user_meta( $user->ID, '_wclr_lifetime_points', (int) $lifetime_points );
            wp_cache_delete( $user->ID, 'user_meta' );
            $imported++;
        }

        fclose( $handle );

        wp_safe_redirect(
            add_query_arg(
                [
                    'wclr_import_result' => 'success',
                    'imported'           => $imported,
                    'skipped'            => $skipped,
                ],
                admin_url( 'admin.php?page=wclr-ledger' )
            )
        );
        exit;
    }

    /**
     * Find user by ID or email.
     *
     * @return \WP_User|null
     */
    private function find_user_for_import( int $user_id, string $email ) {
        if ( $user_id > 0 ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user ) {
                return $user;
            }
        }
        if ( ! empty( $email ) ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Show profile section.
     */
    public function render_user_profile( \WP_User $user ): void {
        $balance  = $this->points->get_user_balance( $user->ID );
        $tier     = $this->tiers->get_user_tier( $user->ID );
        ?>
        <h2><?php esc_html_e( 'Loyalty & Rewards', 'wc-loyalty-rewards' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Current Balance', 'wc-loyalty-rewards' ); ?></th>
                <td><?php echo esc_html( $balance->balance ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Lifetime Points', 'wc-loyalty-rewards' ); ?></th>
                <td><?php echo esc_html( $balance->lifetime_points ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Tier', 'wc-loyalty-rewards' ); ?></th>
                <td><?php echo esc_html( $tier ? $tier->name : __( 'None', 'wc-loyalty-rewards' ) ); ?></td>
            </tr>
            <?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Adjust Points', 'wc-loyalty-rewards' ); ?></th>
                    <td>
                        <label for="wclr_adjust_points"><?php esc_html_e( 'Amount (use negative to deduct)', 'wc-loyalty-rewards' ); ?></label>
                        <input type="number" name="wclr_adjust_points" id="wclr_adjust_points" value="0" step="1" />
                        <input type="text" name="wclr_adjust_reason" placeholder="<?php esc_attr_e( 'Reason', 'wc-loyalty-rewards' ); ?>" />
                        <?php wp_nonce_field( 'wclr_adjust_points_action', 'wclr_adjust_points_nonce' ); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * Handle admin adjustment from profile.
     */
    public function handle_user_adjustment( int $user_id ): void {
        if ( empty( $_POST['wclr_adjust_points_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wclr_adjust_points_nonce'] ) ), 'wclr_adjust_points_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $amount = isset( $_POST['wclr_adjust_points'] ) ? (int) wp_unslash( $_POST['wclr_adjust_points'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $reason = isset( $_POST['wclr_adjust_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wclr_adjust_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 0 === $amount ) {
            return;
        }
        $this->points->adjust_points( $user_id, $amount, $reason, get_current_user_id() );
    }

    /**
     * Get all WooCommerce coupons as array of code => name.
     *
     * @return array<string, string>
     */
    private function get_all_coupons(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [];
        }
        $coupons = get_posts(
            [
                'post_type'      => 'shop_coupon',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]
        );
        $result = [];
        foreach ( $coupons as $coupon ) {
            $code = $coupon->post_title;
            $result[ $code ] = $code;
        }
        return $result;
    }

    /**
     * Add points column to users list.
     *
     * @param array<string, string> $columns Existing columns.
     * @return array<string, string>
     */
    public function add_points_column( array $columns ): array {
        $columns['wclr_points'] = __( 'Points', 'wc-loyalty-rewards' );
        return $columns;
    }

    /**
     * Render points column content.
     *
     * @param string $value       Column value.
     * @param string $column_name Column name.
     * @param int    $user_id     User ID.
     * @return string
     */
    public function render_points_column( string $value, string $column_name, int $user_id ): string {
        if ( 'wclr_points' !== $column_name ) {
            return $value;
        }

        $balance = $this->points->get_user_balance( $user_id );
        $points  = $balance->balance;
        $tier     = $this->tiers->get_user_tier( $user_id );

        $output = '<strong>' . esc_html( number_format_i18n( $points ) ) . '</strong>';
        if ( $tier ) {
            $output .= '<br><small style="color: #666;">' . esc_html( $tier->name ) . '</small>';
        }

        return $output;
    }

    /**
     * Make points column sortable.
     *
     * @param array<string, string> $columns Sortable columns.
     * @return array<string, string>
     */
    public function make_points_column_sortable( array $columns ): array {
        $columns['wclr_points'] = 'wclr_points';
        return $columns;
    }

    /**
     * Handle sorting by points column.
     *
     * @param \WP_User_Query $query User query object.
     */
    public function handle_points_column_sorting( \WP_User_Query $query ): void {
        if ( ! is_admin() || ! isset( $_GET['orderby'] ) || 'wclr_points' !== $_GET['orderby'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $order = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        global $wpdb;
        $meta_key = '_wclr_points_balance';

        $query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS wclr_balance ON {$wpdb->users}.ID = wclr_balance.user_id AND wclr_balance.meta_key = '{$meta_key}'";
        $query->query_orderby = " ORDER BY CAST(wclr_balance.meta_value AS UNSIGNED) {$order}";
    }
}

