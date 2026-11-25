<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $rows = DB::table('audit_logs')->orderByDesc('id')->limit(200)->get();

        $allowedBatches = [];
        if (empty($scope['isGlobal'])) {
            $owned = DB::table('batches')->select(['batchId', 'brand'])->get();
            foreach ($owned as $b) {
                if (RbacService::canScopeAccessBrand($scope, $b->brand ?? null)) {
                    $allowedBatches[$b->batchId] = true;
                }
            }
        }

        $result = [];
        foreach ($rows as $rowObj) {
            $r = (array) $rowObj;
            if (empty($scope['isGlobal']) && !isset($allowedBatches[$r['batchId'] ?? ''])) {
                continue;
            }
            $decoded = json_decode((string) ($r['changes'] ?? '[]'), true);
            $r['changes'] = is_array($decoded) ? $decoded : [];
            $result[] = $r;
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'batches.create', 'batches.allocate', 'batches.suspend', 'batches.redirect_url', 'users.manage')) {
            return $this->deny($request);
        }

        $log = $request->all();
        $batchIdStr = SecurityHardeningService::sanitizeStringInput($log['batchId'] ?? '', 120);
        if ($batchIdStr === '') {
            return response()->json(['error' => 'batchId audit log wajib diisi.'], 400);
        }

        $scope = $this->brandScope($request);
        $relatedBatch = DB::table('batches')
            ->where('batchId', $batchIdStr)
            ->orWhere('id', $batchIdStr)
            ->first(['brand']);

        if (empty($scope['isGlobal'])) {
            if (!$relatedBatch) {
                return response()->json([
                    'error' => 'Akses ditolak. Audit log hanya dapat dicatat untuk batch milik brand yang Anda kelola.',
                ], 403);
            }
            if ($denied = RbacService::assertBrandAccess($scope, $relatedBatch->brand)) {
                return $denied;
            }
        }

        $authUser = $this->authUser($request);
        $actorLabel = $this->actorLabel($request);
        $nowMs = (int) round(microtime(true) * 1000);
        // Always anchor audit_logs.id to server clock so client/server clock skew never bypasses deduplication
        $safeId = $nowMs;
        $safeTimestamp = SecurityHardeningService::sanitizeStringInput($log['timestamp'] ?? '', 80) ?: RbacService::formatIndonesianTimestamp();
        $safeReason = SecurityHardeningService::sanitizeStringInput($log['reason'] ?? '', 400) ?: 'Pembaruan data';
        $safeChanges = is_array($log['changes'] ?? null) ? array_slice($log['changes'], 0, 50) : [];
        $encodedChanges = json_encode($safeChanges) ?: '[]';

        $dedupeWindowMs = 15000;
        $fingerprint = sha1($batchIdStr . '|' . $authUser['name'] . '|' . $safeReason . '|' . $encodedChanges);

        $writeFn = function () use (
            $batchIdStr, $authUser, $safeReason, $encodedChanges, $safeChanges,
            $safeId, $safeTimestamp, $nowMs, $dedupeWindowMs
        ): array {
            $existing = DB::table('audit_logs')
                ->where('batchId', $batchIdStr)
                ->where('user', $authUser['name'])
                ->where('reason', $safeReason)
                ->where('changes', $encodedChanges)
                ->where('id', '>=', $nowMs - $dedupeWindowMs)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $decoded = json_decode((string) ($existing->changes ?? '[]'), true);
                return [
                    'deduplicated' => true,
                    'record' => [
                        'id' => (int) $existing->id,
                        'batchId' => $existing->batchId,
                        'timestamp' => $existing->timestamp,
                        'user' => $existing->user,
                        'reason' => $existing->reason,
                        'changes' => is_array($decoded) ? $decoded : [],
                        'deduplicated' => true,
                    ],
                ];
            }

            // Ensure unique primary key if another insert occurred on the exact same millisecond
            $insertId = $safeId;
            while (DB::table('audit_logs')->where('id', $insertId)->exists()) {
                $insertId++;
            }

            DB::table('audit_logs')->insert([
                'id' => $insertId,
                'batchId' => $batchIdStr,
                'timestamp' => $safeTimestamp,
                'user' => $authUser['name'],
                'reason' => $safeReason,
                'changes' => $encodedChanges,
            ]);

            return [
                'deduplicated' => false,
                'record' => [
                    'id' => $insertId,
                    'batchId' => $batchIdStr,
                    'timestamp' => $safeTimestamp,
                    'user' => $authUser['name'],
                    'reason' => $safeReason,
                    'changes' => $safeChanges,
                ],
            ];
        };

        try {
            $result = Cache::lock("audit_dedupe:{$fingerprint}", 5)->block(3, $writeFn);
        } catch (\Throwable) {
            $result = $writeFn();
        }

        if (!empty($result['deduplicated'])) {
            return response()->json($result['record']);
        }

        $insertedId = $result['record']['id'];
        $lowerReason = strtolower($safeReason);
        $isDeallocate = str_contains($lowerReason, 'membatalkan alokasi');
        $isAllocate = !$isDeallocate && str_starts_with($lowerReason, 'alokasi ');
        $isSuspend = str_contains($lowerReason, 'suspensi') || str_contains($lowerReason, 'suspend');
        $isRestore = str_contains($lowerReason, 'pemulihan');
        $isRedirectUrl = str_contains($lowerReason, 'redirect') || str_contains($lowerReason, 'url');

        if ($isDeallocate) {
            $notifTitle = "Pembatalan Alokasi: {$batchIdStr}";
        } elseif ($isAllocate) {
            $notifTitle = "Alokasi Proyek: {$batchIdStr}";
        } elseif ($isSuspend) {
            $notifTitle = "Suspensi Batch: {$batchIdStr}";
        } elseif ($isRestore) {
            $notifTitle = "Pemulihan Batch: {$batchIdStr}";
        } elseif ($isRedirectUrl) {
            $notifTitle = "Pembaruan URL QR: {$batchIdStr}";
        } else {
            $notifTitle = "Pengeditan Data: {$batchIdStr}";
        }

        // Avoid repeating project info already stated in $safeReason for allocation/deallocation actions
        $description = $safeReason;
        if (!$isAllocate && !$isDeallocate) {
            $fieldParts = [];
            foreach ($safeChanges as $c) {
                if (is_array($c)) {
                    $field = trim((string) ($c['field'] ?? ''));
                    $old = trim((string) ($c['old'] ?? ''));
                    $new = trim((string) ($c['new'] ?? ''));
                    if ($field !== '' && $new !== '') {
                        $fieldParts[] = ($old !== '' && $old !== '-')
                            ? "{$field}: {$old} → {$new}"
                            : "{$field}: {$new}";
                    }
                }
            }
            $fieldSummary = implode(', ', $fieldParts);
            if ($fieldSummary !== '') {
                $description = "{$safeReason} · {$fieldSummary}";
            }
        }

        $brandName = $relatedBatch->brand ?? null;
        NotificationService::createNotification([
            'type' => 'edit',
            'severity' => $isSuspend ? 'warning' : 'info',
            'audience' => $brandName ? 'brand_scope' : 'super_admin',
            'brandName' => $brandName,
            'actorUserId' => $authUser['id'] ?? null,
            'title' => $notifTitle,
            'subtitle' => "Oleh {$actorLabel}" . ($brandName ? " · {$brandName}" : ''),
            'description' => $description,
            'entityType' => 'audit_log',
            'entityId' => (string) $insertedId,
            'status' => 'edited',
            'dedupeKey' => "audit:{$batchIdStr}:{$fingerprint}",
            'dedupeWindowMs' => $dedupeWindowMs,
            'metadata' => [
                'user' => $actorLabel,
                'batchId' => $batchIdStr,
                'brand' => $brandName,
                'changes' => $safeChanges,
            ],
        ]);

        return response()->json($result['record']);
    }
}
