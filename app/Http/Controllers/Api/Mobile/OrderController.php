<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\OutboundItem;
use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use App\Services\OrderPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Daftar pesanan untuk aplikasi Android: belum dikemas, sudah dikemas
 * (menunggu serah terima), dan sudah dikirim.
 *
 * Tahapannya sama persis dengan tab di halaman Pesanan web (Belum QC, Sudah
 * QC, Dikirim) karena memakai scope yang sama.
 */
class OrderController extends Controller implements HasMiddleware
{
    /** Label antrean untuk resi yang kurirnya kosong. */
    public const NO_COURIER = 'Tanpa kurir';

    public const STAGES = [
        ShipmentOrder::STAGE_AWAITING_QC => 'Belum Dikemas',
        ShipmentOrder::STAGE_HANDOVER => 'Sudah Dikemas',
        ShipmentOrder::STAGE_SHIPPED => 'Dikirim',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    /**
     * Angka untuk layar utama aplikasi.
     */
    public function summary(Request $request, OrderPlanner $planner): JsonResponse
    {
        $planner->refresh();

        $date = $this->date($request);

        $shipped = $this->shippedOn(ShipmentOrder::query(), $date)->count();

        // Batas kirim hari ini: yang sudah dikirim hari ini ditambah yang masih
        // terbuka dan batasnya sampai hari ini (termasuk yang sudah lewat).
        $dueOpen = ShipmentOrder::query()->deadline('today')->count();

        return response()->json([
            'date' => $date->toDateString(),
            'belum_dikemas' => ShipmentOrder::query()->awaitingQc()->count(),
            'siap_dikemas' => ShipmentOrder::query()->stockStatus(OrderPlanner::READY)->count(),
            'menunggu_stok' => ShipmentOrder::query()->stockStatus(OrderPlanner::WAITING)->count(),
            'prioritas' => ShipmentOrder::query()->awaitingQc()->where('shipment_orders.is_priority', true)->count(),
            'sudah_dikemas' => ShipmentOrder::query()->qualityChecked()->count(),
            'dikirim' => $shipped,
            'batas_hari_ini_terbuka' => $dueOpen,
            'lewat_batas' => ShipmentOrder::query()->deadline('overdue')->count(),
            'dikirim_sejam_terakhir' => Outbound::query()
                ->where('type', Outbound::TYPE_MARKETPLACE)
                ->where('status', Outbound::STATUS_POSTED)
                ->where('posted_at', '>=', now()->subHour())
                ->count(),
            // Antrean serah terima per kurir, supaya petugas tahu paket kurir
            // mana yang harus disiapkan saat kurirnya datang.
            'antrean_per_kurir' => ShipmentOrder::query()->qualityChecked()
                ->toBase()
                ->selectRaw("COALESCE(NULLIF(courier, ''), ?) as courier_name, COUNT(*) as total", [self::NO_COURIER])
                ->groupByRaw("COALESCE(NULLIF(courier, ''), ?)", [self::NO_COURIER])
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => ['courier' => $row->courier_name, 'total' => (int) $row->total])
                ->values(),
        ]);
    }

