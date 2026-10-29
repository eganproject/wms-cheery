<?php

namespace Tests\Feature\Audit;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Audit berkelanjutan, pass 2: import barang dan hak akses.
 */
class ContinuousAuditPass2Test extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'is_active' => true]);
    }

    /* ------------------------------------------------- import barang ----- */

    public function test_a_stock_only_import_keeps_the_other_product_fields(): void
    {
        $product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035', 'name' => 'Filter Oli Standar',
            'unit' => 'box', 'category' => 'Filter', 'location' => 'A-01', 'min_stock' => 7,
        ]);
        $product->forceFill(['is_active' => false])->save();

        $path = tempnam(sys_get_temp_dir(), 'stok').'.csv';
        file_put_contents($path, "SKU,Nama Barang,Stok\nFLT-OLI-STD,Filter Oli Standar,12");

        $this->actingAs($this->superAdmin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'stok.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(12, $product->stock);
        $this->assertSame('8991234500035', $product->barcode);
        $this->assertSame('box', $product->unit);
        $this->assertSame('Filter', $product->category);
        $this->assertSame('A-01', $product->location);
        $this->assertSame(7, $product->min_stock);
        $this->assertFalse((bool) $product->is_active, 'Barang non-aktif tidak boleh aktif kembali diam-diam.');
    }

    public function test_a_new_product_from_a_minimal_file_gets_defaults(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baru').'.csv';
        file_put_contents($path, "SKU,Nama Barang\nBARU-01,Barang Baru");

        $this->actingAs($this->superAdmin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'baru.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();

        $product = Product::where('sku', 'BARU-01')->firstOrFail();
        $this->assertSame('pcs', $product->unit);
        $this->assertSame(0, $product->min_stock);
        $this->assertTrue((bool) $product->is_active);
    }

    /* ------------------------------------------------- hak akses --------- */

    public function test_an_admin_cannot_promote_anyone_to_super_admin(): void
    {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->admin), [
            'name' => $this->admin->name, 'email' => $this->admin->email,
            'role_id' => Role::where('slug', 'super-admin')->value('id'), 'is_active' => 1,
        ])->assertSessionHasErrors('role_id');

        $this->assertFalse($this->admin->refresh()->isSuperAdmin());

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Penyusup', 'email' => 'x@cheery.test',
            'role_id' => Role::where('slug', 'super-admin')->value('id'), 'is_active' => 1,
            'password' => 'Rahasia#2026', 'password_confirmation' => 'Rahasia#2026',
        ])->assertSessionHasErrors('role_id');
    }

    public function test_an_admin_cannot_touch_a_super_admin_account(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users.edit', $this->superAdmin))->assertForbidden();

        $this->actingAs($this->admin)->put(route('admin.users.update', $this->superAdmin), [
            'name' => 'Diambil alih', 'email' => $this->superAdmin->email,
            'role_id' => $this->superAdmin->role_id, 'is_active' => 1,
            'password' => 'Rahasia#2026', 'password_confirmation' => 'Rahasia#2026',
        ])->assertForbidden();

        $this->assertNotSame('Diambil alih', $this->superAdmin->refresh()->name);
    }

    public function test_an_admin_cannot_grant_a_role_permissions_they_do_not_hold(): void
    {
        $role = Role::where('slug', 'admin')->firstOrFail();
        $missing = Permission::where('slug', 'permissions.update')->value('id');

        $this->actingAs($this->admin)->put(route('admin.roles.update', $role), [
            'name' => $role->name, 'slug' => $role->slug,
            'permissions' => array_merge($role->permissions()->pluck('permissions.id')->all(), [$missing]),
        ])->assertSessionHasErrors('permissions');

        $this->assertFalse($role->refresh()->hasPermission('permissions.update'));
    }

    public function test_nobody_can_lock_themselves_out(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.users.update', $this->superAdmin), [
            'name' => $this->superAdmin->name, 'email' => $this->superAdmin->email,
            'role_id' => $this->superAdmin->role_id, 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $this->superAdmin->refresh()->is_active);
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated(): void
    {
        $second = User::factory()->create(['role_id' => $this->superAdmin->role_id, 'is_active' => true]);

        // Dua super admin: yang satu boleh dihapus.
        $this->actingAs($second)->delete(route('admin.users.destroy', $this->superAdmin))->assertSessionHasNoErrors();
        $this->assertModelMissing($this->superAdmin);

        $other = User::factory()->create(['role_id' => $this->superAdmin->role_id, 'is_active' => false]);

        // Tinggal satu yang aktif: tidak bisa dinonaktifkan orang lain.
        $this->actingAs($other)->put(route('admin.users.update', $second), [
            'name' => $second->name, 'email' => $second->email,
            'role_id' => $second->role_id, 'is_active' => 0,
        ])->assertSessionHasErrors('role_id');
    }

    /* ------------------------------------------------- dokumen ----------- */

    public function test_a_document_approved_meanwhile_is_no_longer_editable(): void
    {
        $inbound = \App\Models\Inbound::create([
            'code' => \App\Models\Inbound::nextCode(), 'date' => now(),
            'status' => \App\Models\Inbound::STATUS_DRAFT,
        ]);

        // Model ini dimuat saat formulir dibuka; sementara itu orang lain
        // menyetujuinya.
        \App\Models\Inbound::whereKey($inbound->id)->update(['status' => \App\Models\Inbound::STATUS_POSTED]);

        $this->assertTrue($inbound->isEditable());
        $this->assertFalse($inbound->lockForEditing());
        $this->assertTrue($inbound->isPosted());
    }
}
