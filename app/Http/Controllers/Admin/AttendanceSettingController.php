<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLocation;
use App\Models\AttendancePunch;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lokasi absen lewat HP, mesin fingerprint, dan aturan absen.
 */
class AttendanceSettingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:attendance-settings.view', only: ['index']),
            new Middleware('can:attendance-settings.update', except: ['index']),
        ];
    }

    public function index(): View
    {
        $today = now()->startOfDay();

        return view('admin.attendance-settings.index', [
            'locations' => AttendanceLocation::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'devices' => AttendanceDevice::query()
                ->withCount(['punches as today_count' => fn ($query) => $query->where('punched_at', '>=', $today)])
                ->orderBy('name')
                ->get(),
            'unmatchedPins' => AttendancePunch::query()
                ->whereNull('employee_id')
                ->where('source', AttendancePunch::SOURCE_MACHINE)
                ->selectRaw('machine_pin, count(*) as total, max(punched_at) as last_at')
                ->groupBy('machine_pin')
                ->orderByDesc('last_at')
                ->limit(20)
                ->get(),
            'requireSelfie' => AppSetting::flag(AppSetting::ATTENDANCE_REQUIRE_SELFIE),
            'allowOutside' => AppSetting::flag(AppSetting::ATTENDANCE_ALLOW_OUTSIDE),
            'defaultMethod' => AppSetting::get(AppSetting::ATTENDANCE_DEFAULT_METHOD),
            'serverHost' => request()->getHost(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'require_selfie' => ['boolean'],
            'allow_outside_radius' => ['boolean'],
            'default_method' => ['required', Rule::in(array_keys(Employee::METHODS))],
        ]);

        AppSetting::put(AppSetting::ATTENDANCE_REQUIRE_SELFIE, $request->boolean('require_selfie') ? '1' : '0');
        AppSetting::put(AppSetting::ATTENDANCE_ALLOW_OUTSIDE, $request->boolean('allow_outside_radius') ? '1' : '0');
        AppSetting::put(AppSetting::ATTENDANCE_DEFAULT_METHOD, $data['default_method']);

        return back()->with('success', 'Aturan absen disimpan.');
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        AttendanceLocation::create($this->locationData($request));

        return back()->with('success', 'Lokasi absen ditambahkan.');
    }

    public function updateLocation(Request $request, AttendanceLocation $location): RedirectResponse
    {
        $location->update($this->locationData($request));

        return back()->with('success', 'Lokasi absen '.$location->name.' diperbarui.');
    }

    public function destroyLocation(AttendanceLocation $location): RedirectResponse
    {
        $location->delete();

        return back()->with('success', 'Lokasi absen dihapus.');
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        AttendanceDevice::create($this->deviceData($request, null));

        return back()->with('success', 'Mesin ditambahkan. Atur alamat server di mesin supaya tapnya masuk.');
    }

    public function updateDevice(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $device->update($this->deviceData($request, $device));

        return back()->with('success', 'Mesin '.$device->name.' diperbarui.');
    }

    public function destroyDevice(AttendanceDevice $device): RedirectResponse
    {
        if ($device->punches()->exists()) {
            $device->update(['is_active' => false]);

            return back()->with('success', 'Mesin sudah punya riwayat tap, jadi dinonaktifkan (tidak dihapus).');
        }

        $device->delete();

        return back()->with('success', 'Mesin dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function locationData(Request $request): array
    {
        // Koordinat boleh ditempel langsung dari Google Maps: "-7.25, 112.75".
        if ($request->filled('coordinates')) {
            $parts = array_map('trim', explode(',', (string) $request->input('coordinates')));
            $request->merge(['latitude' => $parts[0] ?? null, 'longitude' => $parts[1] ?? null]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'min:20', 'max:5000'],
            'is_active' => ['boolean'],
        ], [
            'latitude.required' => 'Isi titik koordinat, misalnya -7.2575, 112.7521.',
            'latitude.numeric' => 'Titik koordinat tidak terbaca. Contoh: -7.2575, 112.7521.',
            'longitude.required' => 'Isi titik koordinat, misalnya -7.2575, 112.7521.',
            'longitude.numeric' => 'Titik koordinat tidak terbaca. Contoh: -7.2575, 112.7521.',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function deviceData(Request $request, ?AttendanceDevice $device): array
    {
        $request->merge(['serial_number' => strtoupper(trim((string) $request->input('serial_number')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'serial_number' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('attendance_devices', 'serial_number')->ignore($device)],
            'is_active' => ['boolean'],
        ], [
            'serial_number.unique' => 'Nomor seri ini sudah terdaftar.',
            'serial_number.regex' => 'Nomor seri hanya berisi huruf, angka, atau tanda hubung.',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
