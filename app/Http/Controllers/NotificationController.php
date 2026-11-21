<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function stream(Request $request): Response
    {
        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $canManageUsers = $this->can($request, 'users.manage');

        $payload = json_encode([
            'role' => $authUser['role'] ?? 'Admin Brand',
            'isGlobal' => !empty($scope['isGlobal']),
            'canManageUsers' => $canManageUsers,
        ]);

        return response("event: connected\ndata: {$payload}\n\n", 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $canManageUsers = $this->can($request, 'users.manage');
        $nowMs = (int) round(microtime(true) * 1000);

        $rawCategory = strtolower((string) $request->query('category', 'all'));
        $category = in_array($rawCategory, ['all', 'important', 'reset', 'scan', 'edit'], true) ? $rawCategory : 'all';
        $limit = min(50, max(1, (int) $request->query('limit', '20') ?: 20));
        $offset = max(0, (int) $request->query('offset', '0'));

        $readRows = DB::table('read_notifications')
            ->where('userId', $authUser['id'])
            ->pluck('notificationId')
            ->all();
        $readIds = array_flip($readRows);

        $allRows = DB::table('notifications')->orderByDesc('createdAtTs')->limit(300)->get();

        $accessCtx = [
            'userId' => $authUser['id'],
            'isGlobal' => !empty($scope['isGlobal']),
            'canManageUsers' => $canManageUsers,
            'ownedBrandNames' => $scope['ownedBrandNames'] ?? [],
            'ownedBrandIds' => $scope['ownedBrandIds'] ?? [],
        ];

        $accessibleItems = [];
        foreach ($allRows as $row) {
            if (NotificationService::canUserAccessRow($row, $accessCtx)) {
                $accessibleItems[] = NotificationService::formatRowForClient($row, $readIds, $nowMs);
            }
        }

        $isImportant = static function (array $item): bool {
            return ($item['severity'] ?? '') === 'critical'
                || ($item['severity'] ?? '') === 'warning'
                || (($item['category'] ?? '') === 'password_reset' && ($item['status'] ?? '') === 'Menunggu')
                || (($item['category'] ?? '') === 'scan' && ($item['status'] ?? '') === 'suspicious');
        };

        $counts = [
            'all' => count($accessibleItems),
            'important' => 0,
            'reset' => 0,
            'scan' => 0,
            'edit' => 0,
        ];

        $unreadCount = 0;
        $pendingResetCount = 0;
        $filteredItems = [];

        foreach ($accessibleItems as $item) {
            $imp = $isImportant($item);
            if ($imp) {
                $counts['important']++;
            }
            if (($item['category'] ?? '') === 'password_reset') {
                $counts['reset']++;
                if (($item['status'] ?? '') === 'Menunggu') {
                    $pendingResetCount++;
                }
            }
            if (($item['category'] ?? '') === 'scan') {
                $counts['scan']++;
            }
            if (in_array($item['category'] ?? '', ['edit', 'system'], true)) {
                $counts['edit']++;
            }
            if (empty($item['isRead'])) {
                $unreadCount++;
            }

            $match = match ($category) {
                'important' => $imp,
                'reset' => ($item['category'] ?? '') === 'password_reset',
                'scan' => ($item['category'] ?? '') === 'scan',
                'edit' => in_array($item['category'] ?? '', ['edit', 'system'], true),
                default => true,
            };
            if ($match) {
                $filteredItems[] = $item;
            }
        }

        $managedBrands = empty($scope['isGlobal'])
            ? DB::table('brands')->where('ownerId', $authUser['id'])->pluck('name')->all()
            : [];

        return response()->json([
            'unreadCount' => $unreadCount,
            'pendingResetCount' => $pendingResetCount,
            'importantCount' => $counts['important'],
            'counts' => $counts,
            'total' => count($filteredItems),
            'hasMore' => ($offset + $limit) < count($filteredItems),
            'roleScope' => [
                'role' => $authUser['role'],
                'isGlobal' => !empty($scope['isGlobal']),
                'canManageUsers' => $canManageUsers,
                'managedBrands' => $managedBrands,
            ],
            'notifications' => array_slice($filteredItems, $offset, $limit),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        if (!$this->can($request, 'notifications.manage')) {
            return $this->deny($request, 'Akses ditolak. Peran Anda tidak memiliki izin mengelola atau menandai notifikasi.');
        }

        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $canManageUsers = $this->can($request, 'users.manage');
        $readAtIso = gmdate('Y-m-d\TH:i:s\Z');
        $body = $request->all();

        $accessCtx = [
            'userId' => $authUser['id'],
            'isGlobal' => !empty($scope['isGlobal']),
            'canManageUsers' => $canManageUsers,
            'ownedBrandNames' => $scope['ownedBrandNames'] ?? [],
            'ownedBrandIds' => $scope['ownedBrandIds'] ?? [],
        ];

        if (!empty($body['all'])) {
            $allRows = DB::table('notifications')
                ->select(['id', 'audience', 'targetUserId', 'brandName', 'brandId', 'type'])
                ->get();
            $inserts = [];
            foreach ($allRows as $row) {
                if (NotificationService::canUserAccessRow($row, $accessCtx)) {
                    $inserts[] = ['userId' => $authUser['id'], 'notificationId' => $row->id, 'readAt' => $readAtIso];
                }
            }
            foreach (array_chunk($inserts, 200) as $chunk) {
                DB::table('read_notifications')->insertOrIgnore($chunk);
            }
        } elseif (is_array($body['ids'] ?? null)) {
            $inserts = [];
            foreach (array_slice($body['ids'], 0, 500) as $nid) {
                if (is_string($nid) && strlen($nid) > 0 && strlen($nid) <= 120) {
                    $inserts[] = ['userId' => $authUser['id'], 'notificationId' => $nid, 'readAt' => $readAtIso];
                }
            }
            foreach (array_chunk($inserts, 200) as $chunk) {
                DB::table('read_notifications')->insertOrIgnore($chunk);
            }
        } elseif (is_string($body['id'] ?? null) && strlen($body['id']) > 0 && strlen($body['id']) <= 120) {
            DB::table('read_notifications')->insertOrIgnore([
                'userId' => $authUser['id'],
                'notificationId' => $body['id'],
                'readAt' => $readAtIso,
            ]);
        }

        return response()->json(['success' => true]);
    }
}
