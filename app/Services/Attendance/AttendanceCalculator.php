<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLeave;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Menghitung status absensi harian dari tap, jam kerja, hari libur, dan
 * catatan izin/sakit/cuti.
 *
 * Aturan singkat:
 *  - Tap masuk = tap pertama di sekitar jam kerja. Tap pulang = tap "pulang"
 *    dari HP, atau tap mesin terakhir setelah pertengahan shift.
 *  - Telat bila masuk lewat dari jam masuk + toleransi; menitnya dihitung dari
 *    jam masuk.
 *  - Pulang cepat bila tap pulang sebelum jam pulang.
 *  - Hari kerja tanpa tap: "belum absen" selama shift berjalan, "tidak hadir"
 *    setelah shift selesai.
 */
class AttendanceCalculator
{
    /** Tap sejauh ini sebelum jam masuk masih dihitung milik shift itu. */
    protected const EARLY_WINDOW_HOURS = 4;

    /** Tap sejauh ini setelah jam pulang masih dihitung (lembur). */
    protected const LATE_WINDOW_HOURS = 8;

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Collection<string, AttendanceDay>> per id karyawan, lalu per tanggal Y-m-d
     */
    public function range(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $employees = (new EloquentCollection($employees->values()->all()))->loadMissing('workShift');
        $ids = $employees->pluck('id');

        // Shift malam bisa berakhir keesokan harinya; ambil tap sedikit lebih lebar.
        $punches = AttendancePunch::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [
                $from->subHours(self::EARLY_WINDOW_HOURS),
                $to->addDays(2),
            ])
            ->orderBy('punched_at')
            ->get()
            ->groupBy('employee_id');

        $leaves = AttendanceLeave::query()
            ->whereIn('employee_id', $ids)
            ->overlapping($from, $to)
            ->get()
            ->groupBy('employee_id');

