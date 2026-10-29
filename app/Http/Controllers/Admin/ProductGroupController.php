<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductGroupRequest;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Produk induk dan varian-variannya.
 *
 * Varian adalah baris products biasa, jadi yang dikerjakan di sini hanya
 * mengelompokkan: membuat induk beserta SKU variannya sekaligus, menambah
 * atau melepas varian, dan menjaga nama varian tetap mengikuti induknya.
 * Stok tidak pernah disentuh.
 */
class ProductGroupController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'show']),
            new Middleware('can:products.create', only: ['create', 'store', 'addVariants']),
            new Middleware('can:products.update', only: ['edit', 'update', 'attach', 'detach']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $groups = ProductGroup::query()
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')))
            ->withCount('variants')
            ->withSum('variants as total_stock', 'stock')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.product-groups.index', [
            'groups' => $groups,
            'categories' => ProductGroup::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function create(): View
    {
        return view('admin.product-groups.create');
    }

    public function store(ProductGroupRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $group = DB::transaction(function () use ($request, $data) {
            $group = ProductGroup::create($this->groupAttributes($data));
            $this->storeImage($group, $request->file('image'));

            foreach ($data['variants'] as $row) {
                $this->createVariant($group, $row);
            }

            return $group;
        });

        return redirect()
            ->route('admin.product-groups.show', $group)
            ->with('success', "Produk {$group->name} dibuat dengan ".count($data['variants']).' varian.');
    }

    public function show(ProductGroup $productGroup): View
    {
        $productGroup->load('variants');
        $productGroup->variants->each->setRelation('group', $productGroup);

        return view('admin.product-groups.show', [
            'group' => $productGroup,
            'committed' => Product::committedUnits($productGroup->variants->pluck('id')),
            // Barang tanpa induk yang bisa dijadikan varian produk ini.
            'candidates' => Product::singles()
                ->whereNull('product_group_id')
                ->orderBy('name')
                ->get(['id', 'sku', 'name']),
        ]);
    }

    public function edit(ProductGroup $productGroup): View
    {
        return view('admin.product-groups.edit', ['group' => $productGroup]);
    }

    public function update(ProductGroupRequest $request, ProductGroup $productGroup): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $data, $productGroup) {
            $productGroup->update($this->groupAttributes($data));

            if ($request->boolean('remove_image')) {
                $this->deleteImage($productGroup);
            }

            $this->storeImage($productGroup, $request->file('image'));

            // Atribut kedua dihapus: nilai lamanya ikut dibuang supaya nama
            // varian tidak membawa atribut yang sudah tidak ada.
            if (! $productGroup->hasSecondOption()) {
                $productGroup->variants()->update(['option2' => null]);
            }

            $productGroup->syncVariants();
        });

        return redirect()
            ->route('admin.product-groups.show', $productGroup)
            ->with('success', 'Produk induk diperbarui. Nama semua varian ikut disesuaikan.');
    }

    /**
     * Tambah varian baru (SKU baru) ke produk yang sudah ada.
     */
    public function addVariants(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $data = $request->validate(
            ProductGroupRequest::variantRules('variants', $productGroup->hasSecondOption()),
            [],
            ProductGroupRequest::variantAttributes(),
        );

        $this->ensureUniqueCombinations($productGroup, $data['variants']);

        DB::transaction(function () use ($productGroup, $data) {
            foreach ($data['variants'] as $row) {
                $this->createVariant($productGroup, $row);
            }
        });

        return back()->with('success', count($data['variants']).' varian ditambahkan.');
    }

    /**
     * Jadikan barang yang sudah ada sebagai varian produk ini.
     *
     * Barangnya tetap baris yang sama: SKU, stok, barcode, lokasi, dan
     * seluruh riwayat mutasinya tidak berubah. Yang berubah hanya induk,
     * atribut varian, dan nama tampilannya.
     */
    public function attach(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->whereNull('product_group_id')
                ->where('type', Product::TYPE_SINGLE)],
            'option1' => ['required', 'string', 'max:100'],
            'option2' => [$productGroup->hasSecondOption() ? 'required' : 'nullable', 'string', 'max:100'],
        ], [
            'product_id.exists' => 'Barang ini sudah punya induk atau berupa paket bundling.',
        ], [
            'product_id' => 'barang',
            'option1' => $productGroup->option1_name,
            'option2' => $productGroup->option2_name ?? 'atribut kedua',
        ]);

        $this->ensureUniqueCombinations($productGroup, [$data], 'option1');

        $product = Product::findOrFail($data['product_id']);
        $product->product_group_id = $productGroup->id;
        $product->option1 = trim($data['option1']);
        $product->option2 = $productGroup->hasSecondOption() ? trim((string) $data['option2']) : null;
        $product->setRelation('group', $productGroup);
        $product->fillFromGroup()->save();

        return back()->with('success', "{$product->sku} sekarang menjadi varian {$product->variantLabel()}.");
    }

    /**
     * Lepas varian dari induknya. Barangnya tetap ada sebagai barang tanpa
     * varian dengan nama lengkap yang terakhir.
     */
    public function detach(ProductGroup $productGroup, Product $product): RedirectResponse
    {
        abort_unless($product->product_group_id === $productGroup->id, 404);

        $product->update(['product_group_id' => null, 'option1' => null, 'option2' => null]);

        return back()->with('success', "{$product->sku} dilepas dari {$productGroup->name}. Barangnya tetap ada sebagai barang tanpa varian.");
    }

    /**
     * Hapus induknya saja. Varian tetap ada sebagai barang tanpa varian, jadi
     * tidak ada stok maupun dokumen yang ikut hilang.
     */
    public function destroy(ProductGroup $productGroup): RedirectResponse
    {
        DB::transaction(function () use ($productGroup) {
            $productGroup->variants()->update(['product_group_id' => null, 'option1' => null, 'option2' => null]);
            $this->deleteImage($productGroup);
            $productGroup->delete();
        });

        return redirect()
            ->route('admin.product-groups.index')
            ->with('success', 'Produk induk dihapus. Variannya tetap ada sebagai barang biasa.');
    }

    /* ------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function groupAttributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'category' => filled($data['category'] ?? null) ? trim($data['category']) : null,
            'unit' => trim($data['unit']),
            'description' => $data['description'] ?? null,
            'option1_name' => trim($data['option1_name']),
            'option2_name' => filled($data['option2_name'] ?? null) ? trim($data['option2_name']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function createVariant(ProductGroup $group, array $row): Product
    {
        $product = new Product([
            'sku' => Str::upper(trim($row['sku'])),
            'barcode' => filled($row['barcode'] ?? null) ? trim($row['barcode']) : null,
            'unit' => $group->unit,
            'location' => filled($row['location'] ?? null) ? trim($row['location']) : null,
            'min_stock' => (int) ($row['min_stock'] ?? 0),
            'is_active' => $group->is_active,
            'type' => Product::TYPE_SINGLE,
            'option1' => trim($row['option1']),
            'option2' => $group->hasSecondOption() ? trim((string) ($row['option2'] ?? '')) : null,
        ]);

        $product->product_group_id = $group->id;
        $product->setRelation('group', $group);
        $product->fillFromGroup()->save();

        return $product;
    }

    /**
     * Kombinasi atribut tidak boleh kembar di dalam satu produk: dua SKU yang
     * sama-sama "Merah / L" tidak bisa dibedakan saat dipilih.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function ensureUniqueCombinations(ProductGroup $group, array $rows, string $field = 'variants'): void
    {
        $existing = $group->variants()->get(['option1', 'option2'])
            ->map(fn (Product $variant) => Str::lower(ProductGroup::label($variant->option1, $variant->option2)));

        foreach ($rows as $row) {
            $label = ProductGroup::label($row['option1'] ?? null, $group->hasSecondOption() ? ($row['option2'] ?? null) : null);

            if ($existing->contains(Str::lower($label))) {
                throw ValidationException::withMessages([
                    $field => "Varian {$label} sudah ada di {$group->name}.",
                ]);
            }

            $existing->push(Str::lower($label));
        }
    }

    protected function storeImage(ProductGroup $group, ?UploadedFile $image): void
    {
        if (! $image) {
            return;
        }

        $this->deleteImage($group);
        $group->update(['image_path' => $image->store('product-groups', 'public')]);
    }

    protected function deleteImage(ProductGroup $group): void
    {
        if ($group->image_path) {
            Storage::disk('public')->delete($group->image_path);
            $group->update(['image_path' => null]);
        }
    }
}
