<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\SecurityPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'settings.view', 'settings.manage')) {
            return $this->deny($request);
        }

        $row = DB::table('system_settings')->where('key', 'app_settings')->first(['value', 'updatedAt']);
        if ($row && !empty($row->value)) {
            $decoded = json_decode((string) $row->value, true);
            $merged = SecurityPolicyService::mergeSystemSettings(is_array($decoded) ? $decoded : []);
            return response()->json([...$merged, 'updatedAt' => $row->updatedAt]);
        }

        $defaults = SecurityPolicyService::mergeSystemSettings(SecurityPolicyService::defaultSystemSettings());
        return response()->json([...$defaults, 'updatedAt' => 'Belum pernah diubah']);
    }

    public function update(Request $request): JsonResponse
    {
        if (!$this->can($request, 'settings.manage')) {
            return $this->deny($request);
        }

        $merged = SecurityPolicyService::mergeSystemSettings($request->all());
        $gwUrl = trim((string) ($merged['notifications']['whatsappGatewayUrl'] ?? ''));
        if ($gwUrl !== '') {
            $urlCheck = SecurityHardeningService::validatePublicWebhookUrl($gwUrl);
            if (!$urlCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => 'URL WhatsApp Gateway tidak valid atau mengarah ke jaringan lokal.',
                ], 400);
            }
        }

        $updatedAt = RbacService::formatIndonesianTimestamp();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'app_settings'],
            ['value' => json_encode($merged), 'updatedAt' => $updatedAt]
        );
        Cache::forget(SecurityPolicyService::SETTINGS_CACHE_KEY);

        $authUser = $this->authUser($request);
        try {
            DB::table('audit_logs')->insert([
                'id' => (int) round(microtime(true) * 1000),
                'batchId' => 'SYSTEM-CONFIG',
                'timestamp' => $updatedAt,
                'user' => $authUser['name'],
                'reason' => 'Pembaruan Kebijakan Keamanan & Pengaturan Sistem',
                'changes' => json_encode([
                    [
                        'field' => 'Ambang Batas Anomali',
                        'old' => 'Versi Sebelumnya',
                        'new' => "{$merged['anomalyThreshold']['warningMaxScans']}x Waspada, >={$merged['anomalyThreshold']['suspiciousMinScans']}x Palsu",
                    ],
                    [
                        'field' => 'Mode Telemetri GPS',
                        'old' => 'Versi Sebelumnya',
                        'new' => $merged['locationPolicy']['mode'],
                    ],
                ]),
            ]);
        } catch (\Throwable) {
        }

        return response()->json(['success' => true, 'settings' => [...$merged, 'updatedAt' => $updatedAt]]);
    }

    public function testAlert(Request $request): JsonResponse
    {
        if (!$this->can($request, 'settings.manage')) {
            return $this->deny($request);
        }

        $channel = $request->input('channel');
        if (!in_array($channel, ['email', 'telegram', 'whatsapp'], true)) {
            return response()->json(['success' => false, 'error' => 'Channel notifikasi tidak valid.'], 400);
        }

        $now = RbacService::formatIndonesianTimestamp();
        if ($channel === 'telegram' || $channel === 'whatsapp') {
            $label = $channel === 'telegram' ? 'Telegram Bot' : 'WhatsApp Gateway';
            return response()->json([
                'success' => true,
                'skipped' => true,
                'message' => "Integrasi API {$label} dilewati sementara sehingga tidak mengirim permintaan ke server eksternal.",
                'timestamp' => $now,
            ]);
        }

        $active = SecurityPolicyService::loadActiveSettings();
        $configOverride = is_array($request->input('config')) ? $request->input('config') : [];
        $effectiveNotif = array_merge($active['notifications'], $configOverride);
        if ($request->filled('target')) {
            $effectiveNotif['emailRecipients'] = (string) $request->input('target');
        }

        $safeNow = SecurityHardeningService::escapeHtml($now);
        $result = NotificationService::sendAlertChannel(
            'email',
            $effectiveNotif,
            '[UJI COBA] Peringatan Keamanan Sistem Telemetri',
            "Uji coba pengiriman peringatan keamanan berhasil terhubung.\nChannel: Email Security\nWaktu: {$now}",
            "<b>[UJI COBA] Peringatan Keamanan</b>\nChannel: Email Security\nWaktu: {$safeNow}"
        );

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['detail'], 'timestamp' => $now], 400);
        }

        return response()->json(['success' => true, 'message' => $result['detail'], 'timestamp' => $now]);
    }

    public function backup(Request $request): Response|JsonResponse
    {
        if (!$this->can($request, 'settings.manage')) {
            return $this->deny($request);
        }

        $format = strtolower((string) $request->query('format', 'json'));
        $backupDate = RbacService::formatIndonesianTimestamp();
        $timestampIso = str_replace(':', '-', substr(gmdate('Y-m-d\TH:i:s'), 0, 19));

        if ($format === 'sqlite' || $format === 'db') {
            return response()->json([
                'error' => 'Pada server Laravel/MySQL (Niagahoster), gunakan format .sql atau .json untuk mencadangkan database.',
            ], 400);
        }

        $batches = DB::table('batches')->get()->map(fn ($r) => (array) $r)->all();
        $tags = DB::table('tags')->get()->map(fn ($r) => (array) $r)->all();
        $scanPoints = DB::table('scan_points')->get()->map(fn ($r) => (array) $r)->all();
        $scanActivities = DB::table('scan_activities')->get()->map(fn ($r) => (array) $r)->all();
        $categories = DB::table('categories')->get()->map(fn ($r) => (array) $r)->all();
        $brands = DB::table('brands')->get()->map(fn ($r) => (array) $r)->all();
        $products = DB::table('products')->get()->map(fn ($r) => (array) $r)->all();
        $auditLogs = DB::table('audit_logs')->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
        $passwordResetRequests = DB::table('password_reset_requests')->get()->map(fn ($r) => (array) $r)->all();
        $readNotifications = DB::table('read_notifications')->get()->map(fn ($r) => (array) $r)->all();
        $systemSettings = DB::table('system_settings')->get()->map(fn ($r) => (array) $r)->all();
        $trashItems = \Illuminate\Support\Facades\Schema::hasTable('trash_items')
            ? DB::table('trash_items')->get()->map(fn ($r) => (array) $r)->all()
            : [];
        $settingsRow = DB::table('system_settings')->where('key', 'app_settings')->first(['value']);

        try {
            $current = ($settingsRow && !empty($settingsRow->value))
                ? (json_decode((string) $settingsRow->value, true) ?: SecurityPolicyService::defaultSystemSettings())
                : SecurityPolicyService::mergeSystemSettings(SecurityPolicyService::defaultSystemSettings());
            $current['backupInfo'] = array_merge(
                SecurityPolicyService::defaultSystemSettings()['backupInfo'],
                is_array($current['backupInfo'] ?? null) ? $current['backupInfo'] : [],
                ['lastBackupDate' => $backupDate]
            );
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'app_settings'],
                ['value' => json_encode($current), 'updatedAt' => $backupDate]
            );
            Cache::forget(SecurityPolicyService::SETTINGS_CACHE_KEY);
        } catch (\Throwable) {
        }

        if ($format === 'mysql' || $format === 'sql') {
            $usersFull = DB::table('users')->get()->map(fn ($r) => (array) $r)->all();
            $esc = static function (mixed $val): string {
                if ($val === null) {
                    return 'NULL';
                }
                if (is_int($val) || is_float($val)) {
                    return is_finite((float) $val) ? (string) $val : 'NULL';
                }
                if (is_bool($val)) {
                    return $val ? '1' : '0';
                }
                $s = (string) $val;
                $s = str_replace(
                    ['\\', "\0", "\n", "\r", "\x1a", "'"],
                    ['\\\\', '\\0', '\\n', '\\r', '\\Z', "\\'"],
                    $s
                );
                return "'{$s}'";
            };
            $insertBlocks = static function (string $table, array $cols, array $rows) use ($esc): string {
                if (count($rows) === 0) {
                    return '';
                }
                $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
                $chunks = [];
                foreach (array_chunk($rows, 100) as $slice) {
                    $values = [];
                    foreach ($slice as $r) {
                        $vals = array_map(fn ($c) => $esc($r[$c] ?? null), $cols);
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $chunks[] = "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $values) . ';';
                }
                return implode("\n\n", $chunks) . "\n";
            };

            $blocks = array_filter([
                "-- SUMAS DATABASE BACKUP (MySQL / MariaDB - phpMyAdmin Ready)",
                "-- Dibuat pada: {$backupDate}",
                "SET NAMES utf8mb4;",
                "SET FOREIGN_KEY_CHECKS = 0;",
                $insertBlocks('batches', ['id', 'batchId', 'date', 'project', 'sku', 'brand', 'qty', 'specs', 'pinLength', 'status', 'isB2B', 'redirectUrl', 'qrType', 'requirePin', 'enableGps'], $batches),
                $insertBlocks('tags', ['id', 'batchId', 'tagNumber', 'pin', 'status', 'project', 'specs', 'codeType', 'warranty', 'prodCode', 'note', 'allocatedAt'], $tags),
                $insertBlocks('scan_points', ['id', 'city', 'province', 'venue', 'lat', 'lng', 'totalScans', 'todayScans', 'brand', 'productType', 'batchId', 'latestTagId', 'pin', 'latestTime', 'isB2B', 'status', 'anomalyReason', 'deviceInfo', 'scanHistory', 'createdAt'], $scanPoints),
                $insertBlocks('scan_activities', ['id', 'rangeType', 'label', 'fullDate', 'scans', 'valid', 'topBrand', 'sortOrder'], $scanActivities),
                $insertBlocks('audit_logs', ['id', 'batchId', 'timestamp', 'user', 'reason', 'changes'], $auditLogs),
                $insertBlocks('users', ['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt', 'password'], $usersFull),
                $insertBlocks('brands', ['id', 'name', 'logo', 'ownerId', 'description', 'businessType', 'status', 'createdAt'], $brands),
                $insertBlocks('products', ['id', 'brandId', 'brandName', 'sku', 'category', 'isB2B', 'description', 'createdAt', 'skuCode', 'image'], $products),
                $insertBlocks('password_reset_requests', ['id', 'userId', 'requestedAt', 'status', 'actionNote', 'createdAtTs'], $passwordResetRequests),
                $insertBlocks('read_notifications', ['userId', 'notificationId', 'readAt'], $readNotifications),
                $insertBlocks('categories', ['id', 'name', 'parentId', 'level', 'createdAt', 'specSchema'], $categories),
                $insertBlocks('system_settings', ['key', 'value', 'updatedAt'], $systemSettings),
                $insertBlocks('trash_items', ['id', 'entityType', 'entityId', 'title', 'subtitle', 'brandName', 'brandId', 'deletedById', 'deletedByName', 'deletedAt', 'deletedAtTs', 'expiresAtTs', 'itemCount', 'payload'], $trashItems),
                "SET FOREIGN_KEY_CHECKS = 1;",
            ]);

            $sqlDump = implode("\n\n", $blocks);
            return response($sqlDump, 200, [
                'Content-Type' => 'application/sql; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"sumas-backup-mysql-{$timestampIso}.sql\"",
                'X-Backup-Date' => rawurlencode($backupDate),
            ]);
        }

        $users = DB::table('users')
            ->select(['id', 'name', 'username', 'email', 'phone', 'role', 'avatar', 'status', 'createdAt'])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $payload = [
            'system' => 'Sumas Anti-Counterfeiting Platform (Laravel + MySQL)',
            'version' => '2.6.0-Enterprise',
            'backupGeneratedAt' => $backupDate,
            'summary' => [
                'totalBatches' => count($batches),
                'totalTags' => count($tags),
                'totalScanPoints' => count($scanPoints),
                'totalScanActivities' => count($scanActivities),
                'totalCategories' => count($categories),
                'totalBrands' => count($brands),
                'totalProducts' => count($products),
                'totalUsers' => count($users),
                'totalAuditLogs' => count($auditLogs),
                'totalTrashItems' => count($trashItems),
            ],
            'data' => [
                'batches' => $batches,
                'tags' => $tags,
                'scanPoints' => $scanPoints,
                'scanActivities' => $scanActivities,
                'categories' => $categories,
                'brands' => $brands,
                'products' => $products,
                'users' => $users,
                'auditLogs' => $auditLogs,
                'passwordResetRequests' => $passwordResetRequests,
                'readNotifications' => $readNotifications,
                'trashItems' => $trashItems,
                'settings' => ($settingsRow && !empty($settingsRow->value))
                    ? (json_decode((string) $settingsRow->value, true) ?: SecurityPolicyService::defaultSystemSettings())
                    : SecurityPolicyService::defaultSystemSettings(),
            ],
        ];

        return response(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"sumas-backup-{$timestampIso}.json\"",
            'X-Backup-Date' => rawurlencode($backupDate),
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        if (!$this->can($request, 'settings.manage')) {
            return $this->deny($request);
        }

        $backup = $request->all();
        if (empty($backup['data']) || !is_array($backup['data']) || empty($backup['system'])) {
            return response()->json(['error' => 'Format berkas cadangan tidak valid atau rusak.'], 400);
        }

        $data = $backup['data'];
        $stats = ['batches' => 0, 'tags' => 0, 'brands' => 0, 'products' => 0, 'categories' => 0];

        DB::transaction(function () use ($data, &$stats) {
            if (is_array($data['batches'] ?? null)) {
                DB::table('batches')->delete();
                $rows = [];
                foreach ($data['batches'] as $b) {
                    $rows[] = [
                        'id' => $b['id'],
                        'batchId' => $b['batchId'],
                        'date' => $b['date'] ?? '',
                        'project' => $b['project'] ?? null,
                        'sku' => $b['sku'] ?? '',
                        'brand' => $b['brand'] ?? '',
                        'qty' => (int) ($b['qty'] ?? 0),
                        'specs' => $b['specs'] ?? null,
                        'pinLength' => (int) ($b['pinLength'] ?? 8),
                        'status' => $b['status'] ?? 'Aktif',
                        'isB2B' => !empty($b['isB2B']) ? 1 : 0,
                        'redirectUrl' => $b['redirectUrl'] ?? 'https://cek.sumas.id/',
                        'qrType' => $b['qrType'] ?? 'dynamic',
                        'requirePin' => (isset($b['requirePin']) && ($b['requirePin'] === 0 || $b['requirePin'] === false)) ? 0 : 1,
                        'enableGps' => (isset($b['enableGps']) && ($b['enableGps'] === 0 || $b['enableGps'] === false)) ? 0 : 1,
                    ];
                    $stats['batches']++;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('batches')->insert($chunk);
                }
            }

            if (is_array($data['tags'] ?? null)) {
                DB::table('tags')->delete();
                $rows = [];
                foreach ($data['tags'] as $t) {
                    $rows[] = [
                        'id' => $t['id'],
                        'batchId' => $t['batchId'],
                        'tagNumber' => $t['tagNumber'],
                        'pin' => $t['pin'],
                        'status' => $t['status'] ?? 'Tersedia',
                        'project' => $t['project'] ?? null,
                        'specs' => $t['specs'] ?? null,
                        'codeType' => $t['codeType'] ?? null,
                        'warranty' => $t['warranty'] ?? null,
                        'prodCode' => $t['prodCode'] ?? null,
                        'note' => $t['note'] ?? null,
                        'allocatedAt' => $t['allocatedAt'] ?? null,
                    ];
                    $stats['tags']++;
                }
                foreach (array_chunk($rows, 400) as $chunk) {
                    DB::table('tags')->insert($chunk);
                }
            }

            if (is_array($data['scanPoints'] ?? null)) {
                DB::table('scan_points')->delete();
                $rows = [];
                foreach ($data['scanPoints'] as $s) {
                    $hist = isset($s['scanHistory'])
                        ? (is_string($s['scanHistory']) ? $s['scanHistory'] : json_encode($s['scanHistory']))
                        : null;
                    $rows[] = [
                        'id' => $s['id'],
                        'city' => $s['city'] ?? '',
                        'province' => $s['province'] ?? '',
                        'venue' => $s['venue'] ?? null,
                        'lat' => (float) ($s['lat'] ?? 0),
                        'lng' => (float) ($s['lng'] ?? 0),
                        'totalScans' => (int) ($s['totalScans'] ?? 0),
                        'todayScans' => (int) ($s['todayScans'] ?? 0),
                        'brand' => $s['brand'] ?? '',
                        'productType' => $s['productType'] ?? null,
                        'batchId' => $s['batchId'] ?? null,
                        'latestTagId' => $s['latestTagId'] ?? null,
                        'pin' => $s['pin'] ?? null,
                        'latestTime' => $s['latestTime'] ?? null,
                        'isB2B' => !empty($s['isB2B']) ? 1 : 0,
                        'status' => $s['status'] ?? 'valid',
                        'anomalyReason' => $s['anomalyReason'] ?? null,
                        'deviceInfo' => $s['deviceInfo'] ?? null,
                        'scanHistory' => $hist,
                        'createdAt' => $s['createdAt'] ?? null,
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('scan_points')->insert($chunk);
                }
            }

            if (is_array($data['scanActivities'] ?? null)) {
                DB::table('scan_activities')->delete();
                $rows = [];
                foreach ($data['scanActivities'] as $a) {
                    $rows[] = [
                        'rangeType' => $a['rangeType'] ?? '7d',
                        'label' => $a['label'] ?? '',
                        'fullDate' => $a['fullDate'] ?? '',
                        'scans' => (int) ($a['scans'] ?? 0),
                        'valid' => (int) ($a['valid'] ?? 0),
                        'topBrand' => $a['topBrand'] ?? '',
                        'sortOrder' => (int) ($a['sortOrder'] ?? 0),
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('scan_activities')->insert($chunk);
                }
            }

            if (is_array($data['categories'] ?? null)) {
                DB::table('categories')->delete();
                $rows = [];
                foreach ($data['categories'] as $c) {
                    $rows[] = [
                        'id' => $c['id'],
                        'name' => $c['name'],
                        'parentId' => $c['parentId'] ?? null,
                        'level' => (int) ($c['level'] ?? 1),
                        'createdAt' => $c['createdAt'] ?? gmdate('Y-m-d'),
                        'specSchema' => $c['specSchema'] ?? null,
                    ];
                    $stats['categories']++;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('categories')->insert($chunk);
                }
            }

            if (is_array($data['brands'] ?? null)) {
                DB::table('brands')->delete();
                $rows = [];
                foreach ($data['brands'] as $br) {
                    $rows[] = [
                        'id' => $br['id'],
                        'name' => $br['name'],
                        'logo' => $br['logo'] ?? null,
                        'ownerId' => $br['ownerId'] ?? '',
                        'description' => $br['description'] ?? null,
                        'businessType' => $br['businessType'] ?? 'Retail',
                        'status' => $br['status'] ?? 'Aktif',
                        'createdAt' => $br['createdAt'] ?? gmdate('Y-m-d'),
                    ];
                    $stats['brands']++;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('brands')->insert($chunk);
                }
            }

            if (is_array($data['products'] ?? null)) {
                DB::table('products')->delete();
                $rows = [];
                foreach ($data['products'] as $p) {
                    $rows[] = [
                        'id' => $p['id'],
                        'brandId' => $p['brandId'] ?? null,
                        'brandName' => $p['brandName'] ?? '',
                        'sku' => $p['sku'] ?? '',
                        'category' => $p['category'] ?? null,
                        'isB2B' => !empty($p['isB2B']) ? 1 : 0,
                        'description' => $p['description'] ?? null,
                        'createdAt' => $p['createdAt'] ?? null,
                        'skuCode' => $p['skuCode'] ?? null,
                        'image' => $p['image'] ?? null,
                    ];
                    $stats['products']++;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('products')->insert($chunk);
                }
            }

            if (is_array($data['auditLogs'] ?? null)) {
                DB::table('audit_logs')->delete();
                $rows = [];
                foreach ($data['auditLogs'] as $l) {
                    $chg = is_string($l['changes'] ?? null) ? $l['changes'] : json_encode($l['changes'] ?? []);
                    $rows[] = [
                        'id' => $l['id'],
                        'batchId' => $l['batchId'] ?? '',
                        'timestamp' => $l['timestamp'] ?? '',
                        'user' => $l['user'] ?? 'System',
                        'reason' => $l['reason'] ?? '',
                        'changes' => $chg,
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('audit_logs')->insert($chunk);
                }
            }

            if (is_array($data['trashItems'] ?? null) && \Illuminate\Support\Facades\Schema::hasTable('trash_items')) {
                DB::table('trash_items')->delete();
                $rows = [];
                foreach ($data['trashItems'] as $t) {
                    if (empty($t['id']) || empty($t['entityType']) || empty($t['entityId'])) {
                        continue;
                    }
                    $payloadRaw = is_string($t['payload'] ?? null) ? $t['payload'] : json_encode($t['payload'] ?? []);
                    $rows[] = [
                        'id' => $t['id'],
                        'entityType' => $t['entityType'],
                        'entityId' => $t['entityId'],
                        'title' => $t['title'] ?? $t['entityId'],
                        'subtitle' => $t['subtitle'] ?? null,
                        'brandName' => $t['brandName'] ?? null,
                        'brandId' => $t['brandId'] ?? null,
                        'deletedById' => $t['deletedById'] ?? null,
                        'deletedByName' => $t['deletedByName'] ?? 'Sistem',
                        'deletedAt' => $t['deletedAt'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                        'deletedAtTs' => (int) ($t['deletedAtTs'] ?? round(microtime(true) * 1000)),
                        'expiresAtTs' => (int) ($t['expiresAtTs'] ?? (round(microtime(true) * 1000) + \App\Services\TrashService::RETENTION_MS)),
                        'itemCount' => max(1, (int) ($t['itemCount'] ?? 1)),
                        'payload' => $payloadRaw,
                    ];
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('trash_items')->insert($chunk);
                }
            }

            if (is_array($data['settings'] ?? null)) {
                $safeSettings = SecurityPolicyService::mergeSystemSettings($data['settings']);
                $now = RbacService::formatIndonesianTimestamp();
                DB::table('system_settings')->updateOrInsert(
                    ['key' => 'app_settings'],
                    ['value' => json_encode($safeSettings), 'updatedAt' => $now]
                );
            }
        });

        Cache::forget(SecurityPolicyService::SETTINGS_CACHE_KEY);
        Cache::forget(RbacService::RBAC_CACHE_KEY);

        return response()->json([
            'success' => true,
            'restored' => $stats,
            'message' => "Pemulihan lengkap berhasil! {$stats['batches']} batch, {$stats['tags']} tag, {$stats['brands']} brand, {$stats['products']} SKU, dan {$stats['categories']} kategori telah dipulihkan.",
        ]);
    }
}
