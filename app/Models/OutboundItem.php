<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outbound_id',
        'product_id',
        'quantity',
        'scanned_quantity',
        'note',
        'substituted_from_id',
        'substitution_reason',
        'substitution_note',
        'substituted_by',
        'substituted_at',
    ];

    /** Alasan substitusi SKU yang bisa dipilih operator QC Kemas. */
    public const SUBSTITUTION_REASONS = [
        'stok_habis' => 'Stok habis',
        'rusak' => 'Barang rusak/cacat',
        'permintaan_pembeli' => 'Permintaan pembeli',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'scanned_quantity' => 'integer',
            'substituted_at' => 'datetime',
        ];
    }

    public function outbound(): BelongsTo
    {
        return $this->belongsTo(Outbound::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** SKU asli pesanan yang digantikan baris ini. Kosong untuk baris biasa. */
    public function substitutedFrom(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substituted_from_id');
    }

    public function substitutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substituted_by');
    }

    public function isSubstitute(): bool
    {
        return $this->substituted_from_id !== null;
    }

    public function substitutionReasonLabel(): string
    {
        $label = self::SUBSTITUTION_REASONS[$this->substitution_reason] ?? (string) $this->substitution_reason;

        return filled($this->substitution_note) ? "{$label} · {$this->substitution_note}" : $label;
    }

    public function isFullyScanned(): bool
    {
        return $this->scanned_quantity >= $this->quantity;
    }

    public function remainingToScan(): int
    {
        return max(0, $this->quantity - $this->scanned_quantity);
    }
}
