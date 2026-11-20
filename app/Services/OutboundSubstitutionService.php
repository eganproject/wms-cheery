<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\OutboundItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Substitusi SKU saat QC Kemas.
 *
 * Operator mengganti sebagian atau seluruh unit satu baris pesanan dengan
 * barang lain (mis. warna lain karena stoknya habis). Unit yang diganti
 * dipindah dari baris asli ke baris pengganti yang langsung terhitung sudah
 * discan — barangnya memang sedang dipegang operator. Stok yang berkurang
 * saat serah terima adalah stok barang pengganti, karena baris pengganti
 * hanyalah baris barang biasa dengan keterangan SKU asalnya.
 *
 * Aturan yang disepakati (Ega, 2026-11-15): barang apa pun boleh menjadi
 * pengganti, asal pengguna punya hak akses outbounds.substitute dan memilih
 * alasan. Paket (bundling) tidak bisa, karena tidak punya wujud fisik.
 */
class OutboundSubstitutionService
{
    /**
     * Baris pesanan yang bisa digantikan oleh barang ini: baris asli yang
     * masih punya sisa unit belum discan dan barangnya berbeda.
     *
     * @return Collection<int, OutboundItem>
     */
    public function candidateLines(Outbound $outbound, Product $substitute): Collection
    {
        if (! $this->canBeSubstitute($substitute)) {
            return collect();
        }

        $outbound->loadMissing('items.product');

        return $outbound->items
            ->filter(fn (OutboundItem $item) => ! $item->isSubstitute()
                && $item->product_id !== $substitute->id
                && $item->remainingToScan() > 0)
            ->values();
    }

    public function canBeSubstitute(Product $product): bool
    {
        return $product->is_active && ! $product->isBundle();
    }

    /**
     * Ganti $quantity unit dari baris $line dengan barang $substitute.
     */
    public function substitute(
        Outbound $outbound,
        OutboundItem $line,
        Product $substitute,
        int $quantity,
        string $reason,
        ?string $note,
        ?int $userId,
    ): OutboundItem {
        if ($line->outbound_id !== $outbound->id || $line->isSubstitute()) {
            throw ValidationException::withMessages([
                'code' => 'Baris yang dipilih bukan baris pesanan pada paket ini.',
            ]);
        }

        if (! $this->canBeSubstitute($substitute)) {
            throw ValidationException::withMessages([
                'code' => $substitute->isBundle()
                    ? "{$substitute->sku} adalah paket · pilih barang isinya sebagai pengganti"
                    : "{$substitute->sku} nonaktif · tidak bisa jadi pengganti",
            ]);
        }

        if ($substitute->id === $line->product_id) {
            throw ValidationException::withMessages([
                'code' => 'Barang pengganti sama dengan barang pesanan.',
            ]);
        }

        $remaining = $line->remainingToScan();

        if ($quantity < 1 || $quantity > $remaining) {
            throw ValidationException::withMessages([
                'code' => "{$line->product->sku} sisa {$remaining} {$line->product->unit} · tidak bisa diganti {$quantity}",
            ]);
        }

        // Stok pengganti harus cukup untuk semua baris barang ini di paket,
        // termasuk yang akan ditambahkan sekarang.
        $needed = (int) $outbound->items()->where('product_id', $substitute->id)->sum('quantity') + $quantity;

        if ($substitute->stock < $needed) {
            throw ValidationException::withMessages([
                'code' => $substitute->stock < 1
                    ? "Stok {$substitute->sku} kosong · tidak bisa jadi pengganti"
                    : "Stok {$substitute->sku} kurang · butuh {$needed}, ada {$substitute->stock} {$substitute->unit}",
            ]);
        }

        return DB::transaction(function () use ($outbound, $line, $substitute, $quantity, $reason, $note, $userId) {
            // Bersyarat di database, sama seperti scan barang: dua permintaan
            // yang hampir bersamaan tidak boleh sama-sama mengambil sisa unit.
            $taken = OutboundItem::whereKey($line->getKey())
                ->whereRaw('quantity - scanned_quantity >= ?', [$quantity])
                ->decrement('quantity', $quantity);

            if ($taken === 0) {
                throw ValidationException::withMessages([
                    'code' => "{$line->product->sku} sudah berubah · scan ulang barang penggantinya",
                ]);
            }

            OutboundItem::whereKey($line->getKey())->where('quantity', 0)->delete();

            $existing = $outbound->items()
                ->where('product_id', $substitute->id)
                ->where('substituted_from_id', $line->product_id)
                ->first();

            $attributes = [
                'substitution_reason' => $reason,
                'substitution_note' => filled($note) ? trim($note) : null,
                'substituted_by' => $userId,
                'substituted_at' => now(),
            ];

            if ($existing) {
                $existing->forceFill($attributes + [
                    'quantity' => $existing->quantity + $quantity,
                    'scanned_quantity' => $existing->scanned_quantity + $quantity,
                ])->save();
                $result = $existing;
            } else {
                $result = $outbound->items()->create($attributes + [
                    'product_id' => $substitute->id,
                    'substituted_from_id' => $line->product_id,
                    'quantity' => $quantity,
                    'scanned_quantity' => $quantity,
                    'note' => $line->note,
                ]);
            }

            $outbound->load('items.product', 'items.substitutedFrom');

            return $result;
        });
    }

