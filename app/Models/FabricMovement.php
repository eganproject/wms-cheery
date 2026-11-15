<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu mutasi kain pada satu roll, lengkap dengan sisa roll sesudahnya.
 */
class FabricMovement extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_ADJUST = 'adjust';

    public const TYPE_CANCEL = 'cancel';

    public const TYPES = [
        self::TYPE_IN => 'Kain masuk',
        self::TYPE_OUT => 'Kain keluar',
        self::TYPE_ADJUST => 'Koreksi',
        self::TYPE_CANCEL => 'Batal keluar',
    ];

    protected $fillable = [
        'fabric_id',
        'fabric_roll_id',
        'fabric_receipt_id',
        'type',
        'quantity',
        'balance_after',
        'destination',
        'note',
        'reverses_id',
        'cancelled_at',
        'user_id',
        'moved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'balance_after' => 'float',
            'cancelled_at' => 'datetime',
            'moved_at' => 'datetime',
        ];
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function roll(): BelongsTo
    {
        return $this->belongsTo(FabricRoll::class, 'fabric_roll_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FabricReceipt::class, 'fabric_receipt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function scopeIssues(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OUT);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
