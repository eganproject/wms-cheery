<?php

namespace App\Services;

use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;

/**
 * Membaca berkas eksport pesanan Shopee Seller Center (Pesanan Saya >
 * Ekspor) menjadi data resi.
 *
 * Seluruh aturan import sama dengan TikTok (resi kembar dicegat, resi yang
 * sudah ada dilewati, baris gagal dicatat); yang berbeda hanya judul kolom,
 * cara mengenali SKU, dan batas kirim. Shopee menuliskan batas kirimnya
 * sendiri ("Pesanan Harus Dikirimkan Sebelum"), jadi batas itu langsung
 * dipakai dan dikunci, tidak dihitung ulang dari lama PO.
 *
 * Seperti TikTok, data pribadi pembeli (username, nama penerima, nomor
 * telepon, alamat) sengaja tidak dibaca.
 */
class ShopeeImportService extends TikTokImportService
{
    public const MARKETPLACE = 'Shopee';

    public const SOURCE = 'shopee';

    /**
     * Judul kolom Shopee (akun berbahasa Indonesia dan Inggris), dalam huruf
     * kecil tanpa spasi dan tanda baca.
     *
     * @var array<string, array<int, string>>
     */
    protected array $columns = [
        'tracking_number' => ['noresi', 'nomorresi', 'trackingnumber', 'trackingno'],
        'order_number' => ['nopesanan', 'nomorpesanan', 'orderid', 'ordersn'],
        // SKU variasi; bila produk tanpa variasi, kolom ini kosong dan SKU
        // Induk yang dipakai.
        'sku' => ['nomorreferensisku', 'skureferenceno', 'skureferencenumber'],
        'parent_sku' => ['skuinduk', 'parentskureferenceno', 'parentsku'],
        'variation' => ['namavariasi', 'variationname'],
        'product_name' => ['namaproduk', 'productname'],
        'quantity' => ['jumlah', 'quantity'],
        // Harga setelah diskon x jumlah pada baris itu: dasar omzet.
        'amount' => ['totalhargaproduk', 'productsubtotal'],
        'order_status' => ['statuspesanan', 'orderstatus'],
        'courier' => ['opsipengiriman', 'shippingoption'],
        'order_date' => ['waktupesanandibuat', 'ordercreationdate'],
        'paid_at' => ['waktupembayarandilakukan', 'orderpaidtime'],
        'ship_by' => [
            'pesananharusdikirimkansebelummenghindariketerlambatan',
            'pesananharusdikirimkansebelum',
            'shipbydate',
        ],
        'buyer_note' => ['catatandaripembeli', 'remarkfrombuyer'],
    ];

    /**
     * Shopee tidak punya baris keterangan kolom seperti TikTok.
     */
    protected function isDescriptionRow(array $row, array $header): bool
    {
        return false;
    }

    protected function hasSkuColumn(array $header): bool
    {
        return isset($header['sku']) || isset($header['parent_sku']);
    }

    protected function missingSkuMessage(): string
    {
        return 'Kolom Nomor Referensi SKU tidak ditemukan. Pastikan berkas adalah eksport pesanan Shopee.';
    }

    protected function skuFor(array $row, array $header): ?string
    {
        return $this->value($row, $header, 'sku') ?? $this->value($row, $header, 'parent_sku');
    }

    protected function noTrackingDetail(): string
    {
        return 'No. Resi Shopee baru terbit setelah pesanan diatur pengirimannya (Atur Pengiriman). Ekspor ulang setelah itu.';
    }

    protected function noSkuDetail(): string
    {
        return 'Nomor Referensi SKU dan SKU Induk sama-sama kosong, jadi barangnya tidak bisa dikenali. Isi SKU produk ini di Shopee.';
    }

    protected function orderAttributes(array $row, array $header): array
    {
        // Shopee hanya punya satu kolom pengiriman ("SPX Hemat", "Instant",
        // ...): dipakai sebagai kurir sekaligus layanan, supaya layanan
        // instan / same day tetap ditandai prioritas.
        $option = $this->value($row, $header, 'courier');

        return [
            'marketplace' => static::MARKETPLACE,
            'order_status' => $this->value($row, $header, 'order_status'),
            'courier' => $option,
            'shipping_method' => $option,
            'buyer_note' => $this->value($row, $header, 'buyer_note'),
            'order_date' => $this->parseDate($this->value($row, $header, 'order_date')),
            'paid_at' => $this->parseDate($this->value($row, $header, 'paid_at')),
            'is_preorder' => null,
            'ship_by' => $this->parseDate($this->value($row, $header, 'ship_by')),
        ];
    }

    /**
     * Batas kirim dari Shopee disimpan sebagai batas terkunci, sehingga
     * hitungan sistem (waktu bayar + lama PO / aturan Normal) tidak
     * menimpanya.
     */
    protected function createOrder(ShipmentImport $import, array $data): ShipmentOrder
    {
        $shipBy = $data['ship_by'] ?? null;
        unset($data['ship_by']);

        $order = new ShipmentOrder($data + ['shipment_import_id' => $import->id]);

        if (ShipmentOrder::looksCancelled($data['order_status'] ?? null)) {
            $order->cancelled_at = now();
        }

        if ($shipBy !== null) {
            $order->forceFill([
                'ship_by' => $shipBy,
                'ship_by_source' => OrderPlanner::SOURCE_SHOPEE,
            ]);
        }

        $order->save();

        return $order;
    }
}
