<?php

namespace App\Services;

use App\Models\MobileAppRelease;
use App\Support\ApkInfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Menyimpan APK aplikasi Android sebagai versi baru — dipakai halaman admin
 * dan kiriman otomatis dari GitHub Actions.
 */
class MobileAppPublisher
{
    public function publish(UploadedFile $file, ?string $notes = null, bool $required = false, ?int $userId = null): MobileAppRelease
    {
        $info = $this->inspect($file);

        if (MobileAppRelease::where('version_code', $info->versionCode)->exists()) {
            throw ValidationException::withMessages([
                'apk' => "Versi {$info->versionName} (kode {$info->versionCode}) sudah pernah diunggah.",
            ]);
        }

        $path = $file->storeAs('mobile-app', "wms-cheery-scan-{$info->versionCode}.apk", MobileAppRelease::DISK);

        return MobileAppRelease::create([
            'version_code' => $info->versionCode,
            'version_name' => $info->versionName ?: (string) $info->versionCode,
            'notes' => filled($notes) ? mb_substr(trim($notes), 0, 1000) : null,
            'is_required' => $required,
            'is_active' => true,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $userId,
        ]);
    }

    public function inspect(UploadedFile $file): ApkInfo
    {
        try {
            $info = ApkInfo::read($file->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['apk' => $e->getMessage()]);
        }

        $package = config('services.mobile_app.package', 'id.cheerychic.wmsscan');
        if ($info->package !== $package) {
            throw ValidationException::withMessages([
                'apk' => "APK ini milik aplikasi lain ({$info->package}), bukan WMS Cheery Scan ({$package}).",
            ]);
        }

        return $info;
    }
}
