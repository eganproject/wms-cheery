<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentOrder;
use App\Models\StockMovement;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class DashboardController extends Controller implements HasMiddleware
{
    /**
     * Izinnya sudah lama ada di matriks hak akses tetapi tidak pernah benar-
     * benar diperiksa, sehingga siapa pun yang bisa masuk melihat saldo stok,
     * barang menipis, dan mutasi terakhir — angka yang justru paling sensitif.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:dashboard.view'),
        ];
    }

    public function __invoke(Request $request): View
    {
        // Angka aktivitas mengikuti periode yang dipilih, bawaannya hari ini.
        // Angka posisi stok (unit, rusak, menipis) selalu keadaan saat ini.
        $range = DateRange::fromRequest($request);

        $stats = [
            /*
                Angka gudang dihitung atas barang yang benar-benar ada di rak.

                Paket bundling tidak punya saldo: menghitungnya sebagai jenis
                barang membuat "Jenis Barang" tidak lagi cocok dengan "Total
                Unit", dan memasukkannya ke stok menipis membuat tiap paket
                mendesak pemesanan barang yang memang tidak pernah dipesan.
            */
            'products' => Product::singles()->count(),
            'stock_units' => (int) Product::singles()->sum('stock'),
            'low_stock' => Product::lowStock()->count(),
            'damaged_units' => (int) Product::singles()->sum('damaged_stock'),
            // Draft milik pesanan batal bukan pengiriman yang menunggu:
            // termasuk dokumen yang batal setelah serah terima, yang kembali
            // ke draft dan disimpan selamanya sebagai jejak kartu stok.
            'outbound_pending' => Outbound::where('status', Outbound::STATUS_DRAFT)
                ->where('type', Outbound::TYPE_REGULAR)
                ->count(),
            'marketplace_pending' => Outbound::where('status', Outbound::STATUS_DRAFT)
                ->where('type', Outbound::TYPE_MARKETPLACE)
                ->notCancelled()
                ->count(),
        ] + $this->periodActivity($range);

        return view('admin.dashboard', [
            'stats' => $stats,
            'range' => $range,
            'periodLabel' => $this->periodLabel($range),
            'waybills' => $this->waybillStages($range),
            'backlog' => $range->from
                ? ShipmentOrder::awaitingQc()->where('shipment_orders.created_at', '<', $range->from)->count()
                : 0,
            'lowStockProducts' => Product::lowStock()->where('is_active', true)->orderBy('stock')->take(6)->get(),
            'movements' => StockMovement::with('product')->latest('id')->take(8)->get(),
            'couriers' => $this->ordersPerCourier($range),
            'pendingOutbounds' => Outbound::with('items')
                ->where('status', Outbound::STATUS_DRAFT)
                ->notCancelled()
                ->latest('id')
                ->take(5)
                ->get(),
        ]);
    }

    /**
     * Barang masuk dan keluar selama periode, dalam dokumen dan unit.
     *
     * Unit dibaca dari kartu stok supaya sama dengan Laporan Stok: barang
     * keluar dihitung bersih dari paket yang batal setelah serah terima.
     *
     * @return array<string, int>
     */
    protected function periodActivity(DateRange $range): array
    {
        $units = DateRange::constrain(StockMovement::query(), 'created_at', $range->from, $range->to)
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->whereIn('reference_type', [Inbound::class, Outbound::class])
            ->selectRaw("COALESCE(SUM(CASE WHEN reference_type = ? AND type = 'in' THEN quantity ELSE 0 END), 0) as inbound_units", [Inbound::class])
            ->selectRaw("COALESCE(SUM(CASE WHEN reference_type = ? THEN (CASE WHEN type = 'out' THEN quantity ELSE -quantity END) ELSE 0 END), 0) as outbound_units", [Outbound::class])
            ->toBase()
            ->first();

        return [
            'inbound_docs' => DateRange::constrain(Inbound::where('status', Inbound::STATUS_POSTED), 'posted_at', $range->from, $range->to)->count(),
            'outbound_docs' => DateRange::constrain(Outbound::where('status', Outbound::STATUS_POSTED), 'posted_at', $range->from, $range->to)->count(),
            'inbound_units' => (int) ($units->inbound_units ?? 0),
            'outbound_units' => (int) ($units->outbound_units ?? 0),
        ];
    }

    protected function periodLabel(DateRange $range): string
    {
        if ($range->isAll()) {
            return 'semua tanggal';
        }

        if ($range->isToday()) {
            return 'hari ini';
        }

        $format = fn (?string $date) => $date ? Carbon::parse($date)->translatedFormat('d M Y') : '…';

        return $range->from === $range->to
            ? $format($range->from)
            : $format($range->from).' – '.$format($range->to);
    }

    /**
     * Jumlah resi pada tiap tahap alur gudang.
     *
     * @return array<string, int>
     */
    protected function waybillStages(DateRange $range): array
    {
        // Resi yang diunggah dalam periode, sama seperti tab di halaman Pesanan.
        $orders = fn () => ShipmentOrder::query()->dateBetween($range);

        return [
            ShipmentOrder::STAGE_AWAITING_QC => $orders()->awaitingQc()->count(),
            ShipmentOrder::STAGE_HANDOVER => $orders()->qualityChecked()->count(),
            ShipmentOrder::STAGE_SHIPPED => $orders()->shipped()->count(),
            ShipmentOrder::STAGE_CANCELLED => $orders()->cancelled()->count(),
        ];
    }

    /**
     * Jumlah pesanan hasil import per ekspedisi, beserta berapa yang sudah
     * benar-benar dikirim, supaya sisa pekerjaan per kurir terlihat.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function ordersPerCourier(DateRange $range)
    {
        return ShipmentOrder::query()
            ->dateBetween($range)
            ->leftJoin('outbounds', function ($join) {
                $join->on('outbounds.shipment_order_id', '=', 'shipment_orders.id')
                    ->where('outbounds.status', Outbound::STATUS_POSTED);
            })
            ->selectRaw('shipment_orders.courier as courier')
            ->selectRaw('COUNT(DISTINCT shipment_orders.id) as total')
            ->selectRaw('COUNT(DISTINCT outbounds.id) as shipped')
            // Resi batal yang belum berangkat bukan pekerjaan yang menunggu.
            ->selectRaw('COUNT(DISTINCT CASE WHEN outbounds.id IS NULL AND shipment_orders.cancelled_at IS NOT NULL THEN shipment_orders.id END) as cancelled')
            ->groupBy('shipment_orders.courier')
            ->orderByDesc('total')
            ->get();
    }
}
