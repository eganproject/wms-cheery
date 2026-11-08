<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Product::create(['sku' => 'SKU-1', 'name' => 'Barang', 'unit' => 'pcs']);
        Supplier::create(['code' => 'SUP-1', 'name' => 'Pemasok']);
        DB::table('app_settings')->insert(['key' => 'x', 'value' => '1']);
    }

    public function test_empties_data_but_keeps_users_roles_and_settings(): void
    {
        $users = User::count();
        $roles = Role::count();

        $this->artisan('reset:data', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Supplier::count());
        $this->assertSame($users, User::count());
        $this->assertSame($roles, Role::count());
        $this->assertGreaterThan(0, DB::table('permission_role')->count());
        $this->assertSame(1, DB::table('app_settings')->count());
        $this->assertGreaterThan(0, DB::table('migrations')->count());
    }

    public function test_dry_run_and_declined_confirmation_delete_nothing(): void
    {
        $this->artisan('reset:data', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('reset:data')
            ->expectsConfirmation('Semua data di tabel di atas akan DIHAPUS PERMANEN. Lanjutkan?', 'no')
            ->assertFailed();

        $this->assertSame(1, Product::count());
    }

    public function test_keep_option_preserves_extra_table(): void
    {
        $this->artisan('reset:data', ['--force' => true, '--keep' => ['suppliers']])->assertSuccessful();

        $this->assertSame(1, Supplier::count());
        $this->assertSame(0, Product::count());
    }

    public function test_refuses_in_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('reset:data')->assertFailed();

        $this->assertSame(1, Product::count());
    }
}
