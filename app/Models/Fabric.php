<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis kain (SKU kain). Stoknya adalah jumlah sisa semua roll-nya.
 */
class Fabric extends Model
{
    /** Satuan yang bisa dipilih; selain ini boleh diketik sendiri. */
    public const UNITS = [
        'meter' => 'Meter (m)',
        'yard' => 'Yard (yd)',
        'kg' => 'Kilogram (kg)',
    ];

    protected $fillable = [
        'sku',
        'name',
        'color',
        'unit',
        'min_stock',
        'supplier_id',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'float',
            'is_active' => 'boolean',
            'last_roll_number' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(FabricRoll::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FabricMovement::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(FabricUsage::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'fabric_usages')->withPivot('quantity')->withTimestamps();
    }

    /** Saldo kain (jumlah sisa roll) dan banyaknya roll yang masih berisi. */
    public function scopeWithStock(Builder $query): Builder
    {
        return $query
            ->withSum(['rolls as stock' => fn (Builder $query) => $query->where('quantity', '>', 0)], 'quantity')
            ->withCount(['rolls as open_rolls' => fn (Builder $query) => $query->where('quantity', '>', 0)]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('sku', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('color', 'like', "%{$term}%");
            });
        });
    }

    /** "Toyobo · Hitam" */
    public function displayName(): string
    {
        return $this->color ? $this->name.' · '.$this->color : $this->name;
    }

    /** Singkatan satuan untuk angka: m, yd, kg, atau satuan yang diketik. */
    public function unitShort(): string
    {
        return self::shortUnit($this->unit);
    }

    public static function shortUnit(?string $unit): string
    {
        return match (strtolower((string) $unit)) {
            'meter' => 'm',
            'yard' => 'yd',
            'kg' => 'kg',
            default => (string) $unit,
        };
    }

    /** 12,5 → "12,5 m" */
    public function format(float|int|string|null $quantity): string
    {
        return self::formatQuantity($quantity, $this->unitShort());
    }

    public static function formatQuantity(float|int|string|null $quantity, string $unit = ''): string
    {
        $text = number_format(round((float) $quantity, 3), 3, ',', '.');
        $text = rtrim(rtrim($text, '0'), ',');
        $text = $text === '-0' ? '0' : $text;

        return $unit !== '' ? $text.' '.$unit : $text;
    }

    /**
     * Kode roll berikutnya untuk kain ini: SKU + nomor urut, contoh
     * TYB-HTM-007. Dipanggil di dalam transaksi dengan baris kain terkunci.
     */
    public function nextRollNumber(): int
    {
        $number = max($this->last_roll_number, (int) $this->rolls()->max('number')) + 1;
        $this->forceFill(['last_roll_number' => $number])->save();

        return $number;
    }

    public function rollCode(int $number): string
    {
        return $this->sku.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