        $holidays = Holiday::query()
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->get()
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->format('Y-m-d') => $holiday->name]);

        $dates = collect(CarbonPeriod::create($from, $to))->map(fn ($date) => CarbonImmutable::parse($date));

        return $employees->mapWithKeys(fn (Employee $employee) => [
            $employee->id => $dates->mapWithKeys(fn (CarbonImmutable $date) => [
                $date->format('Y-m-d') => $this->day(
                    $employee,
                    $date,
                    $punches->get($employee->id, collect()),
                    $leaves->get($employee->id, collect()),
                    $holidays->get($date->format('Y-m-d')),
                ),
            ]),
        ]);
    }

    public function day(
        Employee $employee,
        CarbonImmutable $date,
        Collection $punches,
        Collection $leaves,
        ?string $holiday,
    ): AttendanceDay {
        $shift = $employee->workShift;
        $leave = $leaves->first(fn (AttendanceLeave $leave) => $leave->covers($date));
        $registered = $this->registeredOn($employee, $date);
        $isWorkday = $shift !== null && $shift->worksOn($date) && $holiday === null && $registered;

        [$windowStart, $windowEnd, $start, $end] = $this->window($shift, $date);

        $inWindow = $punches->filter(
            fn (AttendancePunch $punch) => $punch->punched_at->greaterThanOrEqualTo($windowStart)
                && $punch->punched_at->lessThan($windowEnd),
        )->values();

        [$checkIn, $checkOut] = $this->pair($inWindow, $start, $end);

        $base = [
            'employee' => $employee,
            'date' => $date,
            'shift' => $shift,
            'isWorkday' => $isWorkday,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'leave' => $leave,
            'holiday' => $holiday,
        ];

        // Izin/sakit/cuti sehari penuh menimpa semuanya.
        if ($leave && ! $leave->is_half_day) {
            return new AttendanceDay(...$base, status: $leave->type, label: $leave->typeLabel(),
                variant: $leave->variant(), notes: array_filter([$leave->reason]));
        }

        if (! $isWorkday) {
            $present = $checkIn || $checkOut;
            $label = match (true) {
                $present => $shift === null ? 'Hadir' : 'Masuk di hari libur',
                ! $registered => 'Belum terdaftar',
                $shift === null => 'Tanpa jadwal',
                default => 'Libur',
            };

            return new AttendanceDay(...$base,
                status: ($shift === null || ! $registered) && ! $present ? AttendanceDay::NO_SCHEDULE : ($present ? AttendanceDay::PRESENT : AttendanceDay::OFF),
                label: $label,
                variant: $present ? 'info' : 'neutral',
                notes: array_values(array_filter([$holiday, ...$this->punchNotes($checkIn, $checkOut)])),
            );
        }

        $notes = $leave ? [$leave->typeLabel().' ½ hari'.($leave->reason ? ': '.$leave->reason : '')] : [];

        if (! $checkIn && ! $checkOut) {
            $now = CarbonImmutable::now();

            if ($now->lessThan($end)) {
                return new AttendanceDay(...$base, status: AttendanceDay::NOT_YET,
                    label: $now->lessThan($start) ? 'Belum mulai' : 'Belum absen',
                    variant: 'neutral', notes: $notes);
            }

            return new AttendanceDay(...$base, status: $leave ? $leave->type : AttendanceDay::ABSENT,
                label: $leave ? $leave->typeLabel().' ½ hari' : 'Tidak hadir',
                variant: $leave ? $leave->variant() : 'danger',
                notes: $notes ?: ['Tanpa keterangan']);
        }

        // Setengah hari izin: jam datang/pulang tidak dihitung telat/pulang cepat.
        $tolerance = $shift->late_tolerance_minutes;
        $late = ! $leave && $checkIn && $checkIn->punched_at->greaterThan($start->addMinutes($tolerance))
            ? (int) $start->diffInMinutes($checkIn->punched_at)
            : 0;
        $early = ! $leave && $checkOut && $checkOut->punched_at->lessThan($end)
            ? (int) ceil($checkOut->punched_at->diffInSeconds($end) / 60)
            : 0;
        $missingOut = $checkIn && ! $checkOut && CarbonImmutable::now()->greaterThan($end);

        if ($late && $early) {
            $notes[] = 'Pulang cepat '.$early.' mnt';
        }
        if ($missingOut) {
            $notes[] = 'Tidak absen pulang';
        }
        if (! $checkIn && $checkOut) {
            $notes[] = 'Tidak absen masuk';
        }
        $notes = [...$notes, ...$this->punchNotes($checkIn, $checkOut)];

        [$label, $variant] = match (true) {
            $leave !== null => [$leave->typeLabel().' ½ hari', $leave->variant()],
            $late > 0 => ['Telat '.$late.' mnt', 'warning'],
            $early > 0 => ['Pulang cepat '.$early.' mnt', 'warning'],
            default => ['Hadir', 'success'],
        };

        return new AttendanceDay(...$base, status: AttendanceDay::PRESENT, label: $label, variant: $variant,
            lateMinutes: $late, earlyMinutes: $early, missingCheckOut: $missingOut, notes: $notes);
    }

    /**
     * Hari sebelum karyawan masuk kerja, atau sebelum datanya dibuat di WMS,
     * tidak dihitung hari kerja. Tanpa ini, karyawan yang baru didaftarkan
     * di tengah bulan langsung tercatat "Tidak hadir" untuk hari-hari sebelumnya.
     */
    protected function registeredOn(Employee $employee, CarbonImmutable $date): bool
    {
        $since = collect([$employee->joined_at, $employee->created_at])
            ->filter()
            ->map(fn ($value) => CarbonImmutable::parse($value)->startOfDay())
            ->max();

        return $since === null || $since->lessThanOrEqualTo($date);
    }

    /**
     * Rentang tap yang dihitung milik tanggal kerja ini, plus jam masuk/pulang.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    public function window(?WorkShift $shift, CarbonImmutable $date): array
    {
        if ($shift === null) {
            $day = $date->startOfDay();

            return [$day, $day->addDay(), $day, $day->addDay()];
        }

        [$start, $end] = $shift->boundsOn($date);

        // Jendela dua hari berurutan tidak boleh tumpang tindih.
        $windowEnd = $end->addHours(self::LATE_WINDOW_HOURS);
        $limit = $start->addDay()->subHours(self::EARLY_WINDOW_HOURS);

        return [
            $start->subHours(self::EARLY_WINDOW_HOURS),
            $windowEnd->lessThan($limit) ? $windowEnd : $limit,
            $start,
            $end,
        ];
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches  urut waktu
     * @return array{0: ?AttendancePunch, 1: ?AttendancePunch}
     */
    protected function pair(Collection $punches, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $checkIn = $punches->first(fn (AttendancePunch $punch) => $punch->type !== AttendancePunch::TYPE_OUT);

        $checkOut = $punches->last(fn (AttendancePunch $punch) => $punch->type === AttendancePunch::TYPE_OUT);

        if (! $checkOut) {
            // Mesin tidak tahu masuk atau pulang: tap terakhir setelah
            // pertengahan shift dianggap pulang.
            $middle = $start->addSeconds((int) ($start->diffInSeconds($end) / 2));
            $checkOut = $punches->last(fn (AttendancePunch $punch) => $punch->type === null
                && $punch->isNot($checkIn)
                && $punch->punched_at->greaterThanOrEqualTo($middle));
        }

        if ($checkIn && $checkOut && $checkOut->punched_at->lessThan($checkIn->punched_at)) {
            $checkIn = null;
        }

        return [$checkIn, $checkOut];
    }

    /** @return array<int, string> */
    protected function punchNotes(?AttendancePunch $checkIn, ?AttendancePunch $checkOut): array
    {
        $outside = collect([$checkIn, $checkOut])->filter()->contains(fn (AttendancePunch $punch) => $punch->outside_radius);

        return $outside ? ['Di luar lokasi'] : [];
    }

    /**
     * Rekap satu karyawan dari hari-harinya.
     *
     * @param  Collection<string, AttendanceDay>  $days
     * @return array<string, int|float>
     */
    public function summarize(Collection $days): array
    {
        $today = CarbonImmutable::today();
        $counted = $days->filter(fn (AttendanceDay $day) => $day->date->lessThanOrEqualTo($today));
        $workdays = $counted->filter(fn (AttendanceDay $day) => $day->isWorkday);
        $fullLeave = fn (string $type) => $workdays->filter(fn (AttendanceDay $day) => $day->isOnLeave() && $day->leave->type === $type)->count();

        $summary = [
            'workdays' => $workdays->count(),
            'present' => $workdays->filter(fn (AttendanceDay $day) => $day->isPresent())->count(),
            'late' => $workdays->filter(fn (AttendanceDay $day) => $day->isLate())->count(),
            'late_minutes' => $workdays->sum(fn (AttendanceDay $day) => $day->lateMinutes),
            'early' => $workdays->filter(fn (AttendanceDay $day) => $day->leftEarly())->count(),
            'izin' => $fullLeave('izin'),
            'sakit' => $fullLeave('sakit'),
            'cuti' => $fullLeave('cuti'),
            'absent' => $workdays->filter(fn (AttendanceDay $day) => $day->status === AttendanceDay::ABSENT)->count(),
        ];

        $expected = $summary['workdays'] - $summary['izin'] - $summary['sakit'] - $summary['cuti'];
        $summary['rate'] = $expected > 0 ? (int) round(min(100, $summary['present'] / $expected * 100)) : null;

        return $summary;
    }
}
