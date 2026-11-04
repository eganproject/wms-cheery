<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:employees.view', only: ['index']),
            new Middleware('can:employees.create', only: ['create', 'store']),
            new Middleware('can:employees.update', only: ['edit', 'update']),
            new Middleware('can:employees.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $employees = Employee::query()
            ->with(['user:id,email', 'workShift'])
            ->search($request->string('search')->trim()->value())
            ->when(array_key_exists((string) $request->input('method'), Employee::METHODS),
                fn ($query) => $query->where('attendance_method', $request->input('method')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'name' => 'name',
                'method' => 'attendance_method',
                'shift' => fn ($query, $dir) => $query->orderBy(
                    WorkShift::select('name')->whereColumn('work_shifts.id', 'employees.work_shift_id'), $dir
                ),
                'pin' => 'machine_pin',
                'status' => 'is_active',
            ]))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.employees.index', compact('employees'));
    }

    public function create(): View
    {
        return view('admin.employees.create', $this->formData(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = Employee::create($this->validated($request, null) + ['code' => Employee::nextCode()]);

        return redirect()
            ->route('admin.employees.index')
            ->with('success', "Karyawan {$employee->name} berhasil ditambahkan.");
    }

    public function edit(Employee $employee): View
    {
        return view('admin.employees.edit', $this->formData($employee));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $employee->update($this->validated($request, $employee));

        return redirect()
            ->route('admin.employees.index')
            ->with('success', "Karyawan {$employee->name} berhasil diperbarui.");
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        if ($employee->punches()->exists() || $employee->leaves()->exists()) {
            return back()->with('error', 'Karyawan ini sudah punya riwayat absensi. Nonaktifkan saja supaya riwayatnya tetap ada.');
        }

        $employee->delete();

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'Karyawan berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Employee $employee): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'attendance_method' => ['required', Rule::in(array_keys(Employee::METHODS))],
            'machine_pin' => [
                Rule::requiredIf($request->input('attendance_method') === Employee::METHOD_MACHINE),
                'nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/',
                Rule::unique('employees', 'machine_pin')->ignore($employee),
            ],
            'user_id' => [
                Rule::requiredIf($request->input('attendance_method') === Employee::METHOD_PHONE),
                'nullable', 'integer', Rule::exists('users', 'id'),
                Rule::unique('employees', 'user_id')->ignore($employee),
            ],
            'work_shift_id' => ['nullable', 'integer', Rule::exists('work_shifts', 'id')],
            'joined_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ], [
            'machine_pin.required' => 'PIN mesin wajib diisi untuk karyawan yang absen lewat mesin.',
            'machine_pin.unique' => 'PIN mesin ini sudah dipakai karyawan lain.',
            'machine_pin.regex' => 'PIN mesin hanya boleh berisi angka atau huruf.',
            'user_id.required' => 'Pilih akun login supaya karyawan bisa absen dari HP.',
            'user_id.unique' => 'Akun ini sudah terhubung ke karyawan lain.',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(?Employee $employee): array
    {
        $users = User::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $employee?->user_id))
            ->whereDoesntHave('employee', fn ($query) => $query->when($employee, fn ($q) => $q->whereKeyNot($employee->id)))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return [
            'employee' => $employee,
            'code' => $employee?->code ?? Employee::nextCode(),
            'users' => $users,
            'shifts' => WorkShift::query()->where('is_active', true)->orWhere('id', $employee?->work_shift_id)->orderBy('start_time')->get(),
            'defaultMethod' => AppSetting::get(AppSetting::ATTENDANCE_DEFAULT_METHOD) ?? Employee::METHOD_BOTH,
        ];
    }
}
