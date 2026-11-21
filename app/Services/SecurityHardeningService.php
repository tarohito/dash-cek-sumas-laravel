<?php

namespace App\Services;

use Illuminate\Http\Request;

class SecurityHardeningService
{
    public const FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];
    public const USERNAME_MIN_LENGTH = 3;
    public const USERNAME_MAX_LENGTH = 30;
    public const RESERVED_USERNAMES = [
        'null',
        'undefined',
        'root',
        'system',
        'api',
        'auth',
        'login',
        'logout',
        'me',
        'anonymous',
    ];

    public static function normalizeUsernameInput(mixed $raw): string
    {
        if ($raw === null || is_array($raw) || is_object($raw)) {
            return '';
        }
        $str = strtolower(trim((string) $raw));
        $str = preg_replace('/^@+/', '', $str) ?? $str;
        $str = preg_replace('/\s+/', '', $str) ?? $str;
        return $str;
    }

    /**
     * @return array{valid: bool, normalized: string, error?: string, checks: array<string, bool>}
     */
    public static function validateUsernameFormat(mixed $raw): array
    {
        $normalized = self::normalizeUsernameInput($raw);
        $len = strlen($normalized);

        $length = $len >= self::USERNAME_MIN_LENGTH && $len <= self::USERNAME_MAX_LENGTH;
        $allowedChars = $len > 0 && preg_match('/^[a-z0-9._-]+$/', $normalized) === 1;
        $edgeAlnum = $len > 0 && preg_match('/^[a-z0-9]/', $normalized) === 1 && preg_match('/[a-z0-9]$/', $normalized) === 1;
        $noConsecutiveSep = $len > 0 && preg_match('/[._-]{2}/', $normalized) !== 1;
        $notReserved = $len > 0 && !in_array($normalized, self::RESERVED_USERNAMES, true);

        $checks = [
            'length' => $length,
            'allowedChars' => $allowedChars,
            'edgeAlnum' => $edgeAlnum,
            'noConsecutiveSep' => $noConsecutiveSep,
            'notReserved' => $notReserved,
        ];

        if ($normalized === '') {
            return ['valid' => false, 'normalized' => $normalized, 'error' => 'Username wajib diisi.', 'checks' => $checks];
        }
        if (!$allowedChars) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'error' => 'Hanya boleh huruf kecil (a-z), angka (0-9), titik (.), garis bawah (_), atau tanda hubung (-).',
                'checks' => $checks,
            ];
        }
        if (!$edgeAlnum) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'error' => 'Harus diawali dan diakhiri huruf atau angka (tidak boleh diawali/diakhiri ., _, -).',
                'checks' => $checks,
            ];
        }
        if (!$noConsecutiveSep) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'error' => 'Tidak boleh ada dua tanda pemisah berurutan (mis. .., __, --, ._).',
                'checks' => $checks,
            ];
        }
        if (!$length) {
            $min = self::USERNAME_MIN_LENGTH;
            $max = self::USERNAME_MAX_LENGTH;
            return [
                'valid' => false,
                'normalized' => $normalized,
                'error' => "Panjang username harus {$min}–{$max} karakter.",
                'checks' => $checks,
            ];
        }
        if (!$notReserved) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'error' => 'Username tersebut termasuk kata sistem yang tidak dapat digunakan.',
                'checks' => $checks,
            ];
        }

        return ['valid' => true, 'normalized' => $normalized, 'checks' => $checks];
    }

    public static function escapeHtml(mixed $input): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'", '`'],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;', '&#96;'],
            (string) ($input ?? '')
        );
    }

    public static function escapeLikePattern(string $input): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input);
    }

    public static function sanitizeStringInput(mixed $input, int $maxLength = 500): string
    {
        if ($input === null) {
            return '';
        }

        if (is_array($input) || is_object($input)) {
            return '';
        }

        $str = (string) $input;
        $str = str_replace("\0", '', $str);
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str) ?? $str;
        $str = trim($str);

        return mb_substr($str, 0, $maxLength, 'UTF-8');
    }

    public static function isValidIpAddress(string $ip): bool
    {
        $cleaned = trim(preg_replace('/^::ffff:/', '', $ip) ?? $ip);
        return filter_var($cleaned, FILTER_VALIDATE_IP) !== false;
    }

    public static function getSafeClientIp(Request $request): string
    {
        $cfIp = $request->header('cf-connecting-ip');
        if (is_string($cfIp) && $cfIp !== '') {
            $candidate = trim(preg_replace('/^::ffff:/', '', $cfIp) ?? $cfIp);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $xff = $request->header('x-forwarded-for');
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            $first = preg_replace('/^::ffff:/', '', $first) ?? $first;
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = trim(preg_replace('/^::ffff:/', '', (string) $request->ip()) ?? '127.0.0.1');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '127.0.0.1';
    }

    public static function isPrivateOrReservedIpv4(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return true;
        }
        $nums = [];
        foreach ($parts as $p) {
            if (!ctype_digit($p)) {
                return true;
            }
            $n = (int) $p;
            if ($n < 0 || $n > 255) {
                return true;
            }
            $nums[] = $n;
        }
        [$a, $b] = $nums;
        if ($a === 0 || $a === 10 || $a === 127 || $a >= 224) {
            return true;
        }
        if ($a === 169 && $b === 254) {
            return true;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return true;
        }
        if ($a === 192 && $b === 168) {
            return true;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return true;
        }

        return false;
    }

    public static function isPrivateOrReservedHostname(string $hostname): bool
    {
        $host = trim(trim(strtolower($hostname), '[]'));
        if ($host === '') {
            return true;
        }

        if (
            $host === 'localhost' ||
            str_ends_with($host, '.localhost') ||
            str_ends_with($host, '.local') ||
            str_ends_with($host, '.internal') ||
            $host === 'metadata.google.internal' ||
            $host === 'metadata' ||
            $host === '::1' ||
            $host === '::' ||
            str_starts_with($host, 'fc') ||
            str_starts_with($host, 'fd') ||
            str_starts_with($host, 'fe80:')
        ) {
            return true;
        }

        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host)) {
            return true;
        }

        if (str_starts_with($host, '::ffff:')) {
            $mapped = substr($host, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isPrivateOrReservedIpv4($mapped);
            }
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPrivateOrReservedIpv4($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        return false;
    }

    /**
     * @return array{valid: bool, url: string, error?: string}
     */
    public static function validateHttpUrl(
        mixed $rawUrl,
        bool $allowEmpty = false,
        string $defaultUrl = '',
        int $maxLength = 2048
    ): array {
        $trimmed = self::sanitizeStringInput($rawUrl, $maxLength);
        if ($trimmed === '') {
            if ($allowEmpty) {
                return ['valid' => true, 'url' => $defaultUrl];
            }
            return ['valid' => false, 'url' => '', 'error' => 'URL tidak boleh kosong.'];
        }

        if (preg_match('/[\s<>\'"`]/', $trimmed)) {
            return ['valid' => false, 'url' => '', 'error' => 'URL mengandung karakter tidak valid.'];
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['valid' => false, 'url' => '', 'error' => 'Format URL tidak valid.'];
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return ['valid' => false, 'url' => '', 'error' => 'URL hanya boleh menggunakan protokol http:// atau https://.'];
        }

        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return ['valid' => false, 'url' => '', 'error' => 'URL tidak boleh mengandung kredensial tertanam.'];
        }

        return ['valid' => true, 'url' => $trimmed];
    }

    /**
     * @return array{valid: bool, url: string, error?: string}
     */
    public static function validatePublicWebhookUrl(mixed $rawUrl): array
    {
        $base = self::validateHttpUrl($rawUrl, false, '', 1024);
        if (!$base['valid']) {
            return $base;
        }

        $parsed = parse_url($base['url']);
        $host = $parsed['host'] ?? '';
        if (self::isPrivateOrReservedHostname($host)) {
            return [
                'valid' => false,
                'url' => '',
                'error' => 'URL tujuan tidak diizinkan mengarah ke jaringan lokal, localhost, atau alamat privat (proteksi SSRF).',
            ];
        }

        return $base;
    }

    /**
     * @return array{valid: bool, value: ?string, error?: string}
     */
    public static function validateSafeImageSource(mixed $rawSrc, int $maxChars = 3145728): array
    {
        if ($rawSrc === null || $rawSrc === '') {
            return ['valid' => true, 'value' => null];
        }
        if (!is_string($rawSrc)) {
            return ['valid' => false, 'value' => null, 'error' => 'Format sumber gambar tidak valid.'];
        }

        $trimmed = trim(str_replace("\0", '', $rawSrc));
        if ($trimmed === '') {
            return ['valid' => true, 'value' => null];
        }
        if (strlen($trimmed) > $maxChars) {
            return ['valid' => false, 'value' => null, 'error' => 'Ukuran gambar melebihi batas maksimal (2 MB).'];
        }

        if (str_starts_with($trimmed, '/assets/') && !str_contains($trimmed, '..') && !preg_match('/[\s<>\'"`]/', $trimmed)) {
            return ['valid' => true, 'value' => $trimmed];
        }

        if (preg_match('/^data:image\/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+\/=\s]+$/i', $trimmed)) {
            return ['valid' => true, 'value' => $trimmed];
        }

        if (preg_match('/^https?:\/\//i', $trimmed)) {
            $checked = self::validateHttpUrl($trimmed, false, '', 2048);
            if ($checked['valid']) {
                return ['valid' => true, 'value' => $checked['url']];
            }
        }

        return [
            'valid' => false,
            'value' => null,
            'error' => 'Sumber gambar tidak diizinkan. Gunakan PNG/JPG/WebP/GIF atau URL HTTPS yang valid.',
        ];
    }

    /**
     * @return array{ok: bool, value: mixed}
     */
    public static function sanitizeRecursive(mixed $value, int $depth = 0, int $maxDepth = 12): array
    {
        if ($depth > $maxDepth) {
            return ['ok' => false, 'value' => null];
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return ['ok' => true, 'value' => $value];
        }
        if (is_string($value)) {
            return ['ok' => true, 'value' => str_replace("\0", '', $value)];
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
                    continue;
                }
                $child = self::sanitizeRecursive($v, $depth + 1, $maxDepth);
                if (!$child['ok']) {
                    return ['ok' => false, 'value' => null];
                }
                $clean[$k] = $child['value'];
            }
            return ['ok' => true, 'value' => $clean];
        }

        return ['ok' => true, 'value' => $value];
    }
}
