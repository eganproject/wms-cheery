<?php

namespace App\Support;

use App\Models\FabricCutting;
use App\Models\FabricMovement;
use App\Models\Inbound;
use App\Models\SewingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Riwayat satu surat jalan dari awal datanya ada di sistem: kain masuk
 * gudang → diambil pemotong (surat jalan kain) → hasil potong dicatat →
 * dikirim ke penjahit (surat jalan jahit) → barang jadi diterima.
 *
 * Disusun dari data yang sudah ada; tidak ada tabel baru.
 */
class DeliveryTimeline
{
    /** @var list<array{at: Carbon, order: int, title: string, detail: ?string, tone: string}> */
    protected array $events = [];

    protected int $order = 0;

    protected ?Carbon $last = null;

    /** @return Collection<int, array{at: Carbon, date_only: bool, title: string, detail: ?string, tone: string}> */
    public static function forCutting(FabricCutting $cutting): Collection
    {
        $timeline = new self;
        $timeline->fabric($cutting);

        foreach ($cutting->batches as $batch) {
            $timeline->batch($batch);
        }

        if ($cutting->finished_at) {
            $timeline->add($cutting->finished_at, 'Cutting selesai', null, 'success');
        }

        return $timeline->sorted();
    }

    /** @return Collection<int, array{at: Carbon, date_only: bool, title: string, detail: ?string, tone: string}> */
    public static function forBatch(SewingBatch $batch): Collection
    {
        $timeline = new self;

        if ($batch->cutting) {
            $timeline->fabric($batch->cutting);
        }

        $timeline->batch($batch);

        return $timeline->sorted();
    }

    /** Kain masuk, diambil, dibatalkan, dan dikembalikan untuk satu cutting. Butuh movements.roll.receipt.supplier & movements.user. */
    protected function fabric(FabricCutting $cutting): void
    {
        $movements = $cutting->movements;

        $receipts = $movements->where('type', FabricMovement::TYPE_OUT)
            ->map(fn (FabricMovement $movement) => $movement->roll?->receipt)
            ->filter()
            ->unique('id');

        foreach ($receipts as $receipt) {
            $codes = $movements->where('type', FabricMovement::TYPE_OUT)
                ->filter(fn (FabricMovement $movement) => $movement->roll?->fabric_receipt_id === $receipt->id)
                ->map(fn (FabricMovement $movement) => $movement->roll->code)
                ->unique();

            $this->add($receipt->date, 'Kain masuk gudang', $receipt->code.($receipt->supplier ? ' dari '.$receipt->supplier->name : '').' · roll '.$codes->implode(', '), 'muted', dateOnly: true);
        }

        $this->add($cutting->created_at, 'Cutting dimulai '.$cutting->code, 'Pemotong '.($cutting->employee?->name ?? '—').' · '.$cutting->productName(), 'info');

        foreach ($movements as $movement) {
            $unit = $movement->fabric?->unitShort() ?? '';
            $amount = rtrim(rtrim(number_format(abs((float) $movement->quantity), 2, ',', '.'), '0'), ',').' '.$unit;
            $by = $movement->user?->name ? ' · oleh '.$movement->user->name : '';
            $roll = $movement->roll?->code ?? '—';

            match ($movement->type) {
                FabricMovement::TYPE_OUT => $this->add($movement->moved_at, 'Kain diambil pemotong (surat jalan kain)', "Roll {$roll} · {$amount}{$by}", 'info'),
                FabricMovement::TYPE_CANCEL => $this->add($movement->moved_at, 'Pengambilan kain dibatalkan', "Roll {$roll} · {$amount}{$by}", 'muted'),
                FabricMovement::TYPE_RETURN => $this->add($movement->moved_at, 'Sisa kain dikembalikan ke gudang', "Roll {$roll} · {$amount}{$by}", 'muted'),
                default => null,
            };
        }
    }

    /** Hasil potong, pengiriman ke penjahit, penerimaan, dan penutupan satu batch. Butuh supplier, items, inbounds (withSum items quantity). */
    protected function batch(SewingBatch $batch): void
    {
        $pcs = number_format((int) $batch->items->sum('quantity'), 0, ',', '.').' pcs';
        $sentSameDay = $batch->supplier_id && $batch->sent_at && $batch->sent_at->isSameDay($batch->date);

        if ($sentSameDay) {
            $this->add($batch->date, "Hasil potong {$batch->code} dikirim ke penjahit (surat jalan jahit)", "{$pcs} ke {$batch->supplier?->name}", 'info', dateOnly: true);
        } else {
            $this->add($batch->date, "Hasil potong {$batch->code} dicatat", $pcs.($batch->supplier_id && ! $batch->sent_at ? ' di '.$batch->supplier?->name : ' disimpan di gudang'), $batch->supplier_id ? 'info' : 'warning', dateOnly: true);

            if ($batch->supplier_id && $batch->sent_at) {
                $this->add($batch->sent_at, "{$batch->code} dikirim ke penjahit (surat jalan jahit)", "{$pcs} ke {$batch->supplier?->name}", 'info', dateOnly: true);
            }
        }

        foreach ($batch->inbounds as $inbound) {
            $got = number_format((int) ($inbound->items_sum_quantity ?? 0), 0, ',', '.').' pcs';

            if ($inbound->status === Inbound::STATUS_POSTED) {
                $this->add($inbound->posted_at ?? $inbound->date, 'Barang jadi diterima', "{$inbound->code} · {$got}", 'success', dateOnly: ! $inbound->posted_at);
            } else {
                $this->add($inbound->date, 'Penerimaan barang jadi menunggu persetujuan', "{$inbound->code} · {$got}", 'warning', dateOnly: true);
            }
        }

        if ($batch->closed_at) {
            $this->add($batch->closed_at, "{$batch->code} ditutup", $batch->close_reason, 'muted');
        }
    }

    protected function add(?Carbon $at, string $title, ?string $detail, string $tone, bool $dateOnly = false): void
    {
        if (! $at) {
            return;
        }

        // Tanggal tanpa jam: di awal hari itu, tetapi tidak sebelum kejadian
        // sebelumnya pada hari yang sama (mis. hasil potong setelah kain diambil).
        if ($dateOnly) {
            $at = $at->copy()->startOfDay();
            if ($this->last && $this->last->isSameDay($at) && $this->last->gt($at)) {
                $at = $this->last->copy();
            }
        }
        $this->last = $at;

        $this->events[] = [
            'at' => $at,
            'date_only' => $dateOnly,
            'order' => $this->order++,
            'title' => $title,
            'detail' => $detail,
            'tone' => $tone,
        ];
    }

    protected function sorted(): Collection
    {
        return collect($this->events)
            ->sortBy([fn ($a, $b) => $a['at'] <=> $b['at'], fn ($a, $b) => $a['order'] <=> $b['order']])
            ->values();
    }
}
