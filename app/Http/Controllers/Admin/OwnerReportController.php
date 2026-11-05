<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OwnerReportService;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\Listing;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Owner: satu halaman, satu menu, isinya dipisah dengan tab.
 *
 * Owner tidak perlu enam menu laporan; ia perlu satu tempat untuk melihat
 * jualan, ketepatan kirim, barang laku dan tidak laku, retur, umur stok, dan
 * kinerja tim. Setiap tab hanya membaca data yang sudah ada.
 */
class OwnerReportController extends Controller implements HasMiddleware
{
    public const TABS = [
        'penjualan' => ['label' => 'Penjualan', 'icon' => 'chart'],
        'kirim' => ['label' => 'Ketepatan Kirim', 'icon' => 'clock'],
        'barang' => ['label' => 'Terlaris & Tidak Laku', 'icon' => 'box'],
        'retur' => ['label' => 'Retur', 'icon' => 'return'],
        'stok' => ['label' => 'Umur Stok', 'icon' => 'layers'],
        'tim' => ['label' => 'Kinerja Tim', 'icon' => 'users'],
    ];

    public const PERIODS = ['hari' => 'Per Hari', 'minggu' => 'Per Minggu', 'bulan' => 'Per Bulan'];

    public static function middleware(): array
    {
        return [
            new Middleware('can:owner-reports.view'),
        ];
    }

