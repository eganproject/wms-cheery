<?php

namespace App\Services;

use App\Models\ShipmentImport;
use Illuminate\Http\UploadedFile;

/**
 * Pintu masuk Import Resi: mengenali asal berkas (TikTok Shop atau Shopee)
 * dari judul kolomnya, lalu menyerahkannya ke pembaca yang sesuai.
 *
 * TikTok diperiksa lebih dulu dan menjadi bawaan: berkas yang tidak dikenali
 * tetap dibaca pembaca TikTok, sehingga perilaku dan pesan kesalahannya sama
 * persis seperti sebelum Shopee ditambahkan.
 */
class MarketplaceImportService
{
    public function __construct(
        protected TikTokImportService $tiktok,
        protected ShopeeImportService $shopee,
    ) {
    }

    public function import(UploadedFile $file): ShipmentImport
    {
        return $this->detect($file)->import($file);
    }

    public function detect(UploadedFile $file): TikTokImportService
    {
        $top = $this->tiktok->peek($file);

        if ($this->tiktok->recognizes($top)) {
            return $this->tiktok;
        }

        return $this->shopee->recognizes($top) ? $this->shopee : $this->tiktok;
    }
}
