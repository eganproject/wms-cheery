<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu periode lama PO: berlaku mulai starts_at sampai periode berikutnya
 * (untuk produk yang sama) dimulai.
 *
 * Dipasang pada produk induk, sehingga semua variannya ikut. Barang tanpa
 * induk (termasuk paket bundling) memakai periode miliknya sendiri.
 * Lama 0 hari berarti barang siap stok: pesanannya memakai aturan Normal.
 */
class ProductPoPeriod extends Model
{
    protected $fillable = [
        'product_group_id',
        'product_id',
        'starts_at',
        'days',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'days' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kunci pemilik: "g12" untuk induk, "p34" untuk barang tanpa induk. */
    public function ownerKey(): string
    {
        return $this->product_group_id ? 'g'.$this->product_group_id : 'p'.$this->product_id;
    }
}
