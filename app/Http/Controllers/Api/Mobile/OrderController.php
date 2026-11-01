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

        return response()->json([
            'date' => $date->toDateString(),
            'belum_dikemas' => ShipmentOrder::query()->awaitingQc()->count(),
            'siap_dikemas' => ShipmentOrder::query()->stockStatus(OrderPlanner::READY)->count(),
            'sudah_dikemas' => ShipmentOrder::query()->qualityChecked()->count(),
            'dikirim' => $this->shippedOn(ShipmentOrder::query(), $date)->count(),
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
            ->with([
                'items',
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
            'items',
            'outbound' => fn ($query) => $query
                ->withSum('items', 'quantity')
                ->withSum('items', 'scanned_quantity')
                ->with('items.product'),
        ]);

        return response()->json([
            'order' => $this->row($order) + [
                'buyer_note' => $order->buyer_note,
                'outbound_code' => $order->outbound?->code,
                'packed_items' => $order->outbound?->items->map(fn (OutboundItem $item) => [
                    'sku' => $item->product?->sku,
                    'name' => $item->product?->name,
                    'barcode' => $item->product?->barcode,
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
