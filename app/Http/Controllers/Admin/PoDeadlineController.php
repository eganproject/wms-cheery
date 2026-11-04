<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Tenggat PO: berapa resi yang masih harus diproses, dikelompokkan menurut
 * batas kirimnya (lewat batas, hari ini, besok, dan seterusnya), supaya tim
 * tahu mana yang dikerjakan lebih dulu. Hanya membaca.
 *
 * "Harus diproses" = belum batal dan belum dikirim (Belum QC + Sudah QC).
 * Batas kirimnya sama dengan kolom batas kirim di Pesanan (ship_by).
 */
class PoDeadlineController extends Controller implements HasMiddleware
{
    /** Kelompok batas kirim, dari yang paling mendesak. */
    public const BUCKETS = [
        'overdue' => 'Lewat batas',
        'today' => 'Hari ini',
        'tomorrow' => 'Besok',
        'soon' => '2–3 hari lagi',
        'week' => '4–7 hari lagi',
        'later' => 'Lebih dari 7 hari',
        'missing' => 'Belum ada batas',
    ];

    public const KINDS = [
        'po' => 'Pre Order',
        'semua' => 'Semua resi',
    ];

    public const STAGES = [
        '' => 'Semua belum dikirim',
        ShipmentOrder::STAGE_AWAITING_QC => 'Belum QC',
        ShipmentOrder::STAGE_HANDOVER => 'Sudah QC',
    ];

    /** Status resi di halaman ini: kesiapan stok untuk Belum QC, atau Sudah QC. */
    public const DONE = 'qc';

