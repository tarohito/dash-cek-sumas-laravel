<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RbacService
{
    public const RBAC_CACHE_KEY = 'sumas_rbac_matrix_v1';
    public const ALL_PERMISSIONS = [
        'dashboard.view',
        'scope.all_brands',
        'brands.view',
        'brands.manage',
        'categories.view',
        'categories.manage',
        'sku.view',
        'sku.manage',
        'batches.view',
        'batches.create',
        'batches.delete',
        'batches.allocate',
        'batches.redirect_url',
        'batches.suspend',
        'batches.download',
        'batches.layout_edit',
        'scans.view',
        'users.view',
        'users.manage',
        'settings.view',
        'settings.manage',
        'notifications.manage',
    ];

    public const DEFAULT_ROLE_PERMISSIONS = [
        'Super Admin' => self::ALL_PERMISSIONS,
        'Admin Brand' => [
            'dashboard.view',
            'brands.view',
            'sku.view',
            'batches.view',
            'batches.allocate',
            'scans.view',
            'notifications.manage',
        ],
    ];

    public static function normalizeRole(?string $role): string
    {
        $raw = strtolower(trim((string) $role));
        if (in_array($raw, ['super admin', 'administrator', 'admin'], true)) {
            return 'Super Admin';
        }
        return 'Admin Brand';
    }

    /**
     * @param array<string, mixed>|null $stored
     * @return array<string, string[]>
     */
    public static function mergeRbacMatrix(?array $stored): array
    {
        $result = [
            'Super Admin' => self::ALL_PERMISSIONS,
            'Admin Brand' => self::DEFAULT_ROLE_PERMISSIONS['Admin Brand'],
        ];

        if (!$stored) {
            return $result;
        }

        $candidate = $stored['Admin Brand'] ?? $stored['Pengelola Brand'] ?? $stored['Operator'] ?? null;
        if (is_array($candidate)) {
            $validSet = array_flip(self::ALL_PERMISSIONS);
            $filtered = [];
            foreach ($candidate as $perm) {
                if (is_string($perm) && isset($validSet[$perm])) {
                    $filtered[$perm] = $perm;
                }
            }
            $result['Admin Brand'] = array_values($filtered);
        }

        return $result;
    }

    /**
     * @param array<string, string[]>|null $matrix
     * @return string[]
     */
    public static function getPermissionsForRole(?string $role, ?array $matrix = null): array
    {
        $normalized = self::normalizeRole($role);
        if ($normalized === 'Super Admin') {
            return self::ALL_PERMISSIONS;
        }
        $source = $matrix ?? self::DEFAULT_ROLE_PERMISSIONS;
        return isset($source[$normalized]) && is_array($source[$normalized])
            ? $source[$normalized]
            : self::DEFAULT_ROLE_PERMISSIONS[$normalized];
    }

    /**
     * @param array<string, string[]>|null $matrix
     */
    public static function hasPermission(?string $role, string $permission, ?array $matrix = null): bool
    {
        $normalized = self::normalizeRole($role);
        if ($normalized === 'Super Admin') {
            return true;
        }
        $perms = self::getPermissionsForRole($normalized, $matrix);
        return in_array($permission, $perms, true);
    }

    /**
     * @return array<string, string[]>
     */
    public static function getRbacMatrix(): array
    {
        try {
            return Cache::remember(self::RBAC_CACHE_KEY, 300, static function (): array {
                $row = DB::table('system_settings')->where('key', 'rbac_permissions')->first();
                if ($row && !empty($row->value)) {
                    $decoded = json_decode((string) $row->value, true);
                    if (is_array($decoded)) {
                        return self::mergeRbacMatrix($decoded);
                    }
                }
                return self::mergeRbacMatrix(self::DEFAULT_ROLE_PERMISSIONS);
            });
        } catch (\Throwable) {
        }
        return self::mergeRbacMatrix(self::DEFAULT_ROLE_PERMISSIONS);
    }

    /**
     * @param array<string, mixed> $rawMatrix
     * @return array<string, string[]>
     */
    public static function saveRbacMatrix(array $rawMatrix): array
    {
        $merged = self::mergeRbacMatrix($rawMatrix);
        $updatedAt = self::formatIndonesianTimestamp();

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'rbac_permissions'],
            ['value' => json_encode($merged), 'updatedAt' => $updatedAt]
        );

        Cache::forget(self::RBAC_CACHE_KEY);

        return $merged;
    }

    /**
     * @param array<string, string[]> $matrix
     * @return array{user: ?array, isGlobal: bool, ownedBrandNames: array<string, bool>, ownedBrandIds: array<string, bool>}
     */
    public static function getUserBrandScope(?array $user, array $matrix): array
    {
        if (!$user) {
            return [
                'user' => null,
                'isGlobal' => false,
                'ownedBrandNames' => [],
                'ownedBrandIds' => [],
            ];
        }

        $isGlobal = self::hasPermission($user['role'] ?? '', 'scope.all_brands', $matrix);
        if ($isGlobal) {
            return [
                'user' => $user,
                'isGlobal' => true,
                'ownedBrandNames' => [],
                'ownedBrandIds' => [],
            ];
        }

        $rows = DB::table('brands')
            ->select(['id', 'name'])
            ->where('ownerId', $user['id'])
            ->get();

        $ownedNames = [];
        $ownedIds = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row->name ?? '')));
            if ($name !== '') {
                $ownedNames[$name] = true;
            }
            $id = trim((string) ($row->id ?? ''));
            if ($id !== '') {
                $ownedIds[$id] = true;
            }
        }

        return [
            'user' => $user,
            'isGlobal' => false,
            'ownedBrandNames' => $ownedNames,
            'ownedBrandIds' => $ownedIds,
        ];
    }

    /**
     * @param array{isGlobal: bool, ownedBrandNames: array<string, bool>, ownedBrandIds: array<string, bool>} $scope
     */
    public static function canScopeAccessBrand(array $scope, ?string $brandName = null, ?string $brandId = null): bool
    {
        if (!empty($scope['isGlobal'])) {
            return true;
        }
        if ($brandId !== null && $brandId !== '' && isset($scope['ownedBrandIds'][trim($brandId)])) {
            return true;
        }
        if ($brandName !== null && $brandName !== '' && isset($scope['ownedBrandNames'][strtolower(trim($brandName))])) {
            return true;
        }
        return false;
    }

    /**
     * @param array{isGlobal: bool, ownedBrandNames: array<string, bool>, ownedBrandIds: array<string, bool>} $scope
     */
    public static function assertBrandAccess(array $scope, ?string $brandName = null, ?string $brandId = null): ?JsonResponse
    {
        if (self::canScopeAccessBrand($scope, $brandName, $brandId)) {
            return null;
        }
        return response()->json([
            'success' => false,
            'error' => 'Akses ditolak. Anda hanya dapat mengakses data milik brand yang berada di bawah pengelolaan Anda.',
        ], 403);
    }

    public static function isBrandB2B(?string $brandName = null, mixed $batchIsB2B = null): bool
    {
        if ($batchIsB2B === 1 || $batchIsB2B === true || $batchIsB2B === '1') {
            return true;
        }
        if ($batchIsB2B === 0 || $batchIsB2B === false || $batchIsB2B === '0') {
            return false;
        }
        if (!$brandName) {
            return false;
        }
        try {
            $row = DB::table('brands')
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($brandName))])
                ->first(['businessType']);
            return $row && ($row->businessType ?? '') === 'B2B';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function formatIndonesianTimestamp(?int $timestampMs = null): string
    {
        $tsSec = $timestampMs !== null ? (int) floor($timestampMs / 1000) : time();
        $dt = new \DateTimeImmutable('@' . $tsSec);
        $dt = $dt->setTimezone(new \DateTimeZone('Asia/Jakarta'));
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
        $day = $dt->format('d');
        $month = $months[(int) $dt->format('n') - 1];
        $year = $dt->format('Y');
        $time = $dt->format('H.i');
        return "{$day} {$month} {$year}, {$time} WIB";
    }
}
