<?php

namespace Tests\Feature\Audit;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dokumen yang sudah diposting tidak boleh bisa diposting ulang,
 * misalnya karena tombol "Ajukan" diklik dua kali atau dikirim ulang.
 */
class DoublePostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_resubmitting_a_posted_document_does_not_move_stock_twice(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $product = Product::create(['sku' => 'A-1', 'name' => 'Barang A', 'unit' => 'pcs', 'min_stock' => 0]);
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'Pemasok']);

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'supplier_id' => $supplier->id, 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 10]);

        $this->actingAs($admin)->post(route('admin.inbounds.submit', $inbound));
        $this->actingAs($admin)->post(route('admin.inbounds.submit', $inbound))->assertSessionHas('error');

        $this->assertSame(10, $product->refresh()->stock);
        $this->assertSame(1, $product->movements()->count());
    }

    /**
     * Nomor urut ke-10.000 dalam sebulan tidak boleh bertabrakan: sebagai
     * teks, "-10000" lebih kecil dari "-9999".
     */
    public function test_document_numbers_keep_counting_past_9999(): void
    {
        $prefix = 'OUT-'.now()->format('Ym').'-';

        foreach (['9999', '10000'] as $sequence) {
            \App\Models\Outbound::create([
                'code' => $prefix.$sequence, 'date' => now(), 'type' => 'regular',
                'recipient' => 'X', 'status' => 'draft',
            ]);
        }

        $this->assertSame($prefix.'10001', \App\Models\Outbound::nextCode());
    }

    /**
     * Dua stasiun membaca nomor terakhir yang sama: penyimpanan kedua
     * mendapat nomor berikutnya, bukan galat.
     */
    public function test_a_taken_document_number_is_replaced_instead_of_failing(): void
    {
        $code = \App\Models\Outbound::nextCode();

        $first = \App\Models\Outbound::create(['code' => $code, 'date' => now(), 'type' => 'regular', 'recipient' => 'X', 'status' => 'draft']);
        $second = \App\Models\Outbound::create(['code' => $code, 'date' => now(), 'type' => 'regular', 'recipient' => 'Y', 'status' => 'draft']);

        $this->assertNotSame($first->code, $second->code);
        $this->assertSame(2, \App\Models\Outbound::count());
    }
}
