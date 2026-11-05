<?php

use App\Http\Controllers\Admin\OutboundHandoverController;
use App\Http\Controllers\Admin\OutboundMarketplaceController;
use App\Http\Controllers\Admin\OutboundScanController;
use App\Http\Controllers\Api\Mobile\AppVersionController;
use App\Http\Controllers\Api\Mobile\AttendanceController as MobileAttendanceController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\OrderController as MobileOrderController;
use App\Http\Controllers\Api\Mobile\PackingController as MobilePackingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['stock.api.access', 'throttle:stock-api'])
    ->group(function (): void {
        Route::get('/health', HealthController::class);
        Route::get('/stocks', [StockController::class, 'index']);
        Route::get('/stock-movements', [StockMovementController::class, 'index']);
        Route::get('/stock-report', [StockReportController::class, 'index']);
    });

/*
 * Aplikasi Android Scan Kemas & Serah Terima (folder android/).
 *
 * Scan resi, scan barang, dan scan serah terima memakai controller web yang
 * sama persis — aturan, pesan galat, dan hak aksesnya ikut sama. Yang berbeda
 * hanya cara login: token Bearer, bukan sesi browser.
 */
Route::prefix('mobile')
    ->name('mobile.')
    ->group(function (): void {
        Route::post('/login', [MobileAuthController::class, 'login'])->name('login');

        // Pembaruan aplikasi dari dalam aplikasi (tanpa login).
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('/app-version', [AppVersionController::class, 'show'])->name('app-version');
            Route::get('/app-download', [AppVersionController::class, 'download'])->name('app-download');
        });

        Route::middleware(['auth:mobile', 'throttle:240,1'])->group(function (): void {
            Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
            Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');

            // Scan Kemas (QC Kemas di web).
            Route::post('/kemas/resi', [OutboundMarketplaceController::class, 'store'])->name('kemas.resi');
            Route::post('/kemas/{outbound}/item', [OutboundScanController::class, 'item'])->name('kemas.item');
            Route::post('/kemas/{outbound}/reset', [MobilePackingController::class, 'reset'])->name('kemas.reset');

            // Serah Terima Paket.
            Route::post('/serah-terima/scan', [OutboundHandoverController::class, 'scan'])->name('handover.scan');

            // Daftar pesanan: belum dikemas, sudah dikemas, dikirim.
            Route::get('/summary', [MobileOrderController::class, 'summary'])->name('summary');
            Route::get('/orders', [MobileOrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [MobileOrderController::class, 'show'])->name('orders.show');

            // Absen lewat HP (selfie + lokasi).
            Route::get('/absen', [MobileAttendanceController::class, 'today'])->name('absen.today');
            Route::post('/absen', [MobileAttendanceController::class, 'store'])->name('absen.store');
            Route::get('/absen/riwayat', [MobileAttendanceController::class, 'history'])->name('absen.history');
        });
    });
