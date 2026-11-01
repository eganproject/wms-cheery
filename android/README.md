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
(butuh JDK 17 + Android SDK 35). APK ditandatangani dengan `app/wms-scan.keystore`
yang ikut di repo supaya setiap build bisa menimpa versi sebelumnya.
