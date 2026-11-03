<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceLocation;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDay;
use App\Services\Attendance\PhonePunchRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Absen dari aplikasi HP: status hari ini, tap masuk/pulang, riwayat bulanan.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly PhonePunchRecorder $recorder,
    ) {
    }

    /** Kartu absen di beranda. */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json($this->todayPayload($employee));
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'type' => ['required', Rule::in([AttendancePunch::TYPE_IN, AttendancePunch::TYPE_OUT])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'is_mock' => ['nullable', 'boolean'],
            'selfie' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ], [
            'latitude.required' => 'Lokasi belum terbaca. Nyalakan GPS lalu coba lagi.',
            'longitude.required' => 'Lokasi belum terbaca. Nyalakan GPS lalu coba lagi.',
            'selfie.image' => 'Selfie harus berupa foto.',
            'selfie.max' => 'Ukuran foto selfie terlalu besar.',
        ]);

        if ($request->boolean('is_mock')) {
            throw ValidationException::withMessages(['location' => 'Lokasi palsu (fake GPS) terdeteksi. Matikan aplikasi pengubah lokasi lalu coba lagi.']);
        }

        // Cegah dobel tekan: tap HP jenis yang sama dalam 1 menit terakhir.
        $recent = $employee->punches()
            ->where('source', AttendancePunch::SOURCE_PHONE)
            ->where('type', $data['type'])
            ->where('punched_at', '>=', now()->subMinute())
            ->latest('punched_at')
            ->first();

        if ($recent) {
            return response()->json([
                'message' => 'Absen '.($data['type'] === 'in' ? 'masuk' : 'pulang').' sudah tercatat pukul '.$recent->punched_at->format('H:i').'.',
                'punch' => $this->punchPayload($recent, null),
                'today' => $this->todayPayload($employee),
            ]);
        }

        $result = $this->recorder->record(
            $employee,
            $data['type'],
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (int) round($data['accuracy']) : null,
            $request->file('selfie'),
        );

        $today = $this->todayPayload($employee);

        return response()->json([
            'message' => 'Absen '.($data['type'] === 'in' ? 'masuk' : 'pulang').' tercatat.',
            'punch' => $this->punchPayload($result['punch'], $result['location']?->name),
            'today' => $today,
        ], 201);
    }

    /** Riwayat absen sendiri per bulan. */
    public function history(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $value = (string) $request->input('month');
        $month = preg_match('/^\d{4}-\d{2}$/', $value) && strtotime($value.'-01')
            ? CarbonImmutable::parse($value.'-01')
            : CarbonImmutable::today()->startOfMonth();

        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay()->min(CarbonImmutable::today());

        if ($to->lessThan($from)) {
            return response()->json(['month' => $month->format('Y-m'), 'month_label' => $month->translatedFormat('F Y'), 'summary' => $this->calculator->summarize(collect()), 'days' => []]);
        }

        $days = $this->calculator->range(collect([$employee]), $from, $to)->get($employee->id);

        return response()->json([
            'month' => $month->format('Y-m'),
            'month_label' => $month->translatedFormat('F Y'),
            'summary' => $this->calculator->summarize($days),
            'days' => $days->reverse()
                ->filter(fn (AttendanceDay $day) => $day->isWorkday || $day->isPresent() || $day->leave)
                ->map(fn (AttendanceDay $day) => $day->toArray() + ['date_label' => $day->date->translatedFormat('D, j M')])
                ->values(),
        ]);
    }

    protected function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            abort(response()->json(['message' => 'Akunmu belum terhubung ke data karyawan. Minta admin menghubungkannya di menu Karyawan.'], 403));
        }

        return $employee->loadMissing('workShift');
    }

    /**
     * @return array<string, mixed>
     */
    protected function todayPayload(Employee $employee): array
    {
        $date = $this->workingDate($employee);
        $day = $this->calculator->range(collect([$employee]), $date, $date)->get($employee->id)->first();
        $shift = $employee->workShift;

        $next = match (true) {
            ! $employee->is_active || ! $employee->allowsPhone() => null,
            $day->checkIn === null && $day->checkOut === null => AttendancePunch::TYPE_IN,
            default => AttendancePunch::TYPE_OUT,
        };

        return [
            'employee' => [
                'name' => $employee->name,
                'code' => $employee->code,
                'position' => $employee->position,
                'method' => $employee->attendance_method,
                'can_use_phone' => $employee->is_active && $employee->allowsPhone(),
            ],
            'date' => $date->format('Y-m-d'),
            'date_label' => $date->translatedFormat('l, j M'),
            'shift' => $shift ? [
                'name' => $shift->name,
                'start' => substr($shift->start_time, 0, 5),
                'end' => substr($shift->end_time, 0, 5),
                'label' => $shift->name.' '.$shift->timeRange(),
            ] : null,
            'day' => $day->toArray(),
            'next_action' => $next,
            'leave' => $day->leave ? [
                'type' => $day->leave->type,
                'label' => $day->leave->typeLabel(),
                'period' => $day->leave->periodLabel(),
            ] : null,
            'require_selfie' => AppSetting::flag(AppSetting::ATTENDANCE_REQUIRE_SELFIE),
            'allow_outside_radius' => AppSetting::flag(AppSetting::ATTENDANCE_ALLOW_OUTSIDE),
            // Supaya HP bisa memberi tahu jarak ke gudang sebelum absen dikirim.
            'locations' => AttendanceLocation::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (AttendanceLocation $location) => [
                    'name' => $location->name,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'radius_meters' => $location->radius_meters,
                ])->values(),
        ];
    }

    /**
     * Tanggal kerja yang sedang berjalan. Untuk shift malam, tap dini hari
     * masih milik shift kemarin.
     */
    protected function workingDate(Employee $employee): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $shift = $employee->workShift;

        if ($shift) {
            [, $windowEnd] = $this->calculator->window($shift, $today->subDay());

            if (CarbonImmutable::now()->lessThan($windowEnd) && $shift->end_time <= $shift->start_time) {
                return $today->subDay();
            }
        }

        return $today;
    }

    /**
     * @return array<string, mixed>
     */
    protected function punchPayload(AttendancePunch $punch, ?string $locationName): array
    {
        return [
            'id' => $punch->id,
            'type' => $punch->type,
            'time' => $punch->punched_at->format('H:i'),
            'location' => $locationName ?? $punch->location?->name,
            'distance_meters' => $punch->distance_meters,
            'outside_radius' => $punch->outside_radius,
            'has_selfie' => $punch->selfie_path !== null,
        ];
    }
}
