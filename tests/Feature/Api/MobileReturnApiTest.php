<?php

namespace Tests\Feature\Api;

use App\Models\MobileToken;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Terima Retur dari aplikasi Android: memakai controller web yang sama.
 */
class MobileReturnApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->token = MobileToken::issue($this->admin, 'Tes')[1];

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    public function test_profile_tells_the_app_about_return_access(): void
    {
        $this->withToken($this->token)->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.can.retur', true)
            ->assertJsonPath('user.can.retur_terima', true);
    }

    public function test_manual_return_with_foreign_goods_from_the_phone(): void
    {
        $this->withToken($this->token)->postJson('/api/mobile/retur/resi', ['code' => 'RESI-LAMA-1'])
            ->assertOk()->assertJsonPath('found', false);

        $payload = $this->withToken($this->token)->postJson('/api/mobile/retur/manual', [
            'tracking_number' => 'RESI-LAMA-1', 'code' => 'oli standar',
        ])->assertOk()->assertJsonPath('items.0.sku', 'FLT-OLI-STD')->json();

        $returnId = $payload['return']['id'];
        $itemId = $payload['items'][0]['id'];

        $this->withToken($this->token)->post("/api/mobile/retur/foreign", [
            'return_id' => $returnId,
            'description' => 'Kaos merek lain',
            'quantity' => 1,
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'foreign');

        $this->withToken($this->token)->postJson("/api/mobile/retur/{$returnId}/finish", [
            'items' => [$itemId => ['good' => 1, 'damaged' => 0, 'expected' => 2]],
        ])->assertOk()
            ->assertJsonPath('posted', true)
            ->assertJsonPath('missing', 1)
            ->assertJsonPath('foreign', 1);

        $this->assertSame(1, $this->product->refresh()->stock);
        $this->assertSame(ReturnReceipt::FOREIGN_OPEN, ReturnReceipt::find($returnId)->foreign_status);
    }

    public function test_wrong_lines_can_be_removed_from_the_phone(): void
    {
        $payload = $this->withToken($this->token)->postJson('/api/mobile/retur/manual', ['code' => 'FLT-OLI-STD'])
            ->assertOk()->json();

        $this->withToken($this->token)
            ->deleteJson("/api/mobile/retur/{$payload['return']['id']}/item/{$payload['items'][0]['id']}")
            ->assertOk()->assertJsonCount(0, 'items');
    }
}
