<?php

use App\Http\Middleware\StockApiAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Mesin fingerprint tidak membawa token CSRF.
        $middleware->validateCsrfTokens(except: ['iclock/*']);

        $middleware->alias([
            'stock.api.access' => StockApiAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua alamat /api/* (termasuk aplikasi Android) selalu dijawab
        // JSON, walau peminta lupa mengirim header Accept: galat validasi
        // dan "belum login" tidak boleh berubah menjadi redirect ke halaman.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
