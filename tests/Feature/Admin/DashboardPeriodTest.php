<?php

namespace Tests\Feature\Admin;

use App\Models\ShipmentImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Beranda menampilkan aktivitas per periode (bawaannya hari ini), dan
 * mengingatkan resi lama yang belum dikerjakan.
 */
class DashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_waybill_stages_follow_the_period_and_old_backlog_is_flagged(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $import = ShipmentImport::create(['filename' => 'a.csv', 'source' => 'tiktok', 'row_count' => 2]);

        $this->travelTo(now()->subDays(3));
        $import->orders()->create(['tracking_number' => 'LAMA-1', 'courier' => 'J&T']);
        $this->travelBack();
        $import->orders()->create(['tracking_number' => 'BARU-1', 'courier' => 'SPX']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('waybills')['belum-qc']);
        $this->assertSame(1, $response->viewData('backlog'));
        $this->assertSame(['SPX'], $response->viewData('couriers')->pluck('courier')->all());
        $response->assertSee('resi dari sebelum periode ini masih belum QC');

        $all = $this->actingAs($admin)->get(route('admin.dashboard', ['range' => 'semua']))->assertOk();
        $this->assertSame(2, $all->viewData('waybills')['belum-qc']);
        $this->assertSame(0, $all->viewData('backlog'));
    }
}
