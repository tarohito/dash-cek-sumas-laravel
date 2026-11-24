<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $this->brandScope($request);
        $rows = DB::table('brands as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.ownerId')
            ->select(['b.*', 'u.name as userOwnerName', 'u.email as userOwnerEmail', 'u.role as userOwnerRole'])
            ->orderByDesc('b.createdAt')
            ->orderByDesc('b.id')
            ->get();

        $result = [];
        foreach ($rows as $rowObj) {
            $r = (array) $rowObj;
            if (empty($scope['isGlobal']) && !RbacService::canScopeAccessBrand($scope, $r['name'] ?? null, $r['id'] ?? null)) {
                continue;
            }
            $ownerName = $r['userOwnerName'] ?: 'Belum Ditentukan';
            $ownerEmail = $r['userOwnerEmail'] ?: null;
            $ownerRole = $r['userOwnerRole'] ? RbacService::normalizeRole($r['userOwnerRole']) : null;
            unset($r['userOwnerName'], $r['userOwnerEmail'], $r['userOwnerRole']);

            $r['ownerName'] = $ownerName;
            $r['ownerEmail'] = $ownerEmail;
            $r['ownerRole'] = $ownerRole;
            $result[] = $r;
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can($request, 'brands.manage')) {
            return $this->deny($request);
        }

        $b = $request->all();
        $cleanName = SecurityHardeningService::sanitizeStringInput($b['name'] ?? '', 120);
        if ($cleanName === '') {
            return response()->json(['error' => 'Nama brand wajib diisi.'], 400);
        }

        $cleanId = SecurityHardeningService::sanitizeStringInput($b['id'] ?? '', 80)
            ?: ('brand-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)));

        $logoCheck = SecurityHardeningService::validateSafeImageSource($b['logo'] ?? null);
        if (!$logoCheck['valid']) {
            return response()->json(['error' => $logoCheck['error'] ?? 'Format logo brand tidak valid.'], 400);
        }

        $scope = $this->brandScope($request);
        $authUser = $this->authUser($request);
        $requestedOwnerId = is_string($b['ownerId'] ?? null) && trim($b['ownerId']) !== ''
            ? SecurityHardeningService::sanitizeStringInput($b['ownerId'], 80)
            : '';
        $finalOwnerId = !empty($scope['isGlobal']) ? $requestedOwnerId : $authUser['id'];

        $ownerName = 'Belum Ditentukan';
        $ownerEmail = null;
        $ownerRole = null;
        if ($finalOwnerId !== '') {
            $u = DB::table('users')->where('id', $finalOwnerId)->first(['name', 'email', 'role']);
            if ($u) {
                $ownerName = $u->name;
                $ownerEmail = $u->email;
                $ownerRole = RbacService::normalizeRole($u->role);
            }
        }

        $businessType = ($b['businessType'] ?? '') === 'B2B' ? 'B2B' : 'Retail';
        $safeStatus = ($b['status'] ?? '') === 'Nonaktif' ? 'Nonaktif' : 'Aktif';
        $safeDesc = SecurityHardeningService::sanitizeStringInput($b['description'] ?? '', 1000);
        $safeCreatedAt = SecurityHardeningService::sanitizeStringInput($b['createdAt'] ?? '', 80) ?: RbacService::formatIndonesianTimestamp();

        DB::table('brands')->insert([
            'id' => $cleanId,
            'name' => $cleanName,
            'logo' => $logoCheck['value'],
            'ownerId' => $finalOwnerId,
            'description' => $safeDesc,
            'businessType' => $businessType,
            'status' => $safeStatus,
            'createdAt' => $safeCreatedAt,
        ]);

        return response()->json([
            'id' => $cleanId,
            'name' => $cleanName,
            'logo' => $logoCheck['value'],
            'ownerId' => $finalOwnerId,
            'ownerName' => $ownerName,
            'ownerEmail' => $ownerEmail,
            'ownerRole' => $ownerRole,
            'description' => $safeDesc,
            'businessType' => $businessType,
            'status' => $safeStatus,
            'createdAt' => $safeCreatedAt,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'brands.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('brands')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Brand tidak ditemukan'], 404);
        }

        $scope = $this->brandScope($request);
        if ($denied = RbacService::assertBrandAccess($scope, $existing->name, $existing->id)) {
            return $denied;
        }

        $b = $request->all();
        $nextLogo = $existing->logo;
        if (array_key_exists('logo', $b)) {
            $logoCheck = SecurityHardeningService::validateSafeImageSource($b['logo']);
            if (!$logoCheck['valid']) {
                return response()->json(['error' => $logoCheck['error'] ?? 'Format logo brand tidak valid.'], 400);
            }
            $nextLogo = $logoCheck['value'];
        }

        $rawOwnerId = array_key_exists('ownerId', $b) ? $b['ownerId'] : $existing->ownerId;
        $requestedOwnerId = is_string($rawOwnerId) && trim($rawOwnerId) !== ''
            ? SecurityHardeningService::sanitizeStringInput($rawOwnerId, 80)
            : '';

        if (empty($scope['isGlobal']) && array_key_exists('ownerId', $b) && $requestedOwnerId !== (string) ($existing->ownerId ?? '')) {
            return response()->json([
                'error' => 'Hanya Super Admin atau pemilik akses lintas brand yang berwenang memindahkan kepemilikan brand.',
            ], 403);
        }

        $targetOwnerId = !empty($scope['isGlobal']) ? $requestedOwnerId : (string) ($existing->ownerId ?? '');
        $ownerName = 'Belum Ditentukan';
        $ownerEmail = null;
        $ownerRole = null;
        if ($targetOwnerId !== '') {
            $u = DB::table('users')->where('id', $targetOwnerId)->first(['name', 'email', 'role']);
            if ($u) {
                $ownerName = $u->name;
                $ownerEmail = $u->email;
                $ownerRole = RbacService::normalizeRole($u->role);
            }
        }

        $updated = [
            'name' => array_key_exists('name', $b) ? SecurityHardeningService::sanitizeStringInput($b['name'], 120) : $existing->name,
            'logo' => $nextLogo,
            'ownerId' => $targetOwnerId,
            'description' => array_key_exists('description', $b) ? SecurityHardeningService::sanitizeStringInput($b['description'], 1000) : $existing->description,
            'businessType' => array_key_exists('businessType', $b) ? (($b['businessType'] === 'B2B') ? 'B2B' : 'Retail') : ($existing->businessType ?: 'Retail'),
            'status' => array_key_exists('status', $b) ? (($b['status'] === 'Nonaktif') ? 'Nonaktif' : 'Aktif') : $existing->status,
        ];

        DB::table('brands')->where('id', $decodedId)->update($updated);
        $result = (array) DB::table('brands')->where('id', $decodedId)->first();

        return response()->json([
            ...$result,
            'ownerName' => $ownerName,
            'ownerEmail' => $ownerEmail,
            'ownerRole' => $ownerRole,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'brands.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('brands')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Brand tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $existing->name, $existing->id)) {
            return $denied;
        }

        $authUser = $this->authUser($request);
        $trashId = null;
        DB::transaction(function () use ($decodedId, $existing, $authUser, $request, &$trashId) {
            $trashId = TrashService::moveToTrash([
                'entityType' => 'brand',
                'entityId' => (string) $existing->id,
                'title' => (string) ($existing->name ?: $existing->id),
                'subtitle' => ($existing->businessType ?? 'Retail') . ' · ' . ($existing->status ?? 'Aktif'),
                'brandName' => (string) $existing->name,
                'brandId' => (string) $existing->id,
                'deletedById' => $authUser['id'] ?? null,
                'deletedByName' => $this->actorLabel($request),
                'itemCount' => 1,
                'payload' => ['brand' => (array) $existing],
            ]);
            DB::table('brands')->where('id', $decodedId)->delete();
        });

        return response()->json(['success' => true, 'trashed' => true, 'trashId' => $trashId]);
    }
}
