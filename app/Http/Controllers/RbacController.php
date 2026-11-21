<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RbacController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        $matrix = $this->rbacMatrix($request);

        return response()->json([
            'matrix' => $matrix,
            'permissions' => RbacService::getPermissionsForRole($user['role'] ?? null, $matrix),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (RbacService::normalizeRole($user['role'] ?? null) !== 'Super Admin') {
            return $this->deny($request, 'Hanya Super Admin yang berwenang mengubah matriks hak akses (RBAC).');
        }

        $rawMatrix = $request->input('matrix');
        if (!is_array($rawMatrix)) {
            return response()->json([
                'success' => false,
                'error' => 'Payload matriks hak akses tidak valid.',
            ], 400);
        }

        $saved = RbacService::saveRbacMatrix($rawMatrix);
        $nowStr = RbacService::formatIndonesianTimestamp();
        $actorLabel = $this->actorLabel($request);

        try {
            DB::table('audit_logs')->insert([
                'id' => (int) round(microtime(true) * 1000),
                'batchId' => 'RBAC-POLICY',
                'timestamp' => $nowStr,
                'user' => $user['name'],
                'reason' => 'Pembaruan Matriks Hak Akses (RBAC)',
                'changes' => json_encode([
                    ['field' => 'Super Admin', 'old' => '-', 'new' => count($saved['Super Admin']) . ' izin aktif (Terkunci)'],
                    ['field' => 'Admin Brand', 'old' => '-', 'new' => count($saved['Admin Brand']) . ' izin aktif'],
                ]),
            ]);
        } catch (\Throwable) {
        }

        NotificationService::createNotification([
            'type' => 'system',
            'severity' => 'warning',
            'audience' => 'super_admin',
            'title' => 'Matriks Hak Akses (RBAC) Diperbarui',
            'subtitle' => "Diperbarui oleh {$actorLabel}",
            'description' => 'Izin aktif Admin Brand kini berjumlah ' . count($saved['Admin Brand']) . ' hak akses.',
            'entityType' => 'rbac',
            'entityId' => 'RBAC-POLICY',
            'status' => 'edited',
            'metadata' => [
                'actor' => $actorLabel,
                'adminBrandPermissionsCount' => count($saved['Admin Brand']),
            ],
        ]);

        return response()->json(['success' => true, 'matrix' => $saved]);
    }
}
