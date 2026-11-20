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
     * Varian yang bisa diserahkan ke penjahit. Bila ada BOM, hanya varian
     * induk yang memakai kain dari cutting ini (plus barang yang di-scan dan
     * yang sudah pernah diserahkan); tanpa BOM sama sekali, semua varian
     * induknya. Barang tanpa varian: barang itu sendiri.
     *
     * @return Collection<int, Product>
     */
    public function variants(): Collection
    {
        if (! $this->product_group_id) {
            return collect([$this->product]);
        }

        $variants = Product::query()
            ->where('product_group_id', $this->product_group_id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->product_id))
            ->orderBy('id')
            ->get();

        $withBom = FabricUsage::query()
            ->whereIn('product_id', $variants->pluck('id'))
            ->pluck('fabric_id', 'product_id');

        if ($withBom->isEmpty()) {
            return $variants;
        }

        $fabricIds = $this->movements()->distinct()->pluck('fabric_id');
        $keep = FabricUsage::query()
            ->whereIn('product_id', $variants->pluck('id'))
            ->whereIn('fabric_id', $fabricIds)
            ->pluck('product_id')
            ->merge($this->movements()->whereNotNull('product_id')->distinct()->pluck('product_id'))
            ->merge(SewingBatchItem::query()
                ->whereIn('sewing_batch_id', $this->batches()->select('id'))
                ->distinct()->pluck('product_id'))
            ->push($this->product_id)
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $variants->filter(fn ($variant) => $keep->contains($variant->id))->values();
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
