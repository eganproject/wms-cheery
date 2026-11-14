<?php

namespace App\Support;

/**
 * Ukuran label QR yang bisa dipilih saat mencetak.
 *
 * Dua jenis kertas: lembar A4 berisi banyak label (stiker A4 / kertas biasa
 * lalu digunting), dan rol printer label thermal yang satu halamannya satu
 * label. Semua ukuran dalam milimeter.
 */
class QrLabelLayout
{
    public const DEFAULT = 'a4-40';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'a4-40' => [
                'label' => 'A4 – 40 label (4 × 10)',
                'hint' => 'Label kecil ±48 × 28 mm, cocok untuk rak dan plastik barang.',
                'sheet' => true,
                'cols' => 4, 'rows' => 10,
                'margin_x' => 8, 'margin_y' => 10,
                'font' => 5.5, 'sku_font' => 6.5, 'name_chars' => 34, 'pad' => 1.5,
            ],
            'a4-21' => [
                'label' => 'A4 – 21 label (3 × 7)',
                'hint' => 'Label sedang ±65 × 40 mm, QR lebih besar dan mudah discan.',
                'sheet' => true,
                'cols' => 3, 'rows' => 7,
                'margin_x' => 8, 'margin_y' => 10,
                'font' => 7, 'sku_font' => 8.5, 'name_chars' => 60, 'pad' => 2.5,
            ],
            'thermal-50x30' => [
                'label' => 'Thermal 50 × 30 mm',
                'hint' => 'Printer label thermal, satu label per lembar.',
                'sheet' => false,
                'width' => 50, 'height' => 30,
                // QR di atas, SKU dan nama rata tengah di bawahnya.
                'stacked' => true,
                'font' => 6, 'sku_font' => 8, 'name_chars' => 38, 'pad' => 1.5,
            ],
            // Rol "2 line": dua label berdampingan di satu baris kertas.
            // Satu halaman PDF = satu baris (dua label), jadi printer cukup
            // diatur ke ukuran baris itu dan sensor celah memisahkan barisnya.
            'thermal-33x15-2' => [
                'label' => 'Thermal 33 × 15 mm – 2 kolom',
                'hint' => 'Rol "2 line" untuk Zebra ZD230 dan printer label sejenis. Satu baris berisi dua label.',
                'sheet' => false,
                'width' => 33, 'height' => 15,
                'cols' => 2, 'gap_x' => 2,
                // Label pendek: QR di kiri, SKU di kanannya supaya QR tetap besar.
                'stacked' => false,
                'font' => 4.5, 'sku_font' => 6, 'name_chars' => 22, 'pad' => 1,
            ],
            // Rol "3 line": tiga label sebaris. Lebar baris 103 mm, masih
            // muat di kepala cetak printer 4 inci (ZD230 ±104 mm).
            'thermal-33x15-3' => [
                'label' => 'Thermal 33 × 15 mm – 3 kolom',
                'hint' => 'Rol "3 line" untuk Zebra ZD230 dan printer label sejenis. Satu baris berisi tiga label.',
                'sheet' => false,
                'width' => 33, 'height' => 15,
                'cols' => 3, 'gap_x' => 2,
                'stacked' => false,
                'font' => 4.5, 'sku_font' => 6, 'name_chars' => 22, 'pad' => 1,
            ],
            'thermal-40x30' => [
                'label' => 'Thermal 40 × 30 mm',
                'hint' => 'Printer label thermal, satu label per lembar.',
                'sheet' => false,
                'width' => 40, 'height' => 30,
                'stacked' => true,
                'font' => 5.5, 'sku_font' => 7.5, 'name_chars' => 31, 'pad' => 1.5,
            ],
        ];
    }

    /**
     * Satu ukuran lengkap dengan hitungan turunannya: lebar/tinggi label,
     * ukuran QR, dan jumlah label per halaman.
     *
     * @return array<string, mixed>
     */
    public static function get(?string $key): array
    {
        $all = self::all();
        $key = isset($all[$key]) ? $key : self::DEFAULT;
        $layout = $all[$key] + ['key' => $key];

        if ($layout['sheet']) {
            $layout['page_width'] = 210;
            $layout['page_height'] = 297;
            $layout['width'] = floor((210 - 2 * $layout['margin_x']) / $layout['cols'] * 100) / 100;
            // Dibulatkan ke bawah supaya pembulatan tidak mendorong baris
            // terakhir ke halaman berikutnya.
            $layout['height'] = floor((297 - 2 * $layout['margin_y']) / $layout['rows'] * 100) / 100;
            $layout['gap_x'] = 0;
        } else {
            // Rol thermal: satu halaman = satu baris label. Rol berkolom
            // (mis. 2 line) punya celah kosong di antara labelnya.
            $layout['cols'] ??= 1;
            $layout['rows'] = 1;
            $layout['gap_x'] ??= 0;
            $layout['margin_x'] = 0;
            $layout['margin_y'] = 0;
            $layout['page_width'] = $layout['cols'] * $layout['width'] + ($layout['cols'] - 1) * $layout['gap_x'];
            $layout['page_height'] = $layout['height'];
        }

        $layout['stacked'] ??= false;
        $layout['per_page'] = $layout['cols'] * $layout['rows'];
        $layout['qr'] = round(min($layout['height'] - 2 * $layout['pad'], $layout['width'] * 0.45), 2);

        return $layout;
    }

    /**
     * Ubah jarak antar kolom rol berkolom (celah tiap merek rol bisa beda
     * sedikit). Lebar halaman ikut dihitung ulang.
     */
    public static function withGap(array $layout, float $gap): array
    {
        if ($layout['sheet'] || $layout['cols'] < 2) {
            return $layout;
        }

        $layout['gap_x'] = round(max(0, min($gap, 10)), 1);
        $layout['page_width'] = $layout['cols'] * $layout['width'] + ($layout['cols'] - 1) * $layout['gap_x'];

        return $layout;
    }

    /** Ukuran kertas untuk dompdf, dalam point. */
    public static function paper(array $layout): array
    {
        $pt = 72 / 25.4;

        return [0, 0, round($layout['page_width'] * $pt, 2), round($layout['page_height'] * $pt, 2)];
    }
}