    public function __construct(protected OwnerReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $tab = $this->tab($request);
        $range = $this->range($request);

        return view('admin.reports.owner.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'range' => $range,
            'data' => $this->data($tab, $range, $request, paginate: true),
        ] + ($tab === 'penjualan' ? [
            'per' => $this->per($request),
            'periods' => self::PERIODS,
            'options' => $this->reports->filterOptions(),
        ] : []));
    }

    public function export(Request $request): StreamedResponse
    {
        $tab = $this->tab($request);
        $range = $this->range($request);
        $data = $this->data($tab, $range, $request, paginate: false);
        $note = 'Periode: '.$range->label();
        $money = fn ($value) => round((float) $value);

        $export = TableExport::make('Laporan Owner — '.self::TABS[$tab]['label'])->note($note);

        match ($tab) {
            'penjualan' => $export
                ->sheet('Per Periode', [
                    ['Periode', 'text'], ['Pesanan', 'sum'], ['Unit', 'sum'], ['Omzet (Rp)', 'sum'],
                    ['Batal', 'sum'], ['Penjualan Manual', 'sum'], ['Unit Manual', 'sum'],
                ], $data['periods']->map(fn ($r) => [$r->label, $r->orders, $r->units, $money($r->revenue), $r->cancelled, $r->manual_orders, $r->manual_units]))
                ->sheet('Per Toko', [
                    ['Marketplace', 'text'], ['Toko', 'text'], ['Pesanan', 'sum'], ['Unit', 'sum'], ['Omzet (Rp)', 'sum'],
                ], $data['stores']->map(fn ($r) => [$r->marketplace, $r->store, $r->orders, $r->units, $money($r->revenue)])),
            'kirim' => $export
                ->sheet('Per Hari', [
                    ['Batas Kirim', 'text'], ['Resi', 'sum'], ['Tepat Waktu', 'sum'], ['Telat Dikirim', 'sum'],
                    ['Lewat Batas, Belum Dikirim', 'sum'], ['Belum Jatuh Tempo', 'sum'],
                ], $data['days']->map(fn ($r) => [$r->label, $r->total, $r->on_time, $r->late, $r->overdue, $r->pending]))
                ->sheet('Resi Telat', [
                    ['Resi', 'text'], ['No. Pesanan', 'text'], ['Ekspedisi', 'text'], ['Jenis', 'text'],
                    ['Batas Kirim', 'datetime'], ['Dikirim', 'datetime'], ['Telat (jam)', 'number'],
                ], $data['late']->map(fn ($r) => [
                    $r->tracking_number, $r->order_number, $r->courier, $r->is_preorder ? 'PO' : 'Normal',
                    Carbon::parse($r->ship_by), $r->shipped_at ? Carbon::parse($r->shipped_at) : null, $r->late_hours,
                ])),
            'barang' => $export
                ->sheet('Terlaris', [
                    ['No', 'number'], ['SKU', 'text'], ['Nama Barang', 'text'], ['Unit Terjual', 'sum'],
                    ['Pesanan', 'sum'], ['Omzet (Rp)', 'sum'], ['Stok Sekarang', 'number'],
                ], $data['best']->values()->map(fn ($r, $i) => [$i + 1, $r->product->sku, $r->product->name, $r->units, $r->orders, $money($r->revenue), $r->product->stock]))
                ->sheet('Tidak Laku', [
                    ['SKU', 'text'], ['Nama Barang', 'text'], ['Stok', 'sum'], ['Terakhir Terjual', 'date'],
                ], $data['idle']->map(fn ($r) => [$r->product->sku, $r->product->name, $r->stock, $r->last_out])),
            'retur' => $export
                ->sheet('Per Barang', [
                    ['SKU', 'text'], ['Nama Barang', 'text'], ['Unit Retur', 'sum'], ['Kondisi Baik', 'sum'],
                    ['Rusak', 'sum'], ['Terjual', 'sum'], ['% Retur', 'number'],
                ], $data['products']->map(fn ($r) => [$r->product->sku, $r->product->name, $r->units, $r->good, $r->damaged, $r->sold, $r->rate !== null ? round($r->rate, 1) : null]))
                ->sheet('Alasan', [['Alasan', 'text'], ['Dokumen Retur', 'sum']], $data['reasons']->map(fn ($r) => [$r->reason, (int) $r->receipts])),
            'stok' => $export
                ->sheet('Umur Stok', [
                    ['SKU', 'text'], ['Nama Barang', 'text'], ['Stok', 'sum'], ['Terakhir Terjual', 'date'], ['Hari Sejak Terjual', 'number'],
                ], $data['rows']->map(fn ($r) => [$r->product->sku, $r->product->name, $r->stock, $r->last_out, $r->age])),
            'tim' => $export
                ->sheet('Per Orang', [
                    ['Nama', 'text'], ['Akun', 'text'], ['Paket Dikemas', 'sum'], ['Unit', 'sum'],
                    ['Hari Mengemas', 'number'], ['Hari Hadir', 'number'], ['Telat', 'number'], ['Paket per Hari', 'number'],
                ], $data['rows']->map(fn ($r) => [$r->name, $r->user, $r->packages, $r->units, $r->days, $r->present, $r->late, round($r->per_day, 1)])),
        };

        return $export->download('laporan-owner-'.$tab.'-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(string $tab, DateRange $range, Request $request, bool $paginate): array
    {
        $page = fn ($rows, string $pageName = 'page', array $sorts = [], ?array $default = null) => $paginate
            ? Listing::collection($rows, $request, $sorts, $default, pageName: $pageName)
            : $rows;

        switch ($tab) {
            case 'kirim':
                $data = $this->reports->shipping($range, $request->input('jenis'));
                $data['late'] = $page($data['late'], 'telat');

                return $data;

            case 'barang':
                return [
                    'best' => $page($this->reports->bestSellers($range), 'laris', [
                        'units' => 'units', 'orders' => 'orders', 'revenue' => 'revenue',
                    ], ['units', 'desc']),
                    'idle' => $page($this->reports->nonMoving($range), 'diam', [
                        'stock' => 'stock', 'last_out' => fn ($r) => $r->last_out?->timestamp ?? 0,
                    ], ['stock', 'desc']),
                ];

            case 'retur':
                $data = $this->reports->returns($range);
                $data['products'] = $page($data['products'], 'page', [
                    'units' => 'units', 'damaged' => 'damaged', 'rate' => fn ($r) => $r->rate ?? -1,
                ], ['units', 'desc']);

                return $data;

            case 'stok':
                $data = $this->reports->stockAging($request->input('umur'));
                $data['rows'] = $page($data['rows']);

                return $data;

            case 'tim':
                return $this->reports->team($range);

            default:
                return $this->reports->sales($range, $this->per($request), array_filter([
                    'marketplace' => $request->input('marketplace'),
                    'store' => $request->input('store'),
                ]));
        }
    }

    protected function tab(Request $request): string
    {
        $tab = (string) $request->input('tab', 'penjualan');

        return array_key_exists($tab, self::TABS) ? $tab : 'penjualan';
    }

    protected function per(Request $request): string
    {
        $per = (string) $request->input('per', 'hari');

        return array_key_exists($per, self::PERIODS) ? $per : 'hari';
    }

    /**
     * Bawaannya bulan berjalan, bukan hari ini: owner melihat tren, dan satu
     * hari terlalu sempit untuk itu. "Semua tanggal" tidak ditawarkan.
     */
    protected function range(Request $request): DateRange
    {
        if (! $request->filled('from') && ! $request->filled('to')) {
            return new DateRange(now()->startOfMonth()->toDateString(), now()->toDateString());
        }

        $range = DateRange::fromRequest($request);

        return $range->isAll()
            ? new DateRange(now()->startOfMonth()->toDateString(), now()->toDateString())
            : $range;
    }
}
