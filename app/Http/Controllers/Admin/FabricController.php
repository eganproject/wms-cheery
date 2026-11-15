<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Models\FabricUsage;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\TableExport;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saldo Kain: daftar jenis kain beserta sisa semua roll-nya, sekaligus
 * master jenis kain dan pemakaian kain per barang.
 */
class FabricController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['index', 'show', 'export']),
            new Middleware('can:fabrics.manage', only: ['create', 'store', 'edit', 'update', 'destroy', 'usages']),
        ];
    }

    public function index(Request $request): View
    {
        $fabrics = $this->filtered($request)
            ->with('supplier:id,name')
            ->withCount('usages')
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'sku' => 'sku',
                'name' => 'name',
                'stock' => 'stock',
                'rolls' => 'open_rolls',
            ], ['sku', 'asc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        $all = Fabric::query()->withStock()->where('is_active', true)->get();

        return view('admin.fabrics.index', [
            'fabrics' => $fabrics,
            'summary' => [
                'fabrics' => $all->count(),
                'rolls' => (int) $all->sum('open_rolls'),
                'low' => $all->filter(fn (Fabric $fabric) => $fabric->min_stock > 0 && (float) $fabric->stock < $fabric->min_stock)->count(),
                'empty' => $all->filter(fn (Fabric $fabric) => (float) $fabric->stock <= 0)->count(),
                'per_unit' => $all->groupBy(fn (Fabric $fabric) => $fabric->unitShort())
                    ->map(fn ($group) => round($group->sum(fn (Fabric $fabric) => (float) $fabric->stock), 2)),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->with('supplier:id,name')->orderBy('sku')->get();

        return TableExport::make('Saldo Kain')
            ->note('Dicetak '.now()->translatedFormat('d F Y H:i'))
            ->sheet('Saldo Kain', [
                ['SKU Kain', 'text'], ['Nama', 'text'], ['Warna', 'text'], ['Satuan', 'text'],
                ['Roll Berisi', 'sum'], ['Sisa', 'number'], ['Stok Minimum', 'number'], ['Pemasok', 'text'], ['Status', 'text'],
            ], $rows->map(fn (Fabric $fabric) => [
                $fabric->sku, $fabric->name, $fabric->color, $fabric->unit,
                (int) $fabric->open_rolls, round((float) $fabric->stock, 2), $fabric->min_stock,
                $fabric->supplier?->name, $fabric->is_active ? 'Aktif' : 'Nonaktif',
            ]))
            ->download('saldo-kain-'.now()->format('Y-m-d').'.xlsx');
    }

    protected function filtered(Request $request): Builder
    {
        $level = (string) $request->input('stok');

        return Fabric::query()
            ->withStock()
            ->search($request->string('search')->trim()->value())
            ->when($request->input('status', 'active') !== 'all', fn (Builder $query) => $query->where('is_active', $request->input('status', 'active') !== 'inactive'))
            ->when($level === 'habis', fn (Builder $query) => $query->whereDoesntHave('rolls', fn (Builder $query) => $query->where('quantity', '>', 0)))
            ->when($level === 'menipis', fn (Builder $query) => $query->where('min_stock', '>', 0)
                ->whereRaw('COALESCE((SELECT SUM(quantity) FROM fabric_rolls WHERE fabric_rolls.fabric_id = fabrics.id AND quantity > 0), 0) < fabrics.min_stock'));
    }

    public function create(): View
    {
        return view('admin.fabrics.create', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $fabric = new Fabric($this->validated($request, null));
        $this->syncImage($request, $fabric);
        $fabric->save();

        return redirect()
            ->route('admin.fabrics.show', $fabric)
            ->with('success', "Kain {$fabric->sku} berhasil ditambahkan. Atur pemakaian kain per barang di bawah agar kebutuhan kain bisa dihitung.");
    }

    public function show(Request $request, Fabric $fabric): View
    {
        $fabric->load('supplier');
        $fabric->loadSum(['rolls as stock' => fn (Builder $query) => $query->where('quantity', '>', 0)], 'quantity');

        $showEmpty = $request->boolean('habis');

        return view('admin.fabrics.show', [
            'fabric' => $fabric,
            'rolls' => $fabric->rolls()
                ->with('receipt:id,code,date')
                ->when(! $showEmpty, fn ($query) => $query->where('quantity', '>', 0))
                ->orderBy('number')
                ->get(),
            'emptyRolls' => $fabric->rolls()->where('quantity', '<=', 0)->count(),
            'showEmpty' => $showEmpty,
            'usages' => $fabric->usages()->with('product:id,sku,name,stock')->get()->sortBy('product.sku')->values(),
            'movements' => $fabric->movements()->with(['roll:id,code', 'user:id,name', 'receipt:id,code', 'product:id,sku,name'])
                ->latest('moved_at')->latest('id')->limit(30)->get(),
            'catalog' => $this->catalog($fabric),
        ]);
    }

    public function edit(Fabric $fabric): View
    {
        return view('admin.fabrics.edit', $this->formData($fabric));
    }

    public function update(Request $request, Fabric $fabric): RedirectResponse
    {
        $data = $this->validated($request, $fabric);

        // Kode roll memakai SKU kain. Roll yang sudah ada tetap memakai kode
        // lamanya karena label QR-nya sudah tertempel.
        $fabric->fill($data);
        $this->syncImage($request, $fabric);
        $fabric->save();

        return redirect()
            ->route('admin.fabrics.show', $fabric)
            ->with('success', "Kain {$fabric->sku} berhasil diperbarui.");
    }

    public function destroy(Fabric $fabric): RedirectResponse
    {
        if ($fabric->rolls()->exists()) {
            return back()->with('error', 'Kain ini sudah punya roll. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $fabric->delete();

        return redirect()->route('admin.fabrics.index')->with('success', 'Jenis kain berhasil dihapus.');
    }

    /**
     * Simpan pemakaian kain per barang: usages[product_id] = jumlah kain
     * untuk 1 unit. Barang yang tidak dikirim dihapus dari daftar.
     */
    public function usages(Request $request, Fabric $fabric): RedirectResponse
    {
        $data = $request->validate([
            'usages' => ['nullable', 'array', 'max:2000'],
            'usages.*' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ], [], ['usages.*' => 'pemakaian kain']);

        $usages = collect($data['usages'] ?? [])
            ->mapWithKeys(fn ($quantity, $productId) => [(int) $productId => round((float) $quantity, 3)])
            ->filter(fn (float $quantity) => $quantity > 0);

        $valid = Product::query()->whereIn('id', $usages->keys())->pluck('id')->flip();
        $usages = $usages->filter(fn ($quantity, int $productId) => $valid->has($productId));

        DB::transaction(function () use ($fabric, $usages) {
            $fabric->usages()->whereNotIn('product_id', $usages->keys())->delete();

            foreach ($usages as $productId => $quantity) {
                FabricUsage::updateOrCreate(
                    ['fabric_id' => $fabric->id, 'product_id' => $productId],
                    ['quantity' => $quantity],
                );
            }
        });

        return redirect()
            ->route('admin.fabrics.show', $fabric)
            ->with('success', "Pemakaian kain disimpan untuk {$usages->count()} barang.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Fabric $fabric): array
    {
        $request->merge([
            'sku' => trim((string) $request->input('sku')),
            'unit' => $request->input('unit') === 'lainnya' ? trim((string) $request->input('unit_other')) : $request->input('unit'),
        ]);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', Rule::unique('fabrics', 'sku')->ignore($fabric?->id)],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:20'],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'sku.regex' => 'SKU kain hanya boleh huruf, angka, titik, garis bawah, garis miring, dan tanda minus, tanpa spasi.',
            'unit.required' => 'Pilih satuan kain, atau isi satuan lainnya.',
        ], [
            'sku' => 'SKU kain',
            'name' => 'nama kain',
            'unit' => 'satuan',
            'min_stock' => 'stok minimum',
            'image' => 'foto kain',
        ]);

        unset($data['image'], $data['remove_image']);

        // Roll dan label QR yang sudah ada memakai SKU lama; mengganti SKU
        // membuat roll baru berkode beda dengan yang lama.
        if ($fabric && $fabric->sku !== $data['sku'] && $fabric->rolls()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sku' => 'SKU kain tidak bisa diubah karena roll-nya sudah berlabel QR.',
            ]);
        }

        // array_merge, bukan +: kolom kosong (null) dari form harus tertimpa.
        return array_merge($data, [
            'min_stock' => (float) ($data['min_stock'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);
    }

    /** Foto baru menggantikan yang lama; centang hapus melepasnya. */
    protected function syncImage(Request $request, Fabric $fabric): void
    {
        if ($request->hasFile('image')) {
            $fabric->replaceImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $fabric->removeImage();
        }
    }

    /** @return array<string, mixed> */
    protected function formData(?Fabric $fabric): array
    {
        return [
            'fabric' => $fabric,
            'suppliers' => Supplier::query()
                ->where(fn ($query) => $query->where('is_active', true)->when($fabric?->supplier_id, fn ($query, $id) => $query->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['id', 'name']),
            'units' => Fabric::UNITS,
            'otherUnits' => Fabric::query()->whereNotIn('unit', array_keys(Fabric::UNITS))->distinct()->orderBy('unit')->pluck('unit'),
        ];
    }

    /**
     * Barang yang bisa diberi pemakaian kain, untuk kotak pencarian.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function catalog(Fabric $fabric)
    {
        return Product::query()
            ->with('group:id,name')
            ->where(fn ($query) => $query->where('is_active', true)
                ->orWhereIn('id', $fabric->usages()->select('product_id')))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'barcode', 'unit', 'product_group_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'unit' => $product->unit,
                'group_id' => $product->product_group_id,
                'group' => $product->group?->name,
                'available' => 999999,
            ]);
    }
}
