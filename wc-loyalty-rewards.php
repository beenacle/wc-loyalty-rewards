<?php
/**
 * Plugin Name: Loyalty & Rewards for WooCommerce
 * Plugin URI:  https://beenacle.com/
 * Description: Reusable loyalty and rewards system for WooCommerce with configurable earning, tiers, referrals, and redemption.
 * Version:     1.0.5
 * Author:      Beenacle Technologies
 * Author URI:  https://beenacle.com/
 * Text Domain: wc-loyalty-rewards
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * License:     GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WCLR_PLUGIN_FILE' ) ) {
    define( 'WCLR_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WCLR_PLUGIN_DIR' ) ) {
    define( 'WCLR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WCLR_PLUGIN_URL' ) ) {
    define( 'WCLR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// GitHub updates (no helper plugin needed).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';

	$uc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/beenacle/wc-loyalty-rewards/',
		__FILE__,
		'wc-loyalty-rewards'
	);

	// Use GitHub Release ZIP assets (recommended).
	$uc->getVcsApi()->enableReleaseAssets();
}

// Simple PSR-4 style autoloader for this plugin.
spl_autoload_register(
    function ( string $class ) {
        $prefix = 'WCLR\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
        $path     = WCLR_PLUGIN_DIR . 'includes/' . $relative . '.php';
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
);

/**
 * Bootstrap the plugin once all plugins are loaded.
 */
function wclr_init_plugin(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action(
            'admin_notices',
            function () {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Loyalty & Rewards for WooCommerce requires WooCommerce to be active.', 'wc-loyalty-rewards' ) . '</p></div>';
            }
        );
        return;
    }

    $plugin = new WCLR\Plugin();
    $plugin->init();
}
add_action( 'plugins_loaded', 'wclr_init_plugin' );

/**
 * Activation hook.
 */
function wclr_activate(): void {
    require_once WCLR_PLUGIN_DIR . 'includes/Installer.php';
    $installer = new WCLR\Installer();
    $installer->activate();
    // Ensure custom endpoints (e.g., My Account tab) are registered immediately.
    add_rewrite_endpoint( 'wclr-loyalty', EP_ROOT | EP_PAGES );
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wclr_activate' );

/**
 * Deactivation hook placeholder for future needs.
 */
function wclr_deactivate(): void {
    // No-op for now; reserved for scheduled cleanup.
}
register_deactivation_hook( __FILE__, 'wclr_deactivate' );

