<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLeave;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Izin, sakit & cuti yang dicatat admin (tanpa alur persetujuan).
 */
class AttendanceLeaveController extends Controller implements HasMiddleware
{
    public const ATTACHMENT_DIR = 'attendance-leaves';

    public static function middleware(): array
    {
        return [
            new Middleware('can:leaves.view', only: ['index', 'attachment']),
            new Middleware('can:leaves.create', only: ['create', 'store']),
            new Middleware('can:leaves.update', only: ['edit', 'update']),
            new Middleware('can:leaves.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $month = $this->month($request);
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $type = array_key_exists((string) $request->input('type'), AttendanceLeave::TYPES) ? $request->input('type') : null;
        $search = $request->string('search')->trim()->value();

        $base = AttendanceLeave::query()->overlapping($from, $to);

        $leaves = (clone $base)
            ->with(['employee:id,code,name,position', 'creator:id,name'])
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($search !== '', fn ($query) => $query->whereHas('employee', fn ($q) => $q->search($search)))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $counts = (clone $base)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return view('admin.attendance-leaves.index', [
            'leaves' => $leaves,
            'month' => $month,
            'counts' => $counts,
            'awayToday' => AttendanceLeave::query()->overlapping(today(), today())->where('is_half_day', false)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.attendance-leaves.form', [
            'leave' => null,
            'employees' => $this->employees(null),
            'selectedEmployee' => $request->integer('employee') ?: null,
            'selectedDate' => $request->input('date'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['created_by'] = $request->user()->id;

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store(self::ATTACHMENT_DIR.'/'.now()->format('Y/m'), 'local');
        }

        $leave = AttendanceLeave::create($data);

        return redirect()
            ->route('admin.attendance-leaves.index', ['month' => $leave->start_date->format('Y-m')])
            ->with('success', $leave->typeLabel().' untuk '.$leave->employee->name.' tercatat.');
    }

    public function edit(AttendanceLeave $attendanceLeave): View
    {
        return view('admin.attendance-leaves.form', [
            'leave' => $attendanceLeave,
            'employees' => $this->employees($attendanceLeave),
            'selectedEmployee' => $attendanceLeave->employee_id,
            'selectedDate' => null,
        ]);
    }

    public function update(Request $request, AttendanceLeave $attendanceLeave): RedirectResponse
    {
        $data = $this->validated($request, $attendanceLeave);

        if ($request->hasFile('attachment')) {
            $old = $attendanceLeave->attachment_path;
            $data['attachment_path'] = $request->file('attachment')->store(self::ATTACHMENT_DIR.'/'.now()->format('Y/m'), 'local');
        } elseif ($request->boolean('remove_attachment')) {
            $old = $attendanceLeave->attachment_path;
            $data['attachment_path'] = null;
        }

        $attendanceLeave->update($data);

        if (! empty($old)) {
            Storage::disk('local')->delete($old);
        }

        return redirect()
            ->route('admin.attendance-leaves.index', ['month' => $attendanceLeave->start_date->format('Y-m')])
            ->with('success', 'Catatan '.mb_strtolower($attendanceLeave->typeLabel()).' diperbarui.');
    }

    public function destroy(AttendanceLeave $attendanceLeave): RedirectResponse
    {
        if ($attendanceLeave->attachment_path) {
            Storage::disk('local')->delete($attendanceLeave->attachment_path);
        }

        $attendanceLeave->delete();

        return back()->with('success', 'Catatan dihapus.');
    }

    public function attachment(AttendanceLeave $attendanceLeave)
    {
        $path = $attendanceLeave->attachment_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?AttendanceLeave $leave): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'type' => ['required', Rule::in(array_keys(AttendanceLeave::TYPES))],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'attachment.mimes' => 'Lampiran harus foto (JPG/PNG/WEBP) atau PDF.',
            'attachment.max' => 'Lampiran maksimal 5 MB.',
        ]);

        $data['is_half_day'] = $request->boolean('is_half_day');

        if ($data['is_half_day'] && $data['start_date'] !== $data['end_date']) {
            throw ValidationException::withMessages(['is_half_day' => 'Setengah hari hanya untuk satu tanggal.']);
        }

        // Dua catatan untuk karyawan yang sama tidak boleh bertumpuk.
        $overlap = AttendanceLeave::query()
            ->where('employee_id', $data['employee_id'])
            ->when($leave, fn ($query) => $query->whereKeyNot($leave->id))
            ->overlapping(CarbonImmutable::parse($data['start_date']), CarbonImmutable::parse($data['end_date']))
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => 'Sudah ada catatan '.mb_strtolower($overlap->typeLabel()).' ('.$overlap->periodLabel().') untuk karyawan ini di tanggal tersebut.',
            ]);
        }

        unset($data['attachment']);

        return $data;
    }

    protected function employees(?AttendanceLeave $leave)
    {
        return Employee::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $leave?->employee_id))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position']);
    }

    protected function month(Request $request): CarbonImmutable
    {
        $value = (string) $request->input('month');

        return preg_match('/^\d{4}-\d{2}$/', $value) && strtotime($value.'-01')
            ? CarbonImmutable::parse($value.'-01')
            : CarbonImmutable::today()->startOfMonth();
    }
}
