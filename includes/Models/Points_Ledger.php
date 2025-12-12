<?php

namespace WCLR\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a ledger entry.
 */
class Points_Ledger {
    public int $id;
    public int $user_id;
    public string $type;
    public int $amount;
    public int $balance_after;
    public string $context;
    public ?int $order_id;
    public ?int $admin_id;
    public array $meta;
    public string $created_at;

    public function __construct( array $data ) {
        $this->id            = (int) ( $data['id'] ?? 0 );
        $this->user_id       = (int) ( $data['user_id'] ?? 0 );
        $this->type          = (string) ( $data['type'] ?? '' );
        $this->amount        = (int) ( $data['amount'] ?? 0 );
        $this->balance_after = (int) ( $data['balance_after'] ?? 0 );
        $this->context       = (string) ( $data['context'] ?? '' );
        $this->order_id      = isset( $data['order_id'] ) ? (int) $data['order_id'] : null;
        $this->admin_id      = isset( $data['admin_id'] ) ? (int) $data['admin_id'] : null;
        if ( isset( $data['meta'] ) && ! empty( $data['meta'] ) ) {
            $decoded = json_decode( (string) $data['meta'], true );
            $this->meta = is_array( $decoded ) ? $decoded : [];
        } else {
            $this->meta = [];
        }
        $this->created_at    = (string) ( $data['created_at'] ?? '' );
    }
}

