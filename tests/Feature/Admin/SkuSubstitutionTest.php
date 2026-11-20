<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\ReturnReceipt;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Substitusi SKU di QC Kemas: barang pesanan diganti barang lain, stok yang
 * berkurang adalah stok pengganti, dan semuanya tercatat.
 */
class SkuSubstitutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $black;

    protected Product $navy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->black = Product::create(['sku' => 'GMS-HTM-L', 'barcode' => '111', 'name' => 'Gamis Hitam L', 'unit' => 'pcs']);
        $this->navy = Product::create(['sku' => 'GMS-NVY-L', 'barcode' => '222', 'name' => 'Gamis Navy L', 'unit' => 'pcs']);

        $this->giveStock($this->black, 10);
        $this->giveStock($this->navy, 10);
    }

    public function test_scanning_a_foreign_item_offers_a_substitution(): void
    {
        $outbound = $this->open('RESI1', 3);

        $this->scan($outbound, '222')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'GMS-NVY-L tidak ada di pesanan · ganti SKU?')
            ->assertJsonPath('substitution.product.sku', 'GMS-NVY-L')
            ->assertJsonPath('substitution.lines.0.sku', 'GMS-HTM-L')
            ->assertJsonPath('substitution.lines.0.remaining', 3);
    }

    public function test_no_offer_without_the_flag_or_permission(): void
    {
        $outbound = $this->open('RESI1', 3);

        // Layar lama (tanpa flag) tetap mendapat pesan biasa.
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => '222'])
            ->assertStatus(422)
            ->assertJsonMissingPath('substitution');

        $staff = $this->staff();
        $this->actingAs($staff)
            ->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => '222', 'substitution' => true])
            ->assertStatus(422)
            ->assertJsonMissingPath('substitution');

        $this->actingAs($staff)
            ->postJson(route('admin.outbounds.scan.substitute', $outbound), [
                'item_id' => $outbound->items()->value('id'), 'code' => '222', 'reason' => 'stok_habis',
            ])
            ->assertForbidden();
    }

    public function test_partial_substitution_moves_units_and_counts_them_scanned(): void
    {
        $outbound = $this->open('RESI1', 3);
        $line = $outbound->items()->firstOrFail();

        $this->substitute($outbound, $line->id, '222', 1, 'stok_habis')
            ->assertOk()
            ->assertJsonPath('message', 'GMS-HTM-L diganti GMS-NVY-L · 1 pcs')
            ->assertJsonPath('progress.total', 3)
            ->assertJsonPath('progress.scanned', 1)
            ->assertJsonPath('items.1.sku', 'GMS-NVY-L')
            ->assertJsonPath('items.1.substituted_from.sku', 'GMS-HTM-L')
            ->assertJsonPath('items.1.substitution_reason', 'Stok habis');

        $items = $outbound->items()->orderBy('id')->get();
        $this->assertSame([2, 1], $items->pluck('quantity')->all());
        $this->assertSame($this->black->id, $items[1]->substituted_from_id);
        $this->assertSame($this->admin->id, $items[1]->substituted_by);

        // Sisa dua unit hitam masih discan seperti biasa, paket jadi lengkap.
        $this->scan($outbound, '111')->assertOk();
        $this->scan($outbound, '111')->assertOk()->assertJsonPath('progress.ready', true);
    }

    public function test_handover_deducts_the_substitute_stock_and_return_expects_it(): void
    {
        $outbound = $this->open('RESI1', 2);
        $line = $outbound->items()->firstOrFail();

        $this->substitute($outbound, $line->id, '222', 2, 'permintaan_pembeli')
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.handover.process'), ['ids' => [$outbound->id]])
            ->assertSessionHas('success');

        $this->assertSame(10, $this->black->refresh()->stock);
        $this->assertSame(8, $this->navy->refresh()->stock);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'RESI1'])
            ->assertOk();

        $return = ReturnReceipt::latest('id')->firstOrFail();
        $this->assertSame([$this->navy->id => 2], $return->items->pluck('quantity', 'product_id')->all());
    }

    public function test_rescanning_the_waybill_keeps_the_substitution(): void
    {
        $outbound = $this->open('RESI1', 3);
        $line = $outbound->items()->firstOrFail();
        $this->substitute($outbound, $line->id, '222', 1, 'rusak')->assertOk();
        $this->scan($outbound, '111')->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'RESI1'])
            ->assertOk()
            ->assertJsonPath('progress.total', 3)
            ->assertJsonPath('progress.scanned', 2);

        $substitute = $outbound->items()->whereNotNull('substituted_from_id')->firstOrFail();
        $this->assertSame(1, $substitute->quantity);
        $this->assertSame('rusak', $substitute->substitution_reason);
    }

    public function test_revert_and_reset_return_units_to_the_original_item(): void
    {
        $outbound = $this->open('RESI1', 3);
        $line = $outbound->items()->firstOrFail();
        $this->substitute($outbound, $line->id, '222', 3, 'stok_habis')->assertOk();

        // Seluruh baris hitam diganti: baris aslinya hilang.
        $this->assertSame(1, $outbound->items()->count());
        $substitute = $outbound->items()->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.outbounds.scan.substitute.revert', [$outbound, $substitute]))
            ->assertOk()
            ->assertJsonPath('progress.scanned', 0)
            ->assertJsonPath('items.0.sku', 'GMS-HTM-L')
            ->assertJsonPath('items.0.quantity', 3);

        $line = $outbound->items()->firstOrFail();
        $this->substitute($outbound, $line->id, '222', 2, 'stok_habis')->assertOk();

        $this->actingAs($this->admin)->post(route('admin.outbounds.scan.reset', $outbound));

        $this->assertSame([$this->black->id => 3], $outbound->items()->pluck('quantity', 'product_id')->all());
    }

    public function test_substitution_is_validated(): void
    {
        $outbound = $this->open('RESI1', 2);
        $line = $outbound->items()->firstOrFail();

        $this->substitute($outbound, $line->id, '222', 3, 'stok_habis')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'GMS-HTM-L sisa 2 pcs · tidak bisa diganti 3');

        $this->substitute($outbound, $line->id, '222', 1, 'lainnya')
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $empty = Product::create(['sku' => 'GMS-MRH-L', 'name' => 'Gamis Merah L', 'unit' => 'pcs']);
        $this->substitute($outbound, $line->id, 'GMS-MRH-L', 1, 'stok_habis')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Stok GMS-MRH-L kosong · tidak bisa jadi pengganti');

        $this->assertSame(1, $outbound->items()->count());
    }

    public function test_short_stock_package_opens_for_substituting_users(): void
    {
        $this->importOrder('RESI2', [['GMS-HTM-L', 20]]);

        $this->actingAs($this->staff())
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'RESI2', 'substitution' => true])
            ->assertStatus(422);

        // Tanpa flag (layar lama) tetap ditolak seperti dulu.
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'RESI2'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'RESI2', 'substitution' => true])
            ->assertOk()
            ->assertJsonPath('can_substitute', true);
    }

    public function test_substitution_page_and_export(): void
    {
        $outbound = $this->open('RESI1', 2);
        $this->substitute($outbound, $outbound->items()->value('id'), '222', 1, 'stok_habis')->assertOk();

        $this->actingAs($this->admin)->get(route('admin.orders.substitutions'))
            ->assertOk()
            ->assertSee('GMS-NVY-L')
            ->assertSee('GMS-HTM-L')
            ->assertSee('Stok habis');

        $this->actingAs($this->admin)->get(route('admin.orders.substitutions.export'))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.outbounds.show', $outbound))
            ->assertOk()
            ->assertSee('Pengganti dari GMS-HTM-L');
    }

    public function test_mobile_api_supports_substitution(): void
    {
        $outbound = $this->open('RESI1', 2);
        $token = $this->postJson(route('mobile.login'), [
            'email' => 'admin@cheery.test', 'password' => 'password', 'device_name' => 'test',
        ])->assertOk()->assertJsonPath('user.can.substitusi', true)->json('token');

        $headers = ['Authorization' => "Bearer {$token}"];

        $this->postJson(route('mobile.kemas.item', $outbound), ['code' => '222', 'substitution' => true], $headers)
            ->assertStatus(422)
            ->assertJsonPath('substitution.product.sku', 'GMS-NVY-L');

        $this->postJson(route('mobile.kemas.substitute', $outbound), [
            'item_id' => $outbound->items()->value('id'), 'code' => '222', 'quantity' => 2, 'reason' => 'stok_habis',
        ], $headers)->assertOk()->assertJsonPath('completed', true);
    }

    /* ------------------------------------------------------- helpers -- */

    protected function open(string $tracking, int $quantity): Outbound
    {
        $this->importOrder($tracking, [['GMS-HTM-L', $quantity]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => $tracking])
            ->assertOk();

        return Outbound::where('tracking_number', $tracking)->firstOrFail();
    }

    protected function scan(Outbound $outbound, string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => $code, 'substitution' => true]);
    }

    protected function substitute(Outbound $outbound, int $itemId, string $code, int $quantity, string $reason)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.substitute', $outbound), [
                'item_id' => $itemId, 'code' => $code, 'quantity' => $quantity, 'reason' => $reason,
            ]);
    }

    protected function staff(): User
    {
        return User::create([
            'name' => 'Staf', 'email' => 'staf@cheery.test', 'password' => bcrypt('password'),
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'), 'is_active' => true,
        ]);
    }

    protected function importOrder(string $tracking, array $items): ShipmentOrder
    {
        $import = ShipmentImport::create(['filename' => 'tiktok.xlsx', 'source' => 'tiktok']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'TikTok Shop',
            'buyer_name' => 'Pembeli',
        ]);

        foreach ($items as [$sku, $quantity]) {
            $order->items()->create([
                'sku' => $sku,
                'quantity' => $quantity,
                'product_id' => Product::where('sku', $sku)->value('id'),
            ]);
        }

        return $order;
    }

    protected function giveStock(Product $product, int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
