<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\QrCode;
use App\Support\QrLabelLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cetak label QR berisi SKU barang, termasuk SKU tiap varian.
 *
 * QR-nya hanya berisi SKU, jadi bisa dibaca semua layar scan yang sudah ada
 * (pencarian barang dari kode scan memeriksa barcode lalu SKU).
 */
class ProductLabelController extends Controller implements HasMiddleware
{
    /** Batas satu kali cetak, supaya PDF tetap cepat dibuat. */
    public const MAX_LABELS = 1000;

    public const MAX_COPIES = 500;

    /** Barang yang dibawa dari daftar Barang ke halaman cetak. */
    public const MAX_PRESELECT = 1000;

    public static function middleware(): array
    {
        return [new Middleware('can:products.view')];
    }

    /**
     * Halaman pilih barang dan ukuran label. Barang awal bisa dibawa dari
     * pilihan di daftar Barang (ids[] atau seluruh hasil saringan), dari
     * satu produk induk (group = semua variannya), atau dari kartu barang.
     */
    public function create(Request $request): View
    {
        $selected = collect();

        if ($request->input('scope') === 'filtered') {
            $selected = app(ProductController::class)->filtered($request)
                ->limit(self::MAX_PRESELECT)
                ->pluck('id');
        } elseif ($request->filled('ids')) {
            $selected = collect((array) $request->input('ids'))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->take(self::MAX_PRESELECT);
        }

        if ($request->filled('group')) {
            $selected = $selected->merge(
                Product::where('product_group_id', (int) $request->input('group'))->pluck('id')
            );
        }

        $items = $this->products($selected->unique()->values());

        return view('admin.products.labels', [
            'items' => $items->map(fn (Product $product) => $this->row($product))->values(),
            'catalog' => Product::query()
                ->where('is_active', true)
                ->with('group:id,name')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'product_group_id', 'option1', 'option2']),
            'groups' => ProductGroup::query()
                ->whereHas('variants')
                ->with(['variants' => fn ($query) => $query->select(['id', 'sku', 'name', 'product_group_id', 'option1', 'option2'])])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (ProductGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'variants' => $group->variants->map(fn (Product $variant) => [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'name' => $group->name,
                        'variant' => $variant->variantLabel(),
                    ])->values()->all(),
                ]),
            'layouts' => collect(QrLabelLayout::all())->map(fn ($layout, $key) => QrLabelLayout::get($key)),
            'defaultLayout' => QrLabelLayout::DEFAULT,
            'maxLabels' => self::MAX_LABELS,
            'maxCopies' => self::MAX_COPIES,
        ]);
    }

    /**
     * PDF siap cetak. Dibuka di tab baru (bukan diunduh) supaya langsung
     * bisa dicetak dari penampil PDF browser.
     */
    public function pdf(Request $request): Response
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.copies' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
            'layout' => ['nullable', 'string'],
            'show_name' => ['nullable', 'boolean'],
            'skip' => ['nullable', 'integer', 'min:0', 'max:99'],
        ], [
            'items.required' => 'Pilih minimal satu barang untuk dicetak.',
        ], [
            'items.*.copies' => 'jumlah label',
        ]);

        $copies = collect($data['items'])
            ->groupBy(fn ($item) => (int) $item['id'])
            ->map(fn (Collection $rows) => $rows->sum(fn ($item) => (int) $item['copies']));

        $total = $copies->sum();

        if ($total > self::MAX_LABELS) {
            throw ValidationException::withMessages([
                'items' => 'Maksimal '.number_format(self::MAX_LABELS, 0, ',', '.').' label sekali cetak; yang diminta '
                    .number_format($total, 0, ',', '.').'. Bagi menjadi beberapa kali cetak.',
            ]);
        }

        $products = $this->products($copies->keys());

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Barang yang dipilih tidak ditemukan.']);
        }

        $layout = QrLabelLayout::get($data['layout'] ?? null);
        $showName = (bool) ($data['show_name'] ?? true);

        // Lembar A4 yang sebagian labelnya sudah terpakai: lewati sejumlah
        // kotak pertama supaya stiker sisa bisa dipakai lagi.
        $skip = $layout['sheet'] ? min((int) ($data['skip'] ?? 0), $layout['per_page'] - 1) : 0;
        $labels = array_fill(0, $skip, null);

        foreach ($products as $product) {
            $label = $this->label($product, $layout, $showName);

            for ($i = 0; $i < $copies[$product->id]; $i++) {
                $labels[] = $label;
            }
        }

        $pages = array_chunk($labels, $layout['per_page']);

        // Seribu label butuh ±150 MB dan beberapa detik di dompdf; batas
        // bawaan PHP (128 MB / 30 detik) terlalu sempit untuk itu.
        if ($this->bytes(ini_get('memory_limit')) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        @set_time_limit(180);

        $pdf = Pdf::loadView('admin.products.labels-pdf', [
            'pages' => $pages,
            'layout' => $layout,
            'showName' => $showName,
        ])
            ->setPaper(QrLabelLayout::paper($layout))
            // Hanya huruf yang dipakai yang disematkan: PDF jadi puluhan KB, bukan 1 MB.
            ->setOption('isFontSubsettingEnabled', true);

        $name = 'label-qr-'.now()->format('Ymd-His').'.pdf';

        return $pdf->stream($name);
    }

    /** "128M" → byte. -1 (tanpa batas) dianggap sangat besar. */
    protected function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Barang sesuai urutan daftar Barang: varian satu induk berurutan.
     *
     * @return Collection<int, Product>
     */
    protected function products(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return $ids->chunk(500)
            ->flatMap(fn (Collection $chunk) => Product::query()
                ->with('group:id,name')
                ->whereIn('id', $chunk->all())
                ->get())
            ->sortBy(fn (Product $product) => [
                Str::lower($product->group?->name ?? $product->name),
                $product->product_group_id ?? 0,
                Str::lower($product->name),
            ])
            ->values();
    }

    /** Satu baris di layar pilih barang. */
    protected function row(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->isVariant() ? ($product->group?->name ?? $product->name) : $product->name,
            'variant' => $product->variantLabel(),
            'copies' => 1,
        ];
    }

    /** Isi satu label. QR dibuat sekali per barang, dipakai ulang tiap salinan. */
    protected function label(Product $product, array $layout, bool $showName): array
    {
        $title = $product->isVariant() ? ($product->group?->name ?? $product->name) : $product->name;
        $variant = $product->variantLabel();
        $chars = $layout['name_chars'];

        return [
            'sku' => $product->sku,
            'qr' => QrCode::file($product->sku),
            // Label bertumpuk (thermal): "Nama · Varian" satu baris di bawah
            // QR; varian ditulis utuh, namanya yang dipendekkan.
            'name' => $showName ? Str::limit($title, match (true) {
                $variant === '' => $chars,
                $layout['stacked'] => max(8, $chars - mb_strlen(Str::limit($variant, (int) ($chars * 0.5), '')) - 3),
                default => (int) ($chars * 0.7),
            }, '…') : '',
            'variant' => $showName ? Str::limit($variant, (int) ($chars * 0.5), '…') : '',
        ];
    }
}
