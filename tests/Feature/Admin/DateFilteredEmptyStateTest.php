<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daftar bersaring tanggal (bawaannya hari ini) yang kosong harus bilang
 * begitu, bukan "belum ada dokumen" — orang mengira datanya hilang.
 */
class DateFilteredEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_day_points_to_all_dates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $old = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now()->subDays(10)->toDateString(),
            'status' => Inbound::STATUS_DRAFT,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('admin.inbounds.index'))
            ->assertOk()
            ->assertSee('Tidak ada data pada hari ini')
            ->assertSee('Lihat semua tanggal')
            ->assertDontSee('Belum ada dokumen')
            ->assertDontSee($old->code);

        $this->actingAs($admin)->get(route('admin.inbounds.index', ['range' => 'semua']))
            ->assertOk()
            ->assertSee($old->code);

        $old->delete();

        $this->actingAs($admin)->get(route('admin.inbounds.index', ['range' => 'semua']))
            ->assertOk()
            ->assertSee('Belum ada dokumen')
            ->assertDontSee('Lihat semua tanggal');
    }
}
