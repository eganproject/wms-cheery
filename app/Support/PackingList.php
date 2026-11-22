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
        return static::grouped($orders, $mode)[''] ?? static::build([], 0, 0);
    }

    /**
     * Satu daftar per tanggal batas kirim (Y-m-d), dihitung dalam satu kali
     * baca resi. Hasil tiap tanggal sama dengan for() untuk resi tanggal itu.
     *
     * @return array<string, self>
     */
    public static function perShipDate(Builder $orders, string $mode = self::BY_SKU): array
    {
        $lists = static::grouped(
            $orders,
            $mode,
            // Nilai mentah (Y-m-d H:i:s) cukup dipotong; sama dengan DATE() di SQL
            // dan jauh lebih ringan daripada membuat Carbon untuk tiap resi.
            fn (ShipmentOrder $order) => substr((string) $order->getRawOriginal('ship_by'), 0, 10),
        );
        ksort($lists);

        return $lists;
    }

    /**
     * @param  (\Closure(ShipmentOrder): ?string)|null  $bucket
     * @return array<string, self>
     */
    protected static function grouped(Builder $orders, string $mode, ?\Closure $bucket = null): array
    {
        $groups = [];
        $recipes = collect();

        // Hanya kolom yang dibaca di sini: resi besar jadi jauh lebih ringan.
        if ($orders->getQuery()->columns === null) {
            $orders->select(['shipment_orders.id', 'shipment_orders.tracking_number', 'shipment_orders.ship_by']);
        }

        $orders
            ->open()
            ->with([
                'items:id,shipment_order_id,product_id,sku,product_name,quantity',
                'outbound.items:id,outbound_id,product_id,quantity,scanned_quantity',
            ])
            ->reorder()
            // Per id, bukan offset: tetap cepat sampai halaman terakhir.
            ->chunkById(500, function (Collection $chunk) use (&$groups, &$recipes, $mode, $bucket) {
                $recipes = static::recipes($chunk, $recipes);

                foreach ($chunk as $order) {
                    $key = $bucket ? (string) $bucket($order) : '';
                    $group = &$groups[$key];
                    $group ??= ['lines' => [], 'orders' => 0, 'done' => 0];
                    $group['orders']++;
                    $orderLines = $mode === self::BY_PRODUCT
                        ? static::linesOf($order, $recipes)
                        : static::skuLinesOf($order, $recipes);
                    $complete = true;

                    foreach ($orderLines as $lineKey => $line) {
                        $group['lines'][$lineKey] ??= [
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
                        $target = &$group['lines'][$lineKey];

                        $scanned = min($line['scanned'], $line['quantity']);
                        $target['ordered'] += $line['quantity'];
                        $target['scanned'] += $scanned;
                        $target['orders']++;
                        $target['from_bundle'] = $target['from_bundle'] || $line['from_bundle'];

                        if ($scanned < $line['quantity']) {
                            $complete = false;
                            $target['open_orders']++;

                            if (count($target['open_resi']) < self::RESI_SAMPLE) {
                                $target['open_resi'][] = $order->tracking_number;
                            }
                        }

                        unset($target);
                    }

                    if ($complete && $orderLines !== [] && $order->outbound?->isResiVerified()) {
                        $group['done']++;
                    }

                    unset($group);
                }
            }, 'shipment_orders.id', 'id');

        $productIds = collect($groups)->flatMap(fn (array $group) => array_column($group['lines'], 'product_id'))->filter()->unique();
        $products = $productIds->isEmpty() ? collect() : Product::query()
            ->whereIn('id', $productIds)
            ->with('group:id,image_path')
            ->get(['id', 'sku', 'name', 'image_path', 'product_group_id'])
            ->keyBy('id');

        // Data barang dihitung sekali per barang, bukan sekali per baris tiap tanggal.
        $meta = $products->map(fn (Product $product) => static::productMeta($product))->all();

        return array_map(fn (array $group) => static::build($group['lines'], $group['orders'], $group['done'], $meta), $groups);
    }

    /**
     * @param  array<string, array<string, mixed>>  $lines
     * @param  array<int, array<string, ?string>>  $meta  data barang per id (lihat productMeta)
     */
    protected static function build(array $lines, int $orderCount, int $doneCount, array $meta = []): self
    {
        $none = static::productMeta(null);

        $rows = collect($lines)
            ->map(function (array $line) use ($meta, $none) {
                $product = $line['product_id'] ? ($meta[$line['product_id']] ?? $none) : $none;

                return array_merge($line, [
                    'sku' => $line['sku'] ?? $product['sku'],
                    'name' => $product['name'] ?? $line['name'],
                    'image_url' => $product['image_url'],
                    // Untuk PDF: berkas gambar di disk, milik barang atau induk variannya.
                    'image_path' => $product['image_path'],
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
     * @return array{sku: ?string, name: ?string, image_url: ?string, image_path: ?string}
     */
    protected static function productMeta(?Product $product): array
    {
        return [
            'sku' => $product?->sku,
            'name' => $product?->name,
            'image_url' => $product?->image_url,
            'image_path' => $product ? ($product->image_path ?: $product->group?->image_path) : null,
        ];
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
    protected static function recipes(Collection $orders, ?Collection $known = null): Collection
    {
        $known ??= collect();

        // Barang yang sudah dimuat di potongan sebelumnya tidak dibaca ulang.
        $ids = $orders
            ->flatMap(fn (ShipmentOrder $order) => $order->items->pluck('product_id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $known->has($id));

        if ($ids->isEmpty()) {
            return $known;
        }

        return $known->union(Product::query()
            ->with('bundleComponents')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id'));
    }
}
