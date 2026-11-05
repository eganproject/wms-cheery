<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentOrderItem extends Model
{
    protected $fillable = [
        'shipment_order_id',
        'sku',
        'product_name',
        'quantity',
        'amount',
        'product_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShipmentOrder::class, 'shipment_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isMatched(): bool
    {
        return $this->product_id !== null;
    }
}