    public function index(Request $request, OrderPlanner $planner): JsonResponse
    {
        $planner->refresh();

        $stage = array_key_exists((string) $request->input('stage'), self::STAGES)
            ? (string) $request->input('stage')
            : ShipmentOrder::STAGE_AWAITING_QC;
        $date = $this->date($request);
        $search = trim((string) $request->input('search'));

        $query = ShipmentOrder::query()
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            // Saringan cepat untuk tab Belum Dikemas.
            ->when($stage === ShipmentOrder::STAGE_AWAITING_QC && in_array($request->input('ready'), [OrderPlanner::READY, OrderPlanner::WAITING], true),
                fn (Builder $query) => $query->stockStatus((string) $request->input('ready')))
            ->when($stage === ShipmentOrder::STAGE_AWAITING_QC && $request->boolean('priority'),
                fn (Builder $query) => $query->where('shipment_orders.is_priority', true))
            ->when($stage === ShipmentOrder::STAGE_HANDOVER && $request->filled('courier'),
                fn (Builder $query) => $request->input('courier') === self::NO_COURIER
                    ? $query->where(fn (Builder $q) => $q->whereNull('courier')->orWhere('courier', ''))
                    : $query->where('courier', $request->string('courier')->value()))
            ->with([
                'items.product.group',
                'outbound' => fn ($query) => $query
                    ->withSum('items', 'quantity')
                    ->withSum('items', 'scanned_quantity'),
            ]);

        match ($stage) {
            ShipmentOrder::STAGE_SHIPPED => $this->shippedOn($query, $date)
                ->orderByDesc(Outbound::select('posted_at')
                    ->whereColumn('outbounds.shipment_order_id', 'shipment_orders.id')
                    ->where('status', Outbound::STATUS_POSTED)
                    ->latest('posted_at')
                    ->limit(1))
                ->orderByDesc('shipment_orders.id'),
            default => $query->atStage($stage)->mostUrgentFirst(),
        };

        $orders = $query->paginate(30)->withQueryString();

        return response()->json([
            'stage' => $stage,
            'date' => $date->toDateString(),
            'data' => collect($orders->items())->map(fn (ShipmentOrder $order) => $this->row($order))->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Rincian satu resi: isi pesanan dan berapa yang sudah discan per barang.
     */
    public function show(ShipmentOrder $order): JsonResponse
    {
        $order->load([
            'items.product.group',
            'outbound' => fn ($query) => $query
                ->withSum('items', 'quantity')
                ->withSum('items', 'scanned_quantity')
                ->with('items.product.group'),
        ]);

        return response()->json([
            'order' => $this->row($order) + [
                'buyer_note' => $order->buyer_note,
                'outbound_code' => $order->outbound?->code,
                'packed_items' => $order->outbound?->items->map(fn (OutboundItem $item) => [
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name,
                    'barcode' => $item->product?->barcode,
                    'image_url' => $item->product?->image_url,
                    'quantity' => (int) $item->quantity,
                    'scanned' => (int) $item->scanned_quantity,
                ])->values() ?? [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(ShipmentOrder $order): array
    {
        $deadline = $order->deadlineState();
        $stage = $order->stage();

        return [
            'id' => $order->id,
            'tracking_number' => $order->tracking_number,
            'order_number' => $order->order_number,
            'marketplace' => $order->marketplace,
            'store_name' => $order->store_name,
            'buyer_name' => $order->buyer_name,
            'courier' => $order->courier,
            'stage' => $stage,
            'stage_label' => self::STAGES[$stage] ?? $order->stageLabel(),
            'stage_detail' => $order->stageDetail(),
            'ready_label' => AdminOrderController::readyLabel($order),
            'kind' => AdminOrderController::orderKind($order),
            'is_priority' => (bool) $order->is_priority,
            'ship_by' => $order->ship_by?->toIso8601String(),
            'deadline_label' => $deadline[0] ?? null,
            'deadline_tone' => $deadline[1] ?? null,
            'total_quantity' => $order->totalQuantity(),
            'scanned_quantity' => $order->scannedUnits(),
            'shipped_at' => $order->outbound?->isPosted() ? $order->outbound->posted_at?->toIso8601String() : null,
            'items' => $order->items->map(fn (ShipmentOrderItem $item) => [
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'image_url' => $item->product?->image_url,
            ])->values(),
        ];
    }

    protected function shippedOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereHas('outbounds', fn (Builder $outbound) => $outbound
            ->where('status', Outbound::STATUS_POSTED)
            ->whereBetween('posted_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]));
    }

    protected function date(Request $request): Carbon
    {
        try {
            return $request->filled('date')
                ? Carbon::createFromFormat('Y-m-d', (string) $request->input('date'))->startOfDay()
                : today();
        } catch (\Throwable) {
            return today();
        }
    }
}
