<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\OutboundScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Bagian Scan Kemas yang di web berupa redirect, dijawab JSON untuk aplikasi.
 * Scan resi dan scan barang memakai controller web yang sama persis.
 */
class PackingController extends Controller implements HasMiddleware
{
    public function __construct(protected OutboundScanService $scanner) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.scan'),
        ];
    }

    public function reset(Outbound $outbound): JsonResponse
    {
        $this->scanner->reset($outbound);

        return response()->json([
            'message' => 'Progres scan direset. Scan resinya lagi untuk mulai dari awal.',
        ]);
    }
}
