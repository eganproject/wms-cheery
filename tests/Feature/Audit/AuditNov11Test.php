<?php

namespace Tests\Feature\Audit;

use App\Models\DamagedDisposal;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit ulang 11 November 2026.
 */
class AuditNov11Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    public function test_a_supplier_with_a_damaged_goods_return_can_not_be_deleted(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber']);

        $disposal = DamagedDisposal::create([
            'code' => DamagedDisposal::nextCode(),
            'date' => now()->toDateString(),
            'bucket' => 'damaged',
            'action' => DamagedDisposal::ACTION_RETURN,
            'supplier_id' => $supplier->id,
            'status' => DamagedDisposal::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)->delete(route('admin.suppliers.destroy', $supplier))
            ->assertSessionHas('error');

        $this->assertNotNull($supplier->fresh());
        $this->assertSame($supplier->id, $disposal->fresh()->supplier_id);
    }

    public function test_an_unused_supplier_can_still_be_deleted(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber']);

        $this->actingAs($this->admin)->delete(route('admin.suppliers.destroy', $supplier))
            ->assertSessionHas('success');

        $this->assertNull($supplier->fresh());
    }

    /**
     * form.action / form.method / form.submit tertimpa oleh input yang
     * namanya sama (mis. pilihan "action" di Barang Rusak, filter "method"
     * di Karyawan, tombol name="submit" di form dokumen). Skrip harus
     * membaca atributnya lewat getAttribute().
     */
    public function test_scripts_do_not_read_form_properties_that_inputs_can_shadow(): void
    {
        $offenders = [];

        foreach (glob(resource_path('js/*.js')) as $file) {
            foreach (file($file) as $number => $line) {
                if (preg_match('#^\s*(//|\*|/\*)#', $line)) {
                    continue;
                }

                if (preg_match('/\b(form|target|\$el)\.(action|method|submit\(\))/', $line)) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_damaged_units_from_an_inbound_show_in_the_incoming_tab(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber']);
        $product = \App\Models\Product::create(['sku' => 'KAOS-1', 'name' => 'Kaos Merah', 'unit' => 'pcs']);
        $this->actingAs($this->admin);

        $inbound = \App\Models\Inbound::create([
            'code' => \App\Models\Inbound::nextCode(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'status' => \App\Models\Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 10, 'damaged_quantity' => 4]);
        app(\App\Services\StockService::class)->postInbound($inbound->fresh());

        $this->get(route('admin.disposals.index', ['tab' => 'masuk']))
            ->assertOk()
            ->assertSee('Barang Rusak Masuk')
            ->assertSee('Kaos Merah')
            ->assertSee($inbound->code)
            ->assertSee('+4');

        $this->get(route('admin.disposals.index', ['tab' => 'masuk', 'source' => 'return']))
            ->assertOk()
            ->assertDontSee($inbound->code);

        $this->get(route('admin.disposals.export', ['tab' => 'masuk']))
            ->assertOk()
            ->assertDownload();
    }

    public function test_pages_carry_the_asset_version_so_stale_tabs_reload(): void
    {
        $this->actingAs($this->admin)->get(route('admin.disposals.index'))
            ->assertOk()
            ->assertSee('name="asset-version"', false);

        $this->actingAs($this->admin)->get(route('admin.disposals.index'), ['X-Page-Fragment' => '1'])
            ->assertOk()
            ->assertSee('data-assets="', false);
    }
}
