<?php

namespace App\Http\Controllers;

use App\Services\SecurityHardeningService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrashController extends Controller
{
    private function canViewTrash(Request $request): bool
    {
        return $this->canAny(
            $request,
            'brands.manage',
            'categories.manage',
            'sku.manage',
            'batches.delete',
            'batches.layout_edit',
            'users.manage',
            'settings.manage'
        );
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        TrashService::purgeExpired();

        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $matrix = $this->rbacMatrix($request);
        $nowMs = (int) round(microtime(true) * 1000);

        $typeFilter = SecurityHardeningService::sanitizeStringInput((string) $request->query('type', 'all'), 40) ?: 'all';
        $search = strtolower(SecurityHardeningService::sanitizeStringInput((string) $request->query('q', ''), 120));

        $rows = DB::table('trash_items')->orderByDesc('deletedAtTs')->get();

        $counts = [
            'all' => 0,
            'brand' => 0,
            'product' => 0,
            'category' => 0,
            'batch' => 0,
            'user' => 0,
            'tag_layout' => 0,
        ];

        $items = [];
        foreach ($rows as $row) {
            if (!TrashService::canUserAccessItem($authUser, $scope, $matrix, $row)) {
                continue;
            }
            $type = (string) $row->entityType;
            $counts['all']++;
            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            }

            if ($typeFilter !== 'all' && $type !== $typeFilter) {
                continue;
            }

            if ($search !== '') {
                $haystack = strtolower(
                    implode(' ', array_filter([
                        $row->title ?? '',
                        $row->subtitle ?? '',
                        $row->brandName ?? '',
                        $row->deletedByName ?? '',
                        $row->entityId ?? '',
                    ]))
                );
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $items[] = TrashService::formatForClient($row, $nowMs);
        }

        return response()->json([
            'items' => $items,
            'counts' => $counts,
            'retentionDays' => TrashService::RETENTION_DAYS,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return response()->json(['count' => 0]);
        }

        TrashService::purgeExpired();

        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $matrix = $this->rbacMatrix($request);

        $rows = DB::table('trash_items')
            ->select(['id', 'entityType', 'brandName', 'brandId', 'deletedById'])
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            if (TrashService::canUserAccessItem($authUser, $scope, $matrix, $row)) {
                $count++;
            }
        }

        return response()->json(['count' => $count]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        TrashService::purgeExpired();

        $decodedId = urldecode($id);
        $row = DB::table('trash_items')->where('id', $decodedId)->first();
        if (!$row) {
            return response()->json(['error' => 'Item tidak ditemukan di Tempat Sampah atau sudah kedaluwarsa.'], 404);
        }

        if (!TrashService::canUserAccessItem($this->authUser($request), $this->brandScope($request), $this->rbacMatrix($request), $row)) {
            return $this->deny($request, 'Akses ditolak untuk memulihkan item ini.');
        }

        $res = TrashService::restoreItem($row);
        if (empty($res['success'])) {
            return response()->json(['error' => $res['error'] ?? 'Gagal memulihkan item.'], (int) ($res['status'] ?? 400));
        }

        return response()->json([
            'success' => true,
            'entityType' => $row->entityType,
            'entityId' => $row->entityId,
            'title' => $row->title,
            'restoredCount' => $res['restoredCount'] ?? 1,
            'message' => "\"{$row->title}\" berhasil dipulihkan dari Tempat Sampah.",
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        TrashService::purgeExpired();

        $rawIds = $request->input('ids');
        if (!is_array($rawIds) || count($rawIds) === 0) {
            return response()->json(['error' => 'Pilih minimal satu item untuk dipulihkan.'], 400);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($v) => SecurityHardeningService::sanitizeStringInput((string) $v, 128),
            array_slice($rawIds, 0, 200)
        ))));

        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $matrix = $this->rbacMatrix($request);

        $rows = DB::table('trash_items')->whereIn('id', $ids)->get()->keyBy('id');
        $typeOrder = [
            'user' => 0,
            'brand' => 1,
            'category' => 2,
            'product' => 3,
            'batch' => 4,
            'tag_layout' => 5,
        ];
        $orderedRows = collect($ids)
            ->map(fn ($id) => $rows->get($id))
            ->filter()
            ->sortBy(function ($r) use ($typeOrder) {
                $base = ($typeOrder[$r->entityType] ?? 9) * 100;
                if ($r->entityType === 'category') {
                    $decoded = json_decode((string) ($r->payload ?? '{}'), true);
                    $level = (int) ($decoded['root']['level'] ?? 1);
                    return $base + $level;
                }
                return $base;
            })
            ->values();

        $restoredIds = [];
        $restoredTypes = [];
        $failed = [];

        foreach ($orderedRows as $row) {
            if (!TrashService::canUserAccessItem($authUser, $scope, $matrix, $row)) {
                $failed[] = ['id' => $row->id, 'title' => $row->title, 'error' => 'Akses ditolak.'];
                continue;
            }
            $res = TrashService::restoreItem($row);
            if (!empty($res['success'])) {
                $restoredIds[] = $row->id;
                $restoredTypes[$row->entityType] = true;
            } else {
                $failed[] = ['id' => $row->id, 'title' => $row->title, 'error' => $res['error'] ?? 'Gagal memulihkan.'];
            }
        }

        return response()->json([
            'success' => count($restoredIds) > 0,
            'restoredIds' => $restoredIds,
            'restoredTypes' => array_keys($restoredTypes),
            'failed' => $failed,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $row = DB::table('trash_items')->where('id', $decodedId)->first();
        if (!$row) {
            return response()->json(['error' => 'Item tidak ditemukan di Tempat Sampah.'], 404);
        }

        if (!TrashService::canUserAccessItem($this->authUser($request), $this->brandScope($request), $this->rbacMatrix($request), $row)) {
            return $this->deny($request, 'Akses ditolak untuk menghapus permanen item ini.');
        }

        DB::table('trash_items')->where('id', $decodedId)->delete();

        return response()->json(['success' => true]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        $rawIds = $request->input('ids');
        if (!is_array($rawIds) || count($rawIds) === 0) {
            return response()->json(['error' => 'Pilih minimal satu item untuk dihapus permanen.'], 400);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($v) => SecurityHardeningService::sanitizeStringInput((string) $v, 128),
            array_slice($rawIds, 0, 200)
        ))));

        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $matrix = $this->rbacMatrix($request);

        $rows = DB::table('trash_items')->whereIn('id', $ids)->get();
        $allowedIds = [];
        foreach ($rows as $row) {
            if (TrashService::canUserAccessItem($authUser, $scope, $matrix, $row)) {
                $allowedIds[] = $row->id;
            }
        }

        if (count($allowedIds) > 0) {
            DB::table('trash_items')->whereIn('id', $allowedIds)->delete();
        }

        return response()->json([
            'success' => true,
            'deletedCount' => count($allowedIds),
        ]);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        if (!$this->canViewTrash($request)) {
            return $this->deny($request);
        }

        $typeFilter = SecurityHardeningService::sanitizeStringInput((string) $request->query('type', 'all'), 40) ?: 'all';
        $authUser = $this->authUser($request);
        $scope = $this->brandScope($request);
        $matrix = $this->rbacMatrix($request);

        $rows = DB::table('trash_items')->get();
        $allowedIds = [];
        foreach ($rows as $row) {
            if ($typeFilter !== 'all' && $row->entityType !== $typeFilter) {
                continue;
            }
            if (TrashService::canUserAccessItem($authUser, $scope, $matrix, $row)) {
                $allowedIds[] = $row->id;
            }
        }

        if (count($allowedIds) > 0) {
            foreach (array_chunk($allowedIds, 200) as $chunk) {
                DB::table('trash_items')->whereIn('id', $chunk)->delete();
            }
        }

        return response()->json([
            'success' => true,
            'deletedCount' => count($allowedIds),
        ]);
    }
}
