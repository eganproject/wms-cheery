<?php

namespace Tests\Feature\Api;

use App\Models\Inbound;
use App\Models\MobileToken;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * API aplikasi Android Scan Kemas & Serah Terima.
 */
class MobileAppApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $inbound = Inbound::create(['code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => 20]);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
        $this->app['auth']->forgetGuards();
    }

    /* --------------------------------------------------- login ----------- */

    public function test_login_returns_a_token_and_menu_access(): void
    {
        $response = $this->postJson('/api/mobile/login', [
            'email' => 'admin@cheery.test', 'password' => 'password', 'device_name' => 'HP Packing 1',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'admin@cheery.test')
            ->assertJsonPath('user.can.kemas', true)
            ->assertJsonPath('user.can.serah_terima', true)
            ->assertJsonPath('user.can.pesanan', true);

        $token = $response->json('token');
        $this->assertSame(60, strlen($token));
        // Hanya hash yang tersimpan.
        $this->assertDatabaseMissing('mobile_tokens', ['token' => $token]);
        $this->assertDatabaseHas('mobile_tokens', ['token' => hash('sha256', $token), 'name' => 'HP Packing 1']);

        $this->withToken($token)->getJson('/api/mobile/me')->assertOk()->assertJsonPath('user.name', 'Super Admin');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/mobile/login', ['email' => 'admin@cheery.test', 'password' => 'salah'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email atau sandi salah.');
    }

    public function test_inactive_users_can_not_log_in_or_keep_using_a_token(): void
    {
        $token = $this->tokenFor($this->admin);
        $this->admin->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/mobile/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/mobile/login', ['email' => 'admin@cheery.test', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_requests_without_a_token_get_json_401(): void
    {
        // Tanpa header Accept pun jawabannya tetap JSON, bukan redirect ke login.
        $this->get('/api/mobile/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->withToken('bukan-token')->getJson('/api/mobile/summary')->assertUnauthorized();
    }

    public function test_logout_revokes_only_that_token(): void
    {
        $first = $this->tokenFor($this->admin);
        $second = $this->tokenFor($this->admin);

        $this->withToken($first)->postJson('/api/mobile/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($first)->getJson('/api/mobile/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/mobile/me')->assertOk();
    }

    /* --------------------------------------------------- scan kemas ------ */

    public function test_packing_and_handover_run_through_the_same_flow_as_the_web(): void
    {
        $order = $this->importOrder('SPXID111', 2);
        $token = $this->tokenFor($this->admin);

        $resi = $this->withToken($token)->postJson('/api/mobile/kemas/resi', ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('items.0.sku', 'FLT-OLI-STD')
            ->assertJsonPath('progress.total', 2);

        $outboundId = $resi->json('outbound.id');
        $this->assertSame($this->admin->id, Outbound::find($outboundId)->user_id);

        // Belum lengkap: belum boleh diserahkan.
        $this->withToken($token)->postJson("/api/mobile/kemas/{$outboundId}/item", ['code' => '8991234500035'])
            ->assertOk()->assertJsonPath('progress.scanned', 1);
        $this->withToken($token)->postJson('/api/mobile/serah-terima/scan', ['code' => 'SPXID111'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Belum selesai QC · sisa 1 unit');

        $this->withToken($token)->postJson("/api/mobile/kemas/{$outboundId}/item", ['code' => 'FLT-OLI-STD'])
            ->assertOk()->assertJsonPath('progress.ready', true);

        $this->withToken($token)->getJson('/api/mobile/orders?stage=serah-terima')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $order->id);

        $this->withToken($token)->postJson('/api/mobile/serah-terima/scan', ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('shipped', true)
            ->assertJsonPath('remaining', 0);

        $this->assertSame(18, $this->product->refresh()->stock);
        $this->withToken($token)->getJson('/api/mobile/orders?stage=dikirim')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stage', 'dikirim');
    }

    public function test_reset_clears_the_scan_progress(): void
    {
        $this->importOrder('SPXID111', 2);
        $token = $this->tokenFor($this->admin);

        $id = $this->withToken($token)->postJson('/api/mobile/kemas/resi', ['code' => 'SPXID111'])->json('outbound.id');
        $this->withToken($token)->postJson("/api/mobile/kemas/{$id}/item", ['code' => 'FLT-OLI-STD'])->assertOk();

        $this->withToken($token)->postJson("/api/mobile/kemas/{$id}/reset")->assertOk();

        $outbound = Outbound::with('items')->find($id);
        $this->assertFalse($outbound->isResiVerified());
        $this->assertSame(0, $outbound->totalScanned());
    }

    public function test_menus_follow_the_users_permissions(): void
    {
        $role = Role::create(['name' => 'Kurir', 'slug' => 'kurir', 'is_super_admin' => false]);
        $user = User::create([
            'name' => 'Kurir', 'email' => 'kurir@cheery.test', 'password' => Hash::make('password'),
            'role_id' => $role->id, 'is_active' => true,
        ]);
        $this->importOrder('SPXID111', 1);

        $login = $this->postJson('/api/mobile/login', ['email' => 'kurir@cheery.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.can.kemas', false)
            ->assertJsonPath('user.can.serah_terima', false)
            ->assertJsonPath('user.can.pesanan', false);

        $token = $login->json('token');
        $this->withToken($token)->postJson('/api/mobile/kemas/resi', ['code' => 'SPXID111'])->assertForbidden();
        $this->withToken($token)->postJson('/api/mobile/serah-terima/scan', ['code' => 'SPXID111'])->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/orders')->assertForbidden();
    }

    /* --------------------------------------------------- daftar ---------- */

    public function test_the_lists_split_packed_and_unpacked_orders(): void
    {
        $waiting = $this->importOrder('SPXID111', 1);
        $packed = $this->importOrder('SPXID222', 1);
        $token = $this->tokenFor($this->admin);

        $id = $this->withToken($token)->postJson('/api/mobile/kemas/resi', ['code' => 'SPXID222'])->json('outbound.id');
        $this->withToken($token)->postJson("/api/mobile/kemas/{$id}/item", ['code' => 'FLT-OLI-STD'])->assertOk();

        $this->withToken($token)->getJson('/api/mobile/orders?stage=belum-qc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tracking_number', 'SPXID111')
            ->assertJsonPath('data.0.stage_label', 'Belum Dikemas')
            ->assertJsonPath('data.0.items.0.sku', 'FLT-OLI-STD');

        $this->withToken($token)->getJson('/api/mobile/orders?stage=serah-terima')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tracking_number', 'SPXID222')
            ->assertJsonPath('data.0.stage_label', 'Sudah Dikemas')
            ->assertJsonPath('data.0.scanned_quantity', 1);

        $this->withToken($token)->getJson('/api/mobile/orders?stage=belum-qc&search=SPXID222')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->withToken($token)->getJson('/api/mobile/summary')
            ->assertOk()
            ->assertJsonPath('belum_dikemas', 1)
            ->assertJsonPath('sudah_dikemas', 1)
            ->assertJsonPath('dikirim', 0);

        $this->withToken($token)->getJson("/api/mobile/orders/{$packed->id}")
            ->assertOk()
            ->assertJsonPath('order.packed_items.0.scanned', 1)
            ->assertJsonPath('order.outbound_code', Outbound::find($id)->code);

        $this->withToken($token)->getJson("/api/mobile/orders/{$waiting->id}")
            ->assertOk()
            ->assertJsonPath('order.packed_items', []);
    }

    /* --------------------------------------------------- bantuan --------- */

    protected function tokenFor(User $user): string
    {
        return MobileToken::issue($user, 'Tes')[1];
    }

    protected function importOrder(string $tracking, int $quantity): ShipmentOrder
    {
        $import = ShipmentImport::first() ?? ShipmentImport::create(['filename' => 'tiktok.xlsx', 'source' => 'tiktok']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'TikTok Shop',
            'buyer_name' => 'Pembeli',
        ]);

        $order->items()->create([
            'sku' => 'FLT-OLI-STD',
            'product_name' => 'Filter Oli Standar',
            'quantity' => $quantity,
            'product_id' => $this->product->id,
        ]);

        return $order;
    }
}
