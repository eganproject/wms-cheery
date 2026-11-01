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
