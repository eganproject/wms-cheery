<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use App\Services\OrderPlanner;
use App\Services\TableExport;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar pesanan marketplace, bergaya halaman Pesanan wmsxshopee.
 *
 * Tab statusnya hanya tahapan proses internal gudang (Belum QC, Sudah QC,
 * Dikirim, Dibatalkan) — dihitung dari dokumen gudang. Status dari
 * marketplace tidak dijadikan tab. Halaman ini menggantikan Status Resi,
 * termasuk penanda resi kembar dan pembatalan resi.
 *
 * Satu-satunya aksi yang mengubah data di sini adalah tandai batal / cabut
 * batal (ShipmentOrderCancellationController), sama seperti di Status Resi dulu.
 */
class OrderController extends Controller implements HasMiddleware
{
    /** Kolom yang bisa dipilih untuk pencarian. */
    public const SEARCH_FIELDS = [
        '' => 'Semua kolom',
        'tracking_number' => 'No. Resi',
        'order_number' => 'No. Pesanan',
        'buyer_name' => 'Nama Pembeli',
        'sku' => 'SKU',
    ];

    public const SORTS = [
        '' => 'Terbaru diunggah',
        'deadline' => 'Batas kirim terdekat',
        'oldest' => 'Terlama diunggah',
        'order_date_desc' => 'Tanggal pesanan terbaru',
        'order_date_asc' => 'Tanggal pesanan terlama',
    ];

    public const PER_PAGE = [20, 50, 100];

    /** Saringan yang dibawa kembali setelah tandai batal / cabut batal. */
    public const FILTER_KEYS = [
        'stage', 'search', 'search_by', 'marketplace', 'store', 'courier',
        'duplicate', 'unmatched', 'from', 'to', 'range', 'sort', 'per_page',
        'ready', 'deadline',
    ];

    /** Saringan kesiapan stok (resi Belum QC). */
    public const READY_FILTERS = [
        OrderPlanner::READY => 'Siap dikemas',
        OrderPlanner::WAITING => 'Menunggu stok',
    ];

    /** Saringan batas kirim (resi yang belum dikirim). */
    public const DEADLINE_FILTERS = [
        'overdue' => 'Lewat batas',
        'today' => 'Sampai hari ini',
        'tomorrow' => 'Sampai besok',
        'priority' => 'Prioritas',
        'missing' => 'Belum ada batas kirim',
        'mismatch' => 'Beda dengan TikTok',
    ];

