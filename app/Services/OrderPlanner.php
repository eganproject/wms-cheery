<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Outbound;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Batas kirim dan kesiapan stok untuk resi yang belum dikirim.
 *
 * Dua hal dihitung sekaligus untuk setiap resi yang belum dikirim dan belum
 * batal:
 *
 * 1. Batas kirim. Pesanan PO: waktu bayar + lama PO barangnya, memakai
 *    periode lama PO yang berlaku pada waktu bayar itu (beberapa barang:
 *    yang terlama). Pesanan Normal: waktu bayar + aturan Normal; Instan /
 *    besok sampai: hari yang sama. Batas yang dikunci orang atau oleh upload
 *    "Harus dikirim menurut TikTok" tidak ditimpa.
 *
 * 2. Kesiapan stok. Stok di rak dibagi ke resi Belum QC, yang paling
 *    mendesak lebih dulu (prioritas, lalu batas kirim, lalu waktu bayar).
 *    Resi yang seluruh barangnya kebagian = siap dikemas; yang tidak =
 *    menunggu stok, dan stoknya tidak dipotong sehingga resi berikutnya yang
 *    lebih kecil masih bisa siap. Barang yang sudah discan di QC Kemas tetapi
 *    dokumennya belum diproses dianggap sudah turun dari rak.
 *
 * Hasilnya disimpan di shipment_orders supaya halaman bisa menyaring dan
 * mengurutkannya di database. Perhitungan diulang hanya bila datanya berubah
 * (sidik jari beberapa tabel), jadi membuka halaman berulang kali murah.
 */
class OrderPlanner
{
    /** Naikkan bila rumusnya berubah, supaya semua resi dihitung ulang. */
    public const VERSION = 1;

    public const READY = 'siap';

    public const WAITING = 'menunggu';

    public const UNKNOWN_SKU = 'sku';

    public const SOURCE_PO = 'po';

    public const SOURCE_NORMAL = 'normal';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TIKTOK = 'tiktok';

    /** Sumber batas kirim yang dikunci: tidak ditimpa hitungan sistem. */
    public const LOCKED_SOURCES = [self::SOURCE_MANUAL, self::SOURCE_TIKTOK];

    /** Penggalan nama layanan kirim yang harus berangkat hari itu juga. */
    public const EXPRESS_MARKERS = ['instan', 'instant', 'same day', 'sameday', 'besok sampai', 'next day'];

    protected const CACHE_KEY = 'order-planner.fingerprint';

