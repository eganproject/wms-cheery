<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Cutting: kain yang keluar untuk satu barang (induk) oleh satu pemotong.
 * Hasil potongnya diserahkan bertahap ke penjahit sebagai batch WIP Jahit.
 */
class FabricCutting extends Model
{
    use GeneratesDocumentCode;

    public const STATUS_CUTTING = 'cutting';

    public const STATUS_DONE = 'done';

    public const STATUSES = [
        self::STATUS_CUTTING => 'Cutting',
        self::STATUS_DONE => 'Selesai Cutting',
    ];

    protected $fillable = [
        'code', 'product_id', 'product_group_id', 'employee_id', 'status', 'finished_at', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    protected static function codePrefix(): string
    {
        return 'CT-'.now()->format('Ym').'-';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FabricMovement::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(SewingBatch::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CUTTING);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_CUTTING;
    }

    /** Nama barang induk (atau barang itu sendiri bila tanpa varian). */
    public function productName(): string
    {
        return $this->group?->name ?? $this->product?->name ?? '—';
    }

    /**
     * Varian yang bisa diserahkan ke penjahit: semua varian induknya, atau
     * barang itu sendiri bila tanpa varian.
     *
     * @return Collection<int, Product>
     */
    public function variants(): Collection
    {
        if (! $this->product_group_id) {
            return collect([$this->product]);
        }

        return Product::query()
            ->where('product_group_id', $this->product_group_id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->product_id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Kain bersih yang dipakai cutting ini per jenis kain: keluar dikurangi
     * batal keluar dan kain kembali. Kunci = fabric_id.
     *
     * @return Collection<int, float>
     */
    public function fabricUsed(): Collection
    {
        return $this->movements
            ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])
            ->groupBy('fabric_id')
            ->map(fn ($rows) => round(-$rows->sum('quantity'), 2));
    }

    /** Pcs yang sudah diserahkan ke penjahit dari cutting ini. */
    public function handedQuantity(): int
    {
        return (int) $this->batches->sum(fn (SewingBatch $batch) => $batch->items->sum('quantity'));
    }
}
