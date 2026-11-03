<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDay;
use App\Services\TableExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Absensi Harian: siapa yang sudah masuk pada satu tanggal.
 */
class AttendanceController extends Controller implements HasMiddleware
{
    public const STATUS_FILTERS = [
        'hadir' => 'Hadir tepat waktu',
        'telat' => 'Telat',
        'pulang_cepat' => 'Pulang cepat',
        'belum_absen' => 'Belum absen',
        'tidak_hadir' => 'Tidak hadir',
        'izin_sakit_cuti' => 'Izin / Sakit / Cuti',
        'libur' => 'Libur',
    ];

    public function __construct(private readonly AttendanceCalculator $calculator)
    {
    }

    public static function middleware(): array
    {
        return [new Middleware('can:attendance.view')];
    }

    public function index(Request $request): View
    {
        $date = $this->date($request);
        $all = $this->days($date);

        return view('admin.attendance.index', [
            'date' => $date,
            'days' => $this->filter($all, $request),
            'stats' => $this->stats($all),
            'statusFilters' => self::STATUS_FILTERS,
            'hasEmployees' => $all->isNotEmpty(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $date = $this->date($request);
        $days = $this->filter($this->days($date), $request);

        return TableExport::make('Absensi Harian')
            ->note('Tanggal: '.$date->translatedFormat('l, j F Y'))
            ->sheet('Absensi', [
                ['No', 'number'],
                ['Kode', 'text'],
                ['Nama', 'text'],
                ['Jabatan', 'text'],
                ['Jam Kerja', 'text'],
                ['Masuk', 'text'],
                ['Sumber Masuk', 'text'],
                ['Pulang', 'text'],
                ['Sumber Pulang', 'text'],
                ['Status', 'text'],
                ['Telat (menit)', 'sum'],
                ['Pulang Cepat (menit)', 'sum'],
                ['Keterangan', 'text'],
            ], $days->values()->map(fn (AttendanceDay $day, int $index) => [
                $index + 1,
                $day->employee->code,
                $day->employee->name,
                $day->employee->position,
                $day->shift?->timeRange(),
                $day->checkIn?->punched_at->format('H:i'),
                $day->checkIn?->sourceLabel(),
                $day->checkOut?->punched_at->format('H:i'),
                $day->checkOut?->sourceLabel(),
                $day->label,
                $day->lateMinutes,
                $day->earlyMinutes,
                $day->note(),
            ]))
            ->download('absensi-'.$date->format('Y-m-d').'.xlsx');
    }

    /**
     * Riwayat satu karyawan dalam sebulan, lengkap dengan semua tapnya.
     */
    public function employee(Request $request, Employee $employee): View
    {
        $month = $this->month($request);
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay()->min(CarbonImmutable::today());
        $to = $to->lessThan($from) ? $from : $to;

        $days = $this->calculator->range(collect([$employee]), $from, $to)->get($employee->id);

        $punches = $employee->punches()
            ->with(['device:id,name', 'location:id,name'])
            ->whereBetween('punched_at', [$from->subHours(4), $to->addDays(2)])
            ->orderBy('punched_at')
            ->get();

        return view('admin.attendance.employee', [
            'employee' => $employee->load('workShift', 'user:id,email'),
            'month' => $month,
            'days' => $days->reverse(),
            'summary' => $this->calculator->summarize($days),
            'punches' => $punches->groupBy(fn (AttendancePunch $punch) => $punch->punched_at->format('Y-m-d')),
            'focus' => $request->input('date'),
        ]);
    }

    /**
     * Selfie absen HP. Disimpan di disk privat; hanya bisa dibuka lewat sini.
     */
    public function selfie(AttendancePunch $punch)
    {
        abort_unless($punch->selfie_path && Storage::disk('local')->exists($punch->selfie_path), 404);

        return Storage::disk('local')->response($punch->selfie_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * @return Collection<int, AttendanceDay>
     */
    protected function days(CarbonImmutable $date): Collection
    {
        $employees = Employee::query()
            ->active()
            ->with('workShift')
            ->orderBy('name')
            ->get();

        return $this->calculator->range($employees, $date, $date)
            ->map(fn (Collection $days) => $days->first())
            ->values();
    }

    /**
     * @param  Collection<int, AttendanceDay>  $days
     * @return Collection<int, AttendanceDay>
     */
    protected function filter(Collection $days, Request $request): Collection
    {
        $search = mb_strtolower($request->string('search')->trim()->value());
        $status = (string) $request->input('status');
        $source = (string) $request->input('source');

        return $days
            ->when($search !== '', fn (Collection $days) => $days->filter(
                fn (AttendanceDay $day) => str_contains(mb_strtolower($day->employee->name.' '.$day->employee->code.' '.$day->employee->position), $search),
            ))
            ->when(array_key_exists($status, self::STATUS_FILTERS), fn (Collection $days) => $days->filter(
                fn (AttendanceDay $day) => $this->matchesStatus($day, $status),
            ))
            ->when(in_array($source, [AttendancePunch::SOURCE_MACHINE, AttendancePunch::SOURCE_PHONE], true), fn (Collection $days) => $days->filter(
                fn (AttendanceDay $day) => $day->checkIn?->source === $source || $day->checkOut?->source === $source,
            ))
            ->values();
    }

    protected function matchesStatus(AttendanceDay $day, string $status): bool
    {
        return match ($status) {
            'hadir' => $day->status === AttendanceDay::PRESENT && ! $day->isLate() && ! $day->leftEarly(),
            'telat' => $day->isLate(),
            'pulang_cepat' => $day->leftEarly(),
            'belum_absen' => $day->status === AttendanceDay::NOT_YET,
            'tidak_hadir' => $day->status === AttendanceDay::ABSENT,
            'izin_sakit_cuti' => $day->leave !== null,
            'libur' => in_array($day->status, [AttendanceDay::OFF, AttendanceDay::NO_SCHEDULE], true),
            default => true,
        };
    }

    /**
     * @param  Collection<int, AttendanceDay>  $days
     * @return array<string, int>
     */
    protected function stats(Collection $days): array
    {
        $leaves = $days->filter(fn (AttendanceDay $day) => $day->isOnLeave());

        return [
            'scheduled' => $days->filter(fn (AttendanceDay $day) => $day->isWorkday)->count(),
            'present' => $days->filter(fn (AttendanceDay $day) => $day->isPresent())->count(),
            'late' => $days->filter(fn (AttendanceDay $day) => $day->isLate())->count(),
            'not_yet' => $days->filter(fn (AttendanceDay $day) => $day->status === AttendanceDay::NOT_YET)->count(),
            'leave' => $leaves->count(),
            'izin' => $leaves->filter(fn (AttendanceDay $day) => $day->leave->type === 'izin')->count(),
            'sakit' => $leaves->filter(fn (AttendanceDay $day) => $day->leave->type === 'sakit')->count(),
            'cuti' => $leaves->filter(fn (AttendanceDay $day) => $day->leave->type === 'cuti')->count(),
            'absent' => $days->filter(fn (AttendanceDay $day) => $day->status === AttendanceDay::ABSENT)->count(),
        ];
    }

    protected function date(Request $request): CarbonImmutable
    {
        $value = (string) $request->input('date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value)
            ? CarbonImmutable::parse($value)->startOfDay()
            : CarbonImmutable::today();
    }

    protected function month(Request $request): CarbonImmutable
    {
        $value = (string) $request->input('month');

        return preg_match('/^\d{4}-\d{2}$/', $value) && strtotime($value.'-01')
            ? CarbonImmutable::parse($value.'-01')
            : CarbonImmutable::today()->startOfMonth();
    }
}
