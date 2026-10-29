<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Formulir produk induk. Saat membuat, varian-variannya ikut dikirim
 * sekaligus; saat mengubah, hanya data induknya.
 */
class ProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isCreating() ? 'products.create' : 'products.update');
    }

    protected function isCreating(): bool
    {
        return $this->route('product_group') === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'option1_name' => ['required', 'string', 'max:50'],
            'option2_name' => ['nullable', 'string', 'max:50', 'different:option1_name'],
            'image' => ['nullable', 'image', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],
            'is_active' => ['boolean'],
        ];

        if ($this->isCreating()) {
            $rules += static::variantRules('variants', filled($this->input('option2_name')));
        }

        return $rules;
    }

    /**
     * Aturan baris varian baru. Dipakai juga oleh form tambah varian.
     *
     * @return array<string, mixed>
     */
    public static function variantRules(string $key, bool $hasSecondOption): array
    {
        return [
            $key => ['required', 'array', 'min:1', 'max:200'],
            "{$key}.*.option1" => ['required', 'string', 'max:100'],
            "{$key}.*.option2" => [$hasSecondOption ? 'required' : 'nullable', 'string', 'max:100'],
            "{$key}.*.sku" => ['required', 'string', 'max:64', 'distinct:ignore_case', 'unique:products,sku'],
            "{$key}.*.barcode" => ['nullable', 'string', 'max:64', 'distinct', 'unique:products,barcode'],
            "{$key}.*.location" => ['nullable', 'string', 'max:100'],
            "{$key}.*.min_stock" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$key}.*.image" => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function variantAttributes(): array
    {
        return [
            'variants' => 'varian',
            'variants.*.option1' => 'atribut varian',
            'variants.*.option2' => 'atribut kedua',
            'variants.*.sku' => 'SKU varian',
            'variants.*.barcode' => 'barcode varian',
            'variants.*.location' => 'lokasi rak',
            'variants.*.min_stock' => 'stok minimum',
            'variants.*.image' => 'gambar varian',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ];

        if (is_array($this->input('variants'))) {
            $merge['variants'] = collect($this->input('variants'))
                ->map(fn ($row) => is_array($row) ? array_merge($row, [
                    'sku' => Str::upper(trim((string) ($row['sku'] ?? ''))),
                    'barcode' => filled($row['barcode'] ?? null) ? trim((string) $row['barcode']) : null,
                    'min_stock' => $row['min_stock'] ?? 0,
                ]) : $row)
                ->values()
                ->all();
        }

        $this->merge($merge);
    }

    /**
     * Kombinasi atribut tidak boleh kembar di dalam satu produk.
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->isCreating()) {
            return;
        }

        $validator->after(function (Validator $validator) {
            $second = filled($this->input('option2_name'));
            $seen = [];

            foreach ((array) $this->input('variants', []) as $index => $row) {
                $label = Str::lower(ProductGroup::label($row['option1'] ?? null, $second ? ($row['option2'] ?? null) : null));

                if ($label !== '' && isset($seen[$label])) {
                    $validator->errors()->add("variants.{$index}.option1", 'Kombinasi varian ini muncul lebih dari sekali.');
                }

                $seen[$label] = true;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama produk',
            'category' => 'kategori',
            'unit' => 'satuan',
            'description' => 'deskripsi',
            'option1_name' => 'nama atribut pertama',
            'option2_name' => 'nama atribut kedua',
            'image' => 'gambar',
        ] + static::variantAttributes();
    }
}
