<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
use App\Services\TikTokImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * "Harus dikirim menurut TikTok".
 *
 * Berkas eksport TikTok tidak memuat batas kirim, jadi sistem menghitungnya
 * sendiri dari lama PO. Bila hitungan itu meleset (lama PO di sistem tidak
 * sama dengan setelan di TikTok), pesanan bisa terlambat tanpa ada yang tahu.
 *
 * Di sini admin mengunggah pesanan yang di TikTok diurutkan "prioritas
 * pengiriman" lalu dicentang sebagai yang harus dikirim hari ini. Sistem:
 * 1. mengimport resi yang belum ada (sama seperti Import Resi),
 * 2. mengunci batas kirim resi-resi itu ke hari ini dan menandainya prioritas,
 * 3. melaporkan resi yang menurut hitungan sistem belum mendesak (setelan
 *    lama PO-nya perlu dibetulkan) dan resi yang di sistem sudah batal atau
 *    sudah dikirim.
 */
class OrderPriorityController extends Controller implements HasMiddleware
{
    /** Batas baris per daftar di laporan, supaya sesi tidak membengkak. */
    protected const LIST_LIMIT = 300;

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view', only: ['create']),
            new Middleware('can:imports.create', only: ['store']),
        ];
    }

    public function create(OrderPlanner $planner): View
    {
        $planner->refresh();

        $locked = ShipmentOrder::query()
            ->where('ship_by_source', OrderPlanner::SOURCE_TIKTOK)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('outbounds', fn ($query) => $query->where('status', Outbound::STATUS_POSTED));

        return view('admin.orders.priority', [
            'result' => session('priority_result'),
            'openLocked' => (clone $locked)->count(),
            'openMismatch' => (clone $locked)->differsFromTikTok()->count(),
        ]);
    }

    public function store(Request $request, TikTokImportService $importer, OrderPlanner $planner): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:20480'],
        ], [
            'file.extensions' => 'Berkas harus berakhiran .xlsx, .xls, atau .csv — hasil eksport langsung dari TikTok Shop.',
            'file.max' => 'Ukuran berkas melebihi 20 MB.',
        ], ['file' => 'berkas']);

        // Resi yang belum ada ikut masuk; yang sudah ada dilewati.
        $import = $importer->import($request->file('file'));
        $seen = $importer->lastSeen;
        $deadline = Carbon::today()->endOfDay();
        $userId = $request->user()?->id;

        $orders = collect();

        foreach (['tracking_number' => $seen['tracking'], 'order_number' => $seen['orders']] as $column => $values) {
            foreach (array_chunk($values, 1000) as $chunk) {
                $orders = $orders->concat(ShipmentOrder::query()
                    ->with(['items:id,shipment_order_id,sku,quantity', 'outbound'])
                    ->whereIn($column, $chunk)
                    ->get());
            }
        }

        $orders = $orders->unique('id')->values();

        $marked = 0;
        $mismatch = [];
        $closed = [];
        $foundOrders = $orders->pluck('order_number')->filter()->unique();

        foreach ($orders as $order) {
            $stage = $order->stage();

            if (in_array($stage, [ShipmentOrder::STAGE_SHIPPED, ShipmentOrder::STAGE_CANCELLED], true)) {
                $closed[] = $this->row($order, $stage === ShipmentOrder::STAGE_SHIPPED
                    ? 'Sudah dikirim di sistem'
                    : 'Dibatalkan di sistem, tetapi TikTok masih meminta dikirim');

                continue;
            }

            $computed = $order->computed_ship_by;

            if ($computed === null || $computed->gt($deadline)) {
                $mismatch[] = $this->row($order, $computed
                    ? 'Hitungan sistem: '.$computed->translatedFormat('d M Y H:i').($order->po_days ? " (PO {$order->po_days} hari)" : '')
                    : 'Sistem belum punya batas kirim (lama PO belum diisi)');
            }

            $order->forceFill([
                'ship_by' => $order->ship_by && $order->ship_by->lt($deadline) ? $order->ship_by : $deadline,
                'ship_by_source' => OrderPlanner::SOURCE_TIKTOK,
                'ship_by_set_by' => $userId,
                'ship_by_set_at' => now(),
            ])->save();

            $marked++;
        }

        // Pesanan di berkas yang sama sekali tidak ada di sistem: biasanya
        // belum Atur Pengiriman, sehingga resinya belum terbit.
        $absent = collect($seen['orders'])->diff($foundOrders)->values();

        $planner->refresh();

        return redirect()->route('admin.orders.priority')->with('priority_result', [
            'filename' => $request->file('file')->getClientOriginalName(),
            'import_id' => $import->id,
            'imported' => $import->order_count,
            'marked' => $marked,
            'deadline' => $deadline->translatedFormat('d M Y'),
            'mismatch' => array_slice($mismatch, 0, self::LIST_LIMIT),
            'mismatch_count' => count($mismatch),
            'closed' => array_slice($closed, 0, self::LIST_LIMIT),
            'closed_count' => count($closed),
            'absent' => $absent->take(self::LIST_LIMIT)->all(),
            'absent_count' => $absent->count(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    protected function row(ShipmentOrder $order, string $note): array
    {
        return [
            'tracking' => $order->tracking_number,
            'order' => $order->order_number,
            'skus' => $order->items->map(fn ($item) => $item->sku.' ×'.$item->quantity)->implode(', '),
            'paid' => ($order->paid_at ?? $order->order_date)?->translatedFormat('d M Y H:i'),
            'note' => $note,
        ];
    }
}
