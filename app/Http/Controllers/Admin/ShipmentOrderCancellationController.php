<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\ShipmentOrder;
use App\Services\ApprovalService;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Menandai resi sebagai dibatalkan pembeli, atau mencabutnya kembali.
 *
 * Pembatalan sebenarnya sudah terbaca sendiri dari berkas import, tetapi itu
 * baru berlaku setelah berkas terbaru diimport. Penjual biasanya tahu dari
 * aplikasi marketplace beberapa jam lebih awal — dan beberapa jam itu cukup
 * untuk memacking lalu mengirim paket yang seharusnya batal.
 */
class ShipmentOrderCancellationController extends Controller implements HasMiddleware
{
    public function __construct(protected StockService $stock, protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.cancel'),
        ];
    }

    public function store(Request $request, ShipmentOrder $order): RedirectResponse
    {
        $reason = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:255'],
        ], [], ['cancellation_reason' => 'alasan pembatalan'])['cancellation_reason'];

        $order->load('outbound.items');

        // Paket yang sudah dikemas atau sudah diserahkan ke kurir hanya bisa
        // dibatalkan pada hari QC kemasnya. Lewat dari itu, pengembaliannya
        // dicatat lewat penerimaan retur.
        if ($blocker = $order->cancellationBlocker()) {
            return $this->backToStatus($request)->with('error', "Resi {$order->tracking_number} tidak bisa dibatalkan. {$blocker}");
        }

        $outbound = $order->outbound;
        $shipped = (bool) $outbound?->isPosted();

        try {
            DB::transaction(function () use ($order, $outbound, $request, $reason) {
                if ($outbound?->isPosted()) {
                    // Sudah serah terima hari ini: stoknya dikembalikan.
                    $this->stock->reverseOutbound(
                        $outbound,
                        "Batal kirim {$outbound->code} · resi {$order->tracking_number} dibatalkan: {$reason}",
                    );
                } elseif ($outbound?->isPending()) {
                    // Jangan sampai pengajuannya disetujui setelah pesanannya batal.
                    $this->approvals->withdraw($outbound);
                }

                $order->forceFill([
                    'cancelled_at' => now(),
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $reason,
                ])->save();
            });
        } catch (ValidationException $exception) {
            return $this->backToStatus($request)->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return $this->backToStatus($request)->with('success', $shipped
            ? "Resi {$order->tracking_number} dibatalkan dan stok {$outbound->items->sum('quantity')} unit dikembalikan. Ambil kembali paketnya dari kurir dan kembalikan barangnya ke rak."
            : "Resi {$order->tracking_number} ditandai batal. Paketnya tidak bisa lagi discan maupun dikirim.");
    }

    public function destroy(Request $request, ShipmentOrder $order): RedirectResponse
    {
        $order->forceFill([
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ])->save();

        return $this->backToStatus($request)->with(
            'success',
            $order->outbound?->wasReversed() && $order->outbound->isDraft()
                ? "Pembatalan resi {$order->tracking_number} dicabut. Paketnya kembali ke antrean Serah Terima Paket; scan lagi di sana saat diserahkan ke kurir."
                : "Pembatalan resi {$order->tracking_number} dicabut. Paketnya bisa dipacking seperti biasa.",
        );
    }

    /**
     * Kembali ke halaman Pesanan dengan saringan yang tadi dipakai.
     *
     * Sama seperti antrean serah terima dan kotak masuk persetujuan: `back()`
     * membaca header Referer yang tidak selalu ada, dan cadangannya di sesi
     * tidak pernah diperbarui pada navigasi AJAX.
     */
    protected function backToStatus(Request $request): RedirectResponse
    {
        return redirect()->route('admin.orders.index', array_filter(
            $request->only(OrderController::FILTER_KEYS),
            fn ($value) => filled($value),
        ));
    }
}
