<?php

namespace Tests\Feature\Admin;

use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Models\FabricReceipt;
use App\Models\FabricRoll;
use App\Models\MobileToken;
use App\Models\Product;
use App\Models\ProductPoPeriod;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrderPlanner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stok kain: roll ber-QR (SKU kain + nomor roll), kain keluar hanya lewat
 * scan, cek & koreksi roll, dan kebutuhan kain dari Rencana Produksi.
 */
class FabricStockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-12 09:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    protected function fabric(array $attributes = []): Fabric
    {
        return Fabric::create($attributes + ['sku' => 'TYB-HTM', 'name' => 'Toyobo', 'color' => 'Hitam', 'unit' => 'meter']);
    }

    protected function receive(Fabric $fabric, float $quantity = 50, int $rolls = 2): FabricReceipt
    {
        $this->actingAs($this->admin)->post(route('admin.fabric-receipts.store'), [
            'date' => '2026-06-12',
            'reference' => 'SJ-01',
            'lines' => [['fabric_id' => $fabric->id, 'quantity' => $quantity, 'rolls' => $rolls, 'lot' => 'L1']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        return FabricReceipt::latest('id')->firstOrFail();
    }

    public function test_fabric_master_supports_any_unit_and_pages_render(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'Toko Kain', 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), [
            'sku' => 'KTN-PTH', 'name' => 'Katun', 'color' => 'Putih', 'unit' => 'kg', 'min_stock' => '10', 'supplier_id' => $supplier->id, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), [
            'sku' => 'RND-01', 'name' => 'Renda', 'unit' => 'lainnya', 'unit_other' => 'lembar', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        // Isian minimal: stok minimum, pemasok, dan catatan dikosongkan.
        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), [
            'sku' => 'SFN-MRH', 'name' => 'Satin', 'color' => 'Merah', 'unit' => 'meter', 'unit_other' => '',
            'min_stock' => '', 'supplier_id' => '', 'note' => '', 'is_active' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0.0, Fabric::where('sku', 'SFN-MRH')->firstOrFail()->min_stock);

        $this->assertSame('kg', Fabric::where('sku', 'KTN-PTH')->value('unit'));
        $this->assertSame('lembar', Fabric::where('sku', 'RND-01')->value('unit'));

        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), ['sku' => 'ada spasi', 'name' => 'X', 'unit' => 'meter'])
            ->assertSessionHasErrors('sku');

        foreach (['admin.fabrics.index', 'admin.fabrics.create', 'admin.fabric-scan', 'admin.fabric-receipts.index', 'admin.fabric-receipts.create', 'admin.fabric-issues.index', 'admin.fabric-needs.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }

        $this->actingAs($this->admin)->get(route('admin.fabrics.show', Fabric::first()))->assertOk()->assertSee('Pemakaian Kain per Barang');
        $this->actingAs($this->admin)->get(route('admin.fabrics.export'))->assertOk();
    }

    public function test_receiving_creates_rolls_with_qr_codes_from_fabric_sku(): void
    {
        $fabric = $this->fabric();
        $receipt = $this->receive($fabric, 50, 2);
        $this->receive($fabric, 32.5, 1);

        $this->assertSame(['TYB-HTM-001', 'TYB-HTM-002', 'TYB-HTM-003'], FabricRoll::orderBy('id')->pluck('code')->all());
        $this->assertSame(132.5, (float) Fabric::withStock()->find($fabric->id)->stock);
        $this->assertSame(3, FabricMovement::where('type', 'in')->count());

        $this->actingAs($this->admin)->get(route('admin.fabric-receipts.show', $receipt))
            ->assertOk()->assertSee('TYB-HTM-001')->assertSee('Cetak Label QR');

        $ids = $receipt->rolls->pluck('id')->all();
        $this->actingAs($this->admin)->get(route('admin.fabric-labels.create', ['rolls' => $ids]))
            ->assertOk()->assertSee('TYB-HTM-002')->assertSee('Thermal 33 × 15 mm');

        $pdf = $this->actingAs($this->admin)->post(route('admin.fabric-labels.pdf'), [
            'items' => collect($ids)->map(fn ($id) => ['id' => $id, 'copies' => 2])->all(),
            'layout' => 'thermal-50x30',
            'skip' => 0,
        ])->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // SKU kain tidak bisa diganti setelah roll berlabel.
        $this->actingAs($this->admin)->put(route('admin.fabrics.update', $fabric), ['sku' => 'BARU', 'name' => 'Toyobo', 'unit' => 'meter'])
            ->assertSessionHasErrors('sku');

        $this->actingAs($this->admin)->get(route('admin.fabric-receipts.export', ['range' => 'semua']))->assertOk();
    }

    public function test_scan_shows_fabric_and_length_then_issues_partial_and_all(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 50, 1);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.lookup'), ['code' => 'tyb-htm-001'])
            ->assertOk()
            ->assertJsonPath('roll.code', 'TYB-HTM-001')
            ->assertJsonPath('roll.sku', 'TYB-HTM')
            ->assertJsonPath('roll.quantity', 50)
            ->assertJsonPath('roll.quantity_label', '50 m');

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.lookup'), ['code' => 'NGAWUR'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Kode "NGAWUR" bukan roll kain yang terdaftar.');

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), [
            'code' => 'TYB-HTM-001', 'quantity' => 12.25, 'destination' => 'Penjahit Sri',
        ])->assertOk()->assertJsonPath('roll.quantity', 37.75)->assertJsonCount(1, 'recent');

        // Lebih dari sisa ditolak.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'quantity' => 40])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'all' => true])
            ->assertOk()->assertJsonPath('roll.empty', true);

        $roll = FabricRoll::first();
        $this->assertSame(0.0, $roll->quantity);
        $this->assertNotNull($roll->emptied_at);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'all' => true])
            ->assertStatus(422);

        $this->actingAs($this->admin)->get(route('admin.fabric-issues.index'))
            ->assertOk()->assertSee('Penjahit Sri')->assertSee('TYB-HTM-001');
        $this->actingAs($this->admin)->get(route('admin.fabric-issues.export'))->assertOk();

        // Batal keluar mengembalikan kain ke roll.
        $first = FabricMovement::issues()->orderBy('id')->first();
        $this->actingAs($this->admin)->post(route('admin.fabric-issues.cancel', $first))->assertSessionHas('success');
        $this->assertSame(12.25, $roll->fresh()->quantity);
        $this->assertNull($roll->fresh()->emptied_at);
        $this->actingAs($this->admin)->post(route('admin.fabric-issues.cancel', $first))->assertSessionHasErrors();

        $this->actingAs($this->admin)->get(route('admin.fabrics.show', [$fabric, 'habis' => 1]))
            ->assertOk()->assertSee('TYB-HTM-001')->assertSee('Batal keluar');
        $this->actingAs($this->admin)->get(route('admin.fabrics.index'))->assertOk()->assertSee('12,25 m');
        $this->actingAs($this->admin)->get(route('admin.fabric-scan'))->assertOk()->assertSee('Penjahit Sri');
    }

    public function test_check_mode_records_adjustment(): void
    {
        $fabric = $this->fabric(['unit' => 'kg', 'sku' => 'KTN']);
        $this->receive($fabric, 20, 1);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.adjust'), ['code' => 'KTN-001', 'actual' => 19.4])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('roll.quantity', 19.4);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.adjust'), ['code' => 'KTN-001', 'actual' => 19.4])
            ->assertOk()->assertJsonPath('changed', false);

        $this->assertSame(-0.6, FabricMovement::where('type', 'adjust')->value('quantity'));

        // Dokumen yang rollnya sudah dikoreksi tidak bisa dihapus.
        $this->actingAs($this->admin)->delete(route('admin.fabric-receipts.destroy', FabricReceipt::first()))->assertSessionHas('error');
    }

    public function test_receipt_can_be_deleted_while_rolls_are_untouched(): void
    {
        $receipt = $this->receive($this->fabric(), 10, 3);

        $this->actingAs($this->admin)->delete(route('admin.fabric-receipts.destroy', $receipt))->assertSessionHas('success');
        $this->assertSame(0, FabricRoll::count());
        $this->assertSame(0, FabricMovement::count());
    }

    public function test_staff_can_receive_and_issue_but_not_adjust_or_cancel(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id'), 'is_active' => true]);
        $fabric = $this->fabric();
        $this->receive($fabric, 10, 1);

        $this->actingAs($staff)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'quantity' => 1])->assertOk();
        $this->actingAs($staff)->postJson(route('admin.fabric-scan.adjust'), ['code' => 'TYB-HTM-001', 'actual' => 1])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.fabric-issues.cancel', FabricMovement::issues()->first()))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.fabrics.create'))->assertForbidden();
    }

    public function test_mobile_app_uses_same_scan_endpoints(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 10, 1);
        $token = $this->postJson(route('mobile.login'), ['email' => 'admin@cheery.test', 'password' => 'password', 'device_name' => 'HP'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson(route('mobile.me'))->assertJsonPath('user.can.kain_keluar', true);

        $this->withToken($token)->postJson(route('mobile.kain.lookup'), ['code' => 'TYB-HTM-001'])
            ->assertOk()->assertJsonPath('roll.quantity_label', '10 m');
        $this->withToken($token)->postJson(route('mobile.kain.issue'), ['code' => 'TYB-HTM-001', 'quantity' => '2.5', 'destination' => 'Konveksi A'])
            ->assertOk()->assertJsonPath('roll.quantity', 7.5);
        $this->withToken($token)->getJson(route('mobile.kain.recent'))->assertOk()->assertJsonCount(1, 'recent');
    }

    public function test_fabric_needs_follow_production_plan_and_usage(): void
    {
        $gamis = Product::create(['sku' => 'Gamis 1', 'name' => 'Gamis - Hitam', 'unit' => 'pcs', 'min_stock' => 0]);
        $gamis->forceFill(['stock' => 1])->save();
        $tunik = Product::create(['sku' => 'Tunik 1', 'name' => 'Tunik', 'unit' => 'pcs', 'min_stock' => 0]);
        ProductPoPeriod::create(['product_id' => $gamis->id, 'starts_at' => '2026-01-01 00:00', 'days' => 7]);
        ProductPoPeriod::create(['product_id' => $tunik->id, 'starts_at' => '2026-01-01 00:00', 'days' => 7]);

        $header = ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity', 'Normal or Pre-order', 'Created Time', 'Paid Time', 'Delivery Option'];
        $rows = [
            ['1', 'JX01', 'Gamis 1', '3', 'Pre-order', '10/06/2026 10:00:00', '10/06/2026 10:00:00', 'Standar'],
            ['2', 'JX02', 'Tunik 1', '2', 'Pre-order', '10/06/2026 10:00:00', '10/06/2026 10:00:00', 'Standar'],
        ];
        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.csv';
        file_put_contents($path, collect([$header, ...$rows])->map(fn ($row) => implode(',', $row))->implode("\n"));
        $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'Untuk Dikirim.csv', 'text/csv', null, true),
        ])->assertSessionHas('success');
        app(OrderPlanner::class)->refresh(force: true);

        $fabric = $this->fabric();
        $this->receive($fabric, 3, 1);

        $this->actingAs($this->admin)->put(route('admin.fabrics.usages', $fabric), ['usages' => [$gamis->id => '2.5']])
            ->assertSessionHas('success');
        $this->assertSame(2.5, $gamis->fabricUsages()->value('quantity'));

        $response = $this->actingAs($this->admin)->get(route('admin.fabric-needs.index'))->assertOk()->assertSee('TYB-HTM');
        $needs = $response->viewData('needs');
        $row = $needs->rows->first();
        // Gamis: pesan 3, stok 1 → harus diproduksi 2 × 2,5 m = 5 m; stok kain 3 m → kurang 2 m.
        $this->assertSame(2, $row['units']);
        $this->assertSame(5.0, $row['need']);
        $this->assertSame(3.0, $row['stock']);
        $this->assertSame(2.0, $row['short']);
        // Tunik belum diatur pemakaian kainnya.
        $this->assertSame(['Tunik 1'], $needs->missing->pluck('sku')->all());

        // Kain yang sudah keluar sejak tanggal tertentu mengurangi kebutuhan.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'quantity' => 1])->assertOk();
        $row = $this->actingAs($this->admin)->get(route('admin.fabric-needs.index', ['keluar_sejak' => '2026-06-12']))
            ->assertOk()->viewData('needs')->rows->first();
        $this->assertSame(1.0, $row['issued']);
        $this->assertSame(4.0, $row['remaining']);
        $this->assertSame(2.0, $row['stock']);
        $this->assertSame(2.0, $row['short']);

        $this->actingAs($this->admin)->get(route('admin.fabric-needs.export'))->assertOk();

        // Menghapus barang dari daftar pemakaian.
        $this->actingAs($this->admin)->put(route('admin.fabrics.usages', $fabric), ['usages' => []])->assertSessionHas('success');
        $this->assertSame(0, $fabric->usages()->count());
    }

    public function test_fabric_photo_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), [
            'sku' => 'FT-01', 'name' => 'Katun Foto', 'unit' => 'meter', 'is_active' => 1,
            'image' => UploadedFile::fake()->image('kain.jpg', 400, 400),
        ])->assertSessionHasNoErrors();

        $fabric = Fabric::where('sku', 'FT-01')->firstOrFail();
        $first = $fabric->image_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        // Foto tampil di daftar, detail, dan hasil scan roll.
        $this->actingAs($this->admin)->get(route('admin.fabrics.index'))->assertOk()->assertSee($fabric->image_url);
        $this->actingAs($this->admin)->get(route('admin.fabrics.show', $fabric))->assertOk()->assertSee($fabric->image_url);
        $this->receive($fabric, 10, 1);
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.lookup'), ['code' => 'FT-01-001'])
            ->assertOk()->assertJsonPath('roll.image_url', $fabric->image_url);

        $this->actingAs($this->admin)->put(route('admin.fabrics.update', $fabric), [
            'sku' => 'FT-01', 'name' => 'Katun Foto', 'unit' => 'meter',
            'image' => UploadedFile::fake()->image('baru.png'),
        ])->assertSessionHasNoErrors();
        $fabric->refresh();
        $this->assertNotSame($first, $fabric->image_path);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($this->admin)->put(route('admin.fabrics.update', $fabric), [
            'sku' => 'FT-01', 'name' => 'Katun Foto', 'unit' => 'meter', 'remove_image' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNull($fabric->refresh()->image_path);

        $this->actingAs($this->admin)->post(route('admin.fabrics.store'), [
            'sku' => 'FT-02', 'name' => 'Salah', 'unit' => 'meter',
            'image' => UploadedFile::fake()->create('dok.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('image');
    }
}
