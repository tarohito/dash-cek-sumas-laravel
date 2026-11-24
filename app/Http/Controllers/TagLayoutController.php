<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\RbacService;
use App\Services\SecurityHardeningService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagLayoutController extends Controller
{
    private const SETTINGS_KEY_TEMPLATES = 'tag_layout_templates';
    private const SETTINGS_KEY_ACTIVE_ID = 'tag_layout_active_id';
    private const DEFAULT_ACTIVE_ID = 'builtin-sumas-25x60';
    private const BUILT_IN_IDS = [
        'builtin-sumas-25x60',
        'builtin-compact-30x40',
        'builtin-seal-50x22',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredTemplates(): array
    {
        try {
            $row = DB::table('system_settings')->where('key', self::SETTINGS_KEY_TEMPLATES)->first(['value']);
            if ($row && !empty($row->value)) {
                $decoded = json_decode((string) $row->value, true);
                if (is_array($decoded)) {
                    return array_values(array_filter($decoded, static function ($item) {
                        return is_array($item)
                            && !empty($item['id'])
                            && !empty($item['sticker'])
                            && !empty($item['sheet'])
                            && isset($item['layers'])
                            && is_array($item['layers']);
                    }));
                }
            }
        } catch (\Throwable) {
        }
        return [];
    }

    private function loadActiveTemplateId(): string
    {
        try {
            $row = DB::table('system_settings')->where('key', self::SETTINGS_KEY_ACTIVE_ID)->first(['value']);
            if ($row && is_string($row->value) && trim($row->value) !== '') {
                return trim($row->value);
            }
        } catch (\Throwable) {
        }
        return self::DEFAULT_ACTIVE_ID;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{valid: bool, template?: array<string, mixed>, error?: string}
     */
    private function sanitizeTemplate(array $raw): array
    {
        $id = SecurityHardeningService::sanitizeStringInput($raw['id'] ?? '', 80);
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $id)) {
            $id = 'custom-' . (int) round(microtime(true) * 1000);
        }

        $name = SecurityHardeningService::sanitizeStringInput($raw['name'] ?? '', 120);
        if ($name === '') {
            return ['valid' => false, 'error' => 'Nama template layout wajib diisi.'];
        }

        $description = SecurityHardeningService::sanitizeStringInput($raw['description'] ?? '', 300);

        $rawSticker = is_array($raw['sticker'] ?? null) ? $raw['sticker'] : [];
        $rawSheet = is_array($raw['sheet'] ?? null) ? $raw['sheet'] : [];
        $rawLayers = is_array($raw['layers'] ?? null) ? $raw['layers'] : [];

        if (count($rawLayers) === 0 || count($rawLayers) > 60) {
            return ['valid' => false, 'error' => 'Layout tag harus memiliki minimal 1 elemen dan maksimal 60 elemen.'];
        }

        $bgImage = $rawSticker['backgroundImageDataUrl'] ?? null;
        if (is_string($bgImage) && trim($bgImage) !== '') {
            $imgCheck = SecurityHardeningService::validateSafeImageSource($bgImage, 5 * 1024 * 1024);
            if (!$imgCheck['valid']) {
                return ['valid' => false, 'error' => $imgCheck['error'] ?? 'Format gambar artwork tidak valid.'];
            }
            $bgImage = $imgCheck['value'];
        } else {
            $bgImage = null;
        }

        $presetRaw = (string) ($rawSheet['preset'] ?? 'A3+');
        $preset = in_array($presetRaw, ['A3+', 'A4', 'F4', 'Custom'], true) ? $presetRaw : 'Custom';

        $sticker = [
            'widthMm' => max(5, min(300, (float) ($rawSticker['widthMm'] ?? 25))),
            'heightMm' => max(5, min(450, (float) ($rawSticker['heightMm'] ?? 60))),
            'cornerRadiusMm' => max(0, min(50, (float) ($rawSticker['cornerRadiusMm'] ?? 2))),
            'backgroundColor' => $this->sanitizeHexColor($rawSticker['backgroundColor'] ?? '#ffffff', '#ffffff'),
            'showCutLine' => (bool) ($rawSticker['showCutLine'] ?? true),
            'cutLineColor' => $this->sanitizeHexColor($rawSticker['cutLineColor'] ?? '#dc2626', '#dc2626'),
            'cutLineWidthMm' => max(0.05, min(5, (float) ($rawSticker['cutLineWidthMm'] ?? 0.18))),
            'backgroundImageDataUrl' => $bgImage,
        ];

        $sheet = [
            'preset' => $preset,
            'widthMm' => max(20, min(1200, (float) ($rawSheet['widthMm'] ?? 310))),
            'heightMm' => max(20, min(1200, (float) ($rawSheet['heightMm'] ?? 470))),
            'marginXMm' => max(0, min(100, (float) ($rawSheet['marginXMm'] ?? 14))),
            'marginYMm' => max(0, min(100, (float) ($rawSheet['marginYMm'] ?? 25))),
            'gapXMm' => max(0, min(50, (float) ($rawSheet['gapXMm'] ?? 3))),
            'gapYMm' => max(0, min(50, (float) ($rawSheet['gapYMm'] ?? 4))),
            'showRegistrationMarks' => (bool) ($rawSheet['showRegistrationMarks'] ?? true),
        ];

        $allowedTypes = ['qr', 'pin', 'tagId', 'brand', 'emblem', 'text', 'shape'];
        $allowedFonts = ['KodeMono', 'helvetica', 'times'];
        $allowedAligns = ['left', 'center', 'right'];

        $cleanLayers = [];
        foreach ($rawLayers as $idx => $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $type = (string) ($layer['type'] ?? '');
            if (!in_array($type, $allowedTypes, true)) {
                continue;
            }

            $base = [
                'id' => SecurityHardeningService::sanitizeStringInput($layer['id'] ?? "layer-{$idx}", 80) ?: "layer-{$idx}",
                'type' => $type,
                'label' => SecurityHardeningService::sanitizeStringInput($layer['label'] ?? 'Layer', 80) ?: 'Layer',
                'visible' => (bool) ($layer['visible'] ?? true),
                'locked' => (bool) ($layer['locked'] ?? false),
                'xMm' => max(-200, min(500, (float) ($layer['xMm'] ?? 0))),
                'yMm' => max(-200, min(500, (float) ($layer['yMm'] ?? 0))),
                'widthMm' => max(0.5, min(500, (float) ($layer['widthMm'] ?? 10))),
                'heightMm' => max(0.5, min(500, (float) ($layer['heightMm'] ?? 10))),
                'rotation' => (float) ((((int) round((float) ($layer['rotation'] ?? 0))) % 360 + 360) % 360),
            ];

            $rawFont = (string) ($layer['fontFamily'] ?? '');
            $font = in_array($rawFont, $allowedFonts, true) ? $rawFont : 'helvetica';
            $rawAlign = (string) ($layer['align'] ?? '');
            $align = in_array($rawAlign, $allowedAligns, true) ? $rawAlign : 'center';
            $weight = ($layer['fontWeight'] ?? 'bold') === 'normal' ? 'normal' : 'bold';
            $letterSpacingPt = round(max(-2, min(30, (float) ($layer['letterSpacingPt'] ?? 0))), 2);

            switch ($type) {
                case 'qr':
                    $rawEcc = (string) ($layer['qrErrorCorrection'] ?? 'M');
                    $ecc = in_array($rawEcc, ['L', 'M', 'Q', 'H'], true) ? $rawEcc : 'M';
                    $cleanLayers[] = array_merge($base, [
                        'qrDarkColor' => $this->sanitizeHexColor($layer['qrDarkColor'] ?? '#000000', '#000000'),
                        'qrLightColor' => $this->sanitizeHexColor($layer['qrLightColor'] ?? '#ffffff', '#ffffff'),
                        'qrMargin' => max(0, min(4, (int) ($layer['qrMargin'] ?? 1))),
                        'qrErrorCorrection' => $ecc,
                    ]);
                    break;

                case 'pin':
                    $cleanLayers[] = array_merge($base, [
                        'pinMode' => ($layer['pinMode'] ?? '2-line') === '1-line' ? '1-line' : '2-line',
                        'lineGapMm' => max(1.5, min(20, (float) ($layer['lineGapMm'] ?? 4.0))),
                        'fontFamily' => $font,
                        'fontSizePt' => max(2, min(300, (float) ($layer['fontSizePt'] ?? 14))),
                        'fontWeight' => $weight,
                        'letterSpacingPt' => $letterSpacingPt,
                        'color' => $this->sanitizeHexColor($layer['color'] ?? '#0f172a', '#0f172a'),
                        'align' => $align,
                    ]);
                    break;

                case 'tagId':
                    $cleanLayers[] = array_merge($base, [
                        'prefix' => SecurityHardeningService::sanitizeStringInput($layer['prefix'] ?? '', 24),
                        'fontFamily' => $font,
                        'fontSizePt' => max(2, min(300, (float) ($layer['fontSizePt'] ?? 5.5))),
                        'fontWeight' => $weight,
                        'letterSpacingPt' => $letterSpacingPt,
                        'color' => $this->sanitizeHexColor($layer['color'] ?? '#475569', '#475569'),
                        'align' => $align,
                    ]);
                    break;

                case 'brand':
                    $cleanLayers[] = array_merge($base, [
                        'fontFamily' => $font,
                        'fontSizePt' => max(2, min(300, (float) ($layer['fontSizePt'] ?? 6))),
                        'fontWeight' => $weight,
                        'letterSpacingPt' => $letterSpacingPt,
                        'color' => $this->sanitizeHexColor($layer['color'] ?? '#18181b', '#18181b'),
                        'align' => $align,
                        'uppercase' => (bool) ($layer['uppercase'] ?? true),
                    ]);
                    break;

                case 'emblem':
                    $cleanLayers[] = array_merge($base, [
                        'color' => $this->sanitizeHexColor($layer['color'] ?? '#242156', '#242156'),
                        'letter' => substr(SecurityHardeningService::sanitizeStringInput($layer['letter'] ?? 'S', 4) ?: 'S', 0, 2),
                    ]);
                    break;

                case 'shape':
                    $cleanLayers[] = array_merge($base, [
                        'shapeType' => ($layer['shapeType'] ?? 'rect') === 'line' ? 'line' : 'rect',
                        'fillColor' => $this->sanitizeHexColor($layer['fillColor'] ?? '#f8fafc', '#f8fafc'),
                        'strokeColor' => $this->sanitizeHexColor($layer['strokeColor'] ?? '#cbd5e1', '#cbd5e1'),
                        'strokeWidthMm' => max(0, min(10, (float) ($layer['strokeWidthMm'] ?? 0.2))),
                        'cornerRadiusMm' => max(0, min(50, (float) ($layer['cornerRadiusMm'] ?? 0))),
                        'transparentFill' => (bool) ($layer['transparentFill'] ?? false),
                    ]);
                    break;

                case 'text':
                    $cleanLayers[] = array_merge($base, [
                        'content' => SecurityHardeningService::sanitizeStringInput($layer['content'] ?? '', 120),
                        'fontFamily' => $font,
                        'fontSizePt' => max(2, min(300, (float) ($layer['fontSizePt'] ?? 5.5))),
                        'fontWeight' => $weight,
                        'letterSpacingPt' => $letterSpacingPt,
                        'color' => $this->sanitizeHexColor($layer['color'] ?? '#0f172a', '#0f172a'),
                        'align' => $align,
                    ]);
                    break;
            }
        }

        if (count($cleanLayers) === 0) {
            return ['valid' => false, 'error' => 'Tidak ada elemen valid pada template layout.'];
        }

        $rawCreatedAt = SecurityHardeningService::sanitizeStringInput($payload['createdAt'] ?? '', 40);
        $validCreatedAt = ($rawCreatedAt !== '' && strtotime($rawCreatedAt) !== false) ? $rawCreatedAt : null;

        return [
            'valid' => true,
            'template' => [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'isBuiltIn' => in_array($id, self::BUILT_IN_IDS, true),
                'createdAt' => $validCreatedAt,
                'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'sticker' => $sticker,
                'sheet' => $sheet,
                'layers' => $cleanLayers,
            ],
        ];
    }

    private function sanitizeHexColor(mixed $raw, string $fallback): string
    {
        if (!is_string($raw)) {
            return $fallback;
        }
        $trimmed = trim($raw);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed)) {
            return strtolower($trimmed);
        }
        return $fallback;
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->canAny($request, 'batches.view', 'batches.create', 'batches.layout_edit')) {
            return $this->deny($request);
        }

        return response()->json([
            'templates' => $this->loadStoredTemplates(),
            'activeTemplateId' => $this->loadActiveTemplateId(),
            'canEditLayout' => $this->can($request, 'batches.layout_edit'),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        if (!$this->can($request, 'batches.layout_edit')) {
            return $this->deny($request, 'Akses ditolak. Fitur Edit Layout Tag hanya dapat diubah oleh akun dengan izin Edit Layout Tag (Super Admin).');
        }

        $payload = $request->input('template');
        if (!is_array($payload)) {
            $payload = $request->all();
        }

        $validated = $this->sanitizeTemplate($payload);
        if (empty($validated['valid']) || empty($validated['template'])) {
            return response()->json([
                'success' => false,
                'error' => $validated['error'] ?? 'Data template layout tidak valid.',
            ], 400);
        }

        $clean = $validated['template'];
        $templates = $this->loadStoredTemplates();

        $found = false;
        foreach ($templates as $idx => $existing) {
            if (($existing['id'] ?? '') === $clean['id']) {
                $existingCreatedAt = !empty($existing['createdAt']) ? (string) $existing['createdAt'] : null;
                $clean['createdAt'] = $existingCreatedAt ?: ($clean['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'));
                $templates[$idx] = $clean;
                $found = true;
                break;
            }
        }
        if (!$found) {
            if (empty($clean['isBuiltIn']) && empty($clean['createdAt'])) {
                $clean['createdAt'] = gmdate('Y-m-d\TH:i:s\Z');
            }
            $templates[] = $clean;
        }

        $nowStr = RbacService::formatIndonesianTimestamp();
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY_TEMPLATES],
            ['value' => json_encode(array_values($templates)), 'updatedAt' => $nowStr]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY_ACTIVE_ID],
            ['value' => $clean['id'], 'updatedAt' => $nowStr]
        );

        $authUser = $this->authUser($request);
        $actorLabel = $this->actorLabel($request);
        try {
            DB::table('audit_logs')->insert([
                'id' => ((int) round(microtime(true) * 1000)) + random_int(0, 999),
                'batchId' => 'TAG-LAYOUT',
                'timestamp' => $nowStr,
                'user' => $authUser['name'] ?? 'Super Admin',
                'reason' => ($found ? 'Pembaruan' : 'Pembuatan') . " Template Layout Tag ({$clean['name']})",
                'changes' => json_encode([
                    ['field' => 'Template', 'old' => '-', 'new' => $clean['name']],
                    ['field' => 'Ukuran Tag', 'old' => '-', 'new' => "{$clean['sticker']['widthMm']}×{$clean['sticker']['heightMm']} mm"],
                    ['field' => 'Kertas', 'old' => '-', 'new' => "{$clean['sheet']['preset']} ({$clean['sheet']['widthMm']}×{$clean['sheet']['heightMm']} mm)"],
                ]),
            ]);
        } catch (\Throwable) {
        }

        NotificationService::createNotification([
            'type' => 'system',
            'severity' => 'info',
            'audience' => 'super_admin',
            'title' => 'Template Layout Tag Diperbarui',
            'subtitle' => "Diperbarui oleh {$actorLabel}",
            'description' => "Template \"{$clean['name']}\" ({$clean['sticker']['widthMm']}×{$clean['sticker']['heightMm']} mm) telah disimpan.",
            'entityType' => 'tag_layout',
            'entityId' => $clean['id'],
            'status' => 'edited',
            'metadata' => [
                'actor' => $actorLabel,
                'templateId' => $clean['id'],
                'templateName' => $clean['name'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'template' => $clean,
            'templates' => array_values($templates),
            'activeTemplateId' => $clean['id'],
        ]);
    }

    public function setActive(Request $request): JsonResponse
    {
        if (!$this->can($request, 'batches.layout_edit')) {
            return $this->deny($request, 'Akses ditolak. Hanya akun dengan izin Edit Layout Tag (Super Admin) yang dapat menetapkan template default.');
        }

        $templateId = SecurityHardeningService::sanitizeStringInput($request->input('templateId', ''), 80);
        if ($templateId === '' || !preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $templateId)) {
            return response()->json(['success' => false, 'error' => 'ID template tidak valid.'], 400);
        }

        $existsInBuiltIns = in_array($templateId, self::BUILT_IN_IDS, true);
        $existsInStored = false;
        if (!$existsInBuiltIns) {
            foreach ($this->loadStoredTemplates() as $tpl) {
                if (($tpl['id'] ?? '') === $templateId) {
                    $existsInStored = true;
                    break;
                }
            }
        }
        if (!$existsInBuiltIns && !$existsInStored) {
            return response()->json(['success' => false, 'error' => 'Template layout tidak ditemukan.'], 404);
        }

        $nowStr = RbacService::formatIndonesianTimestamp();
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY_ACTIVE_ID],
            ['value' => $templateId, 'updatedAt' => $nowStr]
        );

        return response()->json([
            'success' => true,
            'activeTemplateId' => $templateId,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->can($request, 'batches.layout_edit')) {
            return $this->deny($request, 'Akses ditolak. Hanya akun dengan izin Edit Layout Tag (Super Admin) yang dapat menghapus template layout.');
        }

        $cleanId = SecurityHardeningService::sanitizeStringInput($id, 80);
        if (in_array($cleanId, self::BUILT_IN_IDS, true)) {
            return response()->json(['success' => false, 'error' => 'Template bawaan sistem tidak dapat dihapus.'], 400);
        }

        $templates = $this->loadStoredTemplates();
        $removedTemplate = null;
        $remaining = [];
        foreach ($templates as $t) {
            if (($t['id'] ?? '') === $cleanId) {
                $removedTemplate = $t;
            } else {
                $remaining[] = $t;
            }
        }
        if ($removedTemplate === null) {
            return response()->json(['success' => false, 'error' => 'Template tidak ditemukan.'], 404);
        }

        $authUser = $this->authUser($request);
        $trashId = TrashService::moveToTrash([
            'entityType' => 'tag_layout',
            'entityId' => $cleanId,
            'title' => (string) ($removedTemplate['name'] ?? $cleanId),
            'subtitle' => sprintf(
                '%s×%s mm · Kertas %s',
                $removedTemplate['sticker']['widthMm'] ?? '-',
                $removedTemplate['sticker']['heightMm'] ?? '-',
                $removedTemplate['sheet']['preset'] ?? 'Custom'
            ),
            'deletedById' => $authUser['id'] ?? null,
            'deletedByName' => $this->actorLabel($request),
            'itemCount' => 1,
            'payload' => ['template' => $removedTemplate],
        ]);

        $nowStr = RbacService::formatIndonesianTimestamp();
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY_TEMPLATES],
            ['value' => json_encode($remaining), 'updatedAt' => $nowStr]
        );

        $activeId = $this->loadActiveTemplateId();
        if ($activeId === $cleanId) {
            $activeId = self::DEFAULT_ACTIVE_ID;
            DB::table('system_settings')->updateOrInsert(
                ['key' => self::SETTINGS_KEY_ACTIVE_ID],
                ['value' => $activeId, 'updatedAt' => $nowStr]
            );
        }

        return response()->json([
            'success' => true,
            'trashed' => true,
            'trashId' => $trashId,
            'templates' => $remaining,
            'activeTemplateId' => $activeId,
        ]);
    }
}
