<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pembacaan parameter yang sama untuk endpoint laporan di API.
 *
 * Berbeda dengan halaman admin yang diam-diam mengabaikan masukan rusak, API
 * menolaknya dengan 400: sistem lain yang salah kirim parameter harus tahu,
 * bukan menerima data dengan saringan yang tidak ia minta.
 */
trait ParsesApiParameters
{
    /**
     * @return array{0: int|false, 1: int|false}
     */
    protected function paging(Request $request, int $default = 100, int $max = 500): array
    {
        return [
            filter_var($request->input('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            filter_var($request->input('per_page', $default), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]),
        ];
    }

    /**
     * Tanggal YYYY-MM-DD dalam zona waktu Asia/Jakarta, null bila kosong,
     * false bila bentuknya tidak valid.
     */
    protected function day(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');

            return $date->format('Y-m-d') === $value ? $date : false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Nilai string opsional yang harus salah satu dari pilihan.
     *
     * @param  array<int, string>  $allowed
     */
    protected function choice(Request $request, string $key, array $allowed): string|false|null
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && in_array($value, $allowed, true) ? $value : false;
    }

    protected function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value))
            ->setTimezone('Asia/Jakarta')->toIso8601String();
    }

    /**
     * @return array<string, int>
     */
    protected function pageMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    protected function bad(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'INVALID_PARAMETER', 'message' => $message,
        ]], 400);
    }
}
