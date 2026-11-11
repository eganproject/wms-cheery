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
}
