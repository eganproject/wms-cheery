<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Batch WIP Jahit: hasil cutting yang diserahkan ke satu penjahit, per varian.
 * Berkurang oleh penerimaan barang (yang sudah disetujui) yang merujuk batch
 * ini; selesai bila semua sudah diterima atau ditutup dengan alasan.
 */
class SewingBatch extends Model
{
    use GeneratesDocumentCode;

    protected $fillable = [
        'code', 'fabric_cutting_id', 'supplier_id', 'date', 'note', 'closed_at', 'close_reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected static function codePrefix(): string
    {
        return 'JHT-'.now()->format('Ym').'-';
    }

    public function cutting(): BelongsTo
    {
        return $this->belongsTo(FabricCutting::class, 'fabric_cutting_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SewingBatchItem::class);
    }

    public function inbounds(): HasMany
    {
        return $this->hasMany(Inbound::class);
    }

    /**
     * Pcs diterima per barang dari penerimaan yang sudah disetujui.
     *
     * @return Collection<int, int>
     */
    public function receivedByProduct(): Collection
    {
        return self::receivedFor([$this->id])->get($this->id, collect());
    }

    /**
     * Pcs diterima per batch per barang, untuk banyak batch sekaligus.
     *
     * @param  iterable<int>  $batchIds
     * @return Collection<int, Collection<int, int>>
     */
    public static function receivedFor(iterable $batchIds): Collection
    {
        return InboundItem::query()
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->whereIn('inbounds.sewing_batch_id', collect($batchIds)->all())
            ->where('inbounds.status', Inbound::STATUS_POSTED)
            ->selectRaw('inbounds.sewing_batch_id AS batch_id, inbound_items.product_id, SUM(inbound_items.quantity) AS received')
            ->groupBy('inbounds.sewing_batch_id', 'inbound_items.product_id')
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(int) $row->product_id => (int) $row->received]));
    }

    /**
     * Sisa pcs per barang yang masih di penjahit (tidak pernah negatif).
     *
     * @param  Collection<int, int>|null  $received
     * @return Collection<int, int>
     */
    public function outstanding(?Collection $received = null): Collection
    {
        if ($this->closed_at) {
            return collect();
        }

        $received ??= $this->receivedByProduct();

        return $this->items
            ->mapWithKeys(fn (SewingBatchItem $item) => [$item->product_id => max(0, $item->quantity - (int) ($received[$item->product_id] ?? 0))])
            ->filter(fn (int $left) => $left > 0);
    }

    /** Batch yang belum ditutup manual (bisa saja sudah diterima lengkap). */
    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }
}
