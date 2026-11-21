<?php

namespace App\Http\Controllers;

use App\Services\SecurityHardeningService;
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
            'createdAt' => gmdate('Y-m-d'),
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
            : $existing->name;
        DB::table('categories')->where('id', $decodedId)->update(['name' => $name]);

        return response()->json(DB::table('categories')->where('id', $decodedId)->first());
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

        DB::transaction(function () use ($decodedId) {
            $children = DB::table('categories')->where('parentId', $decodedId)->pluck('id')->all();
            if (count($children) > 0) {
                DB::table('categories')->whereIn('parentId', $children)->delete();
                DB::table('categories')->whereIn('id', $children)->delete();
            }
            DB::table('categories')->where('id', $decodedId)->delete();
        });

        return response()->json(['success' => true]);
    }
}
