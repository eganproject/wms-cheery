<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesApiParameters;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Support\StockMovementSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mutasi stok lewat API: data dan saringan yang sama dengan halaman
 * Stok → Mutasi Stok, ditambah halaman (page/per_page) dan ringkasan.
 */
class StockMovementController extends Controller
{
    use ParsesApiParameters;

    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->paging($request);
        if ($page === false || $perPage === false) {
            return $this->bad('page dan per_page harus berupa integer positif; per_page maksimum 500.');
        }

        $from = $this->day($request->input('from'));
        $to = $this->day($request->input('to'));
        if ($from === false || $to === false) {
            return $this->bad('from dan to harus berupa tanggal YYYY-MM-DD yang valid (WIB).');
        }
        if ($from && $to && $from->gt($to)) {
            return $this->bad('from tidak boleh melebihi to.');
        }
        $all = $request->input('range') === 'semua';
        if ($all && ($from || $to)) {
            return $this->bad('range=semua tidak dapat dipakai bersama from atau to.');
        }
        // Sama seperti halaman admin: tanpa tanggal berarti hari ini.
        if (! $all && ! $from && ! $to) {
            $from = $to = now('Asia/Jakarta')->toImmutable()->startOfDay();
        }

        $type = $this->choice($request, 'type', ['in', 'out']);
        $bucket = $this->choice($request, 'bucket', [StockMovement::BUCKET_GOOD, StockMovement::BUCKET_DAMAGED]);
        $source = $this->choice($request, 'source', array_keys(StockMovement::SOURCES));
        if ($type === false) {
            return $this->bad('type harus in atau out.');
        }
        if ($bucket === false) {
            return $this->bad('bucket harus good atau damaged.');
        }
        if ($source === false) {
            return $this->bad('source harus salah satu dari: '.implode(', ', array_keys(StockMovement::SOURCES)).'.');
        }

        $productId = $request->input('product_id');
        if ($productId !== null && $productId !== '' && filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $this->bad('product_id harus berupa integer positif.');
        }

        $query = fn (): Builder => StockMovement::query()
            ->search($this->text($request, 'search'))
            ->fromSource($source)
            ->inBucket($bucket)
            ->when($type, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($productId, fn (Builder $query) => $query->where('product_id', (int) $productId))
            ->when($this->text($request, 'sku'), fn (Builder $query, string $sku) => $query
                ->whereHas('product', fn (Builder $product) => $product->where('sku', $sku)))
            ->when($from, fn (Builder $query) => $query->whereDate('created_at', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->whereDate('created_at', '<=', $to->toDateString()));

        $summary = StockMovementSummary::of($query());
        $rows = $query()
            ->with(['product:id,sku,name,unit', 'user:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'success' => true,
            'meta' => [
                'warehouse_code' => config('stock_api.warehouse_code'),
                'server_time' => now('Asia/Jakarta')->toIso8601String(),
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                ...$this->pageMeta($page, $perPage, $summary['entries']),
                'summary' => $summary,
            ],
            'data' => $rows->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'created_at' => $this->iso($movement->created_at),
                'sku' => $movement->product?->sku,
                'name' => $movement->product?->name,
                'uom' => $movement->product?->unit,
                'type' => $movement->type,
                'bucket' => $movement->bucket,
                'quantity' => $movement->quantity,
                'balance_after' => $movement->balance_after,
                'source' => $movement->sourceKey(),
                'source_label' => $movement->sourceLabel(),
                'reference_id' => $movement->reference_id,
                'description' => $movement->description,
                'user' => $movement->user?->name,
            ])->values(),
        ]);
    }
}
