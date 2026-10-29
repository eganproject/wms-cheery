<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen berurutan seperti OUT-202610-0001.
 *
 * Urutannya dibaca dari angka setelah awalan, bukan dari urutan teks:
 * "…-10000" lebih kecil dari "…-9999" bila dibandingkan sebagai teks, jadi
 * tanpa ini nomor ke-10.000 dalam sebulan akan terus bertabrakan.
 *
 * Dua stasiun yang membuat dokumen pada saat yang sama bisa membaca nomor
 * terakhir yang sama. Penyimpanan yang tertolak karena nomornya sudah dipakai
 * diulang dengan nomor berikutnya, alih-alih berakhir sebagai galat 500.
 */
trait GeneratesDocumentCode
{
    abstract protected static function codePrefix(): string;

    public static function nextCode(): string
    {
        $prefix = static::codePrefix();

        $last = static::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function performInsert(Builder $query)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // Savepoint: transaksi pemanggil tetap utuh bila insert ditolak.
                return DB::transaction(fn () => parent::performInsert($query));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 5 || ! str_contains($exception->getMessage(), 'code')) {
                    throw $exception;
                }

                $this->exists = false;
                $this->setAttribute('code', static::nextCode());
            }
        }
    }
}
