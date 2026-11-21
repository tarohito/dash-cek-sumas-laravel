<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'dashboard.view', 'scans.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $isGlobal = !empty($scope['isGlobal']);

        $allBatches = DB::table('batches')->orderByDesc('date')->orderByDesc('id')->get();
        $scopedBatches = [];
        foreach ($allBatches as $b) {
            if ($isGlobal || RbacService::canScopeAccessBrand($scope, $b->brand ?? null)) {
                $scopedBatches[] = (array) $b;
            }
        }

        $allProducts = DB::table('products')->select(['id', 'brandId', 'brandName'])->get();
        $scopedProductsCount = 0;
        foreach ($allProducts as $p) {
            if ($isGlobal || RbacService::canScopeAccessBrand($scope, $p->brandName ?? null, $p->brandId ?? null)) {
                $scopedProductsCount++;
            }
        }

        $allBrands = DB::table('brands')->select(['id', 'name', 'status'])->get();
        $scopedBrands = [];
        foreach ($allBrands as $br) {
            if ($isGlobal || RbacService::canScopeAccessBrand($scope, $br->name ?? null, $br->id ?? null)) {
                $scopedBrands[] = (array) $br;
            }
        }

        $allScans = DB::table('scan_points')->select(['city', 'brand', 'totalScans', 'todayScans', 'status'])->get();
        $scopedScans = [];
        foreach ($allScans as $s) {
            if ($isGlobal || RbacService::canScopeAccessBrand($scope, $s->brand ?? null)) {
                $scopedScans[] = (array) $s;
            }
        }

        $scopedBatchCodeSet = [];
        foreach ($scopedBatches as $b) {
            $scopedBatchCodeSet[$b['batchId']] = true;
        }

        $logMetaRows = DB::table('audit_logs')->select(['id', 'batchId'])->orderByDesc('id')->limit(200)->get();
        $scopedLogIds = [];
        foreach ($logMetaRows as $l) {
            if ($isGlobal || isset($scopedBatchCodeSet[$l->batchId])) {
                $scopedLogIds[] = $l->id;
            }
        }
        $totalScopedLogs = count($scopedLogIds);
        $topLogIds = array_slice($scopedLogIds, 0, 5);
        $topLogRows = count($topLogIds) > 0
            ? DB::table('audit_logs')->whereIn('id', $topLogIds)->orderByDesc('id')->get()
            : collect();

        $tagCountsByBatch = DB::table('tags')
            ->select('batchId')
            ->selectRaw('COUNT(*) as total_count')
            ->groupBy('batchId')
            ->pluck('total_count', 'batchId');

        $brandStatsMap = [];
        $activeBatches = 0;
        $suspendedBatches = 0;
        $totalTags = 0;
        $b2bBatches = 0;
        $retailBatches = 0;

        foreach ($scopedBatches as $b) {
            $key = $b['brand'] ?: 'Lainnya';
            if (!isset($brandStatsMap[$key])) {
                $brandStatsMap[$key] = ['brand' => $key, 'batchCount' => 0, 'totalQty' => 0];
            }
            $realTagCount = (int) ($tagCountsByBatch[$b['id']] ?? 0);
            $brandStatsMap[$key]['batchCount']++;
            $brandStatsMap[$key]['totalQty'] += $realTagCount;

            if (($b['status'] ?? '') === 'Disuspend') {
                $suspendedBatches++;
            } else {
                $activeBatches++;
            }
            $totalTags += $realTagCount;
            if (!empty($b['isB2B'])) {
                $b2bBatches++;
            } else {
                $retailBatches++;
            }
        }

        $recentLogs = [];
        foreach ($topLogRows as $rowObj) {
            $r = (array) $rowObj;
            $changes = json_decode((string) ($r['changes'] ?? '[]'), true);
            $r['changes'] = is_array($changes) ? $changes : [];
            $recentLogs[] = $r;
        }

        $recentBatches = [];
        foreach (array_slice($scopedBatches, 0, 5) as $row) {
            $row['isB2B'] = (bool) ($row['isB2B'] ?? false);
            $row['tags'] = null;
            $recentBatches[] = $row;
        }

        $totalScans = 0;
        $todayScans = 0;
        $distinctCities = [];
        $validScans = 0;
        foreach ($scopedScans as $s) {
            $totalScans += (int) ($s['totalScans'] ?? 0);
            $todayScans += (int) ($s['todayScans'] ?? 0);
            if (!empty($s['city'])) {
                $distinctCities[$s['city']] = true;
            }
            if (($s['status'] ?? '') === 'valid') {
                $validScans++;
            }
        }

        $activeBrands = 0;
        foreach ($scopedBrands as $br) {
            if (($br['status'] ?? '') === 'Aktif') {
                $activeBrands++;
            }
        }

        return response()->json([
            'totalBatches' => count($scopedBatches),
            'activeBatches' => $activeBatches,
            'suspendedBatches' => $suspendedBatches,
            'totalTags' => $totalTags,
            'b2bBatches' => $b2bBatches,
            'retailBatches' => $retailBatches,
            'totalLogs' => $totalScopedLogs,
            'totalProducts' => $scopedProductsCount,
            'totalBrands' => count($scopedBrands),
            'activeBrands' => $activeBrands,
            'totalScans' => $totalScans,
            'todayScans' => $todayScans,
            'distinctCities' => count($distinctCities),
            'totalScanPoints' => count($scopedScans),
            'validScans' => $validScans,
            'brandStats' => array_values($brandStatsMap),
            'recentLogs' => $recentLogs,
            'recentBatches' => $recentBatches,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