    public const PER_PAGE = [25, 50, 100];

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    public function index(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();

        $now = now();
        $kind = array_key_exists((string) $request->input('jenis'), self::KINDS) ? (string) $request->input('jenis') : 'po';
        $stage = array_key_exists((string) $request->input('tahap'), self::STAGES) ? (string) $request->input('tahap') : '';
        $bucket = array_key_exists((string) $request->input('kelompok'), self::BUCKETS) ? (string) $request->input('kelompok') : '';
        $date = $this->date($request);
        $poDays = $request->filled('lama') && ctype_digit((string) $request->input('lama')) ? (int) $request->input('lama') : null;
        $view = $request->input('tampilan') === 'daftar' ? 'daftar' : 'kalender';
        $month = $this->month($request, $date ?? $now);
        $perPage = in_array($request->integer('per_page'), self::PER_PAGE, true) ? $request->integer('per_page') : self::PER_PAGE[0];

        [$bucketSql, $bucketBindings] = $this->bucketSql($now);
        $base = fn () => $this->base($request, $kind, $stage);

        // 1. Ringkasan per kelompok batas kirim.
        $summary = $base()->toBase()
            ->selectRaw($bucketSql.' AS bucket', $bucketBindings)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status = 'siap' THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status IN ('menunggu', 'sku') THEN 1 ELSE 0 END) AS waiting")
            ->selectRaw('SUM(CASE WHEN shipment_orders.stock_status IS NULL THEN 1 ELSE 0 END) AS done')
            ->selectRaw('SUM(CASE WHEN shipment_orders.is_priority = 1 THEN 1 ELSE 0 END) AS priority')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $buckets = collect(self::BUCKETS)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'total' => (int) ($summary[$key]->total ?? 0),
            'ready' => (int) ($summary[$key]->ready ?? 0),
            'waiting' => (int) ($summary[$key]->waiting ?? 0),
            'done' => (int) ($summary[$key]->done ?? 0),
            'priority' => (int) ($summary[$key]->priority ?? 0),
        ]);

        // 2. Per tanggal batas kirim.
        $days = $base()->toBase()
            ->whereNotNull('shipment_orders.ship_by')
            ->selectRaw('DATE(shipment_orders.ship_by) AS day')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status = 'siap' THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status IN ('menunggu', 'sku') THEN 1 ELSE 0 END) AS waiting")
            ->selectRaw('SUM(CASE WHEN shipment_orders.stock_status IS NULL THEN 1 ELSE 0 END) AS done')
            ->selectRaw('SUM(CASE WHEN shipment_orders.ship_by < ? THEN 1 ELSE 0 END) AS overdue', [$now->toDateTimeString()])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => Carbon::parse($row->day),
                'total' => (int) $row->total,
                'ready' => (int) $row->ready,
                'waiting' => (int) $row->waiting,
                'done' => (int) $row->done,
                'overdue' => (int) $row->overdue,
            ]);

        // 3. Per lama PO (lead time).
        $leadTimes = $base()->toBase()
            ->selectRaw('shipment_orders.po_days AS po_days', [])
            ->selectRaw($bucketSql.' AS bucket', $bucketBindings)
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('po_days', 'bucket')
            ->get()
            ->groupBy(fn ($row) => $row->po_days === null ? '' : (string) (int) $row->po_days)
            ->map(fn ($rows, $key) => [
                'po_days' => $key === '' ? null : (int) $key,
                'total' => (int) $rows->sum('total'),
                'buckets' => $rows->mapWithKeys(fn ($row) => [$row->bucket => (int) $row->total])->all(),
            ])
            ->sortBy(fn ($row) => $row['po_days'] ?? PHP_INT_MAX)
            ->values();

        // 4. Daftar resi sesuai kelompok / tanggal / lama PO yang dipilih.
        $orders = $base()
            ->with('items:id,shipment_order_id,sku,quantity,product_name')
            ->when($bucket !== '', fn (Builder $query) => $query->whereRaw($bucketSql.' = ?', [...$bucketBindings, $bucket]))
            ->when($date, fn (Builder $query) => $query->whereBetween('shipment_orders.ship_by', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]))
            ->when($request->filled('lama'), fn (Builder $query) => $poDays === null
                ? $query->whereNull('shipment_orders.po_days')
                : $query->where('shipment_orders.po_days', $poDays))
            ->mostUrgentFirst()
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.orders.deadlines', [
            'now' => $now,
            'kind' => $kind,
            'stage' => $stage,
            'bucket' => $bucket,
            'date' => $date,
            'poDays' => $request->filled('lama') ? $poDays : false,
            'buckets' => $buckets,
            'days' => $days,
            'leadTimes' => $leadTimes,
            'orders' => $orders,
            'total' => $buckets->sum('total'),
            'stores' => ShipmentOrder::filterOptions('store_name'),
            'couriers' => ShipmentOrder::filterOptions('courier'),
            'perPage' => $perPage,
            'view' => $view,
            'month' => $month,
        ]);
    }

    public static function status(ShipmentOrder $order): string
    {
        return match ($order->stock_status) {
            OrderPlanner::READY => 'Siap dikemas',
            OrderPlanner::WAITING => 'Menunggu stok',
            OrderPlanner::UNKNOWN_SKU => 'SKU tidak dikenal',
            default => 'Sudah QC',
        };
    }

    public static function statusVariant(ShipmentOrder $order): string
    {
        return match ($order->stock_status) {
            OrderPlanner::READY => 'success',
            OrderPlanner::WAITING => 'warning',
            OrderPlanner::UNKNOWN_SKU => 'danger',
            default => 'info',
        };
    }

    /** "lewat 3 jam", "sisa 5 jam", "2 hari lagi". */
    public static function relative(?Carbon $shipBy, Carbon $now): string
    {
        if (! $shipBy) {
            return '';
        }

        $minutes = (int) abs($now->diffInMinutes($shipBy));
        $span = match (true) {
            $minutes < 60 => max(1, $minutes).' menit',
            $minutes < 60 * 24 => intdiv($minutes, 60).' jam',
            default => intdiv($minutes, 60 * 24).' hari',
        };

        return $shipBy->lt($now) ? 'lewat '.$span : 'sisa '.$span;
    }

    protected function base(Request $request, string $kind, string $stage): Builder
    {
        return ShipmentOrder::query()
            ->open()
            ->atStage($stage)
            ->when($kind === 'po', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('shipment_orders.is_preorder', true)
                ->orWhere('shipment_orders.po_days', '>', 0)))
            ->when($request->filled('store'), fn (Builder $query) => $query->where('shipment_orders.store_name', $request->string('store')))
            ->when($request->filled('courier'), fn (Builder $query) => $query->where('shipment_orders.courier', $request->string('courier')));
    }

    /**
     * Ekspresi SQL yang memberi setiap resi satu kelompok batas kirim.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function bucketSql(Carbon $now): array
    {
        $today = $now->copy()->endOfDay();

        return ['(CASE'
            .' WHEN shipment_orders.ship_by IS NULL THEN \'missing\''
            .' WHEN shipment_orders.ship_by < ? THEN \'overdue\''
            .' WHEN shipment_orders.ship_by <= ? THEN \'today\''
            .' WHEN shipment_orders.ship_by <= ? THEN \'tomorrow\''
            .' WHEN shipment_orders.ship_by <= ? THEN \'soon\''
            .' WHEN shipment_orders.ship_by <= ? THEN \'week\''
            .' ELSE \'later\' END)', [
                $now->toDateTimeString(),
                $today->toDateTimeString(),
                $today->copy()->addDay()->toDateTimeString(),
                $today->copy()->addDays(3)->toDateTimeString(),
                $today->copy()->addDays(7)->toDateTimeString(),
            ]];
    }

    /** Bulan kalender: ?bulan=YYYY-MM, atau bulan tanggal yang dipilih / bulan ini. */
    protected function month(Request $request, Carbon $fallback): Carbon
    {
        $value = (string) $request->input('bulan');

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match) && (int) $match[2] >= 1 && (int) $match[2] <= 12) {
            return Carbon::create((int) $match[1], (int) $match[2], 1)->startOfDay();
        }

        return $fallback->copy()->startOfMonth()->startOfDay();
    }

    protected function date(Request $request): ?Carbon
    {
        $value = (string) $request->input('tanggal');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
