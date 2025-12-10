<?php

namespace WCLR;

use WCLR\Admin\Admin_Service;
use WCLR\Frontend\Frontend_Service;
use WCLR\Services\Cron_Service;
use WCLR\Services\Earning_Rules_Manager;
use WCLR\Services\Points_Service;
use WCLR\Services\Redemption_Service;
use WCLR\Services\Referral_Service;
use WCLR\Services\Tier_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin bootstrap.
 */
class Plugin {

    /**
     * Initialize plugin components.
     */
    public function init(): void {
        $this->load_textdomain();

        $services = $this->get_services();
        foreach ( $services as $service ) {
            if ( method_exists( $service, 'register' ) ) {
                $service->register();
            }
        }
    }

    /**
     * Load translations.
     */
    private function load_textdomain(): void {
        load_plugin_textdomain( 'wc-loyalty-rewards', false, dirname( plugin_basename( WCLR_PLUGIN_FILE ) ) . '/languages' );
    }

    /**
     * Build service instances.
     *
     * @return array<int, object>
     */
    private function get_services(): array {
        $points_service   = new Points_Service();
        $tier_service     = new Tier_Service();

        // Inject dependencies properly.
        $points_service->set_tier_service( $tier_service );

        $referral_service = new Referral_Service( $points_service );
        $redemption       = new Redemption_Service( $points_service, $tier_service );
        $earning_manager  = new Earning_Rules_Manager( $points_service, $tier_service, $referral_service );

        return [
            new Admin_Service( $points_service, $tier_service, $referral_service ),
            new Frontend_Service( $points_service, $tier_service, $referral_service ),
            $points_service,
            $tier_service,
            $referral_service,
            $redemption,
            $earning_manager,
            new Cron_Service( $points_service ),
        ];
    }
}

