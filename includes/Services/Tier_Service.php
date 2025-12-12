<?php

namespace WCLR\Services;

use WCLR\Models\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * Handles tier retrieval and multipliers.
 */
class Tier_Service {

    /**
     * Cache for tiers.
     *
     * @var array<int, Tier>|null
     */
    private ?array $tiers_cache = null;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( 'wclr_tier_updated', [ $this, 'clear_cache' ] );
    }

    /**
     * Clear tier cache.
     */
    public function clear_cache(): void {
        $this->tiers_cache = null;
        wp_cache_delete( 'wclr_tiers', 'wclr' );
    }

    /**
     * Get all enabled tiers sorted (cached).
     *
     * @return array<int, Tier>
     */
    public function get_tiers(): array {
        if ( null !== $this->tiers_cache ) {
            return $this->tiers_cache;
        }

        // Try cache first.
        $cached = wp_cache_get( 'wclr_tiers', 'wclr' );
        if ( false !== $cached ) {
            $this->tiers_cache = $cached;
            return $this->tiers_cache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wclr_tiers';
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = %d ORDER BY sort_order ASC", 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tiers = [];
        foreach ( $rows as $row ) {
            $tiers[] = new Tier( $row );
        }

        $this->tiers_cache = $tiers;
        wp_cache_set( 'wclr_tiers', $tiers, 'wclr', HOUR_IN_SECONDS );
        return $tiers;
    }

    /**
     * Determine the tier for a user based on lifetime points.
     * Returns the highest qualifying tier (highest min_lifetime_points that user qualifies for).
     */
    public function get_user_tier( int $user_id ): ?Tier {
        $lifetime = (int) get_user_meta( $user_id, '_wclr_lifetime_points', true );
        $tiers    = $this->get_tiers();
        $current  = null;
        $highest_min = -1;

        foreach ( $tiers as $tier ) {
            // Check if user qualifies for this tier
            if ( $lifetime < $tier->min_lifetime_points ) {
                continue;
            }
            if ( null !== $tier->max_lifetime_points && $lifetime > $tier->max_lifetime_points ) {
                continue;
            }
            // Select tier with highest min_lifetime_points (best tier user qualifies for)
            if ( $tier->min_lifetime_points > $highest_min ) {
                $highest_min = $tier->min_lifetime_points;
                $current = $tier;
            }
        }
        return $current;
    }

    /**
     * Get multiplier for user.
     */
    public function get_multiplier_for_user( int $user_id ): float {
        $tier = $this->get_user_tier( $user_id );
        return $tier ? (float) $tier->multiplier : 1.0;
    }
}

