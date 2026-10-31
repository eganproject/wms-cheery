<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Ubah batas kirim satu resi secara manual, atau kembalikan ke hitungan
 * sistem. Batas manual tidak ditimpa saat lama PO berubah.
 */
class ShipmentOrderDeadlineController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.create'),
        ];
    }

    public function update(Request $request, ShipmentOrder $order, OrderPlanner $planner): RedirectResponse
    {
        $data = $request->validate([
            'ship_by' => ['required', 'date'],
        ], [], ['ship_by' => 'batas kirim']);

        $order->forceFill([
            'ship_by' => Carbon::parse($data['ship_by'])->startOfMinute(),
            'ship_by_source' => OrderPlanner::SOURCE_MANUAL,
            'ship_by_set_by' => $request->user()?->id,
            'ship_by_set_at' => now(),
        ])->save();

        $planner->refresh();

        return back()->with('success', "Batas kirim resi {$order->tracking_number} diubah ke {$order->ship_by->translatedFormat('d M Y H:i')}.");
    }

    public function destroy(Request $request, ShipmentOrder $order, OrderPlanner $planner): RedirectResponse
    {
        $order->forceFill([
            'ship_by' => $order->computed_ship_by,
            'ship_by_source' => null,
            'ship_by_set_by' => null,
            'ship_by_set_at' => null,
        ])->save();

        $planner->refresh();

        return back()->with('success', "Batas kirim resi {$order->tracking_number} kembali mengikuti hitungan sistem.");
    }
}
