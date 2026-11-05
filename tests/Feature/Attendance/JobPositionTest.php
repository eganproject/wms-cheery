<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\Role;
use App\Models\User;

class JobPositionTest extends AttendanceTestCase
{
    protected function staffRole(): Role
    {
        return Role::where('slug', 'staff-gudang')->firstOrFail();
    }

    protected function account(string $email, ?Role $role = null): User
    {
        return User::create([
            'name' => 'Akun '.$email, 'email' => $email, 'password' => 'password',
            'role_id' => ($role ?? Role::where('slug', 'admin')->firstOrFail())->id, 'is_active' => true,
        ]);
    }

    public function test_pages_render_and_crud(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.job-positions.index'))->assertOk()->assertSee('Belum ada jabatan');
        $this->get(route('admin.job-positions.create'))->assertOk();

        $this->post(route('admin.job-positions.store'), ['name' => 'Packer', 'role_id' => $this->staffRole()->id, 'is_active' => 1])
            ->assertRedirect(route('admin.job-positions.index'));
        $this->post(route('admin.job-positions.store'), ['name' => 'Packer', 'is_active' => 1])->assertSessionHasErrors('name');

        $packer = JobPosition::where('name', 'Packer')->firstOrFail();
        $this->assertTrue($packer->role->is($this->staffRole()));

        $this->get(route('admin.job-positions.index'))->assertOk()->assertSee('Staff Gudang');
        $this->get(route('admin.job-positions.edit', $packer))->assertOk();

        // Nama jabatan baru ikut tersalin ke karyawan.
        $employee = $this->employee(['job_position_id' => $packer->id]);
        $this->assertSame('Packer', $employee->fresh()->position);
        $this->put(route('admin.job-positions.update', $packer), ['name' => 'Staff Packing', 'role_id' => $this->staffRole()->id, 'is_active' => 1])
            ->assertRedirect();
        $this->assertSame('Staff Packing', $employee->fresh()->position);

        // Masih dipakai karyawan: tidak bisa dihapus.
        $this->delete(route('admin.job-positions.destroy', $packer))->assertSessionHas('error');
        $employee->update(['job_position_id' => null]);
        $this->assertNull($employee->fresh()->position);
        $this->delete(route('admin.job-positions.destroy', $packer))->assertRedirect();
        $this->assertModelMissing($packer);
    }

    public function test_employee_form_uses_position_select_and_syncs_account_role(): void
    {
        $this->actingAs($this->admin);
        $packer = JobPosition::create(['name' => 'Packer', 'role_id' => $this->staffRole()->id, 'is_active' => true]);
        $account = $this->account('rina@cheery.test');

        $this->get(route('admin.employees.create'))->assertOk()->assertSee('Packer · role Staff Gudang');

        // Tanpa centang: role akun tidak diubah.
        $this->post(route('admin.employees.store'), [
            'name' => 'Rina', 'job_position_id' => $packer->id, 'attendance_method' => 'phone',
            'user_id' => $account->id, 'is_active' => 1,
        ])->assertRedirect();
        $rina = Employee::where('name', 'Rina')->firstOrFail();
        $this->assertSame('Packer', $rina->position);
        $this->assertSame('admin', $account->fresh()->role->slug);

        // Dengan centang: role akun mengikuti jabatan.
        $this->put(route('admin.employees.update', $rina), [
            'name' => 'Rina', 'job_position_id' => $packer->id, 'attendance_method' => 'phone',
            'user_id' => $account->id, 'is_active' => 1, 'sync_role' => 1,
        ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'Staff Gudang'));
        $this->assertSame('staff-gudang', $account->fresh()->role->slug);

        // Akun super admin tidak pernah diturunkan otomatis.
        $budi = Employee::create(['code' => Employee::nextCode(), 'name' => 'Budi', 'attendance_method' => 'machine', 'machine_pin' => '9', 'is_active' => true]);
        $this->put(route('admin.employees.update', $budi), [
            'name' => 'Budi', 'job_position_id' => $packer->id, 'attendance_method' => 'both',
            'user_id' => $this->admin->id, 'is_active' => 1, 'sync_role' => 1,
        ])->assertRedirect();
        $this->assertTrue($this->admin->fresh()->isSuperAdmin());

        // Filter daftar karyawan per jabatan.
        $this->get(route('admin.employees.index', ['position' => $packer->id]))->assertOk()->assertSee('Rina');
    }

    public function test_apply_role_to_existing_accounts(): void
    {
        $this->actingAs($this->admin);
        $packer = JobPosition::create(['name' => 'Packer', 'is_active' => true]);
        $one = $this->account('a@cheery.test');
        $two = $this->account('b@cheery.test');
        $this->employee(['name' => 'A', 'job_position_id' => $packer->id, 'user_id' => $one->id]);
        $this->employee(['name' => 'B', 'job_position_id' => $packer->id, 'user_id' => $two->id]);

        $this->put(route('admin.job-positions.update', $packer), [
            'name' => 'Packer', 'role_id' => $this->staffRole()->id, 'is_active' => 1, 'apply_to_accounts' => 1,
        ])->assertSessionHas('success', fn ($message) => str_contains($message, '2 akun'));

        $this->assertSame('staff-gudang', $one->fresh()->role->slug);
        $this->assertSame('staff-gudang', $two->fresh()->role->slug);
    }

    public function test_user_form_links_employee(): void
    {
        $this->actingAs($this->admin);
        $packer = JobPosition::create(['name' => 'Packer', 'role_id' => $this->staffRole()->id, 'is_active' => true]);
        $rina = $this->employee(['job_position_id' => $packer->id]);

        $this->get(route('admin.users.create'))->assertOk()->assertSee('Rina Marlina · '.$rina->code.' · Packer');

        $this->post(route('admin.users.store'), [
            'name' => 'Rina Marlina', 'email' => 'rina@cheery.test', 'role_id' => $this->staffRole()->id,
            'password' => 'password123', 'password_confirmation' => 'password123', 'is_active' => 1,
            'employee_id' => $rina->id,
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'rina@cheery.test')->firstOrFail();
        $this->assertTrue($rina->fresh()->user->is($user));

        // Karyawan yang sudah punya akun tidak bisa dipakai akun lain.
        $this->post(route('admin.users.store'), [
            'name' => 'Lain', 'email' => 'lain@cheery.test', 'role_id' => $this->staffRole()->id,
            'password' => 'password123', 'password_confirmation' => 'password123', 'is_active' => 1,
            'employee_id' => $rina->id,
        ])->assertSessionHasErrors('employee_id');

        $this->get(route('admin.users.edit', $user))->assertOk();

        // Karyawan HP-only tidak boleh dilepas dari akunnya.
        $rina->update(['attendance_method' => Employee::METHOD_PHONE]);
        $this->put(route('admin.users.update', $user), [
            'name' => 'Rina Marlina', 'email' => 'rina@cheery.test', 'role_id' => $this->staffRole()->id,
            'is_active' => 1, 'employee_id' => '',
        ])->assertSessionHasErrors('employee_id');

        // Metode lain: boleh dilepas.
        $rina->update(['attendance_method' => Employee::METHOD_BOTH]);
        $this->put(route('admin.users.update', $user), [
            'name' => 'Rina Marlina', 'email' => 'rina@cheery.test', 'role_id' => $this->staffRole()->id,
            'is_active' => 1, 'employee_id' => '',
        ])->assertRedirect();
        $this->assertNull($rina->fresh()->user_id);
    }
}
