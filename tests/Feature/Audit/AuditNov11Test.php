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
}
