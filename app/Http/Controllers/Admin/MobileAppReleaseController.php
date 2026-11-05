<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\MobileAppPublisher;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pengaturan > Aplikasi Android: unggah APK baru, HP lalu memperbarui
 * dirinya sendiri dari server ini (tanpa GitHub / Play Store).
 */
class MobileAppReleaseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:mobile-app.view', only: ['index', 'download']),
            new Middleware('can:mobile-app.update', only: ['store', 'update']),
            new Middleware('can:mobile-app.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $releases = MobileAppRelease::query()
            ->with('uploader:id,name')
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'version' => 'version_code',
                'size' => 'file_size',
                'uploaded' => 'created_at',
                'status' => 'is_active',
            ], ['version', 'desc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.mobile-app.index', [
            'releases' => $releases,
            'latest' => MobileAppRelease::latestActive(),
            'requiredCode' => MobileAppRelease::requiredVersionCode(),
            'downloadUrl' => route('mobile.app-download'),
            'maxUploadMb' => $this->maxUploadMb(),
        ]);
    }

    public function store(Request $request, MobileAppPublisher $publisher): RedirectResponse
    {
        $request->validate([
            'apk' => ['required', 'file', 'max:'.(200 * 1024)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_required' => ['nullable', 'boolean'],
        ], [
            'apk.required' => 'Pilih berkas APK yang akan diunggah.',
            'apk.uploaded' => 'APK gagal diunggah. Ukurannya mungkin melebihi batas upload server ('.$this->maxUploadMb().' MB).',
            'apk.max' => 'APK maksimal 200 MB.',
        ]);

        $release = $publisher->publish(
            $request->file('apk'),
            $request->input('notes'),
            $request->boolean('is_required'),
            $request->user()->id,
        );

        $latest = MobileAppRelease::latestActive();
        $message = "Versi {$release->version_name} berhasil diunggah.";
        $message .= $latest?->is($release)
            ? ' HP akan ditawari pembaruan saat aplikasi dibuka.'
            : " Versi {$latest?->version_name} lebih baru, jadi itu yang tetap ditawarkan ke HP.";

        return back()->with('success', $message);
    }

    public function update(Request $request, MobileAppRelease $release): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_required' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $release->update($data + ['notes' => null]);

        return back()->with('success', "Versi {$release->version_name} diperbarui.");
    }

    public function destroy(MobileAppRelease $release): RedirectResponse
    {
        Storage::disk(MobileAppRelease::DISK)->delete($release->file_path);
        $release->delete();

        return back()->with('success', "Versi {$release->version_name} dihapus.");
    }

    public function download(MobileAppRelease $release): StreamedResponse
    {
        abort_unless(Storage::disk(MobileAppRelease::DISK)->exists($release->file_path), 404, 'Berkas APK tidak ditemukan di server.');

        return Storage::disk(MobileAppRelease::DISK)->download(
            $release->file_path,
            "wms-cheery-scan-{$release->version_name}.apk",
            ['Content-Type' => 'application/vnd.android.package-archive'],
        );
    }

    /** Batas upload PHP (upload_max_filesize / post_max_size), dalam MB. */
    private function maxUploadMb(): int
    {
        $toBytes = function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };

        $limits = array_filter([
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')),
        ]);

        return $limits ? (int) floor(min($limits) / 1024 ** 2) : 0;
    }
}
