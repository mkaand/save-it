<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RedisRateLimit;
use App\Http\Middleware\RejectMalformedToken;
use App\Http\Middleware\VerifyTurnstile;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->get('/health', HealthController::class)->name('health');
            Route::middleware('api')->get('/ready', ReadinessController::class)->name('readiness');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'redis.rate' => RedisRateLimit::class,
            'token.format' => RejectMalformedToken::class,
            'admin' => EnsureAdmin::class,
            'turnstile' => VerifyTurnstile::class,
        ]);
        $middleware->trustProxies(
            at: ['127.0.0.1', '172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
