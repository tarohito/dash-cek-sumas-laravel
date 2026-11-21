<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\SecurityPolicyService;
use App\Services\SessionAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PasswordResetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->can($request, 'users.manage')) {
            return $this->deny($request);
        }

        $rows = DB::table('password_reset_requests as pr')
            ->leftJoin('users as u', 'u.id', '=', 'pr.userId')
            ->select([
                'pr.id',
                'pr.userId',
                'u.name as userName',
                'u.email as userEmail',
                'u.role as userRole',
                'u.avatar as avatar',
                'pr.requestedAt',
                'pr.status',
                'pr.actionNote',
                'pr.createdAtTs',
            ])
            ->orderByDesc('pr.createdAtTs')
            ->orderByDesc('pr.id')
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                if ($arr['createdAtTs'] !== null) {
                    $arr['createdAtTs'] = (int) $arr['createdAtTs'];
                }
                if (!empty($arr['userRole'])) {
                    $arr['userRole'] = RbacService::normalizeRole($arr['userRole']);
                }
                return $arr;
            });

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $lookupKey = strtolower(SecurityHardeningService::sanitizeStringInput(
            $request->input('identifier', $request->input('userEmail', '')),
            160
        ));
        $usernameLookup = str_starts_with($lookupKey, '@') ? substr($lookupKey, 1) : $lookupKey;

        if ($lookupKey === '') {
            return response()->json([
                'success' => false,
                'error' => 'Masukkan alamat email atau username akun Anda.',
            ], 400);
        }

        $matchedUser = DB::table('users')
            ->select(['id', 'name', 'email', 'role', 'avatar', 'status'])
            ->whereRaw("LOWER(email) = ? OR (username IS NOT NULL AND username != '' AND LOWER(username) = ?)", [$lookupKey, $usernameLookup])
            ->first();

        $genericMsg = 'Permohonan reset kata sandi telah diterima dan akan segera diproses oleh administrator.';
        if (!$matchedUser || ($matchedUser->status ?? 'Aktif') === 'Nonaktif') {
            return response()->json(['success' => true, 'message' => $genericMsg]);
        }

        $nowTs = (int) round(microtime(true) * 1000);
        $newId = "pr-{$nowTs}-" . bin2hex(random_bytes(2));
        $requestedAt = RbacService::formatIndonesianTimestamp($nowTs);
        $reason = SecurityHardeningService::sanitizeStringInput($request->input('reason'), 300)
            ?: 'Permohonan lupa kata sandi melalui halaman login';

        DB::table('password_reset_requests')->insert([
            'id' => $newId,
            'userId' => $matchedUser->id,
            'requestedAt' => $requestedAt,
            'status' => 'Menunggu',
            'actionNote' => $reason,
            'createdAtTs' => $nowTs,
        ]);

        NotificationService::createNotification([
            'id' => "notif-reset-{$newId}",
            'type' => 'password_reset',
            'severity' => 'warning',
            'audience' => 'super_admin',
            'title' => "Permintaan Reset Password: {$matchedUser->name}",
            'subtitle' => "{$matchedUser->email} · " . RbacService::normalizeRole($matchedUser->role),
            'description' => $reason,
            'entityType' => 'password_reset',
            'entityId' => $newId,
            'status' => 'Menunggu',
            'createdAtTs' => $nowTs,
            'metadata' => [
                'user' => [
                    'id' => $matchedUser->id,
                    'name' => $matchedUser->name,
                    'email' => $matchedUser->email,
                    'role' => RbacService::normalizeRole($matchedUser->role),
                    'avatar' => $matchedUser->avatar ?: null,
                ],
            ],
        ]);

        return response()->json([
            'success' => true,
            'requestedAt' => $requestedAt,
            'message' => $genericMsg,
        ]);
    }

    public function respond(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'users.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $action = $request->input('action');
        if ($action !== 'approve' && $action !== 'reject') {
            return response()->json(['success' => false, 'error' => 'Aksi persetujuan tidak valid.'], 400);
        }

        $resetReq = DB::table('password_reset_requests as pr')
            ->leftJoin('users as u', 'u.id', '=', 'pr.userId')
            ->select(['pr.*', 'u.name as userName', 'u.email as userEmail', 'u.role as userRole', 'u.avatar as avatar'])
            ->where('pr.id', $decodedId)
            ->first();

        if (!$resetReq) {
            return response()->json(['success' => false, 'error' => 'Permintaan reset sandi tidak ditemukan.'], 404);
        }

        $targetUserRow = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->where('id', $resetReq->userId ?: '')
            ->first();
        $formattedTargetUser = $targetUserRow
            ? [...(array) $targetUserRow, 'role' => RbacService::normalizeRole($targetUserRow->role)]
            : null;

        if (!empty($resetReq->status) && $resetReq->status !== 'Menunggu') {
            return response()->json([
                'success' => false,
                'alreadyProcessed' => true,
                'status' => $resetReq->status,
                'error' => "Permintaan reset sandi ini sudah diproses sebelumnya ({$resetReq->status}).",
                'targetUser' => $formattedTargetUser,
            ], 409);
        }

        $nowStr = RbacService::formatIndonesianTimestamp();
        $newStatus = $action === 'approve' ? 'Disetujui' : 'Ditolak';
        $tempPassword = null;
        $emailSent = false;
        $note = SecurityHardeningService::sanitizeStringInput($request->input('actionNote'), 500);
        $targetName = $resetReq->userName ?: ($targetUserRow->name ?? 'Pengguna');
        $targetEmail = $resetReq->userEmail ?: ($targetUserRow->email ?? '');
        $actorLabel = $this->actorLabel($request);

        if ($action === 'approve') {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            $len = strlen($alphabet);
            $generated = '';
            for ($i = 0; $i < 12; $i++) {
                $generated .= $alphabet[random_int(0, $len - 1)];
            }
            $tempPassword = $generated;

            if ($targetUserRow) {
                DB::table('users')->where('id', $targetUserRow->id)->update([
                    'password' => SessionAuthService::hashPassword($tempPassword),
                ]);
                SessionAuthService::revokeAllUserSessions((string) $targetUserRow->id);
            }

            if ($targetEmail !== '' && str_contains($targetEmail, '@')) {
                $settings = SecurityPolicyService::loadActiveSettings();
                $emailCfg = [...$settings['notifications'], 'emailRecipients' => $targetEmail];
                $safeName = SecurityHardeningService::escapeHtml($targetName);
                $safeActor = SecurityHardeningService::escapeHtml($actorLabel);
                $safeNow = SecurityHardeningService::escapeHtml($nowStr);
                $safeTemp = SecurityHardeningService::escapeHtml($tempPassword);

                $mailRes = NotificationService::sendAlertChannel(
                    'email',
                    $emailCfg,
                    'Kata Sandi Sementara Akun Anda Telah Diterbitkan',
                    "Halo {$targetName},\n\nPermintaan reset kata sandi Anda telah disetujui oleh {$actorLabel} pada {$nowStr}.\nKata sandi sementara baru Anda: {$tempPassword}\n\nSegera ubah kata sandi Anda setelah masuk.",
                    "Halo <b>{$safeName}</b>,\nPermintaan reset kata sandi Anda telah disetujui oleh <b>{$safeActor}</b> pada {$safeNow}.\nKata sandi sementara baru Anda: <code>{$safeTemp}</code>\nSegera ubah kata sandi setelah masuk."
                );
                $emailSent = $mailRes['success'];
            }

            if ($note === '') {
                $note = $emailSent
                    ? "Kata sandi akun telah diperbarui dan dikirim ke email {$targetEmail} oleh {$actorLabel} ({$nowStr})."
                    : "Kata sandi sementara baru telah diterapkan ke database oleh {$actorLabel} ({$nowStr}). Bagikan sandi sementara kepada pengguna karena layanan email belum aktif.";
            }
        } elseif ($note === '') {
            $note = "Permintaan reset ditolak oleh {$actorLabel} ({$nowStr}).";
        }

        DB::table('password_reset_requests')->where('id', $decodedId)->update([
            'status' => $newStatus,
            'actionNote' => $note,
        ]);

        NotificationService::updateNotificationByEntity('password_reset', $decodedId, [
            'status' => $newStatus,
            'severity' => $action === 'approve' ? 'info' : 'warning',
            'description' => $note,
            'metadata' => [
                'emailSent' => $emailSent,
                'tempPassword' => $emailSent ? null : $tempPassword,
                'actionBy' => $actorLabel,
            ],
        ]);

        if ($targetUserRow && !empty($targetUserRow->id)) {
            NotificationService::createNotification([
                'type' => 'system',
                'severity' => $action === 'approve' ? 'info' : 'warning',
                'audience' => 'user',
                'targetUserId' => $targetUserRow->id,
                'title' => $action === 'approve' ? 'Permohonan Reset Kata Sandi Disetujui' : 'Permohonan Reset Kata Sandi Ditolak',
                'subtitle' => "Diproses oleh {$actorLabel}",
                'description' => $action === 'approve'
                    ? "Kata sandi sementara baru untuk akun Anda telah diterbitkan pada {$nowStr}."
                    : $note,
                'entityType' => 'user',
                'entityId' => $targetUserRow->id,
                'status' => $newStatus,
                'metadata' => ['actor' => $actorLabel, 'userId' => $targetUserRow->id, 'requestId' => $decodedId],
            ]);
        }

        $authUser = $this->authUser($request);
        if (!empty($authUser['id'])) {
            DB::table('read_notifications')->insertOrIgnore([
                'userId' => $authUser['id'],
                'notificationId' => "notif-reset-{$decodedId}",
                'readAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        return response()->json([
            'success' => true,
            'id' => $decodedId,
            'status' => $newStatus,
            'actionNote' => $note,
            'tempPassword' => $emailSent ? null : $tempPassword,
            'emailSent' => $emailSent,
            'targetUser' => $formattedTargetUser,
        ]);
    }
}
