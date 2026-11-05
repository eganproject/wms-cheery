<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentOrder;
use App\Models\StockMovement;
use App\Services\Attendance\AttendanceCalculator;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka-angka untuk Laporan Owner.
 *
 * Semua dibaca dari data yang sudah ada di gudang: resi hasil import,
 * dokumen keluar, retur, kartu stok, dan absensi. Tidak ada yang disimpan
 * ulang, jadi angkanya selalu sama dengan halaman asalnya.
 *
 * Pesanan dihitung menurut tanggal pesanannya di marketplace (bila ada),
 * bukan tanggal unggah berkas: bagi owner "penjualan bulan ini" adalah
 * pesanan yang masuk bulan ini.
 */
class OwnerReportService
{
    /** Tanggal pesanan, jatuh ke tanggal unggah bila marketplace tidak mengisinya. */
    public const SALE_DATE = 'COALESCE(shipment_orders.order_date, shipment_orders.created_at)';

    public function __construct(protected AttendanceCalculator $attendance)
    {
    }

    /* ------------------------------------------------------ penjualan --- */

    /**
     * Resi yang dihitung sebagai penjualan: tidak batal, atau batal setelah
     * berangkat (paketnya sudah keluar gudang dan tetap tercatat terjual).
     */
    protected function salesOrders(DateRange $range, array $filters = []): QueryBuilder
    {
        return $this->constrainExpression(DB::table('shipment_orders'), self::SALE_DATE, $range)
            ->when($filters['marketplace'] ?? null, fn ($q, $v) => $q->where('shipment_orders.marketplace', $v))
            ->when($filters['store'] ?? null, fn ($q, $v) => $q->where('shipment_orders.store_name', $v))
            ->where(fn ($q) => $q->whereNull('shipment_orders.cancelled_at')
                ->orWhereExists($this->postedOutbound()));
    }

    protected function cancelledOrders(DateRange $range, array $filters = []): QueryBuilder
    {
        return $this->constrainExpression(DB::table('shipment_orders'), self::SALE_DATE, $range)
            ->when($filters['marketplace'] ?? null, fn ($q, $v) => $q->where('shipment_orders.marketplace', $v))
            ->when($filters['store'] ?? null, fn ($q, $v) => $q->where('shipment_orders.store_name', $v))
            ->whereNotNull('shipment_orders.cancelled_at')
            ->whereNotExists($this->postedOutbound());
    }

    protected function postedOutbound(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('outbounds')
            ->whereColumn('outbounds.shipment_order_id', 'shipment_orders.id')
            ->where('outbounds.status', Outbound::STATUS_POSTED);
    }

    /**
     * Ringkasan penjualan: angka besar, tren per periode, dan per toko.
     *
     * @param  string  $per  hari | minggu | bulan
     * @return array<string, mixed>
     */
    public function sales(DateRange $range, string $per, array $filters = []): array
    {
        $items = fn () => $this->salesOrders($range, $filters)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id');

        $daily = $this->salesOrders($range, $filters)
            ->selectRaw('DATE('.self::SALE_DATE.') as day')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('orders', 'day');

        $dailyItems = $items()
            ->selectRaw('DATE('.self::SALE_DATE.') as day')
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(soi.amount), 0) as revenue')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $dailyCancelled = $this->cancelledOrders($range, $filters)
            ->selectRaw('DATE('.self::SALE_DATE.') as day')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('orders', 'day');

        // Penjualan manual tidak punya harga; dihitung pesanan dan unitnya saja.
        $manual = array_filter($filters) === [] ? $this->manualSales($range) : collect();

        $days = $daily->keys()->concat($dailyCancelled->keys())->concat($manual->keys())->unique()->sort()->values();

        $periods = $days
            ->groupBy(fn (string $day) => $this->periodKey($day, $per))
            ->map(function (Collection $group, string $key) use ($per, $daily, $dailyItems, $dailyCancelled, $manual) {
                return (object) [
                    'key' => $key,
                    'label' => $this->periodLabel($key, $per),
                    'orders' => $group->sum(fn ($d) => (int) ($daily[$d] ?? 0)),
                    'units' => $group->sum(fn ($d) => (int) ($dailyItems[$d]->units ?? 0)),
                    'revenue' => $group->sum(fn ($d) => (float) ($dailyItems[$d]->revenue ?? 0)),
                    'cancelled' => $group->sum(fn ($d) => (int) ($dailyCancelled[$d] ?? 0)),
                    'manual_orders' => $group->sum(fn ($d) => (int) ($manual[$d]->orders ?? 0)),
                    'manual_units' => $group->sum(fn ($d) => (int) ($manual[$d]->units ?? 0)),
                ];
            })
            ->sortKeysDesc()
            ->values();

