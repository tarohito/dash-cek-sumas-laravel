<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\SessionAuthService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authUser = $this->authUser($request);
        if (!$this->canAny($request, 'users.view', 'users.manage')) {
            return response()->json([$authUser]);
        }

        $rows = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->orderByDesc('createdAt')
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can($request, 'users.manage')) {
            return $this->deny($request);
        }

        $authUser = $this->authUser($request);
        $u = $request->all();
        $isActorSuperAdmin = RbacService::normalizeRole($authUser['role'] ?? null) === 'Super Admin';
        $requestedRole = RbacService::normalizeRole($u['role'] ?? 'Admin Brand');

        if (!$isActorSuperAdmin && $requestedRole === 'Super Admin') {
            return response()->json(['error' => 'Hanya Super Admin yang berwenang membuat akun dengan peran Super Admin.'], 403);
        }

        $cleanEmail = strtolower(SecurityHardeningService::sanitizeStringInput($u['email'] ?? '', 160));
        $cleanName = SecurityHardeningService::sanitizeStringInput($u['name'] ?? '', 120);
        $cleanUsername = null;
        if (array_key_exists('username', $u) && $u['username'] !== null) {
            $usernameCheck = SecurityHardeningService::validateUsernameFormat(
                SecurityHardeningService::sanitizeStringInput($u['username'], 80)
            );
            if (!$usernameCheck['valid']) {
                return response()->json(['error' => $usernameCheck['error'] ?? 'Format username tidak valid.'], 400);
            }
            $cleanUsername = $usernameCheck['normalized'];
        }
        $cleanPhone = !empty($u['phone']) ? SecurityHardeningService::sanitizeStringInput($u['phone'], 40) : null;
        $cleanId = SecurityHardeningService::sanitizeStringInput($u['id'] ?? '', 80)
            ?: ('usr-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)));

        if ($cleanName === '') {
            return response()->json(['error' => 'Nama lengkap wajib diisi.'], 400);
        }
        if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Format email tidak valid.'], 400);
        }

        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$cleanEmail])->exists()) {
            return response()->json(['error' => 'Alamat email tersebut sudah terdaftar pada akun lain.'], 409);
        }
        if ($cleanUsername !== null && DB::table('users')->whereRaw('LOWER(username) = LOWER(?)', [$cleanUsername])->exists()) {
            return response()->json(['error' => 'Username tersebut sudah digunakan akun lain.'], 409);
        }

        $avatarCheck = SecurityHardeningService::validateSafeImageSource($u['avatar'] ?? null);
        if (!$avatarCheck['valid']) {
            return response()->json(['error' => $avatarCheck['error'] ?? 'Format foto profil tidak valid.'], 400);
        }

        $hashedPassword = null;
        if (!empty($u['password']) && is_string($u['password']) && trim($u['password']) !== '') {
            if (strlen(trim($u['password'])) < 8) {
                return response()->json(['error' => 'Kata sandi minimal terdiri dari 8 karakter.'], 400);
            }
            $hashedPassword = SessionAuthService::hashPassword($u['password']);
        }

        $safeStatus = ($u['status'] ?? '') === 'Nonaktif' ? 'Nonaktif' : 'Aktif';
        $createdAt = !empty($u['createdAt'])
            ? SecurityHardeningService::sanitizeStringInput($u['createdAt'], 80)
            : gmdate('Y-m-d\TH:i:s\Z');

        DB::table('users')->insert([
            'id' => $cleanId,
            'name' => $cleanName,
            'username' => $cleanUsername,
            'email' => $cleanEmail,
            'phone' => $cleanPhone,
            'role' => $requestedRole,
            'avatar' => $avatarCheck['value'],
            'status' => $safeStatus,
            'createdAt' => $createdAt,
            'password' => $hashedPassword,
        ]);

        $created = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->where('id', $cleanId)
            ->first();

        return response()->json($created);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'users.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('users')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'User tidak ditemukan'], 404);
        }

        $authUser = $this->authUser($request);
        $isActorSuperAdmin = RbacService::normalizeRole($authUser['role'] ?? null) === 'Super Admin';
        if (!$isActorSuperAdmin && RbacService::normalizeRole($existing->role) === 'Super Admin') {
            return response()->json(['error' => 'Anda tidak memiliki izin untuk mengubah atau menghapus akun Super Admin.'], 403);
        }

        $u = $request->all();
        if (($authUser['id'] ?? '') === $decodedId && ($u['status'] ?? '') === 'Nonaktif') {
            return response()->json(['error' => 'Anda tidak dapat menonaktifkan akun Anda sendiri yang sedang aktif login.'], 400);
        }

        if (array_key_exists('email', $u) && (!is_string($u['email']) || !filter_var(trim($u['email']), FILTER_VALIDATE_EMAIL))) {
            return response()->json(['error' => 'Format email tidak valid.'], 400);
        }

        $nextEmail = array_key_exists('email', $u)
            ? strtolower(SecurityHardeningService::sanitizeStringInput($u['email'], 160))
            : $existing->email;
        $nextUsername = !empty($existing->username)
            ? SecurityHardeningService::normalizeUsernameInput($existing->username)
            : null;
        if (array_key_exists('username', $u)) {
            if ($u['username'] === null) {
                $nextUsername = null;
            } else {
                $usernameCheck = SecurityHardeningService::validateUsernameFormat(
                    SecurityHardeningService::sanitizeStringInput($u['username'], 80)
                );
                if (!$usernameCheck['valid']) {
                    return response()->json(['error' => $usernameCheck['error'] ?? 'Format username tidak valid.'], 400);
                }
                $nextUsername = $usernameCheck['normalized'];
            }
        }
        $nextRole = RbacService::normalizeRole(array_key_exists('role', $u) ? $u['role'] : $existing->role);
        $nextStatus = array_key_exists('status', $u)
            ? (($u['status'] === 'Nonaktif') ? 'Nonaktif' : 'Aktif')
            : ($existing->status ?: 'Aktif');

        if (!$isActorSuperAdmin && $nextRole === 'Super Admin') {
            return response()->json(['error' => 'Hanya Super Admin yang berwenang menetapkan peran Super Admin.'], 403);
        }

        if (RbacService::normalizeRole($existing->role) === 'Super Admin' && ($nextRole !== 'Super Admin' || $nextStatus === 'Nonaktif')) {
            $otherAdmins = DB::table('users')
                ->where('id', '!=', $decodedId)
                ->where('role', 'Super Admin')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'Nonaktif');
                })
                ->count();
            if ($otherAdmins === 0) {
                return response()->json(['error' => 'Tidak dapat menonaktifkan atau menurunkan peran Super Admin aktif terakhir di sistem.'], 400);
            }
        }

        if (strtolower((string) $nextEmail) !== strtolower((string) $existing->email)) {
            if (DB::table('users')->whereRaw('LOWER(email) = LOWER(?)', [$nextEmail])->where('id', '!=', $decodedId)->exists()) {
                return response()->json(['error' => 'Alamat email tersebut sudah digunakan akun lain.'], 409);
            }
        }
        if ($nextUsername !== null && strtolower((string) $nextUsername) !== strtolower((string) ($existing->username ?? ''))) {
            if (DB::table('users')->whereRaw('LOWER(username) = LOWER(?)', [$nextUsername])->where('id', '!=', $decodedId)->exists()) {
                return response()->json(['error' => 'Username tersebut sudah digunakan akun lain.'], 409);
            }
        }

        $nextAvatar = $existing->avatar;
        if (array_key_exists('avatar', $u)) {
            $avatarCheck = SecurityHardeningService::validateSafeImageSource($u['avatar']);
            if (!$avatarCheck['valid']) {
                return response()->json(['error' => $avatarCheck['error'] ?? 'Format foto profil tidak valid.'], 400);
            }
            $nextAvatar = $avatarCheck['value'];
        }

        $hasNewPassword = isset($u['password']) && is_string($u['password']) && trim($u['password']) !== '';
        if ($hasNewPassword && strlen(trim($u['password'])) < 8) {
            return response()->json(['error' => 'Kata sandi baru minimal terdiri dari 8 karakter.'], 400);
        }

        DB::table('users')->where('id', $decodedId)->update([
            'name' => array_key_exists('name', $u) ? SecurityHardeningService::sanitizeStringInput($u['name'], 120) : $existing->name,
            'username' => $nextUsername,
            'email' => $nextEmail,
            'phone' => array_key_exists('phone', $u) ? (!empty($u['phone']) ? SecurityHardeningService::sanitizeStringInput($u['phone'], 40) : null) : $existing->phone,
            'role' => $nextRole,
            'avatar' => $nextAvatar,
            'status' => $nextStatus,
        ]);

        if ($hasNewPassword) {
            DB::table('users')->where('id', $decodedId)->update([
                'password' => SessionAuthService::hashPassword($u['password']),
            ]);
            SessionAuthService::revokeAllUserSessions($decodedId);
        } elseif ($nextStatus === 'Nonaktif' || $nextRole !== RbacService::normalizeRole($existing->role)) {
            SessionAuthService::revokeAllUserSessions($decodedId);
        }

        $oldRole = RbacService::normalizeRole($existing->role);
        $oldStatus = $existing->status ?: 'Aktif';
        if (!empty($authUser['id']) && $authUser['id'] !== $decodedId && ($hasNewPassword || $nextRole !== $oldRole || $nextStatus !== $oldStatus)) {
            $changeParts = [];
            if ($hasNewPassword) {
                $changeParts[] = 'kata sandi diperbarui';
            }
            if ($nextRole !== $oldRole) {
                $changeParts[] = "peran diubah menjadi {$nextRole}";
            }
            if ($nextStatus !== $oldStatus) {
                $changeParts[] = "status akun menjadi {$nextStatus}";
            }

            $actorLabel = $this->actorLabel($request);
            NotificationService::createNotification([
                'type' => 'system',
                'severity' => $nextStatus === 'Nonaktif' ? 'warning' : 'info',
                'audience' => 'user',
                'targetUserId' => $decodedId,
                'title' => $hasNewPassword ? 'Kata Sandi Akun Diperbarui' : 'Pembaruan Keamanan Akun Anda',
                'subtitle' => "Diproses oleh {$actorLabel}",
                'description' => 'Perubahan akun Anda: ' . implode(', ', $changeParts) . '.',
                'entityType' => 'user',
                'entityId' => $decodedId,
                'status' => 'info',
                'metadata' => ['actor' => $actorLabel, 'userId' => $decodedId],
            ]);
        }

        $result = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->where('id', $decodedId)
            ->first();

        return response()->json($result);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'users.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('users')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'User tidak ditemukan'], 404);
        }

        $authUser = $this->authUser($request);
        $isActorSuperAdmin = RbacService::normalizeRole($authUser['role'] ?? null) === 'Super Admin';
        if (!$isActorSuperAdmin && RbacService::normalizeRole($existing->role) === 'Super Admin') {
            return response()->json(['error' => 'Anda tidak memiliki izin untuk mengubah atau menghapus akun Super Admin.'], 403);
        }
        if (($authUser['id'] ?? '') === $decodedId) {
            return response()->json(['error' => 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif login.'], 400);
        }

        if (RbacService::normalizeRole($existing->role) === 'Super Admin') {
            $otherAdmins = DB::table('users')
                ->where('id', '!=', $decodedId)
                ->where('role', 'Super Admin')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'Nonaktif');
                })
                ->count();
            if ($otherAdmins === 0) {
                return response()->json(['error' => 'Tidak dapat menghapus Super Admin aktif terakhir di sistem.'], 400);
            }
        }

        SessionAuthService::revokeAllUserSessions($decodedId);

        TrashService::ensureTableExists();
        $trashId = null;
        DB::transaction(function () use ($decodedId, $existing, $authUser, $request, &$trashId) {
            $linkedBrandIds = DB::table('brands')->where('ownerId', $decodedId)->pluck('id')->all();

            $trashId = TrashService::moveToTrash([
                'entityType' => 'user',
                'entityId' => (string) $existing->id,
                'title' => (string) ($existing->name ?: $existing->email),
                'subtitle' => trim(($existing->role ?? 'Admin Brand') . ' · ' . ($existing->email ?? '')),
                'deletedById' => $authUser['id'] ?? null,
                'deletedByName' => $this->actorLabel($request),
                'itemCount' => 1,
                'payload' => [
                    'user' => (array) $existing,
                    'linkedBrandIds' => $linkedBrandIds,
                ],
            ]);

            DB::table('users')->where('id', $decodedId)->delete();

            $fallbackUser = DB::table('users')->orderBy('id')->first(['id']);
            if ($fallbackUser) {
                DB::table('brands')->where('ownerId', $decodedId)->update(['ownerId' => $fallbackUser->id]);
            }
        });

        return response()->json(['success' => true, 'trashed' => true, 'trashId' => $trashId]);
    }
}
