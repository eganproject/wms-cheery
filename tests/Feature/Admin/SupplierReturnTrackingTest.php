<?php

namespace Tests\Feature\Admin;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pelacakan barang yang dikembalikan ke pemasok: dikirim, barang pengganti
 * diterima masuk stok layak jual, sisanya ditutup sebagai ditolak pemasok.
 */
class SupplierReturnTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
        $this->supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);

        $this->giveStock(50);
    }

    public function test_a_processed_supplier_return_waits_to_be_shipped(): void
    {
        $disposal = $this->processedReturn(10);

        $this->assertSame(DamagedDisposal::RETURN_UNSENT, $disposal->return_status);
        $this->assertSame('Diproses', $disposal->statusLabel());
        $this->assertSame(40, $this->product->refresh()->stock);
    }

    public function test_other_actions_have_no_follow_up(): void
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(), 'bucket' => 'good',
            'action' => DamagedDisposal::ACTION_DISCARD,
            'quantities' => [$this->product->id => 2],
        ]);
        $disposal = DamagedDisposal::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.disposals.submit', $disposal));

        $this->assertNull($disposal->refresh()->return_status);
        $this->assertSame('Selesai', $disposal->statusLabel());
    }

    public function test_replacements_need_the_return_to_be_shipped_first(): void
    {
        $disposal = $this->processedReturn(10);
        $item = $disposal->items->first();

        $this->actingAs($this->admin)->post(route('admin.disposals.replace', $disposal), [
            'date' => now()->toDateString(), 'replacements' => [$item->id => 4],
        ])->assertSessionHas('error');

        $this->assertSame(40, $this->product->refresh()->stock);
    }

    public function test_full_flow_ship_replace_partly_then_reject_the_rest(): void
    {
        $disposal = $this->processedReturn(10);
        $item = $disposal->items->first();

        $this->actingAs($this->admin)->post(route('admin.disposals.ship', $disposal), [
            'shipped_at' => now()->toDateString(), 'courier' => 'JNE', 'tracking_number' => 'JNE123',
        ])->assertSessionHas('success');

        $disposal->refresh();
        $this->assertSame(DamagedDisposal::RETURN_SENT, $disposal->return_status);
        $this->assertSame('JNE123', $disposal->tracking_number);

        // Lebih dari yang dikirim ditolak.
        $this->actingAs($this->admin)->post(route('admin.disposals.replace', $disposal), [
            'date' => now()->toDateString(), 'replacements' => [$item->id => 11],
        ])->assertSessionHas('error');

        $this->actingAs($this->admin)->post(route('admin.disposals.replace', $disposal), [
            'date' => now()->toDateString(), 'note' => 'SJ-77', 'replacements' => [$item->id => 6],
        ])->assertSessionHas('success');

        $this->assertSame(46, $this->product->refresh()->stock);
        $this->assertSame(6, $item->refresh()->replaced_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id, 'type' => 'in', 'bucket' => StockMovement::BUCKET_GOOD,
            'quantity' => 6, 'reference_type' => DamagedDisposal::class, 'reference_id' => $disposal->id,
        ]);
        $this->assertSame(DamagedDisposal::RETURN_SENT, $disposal->refresh()->return_status);

        $this->actingAs($this->admin)->get(route('admin.disposals.show', $disposal))
            ->assertOk()->assertSee('Retur ke Pemasok')->assertSee('SJ-77');

        $this->actingAs($this->admin)->post(route('admin.disposals.reject-return', $disposal), [
            'close_note' => 'Rusak karena penyimpanan',
        ])->assertSessionHas('success');

        $disposal->refresh();
        $this->assertSame(DamagedDisposal::RETURN_DONE, $disposal->return_status);
        $this->assertSame(4, $item->refresh()->rejected_quantity);
        $this->assertSame(0, $item->outstandingQuantity());
        $this->assertNotNull($disposal->closed_at);
        $this->assertSame('Selesai', $disposal->statusLabel());
        // Tanpa dikirim balik, stok tidak berubah.
        $this->assertSame(46, $this->product->refresh()->stock);

        // Retur yang selesai tidak bisa diubah lagi.
        $this->actingAs($this->admin)->post(route('admin.disposals.ship', $disposal), [
            'shipped_at' => now()->toDateString(),
        ])->assertSessionHas('error');
    }

    public function test_full_replacement_finishes_the_return(): void
    {
        $disposal = $this->processedReturn(5);
        $item = $disposal->items->first();

        $this->actingAs($this->admin)->post(route('admin.disposals.ship', $disposal), ['shipped_at' => now()->toDateString()]);
        $this->actingAs($this->admin)->post(route('admin.disposals.replace', $disposal), [
            'date' => now()->toDateString(), 'replacements' => [$item->id => 5],
        ])->assertSessionHas('success');

        $this->assertSame(DamagedDisposal::RETURN_DONE, $disposal->refresh()->return_status);
        $this->assertSame(50, $this->product->refresh()->stock);
    }

    public function test_rejected_goods_sent_back_return_to_their_source_balance(): void
    {
        $disposal = $this->processedReturn(10);

        $this->actingAs($this->admin)->post(route('admin.disposals.ship', $disposal), ['shipped_at' => now()->toDateString()]);
        $this->actingAs($this->admin)->post(route('admin.disposals.reject-return', $disposal), [
            'close_note' => 'Bukan cacat produksi', 'back_to_warehouse' => '1',
        ])->assertSessionHas('success');

        $this->assertSame(50, $this->product->refresh()->stock);
        $this->assertSame(DamagedDisposal::RETURN_DONE, $disposal->refresh()->return_status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $disposal = $this->processedReturn(10);
        $this->actingAs($this->admin)->post(route('admin.disposals.ship', $disposal), ['shipped_at' => now()->toDateString()]);

        $this->actingAs($this->admin)->post(route('admin.disposals.reject-return', $disposal), [])
            ->assertSessionHasErrors('close_note');
    }

    public function test_the_supplier_tab_lists_open_returns_and_exports(): void
    {
        $disposal = $this->processedReturn(10);

        $this->actingAs($this->admin)->get(route('admin.disposals.index', ['tab' => 'pemasok']))
            ->assertOk()->assertSee($disposal->code)->assertSee('PT Sumber Otoparts')->assertSee('Belum dikirim');

        $this->actingAs($this->admin)->get(route('admin.disposals.index', ['tab' => 'pemasok', 'return_status' => DamagedDisposal::RETURN_DONE]))
            ->assertOk()->assertDontSee($disposal->code);

        $this->actingAs($this->admin)->get(route('admin.disposals.export', ['tab' => 'pemasok']))
            ->assertOk()->assertDownload();
    }

    protected function processedReturn(int $quantity): DamagedDisposal
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(), 'bucket' => 'good',
            'action' => DamagedDisposal::ACTION_RETURN, 'supplier_id' => $this->supplier->id,
            'quantities' => [$this->product->id => $quantity],
        ])->assertSessionHas('success');

        $disposal = DamagedDisposal::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.disposals.submit', $disposal))->assertSessionHas('success');

        return $disposal->refresh()->load('items');
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function giveDamagedStock(int $quantity): void
    {
        $this->process($this->make(DamagedDisposal::ACTION_WRITE_OFF, $quantity));
    }
}
