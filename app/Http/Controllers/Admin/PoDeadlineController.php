<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
use App\Support\Listing;
use App\Support\PackingList;
use App\Support\ProductionPlan;
use App\Services\TableExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\Product;
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
        $perPage = Listing::perPage($request);

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
            ->tap(fn (Builder $query) => Listing::sort($query, $request, [
                'resi' => 'shipment_orders.tracking_number',
                'lama' => 'shipment_orders.po_days',
                'dibayar' => 'shipment_orders.paid_at',
                'batas' => fn (Builder $query, string $direction) => $direction === 'asc'
                    ? $query->mostUrgentFirst()
                    : $query->orderByDesc('shipment_orders.ship_by')->orderByDesc('shipment_orders.id'),
            ], ['batas', 'asc']))
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

    /**
     * Rincian satu tanggal batas kirim: barang dan jumlah yang harus
     * dikemas, stok gudang, sebaran toko / kurir / lama PO, dan resinya.
     */
    public function day(Request $request, OrderPlanner $planner, string $date): View
    {
        $planner->refresh();

        [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $date));
        abort_unless(checkdate($month, $dayOfMonth, $year), 404);
        $day = Carbon::create($year, $month, $dayOfMonth)->startOfDay();

        $now = now();
        $kind = array_key_exists((string) $request->input('jenis'), self::KINDS) ? (string) $request->input('jenis') : 'po';
        $stage = array_key_exists((string) $request->input('tahap'), self::STAGES) ? (string) $request->input('tahap') : '';
        $base = fn () => $this->base($request, $kind, $stage)
            ->whereBetween('shipment_orders.ship_by', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);

        $summary = $base()->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status = 'siap' THEN 1 ELSE 0 END) AS ready")
            ->selectRaw("SUM(CASE WHEN shipment_orders.stock_status IN ('menunggu', 'sku') THEN 1 ELSE 0 END) AS waiting")
            ->selectRaw('SUM(CASE WHEN shipment_orders.stock_status IS NULL THEN 1 ELSE 0 END) AS done')
            ->selectRaw('SUM(CASE WHEN shipment_orders.ship_by < ? THEN 1 ELSE 0 END) AS overdue', [$now->toDateTimeString()])
            ->selectRaw('SUM(CASE WHEN shipment_orders.is_priority = 1 THEN 1 ELSE 0 END) AS priority')
            ->first();

        // Barang di rak (paket dipecah jadi isinya), dengan stok gudang saat ini.
        $mode = $request->input('per') === PackingList::BY_SKU ? PackingList::BY_SKU : PackingList::BY_PRODUCT;
        $list = PackingList::for($base(), $mode);
        $stocks = Product::query()
            ->whereIn('id', $list->rows->pluck('product_id')->filter()->unique())
            ->pluck('stock', 'id');
        $products = $list->rows
            ->map(fn (array $row) => $row + [
                'stock' => $row['product_id'] !== null && $stocks->has($row['product_id']) ? (int) $stocks[$row['product_id']] : null,
            ])
            ->sortByDesc('remaining')
            ->values();

        $spread = fn (string $column) => $base()->toBase()
            ->selectRaw("COALESCE(NULLIF(shipment_orders.{$column}, ''), '—') AS label, COUNT(*) AS total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->get();

        $leadTimes = $base()->toBase()
            ->selectRaw('shipment_orders.po_days AS po_days, COUNT(*) AS total')
            ->groupBy('po_days')
            ->get()
            ->sortBy(fn ($row) => $row->po_days ?? PHP_INT_MAX)
            ->values();

        $orders = $base()
            ->with('items:id,shipment_order_id,sku,quantity,product_name')
            ->mostUrgentFirst()
            ->tap(fn (Builder $query) => Listing::sort($query, $request, [
                'resi' => 'shipment_orders.tracking_number',
                'lama' => 'shipment_orders.po_days',
                'dibayar' => 'shipment_orders.paid_at',
                'batas' => 'shipment_orders.ship_by',
            ], ['batas', 'asc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.orders.deadline-day', [
            'day' => $day,
            'now' => $now,
            'kind' => $kind,
            'stage' => $stage,
            'mode' => $mode,
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'ready' => (int) ($summary->ready ?? 0),
                'waiting' => (int) ($summary->waiting ?? 0),
                'done' => (int) ($summary->done ?? 0),
                'overdue' => (int) ($summary->overdue ?? 0),
                'priority' => (int) ($summary->priority ?? 0),
            ],
            'totals' => $list->totals,
            'products' => $products,
            'stores' => $spread('store_name'),
            'couriers' => $spread('courier'),
            'leadTimes' => $leadTimes,
            'orders' => $orders,
        ]);
    }

    /**
     * Rencana produksi: gabungan kebutuhan barang untuk tanggal-tanggal batas
     * kirim yang dicentang, dengan stok dan kekurangannya.
     */
    public function production(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();

        return view('admin.orders.deadline-production', $this->plan($request) + ['now' => now()]);
    }

    public function productionExcel(Request $request, OrderPlanner $planner): StreamedResponse
    {
        $planner->refresh();
        $data = $this->plan($request);
        /** @var ProductionPlan $plan */
        $plan = $data['plan'];
        $days = $plan->dates->map(fn (Carbon $date) => $date->format('Y-m-d'));

        $export = TableExport::make('Rencana Produksi PO')
            ->note($data['description'])
            ->note(sprintf('%s resi · %s barang · butuh %s unit · tertutup stok %s · harus diproduksi %s',
                $plan->totals['orders'], $plan->totals['products'], $plan->totals['needed'], $plan->totals['covered'], $plan->totals['short']))
            ->note('Stok dipakai lebih dulu oleh resi yang batas kirimnya lebih awal, termasuk tanggal yang tidak dipilih.');

        $export->sheet('Rencana Produksi', array_merge(
            [['No', 'number'], ['SKU', 'text'], ['Nama Barang', 'text']],
            $plan->dates->map(fn (Carbon $date) => [$date->translatedFormat('d M'), 'sum'])->all(),
            [['Total Dibutuhkan', 'sum'], ['Stok Gudang', 'number'], ['Dipakai Tanggal Lain', 'sum'], ['Tertutup Stok', 'sum'], ['Harus Diproduksi', 'sum'], ['Keterangan', 'text']],
        ), $plan->rows->map(fn (array $row, int $index) => array_merge(
            [$index + 1, $row['sku'], $row['name']],
            $days->map(fn (string $day) => $row['days'][$day] ?? 0)->all(),
            [$row['needed'], $row['stock'], $row['earlier'], $row['covered'], $row['short'], self::planRemark($row)],
        )));

        $export->sheet('Kekurangan per Tanggal', array_merge(
            [['SKU', 'text'], ['Nama Barang', 'text']],
            $plan->dates->map(fn (Carbon $date) => [$date->translatedFormat('d M'), 'sum'])->all(),
            [['Total', 'sum']],
        ), $plan->rows->where('short', '>', 0)->values()->map(fn (array $row) => array_merge(
            [$row['sku'], $row['name']],
            $days->map(fn (string $day) => $row['short_days'][$day] ?? 0)->all(),
            [$row['short']],
        )));

        return $export->download('rencana-produksi-'.$this->planFilename($plan).'.xlsx');
    }

    public function productionPdf(Request $request, OrderPlanner $planner): Response
    {
        $planner->refresh();
        $data = $this->plan($request);

        return Pdf::loadView('admin.orders.deadline-production-pdf', $data)
            ->setPaper('a4', $data['plan']->dates->count() > 5 ? 'landscape' : 'portrait')
            ->download('rencana-produksi-'.$this->planFilename($data['plan']).'.pdf');
    }

    public static function planRemark(array $row): string
    {
        return collect([
            $row['matched'] ? null : 'SKU belum terdaftar',
            $row['from_bundle'] ? 'Termasuk isi paket' : null,
            $row['earlier_short'] > 0 ? 'Tanggal lain lebih awal juga kurang '.$row['earlier_short'] : null,
        ])->filter()->implode(', ');
    }

    /** @return array<string, mixed> */
    protected function plan(Request $request): array
    {
        $kind = array_key_exists((string) $request->input('jenis'), self::KINDS) ? (string) $request->input('jenis') : 'po';
        $stage = array_key_exists((string) $request->input('tahap'), self::STAGES) ? (string) $request->input('tahap') : '';
        $mode = $request->input('per') === PackingList::BY_SKU ? PackingList::BY_SKU : PackingList::BY_PRODUCT;
        $dates = ProductionPlan::datesFrom($request->input('tanggal'), $request->input('dari'), $request->input('sampai'));
        $plan = ProductionPlan::for(fn () => $this->base($request, $kind, $stage), $dates, $mode);

        $description = collect([
            'Jenis: '.self::KINDS[$kind],
            $stage !== '' ? 'Tahap: '.self::STAGES[$stage] : null,
            $request->filled('store') ? 'Toko: '.$request->string('store') : null,
            $request->filled('courier') ? 'Kurir: '.$request->string('courier') : null,
            'Ringkas per: '.($mode === PackingList::BY_SKU ? 'SKU di resi' : 'barang (paket dipecah)'),
            'Tanggal batas kirim: '.($dates->isEmpty() ? '—' : self::describeDates($dates)),
        ])->filter()->implode(' · ');

        return [
            'plan' => $plan,
            'kind' => $kind,
            'stage' => $stage,
            'mode' => $mode,
            'description' => $description,
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, Carbon>  $dates */
    public static function describeDates($dates): string
    {
        $first = $dates->first();
        $last = $dates->last();
        $consecutive = $dates->count() > 2 && (int) $first->diffInDays($last) === $dates->count() - 1;

        if ($consecutive) {
            return $first->translatedFormat('d M').' – '.$last->translatedFormat('d M Y').' ('.$dates->count().' hari)';
        }

        return $dates->map(fn (Carbon $date) => $date->translatedFormat('d M'))->implode(', ').' '.$last->format('Y');
    }

    protected function planFilename(ProductionPlan $plan): string
    {
        return $plan->dates->isEmpty() ? now()->format('Y-m-d')
            : $plan->dates->first()->format('Y-m-d').($plan->dates->count() > 1 ? '-sd-'.$plan->dates->last()->format('Y-m-d') : '');
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
