<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemakaian kain per barang: berapa banyak kain (dalam satuan kainnya)
 * untuk membuat 1 unit barang. Dipakai menghitung kebutuhan kain.
 */
class FabricUsage extends Model
{
    protected $fillable = [
        'product_id',
        'fabric_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }
}
