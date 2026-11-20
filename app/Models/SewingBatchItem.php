<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SewingBatchItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['sewing_batch_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SewingBatch::class, 'sewing_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
