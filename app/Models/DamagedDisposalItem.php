<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamagedDisposalItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'damaged_disposal_id',
        'product_id',
        'quantity',
        'replaced_quantity',
        'rejected_quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'replaced_quantity' => 'integer',
            'rejected_quantity' => 'integer',
        ];
    }

    /** Unit yang dikirim ke pemasok tetapi belum diganti maupun ditolak. */
    public function outstandingQuantity(): int
    {
        return max(0, $this->quantity - $this->replaced_quantity - $this->rejected_quantity);
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(DamagedDisposal::class, 'damaged_disposal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
