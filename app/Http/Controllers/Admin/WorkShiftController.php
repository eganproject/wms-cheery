<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\WorkShift;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Jam kerja (shift) dan hari libur.
 */
class WorkShiftController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:work-shifts.view', only: ['index']),
            new Middleware('can:work-shifts.update', except: ['index']),
        ];
    }

    public function index(Request $request): View
    {
        $year = (int) $request->input('year', now()->year);

        return view('admin.work-shifts.index', [
            'shifts' => WorkShift::query()->withCount(['employees' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('is_active')->orderBy('start_time')
                ->tap(fn ($query) => Listing::sort($query, $request, [
                    'name' => 'name',
                    'start' => 'start_time',
                    'end' => 'end_time',
                    'tolerance' => 'late_tolerance_minutes',
                    'employees' => 'employees_count',
                    'status' => 'is_active',
                ]))
                ->paginate(Listing::perPage($request))
                ->withQueryString(),
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')
                ->paginate(Listing::perPage($request, pageName: 'libur'), ['*'], 'libur')
                ->withQueryString(),
            'year' => $year,
        ]);
    }

    public function create(): View
    {
        return view('admin.work-shifts.form', ['shift' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = WorkShift::create($this->validated($request));

        return redirect()->route('admin.work-shifts.index')->with('success', "Jam kerja {$shift->name} berhasil ditambahkan.");
    }

    public function edit(WorkShift $workShift): View
    {
        return view('admin.work-shifts.form', ['shift' => $workShift]);
    }

    public function update(Request $request, WorkShift $workShift): RedirectResponse
    {
        $workShift->update($this->validated($request));

        return redirect()->route('admin.work-shifts.index')->with('success', "Jam kerja {$workShift->name} berhasil diperbarui.");
    }

    public function destroy(WorkShift $workShift): RedirectResponse
    {
        if ($workShift->employees()->exists()) {
            return back()->with('error', 'Jam kerja ini masih dipakai karyawan. Pindahkan karyawannya dulu, atau nonaktifkan jam kerjanya.');
        }

        $workShift->delete();

        return redirect()->route('admin.work-shifts.index')->with('success', 'Jam kerja berhasil dihapus.');
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        if (Holiday::query()->whereDate('date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => 'Tanggal ini sudah tercatat sebagai hari libur.']);
        }

        $holiday = Holiday::create($data);

        return redirect()
            ->route('admin.work-shifts.index', ['year' => $holiday->date->year])
            ->with('success', 'Hari libur '.$holiday->name.' ditambahkan.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $year = $holiday->date->year;
        $holiday->delete();

        return redirect()->route('admin.work-shifts.index', ['year' => $year])->with('success', 'Hari libur dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_end'],
            'break_end' => ['nullable', 'date_format:H:i', 'required_with:break_start'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'is_active' => ['boolean'],
        ], [
            'end_time.different' => 'Jam pulang tidak boleh sama dengan jam masuk.',
            'work_days.required' => 'Pilih minimal satu hari kerja.',
        ]);

        $data['work_days'] = collect($data['work_days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
