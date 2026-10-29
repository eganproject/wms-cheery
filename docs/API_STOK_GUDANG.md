# API Stok Gudang

```env
STOCK_API_ENABLED=true
STOCK_API_WAREHOUSE_CODE=WMSCHEERY
STOCK_API_TOKEN=<token-rahasia>
STOCK_API_RATE_LIMIT_PER_MINUTE=60
```

API mengirim stok layak jual dari `products.stock`. `damaged_stock` tidak dimasukkan ke `qty`.

Jalankan `php artisan migrate`, buka **Pengaturan → Pengguna & Akses → Akses
API Stok** untuk menambahkan IP, jalankan `php artisan stock-api:backfill`, lalu
`php artisan optimize:clear`. Alternatif melalui terminal: `php artisan
stock-api:allow-ip 203.0.113.10 --label="Server pusat"`.

Semua endpoint membutuhkan `Authorization: Bearer <token>` dan IP sumber aktif:

- `GET /api/v1/health`
- `GET /api/v1/stocks?updated_since=&updated_until=&page=1&per_page=100`
- `GET /api/v1/stocks?as_of=YYYY-MM-DD&page=1&per_page=100`

`updated_since` dan `updated_until` wajib berformat ISO-8601 dengan offset zona
waktu. `as_of` memakai akhir tanggal dalam zona waktu Asia/Jakarta dan tidak
dapat digabung dengan kedua filter waktu tersebut. `per_page` maksimum 500.

## Mutasi stok

`GET /api/v1/stock-movements` mengembalikan data yang sama dengan halaman
**Stok → Mutasi Stok**, terbaru lebih dulu.

| Parameter | Keterangan |
|---|---|
| `from`, `to` | Tanggal `YYYY-MM-DD` (WIB). Tanpa keduanya = hari ini. |
| `range=semua` | Seluruh riwayat; tidak dapat digabung dengan `from`/`to`. |
| `sku` / `product_id` | Satu barang. |
| `type` | `in` atau `out`. |
| `bucket` | `good` (layak jual) atau `damaged` (rusak). |
| `source` | `inbound`, `outbound`, `return`, `adjustment`, `import`, `disposal`. |
| `search` | Nama, SKU, barcode barang, atau keterangan. |
| `page`, `per_page` | Bawaan 1 dan 100; `per_page` maksimum 500. |

`meta.summary` berisi `entries`, `incoming`, `outgoing`, `net`, dan `products`
untuk seluruh hasil saringan (bukan hanya halaman ini). Tiap baris `data` berisi
`id`, `created_at`, `sku`, `name`, `uom`, `type`, `bucket`, `quantity`,
`balance_after`, `source`, `source_label`, `reference_id`, `description`, `user`.

## Laporan stok

`GET /api/v1/stock-report` mengembalikan angka yang sama dengan halaman
**Stok → Laporan Stok**: saldo awal, masuk, keluar, saldo akhir, dan perputaran
tiap barang (saldo layak jual saja).

| Parameter | Keterangan |
|---|---|
| `from`, `to` | Tanggal `YYYY-MM-DD` (WIB). Bawaan 30 hari terakhir. |
| `search` | SKU, nama, barcode, atau kategori. |
| `category` | Kategori persis. |
| `view` | `semua`, `bergerak`, `fast`, `medium`, `slow`, `mati`, `menipis`. |
| `sort` | `keluar` (bawaan), `frekuensi`, `sisa`, `masuk`, `stok`, `nama`. |
| `page`, `per_page` | Bawaan 1 dan 100; `per_page` maksimum 500. |

`meta.summary` dan `meta.classes` sama dengan kartu ringkasan di halaman. Tiap
baris berisi `opening`, `incoming`, `outgoing` (semua mutasi keluar), `sold`
(keluar operasional), `closing`, `damaged`, `frequency`, `per_day`, `share`,
`movement_class`, `turnover`, `days_of_cover`, `urgency`, dan `last_out_at`.

Parameter yang tidak valid ditolak dengan HTTP 400 dan `error.code =
INVALID_PARAMETER`.
