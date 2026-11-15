<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FabricMovement;
use App\Models\FabricRoll;
use App\Models\Product;
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
 * Scan QR roll → kain dan sisa roll langsung tampil → pilih barang yang
 * dibuat dari kain itu → ambil semua atau isi jumlahnya → simpan. Mode "Cek" dipakai saat mengukur ulang roll: sisa
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
            new Middleware('can:fabrics.issue', only: ['issue', 'products']),
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
        ]);
    }

    /** Data roll dari hasil scan. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120']]);
        // 422 (bukan 404) supaya pesannya sampai apa adanya di aplikasi Android.
        $roll = $this->rollOrFail($data['code']);

        return response()->json([
            'roll' => self::rollData($roll) + ['products' => $this->usageProducts($roll)],
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'product_id.required' => 'Pilih barang yang dibuat dari kain ini.',
            'product_id.exists' => 'Barang yang dipilih tidak ditemukan.',
            'quantity.required_unless' => 'Isi jumlah kain yang keluar, atau pilih ambil semua.',
            'quantity.gt' => 'Jumlah kain keluar harus lebih dari 0.',
        ], ['quantity' => 'jumlah kain keluar', 'product_id' => 'barang']);

        $roll = $this->rollOrFail($data['code']);
        $movement = $stock->issue(
            $roll,
            ($data['all'] ?? false) ? null : (float) $data['quantity'],
            Product::findOrFail($data['product_id']),
            filled($data['note'] ?? null) ? trim($data['note']) : null,
            $request->user(),
        );

        $roll->refresh()->load('fabric');
        $fabric = $roll->fabric;

        return response()->json([
            'message' => "Keluar {$fabric->format(-$movement->quantity)} dari {$roll->code} untuk {$movement->product->sku}"
                .($roll->isEmpty() ? ' · roll habis' : ' · sisa '.$fabric->format($roll->quantity)),
            'roll' => self::rollData($roll),
            'movement' => self::movementData($movement->load(['roll.fabric', 'product'])),
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

    /**
     * Cari barang untuk kain keluar (SKU, nama, atau barcode). Barang yang
     * memakai kain roll ini (bila ?code= diisi) tampil paling atas.
     */
    public function products(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->value();
        $roll = $request->filled('code') ? FabricRoll::findByScan((string) $request->input('code')) : null;

        $products = Product::query()
            ->with('group')
            ->where('is_active', true)
            ->search($term)
            ->when($roll, fn ($query) => $query->orderByRaw(
                'CASE WHEN id IN (SELECT product_id FROM fabric_usages WHERE fabric_id = ?) THEN 0 ELSE 1 END',
                [$roll->fabric_id],
            ))
            ->orderBy('sku')
            ->limit(30)
            ->get();

        return response()->json(['products' => $products->map(fn (Product $product) => self::productData($product))->values()]);
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
            ->with(['roll.fabric', 'product'])
            ->where('user_id', $request->user()?->id)
            ->where('moved_at', '>=', today())
            ->latest('moved_at')->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (FabricMovement $movement) => self::movementData($movement))
            ->all();
    }

    /**
     * Barang yang memakai kain roll ini (dari Pemakaian Kain per Barang),
     * untuk pilihan cepat saat kain keluar.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function usageProducts(FabricRoll $roll): array
    {
        return Product::query()
            ->with('group')
            ->whereIn('id', fn ($query) => $query->select('product_id')->from('fabric_usages')->where('fabric_id', $roll->fabric_id))
            ->where('is_active', true)
            ->orderBy('sku')
            ->limit(100)
            ->get()
            ->map(fn (Product $product) => self::productData($product))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'image_url' => $product->image_url,
        ];
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
            'image_url' => $fabric->image_url,
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
            'image_url' => $fabric->image_url,
            'quantity' => -$movement->quantity,
            'quantity_label' => $fabric->format(-$movement->quantity),
            'balance_label' => $fabric->format($movement->balance_after),
            'product_id' => $movement->product_id,
            'product_sku' => $movement->product?->sku,
            'product_name' => $movement->product?->name,
            'destination' => $movement->productLabel(),
            'time' => $movement->moved_at->format('H:i'),
            'cancelled' => $movement->isCancelled(),
        ];
    }
}
