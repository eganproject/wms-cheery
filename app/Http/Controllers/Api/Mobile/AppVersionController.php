<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Versi terbaru aplikasi Android, supaya aplikasi yang sudah terpasang bisa
 * memperbarui dirinya sendiri (unduh APK lalu buka penginstal Android).
 *
 * Tanpa login: HP yang sesinya habis pun tetap bisa memperbarui.
 */
class AppVersionController extends Controller
{
    private const CACHE_KEY = 'mobile-app:latest-release';

    public function show(): JsonResponse
    {
        $latest = $this->latest();

        return response()->json([
            'latest' => $latest ? [
                'version_code' => $latest['version_code'],
                'version_name' => $latest['version_name'],
                'download_url' => $latest['download_url'],
                'size' => $latest['size'],
                'notes' => $latest['notes'],
                'published_at' => $latest['published_at'],
            ] : null,
            'min_version_code' => max(
                (int) config('services.mobile_app.min_version_code', 0),
                rescue(fn () => MobileAppRelease::requiredVersionCode(), 0, false),
            ),
        ]);
    }

    /**
     * Unduh APK lewat server ini: APK unggahan admin, atau APK dari repo
     * GitHub private (HP tidak punya token GitHub). Alamat ini juga bisa
     * dibuka dari browser HP untuk pemasangan pertama.
     */
    public function download(): StreamedResponse
    {
        $latest = $this->latest();

        // APK unggahan admin: langsung dari penyimpanan server.
        if (($latest['release'] ?? null) instanceof MobileAppRelease) {
            $release = $latest['release'];
            $disk = Storage::disk(MobileAppRelease::DISK);
            abort_unless($disk->exists($release->file_path), 404, 'Berkas pembaruan tidak ditemukan.');

            return $disk->download($release->file_path, 'wms-cheery-scan.apk', [
                'Content-Type' => 'application/vnd.android.package-archive',
            ]);
        }

        $token = config('services.mobile_app.github_token');

        abort_if(! $latest || ! $token || ! $latest['asset_api_url'], 404, 'Berkas pembaruan tidak ditemukan.');

        $upstream = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->withOptions(['stream' => true])
            ->timeout(300)
            ->get($latest['asset_api_url']);

        abort_unless($upstream->successful(), 502, 'Berkas pembaruan belum bisa diambil. Coba lagi sebentar.');

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }
        }, 200, array_filter([
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="wms-cheery-scan.apk"',
            'Content-Length' => $latest['size'] ? (string) $latest['size'] : null,
        ]));
    }

    /**
     * Versi terbaru yang ditawarkan (juga dipakai halaman unduh /unduh-aplikasi).
     *
     * @return array{version_code:int, version_name:string, download_url:string, size:?int, notes:?string, published_at:?string, asset_api_url:?string, release?:MobileAppRelease}|null
     */
    public function latest(): ?array
    {
        // APK yang diunggah admin (Pengaturan > Aplikasi Android) selalu
        // didahulukan; GitHub hanya cadangan bila belum ada unggahan.
        // rescue: server yang belum `php artisan migrate` tetap jalan lewat GitHub.
        if (rescue(fn () => MobileAppRelease::query()->exists(), false, false)) {
            $release = MobileAppRelease::latestActive();

            return $release ? [
                'version_code' => $release->version_code,
                'version_name' => $release->version_name,
                'download_url' => route('mobile.app-download', absolute: false),
                'size' => $release->file_size,
                'notes' => $release->notes,
                'published_at' => $release->created_at?->toIso8601String(),
                'asset_api_url' => null,
                'release' => $release,
            ] : null;
        }

        $config = config('services.mobile_app');

        // Diisi manual di .env: tidak perlu GitHub sama sekali.
        if (! empty($config['version_code']) && ! empty($config['download_url'])) {
            return [
                'version_code' => (int) $config['version_code'],
                'version_name' => $config['version_name'] ?: (string) $config['version_code'],
                'download_url' => $config['download_url'],
                'size' => null,
                'notes' => $config['notes'] ?: null,
                'published_at' => null,
                'asset_api_url' => null,
            ];
        }

        if (empty($config['github_repo'])) {
            return null;
        }

        // Disimpan sebentar supaya HP yang banyak tidak menghabiskan batas API GitHub.
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached['release'];
        }

        $release = $this->fetchFromGithub($config['github_repo'], $config['github_token'] ?? null, $failed);
        Cache::put(self::CACHE_KEY, ['release' => $release], $failed ? 60 : 600);

        return $release;
    }

    private function fetchFromGithub(string $repo, ?string $token, ?bool &$failed = null): ?array
    {
        $failed = false;

        try {
            $request = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'wms-cheery-server'])
                ->timeout(10);
            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->get("https://api.github.com/repos/{$repo}/releases", ['per_page' => 20]);
        } catch (\Throwable $e) {
            Log::warning('Cek versi aplikasi Android gagal: '.$e->getMessage());
            $failed = true;

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Cek versi aplikasi Android gagal: GitHub '.$response->status());
            $failed = true;

            return null;
        }

        foreach ((array) $response->json() as $release) {
            if (! is_array($release) || ! empty($release['draft']) || ! empty($release['prerelease'])) {
                continue;
            }

            // Tag dari workflow Android: android-v1.0.<nomor run> = versionCode.
            if (! preg_match('/^android-v(\d+\.\d+\.(\d+))$/', (string) ($release['tag_name'] ?? ''), $match)) {
                continue;
            }

            $asset = collect($release['assets'] ?? [])
                ->first(fn ($asset) => str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.apk'));
            if (! $asset) {
                continue;
            }

            return [
                'version_code' => (int) $match[2],
                'version_name' => $match[1],
                'download_url' => $token
                    ? route('mobile.app-download', absolute: false)
                    : (string) $asset['browser_download_url'],
                'size' => isset($asset['size']) ? (int) $asset['size'] : null,
                'notes' => $this->notes($release['body'] ?? null),
                'published_at' => $release['published_at'] ?? null,
                'asset_api_url' => $asset['url'] ?? null,
            ];
        }

        return null;
    }

    /** Catatan rilis singkat (tanpa petunjuk pasang bawaan workflow). */
    private function notes(?string $body): ?string
    {
        $body = trim((string) $body);
        if ($body === '') {
            return null;
        }

        $marker = stripos($body, 'Cara pasang');
        if ($marker !== false) {
            $body = trim(substr($body, 0, $marker));
        }

        return $body === '' ? null : mb_substr($body, 0, 1000);
    }
}
