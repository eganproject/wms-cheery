<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Menyimpan log tap (ATTLOG) kiriman mesin fingerprint ZKTeco.
 *
 * Satu baris per tap, dipisah tab: PIN, waktu (Y-m-d H:i:s), lalu kolom lain
 * yang tidak dipakai. Mesin sering mengirim ulang log lama, jadi tap yang sama
 * (mesin, PIN, waktu) hanya disimpan sekali.
 */
class MachinePunchIngestion
{
    public function ingest(AttendanceDevice $device, string $body): int
    {
        $stored = 0;
        $employees = Employee::query()->whereNotNull('machine_pin')->pluck('id', 'machine_pin');

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) ?: [] as $line) {
            $columns = explode("\t", trim($line));

            if (count($columns) < 2 || $columns[0] === '') {
                continue;
            }

            $pin = trim($columns[0]);

            try {
                // Jam mesin mengikuti zona waktu aplikasi (dikirim saat handshake).
                $time = CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($columns[1]), config('app.timezone'));
            } catch (Throwable) {
                continue;
            }

            if (! $time || mb_strlen($pin) > 20) {
                continue;
            }

            $punch = AttendancePunch::query()->firstOrCreate([
                'attendance_device_id' => $device->id,
                'machine_pin' => $pin,
                'punched_at' => $time,
            ], [
                'employee_id' => $employees->get($pin),
                'source' => AttendancePunch::SOURCE_MACHINE,
            ]);

            $stored += $punch->wasRecentlyCreated ? 1 : 0;
        }

        return $stored;
    }
}
