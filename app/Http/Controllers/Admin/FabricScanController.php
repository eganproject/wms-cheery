<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FabricMovement;
use App\Models\FabricRoll;
use App\Services\FabricStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Scan Kain: satu-satunya jalan kain keluar dari stok.
 *
 * Scan QR roll → kain dan sisa roll langsung tampil → pilih ambil semua atau
 * isi jumlahnya → simpan. Mode "Cek" dipakai saat mengukur ulang roll: sisa
 * hasil ukur menggantikan sisa tercatat dan selisihnya dicatat sebagai
 * koreksi.
 *
 * Endpoint-nya JSON dan dipakai juga oleh aplikasi Android, jadi aturan dan
 * pesannya sama persis di web dan di HP.
 */
class FabricScanController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['show', 'lookup', 'recent']),
            new Middleware('can:fabrics.issue', only: ['issue']),
            new Middleware('can:fabrics.adjust', only: ['adjust']),
        ];
    }

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('admin.fabric-scan.show', [
            'canIssue' => $user->can('fabrics.issue'),
            'canAdjust' => $user->can('fabrics.adjust'),
            'mode' => $request->input('mode') === 'cek' && $user->can('fabrics.adjust') ? 'cek' : 'keluar',
            'recent' => $this->recentList($request),
            'destinations' => $this->destinations(),
        ]);
    }

    /** Data roll dari hasil scan. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120']]);
        $roll = FabricRoll::findByScan($data['code']);

        if (! $roll) {
            return response()->json([
                'message' => "Kode \"{$data['code']}\" bukan roll kain yang terdaftar.",
            ], 404);
        }

        return response()->json([
            'roll' => self::rollData($roll),
            'message' => $roll->isEmpty()
                ? "Roll {$roll->code} sudah habis."
                : "{$roll->fabric->displayName()} · sisa {$roll->fabric->format($roll->quantity)}",
        ]);
    }

    public function issue(Request $request, FabricStockService $stock): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'all' => ['nullable', 'boolean'],
            'quantity' => ['nullable', 'required_unless:all,1,true', 'numeric', 'gt:0', 'max:999999'],
            'destination' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'quantity.required_unless' => 'Isi jumlah kain yang keluar, atau pilih ambil semua.',
            'quantity.gt' => 'Jumlah kain keluar harus lebih dari 0.',
        ], ['quantity' => 'jumlah kain keluar']);

        $roll = $this->rollOrFail($data['code']);
        $movement = $stock->issue(
            $roll,
            ($data['all'] ?? false) ? null : (float) $data['quantity'],
            filled($data['destination'] ?? null) ? trim($data['destination']) : null,
            filled($data['note'] ?? null) ? trim($data['note']) : null,
            $request->user(),
        );

        $roll->refresh()->load('fabric');
        $fabric = $roll->fabric;

        return response()->json([
            'message' => "Keluar {$fabric->format(-$movement->quantity)} dari {$roll->code}"
                .($roll->isEmpty() ? ' · roll habis' : ' · sisa '.$fabric->format($roll->quantity)),
            'roll' => self::rollData($roll),
            'movement' => self::movementData($movement->load('roll.fabric')),
            'recent' => $this->recentList($request),
        ]);
    }

    public function adjust(Request $request, FabricStockService $stock): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'actual' => ['required', 'numeric', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['actual' => 'sisa hasil cek']);

        $roll = $this->rollOrFail($data['code']);
        $before = $roll->quantity;
        $movement = $stock->adjust($roll, (float) $data['actual'], filled($data['note'] ?? null) ? trim($data['note']) : null, $request->user());
        $roll->refresh()->load('fabric');
        $fabric = $roll->fabric;

        return response()->json([
            'message' => $movement
                ? "Sisa {$roll->code} dikoreksi {$fabric->format($before)} → {$fabric->format($roll->quantity)}"
                : "Sisa {$roll->code} cocok: {$fabric->format($roll->quantity)}",
            'changed' => $movement !== null,
            'roll' => self::rollData($roll),
        ]);
    }

    /** Kain keluar terakhir oleh pengguna ini hari ini. */
    public function recent(Request $request): JsonResponse
    {
        return response()->json(['recent' => $this->recentList($request)]);
    }

    protected function rollOrFail(string $code): FabricRoll
    {
        $roll = FabricRoll::findByScan($code);

        if (! $roll) {
            throw ValidationException::withMessages(['code' => "Kode \"{$code}\" bukan roll kain yang terdaftar."]);
        }

        return $roll;
    }

    /** @return array<int, array<string, mixed>> */
    protected function recentList(Request $request): array
    {
        return FabricMovement::query()
            ->issues()
            ->with('roll.fabric')
            ->where('user_id', $request->user()?->id)
            ->where('moved_at', '>=', today())
            ->latest('moved_at')->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (FabricMovement $movement) => self::movementData($movement))
            ->all();
    }

    /**
     * Tujuan yang pernah dipakai, untuk saran isian.
     *
     * @return array<int, string>
     */
    protected function destinations(): array
    {
        return FabricMovement::query()
            ->issues()
            ->whereNotNull('destination')
            ->where('moved_at', '>=', now()->subDays(90))
            ->selectRaw('destination, MAX(moved_at) AS last_used')
            ->groupBy('destination')
            ->orderByDesc('last_used')
            ->limit(30)
            ->pluck('destination')
            ->all();
    }

    /** @return array<string, mixed> */
    public static function rollData(FabricRoll $roll): array
    {
        $fabric = $roll->fabric;

        return [
            'id' => $roll->id,
            'code' => $roll->code,
            'sku' => $fabric->sku,
            'name' => $fabric->name,
            'color' => $fabric->color,
            'unit' => $fabric->unitShort(),
            'unit_name' => $fabric->unit,
            'initial' => $roll->initial_quantity,
            'quantity' => $roll->quantity,
            'quantity_label' => $fabric->format($roll->quantity),
            'initial_label' => $fabric->format($roll->initial_quantity),
            'empty' => $roll->isEmpty(),
            'lot' => $roll->lot,
            'location' => $roll->location,
            'fabric_url' => route('admin.fabrics.show', $fabric),
        ];
    }

    /** @return array<string, mixed> */
    public static function movementData(FabricMovement $movement): array
    {
        $fabric = $movement->roll->fabric;

        return [
            'id' => $movement->id,
            'code' => $movement->roll->code,
            'sku' => $fabric->sku,
            'name' => $fabric->displayName(),
            'quantity' => -$movement->quantity,
            'quantity_label' => $fabric->format(-$movement->quantity),
            'balance_label' => $fabric->format($movement->balance_after),
            'destination' => $movement->destination,
            'time' => $movement->moved_at->format('H:i'),
            'cancelled' => $movement->isCancelled(),
        ];
    }
}
