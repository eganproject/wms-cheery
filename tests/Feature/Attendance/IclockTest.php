<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;

class IclockTest extends AttendanceTestCase
{
    public function test_unknown_device_is_refused(): void
    {
        $this->get('/iclock/cdata?SN=UNKNOWN')->assertStatus(401);
        $this->call('POST', '/iclock/cdata?SN=UNKNOWN&table=ATTLOG', [], [], [], [], "101\t2026-11-03 07:50:00\t0\t1")->assertStatus(401);
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_handshake_and_attlog_upload(): void
    {
        $device = AttendanceDevice::create(['name' => 'Pintu', 'serial_number' => 'CQZ7231260012', 'is_active' => true]);
        $employee = $this->employee(['machine_pin' => '101']);

        $this->get('/iclock/cdata?SN=CQZ7231260012&options=all')
            ->assertOk()
            ->assertSee('GET OPTION FROM: CQZ7231260012')
            ->assertSee('TimeZone=7');
        $this->assertNotNull($device->fresh()->last_seen_at);

        $body = "101\t2026-11-03 07:50:00\t0\t1\t0\t0\n999\t2026-11-03 07:51:00\t0\t1\t0\t0\nrusak\n";

        $this->call('POST', '/iclock/cdata?SN=CQZ7231260012&table=ATTLOG&Stamp=1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
            ->assertOk()->assertSee('OK: 2');

        // Kiriman ulang tidak membuat tap ganda.
        $this->call('POST', '/iclock/cdata?SN=CQZ7231260012&table=ATTLOG&Stamp=1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
            ->assertOk()->assertSee('OK: 0');

        $this->assertSame(2, AttendancePunch::count());
        $this->assertSame(1, $employee->punches()->count());
        $this->assertSame(1, AttendancePunch::whereNull('employee_id')->where('machine_pin', '999')->count());

        $this->get('/iclock/getrequest?SN=CQZ7231260012')->assertOk()->assertSee('OK');
        $this->post('/iclock/devicecmd?SN=CQZ7231260012', ['ID' => 1, 'Return' => 0])->assertOk();

        $this->actingAs($this->admin)->get(route('admin.attendance.index'))->assertSee('07:50');
    }

    public function test_inactive_device_is_refused(): void
    {
        AttendanceDevice::create(['name' => 'Lama', 'serial_number' => 'OLD1', 'is_active' => false]);

        $this->get('/iclock/cdata?SN=OLD1')->assertStatus(401);
    }
}
