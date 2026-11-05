# WMS Cheery Scan (Android)

Aplikasi Android khusus **Scan Kemas** dan **Scan Serah Terima Paket**, plus
daftar pesanan **Belum Dikemas / Sudah Dikemas / Dikirim**. Memakai API
`/api/mobile/*` di aplikasi Laravel ini; aturan scan, pesan galat, dan hak
akses sama persis dengan halaman QC Kemas dan Serah Terima Paket di web.

## Mendapatkan APK

Setiap perubahan di folder `android/` yang masuk ke `main` dibangun otomatis
oleh GitHub Actions (`.github/workflows/android.yml`) dan diterbitkan di
**Releases** repo ini dengan nama `wms-cheery-scan.apk`. Buka halaman Releases
dari HP (login GitHub), unduh, lalu pasang. Versi baru cukup dipasang menimpa
yang lama.

## Pembaruan dari dalam aplikasi

Setiap kali dibuka (dan paling sering tiap 30 menit), aplikasi menanyakan
`GET /api/mobile/app-version` ke server WMS. Bila ada versi lebih baru,
muncul jendela **Versi baru tersedia** → **Perbarui** → APK diunduh → penginstal
Android terbuka → operator menekan **Perbarui**. Bisa juga dicek manual lewat
tulisan **Periksa pembaruan** di bawah Beranda.

- Pertama kali Android meminta izin **Instal aplikasi tidak dikenal** untuk
  WMS Cheery Scan (aplikasi membuka layar pengaturannya).
- Hanya berjalan bila APK lama dan baru ditandatangani **kunci yang sama**
  (lihat *Kunci tanda tangan*). Tanpa secret keystore, tiap build memakai kunci
  debug acak, sehingga update selalu ditolak Android.
- **Cara utama:** unggah APK di web admin **Pengaturan > Aplikasi Android**
  (hak akses `mobile-app.*`). Nomor versi dibaca otomatis dari APK, bisa diberi
  catatan "Yang baru" dan tanda **Wajib diperbarui**. HP mengunduh APK langsung
  dari server WMS (`/api/mobile/app-download`, alamat ini juga dipakai untuk
  pemasangan pertama dari browser HP). Batas upload PHP minimal ~60 MB
  (`public/.user.ini` / `.htaccess` sudah menaikkan ke 100 MB bila server mengizinkan).
- Bila belum ada APK yang diunggah, server memakai GitHub Releases (tag
  `android-v1.0.N`), disimpan 10 menit. Pengaturan di `.env` server:

| Variabel | Arti |
|---|---|
| `MOBILE_APP_GITHUB_REPO` | repo sumber rilis (bawaan `eganproject/wms-cheery`) |
| `MOBILE_APP_GITHUB_TOKEN` | isi bila repo private; APK lalu diunduh lewat server (`/api/mobile/app-download`) |
| `MOBILE_APP_MIN_VERSION_CODE` | HP dengan versi di bawah angka ini wajib update (jendela tidak bisa ditutup) |
| `MOBILE_APP_VERSION_CODE` + `MOBILE_APP_DOWNLOAD_URL` (+ `_VERSION_NAME`, `_NOTES`) | isi manual tanpa GitHub |

## Pemakaian

1. Isi **alamat server** = alamat yang dibuka di browser untuk WMS, mis.
   `https://wms.domain.com`. Untuk server lokal Laragon dari HP di Wi-Fi yang
   sama pakai IP komputernya, mis. `http://192.168.1.10/wms-cheery/public`
   (nama `*.test` milik Laragon tidak dikenali HP).
2. Login dengan email + sandi yang sama dengan web.
3. Scan bisa lewat scanner genggam/PDA (mode keyboard, akhiri dengan Enter),
   kamera HP (tombol **Kamera**), atau diketik.

Menu mengikuti hak akses role di web:

| Menu | Permission |
|---|---|
| Scan Kemas | `outbounds.create` + `outbounds.scan` |
| Serah Terima | `outbounds.post` (tanpa `outbounds.approve` paket hanya diajukan) |
| Daftar Pesanan | `imports.view` |

## Membangun sendiri

Buka folder `android/` di Android Studio, atau jalankan `./gradlew assembleRelease`
(butuh JDK 17 + Android SDK 35).

## Kunci tanda tangan (sekali saja)

Supaya APK versi baru bisa dipasang menimpa yang lama, semua build harus
ditandatangani kunci yang sama. Kunci itu tidak disimpan di repo; isi di
GitHub > Settings > Secrets and variables > Actions:

| Secret | Isi |
|---|---|
| `WMS_KEYSTORE_BASE64` | isi berkas keystore dalam base64 (`base64 -w0 wms-scan.keystore`) |
| `WMS_KEYSTORE_PASSWORD` | sandi keystore |
| `WMS_KEY_ALIAS` | alias kunci (bawaan `wmsscan`) |
| `WMS_KEY_PASSWORD` | sandi kunci (boleh kosong bila sama dengan sandi keystore) |

Membuat keystore: `keytool -genkeypair -keystore wms-scan.keystore -alias wmsscan -keyalg RSA -keysize 2048 -validity 10000`.
Selama secret belum diisi, APK memakai kunci debug sementara: setiap versi baru
harus di-uninstall dulu sebelum dipasang.
