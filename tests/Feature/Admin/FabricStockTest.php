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

    protected ?Product $gamis = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-12 09:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    /** Barang tujuan kain keluar. */
    protected function product(): Product
    {
        return $this->gamis ??= Product::create(['sku' => 'GMS-01', 'name' => 'Gamis Hitam', 'unit' => 'pcs', 'min_stock' => 0]);
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

        // Barang wajib dipilih.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'quantity' => 1])
            ->assertStatus(422)->assertJsonPath('errors.product_id.0', 'Pilih barang yang dibuat dari kain ini.');

        // Barang pemakai kain ini muncul di hasil scan dan paling atas saat dicari.
        $other = Product::create(['sku' => 'AAA-01', 'name' => 'Aksesoris', 'unit' => 'pcs', 'min_stock' => 0]);
        $fabric->usages()->create(['product_id' => $this->product()->id, 'quantity' => 2.5]);
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.lookup'), ['code' => 'TYB-HTM-001'])
            ->assertOk()->assertJsonCount(1, 'roll.products')->assertJsonPath('roll.products.0.sku', 'GMS-01');
        $this->actingAs($this->admin)->getJson(route('admin.fabric-scan.products', ['code' => 'TYB-HTM-001']))
            ->assertOk()->assertJsonPath('products.0.sku', 'GMS-01')->assertJsonPath('products.1.sku', $other->sku);
        $this->actingAs($this->admin)->getJson(route('admin.fabric-scan.products', ['q' => 'aksesoris']))
            ->assertOk()->assertJsonCount(1, 'products');

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), [
            'code' => 'TYB-HTM-001', 'quantity' => 12.25, 'product_id' => $this->product()->id,
        ])->assertOk()->assertJsonPath('roll.quantity', 37.75)->assertJsonCount(1, 'recent')
            ->assertJsonPath('movement.product_sku', 'GMS-01');
        $this->assertSame($this->product()->id, FabricMovement::issues()->first()->product_id);

        // Lebih dari sisa ditolak.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $this->product()->id, 'quantity' => 40])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $this->product()->id, 'all' => true])
            ->assertOk()->assertJsonPath('roll.empty', true);

        $roll = FabricRoll::first();
        $this->assertSame(0.0, $roll->quantity);
        $this->assertNotNull($roll->emptied_at);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $this->product()->id, 'all' => true])
            ->assertStatus(422);

        $this->actingAs($this->admin)->get(route('admin.fabric-issues.index'))
            ->assertOk()->assertSee('GMS-01')->assertSee('TYB-HTM-001');
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
        $this->actingAs($this->admin)->get(route('admin.fabric-scan'))->assertOk()->assertSee('GMS-01');
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

        $this->actingAs($staff)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $this->product()->id, 'quantity' => 1])->assertOk();
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
        $this->withToken($token)->postJson(route('mobile.kain.issue'), ['code' => 'TYB-HTM-001', 'quantity' => '2.5', 'product_id' => $this->product()->id])
            ->assertOk()->assertJsonPath('roll.quantity', 7.5);
        $this->withToken($token)->getJson(route('mobile.kain.recent'))->assertOk()->assertJsonCount(1, 'recent');
        $this->withToken($token)->getJson(route('mobile.kain.products', ['q' => 'gamis']))->assertOk()->assertJsonPath('products.0.sku', 'GMS-01');
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

        // Kain keluar untuk gamis masuk Cutting dan mengurangi kebutuhan.
        $cutter = \App\Models\Employee::create(['code' => 'K-001', 'name' => 'Pak Cutter', 'attendance_method' => 'machine', 'is_active' => true]);
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), [
            'code' => 'TYB-HTM-001', 'product_id' => $gamis->id, 'employee_id' => $cutter->id, 'quantity' => 2.5,
        ])->assertOk();
        $row = $this->actingAs($this->admin)->get(route('admin.fabric-needs.index'))->assertOk()->viewData('needs')->rows->first();
        $this->assertSame(2.5, $row['in_cutting']);
        $this->assertSame(2.5, $row['remaining']);
        $this->assertSame(0.5, $row['stock']);
        $this->assertSame(2.0, $row['short']);

        // Hasil potong 1 pcs diserahkan ke penjahit: pindah dari Cutting ke WIP Jahit.
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);
        $cutting = \App\Models\FabricCutting::firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), [
            'supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$gamis->id => 1],
        ])->assertSessionHasNoErrors();
        $row = $this->actingAs($this->admin)->get(route('admin.fabric-needs.index'))->assertOk()->viewData('needs')->rows->first();
        $this->assertSame(1, $row['wip']);
        $this->assertSame(1, $row['units']);
        $this->assertSame(0.0, $row['in_cutting']);
        $this->assertSame(2.5, $row['remaining']);
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

    public function test_product_page_sets_optional_fabric_bom_for_one_or_all_variants(): void
    {
        $fabric = $this->fabric();
        $lining = $this->fabric(['sku' => 'FUR-PTH', 'name' => 'Furing', 'color' => 'Putih']);
        $group = \App\Models\ProductGroup::create(['name' => 'Gamis', 'option1_name' => 'Ukuran']);
        $s = Product::create(['sku' => 'GMS-S', 'name' => 'Gamis - S', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $m = Product::create(['sku' => 'GMS-M', 'name' => 'Gamis - M', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);

        // Tanpa BOM halaman barang tetap normal.
        $this->actingAs($this->admin)->get(route('admin.products.show', $s))->assertOk()->assertSee('BOM Kain')->assertSee('Simpan BOM');

        $this->actingAs($this->admin)->put(route('admin.products.fabrics.update', $s), [
            'fabrics' => [['fabric_id' => $fabric->id, 'quantity' => '2.5'], ['fabric_id' => $lining->id, 'quantity' => '1.25']],
            'variants' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.products.show', $s));

        $this->assertSame(2.5, (float) $m->fabricUsages()->where('fabric_id', $fabric->id)->value('quantity'));
        $this->assertSame(2, $s->fabricUsages()->count());

        // Ubah satu varian saja; varian lain tetap.
        $this->actingAs($this->admin)->put(route('admin.products.fabrics.update', $s), [
            'fabrics' => [['fabric_id' => $fabric->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $s->fabricUsages()->count());
        $this->assertSame(2, $m->fabricUsages()->count());

        // Data yang sama terlihat dari detail kain.
        $this->actingAs($this->admin)->get(route('admin.fabrics.show', $fabric))->assertOk()->assertSee('GMS-S')->assertSee('GMS-M');

        // Kain dobel ditolak; mengosongkan BOM boleh.
        $this->actingAs($this->admin)->put(route('admin.products.fabrics.update', $s), [
            'fabrics' => [['fabric_id' => $fabric->id, 'quantity' => 1], ['fabric_id' => $fabric->id, 'quantity' => 2]],
        ])->assertSessionHasErrors('fabrics.1.fabric_id');
        $this->actingAs($this->admin)->put(route('admin.products.fabrics.update', $s), [])->assertSessionHasNoErrors();
        $this->assertSame(0, $s->fabricUsages()->count());

        // Staf tanpa akses kelola kain tidak bisa mengubah.
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id'), 'is_active' => true]);
        $this->actingAs($staff)->put(route('admin.products.fabrics.update', $s), [])->assertForbidden();
    }

    public function test_cut_pieces_can_wait_in_warehouse_then_be_sent_partially(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 50, 1);
        $group = \App\Models\ProductGroup::create(['name' => 'Gamis', 'option1_name' => 'Ukuran']);
        $s = Product::create(['sku' => 'GMS-S', 'name' => 'Gamis - S', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $m = Product::create(['sku' => 'GMS-M', 'name' => 'Gamis - M', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);
        $other = Supplier::create(['code' => 'SUP-8', 'name' => 'Toko Biasa', 'is_active' => true]);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $s->id, 'quantity' => 30])->assertOk();
        $cutting = \App\Models\FabricCutting::firstOrFail();

        // Simpan di gudang: tanpa penjahit.
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['tujuan' => 'gudang', 'date' => '2026-06-12', 'items' => [$s->id => 10, $m->id => 5]])
            ->assertSessionHasNoErrors();
        $stored = \App\Models\SewingBatch::firstOrFail();
        $this->assertTrue($stored->inWarehouse());
        $this->assertNull($stored->sent_at);
        $this->assertSame(10, \App\Support\FabricNeeds::wipByProduct()[$s->id]);
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.index', ['lihat' => 'jahit', 'status' => 'gudang']))->assertOk()->assertSee($stored->code)->assertSee('Di Gudang');
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.show', $cutting))->assertOk()->assertSee('Kirim ke Penjahit');
        $this->actingAs($this->admin)->get(route('admin.inbounds.create'))->assertOk()->assertDontSee($stored->code);

        // Kirim sebagian: jadi batch baru di penjahit, sisanya tetap di gudang.
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.send', $stored), ['supplier_id' => $other->id, 'date' => '2026-06-12', 'items' => [$s->id => 4]])
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.send', $stored), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$s->id => 11]])
            ->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.send', $stored), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$s->id => 4]])
            ->assertSessionHas('success');
        $sent = \App\Models\SewingBatch::whereKeyNot($stored->id)->firstOrFail();
        $this->assertSame($tailor->id, $sent->supplier_id);
        $this->assertSame([$s->id => 4], $sent->items()->pluck('quantity', 'product_id')->all());
        $this->assertSame([$s->id => 6, $m->id => 5], $stored->items()->orderBy('product_id')->pluck('quantity', 'product_id')->all());
        $this->assertTrue($stored->refresh()->inWarehouse());
        $this->actingAs($this->admin)->get(route('admin.inbounds.create'))->assertOk()->assertSee($sent->code);

        // Kirim semua sisanya: batch gudang itu sendiri pindah ke penjahit.
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.send', $stored), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$s->id => 6, $m->id => 5]])
            ->assertSessionHas('success');
        $this->assertFalse($stored->refresh()->inWarehouse());
        $this->assertSame('2026-06-12', $stored->sent_at->toDateString());
        $this->assertSame(2, \App\Models\SewingBatch::count());

        // Rekap: pcs hasil potong tidak dobel; penjahit menerima semua 15.
        $report = \App\Support\CuttingReport::for(\Illuminate\Support\Carbon::parse('2026-06-01'), now());
        $this->assertSame(15, $report->cutters->first()['pieces']);
        $this->assertSame(15, $report->tailors->first()['handed']);
    }

    public function test_fabric_opname_counts_rolls_and_applies_differences(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 50, 3);
        [$r1, $r2, $r3] = FabricRoll::orderBy('id')->get()->all();

        $this->actingAs($this->admin)->get(route('admin.fabric-opnames.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.fabric-opnames.create'))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.fabric-opnames.store'), ['date' => '2026-06-12', 'fabric_id' => $fabric->id])->assertRedirect();
        $opname = \App\Models\FabricOpname::firstOrFail();
        $this->assertSame(3, $opname->lines()->count());

        $this->actingAs($this->admin)->postJson(route('admin.fabric-opnames.count', $opname), ['code' => 'tyb-htm-001', 'counted' => 48.5])->assertOk();
        $this->actingAs($this->admin)->postJson(route('admin.fabric-opnames.count', $opname), ['code' => 'XXX-001', 'counted' => 1])->assertStatus(422);
        $line2 = $opname->lines()->where('fabric_roll_id', $r2->id)->firstOrFail();
        $this->actingAs($this->admin)->postJson(route('admin.fabric-opnames.count', $opname), ['line_id' => $line2->id, 'counted' => 50])->assertOk();
        $this->actingAs($this->admin)->get(route('admin.fabric-opnames.show', $opname))->assertOk()->assertSee('TYB-HTM-003');

        // Staf boleh menghitung tapi tidak menerapkan.
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id'), 'is_active' => true]);
        $this->actingAs($staff)->postJson(route('admin.fabric-opnames.count', $opname), ['line_id' => $line2->id, 'counted' => 50])->assertOk();
        $this->actingAs($staff)->post(route('admin.fabric-opnames.apply', $opname), ['missing_rule' => 'empty'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('admin.fabric-opnames.apply', $opname), ['missing_rule' => 'empty'])->assertSessionHas('success');
        $this->assertSame(48.5, $r1->refresh()->quantity);
        $this->assertSame(50.0, $r2->refresh()->quantity);
        $this->assertSame(0.0, $r3->refresh()->quantity);
        $this->assertSame(2, FabricMovement::where('type', 'adjust')->where('note', 'like', 'Opname%')->count());
        $this->assertSame('applied', $opname->refresh()->status);
        $this->actingAs($this->admin)->postJson(route('admin.fabric-opnames.count', $opname), ['line_id' => $line2->id, 'counted' => 1])->assertStatus(422);
        $this->actingAs($this->admin)->delete(route('admin.fabric-opnames.destroy', $opname))->assertSessionHas('error');
    }

    public function test_manual_cut_pieces_in_warehouse_and_at_tailor_can_be_managed(): void
    {
        $s = Product::create(['sku' => 'GMS-S', 'name' => 'Gamis - S', 'unit' => 'pcs', 'min_stock' => 0]);
        $m = Product::create(['sku' => 'GMS-M', 'name' => 'Gamis - M', 'unit' => 'pcs', 'min_stock' => 0]);
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);

        $this->actingAs($this->admin)->get(route('admin.sewing-batches.create'))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.store'), [
            'posisi' => 'gudang', 'date' => '2026-06-10', 'items' => [['product_id' => $s->id, 'quantity' => 20], ['product_id' => $m->id, 'quantity' => 0]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.store'), [
            'posisi' => 'penjahit', 'date' => '2026-06-10', 'items' => [['product_id' => $m->id, 'quantity' => 8]],
        ])->assertSessionHasErrors('supplier_id');
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.store'), [
            'posisi' => 'penjahit', 'supplier_id' => $tailor->id, 'date' => '2026-06-10', 'items' => [['product_id' => $m->id, 'quantity' => 8]],
        ])->assertSessionHasNoErrors();

        [$warehouse, $atTailor] = \App\Models\SewingBatch::orderBy('id')->get()->all();
        $this->assertNull($warehouse->fabric_cutting_id);
        $this->assertTrue($warehouse->inWarehouse());
        $this->assertSame('2026-06-10', $atTailor->sent_at->toDateString());
        $this->assertSame([$s->id => 20, $m->id => 8], \App\Support\FabricNeeds::wipByProduct()->sortKeys()->all());
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.index', ['lihat' => 'jahit']))->assertOk()->assertSee($warehouse->code)->assertSee('input manual');
        $this->actingAs($this->admin)->get(route('admin.sewing-batches.edit', $warehouse))->assertOk();

        // Batch manual di penjahit diterima lewat penerimaan.
        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(), 'supplier_id' => $tailor->id, 'sewing_batch_id' => $atTailor->id,
            'items' => [['product_id' => $m->id, 'quantity' => 5]], 'submit' => 1,
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->get(route('admin.inbounds.show', \App\Models\Inbound::firstOrFail()))->assertOk()->assertSee($atTailor->code);

        // Ubah: tidak boleh kurang dari yang sudah diterima, penjahit tidak bisa diganti.
        $this->actingAs($this->admin)->put(route('admin.sewing-batches.update', $atTailor), [
            'posisi' => 'penjahit', 'supplier_id' => $tailor->id, 'date' => '2026-06-10', 'items' => [['product_id' => $m->id, 'quantity' => 4]],
        ])->assertSessionHasErrors('items');
        $this->actingAs($this->admin)->put(route('admin.sewing-batches.update', $atTailor), [
            'posisi' => 'penjahit', 'supplier_id' => $tailor->id, 'date' => '2026-06-10', 'items' => [['product_id' => $m->id, 'quantity' => 6]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $atTailor->load('items')->outstanding()->sum());

        $this->actingAs($this->admin)->put(route('admin.sewing-batches.update', $warehouse), [
            'posisi' => 'gudang', 'date' => '2026-06-10', 'items' => [['product_id' => $s->id, 'quantity' => 15], ['product_id' => $m->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$s->id => 15, $m->id => 3], $warehouse->items()->orderBy('product_id')->pluck('quantity', 'product_id')->all());

        // Rekap: input manual tidak dihitung sebagai hasil pemotong.
        $report = \App\Support\CuttingReport::for(\Illuminate\Support\Carbon::parse('2026-06-01'), now());
        $this->assertSame(6, $report->tailors->first()['handed']);

        $this->actingAs($this->admin)->delete(route('admin.sewing-batches.destroy', $warehouse))->assertSessionHas('success');
        $this->assertSame(1, \App\Models\SewingBatch::count());
    }

    public function test_unprocessed_fabric_issue_can_be_deleted(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 50, 2);
        $product = Product::create(['sku' => 'GMS-01', 'name' => 'Gamis', 'unit' => 'pcs', 'min_stock' => 0]);
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);
        $roll1 = FabricRoll::where('code', 'TYB-HTM-001')->firstOrFail();
        $roll2 = FabricRoll::where('code', 'TYB-HTM-002')->firstOrFail();

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => $roll1->code, 'product_id' => $product->id, 'all' => true])->assertOk();
        $first = FabricMovement::where('type', 'out')->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.fabric-issues.index'))->assertOk()->assertSee(route('admin.fabric-issues.destroy', $first));

        $this->actingAs($this->admin)->delete(route('admin.fabric-issues.destroy', $first))->assertSessionHas('success');
        $this->assertSame(50.0, $roll1->refresh()->quantity);
        $this->assertNull($roll1->emptied_at);
        $this->assertSame(0, FabricMovement::where('type', 'out')->count());
        $this->assertSame(0, \App\Models\FabricCutting::count());

        // Sudah diserahkan ke penjahit: tidak bisa dihapus, hanya dibatalkan.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => $roll2->code, 'product_id' => $product->id, 'quantity' => 10])->assertOk();
        $second = FabricMovement::where('type', 'out')->latest('id')->firstOrFail();
        $cutting = \App\Models\FabricCutting::firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$product->id => 4]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete(route('admin.fabric-issues.destroy', $second))->assertSessionHasErrors('movement');
        $this->assertSame(40.0, $roll2->refresh()->quantity);

        // Staf tidak boleh menghapus.
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id'), 'is_active' => true]);
        $this->actingAs($staff)->delete(route('admin.fabric-issues.destroy', $second))->assertForbidden();
    }

    public function test_handover_only_lists_variants_using_the_fabric_when_bom_exists(): void
    {
        $fabric = $this->fabric();
        $other = $this->fabric(['sku' => 'KTN-PTH', 'name' => 'Katun', 'color' => 'Putih']);
        $this->receive($fabric, 50, 1);
        $group = \App\Models\ProductGroup::create(['name' => 'Gamis', 'option1_name' => 'Motif']);
        $make = fn ($sku) => Product::create(['sku' => $sku, 'name' => 'Gamis '.$sku, 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        [$a, $b, $c, $d] = [$make('GMS-A'), $make('GMS-B'), $make('GMS-C'), $make('GMS-D')];
        foreach ([$a, $b, $c] as $product) {
            \App\Models\FabricUsage::create(['fabric_id' => $fabric->id, 'product_id' => $product->id, 'quantity' => 2]);
        }
        \App\Models\FabricUsage::create(['fabric_id' => $other->id, 'product_id' => $d->id, 'quantity' => 2]);
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);

        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $a->id, 'quantity' => 10])->assertOk();
        $cutting = \App\Models\FabricCutting::firstOrFail();

        $this->assertSame(['GMS-A', 'GMS-B', 'GMS-C'], $cutting->variants()->pluck('sku')->all());
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.show', $cutting))->assertOk()->assertSee('GMS-C')->assertDontSee('GMS-D');
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$d->id => 3]])
            ->assertSessionHasErrors('items');
    }

    public function test_cutting_to_sewing_batches_closed_by_inbound_from_tailor(): void
    {
        $fabric = $this->fabric();
        $this->receive($fabric, 50, 2);
        $group = \App\Models\ProductGroup::create(['name' => 'Gamis', 'option1_name' => 'Ukuran']);
        $s = Product::create(['sku' => 'GMS-S', 'name' => 'Gamis - S', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $m = Product::create(['sku' => 'GMS-M', 'name' => 'Gamis - M', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $cutter = \App\Models\Employee::create(['code' => 'K-001', 'name' => 'Pak Cutter', 'attendance_method' => 'machine', 'is_active' => true]);
        $tailor = Supplier::create(['code' => 'SUP-9', 'name' => 'Penjahit Sri', 'is_active' => true, 'is_tailor' => true]);
        $other = Supplier::create(['code' => 'SUP-8', 'name' => 'Toko Biasa', 'is_active' => true]);

        // Dua roll untuk varian berbeda dari induk yang sama → satu Cutting.
        $this->actingAs($this->admin)->get(route('admin.fabric-scan'))->assertOk()->assertSee('Pak Cutter');
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-001', 'product_id' => $s->id, 'employee_id' => $cutter->id, 'all' => true])
            ->assertOk()->assertJsonPath('movement.cutting_code', fn ($code) => str_starts_with($code, 'CT-'));
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-002', 'product_id' => $m->id, 'employee_id' => $cutter->id, 'quantity' => 20])
            ->assertOk();
        $this->assertSame(1, \App\Models\FabricCutting::count());
        $cutting = \App\Models\FabricCutting::firstOrFail();
        $this->assertSame($group->id, $cutting->product_group_id);
        $this->assertSame(70.0, $cutting->load('movements')->fabricUsed()[$fabric->id]);

        // Kain keluar tanpa pemotong (aplikasi lama) tetap jalan, jadi Cutting sendiri.
        $this->actingAs($this->admin)->postJson(route('admin.fabric-scan.issue'), ['code' => 'TYB-HTM-002', 'product_id' => $m->id, 'quantity' => 1])->assertOk();
        $this->assertSame(2, \App\Models\FabricCutting::count());

        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.index'))->assertOk()->assertSee($cutting->code)->assertSee('Pak Cutter');
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.show', $cutting))->assertOk()->assertSee('GMS-S')->assertSee('GMS-M');

        // Penjahit harus bertanda penjahit; minimal satu varian.
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $other->id, 'date' => '2026-06-12', 'items' => [$s->id => 5]])
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$s->id => 0]])
            ->assertSessionHasErrors('items');

        // Diserahkan bertahap: dua batch.
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$s->id => 10, $m->id => 6]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.handover', $cutting), ['supplier_id' => $tailor->id, 'date' => '2026-06-12', 'items' => [$m->id => 4]])
            ->assertSessionHasNoErrors();
        [$first, $second] = \App\Models\SewingBatch::orderBy('id')->get()->all();
        $this->assertSame(16, (int) $first->items()->sum('quantity'));
        $this->assertSame(10, \App\Support\FabricNeeds::wipByProduct()[$m->id]);

        // Selesai cutting: sisa 3 m roll 002 kembali ke roll.
        $roll2 = FabricRoll::where('code', 'TYB-HTM-002')->firstOrFail();
        $before = $roll2->quantity;
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.finish', $cutting), ['returns' => [$roll2->id => 30]])
            ->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('admin.fabric-cuttings.finish', $cutting), ['returns' => [$roll2->id => 3]])
            ->assertSessionHasNoErrors();
        $this->assertSame($before + 3, $roll2->refresh()->quantity);
        $this->assertSame('done', $cutting->refresh()->status);
        $this->assertSame(1, FabricMovement::where('type', 'return')->count());

        // Penerimaan dari penjahit memilih batch; batch dari pemasok lain ditolak.
        $this->actingAs($this->admin)->get(route('admin.inbounds.create'))->assertOk()->assertSee($first->code);
        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(), 'supplier_id' => $other->id, 'sewing_batch_id' => $first->id,
            'items' => [['product_id' => $s->id, 'quantity' => 10]],
        ])->assertSessionHasErrors('sewing_batch_id');

        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(), 'supplier_id' => $tailor->id, 'sewing_batch_id' => $first->id,
            'items' => [['product_id' => $s->id, 'quantity' => 10], ['product_id' => $m->id, 'quantity' => 5]],
            'submit' => 1,
        ])->assertSessionHas('success');
        $this->assertSame([$m->id => 1], $first->refresh()->load('items')->outstanding()->all());
        $this->assertSame(10, $s->refresh()->stock);

        // Batch kedua salah input bisa dihapus; batch pertama ditutup dengan alasan.
        $this->actingAs($this->admin)->delete(route('admin.sewing-batches.destroy', $second))->assertSessionHas('success');
        $this->actingAs($this->admin)->delete(route('admin.sewing-batches.destroy', $first))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.close', $first), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('admin.sewing-batches.close', $first), ['reason' => '1 pcs rusak'])->assertSessionHas('success');
        $this->assertSame([], \App\Support\FabricNeeds::wipByProduct()->all());
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.index', ['lihat' => 'jahit', 'status' => 'semua']))->assertOk()->assertSee($first->code);
        $this->actingAs($this->admin)->get(route('admin.inbounds.show', \App\Models\Inbound::firstOrFail()))->assertOk()->assertSee($first->code);

        // Rekap pemotong & penjahit.
        $report = \App\Support\CuttingReport::for(\Illuminate\Support\Carbon::parse('2026-01-01'), now());
        $row = $report->cutters->firstWhere('name', 'Pak Cutter');
        $this->assertSame(16, $row['pieces']);
        $this->assertSame(2, $row['rolls']);
        $this->assertSame(67.0, $report->fabrics->firstWhere('cutter', 'Pak Cutter')['used']);
        $this->assertNotNull($report->cutters->firstWhere('name', 'Tanpa pemotong'));
        $tailorRow = $report->tailors->firstWhere('name', 'Penjahit Sri');
        $this->assertSame([16, 15, 0, 1], [$tailorRow['handed'], $tailorRow['received'], $tailorRow['open'], $tailorRow['closed_short']]);
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.index', ['lihat' => 'rekap', 'from' => '2026-01-01', 'to' => now()->toDateString()]))
            ->assertOk()->assertSee('Pak Cutter')->assertSee('Penjahit Sri');
        $this->actingAs($this->admin)->get(route('admin.fabric-cuttings.export', ['from' => '2026-01-01', 'to' => now()->toDateString()]))->assertOk();

        // Pemasok bisa ditandai penjahit dari form pemasok.
        $this->actingAs($this->admin)->put(route('admin.suppliers.update', $other), ['name' => 'Toko Biasa', 'is_active' => 1, 'is_tailor' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($other->refresh()->is_tailor);
    }
}
