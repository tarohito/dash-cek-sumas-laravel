<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function authUser(Request $request): array
    {
        return $request->attributes->get('sumas_user', []);
    }

    protected function rbacMatrix(Request $request): array
    {
        return $request->attributes->get('sumas_matrix', RbacService::DEFAULT_ROLE_PERMISSIONS);
    }

    protected function brandScope(Request $request): array
    {
        return $request->attributes->get('sumas_scope', [
            'user' => null,
            'isGlobal' => false,
            'ownedBrandNames' => [],
            'ownedBrandIds' => [],
        ]);
    }

    protected function actorLabel(Request $request): string
    {
        return (string) $request->attributes->get('sumas_actor_label', 'Sistem');
    }

    protected function can(Request $request, string $permission): bool
    {
        $user = $this->authUser($request);
        return RbacService::hasPermission($user['role'] ?? null, $permission, $this->rbacMatrix($request));
    }

    protected function canAny(Request $request, string ...$permissions): bool
    {
        foreach ($permissions as $perm) {
            if ($this->can($request, $perm)) {
                return true;
            }
        }
        return false;
    }

    protected function deny(Request $request, ?string $message = null): JsonResponse
    {
        $role = $this->authUser($request)['role'] ?? 'Admin Brand';
        return response()->json([
            'error' => $message ?? "Akses ditolak. Peran \"{$role}\" tidak memiliki izin untuk melakukan tindakan ini.",
        ], 403);
    }
}