        $priced = $items()->whereNotNull('soi.amount')->count();
        $lines = $items()->count();

        $stores = $this->salesOrders($range, $filters)
            ->leftJoin('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->selectRaw("COALESCE(NULLIF(shipment_orders.marketplace, ''), 'Lainnya') as marketplace")
            ->selectRaw("COALESCE(NULLIF(shipment_orders.store_name, ''), '') as store")
            ->selectRaw('COUNT(DISTINCT shipment_orders.id) as orders')
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(soi.amount), 0) as revenue')
            ->groupBy('marketplace', 'store')
            ->get()
            ->map(fn ($row) => (object) [
                'marketplace' => $row->marketplace,
                'store' => $row->store,
                'orders' => (int) $row->orders,
                'units' => (int) $row->units,
                'revenue' => (float) $row->revenue,
            ]);

        if ($manual->isNotEmpty()) {
            $stores->push((object) [
                'marketplace' => 'Penjualan Manual',
                'store' => '',
                'orders' => $manual->sum('orders'),
                'units' => $manual->sum('units'),
                'revenue' => 0.0,
            ]);
        }

        $orders = (int) $daily->sum();
        $revenue = (float) $dailyItems->sum('revenue');
        $cancelled = (int) $dailyCancelled->sum();

        return [
            'periods' => $periods,
            'stores' => $stores->sortByDesc('orders')->values(),
            'totals' => [
                'orders' => $orders,
                'units' => (int) $dailyItems->sum('units'),
                'revenue' => $revenue,
                'average' => $orders > 0 ? $revenue / $orders : 0,
                'cancelled' => $cancelled,
                'cancel_rate' => ($orders + $cancelled) > 0 ? $cancelled / ($orders + $cancelled) * 100 : 0,
                'manual_orders' => (int) $manual->sum('orders'),
                'manual_units' => (int) $manual->sum('units'),
                'priced_lines' => $priced,
                'lines' => $lines,
            ],
        ];
    }

    /**
     * Penjualan manual yang sudah disetujui, per hari.
     *
     * @return Collection<string, object>
     */
    protected function manualSales(DateRange $range): Collection
    {
        return DateRange::constrain(DB::table('outbounds'), 'outbounds.posted_at', $range->from, $range->to)
            ->where('outbounds.type', Outbound::TYPE_REGULAR)
            ->where('outbounds.status', Outbound::STATUS_POSTED)
            ->leftJoin('outbound_items as oi', 'oi.outbound_id', '=', 'outbounds.id')
            ->selectRaw('DATE(outbounds.posted_at) as day')
            ->selectRaw('COUNT(DISTINCT outbounds.id) as orders')
            ->selectRaw('COALESCE(SUM(oi.quantity), 0) as units')
            ->groupBy('day')
            ->get()
            ->keyBy('day');
    }

    /* ------------------------------------------------- ketepatan kirim --- */

    public const SHIP_ON_TIME = 'tepat';

    public const SHIP_LATE = 'telat';

    public const SHIP_OVERDUE = 'lewat';

    public const SHIP_PENDING = 'menunggu';

    /**
     * Resi yang batas kirimnya jatuh di dalam rentang, dipilah menurut
     * apakah ia berangkat sebelum batasnya.
     *
     * "Berangkat" adalah saat dokumen keluarnya disetujui di Serah Terima
     * Paket — tanggal yang sama dengan tahap Dikirim di halaman Pesanan.
     *
     * @param  string|null  $kind  po | normal | null
     * @return array<string, mixed>
     */
    public function shipping(DateRange $range, ?string $kind = null): array
    {
        $rows = DateRange::constrain(DB::table('shipment_orders'), 'shipment_orders.ship_by', $range->from, $range->to)
            ->whereNotNull('shipment_orders.ship_by')
            ->whereNull('shipment_orders.cancelled_at')
            ->when($kind === 'po', fn ($q) => $q->where('shipment_orders.is_preorder', true))
            ->when($kind === 'normal', fn ($q) => $q->where(fn ($q) => $q->where('shipment_orders.is_preorder', false)->orWhereNull('shipment_orders.is_preorder')))
            ->leftJoinSub(
                DB::table('outbounds')
                    ->where('status', Outbound::STATUS_POSTED)
                    ->whereNotNull('shipment_order_id')
                    ->selectRaw('shipment_order_id, MIN(posted_at) as shipped_at')
                    ->groupBy('shipment_order_id'),
                'sent',
                'sent.shipment_order_id', '=', 'shipment_orders.id',
            )
            ->select('shipment_orders.id', 'shipment_orders.tracking_number', 'shipment_orders.order_number',
                'shipment_orders.courier', 'shipment_orders.is_preorder', 'shipment_orders.ship_by', 'sent.shipped_at')
            ->orderBy('shipment_orders.ship_by')
            ->get();

        $now = now();

        $rows = $rows->map(function ($row) use ($now) {
            $shipBy = Carbon::parse($row->ship_by);
            $shippedAt = $row->shipped_at ? Carbon::parse($row->shipped_at) : null;

            $row->status = match (true) {
                $shippedAt && $shippedAt->lte($shipBy) => self::SHIP_ON_TIME,
                (bool) $shippedAt => self::SHIP_LATE,
                $shipBy->lt($now) => self::SHIP_OVERDUE,
                default => self::SHIP_PENDING,
            };

            // Telat dalam jam, dari batas kirim sampai berangkat (atau sampai
            // sekarang bila belum berangkat).
            $row->late_hours = in_array($row->status, [self::SHIP_LATE, self::SHIP_OVERDUE], true)
                ? (int) round($shipBy->diffInMinutes($shippedAt ?? $now) / 60)
                : 0;
            $row->day = $shipBy->toDateString();

            return $row;
        });

        $count = fn (Collection $rows, string $status) => $rows->where('status', $status)->count();

        $days = $rows->groupBy('day')->map(fn (Collection $group, string $day) => (object) [
            'day' => $day,
            'label' => Carbon::parse($day)->translatedFormat('D, d M Y'),
            'total' => $group->count(),
            'on_time' => $count($group, self::SHIP_ON_TIME),
            'late' => $count($group, self::SHIP_LATE),
            'overdue' => $count($group, self::SHIP_OVERDUE),
            'pending' => $count($group, self::SHIP_PENDING),
        ])->sortKeysDesc()->values();

        $onTime = $count($rows, self::SHIP_ON_TIME);
        $late = $count($rows, self::SHIP_LATE);
        $overdue = $count($rows, self::SHIP_OVERDUE);
        $judged = $onTime + $late + $overdue;
        $lateRows = $rows->whereIn('status', [self::SHIP_LATE, self::SHIP_OVERDUE]);

        return [
            'days' => $days,
            'late' => $lateRows->sortByDesc('late_hours')->values(),
            'totals' => [
                'total' => $rows->count(),
                'on_time' => $onTime,
                'late' => $late,
                'overdue' => $overdue,
                'pending' => $count($rows, self::SHIP_PENDING),
                // Persen tepat waktu dari resi yang sudah bisa dinilai: sudah
                // berangkat, atau sudah lewat batas tanpa berangkat.
                'rate' => $judged > 0 ? $onTime / $judged * 100 : null,
                'avg_late_hours' => $lateRows->isNotEmpty() ? $lateRows->avg('late_hours') : 0,
            ],
        ];
    }

    /* --------------------------------------------- terlaris & tidak laku --- */

    /**
     * Barang menurut jumlah terjual di marketplace pada rentang itu.
     *
     * @return Collection<int, object>
     */
    public function bestSellers(DateRange $range): Collection
    {
        $sold = $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->whereNotNull('soi.product_id')
            ->select('soi.product_id')
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as units')
            ->selectRaw('COUNT(DISTINCT shipment_orders.id) as orders')
            ->selectRaw('COALESCE(SUM(soi.amount), 0) as revenue')
            ->groupBy('soi.product_id')
            ->get()
            ->keyBy('product_id');

        $products = Product::whereIn('id', $sold->keys())->get(['id', 'sku', 'name', 'stock', 'type', 'image_path'])->keyBy('id');

        return $sold->map(fn ($row) => (object) [
            'product' => $products->get($row->product_id),
            'units' => (int) $row->units,
            'orders' => (int) $row->orders,
            'revenue' => (float) $row->revenue,
        ])->filter(fn ($row) => $row->product)->sortByDesc('units')->values();
    }

    /**
     * Barang yang masih punya stok tetapi tidak terjual sama sekali pada
     * rentang itu, dengan kapan terakhir ia keluar gudang.
     *
     * @return Collection<int, object>
     */
    public function nonMoving(DateRange $range): Collection
    {
        $sold = $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->whereNotNull('soi.product_id')
            ->distinct()
            ->pluck('soi.product_id');

        $manualSold = DateRange::constrain(DB::table('outbounds'), 'outbounds.posted_at', $range->from, $range->to)
            ->where('outbounds.status', Outbound::STATUS_POSTED)
            ->join('outbound_items as oi', 'oi.outbound_id', '=', 'outbounds.id')
            ->distinct()
            ->pluck('oi.product_id');

        $products = Product::singles()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->whereNotIn('id', $sold->concat($manualSold)->unique()->values())
            ->get(['id', 'sku', 'name', 'stock', 'type', 'image_path']);

        $lastOut = $this->lastOutgoing($products->pluck('id'));

        return $products->map(fn (Product $product) => (object) [
            'product' => $product,
            'stock' => (int) $product->stock,
            'last_out' => isset($lastOut[$product->id]) ? Carbon::parse($lastOut[$product->id]) : null,
        ])->sortByDesc('stock')->values();
    }

    /**
     * Tanggal terakhir tiap barang keluar lewat penjualan.
     *
     * @return Collection<int, string>
     */
    protected function lastOutgoing(Collection $productIds): Collection
    {
        $result = collect();

        foreach ($productIds->chunk(1000) as $chunk) {
            $result = $result->union(DB::table('stock_movements')
                ->whereIn('product_id', $chunk->all())
                ->where('type', 'out')
                ->where('bucket', StockMovement::BUCKET_GOOD)
                ->where('reference_type', Outbound::class)
                ->groupBy('product_id')
                ->selectRaw('product_id, MAX(created_at) as last_out')
                ->pluck('last_out', 'product_id'));
        }

        return $result;
    }

    /* ------------------------------------------------------- laba kotor --- */

    /**
     * Laba kotor per barang: omzet − HPP × unit − potongan marketplace.
     *
     * Hanya baris yang punya harga jual yang dihitung, supaya omzet dan HPP
     * selalu atas unit yang sama. Barang tanpa HPP tetap tampil, tetapi
     * labanya kosong dan tidak ikut dijumlahkan.
     *
     * @return array<string, mixed>
     */
    public function profit(DateRange $range): array
    {
        $fees = \App\Models\AppSetting::marketplaceFees();

        $lines = $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->whereNotNull('soi.product_id')
            ->whereNotNull('soi.amount')
            ->select('soi.product_id')
            ->selectRaw("COALESCE(shipment_orders.marketplace, '') as marketplace")
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(soi.amount), 0) as revenue')
            ->groupBy('soi.product_id', 'marketplace')
            ->get();

        $unpriced = (int) $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->whereNull('soi.amount')
            ->sum('soi.quantity');

        $products = Product::with('bundleComponents.component:id,cost')
            ->whereIn('id', $lines->pluck('product_id')->unique())
            ->get(['id', 'sku', 'name', 'type', 'cost', 'image_path'])
            ->keyBy('id');

        $rows = $lines->groupBy('product_id')->map(function (Collection $group, $productId) use ($products, $fees) {
            $product = $products->get($productId);
            $units = (int) $group->sum('units');
            $revenue = (float) $group->sum('revenue');
            $fee = (float) $group->sum(fn ($line) => $line->revenue * (($fees[$line->marketplace] ?? 0) / 100));
            $unitCost = $product ? $this->unitCost($product) : null;
            $cost = $unitCost !== null ? $unitCost * $units : null;
            $profit = $cost !== null ? $revenue - $fee - $cost : null;

            return (object) [
                'product' => $product,
                'units' => $units,
                'revenue' => $revenue,
                'fee' => $fee,
                'unit_cost' => $unitCost,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => $profit !== null && $revenue > 0 ? $profit / $revenue * 100 : null,
            ];
        })->filter(fn ($row) => $row->product)->values();

        $costed = $rows->whereNotNull('cost');
        $revenue = (float) $costed->sum('revenue');
        $profit = (float) $costed->sum('profit');

        return [
            'rows' => $rows->sortByDesc(fn ($row) => $row->profit ?? PHP_INT_MIN)->values(),
            'fees' => $fees,
            'totals' => [
                'revenue' => (float) $rows->sum('revenue'),
                'costed_revenue' => $revenue,
                'fee' => (float) $costed->sum('fee'),
                'cost' => (float) $costed->sum('cost'),
                'profit' => $profit,
                'margin' => $revenue > 0 ? $profit / $revenue * 100 : null,
                'missing_cost' => $rows->whereNull('cost')->count(),
                'missing_cost_revenue' => (float) $rows->whereNull('cost')->sum('revenue'),
                'unpriced_units' => $unpriced,
            ],
        ];
    }

    /**
     * HPP satu unit. Paket tanpa HPP sendiri memakai jumlah HPP isinya,
     * asalkan semua isinya sudah punya HPP.
     */
    public function unitCost(Product $product): ?float
    {
        if ($product->cost !== null) {
            return (float) $product->cost;
        }

        if (! $product->isBundle() || $product->bundleComponents->isEmpty()) {
            return null;
        }

        $total = 0.0;

        foreach ($product->bundleComponents as $line) {
            if ($line->component?->cost === null) {
                return null;
            }

            $total += (float) $line->component->cost * $line->quantity;
        }

        return $total;
    }

    /* ------------------------------------------------------------ retur --- */

    /**
     * Retur yang sudah disetujui pada rentang itu (menurut tanggal dokumen).
     *
     * @return array<string, mixed>
     */
    public function returns(DateRange $range): array
    {
        $receipts = fn () => DateRange::constrain(DB::table('return_receipts'), 'return_receipts.date', $range->from, $range->to)
            ->where('return_receipts.status', ReturnReceipt::STATUS_POSTED);

        $items = $receipts()
            ->join('return_receipt_items as rri', 'rri.return_receipt_id', '=', 'return_receipts.id')
            ->select('rri.product_id')
            ->selectRaw('COALESCE(SUM(rri.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(rri.good_quantity), 0) as good')
            ->selectRaw('COALESCE(SUM(rri.damaged_quantity), 0) as damaged')
            ->selectRaw('COUNT(DISTINCT return_receipts.id) as receipts')
            ->groupBy('rri.product_id')
            ->get()
            ->keyBy('product_id');

        // Pembanding: berapa yang terjual pada rentang yang sama.
        $sold = $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->whereIn('soi.product_id', $items->keys()->all())
            ->groupBy('soi.product_id')
            ->selectRaw('soi.product_id, COALESCE(SUM(soi.quantity), 0) as units')
            ->pluck('units', 'product_id');

        $products = Product::whereIn('id', $items->keys())->get(['id', 'sku', 'name', 'image_path', 'type'])->keyBy('id');

        $perProduct = $items->map(fn ($row) => (object) [
            'product' => $products->get($row->product_id),
            'units' => (int) $row->units,
            'good' => (int) $row->good,
            'damaged' => (int) $row->damaged,
            'receipts' => (int) $row->receipts,
            'sold' => (int) ($sold[$row->product_id] ?? 0),
            'rate' => ($sold[$row->product_id] ?? 0) > 0 ? $row->units / $sold[$row->product_id] * 100 : null,
        ])->filter(fn ($row) => $row->product)->sortByDesc('units')->values();

        $reasons = $receipts()
            ->selectRaw("COALESCE(NULLIF(return_receipts.reason, ''), 'Tanpa alasan') as reason")
            ->selectRaw('COUNT(*) as receipts')
            ->groupBy('reason')
            ->orderByDesc('receipts')
            ->get();

        $foreign = $receipts()
            ->join('return_receipt_foreign_items as rfi', 'rfi.return_receipt_id', '=', 'return_receipts.id')
            ->selectRaw('COUNT(DISTINCT return_receipts.id) as receipts')
            ->selectRaw('COALESCE(SUM(rfi.quantity), 0) as units')
            ->first();

        $soldTotal = (int) $this->salesOrders($range)
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->sum('soi.quantity');

        $units = $perProduct->sum('units');

        return [
            'products' => $perProduct,
            'reasons' => $reasons,
            'totals' => [
                'receipts' => (int) $receipts()->count(),
                'marketplace' => (int) $receipts()->where('return_receipts.type', ReturnReceipt::TYPE_MARKETPLACE)->count(),
                'units' => $units,
                'good' => $perProduct->sum('good'),
                'damaged' => $perProduct->sum('damaged'),
                'sold' => $soldTotal,
                'rate' => $soldTotal > 0 ? $units / $soldTotal * 100 : null,
                'foreign_receipts' => (int) ($foreign->receipts ?? 0),
                'foreign_units' => (int) ($foreign->units ?? 0),
            ],
        ];
    }

    /* ------------------------------------------------------------- stok --- */

    /** Kelompok umur stok: hari sejak barang terakhir terjual. */
    public const AGE_BUCKETS = [
        '0-30' => [0, 30, 'Laku ≤ 30 hari'],
        '31-60' => [31, 60, '31–60 hari'],
        '61-90' => [61, 90, '61–90 hari'],
        '90+' => [91, null, 'Lebih dari 90 hari'],
        'never' => [null, null, 'Belum pernah terjual'],
    ];

    /**
     * Posisi stok gudang dan umurnya: berapa lama sejak tiap barang
     * terakhir terjual. Stok yang lama diam adalah uang yang tertahan.
     *
     * @return array<string, mixed>
     */
    public function stockAging(?string $bucket = null): array
    {
        $products = Product::singles()
            ->where('stock', '>', 0)
            ->get(['id', 'sku', 'name', 'stock', 'damaged_stock', 'type', 'image_path', 'is_active', 'cost']);

        $lastOut = $this->lastOutgoing($products->pluck('id'));
        $today = now()->startOfDay();

        $rows = $products->map(function (Product $product) use ($lastOut, $today) {
            $last = isset($lastOut[$product->id]) ? Carbon::parse($lastOut[$product->id]) : null;
            $age = $last ? (int) $last->copy()->startOfDay()->diffInDays($today) : null;

            return (object) [
                'product' => $product,
                'stock' => (int) $product->stock,
                'cost' => $product->cost !== null ? (float) $product->cost : null,
                'value' => $product->cost !== null ? (float) $product->cost * $product->stock : null,
                'last_out' => $last,
                'age' => $age,
                'bucket' => $this->ageBucket($age),
            ];
        });

        $buckets = collect(self::AGE_BUCKETS)->map(fn (array $meta, string $key) => (object) [
            'key' => $key,
            'label' => $meta[2],
            'products' => $rows->where('bucket', $key)->count(),
            'units' => $rows->where('bucket', $key)->sum('stock'),
            'value' => (float) $rows->where('bucket', $key)->sum('value'),
        ]);

        return [
            'buckets' => $buckets,
            'rows' => $rows
                ->when($bucket && isset(self::AGE_BUCKETS[$bucket]), fn ($rows) => $rows->where('bucket', $bucket))
                ->sortBy([fn ($a, $b) => ($b->age ?? PHP_INT_MAX) <=> ($a->age ?? PHP_INT_MAX), fn ($a, $b) => $b->stock <=> $a->stock])
                ->values(),
            'totals' => [
                'products' => $rows->count(),
                'units' => $rows->sum('stock'),
                'damaged' => (int) Product::singles()->sum('damaged_stock'),
                'idle_units' => $rows->whereIn('bucket', ['61-90', '90+', 'never'])->sum('stock'),
                'value' => (float) $rows->sum('value'),
                'idle_value' => (float) $rows->whereIn('bucket', ['61-90', '90+', 'never'])->sum('value'),
                'missing_cost' => $rows->whereNull('cost')->count(),
            ],
        ];
    }

    protected function ageBucket(?int $age): string
    {
        if ($age === null) {
            return 'never';
        }

        foreach (self::AGE_BUCKETS as $key => [$min, $max]) {
            if ($min !== null && $age >= $min && ($max === null || $age <= $max)) {
                return $key;
            }
        }

        return '90+';
    }

    /* -------------------------------------------------------- kinerja tim --- */

    /**
     * Paket yang dikemas tiap orang di QC Kemas, digabung dengan kehadirannya
     * bila akun itu terhubung ke data karyawan.
     *
     * Yang dihitung adalah resi yang lolos verifikasi resi di QC Kemas pada
     * rentang itu, atas nama akun yang memindainya.
     *
     * @return array<string, mixed>
     */
    public function team(DateRange $range): array
    {
        $packed = DateRange::constrain(DB::table('outbounds'), 'outbounds.resi_verified_at', $range->from, $range->to)
            ->where('outbounds.type', Outbound::TYPE_MARKETPLACE)
            ->whereNotNull('outbounds.user_id')
            ->leftJoin('outbound_items as oi', 'oi.outbound_id', '=', 'outbounds.id')
            ->select('outbounds.user_id')
            ->selectRaw('COUNT(DISTINCT outbounds.id) as packages')
            ->selectRaw('COALESCE(SUM(oi.scanned_quantity), 0) as units')
            ->selectRaw('COUNT(DISTINCT DATE(outbounds.resi_verified_at)) as days')
            ->groupBy('outbounds.user_id')
            ->get()
            ->keyBy('user_id');

        $users = \App\Models\User::whereIn('id', $packed->keys())->with('employee.workShift')->get(['id', 'name'])->keyBy('id');

        $attendance = collect();
        $employees = $users->pluck('employee')->filter();

        if ($employees->isNotEmpty() && $range->from && $range->to) {
            $from = CarbonImmutable::parse($range->from);
            $to = CarbonImmutable::parse($range->to);

            // Rentang sangat panjang tidak perlu dihitung hari per hari.
            if ($from->diffInDays($to) <= 400) {
                $attendance = $this->attendance->range($employees, $from, $to)
                    ->map(fn (Collection $days) => $this->attendance->summarize($days));
            }
        }

        $rows = $packed->map(function ($row) use ($users, $attendance) {
            $user = $users->get($row->user_id);
            $employee = $user?->employee;
            $summary = $employee ? $attendance->get($employee->id) : null;
            $present = $summary['present'] ?? null;
            $basis = $present ?: (int) $row->days;

            return (object) [
                'name' => $employee?->name ?? $user?->name ?? 'Pengguna #'.$row->user_id,
                'user' => $user?->name,
                'packages' => (int) $row->packages,
                'units' => (int) $row->units,
                'days' => (int) $row->days,
                'present' => $present,
                'late' => $summary['late'] ?? null,
                'per_day' => $basis > 0 ? $row->packages / $basis : 0,
            ];
        })->sortByDesc('packages')->values();

        $daily = DateRange::constrain(DB::table('outbounds'), 'outbounds.resi_verified_at', $range->from, $range->to)
            ->where('outbounds.type', Outbound::TYPE_MARKETPLACE)
            ->selectRaw('DATE(outbounds.resi_verified_at) as day')
            ->selectRaw('COUNT(*) as packages')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        return [
            'rows' => $rows,
            'daily' => $daily,
            'totals' => [
                'people' => $rows->count(),
                'packages' => $rows->sum('packages'),
                'units' => $rows->sum('units'),
                'days' => $daily->count(),
                'per_day' => $daily->count() > 0 ? $daily->sum('packages') / $daily->count() : 0,
            ],
        ];
    }

    /* ---------------------------------------------------------- bantuan --- */

    /**
     * Saring ekspresi tanggal (bukan kolom polos) dengan batas inklusif.
     */
    protected function constrainExpression(QueryBuilder $query, string $expression, DateRange $range): QueryBuilder
    {
        return $query
            ->when($range->from, fn ($q, $from) => $q->whereRaw("{$expression} >= ?", [$from]))
            ->when($range->to, fn ($q, $to) => $q->whereRaw("{$expression} < ?", [Carbon::parse($to)->addDay()->toDateString()]));
    }

    protected function periodKey(string $day, string $per): string
    {
        $date = Carbon::parse($day);

        return match ($per) {
            'bulan' => $date->format('Y-m'),
            'minggu' => $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            default => $date->toDateString(),
        };
    }

    protected function periodLabel(string $key, string $per): string
    {
        return match ($per) {
            'bulan' => Carbon::parse($key.'-01')->translatedFormat('F Y'),
            'minggu' => Carbon::parse($key)->translatedFormat('d M').' – '.Carbon::parse($key)->addDays(6)->translatedFormat('d M Y'),
            default => Carbon::parse($key)->translatedFormat('D, d M Y'),
        };
    }

    /**
     * Pilihan marketplace dan toko untuk saringan.
     *
     * @return array{marketplaces: Collection<int, string>, stores: Collection<int, string>}
     */
    public function filterOptions(): array
    {
        return [
            'marketplaces' => ShipmentOrder::filterOptions('marketplace'),
            'stores' => ShipmentOrder::filterOptions('store_name'),
        ];
    }
}
