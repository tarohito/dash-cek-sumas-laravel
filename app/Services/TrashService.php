<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrashService
{
    public const RETENTION_DAYS = 30;
    public const RETENTION_MS = self::RETENTION_DAYS * 24 * 60 * 60 * 1000;

    public const VALID_ENTITY_TYPES = [
        'brand',
        'product',
        'category',
        'batch',
        'user',
        'tag_layout',
    ];

    public static function ensureTableExists(): void
    {
        if (Schema::hasTable('trash_items')) {
            return;
        }

        Schema::create('trash_items', function ($table) {
            $table->string('id', 128)->primary();
            $table->string('entityType', 40)->index('idx_trash_entity_type');
            $table->string('entityId', 128)->index('idx_trash_entity_id');
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->string('brandName', 120)->nullable()->index('idx_trash_brand');
            $table->string('brandId', 128)->nullable();
            $table->string('deletedById', 128)->nullable();
            $table->string('deletedByName', 120)->nullable();
            $table->string('deletedAt', 80);
            $table->bigInteger('deletedAtTs')->index('idx_trash_deleted_at');
            $table->bigInteger('expiresAtTs')->index('idx_trash_expires_at');
            $table->integer('itemCount')->default(1);
            $table->longText('payload');
        });
    }

    public static function purgeExpired(): int
    {
        self::ensureTableExists();
        $nowMs = (int) round(microtime(true) * 1000);
        return DB::table('trash_items')->where('expiresAtTs', '<=', $nowMs)->delete();
    }

    /**
     * @param array{
     *   entityType: string,
     *   entityId: string,
     *   title: string,
     *   subtitle?: string|null,
     *   brandName?: string|null,
     *   brandId?: string|null,
     *   deletedById?: string|null,
     *   deletedByName?: string|null,
     *   itemCount?: int,
     *   payload: array<string, mixed>
     * } $data
     */
    public static function moveToTrash(array $data): string
    {
        self::ensureTableExists();
        $nowMs = (int) round(microtime(true) * 1000);
        $nowSec = (int) floor($nowMs / 1000);
        $trashId = 'tr-' . $nowMs . '-' . bin2hex(random_bytes(2));

        DB::table('trash_items')->insert([
            'id' => $trashId,
            'entityType' => (string) $data['entityType'],
            'entityId' => (string) $data['entityId'],
            'title' => mb_substr((string) ($data['title'] ?: $data['entityId']), 0, 255),
            'subtitle' => isset($data['subtitle']) && $data['subtitle'] !== ''
                ? mb_substr((string) $data['subtitle'], 0, 255)
                : null,
            'brandName' => isset($data['brandName']) && $data['brandName'] !== ''
                ? mb_substr((string) $data['brandName'], 0, 120)
                : null,
            'brandId' => isset($data['brandId']) && $data['brandId'] !== ''
                ? mb_substr((string) $data['brandId'], 0, 128)
                : null,
            'deletedById' => $data['deletedById'] ?? null,
            'deletedByName' => isset($data['deletedByName']) && $data['deletedByName'] !== ''
                ? mb_substr((string) $data['deletedByName'], 0, 120)
                : 'Sistem',
            'deletedAt' => gmdate('Y-m-d\TH:i:s\Z', $nowSec),
            'deletedAtTs' => $nowMs,
            'expiresAtTs' => $nowMs + self::RETENTION_MS,
            'itemCount' => max(1, (int) ($data['itemCount'] ?? 1)),
            'payload' => json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $trashId;
    }

    public static function requiredPermissionForType(string $entityType): string
    {
        return match ($entityType) {
            'brand' => 'brands.manage',
            'product' => 'sku.manage',
            'category' => 'categories.manage',
            'batch' => 'batches.delete',
            'user' => 'users.manage',
            'tag_layout' => 'batches.layout_edit',
            default => 'settings.manage',
        };
    }

    public static function canUserAccessItem(?array $authUser, array $scope, array $matrix, object $item): bool
    {
        if (!$authUser) {
            return false;
        }

        $perm = self::requiredPermissionForType((string) ($item->entityType ?? ''));
        if (!RbacService::hasPermission($authUser['role'] ?? null, $perm, $matrix)) {
            return false;
        }

        if (!empty($scope['isGlobal'])) {
            return true;
        }

        if (($item->deletedById ?? null) !== null && ($item->deletedById ?? null) === ($authUser['id'] ?? null)) {
            return true;
        }

        $type = (string) ($item->entityType ?? '');
        if (in_array($type, ['brand', 'product', 'batch'], true)) {
            return RbacService::canScopeAccessBrand($scope, $item->brandName ?? null, $item->brandId ?? null);
        }

        return false;
    }

    public static function formatForClient(object $row, int $nowMs): array
    {
        $expiresAtTs = (int) ($row->expiresAtTs ?? ($nowMs + self::RETENTION_MS));
        $remainingMs = max(0, $expiresAtTs - $nowMs);
        $daysRemaining = (int) ceil($remainingMs / (24 * 60 * 60 * 1000));

        return [
            'id' => (string) $row->id,
            'entityType' => (string) $row->entityType,
            'entityId' => (string) $row->entityId,
            'title' => (string) $row->title,
            'subtitle' => $row->subtitle ?? null,
            'brandName' => $row->brandName ?? null,
            'brandId' => $row->brandId ?? null,
            'deletedById' => $row->deletedById ?? null,
            'deletedByName' => $row->deletedByName ?? 'Sistem',
            'deletedAt' => (string) $row->deletedAt,
            'deletedAtTs' => (int) $row->deletedAtTs,
            'expiresAtTs' => $expiresAtTs,
            'daysRemaining' => $daysRemaining,
            'itemCount' => max(1, (int) ($row->itemCount ?? 1)),
        ];
    }

    /**
     * @return array{success: bool, status?: int, error?: string, restoredCount?: int}
     */
    public static function restoreItem(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? '{}'), true);
        if (!is_array($payload)) {
            return ['success' => false, 'status' => 422, 'error' => 'Payload data di Tempat Sampah rusak dan tidak dapat dipulihkan.'];
        }

        $entityType = (string) $row->entityType;

        if ($entityType === 'brand') {
            $brand = $payload['brand'] ?? $payload;
            if (!is_array($brand) || empty($brand['id'])) {
                return ['success' => false, 'status' => 422, 'error' => 'Data brand tidak lengkap.'];
            }
            if (DB::table('brands')->where('id', $brand['id'])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => "Brand dengan ID {$brand['id']} sudah ada di daftar aktif."];
            }
            $ownerId = (string) ($brand['ownerId'] ?? '');
            if ($ownerId !== '' && !DB::table('users')->where('id', $ownerId)->exists()) {
                $ownerId = '';
            }
            DB::transaction(function () use ($row, $brand, $ownerId) {
                DB::table('brands')->insert([
                    'id' => $brand['id'],
                    'name' => $brand['name'] ?? $row->title,
                    'logo' => $brand['logo'] ?? null,
                    'ownerId' => $ownerId,
                    'description' => $brand['description'] ?? '',
                    'businessType' => $brand['businessType'] ?? 'Retail',
                    'status' => $brand['status'] ?? 'Aktif',
                    'createdAt' => $brand['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => 1];
        }

        if ($entityType === 'product') {
            $prod = $payload['product'] ?? $payload;
            if (!is_array($prod) || empty($prod['id'])) {
                return ['success' => false, 'status' => 422, 'error' => 'Data produk SKU tidak lengkap.'];
            }
            if (DB::table('products')->where('id', $prod['id'])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => "Produk SKU dengan ID {$prod['id']} sudah ada di katalog aktif."];
            }
            DB::transaction(function () use ($row, $prod) {
                DB::table('products')->insert([
                    'id' => $prod['id'],
                    'brandId' => $prod['brandId'] ?? null,
                    'brandName' => $prod['brandName'] ?? ($row->brandName ?? ''),
                    'sku' => $prod['sku'] ?? $row->title,
                    'category' => $prod['category'] ?? null,
                    'isB2B' => !empty($prod['isB2B']) ? 1 : 0,
                    'description' => $prod['description'] ?? null,
                    'createdAt' => $prod['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                    'skuCode' => $prod['skuCode'] ?? null,
                    'image' => $prod['image'] ?? null,
                ]);
                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => 1];
        }

        if ($entityType === 'category') {
            $items = $payload['items'] ?? (isset($payload['id']) ? [$payload] : []);
            $root = $payload['root'] ?? ($items[0] ?? null);
            if (!is_array($items) || count($items) === 0 || !is_array($root)) {
                return ['success' => false, 'status' => 422, 'error' => 'Data kategori tidak lengkap.'];
            }
            $rootParentId = $root['parentId'] ?? null;
            if (!empty($rootParentId) && !DB::table('categories')->where('id', $rootParentId)->exists()) {
                return [
                    'success' => false,
                    'status' => 409,
                    'error' => 'Kategori induk belum tersedia. Pulihkan kategori induknya terlebih dahulu sebelum memulihkan sub-kategori ini.',
                ];
            }
            $restored = 0;
            DB::transaction(function () use ($row, $items, &$restored) {
                usort($items, fn ($a, $b) => ((int) ($a['level'] ?? 1)) <=> ((int) ($b['level'] ?? 1)));
                foreach ($items as $cat) {
                    if (empty($cat['id']) || DB::table('categories')->where('id', $cat['id'])->exists()) {
                        continue;
                    }
                    DB::table('categories')->insert([
                        'id' => $cat['id'],
                        'name' => $cat['name'] ?? 'Kategori',
                        'parentId' => $cat['parentId'] ?? null,
                        'level' => (int) ($cat['level'] ?? 1),
                        'createdAt' => $cat['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                        'specSchema' => $cat['specSchema'] ?? null,
                    ]);
                    $restored++;
                }
                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => max(1, $restored)];
        }

        if ($entityType === 'batch') {
            $batch = $payload['batch'] ?? null;
            $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
            $auditLogs = is_array($payload['auditLogs'] ?? null) ? $payload['auditLogs'] : [];
            if (!is_array($batch) || empty($batch['id'])) {
                return ['success' => false, 'status' => 422, 'error' => 'Data batch tidak lengkap.'];
            }
            if (DB::table('batches')->where('id', $batch['id'])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => "Batch {$batch['batchId']} sudah ada di daftar aktif."];
            }
            DB::transaction(function () use ($row, $batch, $tags, $auditLogs) {
                DB::table('batches')->insert([
                    'id' => $batch['id'],
                    'batchId' => $batch['batchId'] ?? $row->title,
                    'date' => $batch['date'] ?? RbacService::formatIndonesianTimestamp(),
                    'project' => $batch['project'] ?? null,
                    'sku' => $batch['sku'] ?? '',
                    'brand' => $batch['brand'] ?? ($row->brandName ?? ''),
                    'qty' => (int) ($batch['qty'] ?? count($tags)),
                    'specs' => $batch['specs'] ?? null,
                    'pinLength' => (int) ($batch['pinLength'] ?? 8),
                    'status' => $batch['status'] ?? 'Aktif',
                    'isB2B' => !empty($batch['isB2B']) ? 1 : 0,
                    'redirectUrl' => $batch['redirectUrl'] ?? 'https://cek.sumas.id/',
                    'qrType' => $batch['qrType'] ?? 'dynamic',
                    'requirePin' => array_key_exists('requirePin', $batch) ? (!empty($batch['requirePin']) ? 1 : 0) : 1,
                    'enableGps' => array_key_exists('enableGps', $batch) ? (!empty($batch['enableGps']) ? 1 : 0) : 1,
                ]);

                if (count($tags) > 0) {
                    $tagRows = [];
                    foreach ($tags as $t) {
                        if (!is_array($t) || empty($t['id'])) {
                            continue;
                        }
                        $tagRows[] = [
                            'id' => $t['id'],
                            'batchId' => $t['batchId'] ?? $batch['id'],
                            'tagNumber' => $t['tagNumber'] ?? '',
                            'pin' => $t['pin'] ?? '',
                            'status' => $t['status'] ?? 'Tersedia',
                            'project' => $t['project'] ?? null,
                            'specs' => $t['specs'] ?? null,
                            'codeType' => $t['codeType'] ?? null,
                            'warranty' => $t['warranty'] ?? null,
                            'prodCode' => $t['prodCode'] ?? null,
                            'note' => $t['note'] ?? null,
                            'allocatedAt' => $t['allocatedAt'] ?? null,
                        ];
                    }
                    foreach (array_chunk($tagRows, 300) as $chunk) {
                        DB::table('tags')->insertOrIgnore($chunk);
                    }
                }

                if (count($auditLogs) > 0) {
                    $logRows = [];
                    foreach ($auditLogs as $l) {
                        if (!is_array($l) || !isset($l['id'])) {
                            continue;
                        }
                        $logRows[] = [
                            'id' => $l['id'],
                            'batchId' => $l['batchId'] ?? ($batch['batchId'] ?? ''),
                            'timestamp' => $l['timestamp'] ?? '',
                            'user' => $l['user'] ?? 'System',
                            'reason' => $l['reason'] ?? '',
                            'changes' => is_string($l['changes'] ?? null) ? $l['changes'] : json_encode($l['changes'] ?? []),
                        ];
                    }
                    foreach (array_chunk($logRows, 200) as $chunk) {
                        DB::table('audit_logs')->insertOrIgnore($chunk);
                    }
                }

                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => 1 + count($tags)];
        }

        if ($entityType === 'user') {
            $user = $payload['user'] ?? $payload;
            $linkedBrandIds = is_array($payload['linkedBrandIds'] ?? null) ? $payload['linkedBrandIds'] : [];
            if (!is_array($user) || empty($user['id']) || empty($user['email'])) {
                return ['success' => false, 'status' => 422, 'error' => 'Data pengguna tidak lengkap.'];
            }
            if (DB::table('users')->where('id', $user['id'])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => 'Akun pengguna dengan ID ini sudah aktif.'];
            }
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower((string) $user['email'])])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => "Email {$user['email']} sudah digunakan oleh akun aktif lain."];
            }
            if (!empty($user['username']) && DB::table('users')->whereRaw('LOWER(username) = ?', [strtolower((string) $user['username'])])->exists()) {
                return ['success' => false, 'status' => 409, 'error' => "Username @{$user['username']} sudah digunakan oleh akun aktif lain."];
            }

            DB::transaction(function () use ($row, $user, $linkedBrandIds) {
                DB::table('users')->insert([
                    'id' => $user['id'],
                    'name' => $user['name'] ?? $row->title,
                    'username' => $user['username'] ?? null,
                    'email' => $user['email'],
                    'phone' => $user['phone'] ?? null,
                    'role' => RbacService::normalizeRole($user['role'] ?? null),
                    'avatar' => $user['avatar'] ?? null,
                    'status' => $user['status'] ?? 'Aktif',
                    'createdAt' => $user['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                    'password' => $user['password'] ?? null,
                ]);
                if (count($linkedBrandIds) > 0) {
                    DB::table('brands')->whereIn('id', $linkedBrandIds)->update(['ownerId' => $user['id']]);
                }
                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => 1];
        }

        if ($entityType === 'tag_layout') {
            $template = $payload['template'] ?? $payload;
            if (!is_array($template) || empty($template['id'])) {
                return ['success' => false, 'status' => 422, 'error' => 'Data template layout tag tidak lengkap.'];
            }

            DB::transaction(function () use ($row, $template) {
                $settingsRow = DB::table('system_settings')->where('key', 'tag_layout_templates')->first(['value']);
                $templates = [];
                if ($settingsRow && !empty($settingsRow->value)) {
                    $decoded = json_decode((string) $settingsRow->value, true);
                    if (is_array($decoded)) {
                        $templates = $decoded;
                    }
                }
                $exists = false;
                foreach ($templates as $t) {
                    if (($t['id'] ?? '') === $template['id']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $templates[] = $template;
                }
                $nowStr = RbacService::formatIndonesianTimestamp();
                DB::table('system_settings')->updateOrInsert(
                    ['key' => 'tag_layout_templates'],
                    ['value' => json_encode(array_values($templates)), 'updatedAt' => $nowStr]
                );
                DB::table('trash_items')->where('id', $row->id)->delete();
            });
            return ['success' => true, 'restoredCount' => 1];
        }

        return ['success' => false, 'status' => 400, 'error' => 'Jenis item Tempat Sampah tidak dikenali.'];
    }
}
