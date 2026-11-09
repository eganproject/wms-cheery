<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReplacementItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['supplier_replacement_id', 'damaged_disposal_item_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function disposalItem(): BelongsTo
    {
        return $this->belongsTo(DamagedDisposalItem::class, 'damaged_disposal_item_id');
    }
}