    /**
     * Nama tab di halaman Pesanan. "Sudah QC" adalah tahap serah terima:
     * paket lolos QC kemas dan menunggu discan di Serah Terima Paket.
     */
    public const STAGE_LABELS = [
        ShipmentOrder::STAGE_AWAITING_QC => 'Belum QC',
        ShipmentOrder::STAGE_HANDOVER => 'Sudah QC',
        ShipmentOrder::STAGE_SHIPPED => 'Dikirim',
        ShipmentOrder::STAGE_CANCELLED => 'Dibatalkan',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    public function index(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();

        // `stage` dipakai sejak halaman ini menggantikan Status Resi; `status`
        // tetap diterima supaya tautan lama tidak rusak.
        $stage = (string) ($request->input('stage') ?? $request->input('status'));
        $stage = ShipmentOrder::LEGACY_STAGES[$stage] ?? $stage;
        $stage = array_key_exists($stage, ShipmentOrder::STAGES) ? $stage : '';
        $perPage = in_array($request->integer('per_page'), self::PER_PAGE, true) ? $request->integer('per_page') : self::PER_PAGE[0];

        $orders = $this->filtered($request)
            ->with([
                'items',
                'import',
                'returns:id,shipment_order_id,code,status',
                'outbound' => fn ($query) => $query
                    ->withSum('items', 'quantity')
                    ->withSum('items', 'scanned_quantity'),
            ])
            ->atStage($stage)
            ->tap(fn (Builder $query) => $this->sort($query, (string) $request->string('sort')))
            ->paginate($perPage)
            ->withQueryString();

        // Pilihan marketplace, toko, dan kurir dihitung dari seluruh data,
        // bukan hanya yang lolos saringan: memilih yang lain harus tetap mungkin.
        $distinct = fn (string $column) => ShipmentOrder::filterOptions($column);

        return view('admin.orders.index', [
            'orders' => $orders,
            'stage' => $stage,
            'onlyDuplicates' => $request->boolean('duplicate'),
            'counts' => $this->counts($request),
            'duplicates' => $this->filtered($request, withFlags: false)->duplicated()->count(),
            'planCounts' => $this->planCounts($request),
            'explicitDate' => $this->explicitDate($request),
            'unmatched' => $this->filtered($request, withFlags: false)->whereHas('items', fn (Builder $items) => $items->whereNull('product_id'))->count(),
            'unknownSkus' => $this->unknownSkus($request),
            'twins' => ShipmentOrder::twinsFor($orders->items()),
            'marketplaces' => $distinct('marketplace'),
            'stores' => $distinct('store_name'),
            'couriers' => $distinct('courier'),
            'searchTerms' => count($this->terms($request)),
            'perPage' => $perPage,
        ]);
    }

    /**
     * Excel pesanan sesuai tab, saringan, dan urutan yang sedang aktif.
     * Lembar "Pesanan" satu baris per resi; "Detail Barang" satu baris per
     * SKU per resi, untuk diolah ulang di spreadsheet.
     */
    public function export(Request $request, OrderPlanner $planner): StreamedResponse
    {
        $planner->refresh();

        $stage = (string) ($request->input('stage') ?? $request->input('status'));
        $stage = ShipmentOrder::LEGACY_STAGES[$stage] ?? $stage;
        $stage = array_key_exists($stage, ShipmentOrder::STAGES) ? $stage : '';

        $query = fn () => $this->filtered($request)
            ->atStage($stage)
            ->tap(fn (Builder $query) => $this->sort($query, (string) $request->string('sort')));

        $orders = function () use ($query) {
            $number = 0;

            foreach ($query()->with([
                'items',
                'returns:id,shipment_order_id,code,status',
                'outbound' => fn ($query) => $query
                    ->withSum('items', 'quantity')
                    ->withSum('items', 'scanned_quantity'),
            ])->lazy(500) as $order) {
                yield [
                    ++$number,
                    $order->tracking_number,
                    $order->order_number,
                    $order->order_date,
                    $order->created_at,
                    $order->marketplace,
                    $order->store_name,
                    $order->buyer_name,
                    $order->courier,
                    $order->shipping_method,
                    $order->ship_by,
                    self::orderKind($order),
                    $order->is_priority ? 'Ya' : '',
                    self::readyLabel($order),
                    self::STAGE_LABELS[$order->stage()] ?? $order->stageLabel(),
                    $order->stageDetail(),
                    $order->order_status,
                    $order->items->count(),
                    $order->totalQuantity(),
                    $order->items->map(fn ($item) => $item->sku.' x'.$item->quantity)->implode(', '),
                    $order->outbound?->code,
                    $order->cancellation_reason,
                    $order->buyer_note,
                ];
            }
        };

        $items = function () use ($query) {
            foreach ($query()->with('items')->lazy(500) as $order) {
                foreach ($order->items as $item) {
                    yield [
                        $order->tracking_number,
                        $order->order_number,
                        $order->order_date,
                        $order->marketplace,
                        $order->store_name,
                        $order->courier,
                        $item->sku,
                        $item->product_name,
                        (int) $item->quantity,
                        $item->product_id ? 'Ya' : 'Belum',
                    ];
                }
            }
        };

        $notes = collect([
            'Tahap: '.(self::STAGE_LABELS[$stage] ?? 'Semua'),
            'Tanggal unggah: '.($this->dateApplies($request) ? DateRange::fromRequest($request)->label() : 'semua'),
            $request->filled('marketplace') ? 'Marketplace: '.$request->string('marketplace') : null,
            $request->filled('store') ? 'Toko: '.$request->string('store') : null,
            $request->filled('courier') ? 'Kurir: '.$request->string('courier') : null,
            $request->boolean('duplicate') ? 'Hanya resi kembar' : null,
            $request->boolean('unmatched') ? 'Hanya SKU belum terdaftar' : null,
            isset(self::READY_FILTERS[(string) $request->input('ready')]) ? self::READY_FILTERS[(string) $request->input('ready')] : null,
            isset(self::DEADLINE_FILTERS[(string) $request->input('deadline')]) ? 'Batas kirim: '.mb_strtolower(self::DEADLINE_FILTERS[(string) $request->input('deadline')]) : null,
            count($this->terms($request)) ? 'Cari: '.Str::limit(implode(', ', $this->terms($request)), 80) : null,
        ])->filter()->implode(' · ');

        return TableExport::make('Daftar Pesanan')
            ->note($notes)
            ->sheet('Pesanan', [
                ['No', 'number'],
                ['No. Resi', 'text'],
                ['No. Pesanan', 'text'],
                ['Tanggal Pesanan', 'datetime'],
                ['Diunggah', 'datetime'],
                ['Marketplace', 'text'],
                ['Toko', 'text'],
                ['Pembeli', 'text'],
                ['Kurir', 'text'],
                ['Layanan', 'text'],
                ['Batas Kirim', 'datetime'],
                ['Jenis', 'text'],
                ['Prioritas', 'text'],
                ['Kesiapan Stok', 'text'],
                ['Tahap', 'text'],
                ['Keterangan Tahap', 'text'],
                ['Status Marketplace', 'text'],
                ['Jumlah SKU', 'number'],
                ['Total Qty', 'sum'],
                ['Isi Pesanan', 'text'],
                ['Dokumen Pengiriman', 'text'],
                ['Alasan Batal', 'text'],
                ['Catatan Pembeli', 'text'],
            ], $orders())
            ->sheet('Detail Barang', [
                ['No. Resi', 'text'],
                ['No. Pesanan', 'text'],
                ['Tanggal Pesanan', 'datetime'],
                ['Marketplace', 'text'],
                ['Toko', 'text'],
                ['Kurir', 'text'],
                ['SKU', 'text'],
                ['Nama Barang', 'text'],
                ['Qty', 'sum'],
                ['Terdaftar di Master', 'text'],
            ], $items())
            ->download('pesanan-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    /**
     * Saringan yang berlaku atas daftar sekaligus atas angka tiap tab.
     *
     * Tab status sengaja tidak termasuk: tab itulah pemilihnya, jadi angkanya
     * tidak boleh ikut berubah saat tab lain dibuka.
     */
    protected function filtered(Request $request, bool $withFlags = true, ?bool $applyDate = null): Builder
    {
        $applyDate ??= $this->dateApplies($request);

        $field = array_key_exists((string) $request->input('search_by'), self::SEARCH_FIELDS)
            ? (string) $request->input('search_by')
            : '';
        $terms = $this->terms($request);

        return ShipmentOrder::query()
            ->when(count($terms) > 1, fn (Builder $query) => $this->matchMany($query, $field, $terms))
            ->when(count($terms) === 1, fn (Builder $query) => $this->matchOne($query, $field, $terms[0]))
            ->when($request->filled('marketplace'), fn (Builder $query) => $query->where('marketplace', $request->string('marketplace')))
            ->when($request->filled('store'), fn (Builder $query) => $query->where('store_name', $request->string('store')))
            ->when($request->filled('courier'), fn (Builder $query) => $query->where('courier', $request->string('courier')))
            ->when($applyDate, fn (Builder $query) => $query->dateBetween(DateRange::fromRequest($request)))
            ->when($withFlags && $request->filled('ready'), fn (Builder $query) => $query->stockStatus((string) $request->input('ready')))
            ->when($withFlags && $request->filled('deadline'), fn (Builder $query) => $query->deadline((string) $request->input('deadline')))
            ->when($withFlags && $request->boolean('duplicate'), fn (Builder $query) => $query->duplicated())
            ->when($withFlags && $request->boolean('unmatched'),
                fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->whereNull('product_id')));
    }

    /**
     * SKU di resi yang tidak dikenal sistem: bukan barang satuan maupun paket
     * bundling di master barang. Dihitung untuk saringan yang berlaku (tanpa
     * tab), supaya peringatannya sama di tab mana pun.
     *
     * @return \Illuminate\Support\Collection<int, object{sku: string, product_name: ?string, orders: int, units: int}>
     */
    protected function unknownSkus(Request $request)
    {
        return ShipmentOrderItem::query()
            ->whereNull('product_id')
            ->whereIn('shipment_order_id', $this->filtered($request, withFlags: false)->select('shipment_orders.id'))
            ->selectRaw('sku, MAX(product_name) as product_name, COUNT(DISTINCT shipment_order_id) as orders, SUM(quantity) as units')
            ->groupBy('sku')
            ->orderByDesc('orders')
            ->orderBy('sku')
            ->toBase()
            ->get();
    }

    /**
     * Kata kunci pencarian. Banyak nomor sekaligus bisa ditempel, satu per
     * baris (atau dipisah koma), lalu dicocokkan persis.
     *
     * @return array<int, string>
     */
    protected function terms(Request $request): array
    {
        $raw = trim((string) $request->input('search'));

        if ($raw === '') {
            return [];
        }

        if (! preg_match('/[\r\n,;]/', $raw)) {
            return [$raw];
        }

        return collect(preg_split('/[\r\n,;]+/', $raw))
            ->map(fn ($term) => trim($term))
            ->filter()
            ->unique()
            ->take(500)
            ->values()
            ->all();
    }

    protected function matchOne(Builder $query, string $field, string $term): Builder
    {
        return match ($field) {
            'sku' => $query->whereHas('items', fn (Builder $items) => $items->where('sku', 'like', "%{$term}%")),
            '' => $query->search($term),
            default => $query->where($field, 'like', "%{$term}%"),
        };
    }

    /**
     * @param  array<int, string>  $terms
     */
    protected function matchMany(Builder $query, string $field, array $terms): Builder
    {
        return match ($field) {
            'sku' => $query->whereHas('items', fn (Builder $items) => $items->whereIn('sku', $terms)),
            '' => $query->where(fn (Builder $query) => $query
                ->whereIn('tracking_number', $terms)
                ->orWhereIn('order_number', $terms)
                ->orWhereHas('items', fn (Builder $items) => $items->whereIn('sku', $terms))),
            default => $query->whereIn($field, $terms),
        };
    }

    /**
     * Tanggal unggah dipilih sendiri oleh pengguna (bukan bawaan "hari ini").
     */
    protected function explicitDate(Request $request): bool
    {
        return $request->hasAny(['from', 'to', 'range']);
    }

    /**
     * Saringan kesiapan stok dan batas kirim mencari di seluruh resi yang
     * belum dikirim. Bawaan "diunggah hari ini" justru menyembunyikan resi PO
     * lama yang paling mendesak, jadi ia hanya berlaku bila dipilih sendiri.
     */
    protected function dateApplies(Request $request): bool
    {
        $planFilter = isset(self::READY_FILTERS[(string) $request->input('ready')])
            || isset(self::DEADLINE_FILTERS[(string) $request->input('deadline')]);

        return $this->explicitDate($request) || ! $planFilter;
    }

    /**
     * Angka pada penanda kesiapan dan batas kirim. Tanpa tanggal yang dipilih
     * sendiri, dihitung atas seluruh resi (sama dengan isi daftarnya saat
     * penanda diklik).
     *
     * @return array<string, int>
     */
    protected function planCounts(Request $request): array
    {
        // Satu query: di antara resi yang belum dikirim, stock_status hanya
        // terisi untuk resi Belum QC, jadi semua angka bisa dijumlah sekaligus.
        $now = now();
        $row = $this->filtered($request, withFlags: false, applyDate: $this->explicitDate($request))
            ->open()
            ->toBase()
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN shipment_orders.stock_status = 'siap' THEN 1 ELSE 0 END) AS ready_siap",
                "SUM(CASE WHEN shipment_orders.stock_status = 'menunggu' THEN 1 ELSE 0 END) AS ready_menunggu",
                'SUM(CASE WHEN shipment_orders.ship_by < ? THEN 1 ELSE 0 END) AS deadline_overdue',
                'SUM(CASE WHEN shipment_orders.ship_by <= ? THEN 1 ELSE 0 END) AS deadline_today',
                'SUM(CASE WHEN shipment_orders.ship_by <= ? THEN 1 ELSE 0 END) AS deadline_tomorrow',
                'SUM(CASE WHEN shipment_orders.is_priority = 1 THEN 1 ELSE 0 END) AS deadline_priority',
                'SUM(CASE WHEN shipment_orders.ship_by IS NULL THEN 1 ELSE 0 END) AS deadline_missing',
                "SUM(CASE WHEN shipment_orders.ship_by_source = 'tiktok' AND (shipment_orders.computed_ship_by IS NULL OR shipment_orders.computed_ship_by > shipment_orders.ship_by) THEN 1 ELSE 0 END) AS deadline_mismatch",
            ]), [
                $now->toDateTimeString(),
                $now->copy()->endOfDay()->toDateTimeString(),
                $now->copy()->addDay()->endOfDay()->toDateTimeString(),
            ])
            ->first();

        $counts = [];

        foreach (array_keys(self::READY_FILTERS) as $key) {
            $counts['ready.'.$key] = (int) ($row->{'ready_'.$key} ?? 0);
        }

        foreach (array_keys(self::DEADLINE_FILTERS) as $key) {
            $counts['deadline.'.$key] = (int) ($row->{'deadline_'.$key} ?? 0);
        }

        return $counts;
    }

    public static function orderKind(ShipmentOrder $order): string
    {
        return match (true) {
            $order->po_days > 0 => 'PO '.$order->po_days.' hari',
            $order->is_preorder === false => 'Normal',
            $order->po_days === 0 => 'PO · siap stok',
            (bool) $order->is_preorder => 'PO',
            default => '',
        };
    }

    public static function readyLabel(ShipmentOrder $order): string
    {
        if ($order->stage() !== ShipmentOrder::STAGE_AWAITING_QC) {
            return '';
        }

        return match ($order->stock_status) {
            OrderPlanner::READY => 'Siap dikemas',
            OrderPlanner::WAITING => 'Menunggu stok'.($order->stock_note ? ' ('.$order->stock_note.')' : ''),
            OrderPlanner::UNKNOWN_SKU => 'SKU tidak dikenal',
            default => '',
        };
    }

    protected function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'deadline' => $query->mostUrgentFirst(),
            'oldest' => $query->orderBy('shipment_orders.created_at')->orderBy('shipment_orders.id'),
            'order_date_desc' => $query->orderByDesc('order_date')->orderByDesc('shipment_orders.id'),
            'order_date_asc' => $query->orderBy('order_date')->orderBy('shipment_orders.id'),
            default => $query->latestFirst(),
        };
    }

    /**
     * Jumlah pesanan per tahap proses internal, untuk saringan yang berlaku.
     *
     * @return array<string, int>
     */
    protected function counts(Request $request): array
    {
        return collect(self::STAGE_LABELS)
            ->keys()
            ->mapWithKeys(fn (string $stage) => [$stage => $this->filtered($request)->atStage($stage)->count()])
            ->all();
    }

    /**
     * Penjelasan singkat tiap tab, ditampilkan sebagai tooltip.
     *
     * @return array<string, string>
     */
    public static function stageHints(): array
    {
        return [
            ShipmentOrder::STAGE_AWAITING_QC => 'Resi sudah diimport tapi resi atau barangnya belum tuntas discan di QC Kemas.',
            ShipmentOrder::STAGE_HANDOVER => 'QC kemas selesai: resi dan semua barang sudah discan. Paket menunggu discan di Serah Terima Paket saat diserahkan ke kurir.',
            ShipmentOrder::STAGE_SHIPPED => 'Paket sudah discan di Serah Terima Paket (diserahkan ke kurir). Dokumen pengiriman disetujui dan stok sudah berkurang.',
            ShipmentOrder::STAGE_CANCELLED => 'Pesanan batal. Jangan dikirim. Paket yang sudah dikemas atau sudah serah terima hanya bisa dibatalkan pada hari yang sama dengan QC kemasnya; stok paket yang sudah serah terima dikembalikan otomatis.',
        ];
    }

    /**
     * Langkah berikutnya yang disarankan untuk tiap tab.
     *
     * @return array<string, string>
     */
    public static function stageNext(): array
    {
        return [
            ShipmentOrder::STAGE_AWAITING_QC => 'Scan resi dan barangnya di QC Kemas.',
            ShipmentOrder::STAGE_HANDOVER => 'Serahkan paket ke kurir dari menu Serah Terima Paket.',
        ];
    }

    public static function stageTone(string $stage): string
    {
        return match ($stage) {
            ShipmentOrder::STAGE_AWAITING_QC => 'warning',
            ShipmentOrder::STAGE_HANDOVER => 'info',
            ShipmentOrder::STAGE_SHIPPED => 'success',
            ShipmentOrder::STAGE_CANCELLED => 'danger',
            default => 'neutral',
        };
    }

    public static function marketplaceKey(?string $marketplace): string
    {
        return Str::of((string) $marketplace)->lower()->contains('tiktok') ? 'tiktok'
            : (Str::of((string) $marketplace)->lower()->contains('shopee') ? 'shopee'
            : (Str::of((string) $marketplace)->lower()->contains(['tokopedia', 'tokped']) ? 'tokopedia'
            : (Str::of((string) $marketplace)->lower()->contains('lazada') ? 'lazada' : 'other')));
    }
}
