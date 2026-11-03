<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLocation;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AttendanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected WorkShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        // Selasa, 3 Nov 2026 jam 10:00 WIB.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

        $this->shift = WorkShift::create([
            'name' => 'Reguler',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'work_days' => [1, 2, 3, 4, 5, 6],
            'late_tolerance_minutes' => 10,
            'is_active' => true,
        ]);
    }

    protected function employee(array $attributes = []): Employee
    {
        $employee = Employee::create($attributes + [
            'code' => Employee::nextCode(),
            'name' => 'Rina Marlina',
            'position' => 'Packer',
            'work_shift_id' => $this->shift->id,
            'attendance_method' => Employee::METHOD_BOTH,
            'joined_at' => '2026-01-01',
            'is_active' => true,
        ]);

        // Anggap datanya sudah lama ada di WMS, supaya hari-hari lalu ikut dihitung.
        $employee->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();

        return $employee;
    }

    protected function warehouse(int $radius = 100): AttendanceLocation
    {
        return AttendanceLocation::create([
            'name' => 'Gudang Cheery Chic',
            'latitude' => -7.2575,
            'longitude' => 112.7521,
            'radius_meters' => $radius,
            'is_active' => true,
        ]);
    }
}
