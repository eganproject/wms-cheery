<?php

namespace App\Support;

use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Models\FabricUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kebutuhan kain dari Rencana Produksi.
 *
 * Untuk setiap barang yang "harus diproduksi" (pesanan yang tidak tertutup
 * stok barang jadi), kebutuhan kain = jumlah harus diproduksi × pemakaian
 * kain per unit. Hasilnya dijumlahkan per jenis kain lalu dibandingkan
 * dengan sisa semua roll kain itu.
 *
 * Kain yang sudah keluar ke penjahit tetapi barang jadinya belum masuk
 * gudang masih terhitung "harus diproduksi". Karena itu kain keluar sejak
 * tanggal tertentu bisa dipilih untuk mengurangi kebutuhan.
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

    public static function from(ProductionPlan $plan, ?Carbon $issuedSince = null): self
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

        $issued = $issuedSince
            ? FabricMovement::query()
                ->whereIn('fabric_id', $fabricIds)
                ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL])
                ->where('moved_at', '>=', $issuedSince)
                ->selectRaw('fabric_id, SUM(quantity) AS total')
                ->groupBy('fabric_id')
                ->pluck('total', 'fabric_id')
            : collect();

        $rows = $usages
            ->map(function (Collection $lines, int $fabricId) use ($short, $fabrics, $issued, $issuedSince) {
                $fabric = $fabrics[$fabricId];
                $products = $lines
                    ->map(fn (FabricUsage $usage) => [
                        'product_id' => $usage->product_id,
                        'sku' => $short[$usage->product_id]['sku'],
                        'name' => $short[$usage->product_id]['name'],
                        'short' => (int) $short[$usage->product_id]['short'],
                        'usage' => $usage->quantity,
                        'need' => round($short[$usage->product_id]['short'] * $usage->quantity, 2),
                    ])
                    ->sortByDesc('need')
                    ->values();

                $need = round($products->sum('need'), 2);
                // Kain keluar tercatat negatif; dibalik jadi jumlah yang keluar.
                $out = $issuedSince ? round(max(0, -(float) ($issued[$fabricId] ?? 0)), 2) : 0.0;
                $remaining = round(max(0, $need - $out), 2);
                $stock = round((float) $fabric->stock, 2);

                return [
                    'fabric' => $fabric,
                    'products' => $products,
                    'units' => (int) $products->sum('short'),
                    'need' => $need,
                    'issued' => $out,
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
            'missing' => $missing->count(),
            'missing_units' => (int) $missing->sum('short'),
        ]);
    }
}
