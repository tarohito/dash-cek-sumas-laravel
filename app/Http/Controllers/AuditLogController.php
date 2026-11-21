<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $rawId = $log['id'] ?? null;
        $safeId = (is_numeric($rawId) && (int) $rawId > 0) ? (int) $rawId : (int) round(microtime(true) * 1000);
        $safeTimestamp = SecurityHardeningService::sanitizeStringInput($log['timestamp'] ?? '', 80) ?: RbacService::formatIndonesianTimestamp();
        $safeReason = SecurityHardeningService::sanitizeStringInput($log['reason'] ?? '', 400) ?: 'Pembaruan data';
        $safeChanges = is_array($log['changes'] ?? null) ? array_slice($log['changes'], 0, 50) : [];

        DB::table('audit_logs')->insert([
            'id' => $safeId,
            'batchId' => $batchIdStr,
            'timestamp' => $safeTimestamp,
            'user' => $authUser['name'],
            'reason' => $safeReason,
            'changes' => json_encode($safeChanges),
        ]);

        $fieldParts = [];
        foreach ($safeChanges as $c) {
            if (is_array($c)) {
                $field = $c['field'] ?? '';
                $old = $c['old'] ?? '';
                $new = $c['new'] ?? '';
                $fieldParts[] = "{$field}: \"{$old}\" → \"{$new}\"";
            }
        }
        $fieldSummary = implode(', ', $fieldParts);

        $brandName = $relatedBatch->brand ?? null;
        NotificationService::createNotification([
            'type' => 'edit',
            'severity' => str_contains(strtolower($safeReason), 'suspend') ? 'warning' : 'info',
            'audience' => $brandName ? 'brand_scope' : 'super_admin',
            'brandName' => $brandName,
            'title' => "Pengeditan Data: {$batchIdStr}",
            'subtitle' => "Dilakukan oleh {$actorLabel}" . ($brandName ? " · {$brandName}" : ''),
            'description' => $safeReason . ($fieldSummary !== '' ? " ({$fieldSummary})" : ''),
            'entityType' => 'audit_log',
            'entityId' => (string) $safeId,
            'status' => 'edited',
            'dedupeKey' => "audit:{$batchIdStr}:{$safeReason}",
            'dedupeWindowMs' => 5000,
            'metadata' => [
                'user' => $actorLabel,
                'batchId' => $batchIdStr,
                'brand' => $brandName,
                'changes' => $safeChanges,
            ],
        ]);

        return response()->json([
            'id' => $safeId,
            'batchId' => $batchIdStr,
            'timestamp' => $safeTimestamp,
            'user' => $authUser['name'],
            'reason' => $safeReason,
            'changes' => $safeChanges,
        ]);
    }
}
