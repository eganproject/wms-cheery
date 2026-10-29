<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Produk induk: nama, kategori, dan gambar utama bagi sekumpulan varian.
 *
 * Induk tidak punya stok. Setiap varian adalah baris products biasa dengan
 * SKU dan saldonya sendiri, jadi induk hanyalah pengelompokan untuk tampilan.
 */
class ProductGroup extends Model
{
    protected $fillable = [
        'name',
        'category',
        'unit',
        'description',
        'option1_name',
        'option2_name',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('option1')->orderBy('option2')->orderBy('id');
    }

    public function hasSecondOption(): bool
    {
        return filled($this->option2_name);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * Tulis ulang nama dan kategori setiap varian dari induknya.
     *
     * Nama lengkap varian ("Kaos Polos - Merah / L") sengaja disimpan di
     * products.name. Puluhan layar, laporan, export, dan pencarian sudah
     * membaca kolom itu, sehingga semuanya langsung menampilkan nama varian
     * tanpa perlu diubah satu per satu.
     */
    public function syncVariants(): void
    {
        $this->variants()->get()->each(function (Product $variant) {
            $variant->setRelation('group', $this);
            $variant->fillFromGroup();
            $variant->saveQuietly();
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $query, string $term) => $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('category', 'like', "%{$term}%")
            ->orWhereHas('variants', fn (Builder $query) => $query
                ->where('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%"))));
    }

    /**
     * Label varian dari dua nilai atributnya, mis. "Merah / L".
     */
    public static function label(?string $option1, ?string $option2): string
    {
        return collect([$option1, $option2])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->implode(' / ');
    }

    /**
     * Nama lengkap varian, mis. "Kaos Polos - Merah / L".
     */
    public function variantName(?string $option1, ?string $option2): string
    {
        $label = static::label($option1, $option2);

        return $label === '' ? $this->name : "{$this->name} - {$label}";
    }
}
