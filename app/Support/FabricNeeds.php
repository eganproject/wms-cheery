<?php

namespace App\Support;

use App\Models\Fabric;
use App\Models\FabricCutting;
use App\Models\FabricUsage;
use App\Models\SewingBatch;
use Illuminate\Support\Collection;

/**
 * Kebutuhan kain dari Rencana Produksi.
 *
 * Per barang (varian): yang masih harus diproduksi = harus diproduksi
 * (pesanan yang tidak tertutup stok barang jadi) − pcs yang masih WIP di
 * penjahit. Kebutuhan kain = sisa itu × pemakaian kain per unit (BOM),
 * dijumlahkan per jenis kain, lalu dikurangi kain yang masih di Cutting
 * (sudah keluar tetapi belum diserahkan ke penjahit). Hasilnya dibandingkan
 * dengan sisa semua roll di gudang.
 *
 * Begitu barang jadi diterima, stok barang naik sehingga "harus diproduksi"
 * dan WIP-nya turun bersamaan; tidak ada yang terhitung dua kali.
 */
class FabricNeeds
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  per jenis kain
     * @param  Collection<int, array<string, mixed>>  $missing  barang tanpa pemakaian kain
     * @param  array<string, int|float>  $totals
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly Collection $missing,
        public readonly array $totals,
    ) {
    }

    public static function from(ProductionPlan $plan): self
    {
        $short = $plan->rows
            ->filter(fn (array $row) => $row['short'] > 0 && $row['product_id'] !== null)
            ->keyBy('product_id');

        $usages = FabricUsage::query()
            ->whereIn('product_id', $short->keys())
            ->get()
            ->groupBy('fabric_id');

        $fabricIds = $usages->keys();

        $fabrics = Fabric::query()->withStock()->whereIn('id', $fabricIds)->get()->keyBy('id');

        $wip = self::wipByProduct();
        $inCutting = self::fabricInCutting();

        $rows = $usages
            ->map(function (Collection $lines, int $fabricId) use ($short, $fabrics, $wip, $inCutting) {
                $fabric = $fabrics[$fabricId];
                $products = $lines
                    ->map(function (FabricUsage $usage) use ($short, $wip) {
                        $row = $short[$usage->product_id];
                        $inWip = min((int) $row['short'], (int) ($wip[$usage->product_id] ?? 0));
                        $net = max(0, (int) $row['short'] - $inWip);

                        return [
                            'product_id' => $usage->product_id,
                            'sku' => $row['sku'],
                            'name' => $row['name'],
                            'short' => (int) $row['short'],
                            'wip' => $inWip,
                            'net' => $net,
                            'usage' => $usage->quantity,
                            'need' => round($net * $usage->quantity, 2),
                        ];
                    })
                    ->sortByDesc('need')
                    ->values();

                $need = round($products->sum('need'), 2);
                $cutting = round(min($need, (float) ($inCutting[$fabricId] ?? 0)), 2);
                $remaining = round(max(0, $need - $cutting), 2);
                $stock = round((float) $fabric->stock, 2);

                return [
                    'fabric' => $fabric,
                    'products' => $products,
                    'units' => (int) $products->sum('net'),
                    'wip' => (int) $products->sum('wip'),
                    'need' => $need,
                    'in_cutting' => $cutting,
                    'remaining' => $remaining,
                    'stock' => $stock,
                    'open_rolls' => (int) $fabric->open_rolls,
                    'short' => round(max(0, $remaining - $stock), 2),
                    'after' => round($stock - $remaining, 2),
                ];
            })
            ->sortBy([['short', 'desc'], ['need', 'desc']])
            ->values();

        $covered = $usages->flatten(1)->pluck('product_id')->unique()->flip();
        $missing = $short
            ->reject(fn (array $row, int $productId) => $covered->has($productId))
            ->map(fn (array $row) => ['product_id' => $row['product_id'], 'sku' => $row['sku'], 'name' => $row['name'], 'short' => (int) $row['short']])
            ->sortByDesc('short')
            ->values();

        return new self($rows, $missing, [
            'fabrics' => $rows->count(),
            'short_fabrics' => $rows->where('short', '>', 0)->count(),
            'products' => $short->count(),
            'units' => (int) $short->sum('short'),
            'wip_units' => (int) $short->sum(fn (array $row) => min((int) $row['short'], (int) ($wip[$row['product_id']] ?? 0))),
            'missing' => $missing->count(),
            'missing_units' => (int) $missing->sum('short'),
        ]);
    }

    /**
     * Pcs yang masih di penjahit (batch WIP Jahit belum ditutup), per barang.
     *
     * @return Collection<int, int>
     */
    public static function wipByProduct(): Collection
    {
        $batches = SewingBatch::query()->notClosed()->with('items')->get();
        $received = SewingBatch::receivedFor($batches->pluck('id'));

        return $batches
            ->flatMap(fn (SewingBatch $batch) => $batch->outstanding($received->get($batch->id, collect()))->map(fn ($quantity, $productId) => ['product_id' => $productId, 'quantity' => $quantity])->values())
            ->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity'));
    }

    /**
     * Kain yang masih di Cutting (cutting belum selesai) dan belum menjadi
     * potongan yang diserahkan ke penjahit, per jenis kain:
     * kain bersih yang keluar − pcs diserahkan × BOM varian itu.
     *
     * @return Collection<int, float>
     */
    public static function fabricInCutting(): Collection
    {
        $cuttings = FabricCutting::query()->open()->with(['movements', 'batches.items'])->get();

        if ($cuttings->isEmpty()) {
            return collect();
        }

        $handedProducts = $cuttings->flatMap(fn (FabricCutting $cutting) => $cutting->batches->flatMap->items->pluck('product_id'))->unique();
        $bom = FabricUsage::query()->whereIn('product_id', $handedProducts)->get()->groupBy('product_id');

        $total = collect();

        foreach ($cuttings as $cutting) {
            $used = $cutting->fabricUsed();
            $converted = collect();

            foreach ($cutting->batches->flatMap->items as $item) {
                foreach ($bom->get($item->product_id, collect()) as $usage) {
                    $converted[$usage->fabric_id] = ($converted[$usage->fabric_id] ?? 0) + $item->quantity * $usage->quantity;
                }
            }

            foreach ($used as $fabricId => $quantity) {
                $left = max(0, $quantity - (float) ($converted[$fabricId] ?? 0));
                $total[$fabricId] = round(($total[$fabricId] ?? 0) + $left, 2);
            }
        }

        return $total;
    }
}
