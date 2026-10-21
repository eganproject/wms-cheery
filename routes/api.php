<?php

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
