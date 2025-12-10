<?php

namespace WCLR\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Referral relation model.
 */
class Referral {
    public int $id;
    public int $referrer_id;
    public ?int $referred_user_id;
    public string $referral_code;
    public ?int $first_order_id;
    public string $status;
    public string $created_at;

    public function __construct( array $data ) {
        $this->id               = (int) ( $data['id'] ?? 0 );
        $this->referrer_id      = (int) ( $data['referrer_id'] ?? 0 );
        $this->referred_user_id = isset( $data['referred_user_id'] ) ? (int) $data['referred_user_id'] : null;
        $this->referral_code    = (string) ( $data['referral_code'] ?? '' );
        $this->first_order_id   = isset( $data['first_order_id'] ) ? (int) $data['first_order_id'] : null;
        $this->status           = (string) ( $data['status'] ?? 'pending' );
        $this->created_at       = (string) ( $data['created_at'] ?? '' );
    }
}

