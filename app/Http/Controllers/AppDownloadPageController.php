<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Mobile\AppVersionController;
use Illuminate\View\View;

/**
 * Halaman unduh aplikasi Android (tanpa login): staf membukanya dari browser
 * HP baru, menekan Unduh, lalu memasang APK-nya. Pembaruan berikutnya
 * berjalan dari dalam aplikasi.
 */
class AppDownloadPageController extends Controller
{
    public function __invoke(AppVersionController $versions): View
    {
        $latest = $versions->latest();

        $downloadUrl = null;
        if ($latest) {
            $url = $latest['download_url'];
            $downloadUrl = str_starts_with($url, '/') ? url($url) : $url;
        }

        return view('app-download', [
            'latest' => $latest,
            'downloadUrl' => $downloadUrl,
        ]);
    }
}
