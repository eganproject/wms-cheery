<?php

namespace App\Providers;

use App\Models\MobileToken;
use App\Models\Product;
use App\Models\User;
use App\Services\StockApiSyncService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPermissionGates();
        $this->registerMobileGuard();

        RateLimiter::for('stock-api', fn (Request $request) => Limit::perMinute(
            config('stock_api.rate_limit_per_minute')
        )->by(hash('sha256', (string) $request->bearerToken().'|'.$request->ip())));

        // Mesin fingerprint menghubungi server tiap ~30 detik; batasi per IP
        // supaya alamat publik ini tidak bisa dibanjiri.
        RateLimiter::for('iclock', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        Product::saved(fn (Product $product) => StockApiSyncService::sync($product));
        Product::deleting(fn (Product $product) => StockApiSyncService::markDeleted($product));
    }

    /**
     * Guard "mobile": aplikasi Android mengirim token Bearer hasil login.
     * Pengguna yang dinonaktifkan langsung tertolak walau tokennya masih ada.
     */
    protected function registerMobileGuard(): void
    {
        Auth::viaRequest('mobile-token', function (Request $request) {
            $token = MobileToken::findPlain($request->bearerToken());

            if (! $token || ! $token->user?->is_active) {
                return null;
            }

            // Cukup dicatat sekali semenit, bukan pada setiap scan.
            if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
                $token->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            $request->attributes->set('mobile_token', $token);

            return $token->user;
        });
    }

    /**
     * Setiap ability berformat "modul.aksi" diperlakukan sebagai permission,
     * sehingga can() dan @can bekerja tanpa perlu mendaftarkan gate satu per satu.
     *
     * Pemeriksaan sengaja dilakukan saat gate dipanggil, bukan saat boot, agar
     * permission yang baru ditambahkan langsung berlaku.
     */
    protected function registerPermissionGates(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if (! str_contains($ability, '.')) {
                return null;
            }

            // null berarti "tidak berpendapat" sehingga policy lain tetap bisa
            // dipertimbangkan; tanpa gate lain hasilnya tetap ditolak.
            return $user->hasPermission($ability) ?: null;
        });
    }
}
