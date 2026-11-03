<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Services\Attendance\MachinePunchIngestion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Protokol push "iclock" (ADMS) mesin fingerprint ZKTeco.
 *
 * Mesin diisi alamat WMS sebagai Cloud Server lalu mengirim tap ke sini. Mesin
 * tidak bisa mengirim login, jadi yang dilayani hanya nomor seri yang sudah
 * didaftarkan dan aktif di halaman Lokasi & Mesin Absen. Pakai HTTPS.
 */
class IclockController extends Controller
{
    public function __construct(private readonly MachinePunchIngestion $ingestion)
    {
    }

    /** GET /iclock/cdata — mesin meminta pengaturannya. */
    public function handshake(Request $request): Response
    {
        $device = $this->device($request);
        $offsetHours = intdiv(now()->utcOffset(), 60);

        return $this->text(implode("\n", [
            'GET OPTION FROM: '.$device->serial_number,
            'Stamp=9999',
            'OpStamp=9999',
            'ErrorDelay=30',
            'Delay=30',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=1111000000',
            'TimeZone='.$offsetHours,
            'Realtime=1',
            'Encrypt=0',
        ]));
    }

    /** POST /iclock/cdata — mesin mengirim data; table=ATTLOG berisi tap. */
    public function receive(Request $request): Response
    {
        $device = $this->device($request);

        if (strtoupper((string) $request->query('table')) === 'ATTLOG') {
            return $this->text('OK: '.$this->ingestion->ingest($device, $request->getContent()));
        }

        // Data lain (daftar user, log operasi) cukup diterima.
        return $this->text('OK');
    }

    /** GET /iclock/getrequest — mesin menanyakan perintah dari server. */
    public function getrequest(Request $request): Response
    {
        $this->device($request);

        return $this->text('OK');
    }

    /** POST /iclock/devicecmd — mesin melaporkan hasil perintah. */
    public function devicecmd(Request $request): Response
    {
        $this->device($request);

        return $this->text('OK');
    }

    private function device(Request $request): AttendanceDevice
    {
        $serial = strtoupper(trim((string) ($request->query('SN') ?? $request->query('sn'))));

        $device = $serial === '' ? null : AttendanceDevice::query()
            ->where('serial_number', $serial)
            ->where('is_active', true)
            ->first();

        abort_if(! $device, 401, 'Mesin tidak dikenal atau nonaktif.');

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();

        return $device;
    }

    private function text(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
