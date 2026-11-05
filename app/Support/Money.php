<?php

namespace App\Support;

/**
 * Membaca angka rupiah dari teks Excel/CSV: "IDR 59.000", "59,000",
 * "59000.00", "Rp 1.250.000". Kosong atau tak terbaca menjadi null.
 */
class Money
{
    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = preg_replace('/[^0-9.,\-]/', '', (string) $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        // Pemisah ribuan lebih dulu: "59.000" adalah lima puluh sembilan ribu
        // rupiah, bukan 59 — padahal PHP menganggapnya angka desimal.
        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $value)) {
            return (float) preg_replace('/[.,]/', '', $value);
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        // Pemisah terakhir dengan 1–2 angka di belakangnya adalah desimal.
        if (preg_match('/^(-?[\d.,]*?)[.,](\d{1,2})$/', $value, $match)) {
            return round((float) (preg_replace('/[.,]/', '', $match[1]).'.'.$match[2]), 2);
        }

        $digits = preg_replace('/[.,]/', '', $value);

        return is_numeric($digits) ? (float) $digits : null;
    }

    public static function format(float|int|null $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}
