<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
        'oldest' => 'Terlama diunggah',
        'order_date_desc' => 'Tanggal pesanan terbaru',
        'order_date_asc' => 'Tanggal pesanan terlama',
    ];

    public const PER_PAGE = [20, 50, 100];

    /** Saringan yang dibawa kembali setelah tandai batal / cabut batal. */
    public const FILTER_KEYS = [
        'stage', 'search', 'search_by', 'marketplace', 'store', 'courier',
        'duplicate', 'unmatched', 'from', 'to', 'range', 'sort', 'per_page',
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

    public function index(Request $request): View
    {
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
        $distinct = fn (string $column) => ShipmentOrder::query()
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column);

        return view('admin.orders.index', [
            'orders' => $orders,
            'stage' => $stage,
            'onlyDuplicates' => $request->boolean('duplicate'),
            'counts' => $this->counts($request),
            'duplicates' => $this->filtered($request, withFlags: false)->duplicated()->count(),
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
     * Saringan yang berlaku atas daftar sekaligus atas angka tiap tab.
     *
     * Tab status sengaja tidak termasuk: tab itulah pemilihnya, jadi angkanya
     * tidak boleh ikut berubah saat tab lain dibuka.
     */
    protected function filtered(Request $request, bool $withFlags = true): Builder
    {
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
            ->dateBetween(DateRange::fromRequest($request))
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

    protected function sort(Builder $query, string $sort): void
    {
        match ($sort) {
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
            ShipmentOrder::STAGE_SHIPPED => 'Paket sudah discan di Serah Terima Paket (diserahkan ke kurir). Dokumen barang keluar disetujui dan stok sudah berkurang.',
            ShipmentOrder::STAGE_CANCELLED => 'Pesanan batal sebelum berangkat. Jangan dikirim.',
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
