<?php

namespace App\Http\Controllers;

use App\Services\SecurityHardeningService;
use App\Services\SecurityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class QrVerificationController extends Controller
{
    public function show(Request $request, string $tagNumber): Response
    {
        $decodedTag = urldecode($tagNumber);
        $tag = DB::table('tags')->where('tagNumber', $decodedTag)->first();

        if (!$tag) {
            $safeTag = SecurityHardeningService::escapeHtml($decodedTag);
            $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tag Tidak Terdaftar - Verifikasi Keaslian</title>
    <style>
      body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
      .card { background: #1e293b; border: 1px solid #334155; border-radius: 20px; padding: 32px 24px; max-width: 420px; width: 100%; text-align: center; }
      h2 { color: #f43f5e; margin: 0 0 12px; font-size: 20px; }
      p { color: #94a3b8; font-size: 14px; line-height: 1.6; margin: 0 0 20px; }
      .badge { display: inline-block; padding: 6px 14px; border-radius: 999px; background: rgba(244, 63, 94, 0.15); color: #fb7185; font-family: monospace; font-size: 13px; font-weight: bold; border: 1px solid rgba(244, 63, 94, 0.3); }
    </style>
  </head>
  <body>
    <div class="card">
      <h2>Tag Tidak Terdaftar</h2>
      <p>Nomor tag fisik berikut tidak terdata pada sistem verifikasi keamanan:</p>
      <div class="badge">{$safeTag}</div>
    </div>
  </body>
</html>
HTML;
            return response($html, 404)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $batch = DB::table('batches')->where('id', $tag->batchId)->first();
        $rawTarget = ($batch && !empty(trim((string) $batch->redirectUrl))) ? trim((string) $batch->redirectUrl) : 'https://cek.sumas.id/';
        $targetUrl = preg_match('/^https?:\/\//i', $rawTarget) ? $rawTarget : 'https://cek.sumas.id/';
        $settings = SecurityPolicyService::loadActiveSettings();

        $effectiveMode = ($batch && (int) $batch->enableGps === 0)
            ? 'flexible'
            : ($settings['locationPolicy']['mode'] ?? 'hybrid');

        if ($effectiveMode === 'hybrid' || $effectiveMode === 'strict') {
            $html = SecurityPolicyService::buildQrVerificationHtml([
                'tagNumber' => $tag->tagNumber,
                'brand' => $batch->brand ?? 'Produk',
                'sku' => $batch->sku ?? '',
                'mode' => $effectiveMode,
                'maxAccuracyMeters' => $settings['locationPolicy']['maxAccuracyMeters'] ?? 150,
            ]);
            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Cache-Control', 'no-store');
        }

        $geoIp = SecurityPolicyService::getGeoIpFromRequest($request);
        $existingRow = DB::table('scan_points')->where('latestTagId', $tag->tagNumber)->orderByDesc('id')->first();
        $existingHistory = [];
        if ($existingRow && !empty($existingRow->scanHistory)) {
            $decoded = json_decode((string) $existingRow->scanHistory, true);
            if (is_array($decoded)) {
                $existingHistory = $decoded;
            }
        }

        $evaluation = SecurityPolicyService::evaluateScanAgainstPolicy([
            'settings' => $settings,
            'batchEnableGps' => false,
            'existingTotalScans' => $existingRow ? (int) ($existingRow->totalScans ?? 0) : 0,
            'existingTodayScans' => $existingRow ? (int) ($existingRow->todayScans ?? 0) : 0,
            'existingStatus' => $existingRow->status ?? null,
            'existingHistory' => $existingHistory,
            'gpsGranted' => false,
            'geoIp' => $geoIp,
            'ipAddress' => SecurityHardeningService::getSafeClientIp($request),
            'isp' => $geoIp['isp'],
            'userAgent' => (string) $request->userAgent(),
        ]);

        $finalStatus = ($batch->status ?? '') === 'Disuspend' ? 'suspicious' : $evaluation['status'];
        $updatedHistory = $evaluation['withinCooldown']
            ? $existingHistory
            : array_slice(array_merge([$evaluation['historyEntry']], $existingHistory), 0, 50);

        try {
            if ($existingRow) {
                DB::table('scan_points')->where('id', $existingRow->id)->update([
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
                DB::table('scan_points')->insert([
                    'id' => 'scan-' . (int) round(microtime(true) * 1000),
                    'city' => $evaluation['city'],
                    'province' => $evaluation['province'],
                    'venue' => $evaluation['venue'],
                    'lat' => $evaluation['lat'],
                    'lng' => $evaluation['lng'],
                    'totalScans' => $evaluation['newTotalScans'],
                    'todayScans' => $evaluation['newTodayScans'],
                    'brand' => $batch->brand ?? 'Sumas',
                    'productType' => $batch->sku ?? 'Produk',
                    'batchId' => $batch->batchId ?? ($batch->id ?? ''),
                    'latestTagId' => $tag->tagNumber,
                    'pin' => $tag->pin ?? null,
                    'latestTime' => $evaluation['historyEntry']['scannedAt'],
                    'isB2B' => !empty($batch->isB2B) ? 1 : 0,
                    'status' => $finalStatus,
                    'anomalyReason' => $evaluation['anomalyReasonText'],
                    'deviceInfo' => $evaluation['historyEntry']['device'],
                    'scanHistory' => json_encode($updatedHistory),
                    'createdAt' => gmdate('Y-m-d'),
                ]);
            }
            SecurityPolicyService::syncScanActivitiesTable();
        } catch (\Throwable) {
        }

        $sep = str_contains($targetUrl, '?') ? '&' : '?';
        $redirectTarget = $targetUrl . $sep
            . 'tag=' . urlencode((string) $tag->tagNumber)
            . '&pin=' . urlencode((string) ($tag->pin ?? ''))
            . '&batch=' . urlencode((string) ($batch->batchId ?? ''))
            . '&sku=' . urlencode((string) ($batch->sku ?? ''))
            . '&brand=' . urlencode((string) ($batch->brand ?? ''))
            . '&status=' . urlencode($finalStatus);

        return response('', 302)->header('Location', $redirectTarget);
    }

    public function verify(Request $request, string $tagNumber): JsonResponse
    {
        return app(ScanController::class)->record($request, $tagNumber);
    }
}
