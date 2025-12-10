<?php

namespace WCLR\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for user points balance.
 */
class Points_Balance {
    public int $user_id;
    public int $balance;
    public int $lifetime_points;

    public function __construct( int $user_id, int $balance, int $lifetime_points ) {
        $this->user_id         = $user_id;
        $this->balance         = $balance;
        $this->lifetime_points = $lifetime_points;
    }
}

