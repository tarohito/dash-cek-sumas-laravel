<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\SessionAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = SessionAuthService::resolveSessionUser($request);
        $matrix = RbacService::getRbacMatrix();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
                'user' => null,
                'permissions' => [],
                'matrix' => $matrix,
            ]);
        }

        return response()->json([
            'authenticated' => true,
            'user' => $user,
            'permissions' => RbacService::getPermissionsForRole($user['role'] ?? null, $matrix),
            'matrix' => $matrix,
        ]);
    }

    public function demoAccounts(): JsonResponse
    {
        try {
            $users = DB::table('users')
                ->select(['id', 'name', 'email', 'role'])
                ->where('status', 'Aktif')
                ->orderByRaw("CASE WHEN role = 'Super Admin' THEN 1 ELSE 2 END, id ASC")
                ->limit(3)
                ->get();

            $brands = DB::table('brands')->select(['ownerId', 'name'])->get();
            $brandsByOwner = [];
            foreach ($brands as $b) {
                $ownerId = (string) ($b->ownerId ?? '');
                if ($ownerId !== '') {
                    $brandsByOwner[$ownerId][] = $b->name;
                }
            }

            $result = [];
            foreach ($users as $u) {
                $managed = isset($brandsByOwner[$u->id]) ? implode(', ', $brandsByOwner[$u->id]) : '';
                $result[] = [
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role === 'Super Admin'
                        ? 'Super Admin'
                        : 'Admin Brand' . ($managed !== '' ? " · ({$managed})" : ''),
                ];
            }

            return response()->json($result);
        } catch (\Throwable) {
            return response()->json([]);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $identifier = strtolower(SecurityHardeningService::sanitizeStringInput(
            $request->input('identifier', $request->input('email', '')),
            160
        ));
        $usernameLookup = str_starts_with($identifier, '@') ? substr($identifier, 1) : $identifier;
        $password = is_string($request->input('password'))
            ? substr((string) $request->input('password'), 0, 200)
            : '';
        $rememberMe = $request->input('rememberMe') !== false;
        $clientIp = SecurityHardeningService::getSafeClientIp($request);

        if ($identifier === '' || $password === '') {
            return response()->json([
                'success' => false,
                'error' => 'Email/username dan kata sandi wajib diisi.',
            ], 400);
        }

        $lockState = SessionAuthService::checkLoginLocked($identifier, $clientIp);
        if ($lockState['locked']) {
            $waitMin = max(1, (int) ceil($lockState['retryAfterSeconds'] / 60));
            return response()->json([
                'success' => false,
                'error' => "Terlalu banyak percobaan login gagal. Akun sementara dikunci selama {$waitMin} menit untuk keamanan.",
            ], 429)->header('Retry-After', (string) $lockState['retryAfterSeconds']);
        }

        $userRow = DB::table('users')
            ->whereRaw("LOWER(email) = ? OR (username IS NOT NULL AND username != '' AND LOWER(username) = ?)", [$identifier, $usernameLookup])
            ->first();

        if (!$userRow || empty(trim((string) ($userRow->password ?? '')))) {
            SessionAuthService::performDummyPasswordWork($password);
            $failure = SessionAuthService::recordLoginFailure($identifier, $clientIp);
            if ($failure['justLocked']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak percobaan login gagal. Coba kembali dalam 15 menit.',
                ], 429);
            }
            return response()->json([
                'success' => false,
                'error' => 'Email/username atau kata sandi salah.',
            ], 401);
        }

        if (!SessionAuthService::verifyPassword($password, (string) $userRow->password)) {
            $failure = SessionAuthService::recordLoginFailure($identifier, $clientIp);
            if ($failure['justLocked']) {
                try {
                    DB::table('audit_logs')->insert([
                        'id' => (int) round(microtime(true) * 1000) + random_int(0, 999),
                        'batchId' => 'AUTH-SECURITY',
                        'timestamp' => RbacService::formatIndonesianTimestamp(),
                        'user' => 'Security Shield',
                        'reason' => "Proteksi Brute-Force: Penguncian sementara percobaan login ({$identifier})",
                        'changes' => json_encode([
                            ['field' => 'IP Sumber', 'old' => '-', 'new' => $clientIp],
                            ['field' => 'Tindakan', 'old' => '-', 'new' => 'Dikunci 15 menit setelah 5 kegagalan beruntun'],
                        ]),
                    ]);
                } catch (\Throwable) {
                }
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak percobaan login gagal. Akses login sementara dikunci selama 15 menit.',
                ], 429);
            }
            return response()->json([
                'success' => false,
                'error' => 'Email/username atau kata sandi salah.',
            ], 401);
        }

        if (($userRow->status ?? 'Aktif') === 'Nonaktif') {
            return response()->json([
                'success' => false,
                'error' => 'Akun ini sedang dinonaktifkan. Hubungi Super Admin untuk mengaktifkan kembali.',
            ], 403);
        }

        SessionAuthService::clearLoginSuccess($identifier, $clientIp);

        $maxAgeSeconds = $rememberMe ? (30 * 24 * 3600) : (12 * 3600);
        $token = SessionAuthService::createUserSession((string) $userRow->id, $maxAgeSeconds, $request);

        $safeUser = (array) $userRow;
        unset($safeUser['password']);
        $safeUser['role'] = RbacService::normalizeRole($safeUser['role'] ?? null);

        $matrix = RbacService::getRbacMatrix();
        $permissions = RbacService::getPermissionsForRole($safeUser['role'], $matrix);

        $isHttps = $request->isSecure() || $request->header('x-forwarded-proto') === 'https';
        $sameSite = $isHttps ? 'none' : 'lax';
        $minutes = (int) ceil($maxAgeSeconds / 60);

        return response()->json([
            'success' => true,
            'user' => $safeUser,
            'permissions' => $permissions,
            'matrix' => $matrix,
            'sessionToken' => $token,
        ])
            ->cookie('sumas_session', $token, $minutes, '/', null, $isHttps, true, false, $sameSite)
            ->cookie('sumas_logged_out', '', -1, '/', null, $isHttps, false, false, $sameSite);
    }

    public function logout(Request $request): JsonResponse
    {
        $cookieToken = $request->cookie('sumas_session');
        if (is_string($cookieToken) && $cookieToken !== '') {
            SessionAuthService::revokeSessionByToken($cookieToken);
        }
        $extracted = SessionAuthService::extractSessionToken($request);
        if ($extracted && $extracted !== $cookieToken) {
            SessionAuthService::revokeSessionByToken($extracted);
        }

        $isHttps = $request->isSecure() || $request->header('x-forwarded-proto') === 'https';
        $sameSite = $isHttps ? 'none' : 'lax';

        return response()->json(['success' => true])
            ->cookie('sumas_session', '', -1, '/', null, $isHttps, true, false, $sameSite)
            ->cookie('sumas_logged_out', '1', 7 * 24 * 60, '/', null, $isHttps, false, false, $sameSite);
    }

    public function checkUsername(Request $request): JsonResponse
    {
        $authUser = $this->authUser($request);
        $rawUsername = (string) $request->query('username', '');
        $format = SecurityHardeningService::validateUsernameFormat($rawUsername);

        if (!$format['valid']) {
            return response()->json([
                'valid' => false,
                'available' => false,
                'normalized' => $format['normalized'],
                'reason' => $format['error'] ?? 'Format username tidak valid.',
                'checks' => $format['checks'],
            ]);
        }

        $requestedExcludeId = SecurityHardeningService::sanitizeStringInput($request->query('excludeUserId', ''), 80);
        $isSuperAdmin = RbacService::normalizeRole($authUser['role'] ?? null) === 'Super Admin';
        $effectiveExcludeId = ($isSuperAdmin || $this->can($request, 'users.manage'))
            ? $requestedExcludeId
            : (string) ($authUser['id'] ?? '');

        $query = DB::table('users')->whereRaw('LOWER(username) = LOWER(?)', [$format['normalized']]);
        if ($effectiveExcludeId !== '') {
            $query->where('id', '!=', $effectiveExcludeId);
        }
        $dup = $query->first(['id']);

        return response()->json([
            'valid' => true,
            'available' => !$dup,
            'normalized' => $format['normalized'],
            'reason' => $dup ? 'Username tersebut sudah digunakan oleh akun lain.' : null,
            'checks' => $format['checks'],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $authUser = $this->authUser($request);
        $existingRow = DB::table('users')->where('id', $authUser['id'])->first();
        if (!$existingRow) {
            return response()->json(['success' => false, 'error' => 'Akun pengguna tidak ditemukan.'], 404);
        }

        $cleanName = $request->has('name')
            ? SecurityHardeningService::sanitizeStringInput($request->input('name'), 120)
            : (string) $existingRow->name;
        if ($cleanName === '') {
            return response()->json(['success' => false, 'error' => 'Nama lengkap wajib diisi.'], 400);
        }

        $cleanUsername = !empty($existingRow->username)
            ? SecurityHardeningService::normalizeUsernameInput($existingRow->username)
            : null;
        if ($request->has('username')) {
            $usernameCheck = SecurityHardeningService::validateUsernameFormat(
                SecurityHardeningService::sanitizeStringInput($request->input('username'), 80)
            );
            if (!$usernameCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => $usernameCheck['error'] ?? 'Format username tidak valid.',
                ], 400);
            }
            $cleanUsername = $usernameCheck['normalized'];
        }

        $cleanEmail = $request->has('email')
            ? strtolower(SecurityHardeningService::sanitizeStringInput($request->input('email'), 160))
            : (string) $existingRow->email;
        if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'Format alamat email tidak valid.'], 400);
        }

        $cleanPhone = $request->has('phone')
            ? SecurityHardeningService::sanitizeStringInput($request->input('phone'), 40)
            : ($existingRow->phone ?? null);
        $rawNewPassword = is_string($request->input('newPassword')) ? (string) $request->input('newPassword') : '';
        $rawCurrentPassword = is_string($request->input('currentPassword')) ? (string) $request->input('currentPassword') : '';

        $dupEmail = DB::table('users')
            ->whereRaw('LOWER(email) = LOWER(?)', [$cleanEmail])
            ->where('id', '!=', $authUser['id'])
            ->first(['id']);
        if ($dupEmail) {
            return response()->json(['success' => false, 'error' => 'Alamat email sudah digunakan oleh akun lain.'], 409);
        }

        if ($cleanUsername !== null) {
            $dup = DB::table('users')
                ->whereRaw('LOWER(username) = LOWER(?)', [$cleanUsername])
                ->where('id', '!=', $authUser['id'])
                ->first(['id']);
            if ($dup) {
                return response()->json(['success' => false, 'error' => 'Username tersebut sudah digunakan akun lain.'], 409);
            }
        }

        $nextAvatar = $existingRow->avatar;
        if ($request->exists('avatar')) {
            $avatarCheck = SecurityHardeningService::validateSafeImageSource($request->input('avatar'));
            if (!$avatarCheck['valid']) {
                return response()->json(['success' => false, 'error' => $avatarCheck['error'] ?? 'Format foto profil tidak valid.'], 400);
            }
            $nextAvatar = $avatarCheck['value'];
        }

        $nextPasswordHash = $existingRow->password;
        $passwordChanged = trim($rawNewPassword) !== '';
        if ($passwordChanged) {
            if (strlen(trim($rawNewPassword)) < 6) {
                return response()->json(['success' => false, 'error' => 'Kata sandi baru minimal harus 6 karakter.'], 400);
            }
            if ($rawCurrentPassword === '') {
                return response()->json(['success' => false, 'error' => 'Masukkan kata sandi saat ini untuk mengubah kata sandi.'], 400);
            }
            if (!SessionAuthService::verifyPassword($rawCurrentPassword, (string) $existingRow->password)) {
                return response()->json(['success' => false, 'error' => 'Kata sandi saat ini tidak sesuai.'], 400);
            }
            $nextPasswordHash = SessionAuthService::hashPassword(trim($rawNewPassword));
        }

        DB::table('users')
            ->where('id', $authUser['id'])
            ->update([
                'name' => $cleanName,
                'username' => $cleanUsername,
                'email' => $cleanEmail,
                'phone' => $cleanPhone ?: null,
                'avatar' => $nextAvatar,
                'password' => $nextPasswordHash,
            ]);

        if ($passwordChanged) {
            $currentRawToken = SessionAuthService::extractSessionToken($request);
            if ($currentRawToken) {
                $secret = SessionAuthService::getOrCreateSessionSecret();
                $currentTokenHash = SessionAuthService::hashSessionToken($currentRawToken, $secret);
                DB::table('user_sessions')
                    ->where('userId', $authUser['id'])
                    ->where('tokenHash', '!=', $currentTokenHash)
                    ->delete();
            } else {
                SessionAuthService::revokeAllUserSessions((string) $authUser['id']);
            }
        }

        $updated = (array) DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->where('id', $authUser['id'])
            ->first();
        $updated['role'] = RbacService::normalizeRole($updated['role'] ?? null);

        return response()->json([
            'success' => true,
            'user' => $updated,
            'passwordUpdated' => $passwordChanged,
        ]);
    }
}
