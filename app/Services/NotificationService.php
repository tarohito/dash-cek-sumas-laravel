<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public static function getActorLabel(array $actor): string
    {
        if (RbacService::normalizeRole($actor['role'] ?? null) === 'Super Admin') {
            return 'Administrator';
        }
        return $actor['name'] ?? 'Sistem';
    }

    public static function createNotification(array $input): void
    {
        try {
            $nowMs = $input['createdAtTs'] ?? (int) round(microtime(true) * 1000);
            $dedupeKey = $input['dedupeKey'] ?? null;
            if ($dedupeKey) {
                $windowMs = (int) ($input['dedupeWindowMs'] ?? 30000);
                $existing = DB::table('notifications')
                    ->where('dedupeKey', $dedupeKey)
                    ->orderByDesc('createdAtTs')
                    ->first(['id', 'createdAtTs']);
                if ($existing && ($nowMs - (int) $existing->createdAtTs) < $windowMs) {
                    return;
                }
            }

            $id = $input['id'] ?? ('notif-' . $nowMs . '-' . bin2hex(random_bytes(3)));
            DB::table('notifications')->insert([
                'id' => $id,
                'type' => $input['type'],
                'severity' => $input['severity'] ?? 'info',
                'audience' => $input['audience'] ?? 'brand_scope',
                'targetUserId' => $input['targetUserId'] ?? null,
                'brandName' => $input['brandName'] ?? null,
                'brandId' => $input['brandId'] ?? null,
                'title' => $input['title'],
                'subtitle' => $input['subtitle'] ?? '',
                'description' => $input['description'] ?? '',
                'entityType' => $input['entityType'] ?? null,
                'entityId' => $input['entityId'] ?? null,
                'status' => $input['status'] ?? 'info',
                'metadata' => isset($input['metadata']) ? json_encode($input['metadata']) : null,
                'dedupeKey' => $dedupeKey,
                'createdAtTs' => $nowMs,
            ]);
        } catch (\Throwable) {
        }
    }

    public static function updateNotificationByEntity(string $entityType, string $entityId, array $updates): void
    {
        try {
            $row = DB::table('notifications')
                ->where('entityType', $entityType)
                ->where('entityId', $entityId)
                ->orderByDesc('createdAtTs')
                ->first();
            if (!$row) {
                return;
            }
            $prevMeta = [];
            if (!empty($row->metadata)) {
                $decoded = json_decode((string) $row->metadata, true);
                if (is_array($decoded)) {
                    $prevMeta = $decoded;
                }
            }
            $mergedMeta = isset($updates['metadata']) && is_array($updates['metadata'])
                ? array_merge($prevMeta, $updates['metadata'])
                : $prevMeta;

            DB::table('notifications')
                ->where('id', $row->id)
                ->update([
                    'status' => $updates['status'] ?? $row->status,
                    'severity' => $updates['severity'] ?? $row->severity,
                    'description' => $updates['description'] ?? $row->description,
                    'metadata' => json_encode($mergedMeta),
                ]);
        } catch (\Throwable) {
        }
    }

    public static function canUserAccessRow(object|array $rowObj, array $ctx): bool
    {
        $row = (array) $rowObj;
        $audience = (string) ($row['audience'] ?? 'brand_scope');

        if ($audience === 'user') {
            return !empty($ctx['userId']) && !empty($row['targetUserId']) && $row['targetUserId'] === $ctx['userId'];
        }
        if ($audience === 'super_admin') {
            return !empty($ctx['canManageUsers']);
        }
        if ($audience === 'brand_scope') {
            if (!empty($ctx['isGlobal'])) {
                return true;
            }
            $bId = trim((string) ($row['brandId'] ?? ''));
            $bName = strtolower(trim((string) ($row['brandName'] ?? '')));
            if ($bId !== '' && isset($ctx['ownedBrandIds'][$bId])) {
                return true;
            }
            if ($bName !== '' && isset($ctx['ownedBrandNames'][$bName])) {
                return true;
            }
            return false;
        }
        return true;
    }

    public static function formatRelativeTimeId(int $tsMs, int $nowMs): string
    {
        $diffSec = max(0, (int) floor(($nowMs - $tsMs) / 1000));
        if ($diffSec < 45) {
            return 'Baru saja';
        }
        $diffMin = (int) floor($diffSec / 60);
        if ($diffMin < 60) {
            return "{$diffMin} menit lalu";
        }
        $diffHr = (int) floor($diffMin / 60);
        if ($diffHr < 24) {
            return "{$diffHr} jam lalu";
        }
        $diffDay = (int) floor($diffHr / 24);
        if ($diffDay < 7) {
            return "{$diffDay} hari lalu";
        }

        $dt = (new \DateTimeImmutable('@' . (int) floor($tsMs / 1000)))->setTimezone(new \DateTimeZone('Asia/Jakarta'));
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
        return $dt->format('d') . ' ' . $months[(int) $dt->format('n') - 1];
    }

    public static function formatRowForClient(object|array $rowObj, array $readIds, int $nowMs): array
    {
        $row = (array) $rowObj;
        $meta = [];
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string) $row['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $createdTs = !empty($row['createdAtTs']) ? (int) $row['createdAtTs'] : $nowMs;
        $isActionableReset = ($row['type'] ?? '') === 'password_reset' && ($row['status'] ?? '') === 'Menunggu';

        return [
            'id' => $row['id'],
            'sourceId' => $row['entityId'] ?? $row['id'],
            'category' => $row['type'],
            'severity' => $row['severity'] ?? 'info',
            'audience' => $row['audience'] ?? 'brand_scope',
            'brandName' => $row['brandName'] ?? null,
            'title' => $row['title'],
            'subtitle' => $row['subtitle'] ?? '',
            'description' => $row['description'] ?? '',
            'timestamp' => RbacService::formatIndonesianTimestamp($createdTs),
            'timeAgo' => self::formatRelativeTimeId($createdTs, $nowMs),
            'createdAtTs' => $createdTs,
            'status' => $row['status'] ?? 'info',
            'isRead' => $isActionableReset ? false : isset($readIds[$row['id']]),
            'user' => $meta['user'] ?? null,
            'emailSent' => $meta['emailSent'] ?? null,
            'tempPassword' => $meta['tempPassword'] ?? null,
            'batchId' => $meta['batchId'] ?? null,
            'brand' => $meta['brand'] ?? ($row['brandName'] ?? null),
            'city' => $meta['city'] ?? null,
            'province' => $meta['province'] ?? null,
            'totalScans' => $meta['totalScans'] ?? null,
            'todayScans' => $meta['todayScans'] ?? null,
            'changes' => isset($meta['changes']) && is_array($meta['changes']) ? $meta['changes'] : [],
        ];
    }

    /**
     * @return array{success: bool, target: string, detail: string}
     */
    public static function sendAlertChannel(string $channel, array $cfg, string $subject, string $plainText, string $htmlBody): array
    {
        if ($channel === 'telegram' || $channel === 'whatsapp') {
            $label = $channel === 'telegram' ? 'Telegram Bot' : 'WhatsApp Gateway';
            return [
                'success' => true,
                'target' => '-',
                'detail' => "Integrasi API {$label} dilewati sementara sehingga tidak mengirim permintaan ke server eksternal.",
            ];
        }

        $rawRecipients = (string) ($cfg['emailRecipients'] ?? '');
        $parts = preg_split('/[,;]+/', $rawRecipients) ?: [];
        $recipients = array_values(array_filter(array_map('trim', $parts), fn (string $s) => str_contains($s, '@')));
        if (count($recipients) === 0) {
            return ['success' => false, 'target' => '-', 'detail' => 'Alamat email penerima belum diisi atau tidak valid.'];
        }

        $resendKey = env('RESEND_API_KEY');
        if (is_string($resendKey) && $resendKey !== '') {
            try {
                $res = Http::withToken($resendKey)->post('https://api.resend.com/emails', [
                    'from' => env('RESEND_FROM', 'Security Alert <onboarding@resend.dev>'),
                    'to' => $recipients,
                    'subject' => $subject,
                    'text' => $plainText,
                    'html' => '<div style="font-family:sans-serif;line-height:1.6">' . str_replace("\n", '<br/>', $htmlBody) . '</div>',
                ]);
                $data = $res->json();
                if ($res->successful() && !empty($data['id'])) {
                    return [
                        'success' => true,
                        'target' => implode(', ', $recipients),
                        'detail' => "Email terkirim via Resend API (id: {$data['id']}).",
                    ];
                }
                return [
                    'success' => false,
                    'target' => implode(', ', $recipients),
                    'detail' => 'Resend API menolak (' . $res->status() . '): ' . ($data['message'] ?? 'Periksa RESEND_API_KEY.'),
                ];
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'target' => implode(', ', $recipients),
                    'detail' => 'Gagal menghubungi Resend API: ' . $e->getMessage(),
                ];
            }
        }

        $mailHost = config('mail.mailers.smtp.host');
        $mailer = config('mail.default');
        if ($mailer === 'smtp' && !empty($mailHost) && $mailHost !== '127.0.0.1') {
            try {
                Mail::html('<div style="font-family:sans-serif;line-height:1.6">' . str_replace("\n", '<br/>', $htmlBody) . '</div>', function ($message) use ($recipients, $subject) {
                    $message->to($recipients)->subject($subject);
                });
                return [
                    'success' => true,
                    'target' => implode(', ', $recipients),
                    'detail' => 'Email terkirim melalui konfigurasi SMTP Laravel.',
                ];
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'target' => implode(', ', $recipients),
                    'detail' => 'Gagal mengirim email via SMTP: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => false,
            'target' => implode(', ', $recipients),
            'detail' => 'Layanan SMTP atau RESEND_API_KEY belum dikonfigurasi di file .env.',
        ];
    }
}
