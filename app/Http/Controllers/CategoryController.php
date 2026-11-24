<?php

namespace App\Http\Controllers;

use App\Services\SecurityHardeningService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('categories')->orderBy('level')->orderBy('createdAt')->orderBy('name')->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['level'] = (int) ($arr['level'] ?? 1);
                return $arr;
            });
        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can($request, 'categories.manage')) {
            return $this->deny($request);
        }

        $c = $request->all();
        $cleanName = SecurityHardeningService::sanitizeStringInput($c['name'] ?? '', 120);
        if ($cleanName === '') {
            return response()->json(['error' => 'Nama kategori wajib diisi.'], 400);
        }

        $id = SecurityHardeningService::sanitizeStringInput($c['id'] ?? '', 80)
            ?: ('cat-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(2)));
        $level = min(3, max(1, (int) ($c['level'] ?? 1) ?: 1));
        $parentId = !empty($c['parentId']) ? SecurityHardeningService::sanitizeStringInput($c['parentId'], 80) : null;
        $specSchema = isset($c['specSchema'])
            ? (is_string($c['specSchema']) ? $c['specSchema'] : json_encode($c['specSchema']))
            : null;

        DB::table('categories')->insert([
            'id' => $id,
            'name' => $cleanName,
            'parentId' => $parentId,
            'level' => $level,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'specSchema' => $specSchema,
        ]);

        return response()->json(DB::table('categories')->where('id', $id)->first());
    }

    public function updateSpecs(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'categories.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('categories')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Kategori tidak ditemukan.'], 404);
        }

        $raw = $request->input('specSchema');
        $specSchema = is_string($raw) ? $raw : json_encode($raw ?? []);
        DB::table('categories')->where('id', $decodedId)->update(['specSchema' => $specSchema]);

        return response()->json(DB::table('categories')->where('id', $decodedId)->first());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'categories.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('categories')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Kategori tidak ditemukan.'], 404);
        }

        $name = $request->has('name')
            ? SecurityHardeningService::sanitizeStringInput($request->input('name'), 120)
            : (string) $existing->name;
        if ($name === '') {
            return response()->json(['error' => 'Nama kategori wajib diisi.'], 400);
        }

        $oldName = (string) $existing->name;
        $level = (int) ($existing->level ?? 1);
        $updatedProductsCount = 0;

        DB::transaction(function () use ($decodedId, $oldName, $name, $level, $existing, &$updatedProductsCount) {
            DB::table('categories')->where('id', $decodedId)->update(['name' => $name]);

            if ($oldName !== '' && $oldName !== $name) {
                $parentName = null;
                $grandparentName = null;

                if ($level >= 2 && !empty($existing->parentId)) {
                    $parentRow = DB::table('categories')->where('id', $existing->parentId)->first(['id', 'name', 'parentId']);
                    if ($parentRow) {
                        $parentName = (string) $parentRow->name;
                        if ($level === 3 && !empty($parentRow->parentId)) {
                            $gpRow = DB::table('categories')->where('id', $parentRow->parentId)->first(['name']);
                            if ($gpRow) {
                                $grandparentName = (string) $gpRow->name;
                            }
                        }
                    }
                }

                $products = DB::table('products')->select(['id', 'category'])->get();
                foreach ($products as $prod) {
                    $raw = trim((string) ($prod->category ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $parts = array_values(array_filter(array_map('trim', explode('>', $raw)), static fn ($v) => $v !== ''));
                    if (count($parts) === 0) {
                        continue;
                    }

                    $changed = false;
                    if ($level === 1 && strcasecmp($parts[0], $oldName) === 0) {
                        $parts[0] = $name;
                        $changed = true;
                    } elseif ($level === 2 && isset($parts[1])) {
                        $parentOk = ($parentName === null || strcasecmp($parts[0], $parentName) === 0);
                        if ($parentOk && strcasecmp($parts[1], $oldName) === 0) {
                            $parts[1] = $name;
                            $changed = true;
                        }
                    } elseif ($level === 3 && isset($parts[2])) {
                        $gpOk = ($grandparentName === null || strcasecmp($parts[0], $grandparentName) === 0);
                        $parentOk = ($parentName === null || strcasecmp($parts[1], $parentName) === 0);
                        if ($gpOk && $parentOk && strcasecmp($parts[2], $oldName) === 0) {
                            $parts[2] = $name;
                            $changed = true;
                        }
                    } elseif (count($parts) === 1 && strcasecmp($parts[0], $oldName) === 0) {
                        $parts[0] = $name;
                        $changed = true;
                    }

                    if ($changed) {
                        $nextPath = mb_substr(implode(' > ', $parts), 0, 400, 'UTF-8');
                        DB::table('products')->where('id', $prod->id)->update(['category' => $nextPath]);
                        $updatedProductsCount++;
                    }
                }
            }
        });

        $updatedRow = (array) DB::table('categories')->where('id', $decodedId)->first();
        $updatedRow['level'] = (int) ($updatedRow['level'] ?? 1);
        $updatedRow['updatedProductsCount'] = $updatedProductsCount;

        return response()->json($updatedRow);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'categories.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('categories')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Kategori tidak ditemukan.'], 404);
        }

        $deletedIds = [$decodedId];
        $authUser = $this->authUser($request);
        TrashService::ensureTableExists();
        $trashId = null;
        DB::transaction(function () use ($decodedId, $existing, $authUser, $request, &$deletedIds, &$trashId) {
            $items = [(array) $existing];
            $childrenL2 = DB::table('categories')->where('parentId', $decodedId)->get();
            $childrenL2Ids = $childrenL2->pluck('id')->all();
            foreach ($childrenL2 as $row) {
                $items[] = (array) $row;
            }
            if (count($childrenL2Ids) > 0) {
                $childrenL3 = DB::table('categories')->whereIn('parentId', $childrenL2Ids)->get();
                $childrenL3Ids = $childrenL3->pluck('id')->all();
                foreach ($childrenL3 as $row) {
                    $items[] = (array) $row;
                }
                $deletedIds = array_values(array_unique(array_merge($deletedIds, $childrenL2Ids, $childrenL3Ids)));
                if (count($childrenL3Ids) > 0) {
                    DB::table('categories')->whereIn('id', $childrenL3Ids)->delete();
                }
                DB::table('categories')->whereIn('id', $childrenL2Ids)->delete();
            }
            DB::table('categories')->where('id', $decodedId)->delete();

            $levelLabel = match ((int) ($existing->level ?? 1)) {
                1 => 'Kategori Utama',
                2 => 'Sub Kategori',
                default => 'Varian / Jenis',
            };
            $subCount = max(0, count($items) - 1);
            $subtitle = $subCount > 0 ? "{$levelLabel} (+{$subCount} sub-kategori)" : $levelLabel;

            $trashId = TrashService::moveToTrash([
                'entityType' => 'category',
                'entityId' => (string) $existing->id,
                'title' => (string) ($existing->name ?: $existing->id),
                'subtitle' => $subtitle,
                'deletedById' => $authUser['id'] ?? null,
                'deletedByName' => $this->actorLabel($request),
                'itemCount' => count($items),
                'payload' => [
                    'root' => (array) $existing,
                    'items' => $items,
                ],
            ]);
        });

        return response()->json(['success' => true, 'trashed' => true, 'trashId' => $trashId, 'deletedIds' => $deletedIds]);
    }
}
