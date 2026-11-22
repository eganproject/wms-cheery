<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SewingBatch;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Input manual hasil potong per SKU: di gudang atau sedang dijahit di
 * penjahit, tanpa lewat Cutting (saldo yang sudah berjalan). Batch dari
 * Cutting juga bisa diubah di sini selama belum ditutup.
 */
class SewingBatchController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:fabrics.issue')];
    }

    public function create(): View
    {
        return view('admin.sewing-batches.form', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $batch = DB::transaction(function () use ($data, $request) {
            $batch = SewingBatch::create([
                'code' => SewingBatch::nextCode(),
                'fabric_cutting_id' => null,
                'supplier_id' => $data['supplier_id'],
                'date' => $data['date'],
                'sent_at' => $data['supplier_id'] ? ($data['sent_at'] ?? $data['date']) : null,
                'note' => $data['note'],
                'user_id' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $productId => $quantity) {
                $batch->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
            }

            return $batch;
        });

        return redirect()
            ->route('admin.fabric-cuttings.index', ['lihat' => $batch->inWarehouse() ? 'gudang' : 'jahit'])
            ->with('success', "{$batch->code}: {$data['lines']->sum()} pcs dicatat ".($batch->inWarehouse() ? 'di gudang.' : 'di penjahit.'));
    }

    public function edit(SewingBatch $sewingBatch): View|RedirectResponse
    {
        if ($sewingBatch->closed_at) {
            return back()->with('error', "Batch {$sewingBatch->code} sudah ditutup.");
        }

        return view('admin.sewing-batches.form', $this->formData($sewingBatch->load(['items', 'cutting'])));
    }

    public function update(Request $request, SewingBatch $sewingBatch): RedirectResponse
    {
        if ($sewingBatch->closed_at) {
            return back()->with('error', "Batch {$sewingBatch->code} sudah ditutup.");
        }

        $data = $this->validated($request);
        $received = $sewingBatch->receivedByProduct();

        foreach ($received as $productId => $quantity) {
            if ($quantity > 0 && ($data['lines'][$productId] ?? 0) < $quantity) {
                $sku = Product::whereKey($productId)->value('sku');
                throw ValidationException::withMessages(['items' => "{$sku} sudah diterima {$quantity} pcs dari batch ini; jumlahnya tidak boleh kurang dari itu."]);
            }
        }

        if ($sewingBatch->inbounds()->exists() && (int) $data['supplier_id'] !== (int) $sewingBatch->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => 'Batch ini sudah dipakai di penerimaan barang, jadi penjahitnya tidak bisa diganti.']);
        }

        DB::transaction(function () use ($sewingBatch, $data) {
            $sewingBatch->forceFill([
                'supplier_id' => $data['supplier_id'],
                'date' => $data['date'],
                'sent_at' => $data['supplier_id'] ? ($data['sent_at'] ?? $sewingBatch->sent_at ?? $data['date']) : null,
                'note' => $data['note'],
            ])->save();

            $sewingBatch->items()->whereNotIn('product_id', $data['lines']->keys())->delete();
            foreach ($data['lines'] as $productId => $quantity) {
                $sewingBatch->items()->updateOrCreate(['product_id' => $productId], ['quantity' => $quantity]);
            }
        });

        return redirect()
            ->to($sewingBatch->cutting ? $sewingBatch->url() : route('admin.fabric-cuttings.index', ['lihat' => $sewingBatch->inWarehouse() ? 'gudang' : 'jahit', 'status' => 'semua']))
            ->with('success', "Batch {$sewingBatch->code} diperbarui.");
    }

    /**
     * @return array{supplier_id: ?int, date: string, sent_at: ?string, note: ?string, lines: Collection<int, int>}
     */
    protected function validated(Request $request): array
    {
        $toWarehouse = $request->input('posisi') === 'gudang';

        $data = $request->validate([
            'posisi' => ['required', 'in:gudang,penjahit'],
            'supplier_id' => [$toWarehouse ? 'nullable' : 'required', 'integer', 'exists:suppliers,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'sent_at' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['supplier_id.required' => 'Pilih penjahit, atau pilih posisi Gudang.'], ['supplier_id' => 'penjahit', 'date' => 'tanggal potong']);

        if (! $toWarehouse && ! Supplier::whereKey($data['supplier_id'])->where('is_tailor', true)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'Pemasok ini belum ditandai sebagai Penjahit/Konveksi.']);
        }

        $lines = collect($data['items'])
            ->filter(fn ($row) => ! empty($row['product_id']) && (int) ($row['quantity'] ?? 0) > 0)
            ->groupBy(fn ($row) => (int) $row['product_id'])
            ->map(fn ($rows) => (int) $rows->sum('quantity'));

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu barang dengan jumlah pcs.']);
        }

        return [
            'supplier_id' => $toWarehouse ? null : (int) $data['supplier_id'],
            'date' => $data['date'],
            'sent_at' => $data['sent_at'] ?? null,
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    protected function formData(?SewingBatch $batch): array
    {
        return [
            'batch' => $batch,
            'tailors' => Supplier::query()->where('is_tailor', true)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::singles()->where('is_active', true)->orderBy('name')->get(),
            'items' => $batch?->items->map(fn ($item) => (object) [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'damaged_quantity' => 0,
                'note' => null,
            ]),
        ];
    }
}
