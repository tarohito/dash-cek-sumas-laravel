<?php

use App\Http\Middleware\SanitizeApiInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SumasSessionAuth;
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
        $middleware->trustProxies(at: '*');

        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: [
            'sumas_session',
            'sumas_logged_out',
            'sumas_csrf',
        ]);

        $middleware->alias([
            'sumas.auth' => SumasSessionAuth::class,
            'sumas.sanitize' => SanitizeApiInput::class,
        ]);

        $middleware->api(prepend: [
            SanitizeApiInput::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
