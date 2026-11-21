<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $scope = $this->brandScope($request);
        $tagCounts = DB::table('tags')
            ->selectRaw("batchId, COUNT(*) as totalCount, SUM(CASE WHEN status = 'Tersedia' THEN 1 ELSE 0 END) as availableCount, SUM(CASE WHEN status = 'Telah Digunakan' THEN 1 ELSE 0 END) as usedCount")
            ->groupBy('batchId')
            ->get()
            ->keyBy('batchId');

        $rows = DB::table('batches')->orderByDesc('date')->orderByDesc('id')->get();
        $result = [];

        foreach ($rows as $rowObj) {
            $row = (array) $rowObj;
            if (empty($scope['isGlobal']) && !RbacService::canScopeAccessBrand($scope, $row['brand'] ?? null)) {
                continue;
            }
            $tc = $tagCounts->get($row['id']);
            $row['qty'] = (int) ($row['qty'] ?? 0);
            $row['pinLength'] = (int) ($row['pinLength'] ?? 8);
            $row['actualTagCount'] = $tc ? (int) $tc->totalCount : 0;
            $row['availableCount'] = $tc ? (int) $tc->availableCount : 0;
            $row['usedCount'] = $tc ? (int) $tc->usedCount : 0;
            $row['isB2B'] = (bool) ($row['isB2B'] ?? false);
            $row['redirectUrl'] = $row['redirectUrl'] ?: 'https://cek.sumas.id/';
            $row['qrType'] = $row['qrType'] ?: 'dynamic';
            $row['requirePin'] = $row['requirePin'] === null ? true : (bool) $row['requirePin'];
            $row['enableGps'] = $row['enableGps'] === null ? true : (bool) $row['enableGps'];
            $row['tags'] = null;
            $result[] = $row;
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can($request, 'batches.create')) {
            return $this->deny($request);
        }

        $b = $request->all();
        $batchBrand = SecurityHardeningService::sanitizeStringInput($b['brand'] ?? '', 120);
        $batchSku = SecurityHardeningService::sanitizeStringInput($b['sku'] ?? '', 200);
        $batchCode = SecurityHardeningService::sanitizeStringInput($b['batchId'] ?? '', 120);
        $batchPk = SecurityHardeningService::sanitizeStringInput($b['id'] ?? '', 120)
            ?: ('batch-' . (int) round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)));

        if ($batchBrand === '' || $batchSku === '' || $batchCode === '') {
            return response()->json(['error' => 'ID Batch, Brand, dan SKU wajib diisi.'], 400);
        }

        $scope = $this->brandScope($request);
        if ($denied = RbacService::assertBrandAccess($scope, $batchBrand)) {
            return $denied;
        }

        $rawQty = (int) ($b['qty'] ?? 0);
        if ($rawQty < 1 || $rawQty > 25000) {
            return response()->json(['error' => 'Jumlah tag per batch harus antara 1 hingga 25.000 tag.'], 400);
        }

        $pinLength = min(12, max(4, (int) ($b['pinLength'] ?? 8) ?: 8));
        $isB2B = !empty($b['isB2B']) ? 1 : 0;

        $urlCheck = SecurityHardeningService::validateHttpUrl($b['redirectUrl'] ?? '', true, 'https://cek.sumas.id/');
        if (!$urlCheck['valid']) {
            return response()->json(['error' => $urlCheck['error'] ?? 'URL pengalihan QR tidak valid.'], 400);
        }

        $redirectUrl = $urlCheck['url'] ?: 'https://cek.sumas.id/';
        $qrType = ($b['qrType'] ?? '') === 'static' ? 'static' : 'dynamic';
        $requirePin = (isset($b['requirePin']) && ($b['requirePin'] === false || $b['requirePin'] === 0 || $b['requirePin'] === '0')) ? 0 : 1;
        $enableGps = (isset($b['enableGps']) && ($b['enableGps'] === false || $b['enableGps'] === 0 || $b['enableGps'] === '0')) ? 0 : 1;
        $safeStatus = ($b['status'] ?? '') === 'Disuspend' ? 'Disuspend' : 'Aktif';
        $safeDate = SecurityHardeningService::sanitizeStringInput($b['date'] ?? '', 80) ?: RbacService::formatIndonesianTimestamp();
        $safeSpecs = SecurityHardeningService::sanitizeStringInput($b['specs'] ?? '', 500) ?: '-';
        $safeProject = !empty($b['project']) ? SecurityHardeningService::sanitizeStringInput($b['project'], 200) : null;

        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charsLen = strlen($chars);
        $tagsToInsert = [];
        for ($i = 0; $i < $rawQty; $i++) {
            $pin = '';
            for ($j = 0; $j < $pinLength; $j++) {
                $pin .= (string) random_int(0, 9);
            }
            $tagNumber = '';
            for ($k = 0; $k < 8; $k++) {
                $tagNumber .= $chars[random_int(0, $charsLen - 1)];
            }
            $tagsToInsert[] = [
                'id' => "tag-{$batchPk}-{$tagNumber}-{$i}",
                'batchId' => $batchPk,
                'tagNumber' => $tagNumber,
                'pin' => $pin,
                'status' => 'Tersedia',
                'project' => null,
                'specs' => '-',
                'codeType' => '-',
                'warranty' => '-',
                'prodCode' => '-',
                'note' => '-',
                'allocatedAt' => null,
            ];
        }

        DB::transaction(function () use (
            $batchPk, $batchCode, $safeDate, $safeProject, $batchSku, $batchBrand,
            $rawQty, $safeSpecs, $pinLength, $safeStatus, $isB2B, $redirectUrl,
            $qrType, $requirePin, $enableGps, $tagsToInsert
        ) {
            DB::table('batches')->insert([
                'id' => $batchPk,
                'batchId' => $batchCode,
                'date' => $safeDate,
                'project' => $safeProject,
                'sku' => $batchSku,
                'brand' => $batchBrand,
                'qty' => $rawQty,
                'specs' => $safeSpecs,
                'pinLength' => $pinLength,
                'status' => $safeStatus,
                'isB2B' => $isB2B,
                'redirectUrl' => $redirectUrl,
                'qrType' => $qrType,
                'requirePin' => $requirePin,
                'enableGps' => $enableGps,
            ]);

            foreach (array_chunk($tagsToInsert, 400) as $chunk) {
                DB::table('tags')->insert($chunk);
            }
        });

        return response()->json([
            ...$b,
            'id' => $batchPk,
            'batchId' => $batchCode,
            'date' => $safeDate,
            'project' => $safeProject,
            'sku' => $batchSku,
            'brand' => $batchBrand,
            'specs' => $safeSpecs,
            'status' => $safeStatus,
            'qty' => $rawQty,
            'pinLength' => $pinLength,
            'isB2B' => (bool) $isB2B,
            'redirectUrl' => $redirectUrl,
            'qrType' => $qrType,
            'requirePin' => (bool) $requirePin,
            'enableGps' => (bool) $enableGps,
            'actualTagCount' => $rawQty,
            'availableCount' => $rawQty,
            'usedCount' => 0,
        ]);
    }

    public function tags(Request $request, string $id): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batchRecord = DB::table('batches')
            ->where('id', $decodedId)
            ->orWhere('batchId', $decodedId)
            ->first(['id', 'batchId', 'brand']);

        if (!$batchRecord) {
            return response()->json(['error' => 'Batch tidak ditemukan.'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batchRecord->brand)) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', '1'));
        $limit = min(500, max(10, (int) $request->query('limit', '96')));
        $offset = ($page - 1) * $limit;
        $search = strtolower(SecurityHardeningService::sanitizeStringInput($request->query('search', ''), 120));
        $status = SecurityHardeningService::sanitizeStringInput($request->query('status', 'all'), 40) ?: 'all';
        $project = SecurityHardeningService::sanitizeStringInput($request->query('project', 'all'), 120) ?: 'all';

        $query = DB::table('tags')->where('batchId', $batchRecord->id);

        if ($search !== '') {
            $escaped = '%' . SecurityHardeningService::escapeLikePattern($search) . '%';
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("LOWER(pin) LIKE ? ESCAPE '!'", [$escaped])
                    ->orWhereRaw("LOWER(tagNumber) LIKE ? ESCAPE '!'", [$escaped])
                    ->orWhereRaw("LOWER(COALESCE(project, '')) LIKE ? ESCAPE '!'", [$escaped]);
            });
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($project !== 'all') {
            if ($project === 'unallocated') {
                $query->where(function ($q) {
                    $q->whereNull('project')->orWhere('project', '');
                });
            } else {
                $query->where('project', $project);
            }
        }

        $totalCount = (clone $query)->count();
        $tags = $query->orderBy('prodCode')->orderBy('id')->offset($offset)->limit($limit)->get();

        $summary = DB::table('tags')
            ->where('batchId', $batchRecord->id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'Tersedia' THEN 1 ELSE 0 END) as available, SUM(CASE WHEN status = 'Telah Digunakan' THEN 1 ELSE 0 END) as used")
            ->first();

        return response()->json([
            'tags' => $tags,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'totalPages' => max(1, (int) ceil($totalCount / $limit)),
            'batchSummary' => [
                'total' => (int) ($summary->total ?? 0),
                'available' => (int) ($summary->available ?? 0),
                'used' => (int) ($summary->used ?? 0),
            ],
        ]);
    }

    public function allTags(Request $request, string $id): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batch = DB::table('batches')->where('id', $decodedId)->orWhere('batchId', $decodedId)->first();
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }

        $tags = DB::table('tags')
            ->select(['id', 'batchId', 'tagNumber', 'pin', 'status', 'project', 'specs'])
            ->where('batchId', $batch->id)
            ->orderBy('prodCode')
            ->orderBy('id')
            ->get();

        return response()->json([
            'batch' => $batch,
            'tags' => $tags,
            'total' => $tags->count(),
        ]);
    }

    public function summary(Request $request, string $id): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'dashboard.view')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batch = DB::table('batches')->where('id', $decodedId)->orWhere('batchId', $decodedId)->first();
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }

        $tagStats = DB::table('tags')
            ->where('batchId', $batch->id)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'Tersedia' THEN 1 ELSE 0 END) as available, SUM(CASE WHEN status = 'Telah Digunakan' THEN 1 ELSE 0 END) as used")
            ->first();

        $projectAllocations = DB::table('tags')
            ->selectRaw("project as name, COUNT(*) as count, MAX(specs) as specs, MAX(warranty) as warranty, MAX(codeType) as codeType, MAX(prodCode) as prodCode, MAX(note) as note, MAX(allocatedAt) as date")
            ->where('batchId', $batch->id)
            ->whereNotNull('project')
            ->where('status', 'Telah Digunakan')
            ->groupBy('project')
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['count'] = (int) ($arr['count'] ?? 0);
                return $arr;
            })
            ->all();

        $isB2B = RbacService::isBrandB2B($batch->brand, $batch->isB2B);
        $batchArr = (array) $batch;
        $batchArr['qty'] = (int) ($batchArr['qty'] ?? 0);
        $batchArr['pinLength'] = (int) ($batchArr['pinLength'] ?? 8);
        $batchArr['isB2B'] = $isB2B;
        $batchArr['requirePin'] = $batchArr['requirePin'] === null ? true : (bool) $batchArr['requirePin'];
        $batchArr['enableGps'] = $batchArr['enableGps'] === null ? true : (bool) $batchArr['enableGps'];

        return response()->json([
            'batch' => $batchArr,
            'totalTags' => (int) ($tagStats->total ?? $batch->qty),
            'availableCount' => (int) ($tagStats->available ?? 0),
            'usedCount' => (int) ($tagStats->used ?? 0),
            'projects' => $isB2B ? $projectAllocations : [],
        ]);
    }

    public function updateRedirectUrl(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.redirect_url')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batch = DB::table('batches')->where('id', $decodedId)->first();
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }

        if (($batch->qrType ?? '') === 'static') {
            return response()->json([
                'error' => 'Batch dengan tipe QR Statis tidak mendukung perubahan URL karena alamat URL telah tertanam permanen pada stiker fisik.',
            ], 400);
        }

        $body = $request->all();
        $newUrl = $batch->redirectUrl ?: 'https://cek.sumas.id/';
        if (array_key_exists('redirectUrl', $body)) {
            $urlCheck = SecurityHardeningService::validateHttpUrl($body['redirectUrl'], false);
            if (!$urlCheck['valid']) {
                return response()->json([
                    'error' => $urlCheck['error'] ?? 'URL pengalihan QR hanya boleh menggunakan http:// atau https://.',
                ], 400);
            }
            $newUrl = $urlCheck['url'];
        }

        $newQrType = ($body['qrType'] ?? null) === 'static'
            ? 'static'
            : (($body['qrType'] ?? null) === 'dynamic' ? 'dynamic' : ($batch->qrType ?: 'dynamic'));
        $newRequirePin = array_key_exists('requirePin', $body) ? ($body['requirePin'] ? 1 : 0) : ($batch->requirePin ?? 1);
        $newEnableGps = array_key_exists('enableGps', $body) ? ($body['enableGps'] ? 1 : 0) : ($batch->enableGps ?? 1);

        DB::table('batches')->where('id', $decodedId)->update([
            'redirectUrl' => $newUrl,
            'qrType' => $newQrType,
            'requirePin' => $newRequirePin,
            'enableGps' => $newEnableGps,
        ]);

        $authUser = $this->authUser($request);
        $actorLabel = $this->actorLabel($request);

        try {
            DB::table('audit_logs')->insert([
                'id' => (int) round(microtime(true) * 1000),
                'batchId' => $batch->batchId,
                'timestamp' => RbacService::formatIndonesianTimestamp(),
                'user' => $authUser['name'],
                'reason' => 'Pembaruan URL Pengalihan QR Dinamis',
                'changes' => json_encode([
                    ['field' => 'URL Pengalihan', 'old' => $batch->redirectUrl ?: '-', 'new' => $newUrl],
                    ['field' => 'Tipe QR', 'old' => $batch->qrType ?: 'dynamic', 'new' => $newQrType],
                ]),
            ]);
        } catch (\Throwable) {
        }

        NotificationService::createNotification([
            'type' => 'edit',
            'severity' => 'info',
            'audience' => 'brand_scope',
            'brandName' => $batch->brand,
            'title' => "Pengaturan QR Diperbarui: {$batch->batchId}",
            'subtitle' => "Oleh {$actorLabel} · Brand {$batch->brand}",
            'description' => "URL pengalihan QR diubah menjadi {$newUrl}",
            'entityType' => 'batch',
            'entityId' => $batch->batchId,
            'status' => 'edited',
            'dedupeKey' => "batch-url:{$batch->batchId}:{$newUrl}",
            'dedupeWindowMs' => 10000,
        ]);

        return response()->json([
            'success' => true,
            'redirectUrl' => $newUrl,
            'qrType' => $newQrType,
            'requirePin' => (bool) $newRequirePin,
            'enableGps' => (bool) $newEnableGps,
        ]);
    }

    public function allocate(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.allocate')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batch = DB::table('batches')->where('id', $decodedId)->first(['id', 'brand', 'isB2B']);
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }
        if (!RbacService::isBrandB2B($batch->brand, $batch->isB2B)) {
            return response()->json([
                'error' => "Batch milik brand \"{$batch->brand}\" bertipe Retail dan tidak memiliki fitur alokasi proyek.",
            ], 403);
        }

        $body = $request->all();
        $tagIds = is_array($body['tagIds'] ?? null) ? array_values(array_filter($body['tagIds'], fn ($t) => is_string($t) && $t !== '')) : [];
        if (count($tagIds) === 0 || count($tagIds) > 10000) {
            return response()->json(['error' => 'Jumlah tag yang dipilih harus antara 1 hingga 10.000 tag.'], 400);
        }

        $cleanProject = SecurityHardeningService::sanitizeStringInput($body['project'] ?? '', 200);
        if ($cleanProject === '') {
            return response()->json(['error' => 'Nama proyek wajib diisi.'], 400);
        }

        $allocatedAt = RbacService::formatIndonesianTimestamp();
        $updatePayload = [
            'status' => 'Telah Digunakan',
            'project' => $cleanProject,
            'specs' => SecurityHardeningService::sanitizeStringInput($body['specs'] ?? '', 300) ?: '-',
            'codeType' => SecurityHardeningService::sanitizeStringInput($body['codeType'] ?? '', 120) ?: '-',
            'warranty' => SecurityHardeningService::sanitizeStringInput($body['warranty'] ?? '', 120) ?: '-',
            'prodCode' => SecurityHardeningService::sanitizeStringInput($body['prodCode'] ?? '', 120) ?: '-',
            'note' => SecurityHardeningService::sanitizeStringInput($body['note'] ?? '', 500) ?: '-',
            'allocatedAt' => $allocatedAt,
        ];

        foreach (array_chunk($tagIds, 400) as $chunk) {
            DB::table('tags')
                ->where('batchId', $decodedId)
                ->whereIn('id', $chunk)
                ->update($updatePayload);
        }

        $distinctProjects = DB::table('tags')
            ->where('batchId', $decodedId)
            ->whereNotNull('project')
            ->where('status', 'Telah Digunakan')
            ->distinct()
            ->pluck('project')
            ->all();

        DB::table('batches')->where('id', $decodedId)->update([
            'project' => count($distinctProjects) > 0 ? implode(', ', $distinctProjects) : null,
        ]);

        return response()->json([
            'success' => true,
            'allocatedCount' => count($tagIds),
            'project' => $cleanProject,
            'allocatedAt' => $allocatedAt,
        ]);
    }

    public function deallocate(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.allocate')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $project = SecurityHardeningService::sanitizeStringInput($request->input('project', ''), 200);
        $batch = DB::table('batches')->where('id', $decodedId)->first(['id', 'brand', 'isB2B']);
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }
        if (!RbacService::isBrandB2B($batch->brand, $batch->isB2B)) {
            return response()->json(['error' => 'Operasi alokasi proyek hanya berlaku untuk brand/batch B2B.'], 403);
        }

        DB::table('tags')
            ->where('batchId', $decodedId)
            ->where('project', $project)
            ->update([
                'status' => 'Tersedia',
                'project' => null,
                'specs' => '-',
                'codeType' => '-',
                'warranty' => '-',
                'prodCode' => '-',
                'note' => '-',
                'allocatedAt' => null,
            ]);

        $distinctProjects = DB::table('tags')
            ->where('batchId', $decodedId)
            ->whereNotNull('project')
            ->where('status', 'Telah Digunakan')
            ->distinct()
            ->pluck('project')
            ->all();

        DB::table('batches')->where('id', $decodedId)->update([
            'project' => count($distinctProjects) > 0 ? implode(', ', $distinctProjects) : null,
        ]);

        return response()->json(['success' => true, 'deallocatedProject' => $project]);
    }

    public function updateProjects(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.allocate')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $batch = DB::table('batches')->where('id', $decodedId)->first(['id', 'brand', 'isB2B']);
        if (!$batch) {
            return response()->json(['error' => 'Batch tidak ditemukan'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $batch->brand)) {
            return $denied;
        }
        if (!RbacService::isBrandB2B($batch->brand, $batch->isB2B)) {
            return response()->json(['error' => 'Pengeditan data proyek hanya berlaku untuk brand/batch B2B.'], 403);
        }

        $b = $request->all();
        $trimmedOld = SecurityHardeningService::sanitizeStringInput($b['oldProjectName'] ?? '', 200);
        $trimmedNew = SecurityHardeningService::sanitizeStringInput($b['newProjectName'] ?? '', 200);
        if ($trimmedOld === '' || $trimmedNew === '') {
            return response()->json(['error' => 'Nama proyek lama dan baru wajib diisi'], 400);
        }

        DB::table('tags')
            ->where('batchId', $decodedId)
            ->where('project', $trimmedOld)
            ->update([
                'project' => $trimmedNew,
                'specs' => SecurityHardeningService::sanitizeStringInput($b['specs'] ?? '', 300) ?: '-',
                'codeType' => SecurityHardeningService::sanitizeStringInput($b['codeType'] ?? '', 120) ?: '-',
                'warranty' => SecurityHardeningService::sanitizeStringInput($b['warranty'] ?? '', 120) ?: '-',
                'prodCode' => SecurityHardeningService::sanitizeStringInput($b['prodCode'] ?? '', 120) ?: '-',
                'note' => SecurityHardeningService::sanitizeStringInput($b['note'] ?? '', 500) ?: '-',
            ]);

        $distinctProjects = DB::table('tags')
            ->where('batchId', $decodedId)
            ->whereNotNull('project')
            ->where('status', 'Telah Digunakan')
            ->distinct()
            ->pluck('project')
            ->all();

        DB::table('batches')->where('id', $decodedId)->update([
            'project' => count($distinctProjects) > 0 ? implode(', ', $distinctProjects) : null,
        ]);

        return response()->json(['success' => true]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $decodedId = urldecode($id);
        $existing = DB::table('batches')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Batch tidak ditemukan.'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $existing->brand)) {
            return $denied;
        }

        $b = $request->all();
        $nextProject = array_key_exists('project', $b)
            ? ($b['project'] === null ? null : SecurityHardeningService::sanitizeStringInput($b['project'], 200))
            : $existing->project;
        $nextSpecs = array_key_exists('specs', $b)
            ? SecurityHardeningService::sanitizeStringInput($b['specs'], 500)
            : $existing->specs;
        $nextStatus = array_key_exists('status', $b)
            ? (($b['status'] === 'Disuspend') ? 'Disuspend' : 'Aktif')
            : $existing->status;

        $isModifyingDetails = (array_key_exists('project', $b) && $nextProject !== $existing->project)
            || (array_key_exists('specs', $b) && $nextSpecs !== $existing->specs);
        $isModifyingStatus = array_key_exists('status', $b) && $nextStatus !== $existing->status;

        if ($isModifyingDetails && !$this->canAny($request, 'batches.allocate', 'batches.create')) {
            return $this->deny($request, 'Akses ditolak. Anda tidak memiliki izin untuk mengubah detail batch.');
        }
        if ($isModifyingStatus && !$this->can($request, 'batches.suspend')) {
            return $this->deny($request, 'Akses ditolak. Anda tidak memiliki izin untuk menangguhkan atau memulihkan batch.');
        }

        DB::table('batches')->where('id', $decodedId)->update([
            'project' => $nextProject,
            'specs' => $nextSpecs,
            'status' => $nextStatus,
        ]);

        $result = (array) DB::table('batches')->where('id', $decodedId)->first();

        if ($existing->status !== $nextStatus) {
            $isSuspendedNow = $nextStatus === 'Disuspend';
            $actorLabel = $this->actorLabel($request);
            NotificationService::createNotification([
                'type' => 'edit',
                'severity' => $isSuspendedNow ? 'warning' : 'info',
                'audience' => 'brand_scope',
                'brandName' => $existing->brand,
                'title' => $isSuspendedNow ? "Batch Disuspend: {$existing->batchId}" : "Batch Dipulihkan: {$existing->batchId}",
                'subtitle' => "Dilakukan oleh {$actorLabel} · Brand {$existing->brand}",
                'description' => $isSuspendedNow
                    ? "Status batch {$existing->batchId} ({$existing->sku}) diubah menjadi Disuspend."
                    : "Status batch {$existing->batchId} ({$existing->sku}) telah diaktifkan kembali.",
                'entityType' => 'batch',
                'entityId' => $existing->batchId,
                'status' => 'edited',
                'dedupeKey' => "batch-status:{$existing->batchId}:{$nextStatus}",
                'dedupeWindowMs' => 10000,
            ]);
        }

        $result['isB2B'] = (bool) ($result['isB2B'] ?? false);
        $result['tags'] = null;

        return response()->json($result);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.delete')) {
            return $this->deny($request);
        }

        $decodedId = urldecode($id);
        $existing = DB::table('batches')->where('id', $decodedId)->first();
        if (!$existing) {
            return response()->json(['error' => 'Batch tidak ditemukan.'], 404);
        }
        if ($denied = RbacService::assertBrandAccess($this->brandScope($request), $existing->brand)) {
            return $denied;
        }

        DB::transaction(function () use ($decodedId, $existing) {
            DB::table('batches')->where('id', $decodedId)->delete();
            DB::table('tags')->where('batchId', $decodedId)->delete();
            DB::table('audit_logs')->where('batchId', $existing->batchId)->delete();
        });

        return response()->json(['success' => true]);
    }
}