    /**
     * Batalkan satu baris pengganti: unitnya kembali ke barang pesanan asli
     * dan harus discan lagi.
     */
    public function revert(Outbound $outbound, OutboundItem $substituteLine): void
    {
        if ($substituteLine->outbound_id !== $outbound->id || ! $substituteLine->isSubstitute()) {
            throw ValidationException::withMessages([
                'code' => 'Baris ini bukan barang pengganti.',
            ]);
        }

        DB::transaction(function () use ($outbound, $substituteLine) {
            $this->restoreOriginal($outbound, $substituteLine);
            $substituteLine->delete();
        });

        $outbound->load('items.product', 'items.substitutedFrom');
    }

    /**
     * Batalkan semua substitusi pada paket. Dipakai saat progres scan direset.
     */
    public function revertAll(Outbound $outbound): void
    {
        DB::transaction(function () use ($outbound) {
            $lines = $outbound->items()->whereNotNull('substituted_from_id')->get();

            foreach ($lines as $line) {
                $this->restoreOriginal($outbound, $line);
                $line->delete();
            }
        });

        $outbound->unsetRelation('items');
    }

    protected function restoreOriginal(Outbound $outbound, OutboundItem $substituteLine): void
    {
        $original = $outbound->items()
            ->where('product_id', $substituteLine->substituted_from_id)
            ->whereNull('substituted_from_id')
            ->first();

        if ($original) {
            $original->increment('quantity', $substituteLine->quantity);

            return;
        }

        $outbound->items()->create([
            'product_id' => $substituteLine->substituted_from_id,
            'quantity' => $substituteLine->quantity,
            'scanned_quantity' => 0,
            'note' => $substituteLine->note,
        ]);
    }

    /**
     * Terapkan substitusi yang sudah ada ke baris pesanan yang dibentuk ulang
     * dari data import (scan ulang resi yang setengah jalan).
     *
     * Unit pengganti dikurangkan dari baris aslinya. Bila pesanannya berubah
     * dan unit aslinya kini lebih sedikit, substitusinya ikut dipotong.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, OutboundItem>  $substitutes
     * @return array<int, array<string, mixed>>
     */
    public function reapply(array $lines, Collection $substitutes): array
    {
        $kept = [];

        foreach ($substitutes as $substitute) {
            $take = $substitute->quantity;

            foreach ($lines as $index => $line) {
                if ($take < 1) {
                    break;
                }

                if ((int) $line['product_id'] !== $substitute->substituted_from_id) {
                    continue;
                }

                $used = min($take, (int) $line['quantity']);
                $lines[$index]['quantity'] = (int) $line['quantity'] - $used;
                $take -= $used;
            }

            $moved = $substitute->quantity - $take;

            if ($moved > 0) {
                $kept[] = [
                    'product_id' => $substitute->product_id,
                    'substituted_from_id' => $substitute->substituted_from_id,
                    'quantity' => $moved,
                    'scanned_quantity' => min($moved, $substitute->scanned_quantity),
                    'note' => $substitute->note,
                    'substitution_reason' => $substitute->substitution_reason,
                    'substitution_note' => $substitute->substitution_note,
                    'substituted_by' => $substitute->substituted_by,
                    'substituted_at' => $substitute->substituted_at,
                ];
            }
        }

        $lines = array_values(array_filter($lines, fn (array $line) => (int) $line['quantity'] > 0));

        return [$lines, $kept];
    }

    /**
     * Baris retur mengikuti barang yang benar-benar dikirim: unit yang
     * diganti saat QC Kemas diharapkan kembali sebagai barang penggantinya.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function applyToReturnLines(array $lines, ?Outbound $outbound): array
    {
        if (! $outbound) {
            return $lines;
        }

        $substitutes = $outbound->items()->whereNotNull('substituted_from_id')->get();

        if ($substitutes->isEmpty()) {
            return $lines;
        }

        foreach ($substitutes as $substitute) {
            $take = $substitute->quantity;

            foreach ($lines as $index => $line) {
                if ($take < 1) {
                    break;
                }

                if ((int) $line['product_id'] !== $substitute->substituted_from_id) {
                    continue;
                }

                $used = min($take, (int) $line['quantity']);
                $lines[$index]['quantity'] = (int) $line['quantity'] - $used;
                $lines[$index]['good_quantity'] = $lines[$index]['quantity'];
                $take -= $used;
            }

            $moved = $substitute->quantity - $take;

            if ($moved < 1) {
                continue;
            }

            $merged = false;

            foreach ($lines as $index => $line) {
                if ((int) $line['product_id'] === $substitute->product_id) {
                    $lines[$index]['quantity'] = (int) $line['quantity'] + $moved;
                    $lines[$index]['good_quantity'] = $lines[$index]['quantity'];
                    $merged = true;
                    break;
                }
            }

            if (! $merged) {
                $lines[] = [
                    'product_id' => $substitute->product_id,
                    'quantity' => $moved,
                    'good_quantity' => $moved,
                    'damaged_quantity' => 0,
                    'note' => $substitute->note,
                ];
            }
        }

        return array_values(array_filter($lines, fn (array $line) => (int) $line['quantity'] > 0));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function reasons(): array
    {
        return collect(OutboundItem::SUBSTITUTION_REASONS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
