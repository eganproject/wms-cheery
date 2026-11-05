<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobPosition;
use App\Models\Role;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master jabatan karyawan beserta role bawaannya.
 */
class JobPositionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:employees.view', only: ['index']),
            new Middleware('can:employees.update', except: ['index']),
        ];
    }

    public function index(Request $request): View
    {
        return view('admin.job-positions.index', [
            'positions' => JobPosition::query()
                ->with('role:id,name')
                ->withCount([
                    'employees' => fn ($query) => $query->where('is_active', true),
                    // Karyawan yang role akunnya beda dari role bawaan jabatannya.
                    'employees as mismatched_count' => fn ($query) => $query->where('is_active', true)
                        ->whereNotNull('employees.user_id')
                        ->whereHas('user', fn ($user) => $user->whereColumn('users.role_id', '!=', 'job_positions.role_id')),
                ])
                ->orderByDesc('is_active')->orderBy('name')
                ->tap(fn ($query) => Listing::sort($query, $request, [
                    'name' => 'name',
                    'role' => fn ($query, $dir) => $query->orderBy(
                        Role::select('name')->whereColumn('roles.id', 'job_positions.role_id'), $dir
                    ),
                    'employees' => 'employees_count',
                    'status' => 'is_active',
                ]))
                ->paginate(Listing::perPage($request))
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.job-positions.form', ['position' => null, 'roles' => $this->roles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $position = JobPosition::create($this->validated($request, null));

        return redirect()->route('admin.job-positions.index')->with('success', "Jabatan {$position->name} berhasil ditambahkan.");
    }

    public function edit(JobPosition $jobPosition): View
    {
        $jobPosition->loadCount(['employees' => fn ($query) => $query->whereNotNull('user_id')]);

        return view('admin.job-positions.form', ['position' => $jobPosition, 'roles' => $this->roles()]);
    }

    public function update(Request $request, JobPosition $jobPosition): RedirectResponse
    {
        $jobPosition->update($this->validated($request, $jobPosition));

        $message = "Jabatan {$jobPosition->name} berhasil diperbarui.";

        if ($request->boolean('apply_to_accounts') && $request->user()->can('users.update')) {
            $changed = $jobPosition->applyRoleToAccounts();
            $message .= $changed ? " Role {$changed} akun karyawan ikut diubah." : ' Semua akun karyawannya sudah sesuai.';
        }

        return redirect()->route('admin.job-positions.index')->with('success', $message);
    }

    public function destroy(JobPosition $jobPosition): RedirectResponse
    {
        if ($jobPosition->employees()->exists()) {
            return back()->with('error', 'Jabatan ini masih dipakai karyawan. Ganti jabatan karyawannya dulu, atau nonaktifkan jabatannya.');
        }

        $jobPosition->delete();

        return redirect()->route('admin.job-positions.index')->with('success', 'Jabatan berhasil dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?JobPosition $position): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('job_positions', 'name')->ignore($position)],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [
            'name.unique' => 'Jabatan dengan nama ini sudah ada.',
        ]);

        // Role super admin hanya boleh diberikan oleh super admin.
        if (! empty($data['role_id']) && ! $request->user()->isSuperAdmin()
            && Role::whereKey($data['role_id'])->where('is_super_admin', true)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role_id' => 'Hanya super admin yang bisa memakai role super admin sebagai role bawaan.',
            ]);
        }

        $data['name'] = trim($data['name']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    protected function roles()
    {
        return Role::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query->where('is_super_admin', false))
            ->orderBy('name')
            ->get(['id', 'name', 'description']);
    }
}
