<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FabricUsage;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BOM kain per barang, diatur dari halaman barang: kain apa saja dan
 * berapa banyak untuk 1 unit barang. Opsional; barang tanpa BOM kain tetap
 * jalan seperti biasa, hanya tidak ikut dihitung di Kebutuhan Kain.
 *
 * Datanya sama dengan Pemakaian Kain per Barang di halaman detail kain
 * (tabel fabric_usages), jadi mengubah dari sisi mana pun hasilnya sama.
 */
class ProductFabricController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:fabrics.manage')];
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        if ($product->isBundle()) {
            throw ValidationException::withMessages(['fabrics' => 'Paket tidak punya BOM kain; atur BOM di barang isinya.']);
        }

        $data = $request->validate([
            'fabrics' => ['nullable', 'array', 'max:30'],
            'fabrics.*.fabric_id' => ['required', 'integer', 'distinct', 'exists:fabrics,id'],
            'fabrics.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'variants' => ['nullable', 'boolean'],
        ], [
            'fabrics.*.fabric_id.distinct' => 'Kain yang sama tercantum dua kali.',
            'fabrics.*.quantity.gt' => 'Pemakaian kain harus lebih dari 0.',
        ], [
            'fabrics.*.fabric_id' => 'kain',
            'fabrics.*.quantity' => 'pemakaian kain',
        ]);

        $lines = collect($data['fabrics'] ?? [])
            ->mapWithKeys(fn ($line) => [(int) $line['fabric_id'] => round((float) $line['quantity'], 3)]);

        $targets = collect([$product]);

        if ($request->boolean('variants') && $product->product_group_id) {
            $targets = Product::query()->where('product_group_id', $product->product_group_id)->get();
        }

        DB::transaction(function () use ($targets, $lines) {
            foreach ($targets as $target) {
                FabricUsage::query()->where('product_id', $target->id)->whereNotIn('fabric_id', $lines->keys())->delete();

                foreach ($lines as $fabricId => $quantity) {
                    FabricUsage::updateOrCreate(
                        ['product_id' => $target->id, 'fabric_id' => $fabricId],
                        ['quantity' => $quantity],
                    );
                }
            }
        });

        $message = $lines->isEmpty() ? 'BOM kain dikosongkan' : "BOM kain disimpan: {$lines->count()} kain";

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', $message.($targets->count() > 1 ? " untuk {$targets->count()} varian." : '.'));
    }
}
