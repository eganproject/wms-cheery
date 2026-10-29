<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ShipmentOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar barang yang harus dikemas untuk sekumpulan resi hasil import,
 * berikut sisanya setelah QC Kemas.
 *
 * Hanya membaca. Barisnya mengikuti apa yang benar-benar diambil dari rak:
 *
 *  - Resi yang sudah punya dokumen barang keluar memakai baris dokumennya
 *    (paket sudah dipecah, dan jumlah yang sudah discan tercatat di sana).
 *  - Resi yang belum punya dokumen dipecah dari isi pesanannya dengan resep
 *    paket yang sama, sehingga satu barang tetap satu baris meskipun
 *    sebagian datang dari paket.
 *  - SKU yang belum terdaftar di master barang tetap ditampilkan menurut
 *    SKU-nya, supaya tidak ada barang yang diam-diam hilang dari daftar.
 */
class PackingList
{
    /** Diringkas menurut SKU yang tertulis di resi (paket tetap satu baris). */
    public const BY_SKU = 'sku';

    /** Diringkas menurut barang di rak (paket dipecah jadi isinya). */
    public const BY_PRODUCT = 'barang';

    /** Batas nomor resi yang disimpan per baris untuk daftar "belum tuntas". */
    public const RESI_SAMPLE = 100;

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $totals
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly array $totals,
    ) {
    }

    /**
     * Susun daftar dari resi yang belum dikirim dan belum dibatalkan.
     */
    public static function for(Builder $orders, string $mode = self::BY_SKU): self
    {
        $lines = [];
        $orderCount = 0;
        $doneCount = 0;

        $orders
            ->whereNull('cancelled_at')
            ->whereDoesntHave('outbounds', fn (Builder $outbound) => $outbound->where('status', \App\Models\Outbound::STATUS_POSTED))
            ->with(['items', 'outbound.items'])
            ->orderBy('shipment_orders.id')
            ->chunk(500, function (Collection $chunk) use (&$lines, &$orderCount, &$doneCount, $mode) {
                $recipes = static::recipes($chunk);

                foreach ($chunk as $order) {
                    $orderCount++;
                    $orderLines = $mode === self::BY_PRODUCT
                        ? static::linesOf($order, $recipes)
                        : static::skuLinesOf($order, $recipes);
                    $complete = true;

                    foreach ($orderLines as $key => $line) {
                        $lines[$key] ??= [
                            'product_id' => $line['product_id'],
                            'sku' => $line['sku'],
                            'name' => $line['name'],
                            'from_bundle' => false,
                            'ordered' => 0,
                            'scanned' => 0,
                            'orders' => 0,
                            'open_orders' => 0,
                            'open_resi' => [],
                        ];

                        $scanned = min($line['scanned'], $line['quantity']);
                        $lines[$key]['ordered'] += $line['quantity'];
                        $lines[$key]['scanned'] += $scanned;
                        $lines[$key]['orders']++;
                        $lines[$key]['from_bundle'] = $lines[$key]['from_bundle'] || $line['from_bundle'];

                        if ($scanned < $line['quantity']) {
                            $complete = false;
                            $lines[$key]['open_orders']++;

                            if (count($lines[$key]['open_resi']) < self::RESI_SAMPLE) {
                                $lines[$key]['open_resi'][] = $order->tracking_number;
                            }
                        }
                    }

                    if ($complete && $orderLines !== [] && $order->outbound?->isResiVerified()) {
                        $doneCount++;
                    }
                }
            });

        $products = Product::query()
            ->whereIn('id', collect($lines)->pluck('product_id')->filter()->unique())
            ->with('group:id,image_path')
            ->get(['id', 'sku', 'name', 'image_path', 'product_group_id'])
            ->keyBy('id');

        $rows = collect($lines)
            ->map(function (array $line) use ($products) {
                $product = $line['product_id'] ? $products->get($line['product_id']) : null;

                return array_merge($line, [
                    'sku' => $line['sku'] ?? $product?->sku,
                    'name' => $product?->name ?? $line['name'],
                    'image_url' => $product?->image_url,
                    // Untuk PDF: berkas gambar di disk, milik barang atau induk variannya.
                    'image_path' => $product ? ($product->image_path ?: $product->group?->image_path) : null,
                    'matched' => $line['product_id'] !== null,
                    'remaining' => $line['ordered'] - $line['scanned'],
                ]);
            })
            ->sortBy(fn (array $row) => mb_strtoupper((string) $row['sku']))
            ->values();

        return new self($rows, [
            'orders' => $orderCount,
            'orders_done' => $doneCount,
            'orders_open' => $orderCount - $doneCount,
            'skus' => $rows->count(),
            'ordered' => (int) $rows->sum('ordered'),
            'scanned' => (int) $rows->sum('scanned'),
            'remaining' => (int) $rows->sum('remaining'),
            'unmatched' => $rows->where('matched', false)->count(),
        ]);
    }

    /**
     * Baris barang satu resi, dikunci per barang (atau per SKU bila belum
     * terdaftar). Barang yang sama digabung.
     *
     * @param  Collection<int, Product>  $recipes
     * @return array<string, array<string, mixed>>
     */
    protected static function linesOf(ShipmentOrder $order, Collection $recipes): array
    {
        $lines = [];
        $add = function (string $key, array $line) use (&$lines) {
            if (isset($lines[$key])) {
                $lines[$key]['quantity'] += $line['quantity'];
                $lines[$key]['scanned'] += $line['scanned'];
                $lines[$key]['from_bundle'] = $lines[$key]['from_bundle'] || $line['from_bundle'];

                return;
            }

            $lines[$key] = $line;
        };

        if ($order->outbound) {
            foreach ($order->outbound->items as $item) {
                $add('p'.$item->product_id, [
                    'product_id' => (int) $item->product_id,
                    'sku' => null,
                    'name' => null,
                    'quantity' => (int) $item->quantity,
                    'scanned' => (int) $item->scanned_quantity,
                    // Paket sudah dipecah saat dokumennya dibentuk.
                    'from_bundle' => false,
                ]);
            }

            return $lines;
        }

        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $product = $item->product_id ? $recipes->get($item->product_id) : null;

            if (! $product) {
                $add('s'.mb_strtoupper(trim($item->sku)), [
                    'product_id' => null,
                    'sku' => $item->sku,
                    'name' => $item->product_name,
                    'quantity' => $quantity,
                    'scanned' => 0,
                    'from_bundle' => false,
                ]);

                continue;
            }

            if ($product->isBundle() && $product->bundleComponents->isNotEmpty()) {
                foreach ($product->bundleComponents as $component) {
                    $add('p'.$component->component_id, [
                        'product_id' => (int) $component->component_id,
                        'sku' => null,
                        'name' => null,
                        'quantity' => (int) $component->quantity * $quantity,
                        'scanned' => 0,
                        'from_bundle' => true,
                    ]);
                }

                continue;
            }

            $add('p'.$product->id, [
                'product_id' => (int) $product->id,
                'sku' => null,
                'name' => null,
                'quantity' => $quantity,
                'scanned' => 0,
                'from_bundle' => false,
            ]);
        }

        return $lines;
    }

    /**
     * Baris satu resi menurut SKU yang tertulis di resinya, dijumlahkan per
     * SKU. Paket tetap satu baris dengan SKU paketnya.
     *
     * Jumlah yang sudah discan dibaca dari dokumen gudangnya: barang satuan
     * dihitung lebih dulu, lalu sisanya dipakai untuk paket — satu paket
     * dianggap selesai bila seluruh isinya sudah discan.
     *
     * @param  Collection<int, Product>  $recipes
     * @return array<string, array<string, mixed>>
     */
    protected static function skuLinesOf(ShipmentOrder $order, Collection $recipes): array
    {
        $pool = $order->outbound
            ? $order->outbound->items->groupBy('product_id')->map(fn ($items) => (int) $items->sum('scanned_quantity'))->all()
            : [];

        $lines = [];
        $bundles = [];

        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $key = 's'.mb_strtoupper(trim($item->sku));
            $product = $item->product_id ? $recipes->get($item->product_id) : null;
            $isBundle = $product && $product->isBundle() && $product->bundleComponents->isNotEmpty();

            $lines[$key] ??= [
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => 0,
                'scanned' => 0,
                'from_bundle' => $isBundle,
            ];
            $lines[$key]['quantity'] += $quantity;

            if ($isBundle) {
                $bundles[] = [$key, $product, $quantity];

                continue;
            }

            if ($item->product_id) {
                $take = min($quantity, $pool[$item->product_id] ?? 0);
                $pool[$item->product_id] = ($pool[$item->product_id] ?? 0) - $take;
                $lines[$key]['scanned'] += $take;
            }
        }

        foreach ($bundles as [$key, $product, $quantity]) {
            $done = $quantity;

            foreach ($product->bundleComponents as $component) {
                $per = max(1, (int) $component->quantity);
                $done = min($done, intdiv(max(0, $pool[$component->component_id] ?? 0), $per));
            }

            foreach ($product->bundleComponents as $component) {
                $pool[$component->component_id] = ($pool[$component->component_id] ?? 0) - $done * max(1, (int) $component->quantity);
            }

            $lines[$key]['scanned'] += $done;
        }

        return $lines;
    }

    /**
     * Barang yang disebut pesanan, berikut isi paketnya.
     *
     * @param  Collection<int, ShipmentOrder>  $orders
     * @return Collection<int, Product>
     */
    protected static function recipes(Collection $orders): Collection
    {
        $ids = $orders
            ->flatMap(fn (ShipmentOrder $order) => $order->items->pluck('product_id'))
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->with('bundleComponents')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
