<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\AttendanceLocation;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AttendancePagesTest extends AttendanceTestCase
{
    public function test_admin_pages_render(): void
    {
        $employee = $this->employee(['machine_pin' => '101']);
        $employee->punches()->create(['source' => 'machine', 'punched_at' => '2026-11-03 07:52:00', 'machine_pin' => '101']);
        $this->warehouse();
        AttendanceDevice::create(['name' => 'Pintu masuk', 'serial_number' => 'CQZ1', 'is_active' => true]);
        AttendanceLeave::create(['employee_id' => $employee->id, 'type' => 'izin', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01']);

        $this->actingAs($this->admin);

        $this->get(route('admin.attendance.index'))->assertOk()->assertSee('Rina Marlina')->assertSee('07:52');
        $this->get(route('admin.attendance.index', ['status' => 'telat']))->assertOk()->assertDontSee('Rina Marlina');
        $this->get(route('admin.attendance.employee', $employee))->assertOk()->assertSee('07:52');
        $this->get(route('admin.attendance.report'))->assertOk()->assertSee('Rina Marlina');
        $this->get(route('admin.attendance-leaves.index'))->assertOk()->assertSee('Rina Marlina');
        $this->get(route('admin.attendance-leaves.create'))->assertOk();
        $this->get(route('admin.employees.index'))->assertOk()->assertSee('Mesin &amp; HP', false);
        $this->get(route('admin.employees.create'))->assertOk();
        $this->get(route('admin.employees.edit', $employee))->assertOk();
        $this->get(route('admin.work-shifts.index'))->assertOk()->assertSee('Reguler');
        $this->get(route('admin.work-shifts.create'))->assertOk();
        $this->get(route('admin.attendance-settings.index'))->assertOk()->assertSee('CQZ1')->assertSee('Gudang Cheery Chic');
    }

    public function test_exports_download(): void
    {
        $this->employee();
        $this->actingAs($this->admin);

        $this->get(route('admin.attendance.export'))->assertOk()->assertDownload('absensi-2026-11-03.xlsx');
        $this->get(route('admin.attendance.report.export', ['month' => '2026-10']))->assertOk()->assertDownload('rekap-absensi-2026-10.xlsx');
    }

    public function test_staff_without_permission_cannot_open_attendance_pages(): void
    {
        $staff = User::create([
            'name' => 'Staf', 'email' => 'staf@cheery.test', 'password' => 'password', 'is_active' => true,
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);

        $this->actingAs($staff);
        $this->get(route('admin.attendance.index'))->assertForbidden();
        $this->get(route('admin.employees.index'))->assertForbidden();
        $this->get(route('admin.attendance-settings.index'))->assertForbidden();
    }

    public function test_employee_crud_and_validation(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.employees.store'), ['name' => 'Ayu', 'attendance_method' => 'machine', 'is_active' => 1])
            ->assertSessionHasErrors('machine_pin');
        $this->post(route('admin.employees.store'), ['name' => 'Ayu', 'attendance_method' => 'phone', 'is_active' => 1])
            ->assertSessionHasErrors('user_id');

        $this->post(route('admin.employees.store'), [
            'name' => 'Ayu', 'attendance_method' => 'phone', 'user_id' => $this->admin->id,
            'work_shift_id' => $this->shift->id, 'is_active' => 1,
        ])->assertRedirect(route('admin.employees.index'));

        $ayu = Employee::where('name', 'Ayu')->firstOrFail();
        $this->assertSame('K-0001', $ayu->code);
        $this->assertTrue($ayu->user->is($this->admin));

        // Satu akun hanya untuk satu karyawan.
        $this->post(route('admin.employees.store'), ['name' => 'Budi', 'attendance_method' => 'both', 'user_id' => $this->admin->id, 'is_active' => 1])
            ->assertSessionHasErrors('user_id');

        $this->put(route('admin.employees.update', $ayu), [
            'name' => 'Ayu Lestari', 'attendance_method' => 'both', 'machine_pin' => '103', 'is_active' => 0,
        ])->assertRedirect();
        $this->assertFalse($ayu->fresh()->is_active);
        $this->assertSame('103', $ayu->fresh()->machine_pin);

        $this->delete(route('admin.employees.destroy', $ayu))->assertRedirect();
        $this->assertModelMissing($ayu);
    }

    public function test_setting_a_pin_links_earlier_machine_taps(): void
    {
        $device = AttendanceDevice::create(['name' => 'Mesin', 'serial_number' => 'SN1', 'is_active' => true]);
        $punch = AttendancePunch::create(['source' => 'machine', 'attendance_device_id' => $device->id, 'machine_pin' => '205', 'punched_at' => '2026-11-03 07:50:00']);

        $employee = $this->employee();
        $this->assertNull($punch->fresh()->employee_id);

        $employee->update(['machine_pin' => '205']);
        $this->assertSame($employee->id, $punch->fresh()->employee_id);
    }

    public function test_employee_with_history_cannot_be_deleted(): void
    {
        $employee = $this->employee();
        $employee->punches()->create(['source' => 'phone', 'type' => 'in', 'punched_at' => now()]);

        $this->actingAs($this->admin)->delete(route('admin.employees.destroy', $employee))->assertSessionHas('error');
        $this->assertModelExists($employee);
    }

    public function test_leave_crud_with_attachment_and_overlap_check(): void
    {
        Storage::fake('local');
        $employee = $this->employee();
        $this->actingAs($this->admin);

        $this->post(route('admin.attendance-leaves.store'), [
            'employee_id' => $employee->id, 'type' => 'sakit', 'start_date' => '2026-11-03', 'end_date' => '2026-11-04',
            'reason' => 'Demam', 'attachment' => UploadedFile::fake()->image('surat.jpg'),
        ])->assertRedirect();

        $leave = AttendanceLeave::firstOrFail();
        Storage::disk('local')->assertExists($leave->attachment_path);
        $this->assertSame($this->admin->id, $leave->created_by);
        $this->get(route('admin.attendance-leaves.attachment', $leave))->assertOk();
        $this->get(route('admin.attendance-leaves.edit', $leave))->assertOk()->assertSee('Demam');

        $this->post(route('admin.attendance-leaves.store'), [
            'employee_id' => $employee->id, 'type' => 'izin', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04',
        ])->assertSessionHasErrors('start_date');

        $this->post(route('admin.attendance-leaves.store'), [
            'employee_id' => $employee->id, 'type' => 'izin', 'start_date' => '2026-11-05', 'end_date' => '2026-11-06', 'is_half_day' => 1,
        ])->assertSessionHasErrors('is_half_day');

        $this->get(route('admin.attendance.index'))->assertSee('Sakit');

        $this->put(route('admin.attendance-leaves.update', $leave), [
            'employee_id' => $employee->id, 'type' => 'cuti', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'remove_attachment' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $old = $leave->attachment_path;
        $this->assertNull($leave->fresh()->attachment_path);
        Storage::disk('local')->assertMissing($old);

        $this->delete(route('admin.attendance-leaves.destroy', $leave))->assertRedirect();
        $this->assertModelMissing($leave);
    }

    public function test_work_shift_and_holiday_management(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.work-shifts.store'), [
            'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '15:00', 'work_days' => ['1', '2', '3'],
            'late_tolerance_minutes' => 5, 'is_active' => 1,
        ])->assertRedirect(route('admin.work-shifts.index'));

        $pagi = WorkShift::where('name', 'Pagi')->firstOrFail();
        $this->assertSame([1, 2, 3], $pagi->work_days);
        $this->assertSame('Sen–Rab', $pagi->daysLabel());

        $this->post(route('admin.work-shifts.store'), ['name' => 'X', 'start_time' => '07:00', 'end_time' => '07:00', 'late_tolerance_minutes' => 0])
            ->assertSessionHasErrors(['end_time', 'work_days']);

        $this->employee();
        $this->delete(route('admin.work-shifts.destroy', $this->shift))->assertSessionHas('error');
        $this->delete(route('admin.work-shifts.destroy', $pagi))->assertRedirect();

        $this->post(route('admin.holidays.store'), ['date' => '2026-12-25', 'name' => 'Natal'])->assertRedirect();
        $this->post(route('admin.holidays.store'), ['date' => '2026-12-25', 'name' => 'Natal'])->assertSessionHasErrors('date');
        $this->delete(route('admin.holidays.destroy', Holiday::firstOrFail()))->assertRedirect();
        $this->assertSame(0, Holiday::count());
    }

    public function test_locations_devices_and_rules(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.attendance-settings.locations.store'), [
            'name' => 'Gudang', 'coordinates' => '-7.2575, 112.7521', 'radius_meters' => 150, 'is_active' => 1,
        ])->assertRedirect();
        $location = AttendanceLocation::firstOrFail();
        $this->assertEqualsWithDelta(-7.2575, $location->latitude, 0.00001);
        $this->assertSame(150, $location->radius_meters);

        $this->post(route('admin.attendance-settings.locations.store'), ['name' => 'X', 'coordinates' => 'bukan koordinat', 'radius_meters' => 100])
            ->assertSessionHasErrors('latitude');

        $this->post(route('admin.attendance-settings.devices.store'), ['name' => 'Pintu', 'serial_number' => ' cqz7231 '])->assertRedirect();
        $device = AttendanceDevice::firstOrFail();
        $this->assertSame('CQZ7231', $device->serial_number);
        $this->post(route('admin.attendance-settings.devices.store'), ['name' => 'Lagi', 'serial_number' => 'CQZ7231'])->assertSessionHasErrors('serial_number');

        $this->put(route('admin.attendance-settings.update'), ['require_selfie' => 0, 'allow_outside_radius' => 1, 'default_method' => 'machine'])->assertRedirect();
        $this->assertFalse(\App\Models\AppSetting::flag(\App\Models\AppSetting::ATTENDANCE_REQUIRE_SELFIE));
        $this->assertTrue(\App\Models\AppSetting::flag(\App\Models\AppSetting::ATTENDANCE_ALLOW_OUTSIDE));

        // Mesin yang sudah punya tap hanya dinonaktifkan.
        $device->punches()->create(['source' => 'machine', 'machine_pin' => '1', 'punched_at' => now()]);
        $this->delete(route('admin.attendance-settings.devices.destroy', $device))->assertRedirect();
        $this->assertFalse($device->fresh()->is_active);

        $this->delete(route('admin.attendance-settings.locations.destroy', $location))->assertRedirect();
        $this->assertModelMissing($location);
    }

    public function test_selfie_is_only_served_to_logged_in_admins(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('attendance-selfies/a.jpg', 'jpg');
        $punch = $this->employee()->punches()->create(['source' => 'phone', 'type' => 'in', 'punched_at' => now(), 'selfie_path' => 'attendance-selfies/a.jpg']);

        $this->get(route('admin.attendance.selfie', $punch))->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get(route('admin.attendance.selfie', $punch))->assertOk();
    }
}
