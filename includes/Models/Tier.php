<?php

namespace WCLR\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Tier model.
 */
class Tier {
    public int $id;
    public string $name;
    public int $min_lifetime_points;
    public ?int $max_lifetime_points;
    public float $multiplier;
    public int $sort_order;
    public bool $enabled;

    public function __construct( array $data ) {
        $this->id                  = (int) ( $data['id'] ?? 0 );
        $this->name                = (string) ( $data['name'] ?? '' );
        $this->min_lifetime_points = (int) ( $data['min_lifetime_points'] ?? 0 );
        $this->max_lifetime_points = isset( $data['max_lifetime_points'] ) ? (int) $data['max_lifetime_points'] : null;
        $this->multiplier          = (float) ( $data['multiplier'] ?? 1 );
        $this->sort_order          = (int) ( $data['sort_order'] ?? 0 );
        $this->enabled             = (bool) ( $data['enabled'] ?? true );
    }
}

