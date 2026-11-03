<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;

/**
 * Hasil hitung absensi satu karyawan pada satu tanggal kerja.
 */
class AttendanceDay
{
    public const PRESENT = 'hadir';

    public const NOT_YET = 'belum_absen';

    public const ABSENT = 'tidak_hadir';

    public const OFF = 'libur';

    public const NO_SCHEDULE = 'tanpa_jadwal';

    /** @param  array<int, string>  $notes */
    public function __construct(
        public readonly Employee $employee,
        public readonly CarbonImmutable $date,
        public readonly ?WorkShift $shift,
        public readonly string $status,
        public readonly string $label,
        public readonly string $variant,
        public readonly bool $isWorkday,
        public readonly ?AttendancePunch $checkIn = null,
        public readonly ?AttendancePunch $checkOut = null,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyMinutes = 0,
        public readonly bool $missingCheckOut = false,
        public readonly ?AttendanceLeave $leave = null,
        public readonly ?string $holiday = null,
        public readonly array $notes = [],
    ) {
    }

    public function isPresent(): bool
    {
        return $this->checkIn !== null || $this->checkOut !== null;
    }

    public function isLate(): bool
    {
        return $this->lateMinutes > 0;
    }

    public function leftEarly(): bool
    {
        return $this->earlyMinutes > 0;
    }

    /** Izin/sakit/cuti sehari penuh. */
    public function isOnLeave(): bool
    {
        return $this->leave !== null && ! $this->leave->is_half_day;
    }

    public function note(): ?string
    {
        return $this->notes === [] ? null : implode(' · ', $this->notes);
    }

    /**
     * Ringkasan untuk aplikasi HP.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'status' => $this->status,
            'label' => $this->label,
            'variant' => $this->variant,
            'is_workday' => $this->isWorkday,
            'check_in' => $this->checkIn?->punched_at->format('H:i'),
            'check_in_source' => $this->checkIn?->source,
            'check_out' => $this->checkOut?->punched_at->format('H:i'),
            'check_out_source' => $this->checkOut?->source,
            'late_minutes' => $this->lateMinutes,
            'early_minutes' => $this->earlyMinutes,
            'note' => $this->note(),
        ];
    }
}
