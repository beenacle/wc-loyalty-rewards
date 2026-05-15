<?php

namespace WCLR\Services;

use WCLR\Helpers\Settings_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates ledger / referral / tier data for the analytics dashboard.
 *
 * All public query methods accept ISO date strings (Y-m-d) and a granularity
 * ("day"|"week"|"month"). Results are cached in transients (5 min) keyed by
 * arguments so repeated dashboard loads stay cheap. Cache is invalidated
 * whenever the ledger is written via {@see self::bust_cache()}.
 */
class Analytics_Service {

    private const CACHE_GROUP   = 'wclr_analytics';
    private const CACHE_VERSION = 'wclr_analytics_cache_version';
    private const CACHE_TTL     = 5 * MINUTE_IN_SECONDS;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( 'wp_ajax_wclr_analytics_fetch', [ $this, 'ajax_fetch' ] );
        // Invalidate cache whenever ledger or referrals change.
        add_action( 'wc_loyalty_rewards_after_earn_points', [ $this, 'bust_cache' ] );
        add_action( 'wc_loyalty_rewards_after_redeem_points', [ $this, 'bust_cache' ] );
        add_action( 'wc_loyalty_rewards_after_admin_adjustment', [ $this, 'bust_cache' ] );
    }

    /**
     * AJAX endpoint that returns the full dashboard payload.
     */
    public function ajax_fetch(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Forbidden', 'wc-loyalty-rewards' ) ], 403 );
        }
        check_ajax_referer( 'wclr_analytics', 'nonce' );

        $from        = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
        $to          = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
        $granularity = isset( $_POST['granularity'] ) ? sanitize_key( wp_unslash( $_POST['granularity'] ) ) : 'day';

        [ $from, $to ] = $this->normalize_range( $from, $to );
        $granularity = in_array( $granularity, [ 'day', 'week', 'month' ], true ) ? $granularity : 'day';

        wp_send_json_success( $this->get_dashboard( $from, $to, $granularity ) );
    }

    /**
     * Aggregate every panel of the dashboard.
     *
     * @param string $from        Y-m-d (inclusive).
     * @param string $to          Y-m-d (inclusive).
     * @param string $granularity day|week|month.
     */
    public function get_dashboard( string $from, string $to, string $granularity ): array {
        $key    = $this->cache_key( 'dashboard', compact( 'from', 'to', 'granularity' ) );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached;
        }

        $data = [
            'range'        => [ 'from' => $from, 'to' => $to, 'granularity' => $granularity ],
            'kpis'         => $this->get_kpis( $from, $to ),
            'timeseries'   => $this->get_issued_vs_redeemed( $from, $to, $granularity ),
            'by_context'   => $this->get_earning_by_context( $from, $to ),
            'tiers'        => $this->get_tier_distribution(),
            'generated_at' => current_time( 'mysql' ),
        ];

        set_transient( $key, $data, self::CACHE_TTL );
        return $data;
    }

    /**
     * High-level KPI numbers for the cards.
     */
    public function get_kpis( string $from, string $to ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';

        $range_clause = $wpdb->prepare( 'created_at >= %s AND created_at < %s', $from . ' 00:00:00', $this->next_day( $to ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
        $issued = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$table}
             WHERE type = 'earn' AND amount > 0 AND {$range_clause}"
        );

        $redeemed = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(ABS(amount)),0) FROM {$table}
             WHERE type = 'spend' AND {$range_clause}"
        );

        $active_members = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$range_clause}"
        );

        $order_earn_rows = $wpdb->get_row(
            "SELECT COALESCE(SUM(amount),0) AS pts, COUNT(DISTINCT order_id) AS orders
             FROM {$table}
             WHERE type = 'earn' AND order_id IS NOT NULL AND {$range_clause}",
            ARRAY_A
        );
        // phpcs:enable

        $orders     = (int) ( $order_earn_rows['orders'] ?? 0 );
        $order_pts  = (int) ( $order_earn_rows['pts'] ?? 0 );
        $avg_per_order = $orders > 0 ? round( $order_pts / $orders, 1 ) : 0.0;

        // Outstanding liability (whole store, point-in-time).
        $total_balance = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(meta_value AS SIGNED)),0) FROM {$wpdb->usermeta} WHERE meta_key = %s",
                '_wclr_points_balance'
            )
        );

        $settings        = Settings_Cache::get();
        $points_per_unit = max( 1, (int) ( $settings['redemption']['points_per_unit'] ?? 100 ) );
        $unit_value      = (float) ( $settings['redemption']['unit_value'] ?? 1.0 );
        $liability_value = round( ( $total_balance / $points_per_unit ) * $unit_value, 2 );

        $redemption_rate = $issued > 0 ? round( ( $redeemed / $issued ) * 100, 1 ) : 0.0;

        return [
            'issued'              => $issued,
            'redeemed'            => $redeemed,
            'redemption_rate_pct' => $redemption_rate,
            'active_members'      => $active_members,
            'avg_points_per_order'=> $avg_per_order,
            'total_balance'       => $total_balance,
            'liability_value'     => $liability_value,
            'currency_symbol'     => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
        ];
    }

    /**
     * Points issued vs redeemed over time.
     */
    public function get_issued_vs_redeemed( string $from, string $to, string $granularity ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';

        $bucket_expr = $this->bucket_sql_expression( $granularity );

        $sql = $wpdb->prepare(
            "SELECT {$bucket_expr} AS bucket,
                    SUM(CASE WHEN type = 'earn' AND amount > 0 THEN amount ELSE 0 END) AS issued,
                    SUM(CASE WHEN type = 'spend' THEN ABS(amount) ELSE 0 END) AS redeemed
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
             GROUP BY bucket
             ORDER BY bucket ASC",
            $from . ' 00:00:00',
            $this->next_day( $to )
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        $labels   = [];
        $issued   = [];
        $redeemed = [];
        foreach ( (array) $rows as $row ) {
            $labels[]   = (string) $row['bucket'];
            $issued[]   = (int) $row['issued'];
            $redeemed[] = (int) $row['redeemed'];
        }

        return [
            'labels'   => $labels,
            'issued'   => $issued,
            'redeemed' => $redeemed,
        ];
    }

    /**
     * Earnings grouped by context (order, signup, referral, etc).
     */
    public function get_earning_by_context( string $from, string $to ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wclr_points_ledger';

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT context, SUM(amount) AS total
                 FROM {$table}
                 WHERE type = 'earn' AND amount > 0
                   AND created_at >= %s AND created_at < %s
                 GROUP BY context
                 ORDER BY total DESC",
                $from . ' 00:00:00',
                $this->next_day( $to )
            ),
            ARRAY_A
        );

        $labels = [];
        $values = [];
        foreach ( (array) $rows as $row ) {
            $labels[] = (string) $row['context'];
            $values[] = (int) $row['total'];
        }

        return [ 'labels' => $labels, 'values' => $values ];
    }

    /**
     * Users-per-tier distribution (live, all users).
     */
    public function get_tier_distribution(): array {
        global $wpdb;
        $tiers_table = $wpdb->prefix . 'wclr_tiers';

        $tiers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, name, min_lifetime_points, max_lifetime_points
             FROM {$tiers_table}
             WHERE enabled = 1
             ORDER BY min_lifetime_points ASC",
            ARRAY_A
        );

        $labels = [ __( 'No tier', 'wc-loyalty-rewards' ) ];
        $values = [ 0 ];
        $bucket_for = [];
        foreach ( (array) $tiers as $i => $tier ) {
            $labels[] = (string) $tier['name'];
            $values[] = 0;
            $bucket_for[ (int) $tier['id'] ] = $i + 1;
        }

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT CAST(meta_value AS SIGNED) AS lifetime FROM {$wpdb->usermeta} WHERE meta_key = %s",
                '_wclr_lifetime_points'
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $lifetime = (int) $row['lifetime'];
            $placed   = false;
            // Walk tiers from highest min ascending; pick the highest tier whose min <= lifetime.
            $matched_index = 0;
            foreach ( (array) $tiers as $i => $tier ) {
                $min = (int) $tier['min_lifetime_points'];
                $max = null !== $tier['max_lifetime_points'] ? (int) $tier['max_lifetime_points'] : null;
                if ( $lifetime >= $min && ( null === $max || $lifetime <= $max ) ) {
                    $matched_index = $i + 1;
                    $placed = true;
                }
            }
            if ( ! $placed ) {
                $values[0]++;
            } else {
                $values[ $matched_index ]++;
            }
        }

        return [ 'labels' => $labels, 'values' => $values ];
    }

    /**
     * Invalidate analytics cache by bumping its version namespace.
     */
    public function bust_cache(): void {
        update_option( self::CACHE_VERSION, (int) get_option( self::CACHE_VERSION, 0 ) + 1, false );
    }

    /**
     * Build a transient key that incorporates the cache version (cheap busting).
     */
    private function cache_key( string $name, array $args ): string {
        $version = (int) get_option( self::CACHE_VERSION, 0 );
        return self::CACHE_GROUP . '_' . $name . '_v' . $version . '_' . md5( wp_json_encode( $args ) );
    }

    /**
     * MySQL expression that buckets `created_at` according to granularity.
     */
    private function bucket_sql_expression( string $granularity ): string {
        switch ( $granularity ) {
            case 'month':
                return "DATE_FORMAT(created_at, '%Y-%m')";
            case 'week':
                // ISO week (Monday-based).
                return "DATE_FORMAT(created_at, '%x-W%v')";
            case 'day':
            default:
                return "DATE_FORMAT(created_at, '%Y-%m-%d')";
        }
    }

    /**
     * Clamp / default the requested date range.
     *
     * @return array{0:string,1:string}
     */
    private function normalize_range( string $from, string $to ): array {
        $today = current_time( 'Y-m-d' );
        if ( ! $this->is_valid_date( $from ) ) {
            $from = gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );
        }
        if ( ! $this->is_valid_date( $to ) ) {
            $to = $today;
        }
        if ( strtotime( $from ) > strtotime( $to ) ) {
            [ $from, $to ] = [ $to, $from ];
        }
        return [ $from, $to ];
    }

    private function is_valid_date( string $date ): bool {
        $d = \DateTime::createFromFormat( 'Y-m-d', $date );
        return $d && $d->format( 'Y-m-d' ) === $date;
    }

    private function next_day( string $date ): string {
        return gmdate( 'Y-m-d 00:00:00', strtotime( $date . ' +1 day' ) );
    }
}
