<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali penerimaan barang pengganti dari pemasok atas dokumen retur.
 */
class SupplierReplacement extends Model
{
    protected $fillable = ['damaged_disposal_id', 'date', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(DamagedDisposal::class, 'damaged_disposal_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReplacementItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
