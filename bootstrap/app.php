<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // Pastikan request API yang tidak terautentikasi mendapat
        // respon JSON 401, bukan redirect ke halaman login web.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Kembalikan JSON 401 untuk request API yang tidak terautentikasi
        // (bukan redirect ke route 'login' yang tidak ada)
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Silakan login terlebih dahulu.',
                ], 401);
            }
        });
    })->create();
