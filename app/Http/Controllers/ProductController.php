<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'sku.view', 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $rows = DB::table('products')->orderBy('brandName')->orderBy('sku')->get();

        $result = [];
        foreach ($rows as $rowObj) {
            $p = (array) $rowObj;
            if (empty($scope['isGlobal']) && !RbacService::canScopeAccessBrand($scope, $p['brandName'] ?? null, $p['brandId'] ?? null)) {
                continue;
            }
            $p['skuCode'] = $p['skuCode'] ?: ('SKU-' . strtoupper((string) $p['id']));
            $p['isB2B'] = (bool) ($p['isB2B'] ?? false);
            $result[] = $p;
        }

        return response()->json($result);
    }

    public function relational(Request $request, string $id): JsonResponse
    {
        if (!$this->canAny($request, 'sku.view', 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $prod = DB::table('products')->where('id', $decodedId)->first();
        if (!$prod) {
            $prod = DB::table('products')->whereRaw('LOWER(sku) = LOWER(?)', [$decodedId])->first();
        }
        if (!$prod) {
            return response()->json(['error' => 'Produk tidak ditemukan'], 404);
        }

        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $prod->brandName, $prod->brandId)) {
            return $denied;
        }

        $brandRow = DB::table('brands')
            ->select(['id', 'name', 'logo', 'ownerId', 'status', 'description'])
            ->where(function ($q) use ($prod) {
                if ($prod->brandId) {
                    $q->where('id', $prod->brandId)->orWhereRaw('LOWER(name) = LOWER(?)', [$prod->brandName]);
                } else {
                    $q->whereRaw('LOWER(name) = LOWER(?)', [$prod->brandName]);
                }
            })
            ->first();

        $user = null;
        if ($brandRow && !empty($brandRow->ownerId)) {
            $u = DB::table('users')
                ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status'])
                ->where('id', $brandRow->ownerId)
                ->first();
            if ($u) {
                $user = (array) $u;
            }
        }

        $brand = null;
        if ($brandRow) {
            $brand = [
                ...(array) $brandRow,
                'ownerName' => $user['name'] ?? 'Belum Ditentukan',
                'ownerEmail' => $user['email'] ?? null,
                'ownerRole' => isset($user['role']) ? RbacService::normalizeRole($user['role']) : null,
            ];
        }

        $brandTargetName = $brand['name'] ?? $prod->brandName;
        $batches = DB::table('batches')
            ->select(['id', 'batchId', 'date', 'project', 'sku', 'brand', 'qty', 'specs', 'pinLength', 'status', 'isB2B', 'redirectUrl', 'qrType', 'requirePin', 'enableGps'])
            ->whereRaw('LOWER(sku) = LOWER(?)', [$prod->sku])
            ->where(function ($q) use ($prod, $brandTargetName) {
                $q->whereRaw('LOWER(brand) = LOWER(?)', [$prod->brandName])
                    ->orWhereRaw('LOWER(brand) = LOWER(?)', [$brandTargetName]);
            })
            ->orderByDesc('date')
            ->get();

        $batchIds = $batches->pluck('id')->all();
        $tagStats = ['totalTags' => 0, 'usedTags' => 0, 'availableTags' => 0];
        $projects = [];
        $warranties = [];
        $prodCodes = [];
        $sampleAllocatedTags = [];

        if (count($batchIds) > 0) {
            $statsRow = DB::table('tags')
                ->whereIn('batchId', $batchIds)
                ->selectRaw("COUNT(*) as total_tags, SUM(CASE WHEN status = 'Telah Digunakan' THEN 1 ELSE 0 END) as used_tags, SUM(CASE WHEN status = 'Tersedia' THEN 1 ELSE 0 END) as available_tags")
                ->first();
            if ($statsRow) {
                $tagStats = [
                    'totalTags' => (int) ($statsRow->total_tags ?? 0),
                    'usedTags' => (int) ($statsRow->used_tags ?? 0),
                    'availableTags' => (int) ($statsRow->available_tags ?? 0),
                ];
            }

            $projects = DB::table('tags')
                ->whereIn('batchId', $batchIds)
                ->whereNotNull('project')
                ->where('project', '!=', '')
                ->where('project', '!=', '-')
                ->distinct()
                ->pluck('project')
                ->all();
            $warranties = DB::table('tags')
                ->whereIn('batchId', $batchIds)
                ->whereNotNull('warranty')
                ->where('warranty', '!=', '')
                ->where('warranty', '!=', '-')
                ->distinct()
                ->pluck('warranty')
                ->all();
            $prodCodes = DB::table('tags')
                ->whereIn('batchId', $batchIds)
                ->whereNotNull('prodCode')
                ->where('prodCode', '!=', '')
                ->where('prodCode', '!=', '-')
                ->distinct()
                ->pluck('prodCode')
                ->all();
            $sampleAllocatedTags = DB::table('tags')
                ->select(['tagNumber', 'pin', 'status', 'project', 'warranty', 'prodCode', 'note', 'allocatedAt'])
                ->whereIn('batchId', $batchIds)
                ->where('status', 'Telah Digunakan')
                ->limit(6)
                ->get();
        }

        $escapedSku = '%' . SecurityHardeningService::escapeLikePattern(strtolower((string) $prod->sku)) . '%';
        $wrappedProductType = DB::getQueryGrammar()->wrap('productType');
        $scanRows = DB::table('scan_points')
            ->select(['city', 'province', 'venue', 'totalScans', 'todayScans', 'status', 'latestTime', 'deviceInfo'])
            ->where(function ($q) use ($prod, $brandTargetName) {
                $q->whereRaw('LOWER(brand) = LOWER(?)', [$prod->brandName])
                    ->orWhereRaw('LOWER(brand) = LOWER(?)', [$brandTargetName]);
            })
            ->where(function ($q) use ($escapedSku, $batchIds, $wrappedProductType) {
                $q->whereRaw("LOWER({$wrappedProductType}) LIKE ? ESCAPE '!'", [$escapedSku]);
                if (count($batchIds) > 0) {
                    $q->orWhereIn('batchId', $batchIds);
                }
            })
            ->orderByDesc('totalScans')
            ->limit(4)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['totalScans'] = (int) ($arr['totalScans'] ?? 0);
                $arr['todayScans'] = (int) ($arr['todayScans'] ?? 0);
                return $arr;
            })
            ->all();

        $totalScanSum = 0;
        foreach ($scanRows as $sr) {
            $totalScanSum += $sr['totalScans'];
        }

        $formattedBatches = $batches->map(function ($row) {
            $arr = (array) $row;
            $arr['qty'] = (int) ($arr['qty'] ?? 0);
            $arr['pinLength'] = (int) ($arr['pinLength'] ?? 8);
            $arr['isB2B'] = (bool) ($arr['isB2B'] ?? false);
            $arr['requirePin'] = $arr['requirePin'] === null ? true : (bool) $arr['requirePin'];
            $arr['enableGps'] = $arr['enableGps'] === null ? true : (bool) $arr['enableGps'];
            return $arr;
        })->all();

        $prodArr = (array) $prod;
        $prodArr['skuCode'] = $prodArr['skuCode'] ?: ('SKU-' . strtoupper((string) $prod->id));
        $prodArr['isB2B'] = (bool) ($prodArr['isB2B'] ?? false);

        return response()->json([
            'product' => $prodArr,
            'brand' => $brand,
            'user' => $user,
            'batches' => $formattedBatches,
            'activeBatchesCount' => $batches->filter(fn ($b) => ($b->status ?? '') !== 'Disuspend')->count(),
            'suspendedBatchesCount' => $batches->filter(fn ($b) => ($b->status ?? '') === 'Disuspend')->count(),
            'tagStats' => $tagStats,
            'projects' => $projects,
            'warranties' => $warranties,
            'prodCodes' => $prodCodes,
            'sampleAllocatedTags' => $sampleAllocatedTags,
            'scanActivity' => [
                'totalScans' => $totalScanSum,
                'locations' => $scanRows,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can($request, 'sku.manage')) {
            return $this->deny($request);
        }

        $p = $request->all();
        $cleanBrandName = SecurityHardeningService::sanitizeStringInput($p['brandName'] ?? '', 120);
        $cleanBrandId = !empty($p['brandId']) ? SecurityHardeningService::sanitizeStringInput($p['brandId'], 80) : null;
        $cleanSku = SecurityHardeningService::sanitizeStringInput($p['sku'] ?? '', 200);

        if ($cleanBrandName === '' || $cleanSku === '') {
            return response()->json(['error' => 'Nama brand dan nama SKU wajib diisi.'], 400);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $cleanBrandName, $cleanBrandId)) {
            return $denied;
        }

        $imgCheck = SecurityHardeningService::validateSafeImageSource($p['image'] ?? null);
        if (!$imgCheck['valid']) {
            return response()->json(['error' => $imgCheck['error'] ?? 'Format gambar produk tidak valid.'], 400);
        }

        $cleanId = SecurityHardeningService::sanitizeStringInput($p['id'] ?? '', 80)
            ?: ('prod-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)));
        $createdAt = SecurityHardeningService::sanitizeStringInput($p['createdAt'] ?? '', 80) ?: RbacService::formatIndonesianTimestamp();
        $skuCode = SecurityHardeningService::sanitizeStringInput($p['skuCode'] ?? '', 80)
            ?: ('SKU-' . substr((string) (int) round(microtime(true) * 1000), -4));
        $cleanCategory = SecurityHardeningService::sanitizeStringInput($p['category'] ?? '', 400) ?: 'Umum';
        $cleanDesc = !empty($p['description']) ? SecurityHardeningService::sanitizeStringInput($p['description'], 1500) : null;
        $isB2B = !empty($p['isB2B']) ? 1 : 0;

        DB::table('products')->insert([
            'id' => $cleanId,
            'brandId' => $cleanBrandId,
            'brandName' => $cleanBrandName,
            'sku' => $cleanSku,
            'skuCode' => $skuCode,
            'category' => $cleanCategory,
            'isB2B' => $isB2B,
            'description' => $cleanDesc,
            'createdAt' => $createdAt,
            'image' => $imgCheck['value'],
        ]);

        return response()->json([
            'id' => $cleanId,
            'brandId' => $cleanBrandId,
            'brandName' => $cleanBrandName,
            'sku' => $cleanSku,
            'skuCode' => $skuCode,
            'category' => $cleanCategory,
            'description' => $cleanDesc,
            'createdAt' => $createdAt,
            'isB2B' => (bool) $isB2B,
            'image' => $imgCheck['value'],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'sku.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('products')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Product tidak ditemukan'], 404);
        }

        $scope = $this->brandScope($request);
        if ($denied = RbacService::assertBrandAccess($scope, $existing->brandName, $existing->brandId)) {
            return $denied;
        }

        $p = $request->all();
        $brandId = array_key_exists('brandId', $p)
            ? (!empty($p['brandId']) ? SecurityHardeningService::sanitizeStringInput($p['brandId'], 80) : null)
            : $existing->brandId;
        $brandName = array_key_exists('brandName', $p)
            ? SecurityHardeningService::sanitizeStringInput($p['brandName'], 120)
            : $existing->brandName;

        if ($denied = RbacService::assertBrandAccess($scope, $brandName, $brandId)) {
            return $denied;
        }

        $nextImage = $existing->image;
        if (array_key_exists('image', $p)) {
            $imgCheck = SecurityHardeningService::validateSafeImageSource($p['image']);
            if (!$imgCheck['valid']) {
                return response()->json(['error' => $imgCheck['error'] ?? 'Format gambar produk tidak valid.'], 400);
            }
            $nextImage = $imgCheck['value'];
        }

        $sku = array_key_exists('sku', $p) ? SecurityHardeningService::sanitizeStringInput($p['sku'], 200) : $existing->sku;
        $skuCode = array_key_exists('skuCode', $p)
            ? SecurityHardeningService::sanitizeStringInput($p['skuCode'], 80)
            : ($existing->skuCode ?: ('SKU-' . strtoupper($decodedId)));
        $category = array_key_exists('category', $p) ? SecurityHardeningService::sanitizeStringInput($p['category'], 400) : $existing->category;
        $isB2B = array_key_exists('isB2B', $p) ? (!empty($p['isB2B']) ? 1 : 0) : (int) $existing->isB2B;
        $description = array_key_exists('description', $p)
            ? (!empty($p['description']) ? SecurityHardeningService::sanitizeStringInput($p['description'], 1500) : null)
            : $existing->description;

        DB::table('products')->where('id', $decodedId)->update([
            'brandId' => $brandId,
            'brandName' => $brandName,
            'sku' => $sku,
            'skuCode' => $skuCode,
            'category' => $category,
            'isB2B' => $isB2B,
            'description' => $description,
            'image' => $nextImage,
        ]);

        return response()->json([
            'id' => $decodedId,
            'brandId' => $brandId,
            'brandName' => $brandName,
            'sku' => $sku,
            'skuCode' => $skuCode,
            'category' => $category,
            'isB2B' => (bool) $isB2B,
            'description' => $description,
            'image' => $nextImage,
            'createdAt' => $existing->createdAt,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'sku.manage')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('products')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Product tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $existing->brandName, $existing->brandId)) {
            return $denied;
        }

        DB::table('products')->where('id', $decodedId)->delete();
        return response()->json(['success' => true]);
    }
}
