<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen kain masuk: kain diterima dari pemasok, dicatat per roll.
 */
class FabricReceipt extends Model
{
    use GeneratesDocumentCode;

    protected $fillable = [
        'code',
        'date',
        'supplier_id',
        'reference',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(FabricRoll::class);
    }

    /** KM-202611-0001 */
    protected static function codePrefix(): string
    {
        return 'KM-'.now()->format('Ym').'-';
    }
}
