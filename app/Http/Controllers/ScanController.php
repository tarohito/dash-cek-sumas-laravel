<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\SecurityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public function locations(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'dashboard.view', 'scans.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $rows = DB::table('scan_points')->orderBy('id')->get();
        $result = [];

        foreach ($rows as $rowObj) {
            $r = (array) $rowObj;
            if (empty($scope['isGlobal']) && !RbacService::canScopeAccessBrand($scope, $r['brand'] ?? null)) {
                continue;
            }
            $history = [];
            if (!empty($r['scanHistory'])) {
                $decoded = is_string($r['scanHistory']) ? json_decode($r['scanHistory'], true) : $r['scanHistory'];
                if (is_array($decoded)) {
                    $history = $decoded;
                }
            }
            $r['lat'] = (float) ($r['lat'] ?? 0);
            $r['lng'] = (float) ($r['lng'] ?? 0);
            $r['totalScans'] = (int) ($r['totalScans'] ?? 0);
            $r['todayScans'] = (int) ($r['todayScans'] ?? 0);
            $r['isB2B'] = (bool) ($r['isB2B'] ?? false);
            $r['scanHistory'] = $history;
            $result[] = $r;
        }

        return response()->json($result);
    }

    public function stats(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'dashboard.view', 'scans.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $rows = DB::table('scan_points')->select(['brand', 'status', 'totalScans', 'todayScans'])->get();

        $totalLocations = 0;
        $totalScans = 0;
        $todayScans = 0;
        $valid = 0;
        $repeated = 0;
        $suspicious = 0;

        foreach ($rows as $r) {
            if (empty($scope['isGlobal']) && !RbacService::canScopeAccessBrand($scope, $r->brand ?? null)) {
                continue;
            }
            $totalLocations++;
            $totalScans += (int) ($r->totalScans ?? 0);
            $todayScans += (int) ($r->todayScans ?? 0);
            if ($r->status === 'valid') {
                $valid++;
            } elseif ($r->status === 'repeated') {
                $repeated++;
            } elseif ($r->status === 'suspicious') {
                $suspicious++;
            }
        }

        return response()->json([
            'totalLocations' => $totalLocations,
            'totalScans' => $totalScans,
            'todayScans' => $todayScans,
            'valid' => $valid,
            'repeated' => $repeated,
            'suspicious' => $suspicious,
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'dashboard.view', 'scans.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $range = SecurityHardeningService::sanitizeStringInput($request->query('range', '7d'), 10);
        $requestedBrand = SecurityHardeningService::sanitizeStringInput($request->query('brand', ''), 120);

        if (empty($scope['isGlobal']) && $requestedBrand !== '' && !RbacService::canScopeAccessBrand($scope, $requestedBrand)) {
            return response()->json(['error' => 'Akses ditolak untuk data aktivitas brand lain.'], 403);
        }

        $rows = DB::table('scan_points')
            ->select(['id', 'brand', 'totalScans', 'todayScans', 'status', 'latestTime', 'createdAt', 'scanHistory'])
            ->get();

        $scopedRows = [];
        foreach ($rows as $r) {
            if (!empty($scope['isGlobal']) || RbacService::canScopeAccessBrand($scope, $r->brand ?? null)) {
                $scopedRows[] = $r;
            }
        }

        return response()->json(SecurityPolicyService::aggregateScanActivityFromPoints($scopedRows, $range, $requestedBrand));
    }

    public function record(Request $request, ?string $tagNumber = null): JsonResponse
    {
        $isVerifyRoute = $tagNumber !== null;
        $s = $request->all();
        $tagLookup = $isVerifyRoute
            ? urldecode($tagNumber)
            : (string) ($s['latestTagId'] ?? ($s['tagNumber'] ?? ''));

        $tagRecord = null;
        $batchRecord = null;
        if ($tagLookup !== '') {
            $tagRecord = DB::table('tags')->where('tagNumber', $tagLookup)->first();
            if ($tagRecord) {
                $batchRecord = DB::table('batches')->where('id', $tagRecord->batchId)->first();
            }
        }

        if ($isVerifyRoute && !$tagRecord) {
            return response()->json(['success' => false, 'message' => 'Nomor tag fisik tidak ditemukan.'], 404);
        }

        $settings = SecurityPolicyService::loadActiveSettings();
        $geoIp = SecurityPolicyService::getGeoIpFromRequest($request);
        $existingRow = $tagLookup !== ''
            ? DB::table('scan_points')->where('latestTagId', $tagLookup)->orderByDesc('id')->first()
            : null;

        $existingHistory = [];
        if ($existingRow && !empty($existingRow->scanHistory)) {
            $decoded = json_decode((string) $existingRow->scanHistory, true);
            if (is_array($decoded)) {
                $existingHistory = $decoded;
            }
        }

        $latVal = isset($s['lat']) && $s['lat'] !== '' && is_numeric($s['lat']) ? (float) $s['lat'] : null;
        $lngVal = isset($s['lng']) && $s['lng'] !== '' && is_numeric($s['lng']) ? (float) $s['lng'] : null;
        $gpsGranted = isset($s['gpsGranted']) && is_bool($s['gpsGranted'])
            ? $s['gpsGranted']
            : ($latVal !== null && $lngVal !== null);

        $evaluation = SecurityPolicyService::evaluateScanAgainstPolicy([
            'settings' => $settings,
            'batchEnableGps' => $batchRecord ? ((int) $batchRecord->enableGps !== 0) : true,
            'existingTotalScans' => $existingRow ? (int) ($existingRow->totalScans ?? 0) : 0,
            'existingTodayScans' => $existingRow ? (int) ($existingRow->todayScans ?? 0) : 0,
            'existingStatus' => $existingRow->status ?? null,
            'existingHistory' => $existingHistory,
            'gpsGranted' => $gpsGranted,
            'lat' => $latVal,
            'lng' => $lngVal,
            'accuracyMeters' => isset($s['accuracyMeters']) && is_numeric($s['accuracyMeters']) ? (float) $s['accuracyMeters'] : null,
            'speedMps' => isset($s['speedMps']) && is_numeric($s['speedMps']) ? (float) $s['speedMps'] : null,
            'isMockLocation' => !empty($s['isMockLocation']),
            'webdriver' => !empty($s['webdriver']),
            'city' => $s['city'] ?? null,
            'province' => $s['province'] ?? null,
            'venue' => $s['venue'] ?? null,
            'countryCode' => $s['countryCode'] ?? null,
            'geoIp' => $geoIp,
            'ipAddress' => SecurityHardeningService::getSafeClientIp($request),
            'isp' => $geoIp['isp'],
            'userAgent' => (string) $request->userAgent(),
            'deviceInfo' => $s['deviceInfo'] ?? null,
        ]);

        if (!empty($evaluation['blocked'])) {
            return response()->json([
                'success' => false,
                'blocked' => true,
                'code' => $evaluation['blockCode'],
                'message' => $evaluation['blockMessage'],
                'error' => $evaluation['blockMessage'],
            ], 403);
        }

        $finalStatus = ($batchRecord->status ?? '') === 'Disuspend' ? 'suspicious' : $evaluation['status'];
        $updatedHistory = $evaluation['withinCooldown']
            ? $existingHistory
            : array_slice(array_merge([$evaluation['historyEntry']], $existingHistory), 0, 50);

        $scanBrand = (string) ($s['brand'] ?? ($batchRecord->brand ?? 'Umum'));
        $scanProduct = (string) ($s['productType'] ?? ($batchRecord->sku ?? $scanBrand));

        if ($existingRow) {
            $scanId = (string) $existingRow->id;
            DB::table('scan_points')->where('id', $scanId)->update([
                'city' => $evaluation['city'],
                'province' => $evaluation['province'],
                'venue' => $evaluation['venue'],
                'lat' => $evaluation['lat'],
                'lng' => $evaluation['lng'],
                'totalScans' => $evaluation['newTotalScans'],
                'todayScans' => $evaluation['newTodayScans'],
                'latestTime' => $evaluation['historyEntry']['scannedAt'],
                'status' => $finalStatus,
                'anomalyReason' => $evaluation['anomalyReasonText'],
                'deviceInfo' => $evaluation['historyEntry']['device'],
                'scanHistory' => json_encode($updatedHistory),
            ]);
        } else {
            $scanId = (string) ($s['id'] ?? ('scan-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(2))));
            DB::table('scan_points')->insert([
                'id' => $scanId,
                'city' => $evaluation['city'],
                'province' => $evaluation['province'],
                'venue' => $evaluation['venue'],
                'lat' => $evaluation['lat'],
                'lng' => $evaluation['lng'],
                'totalScans' => $evaluation['newTotalScans'],
                'todayScans' => $evaluation['newTodayScans'],
                'brand' => $scanBrand,
                'productType' => $scanProduct,
                'batchId' => $s['batchId'] ?? ($batchRecord->batchId ?? ''),
                'latestTagId' => $tagLookup,
                'pin' => $s['pin'] ?? ($tagRecord->pin ?? null),
                'latestTime' => $evaluation['historyEntry']['scannedAt'],
                'isB2B' => !empty($s['isB2B'] ?? ($batchRecord->isB2B ?? false)) ? 1 : 0,
                'status' => $finalStatus,
                'anomalyReason' => $evaluation['anomalyReasonText'],
                'deviceInfo' => $evaluation['historyEntry']['device'],
                'scanHistory' => json_encode($updatedHistory),
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        $isAnomalyScan = $finalStatus === 'suspicious' || !empty($evaluation['impossibleTravelDetected']);
        NotificationService::createNotification([
            'type' => 'scan',
            'severity' => $isAnomalyScan ? 'critical' : ($finalStatus === 'repeated' ? 'warning' : 'info'),
            'audience' => 'brand_scope',
            'brandName' => $scanBrand,
            'title' => $isAnomalyScan
                ? "Anomali Scan Terdeteksi ({$scanProduct})"
                : ($finalStatus === 'repeated' ? "Scan Berulang ({$scanProduct})" : "Aktivitas Scan Valid ({$scanProduct})"),
            'subtitle' => ($evaluation['venue'] ? "{$evaluation['venue']} · " : '') . "{$evaluation['city']}, {$evaluation['province']}",
            'description' => $isAnomalyScan
                ? "Tag " . ($tagLookup ?: '-') . " ({$scanBrand}) terindikasi mencurigakan · {$evaluation['newTotalScans']}x total scan"
                : "Tag " . ($tagLookup ?: '-') . " ({$scanBrand}) dipindai · {$evaluation['newTodayScans']}x hari ini",
            'entityType' => 'scan',
            'entityId' => $scanId,
            'status' => $finalStatus,
            'dedupeKey' => "scan:{$scanId}:{$finalStatus}:{$evaluation['newTotalScans']}",
            'dedupeWindowMs' => 20000,
            'metadata' => [
                'brand' => $scanBrand,
                'city' => $evaluation['city'],
                'province' => $evaluation['province'],
                'totalScans' => $evaluation['newTotalScans'],
                'todayScans' => $evaluation['newTodayScans'],
            ],
        ]);

        $rawTarget = ($batchRecord && !empty(trim((string) $batchRecord->redirectUrl)))
            ? trim((string) $batchRecord->redirectUrl)
            : 'https://cek.sumas.id/';
        $targetUrl = preg_match('/^https?:\/\//i', $rawTarget) ? $rawTarget : 'https://cek.sumas.id/';
        $sep = str_contains($targetUrl, '?') ? '&' : '?';
        $redirectUrl = $targetUrl . $sep
            . 'tag=' . urlencode($tagLookup)
            . '&pin=' . urlencode((string) ($tagRecord->pin ?? ''))
            . '&batch=' . urlencode((string) ($batchRecord->batchId ?? ''))
            . '&sku=' . urlencode((string) ($batchRecord->sku ?? ''))
            . '&brand=' . urlencode((string) ($batchRecord->brand ?? ''))
            . '&status=' . urlencode($finalStatus);

        return response()->json([
            'success' => true,
            'id' => $scanId,
            'scanId' => $scanId,
            'redirectUrl' => $redirectUrl,
            'status' => $finalStatus,
            'anomalyReason' => $evaluation['anomalyReasonText'],
            'withinCooldown' => $evaluation['withinCooldown'],
            'totalScans' => $evaluation['newTotalScans'],
            'locationSource' => $evaluation['locationSource'],
            'city' => $evaluation['city'],
            'province' => $evaluation['province'],
        ]);
    }
}
