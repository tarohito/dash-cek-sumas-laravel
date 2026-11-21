<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SessionAuthService
{
    public const MAX_FAILURES_PER_PAIR = 5;
    public const MAX_FAILURES_PER_IDENTIFIER = 10;
    public const WINDOW_SECONDS = 900;
    public const LOCKOUT_SECONDS = 900;

    public static function hashPassword(string $password): string
    {
        $trimmed = trim($password);
        if ($trimmed === '') {
            return '';
        }
        return Hash::make($trimmed);
    }

    public static function verifyPassword(string $password, ?string $storedHash): bool
    {
        if (!$storedHash || $password === '') {
            return false;
        }
        return Hash::check(trim($password), $storedHash);
    }

    public static function performDummyPasswordWork(string $password): void
    {
        // Constant-time bcrypt dummy check to mitigate user enumeration timing attacks
        Hash::check($password !== '' ? $password : 'dummy', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
    }

    private static ?string $cachedSessionSecret = null;

    public static function getOrCreateSessionSecret(): string
    {
        if (self::$cachedSessionSecret !== null) {
            return self::$cachedSessionSecret;
        }

        $envSecret = env('SESSION_SECRET');
        if (is_string($envSecret) && strlen(trim($envSecret)) >= 32) {
            return self::$cachedSessionSecret = trim($envSecret);
        }

        try {
            $cached = Cache::get('sumas_session_hmac_secret_v1');
            if (is_string($cached) && strlen($cached) >= 64) {
                return self::$cachedSessionSecret = $cached;
            }

            $existing = DB::table('server_secrets')->where('key', 'session_hmac_secret')->first();
            if ($existing && strlen((string) $existing->value) >= 64) {
                $secret = (string) $existing->value;
                Cache::put('sumas_session_hmac_secret_v1', $secret, 3600);
                return self::$cachedSessionSecret = $secret;
            }
            $generated = bin2hex(random_bytes(32));
            DB::table('server_secrets')->updateOrInsert(
                ['key' => 'session_hmac_secret'],
                ['value' => $generated, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')]
            );
            Cache::put('sumas_session_hmac_secret_v1', $generated, 3600);
            return self::$cachedSessionSecret = $generated;
        } catch (\Throwable) {
            return hash('sha256', (string) config('app.key', 'sumas-fallback-secret'));
        }
    }

    public static function hashSessionToken(string $rawToken, string $secret): string
    {
        return hash_hmac('sha256', $rawToken, $secret);
    }

    public const ROTATION_INTERVAL_MS = 24 * 3600 * 1000;

    public static function isHttpsRequest(Request $request): bool
    {
        return $request->isSecure() || strtolower((string) $request->header('x-forwarded-proto')) === 'https';
    }

    public static function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function extractUaFamily(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));
        if ($ua === '') {
            return 'unknown|unknown';
        }

        $os = 'other';
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            $os = 'ios';
        } elseif (str_contains($ua, 'android')) {
            $os = 'android';
        } elseif (str_contains($ua, 'windows')) {
            $os = 'windows';
        } elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            $os = 'macos';
        } elseif (str_contains($ua, 'linux')) {
            $os = 'linux';
        }

        $browser = 'other';
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edga/') || str_contains($ua, 'edgios/')) {
            $browser = 'edge';
        } elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
            $browser = 'opera';
        } elseif (str_contains($ua, 'firefox') || str_contains($ua, 'fxios')) {
            $browser = 'firefox';
        } elseif (str_contains($ua, 'chrome') || str_contains($ua, 'crios')) {
            $browser = 'chrome';
        } elseif (str_contains($ua, 'safari')) {
            $browser = 'safari';
        }

        return "{$os}|{$browser}";
    }

    public static function validateSameOriginAndCsrf(Request $request, bool $requireCsrfToken = true): ?string
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $expectedHost = strtolower($request->getHost());
        $origin = trim((string) $request->header('Origin', ''));
        if ($origin !== '') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($originHost === '' || $originHost !== $expectedHost) {
                return 'Permintaan lintas origin ditolak demi keamanan sesi.';
            }
        } else {
            $referer = trim((string) $request->header('Referer', ''));
            if ($referer !== '') {
                $refererHost = strtolower((string) parse_url($referer, PHP_URL_HOST));
                if ($refererHost === '' || $refererHost !== $expectedHost) {
                    return 'Sumber permintaan tidak valid.';
                }
            }
        }

        if ($requireCsrfToken) {
            $xrw = strtolower(trim((string) $request->header('X-Requested-With', '')));
            if ($xrw !== 'xmlhttprequest') {
                return 'Header keamanan permintaan tidak lengkap.';
            }

            $csrfCookie = trim((string) $request->cookie('sumas_csrf', ''));
            $csrfHeader = trim((string) $request->header('X-Sumas-CSRF', ''));
            if ($csrfCookie !== '' && ($csrfHeader === '' || !hash_equals($csrfCookie, $csrfHeader))) {
                return 'Token keamanan CSRF tidak cocok. Muat ulang halaman lalu coba kembali.';
            }
        }

        return null;
    }

    public static function extractSessionToken(Request $request): ?string
    {
        $cookieToken = $request->cookie('sumas_session');
        if (is_string($cookieToken)) {
            $cookieToken = trim($cookieToken);
            if (strlen($cookieToken) >= 32 && strlen($cookieToken) <= 256) {
                return $cookieToken;
            }
        }

        return null;
    }

    public static function createUserSession(string $userId, int $maxAgeSeconds, Request $request): string
    {
        $secret = self::getOrCreateSessionSecret();
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = self::hashSessionToken($rawToken, $secret);
        $nowMs = (int) round(microtime(true) * 1000);
        $expiresAt = $nowMs + ($maxAgeSeconds * 1000);
        $ipAddress = SecurityHardeningService::getSafeClientIp($request);
        $userAgent = SecurityHardeningService::sanitizeStringInput($request->userAgent(), 240);

        DB::table('user_sessions')->insert([
            'tokenHash' => $tokenHash,
            'userId' => $userId,
            'createdAt' => $nowMs,
            'expiresAt' => $expiresAt,
            'ipAddress' => $ipAddress,
            'userAgent' => $userAgent,
        ]);

        try {
            DB::table('user_sessions')->where('expiresAt', '<=', $nowMs)->delete();
            $activeSessions = DB::table('user_sessions')
                ->where('userId', $userId)
                ->orderByDesc('createdAt')
                ->pluck('tokenHash')
                ->all();
            if (count($activeSessions) > 10) {
                $stale = array_slice($activeSessions, 10);
                DB::table('user_sessions')->whereIn('tokenHash', $stale)->delete();
            }
        } catch (\Throwable) {
        }

        return $rawToken;
    }

    public static function revokeSessionByToken(?string $rawToken): void
    {
        if (!$rawToken) {
            return;
        }
        try {
            $secret = self::getOrCreateSessionSecret();
            $tokenHash = self::hashSessionToken($rawToken, $secret);
            DB::table('user_sessions')->where('tokenHash', $tokenHash)->delete();
        } catch (\Throwable) {
        }
    }

    public static function revokeAllUserSessions(string $userId): void
    {
        if ($userId === '') {
            return;
        }
        try {
            DB::table('user_sessions')->where('userId', $userId)->delete();
        } catch (\Throwable) {
        }
    }

    public static function resolveSessionUser(Request $request): ?array
    {
        $rawToken = self::extractSessionToken($request);
        if (!$rawToken) {
            return null;
        }

        $secret = self::getOrCreateSessionSecret();
        $tokenHash = self::hashSessionToken($rawToken, $secret);
        $session = DB::table('user_sessions')
            ->where('tokenHash', $tokenHash)
            ->first(['userId', 'createdAt', 'expiresAt', 'userAgent']);

        if (!$session) {
            return null;
        }

        $nowMs = (int) round(microtime(true) * 1000);
        if ((int) $session->expiresAt <= $nowMs) {
            try {
                DB::table('user_sessions')->where('tokenHash', $tokenHash)->delete();
            } catch (\Throwable) {
            }
            return null;
        }

        // Session User-Agent family binding: revoke immediately if cookie is replayed from a different OS/browser family
        $storedUa = trim((string) ($session->userAgent ?? ''));
        $currentUa = SecurityHardeningService::sanitizeStringInput($request->userAgent(), 240);
        if ($storedUa !== '' && $currentUa !== '') {
            $storedFamily = self::extractUaFamily($storedUa);
            $currentFamily = self::extractUaFamily($currentUa);
            if ($storedFamily !== 'unknown|unknown' && $currentFamily !== 'unknown|unknown' && $storedFamily !== $currentFamily) {
                try {
                    DB::table('user_sessions')->where('tokenHash', $tokenHash)->delete();
                } catch (\Throwable) {
                }
                return null;
            }
        }

        $user = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->where('id', $session->userId)
            ->first();

        if (!$user || ($user->status ?? 'Aktif') === 'Nonaktif') {
            self::revokeAllUserSessions((string) $session->userId);
            return null;
        }

        // Periodic 24-hour session token rotation to limit exposure of static cookies
        $createdAt = (int) ($session->createdAt ?? 0);
        $remainingMs = (int) $session->expiresAt - $nowMs;
        if ($createdAt > 0 && ($nowMs - $createdAt) > self::ROTATION_INTERVAL_MS && $remainingMs > 60000) {
            try {
                $newRawToken = bin2hex(random_bytes(32));
                $newTokenHash = self::hashSessionToken($newRawToken, $secret);
                DB::transaction(function () use ($tokenHash, $newTokenHash, $session, $nowMs, $request, $currentUa) {
                    DB::table('user_sessions')->insert([
                        'tokenHash' => $newTokenHash,
                        'userId' => (string) $session->userId,
                        'createdAt' => $nowMs,
                        'expiresAt' => (int) $session->expiresAt,
                        'ipAddress' => SecurityHardeningService::getSafeClientIp($request),
                        'userAgent' => $currentUa ?: (string) ($session->userAgent ?? ''),
                    ]);
                    DB::table('user_sessions')->where('tokenHash', $tokenHash)->delete();
                });
                $request->attributes->set('sumas_rotated_token', [
                    'token' => $newRawToken,
                    'minutes' => max(1, (int) ceil($remainingMs / 60000)),
                ]);
            } catch (\Throwable) {
            }
        }

        $arr = (array) $user;
        $arr['role'] = RbacService::normalizeRole($arr['role'] ?? null);
        return $arr;
    }

    /**
     * @return array{locked: bool, retryAfterSeconds: int}
     */
    public static function checkLoginLocked(string $identifier, string $ip): array
    {
        $now = time();
        $pairKey = 'sumas_lock_pair_' . hash('sha256', "{$identifier}|{$ip}");
        $idKey = 'sumas_lock_id_' . hash('sha256', $identifier);

        foreach ([$pairKey, $idKey] as $key) {
            $entry = Cache::get($key);
            if (is_array($entry) && !empty($entry['lockedUntil']) && $entry['lockedUntil'] > $now) {
                return [
                    'locked' => true,
                    'retryAfterSeconds' => max(1, (int) ($entry['lockedUntil'] - $now)),
                ];
            }
        }

        return ['locked' => false, 'retryAfterSeconds' => 0];
    }

    /**
     * @return array{justLocked: bool, retryAfterSeconds: int}
     */
    public static function recordLoginFailure(string $identifier, string $ip): array
    {
        $now = time();
        $pairKey = 'sumas_lock_pair_' . hash('sha256', "{$identifier}|{$ip}");
        $idKey = 'sumas_lock_id_' . hash('sha256', $identifier);

        $pairEntry = self::bumpFailureEntry($pairKey, self::MAX_FAILURES_PER_PAIR, $now);
        $idEntry = self::bumpFailureEntry($idKey, self::MAX_FAILURES_PER_IDENTIFIER, $now);

        $justLocked = !empty($pairEntry['justLocked']) || !empty($idEntry['justLocked']);
        $lockedUntil = max((int) ($pairEntry['lockedUntil'] ?? 0), (int) ($idEntry['lockedUntil'] ?? 0));

        return [
            'justLocked' => $justLocked,
            'retryAfterSeconds' => $lockedUntil > $now ? $lockedUntil - $now : 0,
        ];
    }

    public static function clearLoginSuccess(string $identifier, string $ip): void
    {
        Cache::forget('sumas_lock_pair_' . hash('sha256', "{$identifier}|{$ip}"));
        Cache::forget('sumas_lock_id_' . hash('sha256', $identifier));
    }

    private static function bumpFailureEntry(string $key, int $threshold, int $now): array
    {
        $entry = Cache::get($key);
        if (!is_array($entry) || ($now - (int) ($entry['firstAttemptAt'] ?? 0)) > self::WINDOW_SECONDS) {
            $entry = ['count' => 1, 'firstAttemptAt' => $now, 'lockedUntil' => 0, 'justLocked' => false];
        } else {
            $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
            $entry['justLocked'] = false;
        }

        if ($entry['count'] >= $threshold && (int) ($entry['lockedUntil'] ?? 0) <= $now) {
            $entry['lockedUntil'] = $now + self::LOCKOUT_SECONDS;
            $entry['justLocked'] = true;
        }

        Cache::put($key, $entry, self::WINDOW_SECONDS + self::LOCKOUT_SECONDS);
        return $entry;
    }
}
