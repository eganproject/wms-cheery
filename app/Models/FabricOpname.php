<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock opname kain: daftar roll (per jenis kain dan/atau lokasi) dengan sisa
 * tercatat saat dibuat, diisi hasil hitung fisik, lalu diterapkan sekaligus
 * sebagai koreksi roll.
 */
class FabricOpname extends Model
{
    use GeneratesDocumentCode;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPLIED = 'applied';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Sedang dihitung',
        self::STATUS_APPLIED => 'Diterapkan',
    ];

    /** Roll yang tidak dihitung saat diterapkan. */
    public const MISSING_RULES = [
        'skip' => 'Biarkan (tidak dikoreksi)',
        'empty' => 'Anggap habis / hilang (jadi 0)',
    ];

    protected $fillable = [
        'code', 'date', 'fabric_id', 'location', 'status', 'missing_rule', 'note', 'user_id', 'applied_at', 'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    protected static function codePrefix(): string
    {
        return 'OPK-'.now()->format('Ym').'-';
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FabricOpnameLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeLabel(): string
    {
        return collect([$this->fabric?->sku ? $this->fabric->sku.' — '.$this->fabric->displayName() : 'Semua kain', $this->location ? 'lokasi '.$this->location : null])
            ->filter()->implode(' · ');
    }
}
