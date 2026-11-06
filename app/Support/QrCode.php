<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Gambar QR code sebagai SVG.
 *
 * Kotak-kotaknya digambar sendiri sebagai <rect> polos (satu per deretan
 * modul hitam), bukan path ber-fill-rule seperti keluaran bawaan pustakanya:
 * dompdf menggambar <rect> dengan tepat, sedangkan path rumit kadang meleset.
 */
class QrCode
{
    /** Markup SVG untuk teks yang diberikan. Satu modul = satu unit viewBox. */
    public static function svg(string $text, int $quietZone = 2): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $size = $matrix->getWidth();
        $full = $size + 2 * $quietZone;
        $rects = '';

        for ($y = 0; $y < $size; $y++) {
            $x = 0;

            while ($x < $size) {
                if ($matrix->get($x, $y) !== 1) {
                    $x++;

                    continue;
                }

                $start = $x;

                while ($x < $size && $matrix->get($x, $y) === 1) {
                    $x++;
                }

                $rects .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="1"/>',
                    $start + $quietZone,
                    $y + $quietZone,
                    $x - $start,
                );
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$full.' '.$full.'" width="'.$full.'" height="'.$full.'" shape-rendering="crispEdges">'
            .'<rect width="'.$full.'" height="'.$full.'" fill="#fff"/>'
            .'<g fill="#000">'.$rects.'</g></svg>';
    }

    /**
     * Berkas gambar QR di storage untuk PDF dompdf.
     *
     * PNG (lewat GD) bila tersedia: dompdf menyematkan satu gambar sekali
     * lalu memakainya ulang, jadi satu SKU yang dicetak ratusan kali tetap
     * ringan. SVG dipakai hanya bila GD tidak terpasang — hasilnya sama
     * tajam, tetapi digambar ulang di setiap label sehingga lebih lambat.
     */
    public static function file(string $text): string
    {
        $png = function_exists('imagecreate');
        $dir = storage_path('app/qr-cache');
        $path = $dir.'/'.sha1($text).($png ? '.png' : '.svg');

        if (! is_file($path)) {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($path, $png ? self::png($text) : self::svg($text), LOCK_EX);
        }

        return $path;
    }

    /** PNG hitam-putih tanpa transparansi, $scale piksel per modul. */
    public static function png(string $text, int $scale = 10, int $quietZone = 2): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $size = $matrix->getWidth();
        $full = ($size + 2 * $quietZone) * $scale;

        $image = imagecreate($full, $full);
        imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $quietZone) * $scale;
                    $top = ($y + $quietZone) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
