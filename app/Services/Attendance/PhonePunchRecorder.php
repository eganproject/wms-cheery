<?php

namespace App\Services\Attendance;

use App\Models\AppSetting;
use App\Models\AttendanceLocation;
use App\Models\AttendancePunch;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Absen dari aplikasi HP: cek lokasi terhadap titik gudang, simpan selfie.
 */
class PhonePunchRecorder
{
    /** Selfie disimpan di disk privat, tidak bisa dibuka tanpa login admin. */
    public const SELFIE_DIR = 'attendance-selfies';

    /**
     * @return array{punch: AttendancePunch, location: ?AttendanceLocation, distance: ?int}
     */
    public function record(
        Employee $employee,
        string $type,
        float $latitude,
        float $longitude,
        ?int $accuracy,
        ?UploadedFile $selfie,
    ): array {
        if (! $employee->is_active) {
            throw ValidationException::withMessages(['employee' => 'Data karyawanmu nonaktif. Hubungi admin.']);
        }

        if (! $employee->allowsPhone()) {
            throw ValidationException::withMessages(['employee' => 'Kamu diatur absen lewat mesin fingerprint, bukan HP.']);
        }

        if (AppSetting::flag(AppSetting::ATTENDANCE_REQUIRE_SELFIE) && ! $selfie) {
            throw ValidationException::withMessages(['selfie' => 'Selfie wajib diambil untuk absen.']);
        }

        [$location, $distance] = $this->nearest($latitude, $longitude);

        if ($location === null) {
            throw ValidationException::withMessages(['location' => 'Lokasi absen belum diatur admin. Hubungi admin gudang.']);
        }

        $outside = $distance > $location->radius_meters;

        if ($outside && ! AppSetting::flag(AppSetting::ATTENDANCE_ALLOW_OUTSIDE)) {
            throw ValidationException::withMessages([
                'location' => 'Kamu di luar lokasi absen. Posisimu sekitar '.$this->distanceLabel($distance)
                    .' dari '.$location->name.', batasnya '.$location->radius_meters.' m.',
            ])->status(422);
        }

        $path = $selfie?->store(self::SELFIE_DIR.'/'.now()->format('Y/m'), 'local');

        $punch = $employee->punches()->create([
            'source' => AttendancePunch::SOURCE_PHONE,
            'type' => $type,
            'punched_at' => now(),
            'attendance_location_id' => $location->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_meters' => $accuracy,
            'distance_meters' => $distance,
            'outside_radius' => $outside,
            'selfie_path' => $path ?: null,
        ]);

        return ['punch' => $punch, 'location' => $location, 'distance' => $distance];
    }

    /**
     * Lokasi aktif terdekat.
     *
     * @return array{0: ?AttendanceLocation, 1: ?int}
     */
    public function nearest(float $latitude, float $longitude): array
    {
        $best = null;
        $bestDistance = null;

        foreach (AttendanceLocation::query()->where('is_active', true)->get() as $location) {
            $distance = $location->distanceTo($latitude, $longitude);

            // Utamakan lokasi yang radiusnya mencakup posisi, lalu yang terdekat.
            $inside = $distance <= $location->radius_meters;
            $bestInside = $best && $bestDistance <= $best->radius_meters;

            if ($best === null || ($inside && ! $bestInside) || ($inside === $bestInside && $distance < $bestDistance)) {
                $best = $location;
                $bestDistance = $distance;
            }
        }

        return [$best, $bestDistance];
    }

    public function distanceLabel(int $meters): string
    {
        return $meters >= 1000
            ? str_replace('.', ',', (string) round($meters / 1000, 1)).' km'
            : $meters.' m';
    }

    public static function deleteSelfie(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
