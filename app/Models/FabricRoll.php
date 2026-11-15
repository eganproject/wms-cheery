<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu roll (gulungan) kain dengan label QR sendiri. Isi QR = kode roll
 * (SKU kain + nomor roll), jadi sekali scan langsung tahu kain dan sisanya.
 */
class FabricRoll extends Model
{
    protected $fillable = [
        'fabric_id',
        'fabric_receipt_id',
        'code',
        'number',
        'initial_quantity',
        'quantity',
        'lot',
        'location',
        'emptied_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'initial_quantity' => 'float',
            'quantity' => 'float',
            'emptied_at' => 'datetime',
        ];
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FabricReceipt::class, 'fabric_receipt_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FabricMovement::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function isEmpty(): bool
    {
        return $this->quantity <= 0;
    }

    /** Sudah ada kain yang keluar atau dikoreksi dari roll ini. */
    public function hasBeenUsed(): bool
    {
        return $this->movements()->where('type', '!=', FabricMovement::TYPE_IN)->exists();
    }

    /**
     * Cari roll dari hasil scan. QR berisi kode roll; spasi dan huruf
     * besar/kecil diabaikan.
     */
    public static function findByScan(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return self::query()->with('fabric')->where('code', $code)->first()
            ?? self::query()->with('fabric')->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();
    }
}
