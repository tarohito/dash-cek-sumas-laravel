<?php

namespace App\Providers;

use App\Database\SqliteBridgePdo;
use App\Services\SecurityHardeningService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        if (class_exists(SqliteBridgePdo::class)) {
            Connection::resolverFor('sqlite_bridge', function ($connection, $database, $prefix, $config) {
                $socketPath = $config['socket'] ?? storage_path('sqlite_bridge.sock');
                $pdo = new SqliteBridgePdo($socketPath);
                return new SQLiteConnection($pdo, $database, $prefix, $config);
            });
        }
        RateLimiter::for('api-global', function (Request $request) {
            $ip = SecurityHardeningService::getSafeClientIp($request);
            return Limit::perMinute(300)->by("api-global:{$ip}")->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak permintaan ke server. Mohon tunggu 1 menit lalu coba kembali.',
                ], 429);
            });
        });

        RateLimiter::for('auth-login', function (Request $request) {
            $ip = SecurityHardeningService::getSafeClientIp($request);
            return Limit::perMinutes(15, 20)->by("auth-login:{$ip}")->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak percobaan login dari jaringan Anda. Coba lagi dalam beberapa menit.',
                ], 429);
            });
        });

        RateLimiter::for('auth-reset', function (Request $request) {
            $ip = SecurityHardeningService::getSafeClientIp($request);
            return Limit::perMinutes(15, 6)->by("auth-reset:{$ip}")->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'Batas pengajuan lupa kata sandi tercapai. Silakan coba lagi setelah 15 menit.',
                ], 429);
            });
        });

        RateLimiter::for('public-scan', function (Request $request) {
            $ip = SecurityHardeningService::getSafeClientIp($request);
            return Limit::perMinute(80)->by("public-scan:{$ip}")->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'Batas pemindaian sementara tercapai. Mohon tunggu sebentar.',
                ], 429);
            });
        });

        RateLimiter::for('heavy-ops', function (Request $request) {
            $ip = SecurityHardeningService::getSafeClientIp($request);
            return Limit::perMinutes(5, 25)->by("heavy-ops:{$ip}")->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak operasi berat dalam waktu singkat. Coba lagi beberapa menit lagi.',
                ], 429);
            });
        });
    }
}
