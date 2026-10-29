<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    /** Barang biasa: punya wujud di rak dan punya saldo sendiri. */
    public const TYPE_SINGLE = 'single';

    /** Paket bundling: dijual sebagai satu SKU, tetapi tidak pernah ada di rak. */
    public const TYPE_BUNDLE = 'bundle';

    /**
     * Bawaan yang sama dengan kolomnya, supaya model yang baru dibuat sudah
     * membawanya sebelum dibaca ulang — sinkronisasi API stok membacanya
     * tepat setelah disimpan, dan tanpa ini barang baru terkirim "inactive".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'min_stock' => 0,
    ];

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'category',
        'unit',
        'location',
        'min_stock',
        'is_active',
        'type',
        'product_group_id',
        'option1',
        'option2',
    ];

    /** Disk tempat gambar barang disimpan. Perlu `php artisan storage:link`. */
    public const IMAGE_DISK = 'public';

    public const IMAGE_DIR = 'products';

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------- gambar -- */

    /**
     * URL gambar barang, atau null bila belum ada.
     *
     * Semua tampilan membaca gambar lewat sini, bukan dari image_path
     * langsung. Varian tanpa gambar sendiri memakai gambar utama produk
     * induknya.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (blank($this->image_path)) {
            return ($this->attributes['product_group_id'] ?? null)
                ? $this->group?->imageUrl()
                : null;
        }

        return Storage::disk(self::IMAGE_DISK)->url($this->image_path);
    }

    /**
     * Simpan gambar baru dan buang yang lama. Tidak memanggil save().
     */
    public function replaceImage(UploadedFile $file): void
    {
        $old = $this->image_path;

        $this->image_path = $file->store(self::IMAGE_DIR, self::IMAGE_DISK);

        $this->deleteImageFile($old);
    }

    /**
     * Lepas gambar dari barang ini. Tidak memanggil save().
     */
    public function removeImage(): void
    {
        $this->deleteImageFile($this->image_path);

        $this->image_path = null;
    }

    protected function deleteImageFile(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::IMAGE_DISK)->delete($path);
        }
    }

    protected static function booted(): void
    {
        // Berkasnya ikut dibuang saat barangnya dihapus, supaya folder
        // unggahan tidak menumpuk gambar yatim.
        static::deleted(fn (Product $product) => $product->deleteImageFile($product->image_path));

        // Resi yang SKU-nya belum dikenal langsung tertaut begitu barangnya
        // didaftarkan, tanpa perlu menekan Cocokkan Ulang.
        static::saved(function (Product $product) {
            if ($product->wasRecentlyCreated || $product->wasChanged('sku')) {
                app(\App\Services\ShipmentSkuMatcher::class)->matchProduct($product);
            }
        });
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    /* ----------------------------------------------------------- varian -- */

    /**
     * Produk induk varian ini. Kosong untuk barang tanpa varian.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function isVariant(): bool
    {
        return $this->product_group_id !== null;
    }

    /**
     * Label varian saja, mis. "Merah / L". Kosong untuk barang tanpa varian.
     */
    public function variantLabel(): string
    {
        return $this->isVariant() ? ProductGroup::label($this->option1, $this->option2) : '';
    }

    /**
     * Isi nama dan kategori dari induknya. Stok, SKU, barcode, dan lokasi
     * tidak pernah disentuh: itu milik varian sendiri.
     */
    public function fillFromGroup(): static
    {
        if ($group = $this->group) {
            $this->name = $group->variantName($this->option1, $this->option2);
            $this->category = $group->category;
        }

        return $this;
    }

    /* ------------------------------------------------------------ paket -- */

    /**
     * Isi paket ini. Kosong untuk barang biasa.
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id');
    }

    /**
     * Paket-paket yang memuat barang ini sebagai isinya.
     */
    public function partOfBundles(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'component_id');
    }

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    /**
     * Berapa paket yang masih bisa dijanjikan ke pembeli.
     *
     * Selalu dihitung, tidak pernah disimpan. Menyimpannya berarti ada dua
     * sumber kebenaran atas satu barang yang sama — dan yang satu pasti
     * ketinggalan begitu komponennya bergerak lewat dokumen lain.
     *
     * Unit yang sudah tercantum di dokumen barang keluar yang belum diproses
     * ikut dipotong. Saldo di rak saja bukan jawaban atas pertanyaan yang
     * sebenarnya diajukan orang yang membaca angka ini: paket ini masih bisa
     * dijual berapa lagi? Barang yang sudah dijanjikan ke pembeli lain secara
     * fisik memang masih ada, tetapi tidak lagi tersedia untuk dijanjikan
     * kedua kalinya.
     *
     * Paket tanpa isi bernilai nol: ia belum bisa dijual, bukan bisa dijual
     * tanpa batas. Paket yang dinonaktifkan juga nol — sama seperti perlakuan
     * atas komponen yang dinonaktifkan, supaya keduanya tidak berbeda aturan.
     */
    public function bundleAvailability(): int
    {
        if (! $this->isBundle()) {
            return (int) $this->stock;
        }

        if (! $this->is_active) {
            return 0;
        }

        /*
            Hasil withBundleAvailability() dipakai bila kuerinya memang
            memintanya, supaya daftar berisi puluhan paket tidak berubah
            menjadi puluhan kueri komponen.

            Keberadaan kuncinya yang diperiksa, bukan nilainya: paket tanpa isi
            menghasilkan null, dan `??` akan menganggapnya belum dihitung lalu
            memuat komponennya — persis N+1 yang scope itu hindari.
        */
        if (array_key_exists('bundle_availability', $this->attributes)) {
            return (int) $this->attributes['bundle_availability'];
        }

        $components = $this->relationLoaded('bundleComponents')
            ? $this->bundleComponents->loadMissing('component')
            : $this->bundleComponents()->with('component')->get();

        if ($components->isEmpty()) {
            return 0;
        }

        $committed = static::committedUnits($components->pluck('component_id'));

        return (int) $components->min(
            fn (ProductBundleItem $item) => $item->availableSets($committed[$item->component_id] ?? 0),
        );
    }

    /**
     * Unit yang sudah tercantum pada dokumen barang keluar yang belum diproses.
     *
     * Barangnya masih di rak, tetapi sudah dijanjikan ke pembeli lain — jadi
     * tidak lagi tersedia untuk dijanjikan kedua kalinya. Angka yang sama
     * dipakai laporan restock untuk menentukan apa yang perlu dipesan.
     *
     * @param  \Illuminate\Support\Collection<int, int>|array<int, int>  $productIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function committedUnits($productIds): \Illuminate\Support\Collection
    {
        return Outbound::committedLines()
            ->whereIn('oi.product_id', collect($productIds)->all())
            ->groupBy('oi.product_id')
            ->select('oi.product_id')
            ->selectRaw('SUM(oi.quantity) as committed')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->product_id => (int) $row->committed]);
    }

    /**
     * Ketersediaan paket sebagai kolom hasil kueri, untuk daftar dan laporan.
     *
     * Dihitung di basis data supaya satu halaman berisi puluhan paket tidak
     * berubah menjadi puluhan kueri komponen.
     *
     * Pembagiannya ditulis dengan sisa bagi, bukan FLOOR: SQLite bawaan PHP
     * dikompilasi tanpa fungsi matematika sama sekali, sedangkan MySQL
     * punya. Bentuk (a - a % b) / b selalu habis dibagi, jadi hasilnya tepat
     * di kedua basis data — sama seperti yang dipakai laporan restock.
     */
    public function scopeWithBundleAvailability(Builder $query): Builder
    {
        // Unit yang sudah dijanjikan ke pembeli lain, dipotong lebih dulu.
        $committed = Outbound::committedLines()
            ->groupBy('oi.product_id')
            ->select('oi.product_id')
            ->selectRaw('SUM(oi.quantity) as committed');

        // Tidak pernah negatif: dokumen yang jumlahnya melebihi saldo memang
        // bisa disusun sebagai draft, dan sisanya bukan berarti utang.
        $free = '(CASE WHEN c.stock - COALESCE(cm.committed, 0) > 0 THEN c.stock - COALESCE(cm.committed, 0) ELSE 0 END)';
        $sets = "({$free} - ({$free} % pbi.quantity)) / pbi.quantity";

        $availability = DB::table('product_bundle_items as pbi')
            ->join('products as c', 'c.id', '=', 'pbi.component_id')
            ->leftJoinSub($committed, 'cm', 'cm.product_id', '=', 'c.id')
            ->whereColumn('pbi.bundle_id', 'products.id')
            ->selectRaw("MIN(CASE WHEN c.is_active = 0 OR pbi.quantity < 1 THEN 0 ELSE {$sets} END)");

        return $query
            ->select('products.*')
            ->selectSub($availability, 'bundle_availability');
    }

    public function scopeBundles(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_BUNDLE);
    }

    public function scopeSingles(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SINGLE);
    }

    /**
     * Angka yang layak ditampilkan sebagai "berapa yang bisa dikirim".
     *
     * Untuk barang biasa itu saldo di rak. Untuk paket itu hasil hitungan dari
     * komponennya — satu-satunya angka yang punya arti, karena kolom stoknya
     * memang selamanya nol.
     */
    public function availableStock(): int
    {
        return $this->isBundle() ? $this->bundleAvailability() : (int) $this->stock;
    }

    /**
     * Stok berada pada atau di bawah batas minimum.
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Label status stok untuk badge di tabel.
     *
     * Paket hanya mengenal dua keadaan: masih bisa dirakit, atau tidak.
     * "Menipis" tidak berlaku baginya — batas minimum adalah setelan atas
     * saldo di rak, dan paket tidak punya rak.
     */
    public function stockStatus(): string
    {
        if ($this->isBundle()) {
            return $this->availableStock() > 0 ? 'aman' : 'habis';
        }

        return match (true) {
            $this->isOutOfStock() => 'habis',
            $this->isLowStock() => 'menipis',
            default => 'aman',
        };
    }

    /**
     * Barang yang stoknya menyentuh batas minimum.
     *
     * Paket tidak pernah ikut: kolom stoknya selamanya nol dan batas
     * minimumnya nol pula, sehingga 0 <= 0 akan membuat setiap paket terbaca
     * menipis selama-lamanya — menaikkan hitungan di dasbor dan mendesak
     * pemesanan barang yang memang tidak pernah dipesan.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->singles()->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Jenis dokumen yang masih memakai barang ini.
     *
     * Baris dokumen memakai kunci asing RESTRICT, sedangkan penghapusan hanya
     * memeriksa mutasi stok — padahal dokumen draft belum menghasilkan mutasi
     * apa pun. Tanpa pemeriksaan ini, menghapus barang yang masih tercantum di
     * draft menabrak kunci asing dan berakhir sebagai galat basis data, bukan
     * sebagai pesan yang bisa ditindaklanjuti.
     *
     * @return array<int, string>
     */
    public function blockingDocuments(): array
    {
        $tables = [
            'inbound_items' => 'barang masuk',
            'outbound_items' => 'barang keluar',
            'return_receipt_items' => 'penerimaan retur',
            'stock_adjustment_items' => 'penyesuaian stok',
            'stock_opname_items' => 'stok opname',
            'damaged_disposal_items' => 'barang rusak',
        ];

        $blocking = collect($tables)
            ->filter(fn (string $label, string $table) => DB::table($table)->where('product_id', $this->id)->exists())
            ->values()
            ->all();

        /*
            Resep paket diperiksa terpisah karena kolomnya bukan product_id.

            Kedua arah dihitung: barang yang menjadi isi paket lain, dan paket
            yang isinya sudah tersusun. Keduanya memakai kunci asing yang
            berbeda perlakuannya — isi bersifat RESTRICT, resep bersifat
            CASCADE — tetapi bagi yang menghapus, keduanya sama-sama alasan
            untuk berhenti dan melihat dulu.
        */
        if ($this->partOfBundles()->exists()) {
            $blocking[] = 'isi paket bundling';
        }

        if ($this->isBundle() && $this->bundleComponents()->exists()) {
            $blocking[] = 'resep paket bundling';
        }

        /*
            Paket yang tercantum pada dokumen barang keluar.

            Kolomnya bundle_id, bukan product_id, sehingga pemeriksaan di atas
            melewatinya — sementara kunci asingnya RESTRICT dan akan menolak
            penghapusannya. Tanpa baris ini, menghapus paket yang masih ada di
            satu draft berakhir sebagai galat basis data, bukan sebagai kalimat
            yang bisa ditindaklanjuti. Persis kekeliruan yang sudah dijelaskan
            di atas, hanya untuk tabel yang lebih baru.
        */
        if ($this->isBundle() && DB::table('outbound_bundles')->where('bundle_id', $this->id)->exists()) {
            $blocking[] = 'barang keluar (sebagai paket)';
        }

        return $blocking;
    }

    /**
     * Cari barang dari kode yang discan: barcode dulu, lalu SKU.
     *
     * Spasi dan besar kecil huruf diabaikan karena scanner sering
     * menyisipkannya. Kolom kosong tidak pernah dianggap cocok.
     */
    public static function findByCode(?string $code): ?self
    {
        $trimmed = trim((string) $code);

        if ($trimmed === '') {
            return null;
        }

        // Jalur cepat: kode apa adanya masih bisa memakai indeks unik pada
        // barcode dan sku. Inilah yang terjadi pada hampir semua scan.
        $exact = static::query()
            ->where('barcode', $trimmed)
            ->orWhere('sku', $trimmed)
            ->first();

        if ($exact) {
            return $exact;
        }

        // Jalur lambat: baru dinormalkan bila kode mengandung spasi atau
        // beda besar kecil huruf. Perbandingan ini tidak bisa memakai indeks,
        // jadi sengaja tidak dijalankan lebih dulu.
        $needle = strtoupper(preg_replace('/\s+/', '', $trimmed));

        return static::query()
            ->whereRaw("UPPER(REPLACE(COALESCE(barcode, ''), ' ', '')) = ?", [$needle])
            ->orWhereRaw("UPPER(REPLACE(sku, ' ', '')) = ?", [$needle])
            ->first();
    }

    /**
     * Pencarian bebas untuk SKU, barcode, dan nama barang.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            });
        });
    }
}
