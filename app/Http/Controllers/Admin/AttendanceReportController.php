<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\TableExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap Absensi: ringkasan kehadiran per karyawan dalam satu bulan.
 */
class AttendanceReportController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AttendanceCalculator $calculator)
    {
    }

    public static function middleware(): array
    {
        return [new Middleware('can:attendance.view')];
    }

    public function index(Request $request): View
    {
        $month = $this->month($request);

        return view('admin.attendance.report', [
            'month' => $month,
            'rows' => $this->rows($month, $request),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $month = $this->month($request);

        return TableExport::make('Rekap Absensi')
            ->note('Bulan: '.$month->translatedFormat('F Y').' · dihitung sampai '.$this->until($month)->translatedFormat('j F Y'))
            ->sheet('Rekap', [
                ['No', 'number'],
                ['Kode', 'text'],
                ['Nama', 'text'],
                ['Jabatan', 'text'],
                ['Hari Kerja', 'sum'],
                ['Hadir', 'sum'],
                ['Telat (kali)', 'sum'],
                ['Telat (menit)', 'sum'],
                ['Pulang Cepat (kali)', 'sum'],
                ['Izin', 'sum'],
                ['Sakit', 'sum'],
                ['Cuti', 'sum'],
                ['Tidak Hadir', 'sum'],
                ['Kehadiran (%)', 'number'],
            ], $this->rows($month, $request)->values()->map(fn (array $row, int $index) => [
                $index + 1,
                $row['employee']->code,
                $row['employee']->name,
                $row['employee']->position,
                $row['summary']['workdays'],
                $row['summary']['present'],
                $row['summary']['late'],
                $row['summary']['late_minutes'],
                $row['summary']['early'],
                $row['summary']['izin'],
                $row['summary']['sakit'],
                $row['summary']['cuti'],
                $row['summary']['absent'],
                $row['summary']['rate'],
            ]))
            ->download('rekap-absensi-'.$month->format('Y-m').'.xlsx');
    }

    /**
     * @return Collection<int, array{employee: Employee, summary: array<string, int|float|null>}>
     */
    protected function rows(CarbonImmutable $month, Request $request): Collection
    {
        $from = $month->startOfMonth();
        $until = $this->until($month);

        // Karyawan yang sudah nonaktif tetap muncul bila punya tap di bulan ini.
        $employees = Employee::query()
            ->with('workShift')
            ->search($request->string('search')->trim()->value())
            ->where(fn ($query) => $query->where('is_active', true)->orWhereHas('punches', fn ($q) => $q->whereBetween('punched_at', [$from, $until->endOfDay()])))
            ->where(fn ($query) => $query->whereNull('joined_at')->orWhere('joined_at', '<=', $until->format('Y-m-d')))
            ->orderBy('name')
            ->get();

        if ($until->lessThan($from)) {
            return $employees->map(fn (Employee $employee) => [
                'employee' => $employee,
                'summary' => $this->calculator->summarize(collect()),
            ]);
        }

        $days = $this->calculator->range($employees, $from, $until);

        return $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'summary' => $this->calculator->summarize($days->get($employee->id, collect())),
        ]);
    }

    /** Bulan berjalan dihitung sampai hari ini saja. */
    protected function until(CarbonImmutable $month): CarbonImmutable
    {
        return $month->endOfMonth()->startOfDay()->min(CarbonImmutable::today());
    }

    protected function month(Request $request): CarbonImmutable
    {
        $value = (string) $request->input('month');

        return preg_match('/^\d{4}-\d{2}$/', $value) && strtotime($value.'-01')
            ? CarbonImmutable::parse($value.'-01')
            : CarbonImmutable::today()->startOfMonth();
    }
}
