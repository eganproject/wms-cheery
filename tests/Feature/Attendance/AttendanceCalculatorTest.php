<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDay;
use Carbon\CarbonImmutable;

class AttendanceCalculatorTest extends AttendanceTestCase
{
    protected function day(Employee $employee, string $date): AttendanceDay
    {
        $date = CarbonImmutable::parse($date);

        return app(AttendanceCalculator::class)->range(collect([$employee]), $date, $date)->get($employee->id)->first();
    }

    protected function tap(Employee $employee, string $at, ?string $type = null, string $source = 'machine'): AttendancePunch
    {
        return $employee->punches()->create(['source' => $source, 'type' => $type, 'punched_at' => $at, 'machine_pin' => $source === 'machine' ? '101' : null]);
    }

    public function test_on_time_arrival_and_departure_is_present(): void
    {
        $employee = $this->employee();
        $this->tap($employee, '2026-11-02 07:52:00');
        $this->tap($employee, '2026-11-02 17:04:00');

        $day = $this->day($employee, '2026-11-02');

        $this->assertSame('hadir', $day->status);
        $this->assertSame('Hadir', $day->label);
        $this->assertSame('07:52', $day->checkIn->punched_at->format('H:i'));
        $this->assertSame('17:04', $day->checkOut->punched_at->format('H:i'));
    }

    public function test_late_is_counted_from_shift_start_after_tolerance(): void
    {
        $employee = $this->employee();
        $this->tap($employee, '2026-11-02 08:09:00');
        $this->assertSame(0, $this->day($employee, '2026-11-02')->lateMinutes);

        $other = $this->employee(['name' => 'Dimas']);
        $this->tap($other, '2026-11-02 08:17:00');
        $day = $this->day($other, '2026-11-02');
        $this->assertSame(17, $day->lateMinutes);
        $this->assertSame('Telat 17 mnt', $day->label);
    }

    public function test_double_tap_in_the_morning_is_not_a_checkout(): void
    {
        $employee = $this->employee();
        $this->tap($employee, '2026-11-02 07:55:00');
        $this->tap($employee, '2026-11-02 07:56:00');

        $day = $this->day($employee, '2026-11-02');

        $this->assertNull($day->checkOut);
        $this->assertTrue($day->missingCheckOut);
        $this->assertStringContainsString('Tidak absen pulang', $day->note());
    }

    public function test_phone_checkout_before_midday_still_counts_as_leaving_early(): void
    {
        $employee = $this->employee();
        $this->tap($employee, '2026-11-02 07:58:00', 'in', 'phone');
        $this->tap($employee, '2026-11-02 11:30:00', 'out', 'phone');

        $day = $this->day($employee, '2026-11-02');

        $this->assertSame('11:30', $day->checkOut->punched_at->format('H:i'));
        $this->assertSame(330, $day->earlyMinutes);
        $this->assertSame('Pulang cepat 330 mnt', $day->label);
    }

    public function test_no_tap_is_not_yet_during_shift_and_absent_after(): void
    {
        $employee = $this->employee();

        $this->assertSame('belum_absen', $this->day($employee, '2026-11-03')->status);
        $this->assertSame('tidak_hadir', $this->day($employee, '2026-11-02')->status);
    }

    public function test_sunday_and_holidays_are_off_and_leave_overrides_absence(): void
    {
        $employee = $this->employee();
        Holiday::create(['date' => '2026-10-31', 'name' => 'Libur contoh']);
        AttendanceLeave::create(['employee_id' => $employee->id, 'type' => 'sakit', 'start_date' => '2026-10-29', 'end_date' => '2026-10-30']);

        $this->assertSame('libur', $this->day($employee, '2026-11-01')->status); // Minggu
        $this->assertSame('libur', $this->day($employee, '2026-10-31')->status);
        $this->assertSame('sakit', $this->day($employee, '2026-10-30')->status);
        $this->assertSame('Sakit', $this->day($employee, '2026-10-29')->label);
    }

    public function test_half_day_leave_skips_late_rule(): void
    {
        $employee = $this->employee();
        AttendanceLeave::create(['employee_id' => $employee->id, 'type' => 'izin', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'is_half_day' => true]);
        $this->tap($employee, '2026-11-02 12:05:00');
        $this->tap($employee, '2026-11-02 17:01:00');

        $day = $this->day($employee, '2026-11-02');

        $this->assertSame(0, $day->lateMinutes);
        $this->assertSame('Izin ½ hari', $day->label);
        $this->assertTrue($day->isPresent());
    }

    public function test_night_shift_checkout_lands_on_the_next_morning(): void
    {
        $night = WorkShift::create([
            'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00',
            'work_days' => [1, 2, 3, 4, 5, 6, 7], 'late_tolerance_minutes' => 10, 'is_active' => true,
        ]);
        $employee = $this->employee(['work_shift_id' => $night->id]);
        $this->tap($employee, '2026-11-01 21:55:00');
        $this->tap($employee, '2026-11-02 06:03:00');
        $this->tap($employee, '2026-11-02 22:20:00');

        $first = $this->day($employee, '2026-11-01');
        $second = $this->day($employee, '2026-11-02');

        $this->assertSame('21:55', $first->checkIn->punched_at->format('H:i'));
        $this->assertSame('06:03', $first->checkOut->punched_at->format('H:i'));
        $this->assertSame('22:20', $second->checkIn->punched_at->format('H:i'));
        $this->assertSame(20, $second->lateMinutes);
    }

    public function test_days_before_joining_are_not_absences(): void
    {
        $employee = $this->employee(['joined_at' => '2026-11-03']);

        $this->assertFalse($this->day($employee, '2026-11-02')->isWorkday);
    }

    public function test_days_before_employee_was_added_are_not_absences(): void
    {
        $employee = $this->employee(['joined_at' => null]);
        $employee->forceFill(['created_at' => '2026-11-03 09:00:00'])->saveQuietly();

        $before = $this->day($employee->fresh(), '2026-11-02');
        $this->assertFalse($before->isWorkday);
        $this->assertSame('Belum terdaftar', $before->label);
        $this->assertTrue($this->day($employee->fresh(), '2026-11-03')->isWorkday);
    }

    public function test_monthly_summary(): void
    {
        $employee = $this->employee();
        $this->tap($employee, '2026-11-02 08:30:00');
        $this->tap($employee, '2026-11-02 17:00:00');
        AttendanceLeave::create(['employee_id' => $employee->id, 'type' => 'cuti', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03']);

        $calculator = app(AttendanceCalculator::class);
        $days = $calculator->range(collect([$employee]), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-03'))->get($employee->id);
        $summary = $calculator->summarize($days);

        $this->assertSame(2, $summary['workdays']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(30, $summary['late_minutes']);
        $this->assertSame(1, $summary['cuti']);
        $this->assertSame(100, $summary['rate']);
    }
}
