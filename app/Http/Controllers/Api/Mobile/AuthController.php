<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login aplikasi Android memakai email dan sandi yang sama dengan web.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $key = 'mobile-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => 'Email atau sandi salah.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Akun ini dinonaktifkan. Hubungi admin.',
            ]);
        }

        RateLimiter::clear($key);

        [, $plain] = MobileToken::issue($user, $data['device_name'] ?? 'Android');
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $plain,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('mobile_token')?->delete();

        return response()->json(['message' => 'Sudah keluar.']);
    }

    /**
     * Data pengguna berikut menu yang boleh dibuka di aplikasi — memakai hak
     * akses yang sama persis dengan halaman web-nya.
     *
     * @return array<string, mixed>
     */
    protected function profile(User $user): array
    {
        $user->loadMissing('role', 'employee');
        $employee = $user->employee;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'can' => [
                // QC Kemas di web: outbounds.create + outbounds.scan.
                'kemas' => $user->can('outbounds.create') && $user->can('outbounds.scan'),
                // Serah Terima Paket di web: outbounds.post.
                'serah_terima' => $user->can('outbounds.post'),
                // Halaman Pesanan di web: imports.view.
                'pesanan' => $user->can('imports.view'),
                // Absen dari HP: akun terhubung ke karyawan aktif bermetode HP.
                'absen' => $employee !== null && $employee->is_active && $employee->allowsPhone(),
            ],
        ];
    }
}
