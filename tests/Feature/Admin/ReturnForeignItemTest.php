<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Barang bukan milik kita di paket retur, dan cari barang lewat nama.
 */
class ReturnForeignItemTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    public function test_a_package_of_only_foreign_goods_is_recorded_without_touching_stock(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.returns.marketplace.foreign'), [
            'tracking_number' => 'RESI-ASING-1',
            'description' => 'Kaos hitam merek lain',
            'quantity' => 2,
            'photo' => UploadedFile::fake()->image('asing.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonPath('foreign.0.description', 'Kaos hitam merek lain')
            ->assertJsonPath('foreign.0.quantity', 2);

        $return = ReturnReceipt::firstOrFail();
        $this->assertSame('RESI-ASING-1', $return->tracking_number);
        $this->assertSame(ReturnReceipt::FOREIGN_OPEN, $return->foreign_status);

        $foreign = $return->foreignItems()->first();
        Storage::disk('local')->assertExists($foreign->photo_path);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.finish', $return), ['items' => []])
            ->assertOk()
            ->assertJsonPath('posted', true)
            ->assertJsonPath('foreign', 2);

        $this->assertSame(0, $this->product->refresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->actingAs($this->admin)->get(route('admin.returns.foreign.photo', $foreign))->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.returns.show', $return))
            ->assertOk()
            ->assertSee('Barang Bukan Milik Kita')
            ->assertSee('Kaos hitam merek lain');
    }

    public function test_foreign_goods_sit_beside_our_goods_and_only_ours_enter_stock(): void
    {
        $return = ReturnReceipt::find($this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.manual'), ['code' => 'FLT-OLI-STD'])
            ->assertOk()
            ->json('return.id'));

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.foreign'), [
            'return_id' => $return->id,
            'description' => 'Botol sampo merek lain',
            'quantity' => 1,
        ])->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(1, 'foreign');

        $item = $return->items()->first();

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), [
            'items' => [$item->id => ['good' => 1, 'damaged' => 0, 'expected' => 1]],
        ])->assertOk()->assertJsonPath('missing', 0);

        $this->assertSame(1, $this->product->refresh()->stock);
    }

    public function test_follow_up_can_be_updated_after_the_return_is_posted_and_filtered(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.foreign'), [
            'description' => 'Sepatu bukan produk kita',
            'quantity' => 1,
        ])->assertOk();

        $return = ReturnReceipt::firstOrFail();
        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), ['items' => []])->assertOk();

        $this->actingAs($this->admin)->put(route('admin.returns.foreign.followup', $return), [
            'foreign_status' => ReturnReceipt::FOREIGN_CLAIMED,
            'foreign_note' => 'Klaim TikTok 123',
        ])->assertRedirect();

        $return->refresh();
        $this->assertSame(ReturnReceipt::FOREIGN_CLAIMED, $return->foreign_status);
        $this->assertSame('Klaim TikTok 123', $return->foreign_note);
        $this->assertNotNull($return->foreign_updated_at);

        $this->actingAs($this->admin)->get(route('admin.returns.index', ['foreign' => 'claimed']))
            ->assertOk()->assertSee($return->code);
        $this->actingAs($this->admin)->get(route('admin.returns.index', ['foreign' => 'done']))
            ->assertOk()->assertDontSee($return->code);
    }

    public function test_removing_the_last_foreign_line_clears_the_follow_up(): void
    {
        $payload = $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.foreign'), [
            'description' => 'Salah tulis',
            'quantity' => 1,
        ])->assertOk()->json();

        $this->actingAs($this->admin)->deleteJson($payload['foreign'][0]['remove_url'])
            ->assertOk()->assertJsonCount(0, 'foreign');

        $this->assertNull(ReturnReceipt::firstOrFail()->foreign_status);
    }

    public function test_items_can_be_found_by_name_when_the_label_is_missing(): void
    {
        Product::create(['sku' => 'FLT-OLI-SPT', 'name' => 'Filter Oli Sport', 'unit' => 'pcs', 'min_stock' => 0]);

        // Satu nama cocok: langsung dipakai.
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.manual'), ['code' => 'oli standar'])
            ->assertOk()
            ->assertJsonPath('scanned.sku', 'FLT-OLI-STD');

        $return = ReturnReceipt::firstOrFail();

        // Beberapa cocok: dikirim balik sebagai pilihan, tidak ada yang ditambahkan.
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.item', $return), ['code' => 'filter oli'])
            ->assertOk()
            ->assertJsonCount(2, 'candidates');

        $this->assertSame(1, $return->items()->sum('quantity'));

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.item', $return), ['code' => 'tidak ada sama sekali'])
            ->assertStatus(422);
    }
}