    public static function isExpress(?string $shippingMethod): bool
    {
        $needle = mb_strtolower((string) $shippingMethod);

        foreach (self::EXPRESS_MARKERS as $marker) {
            if ($needle !== '' && str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hitung ulang bila ada yang berubah sejak perhitungan terakhir.
     */
    public function refresh(bool $force = false): void
    {
        $fingerprint = $this->fingerprint();

        if (! $force && Cache::get(self::CACHE_KEY) === $fingerprint) {
            return;
        }

        $lock = Cache::lock('order-planner.refresh', 120);

        // Permintaan lain sedang menghitung: hasilnya dipakai apa adanya.
        if (! $lock->get()) {
            return;
        }

        try {
            $this->recalculate();
            // Diambil lagi setelah menulis: tulisan planner tidak menyentuh
            // updated_at, tetapi data lain bisa berubah selama perhitungan.
            Cache::forever(self::CACHE_KEY, $fingerprint);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ringkasan beberapa tabel yang memengaruhi hasil. Berubah bila stok,
     * resi, hasil scan, resep paket, lama PO, atau pengaturan berubah.
     */
    public function fingerprint(): string
    {
        // Satu query berisi beberapa sub-query, supaya halaman yang
        // memanggilnya tidak menambah banyak query.
        $row = DB::selectOne('SELECT '.implode(', ', array_map(
            fn (string $sql, int $index) => $sql.' AS f'.$index,
            $sub = [
                '(SELECT MAX(id) FROM stock_movements)',
                '(SELECT COUNT(*) FROM products)',
                '(SELECT SUM(stock) FROM products)',
                '(SELECT MAX(updated_at) FROM products)',
                '(SELECT COUNT(*) FROM shipment_orders)',
                '(SELECT MAX(updated_at) FROM shipment_orders)',
                '(SELECT SUM(CASE WHEN cancelled_at IS NULL THEN 1 ELSE 0 END) FROM shipment_orders)',
                '(SELECT COUNT(*) FROM shipment_order_items)',
                '(SELECT SUM(product_id) FROM shipment_order_items)',
                '(SELECT MAX(updated_at) FROM shipment_order_items)',
                '(SELECT COUNT(*) FROM outbounds)',
                '(SELECT MAX(updated_at) FROM outbounds)',
                '(SELECT MAX(id) FROM outbounds)',
                '(SELECT COUNT(*) FROM outbound_items)',
                '(SELECT SUM(scanned_quantity) FROM outbound_items)',
                '(SELECT SUM(quantity) FROM outbound_items)',
                '(SELECT COUNT(*) FROM product_bundle_items)',
                '(SELECT MAX(updated_at) FROM product_bundle_items)',
                '(SELECT COUNT(*) FROM product_po_periods)',
                '(SELECT MAX(updated_at) FROM product_po_periods)',
                '(SELECT MAX(id) FROM product_po_periods)',
                '(SELECT COUNT(*) FROM app_settings)',
                '(SELECT MAX(updated_at) FROM app_settings)',
            ],
            array_keys($sub),
        )));

        $parts = [self::VERSION, ...array_values((array) $row)];

        return md5(implode('|', $parts));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /* ---------------------------------------------------- perhitungan --- */

    protected function recalculate(): void
    {
        $orders = $this->openOrders();

        if ($orders === []) {
            return;
        }

        $items = $this->orderItems();
        $outbounds = $this->openOutbounds(array_keys($orders));
        $products = $this->products();
        $periods = new PoPeriodResolver;
        $normalDays = AppSetting::normalShipDays();

        // 1. Batas kirim.
        foreach ($orders as $id => &$order) {
            $deadline = $this->deadline($order, $items[$id] ?? [], $products, $periods, $normalDays);

            $order['new'] = $deadline + [
                'stock_status' => null,
                'stock_note' => null,
            ];
        }
        unset($order);

        // 2. Kesiapan stok.
        $available = [];

        foreach ($products as $productId => $product) {
            $available[$productId] = (int) $product['stock'];
        }

        $queue = [];

        foreach ($orders as $id => $order) {
            $outbound = $outbounds[$id] ?? null;

            // Barang yang sudah discan sudah turun dari rak, meskipun stoknya
            // baru dipotong saat serah terima.
            if ($outbound) {
                foreach ($outbound['items'] as $productId => $line) {
                    $available[$productId] = ($available[$productId] ?? 0) - min($line['scanned'], $line['quantity']);
                }

                if ($outbound['done']) {
                    continue; // Sudah QC: tidak perlu dibagi lagi.
                }
            }

            $queue[] = $id;
        }

        usort($queue, function (int $a, int $b) use ($orders) {
            $x = $orders[$a]['new'];
            $y = $orders[$b]['new'];

            return [! $x['is_priority'], $x['ship_by'] === null, $x['ship_by'] ?? '', $orders[$a]['ref'], $a]
                <=> [! $y['is_priority'], $y['ship_by'] === null, $y['ship_by'] ?? '', $orders[$b]['ref'], $b];
        });

        foreach ($queue as $id) {
            [$needs, $unknown] = $this->needs($items[$id] ?? [], $outbounds[$id] ?? null, $products);

            if ($unknown !== [] || $needs === []) {
                $orders[$id]['new']['stock_status'] = self::UNKNOWN_SKU;
                $orders[$id]['new']['stock_note'] = $unknown === []
                    ? 'Resi tanpa barang'
                    : 'SKU tidak dikenal: '.implode(', ', array_unique($unknown));

                continue;
            }

            $short = [];

            foreach ($needs as $productId => $quantity) {
                $left = $available[$productId] ?? 0;

                if ($left < $quantity) {
                    $short[] = ($products[$productId]['sku'] ?? '?').' kurang '.($quantity - max(0, $left));
                }
            }

            if ($short === []) {
                foreach ($needs as $productId => $quantity) {
                    $available[$productId] -= $quantity;
                }

                $orders[$id]['new']['stock_status'] = self::READY;
            } else {
                $orders[$id]['new']['stock_status'] = self::WAITING;
                $orders[$id]['new']['stock_note'] = mb_substr(implode(', ', $short), 0, 250);
            }
        }

        $this->write($orders);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    protected function deadline(array $order, array $items, array $products, PoPeriodResolver $periods, int $normalDays): array
    {
        $ref = Carbon::parse($order['ref']);
        $express = self::isExpress($order['shipping_method']);
        $computed = null;
        $poDays = null;
        $source = null;

        $normal = function () use ($ref, $express, $normalDays) {
            return $express ? $ref->copy()->endOfDay() : $ref->copy()->addDays($normalDays);
        };

        if ($order['is_preorder'] === 0) {
            $computed = $normal();
            $source = self::SOURCE_NORMAL;
        } else {
            $days = [];
            $missing = false;

            foreach ($items as $item) {
                $value = $item['product_id'] ? $periods->daysFor((int) $item['product_id'], $products, $ref) : null;

                if ($value === null) {
                    $missing = true;
                } else {
                    $days[] = $value;
                }
            }

            if ($order['is_preorder'] === null && $days === []) {
                // Data lama tanpa penanda PO dan tanpa lama PO: belum bisa dihitung.
                $missing = true;
            }

            if (! $missing && $days !== []) {
                $poDays = max($days);

                if ($poDays > 0) {
                    $computed = $ref->copy()->addDays($poDays);
                    $source = self::SOURCE_PO;
                } else {
                    $computed = $normal();
                    $source = self::SOURCE_NORMAL;
                }
            }
        }

        $locked = in_array($order['ship_by_source'], self::LOCKED_SOURCES, true);
        $computedValue = $computed?->toDateTimeString();

        return [
            'computed_ship_by' => $computedValue,
            'po_days' => $poDays,
            'ship_by' => $locked ? $order['ship_by'] : $computedValue,
            'ship_by_source' => $locked ? $order['ship_by_source'] : $source,
            'is_priority' => $express || $order['ship_by_source'] === self::SOURCE_TIKTOK,
        ];
    }

    /**
     * Barang yang masih harus diambil dari rak untuk satu resi.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $outbound
     * @param  array<int, array<string, mixed>>  $products
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    protected function needs(array $items, ?array $outbound, array $products): array
    {
        $needs = [];
        $unknown = [];

        // Dokumen QC Kemas sudah ada: isinya yang berlaku (paket sudah dipecah).
        if ($outbound && $outbound['items'] !== []) {
            foreach ($outbound['items'] as $productId => $line) {
                $left = $line['quantity'] - $line['scanned'];

                if ($left > 0) {
                    $needs[$productId] = ($needs[$productId] ?? 0) + $left;
                }
            }

            return [$needs, $unknown];
        }

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $product = $item['product_id'] ? ($products[$item['product_id']] ?? null) : null;

            if (! $product) {
                $unknown[] = (string) $item['sku'];

                continue;
            }

            if ($product['bundle'] !== []) {
                foreach ($product['bundle'] as $componentId => $per) {
                    $needs[$componentId] = ($needs[$componentId] ?? 0) + $per * $quantity;
                }

                continue;
            }

            if ($quantity > 0) {
                $needs[$product['id']] = ($needs[$product['id']] ?? 0) + $quantity;
            }
        }

        return [$needs, $unknown];
    }

    /* ---------------------------------------------------- pembacaan ----- */

    /**
     * Resi yang belum dikirim dan belum batal.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function openOrders(): array
    {
        $orders = [];

        $rows = DB::table('shipment_orders')
            ->whereNull('cancelled_at')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('outbounds')
                ->whereColumn('outbounds.shipment_order_id', 'shipment_orders.id')
                ->where('outbounds.status', Outbound::STATUS_POSTED))
            ->select([
                'id', 'is_preorder', 'paid_at', 'order_date', 'created_at', 'shipping_method',
                'computed_ship_by', 'po_days', 'ship_by', 'ship_by_source', 'is_priority',
                'stock_status', 'stock_note',
            ])
            ->cursor();

        foreach ($rows as $row) {
            $orders[(int) $row->id] = [
                'is_preorder' => $row->is_preorder === null ? null : (int) $row->is_preorder,
                'ref' => (string) ($row->paid_at ?? $row->order_date ?? $row->created_at),
                'shipping_method' => $row->shipping_method,
                'ship_by' => $row->ship_by ? Carbon::parse($row->ship_by)->toDateTimeString() : null,
                'ship_by_source' => $row->ship_by_source,
                'old' => [
                    'computed_ship_by' => $row->computed_ship_by ? Carbon::parse($row->computed_ship_by)->toDateTimeString() : null,
                    'po_days' => $row->po_days === null ? null : (int) $row->po_days,
                    'ship_by' => $row->ship_by ? Carbon::parse($row->ship_by)->toDateTimeString() : null,
                    'ship_by_source' => $row->ship_by_source,
                    'is_priority' => (bool) $row->is_priority,
                    'stock_status' => $row->stock_status,
                    'stock_note' => $row->stock_note,
                ],
            ];
        }

        return $orders;
    }

    /**
     * Baris barang resi yang belum dikirim, dikelompokkan per resi.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function orderItems(): array
    {
        $items = [];

        $rows = DB::table('shipment_order_items')
            ->join('shipment_orders', 'shipment_orders.id', '=', 'shipment_order_items.shipment_order_id')
            ->whereNull('shipment_orders.cancelled_at')
            ->select(['shipment_order_items.shipment_order_id', 'shipment_order_items.product_id', 'shipment_order_items.sku', 'shipment_order_items.quantity'])
            ->orderBy('shipment_order_items.id')
            ->cursor();

        foreach ($rows as $row) {
            $items[(int) $row->shipment_order_id][] = [
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'sku' => $row->sku,
                'quantity' => (int) $row->quantity,
            ];
        }

        return $items;
    }

    /**
     * Dokumen QC Kemas terakhir per resi yang belum diproses.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array<string, mixed>>
     */
    protected function openOutbounds(array $orderIds): array
    {
        $latest = DB::table('outbounds')
            ->whereNotNull('shipment_order_id')
            ->groupBy('shipment_order_id')
            ->selectRaw('shipment_order_id, MAX(id) as id')
            ->pluck('id', 'shipment_order_id');

        $wanted = [];

        foreach ($orderIds as $orderId) {
            if (isset($latest[$orderId])) {
                $wanted[(int) $latest[$orderId]] = $orderId;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $outbounds = [];

        foreach (array_chunk(array_keys($wanted), 1000) as $chunk) {
            $rows = DB::table('outbounds')
                ->leftJoin('outbound_items', 'outbound_items.outbound_id', '=', 'outbounds.id')
                ->whereIn('outbounds.id', $chunk)
                ->where('outbounds.status', '!=', Outbound::STATUS_POSTED)
                ->select(['outbounds.id', 'outbounds.resi_verified_at', 'outbound_items.product_id', 'outbound_items.quantity', 'outbound_items.scanned_quantity'])
                ->get();

            foreach ($rows as $row) {
                $orderId = $wanted[(int) $row->id];
                $outbounds[$orderId] ??= ['verified' => $row->resi_verified_at !== null, 'items' => []];

                if ($row->product_id === null) {
                    continue;
                }

                $line = &$outbounds[$orderId]['items'][(int) $row->product_id];
                $line ??= ['quantity' => 0, 'scanned' => 0];
                $line['quantity'] += (int) $row->quantity;
                $line['scanned'] += (int) $row->scanned_quantity;
                unset($line);
            }
        }

        foreach ($outbounds as &$outbound) {
            $ordered = array_sum(array_column($outbound['items'], 'quantity'));
            $complete = $ordered > 0;

            foreach ($outbound['items'] as $line) {
                $complete = $complete && $line['scanned'] >= $line['quantity'];
            }

            $outbound['done'] = $outbound['verified'] && $complete;
        }
        unset($outbound);

        return $outbounds;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function products(): array
    {
        $products = [];

        foreach (DB::table('products')->select(['id', 'sku', 'stock', 'type', 'product_group_id'])->cursor() as $row) {
            $products[(int) $row->id] = [
                'id' => (int) $row->id,
                'sku' => $row->sku,
                'stock' => (int) $row->stock,
                'group' => $row->product_group_id ? (int) $row->product_group_id : null,
                'bundle' => [],
                'is_bundle' => $row->type === Product::TYPE_BUNDLE,
            ];
        }

        foreach (DB::table('product_bundle_items')->get(['bundle_id', 'component_id', 'quantity']) as $row) {
            if (isset($products[(int) $row->bundle_id]) && $products[(int) $row->bundle_id]['is_bundle']) {
                $products[(int) $row->bundle_id]['bundle'][(int) $row->component_id] = max(1, (int) $row->quantity);
            }
        }

        return $products;
    }

    /* ---------------------------------------------------- penulisan ----- */

    /**
     * Tulis hanya baris yang berubah, borongan, tanpa menyentuh updated_at
     * (supaya sidik jarinya tidak ikut berubah karena tulisan sendiri).
     *
     * @param  array<int, array<string, mixed>>  $orders
     */
    protected function write(array $orders): void
    {
        $columns = ['computed_ship_by', 'po_days', 'ship_by', 'ship_by_source', 'is_priority', 'stock_status', 'stock_note'];
        $changed = [];

        foreach ($orders as $id => $order) {
            foreach ($columns as $column) {
                if ($order['old'][$column] !== $order['new'][$column]) {
                    $changed[$id] = $order['new'];
                    break;
                }
            }
        }

        if ($changed === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();

        DB::transaction(function () use ($changed, $columns, $grammar) {
            foreach (array_chunk($changed, 300, true) as $chunk) {
                $sets = [];
                $bindings = [];

                foreach ($columns as $column) {
                    $case = 'CASE id';

                    foreach ($chunk as $id => $values) {
                        $case .= ' WHEN ? THEN ?';
                        $bindings[] = $id;
                        $value = $values[$column];
                        $bindings[] = is_bool($value) ? (int) $value : $value;
                    }

                    $sets[] = $grammar->wrap($column).' = '.$case.' END';
                }

                $ids = array_keys($chunk);
                $bindings = array_merge($bindings, $ids);

                DB::update(
                    'UPDATE '.$grammar->wrapTable('shipment_orders').' SET '.implode(', ', $sets)
                    .' WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                    $bindings,
                );
            }
        });
    }
}
