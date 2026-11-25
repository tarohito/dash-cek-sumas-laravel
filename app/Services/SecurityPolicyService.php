<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecurityPolicyService
{
    public const SETTINGS_CACHE_KEY = 'sumas_app_settings_v1';
    public const INDONESIAN_CITIES = [
        ['city' => 'Jakarta', 'province' => 'DKI Jakarta', 'lat' => -6.2088, 'lng' => 106.8456],
        ['city' => 'Tangerang', 'province' => 'Banten', 'lat' => -6.1783, 'lng' => 106.6319],
        ['city' => 'Bekasi', 'province' => 'Jawa Barat', 'lat' => -6.2383, 'lng' => 106.9756],
        ['city' => 'Depok', 'province' => 'Jawa Barat', 'lat' => -6.4025, 'lng' => 106.7942],
        ['city' => 'Bogor', 'province' => 'Jawa Barat', 'lat' => -6.5971, 'lng' => 106.806],
        ['city' => 'Bandung', 'province' => 'Jawa Barat', 'lat' => -6.9175, 'lng' => 107.6191],
        ['city' => 'Cirebon', 'province' => 'Jawa Barat', 'lat' => -6.732, 'lng' => 108.5523],
        ['city' => 'Serang', 'province' => 'Banten', 'lat' => -6.12, 'lng' => 106.1503],
        ['city' => 'Semarang', 'province' => 'Jawa Tengah', 'lat' => -6.9667, 'lng' => 110.4167],
        ['city' => 'Surakarta', 'province' => 'Jawa Tengah', 'lat' => -7.5755, 'lng' => 110.8243],
        ['city' => 'Purwokerto', 'province' => 'Jawa Tengah', 'lat' => -7.4244, 'lng' => 109.2302],
        ['city' => 'Yogyakarta', 'province' => 'DI Yogyakarta', 'lat' => -7.7956, 'lng' => 110.3695],
        ['city' => 'Surabaya', 'province' => 'Jawa Timur', 'lat' => -7.2575, 'lng' => 112.7521],
        ['city' => 'Malang', 'province' => 'Jawa Timur', 'lat' => -7.9666, 'lng' => 112.6326],
        ['city' => 'Kediri', 'province' => 'Jawa Timur', 'lat' => -7.848, 'lng' => 112.0178],
        ['city' => 'Jember', 'province' => 'Jawa Timur', 'lat' => -8.1845, 'lng' => 113.6681],
        ['city' => 'Denpasar', 'province' => 'Bali', 'lat' => -8.6705, 'lng' => 115.2126],
        ['city' => 'Mataram', 'province' => 'Nusa Tenggara Barat', 'lat' => -8.5833, 'lng' => 116.1167],
        ['city' => 'Kupang', 'province' => 'Nusa Tenggara Timur', 'lat' => -10.1772, 'lng' => 123.607],
        ['city' => 'Banda Aceh', 'province' => 'Aceh', 'lat' => 5.5483, 'lng' => 95.3238],
        ['city' => 'Medan', 'province' => 'Sumatera Utara', 'lat' => 3.5952, 'lng' => 98.6722],
        ['city' => 'Padang', 'province' => 'Sumatera Barat', 'lat' => -0.9471, 'lng' => 100.4172],
        ['city' => 'Pekanbaru', 'province' => 'Riau', 'lat' => 0.5071, 'lng' => 101.4478],
        ['city' => 'Batam', 'province' => 'Kepulauan Riau', 'lat' => 1.0456, 'lng' => 104.0305],
        ['city' => 'Jambi', 'province' => 'Jambi', 'lat' => -1.6101, 'lng' => 103.6131],
        ['city' => 'Palembang', 'province' => 'Sumatera Selatan', 'lat' => -2.9761, 'lng' => 104.7754],
        ['city' => 'Bengkulu', 'province' => 'Bengkulu', 'lat' => -3.7928, 'lng' => 102.2608],
        ['city' => 'Bandar Lampung', 'province' => 'Lampung', 'lat' => -5.4292, 'lng' => 105.261],
        ['city' => 'Pangkal Pinang', 'province' => 'Bangka Belitung', 'lat' => -2.1316, 'lng' => 106.1169],
        ['city' => 'Pontianak', 'province' => 'Kalimantan Barat', 'lat' => -0.0263, 'lng' => 109.3425],
        ['city' => 'Palangka Raya', 'province' => 'Kalimantan Tengah', 'lat' => -2.2083, 'lng' => 113.9167],
        ['city' => 'Banjarmasin', 'province' => 'Kalimantan Selatan', 'lat' => -3.3186, 'lng' => 114.5944],
        ['city' => 'Balikpapan', 'province' => 'Kalimantan Timur', 'lat' => -1.2379, 'lng' => 116.8529],
        ['city' => 'Samarinda', 'province' => 'Kalimantan Timur', 'lat' => -0.5022, 'lng' => 117.1536],
        ['city' => 'Tarakan', 'province' => 'Kalimantan Utara', 'lat' => 3.3, 'lng' => 117.6333],
        ['city' => 'Makassar', 'province' => 'Sulawesi Selatan', 'lat' => -5.1477, 'lng' => 119.4327],
        ['city' => 'Manado', 'province' => 'Sulawesi Utara', 'lat' => 1.4748, 'lng' => 124.8421],
        ['city' => 'Palu', 'province' => 'Sulawesi Tengah', 'lat' => -0.8917, 'lng' => 119.8707],
        ['city' => 'Kendari', 'province' => 'Sulawesi Tenggara', 'lat' => -3.9985, 'lng' => 122.5129],
        ['city' => 'Gorontalo', 'province' => 'Gorontalo', 'lat' => 0.5435, 'lng' => 123.0568],
        ['city' => 'Ambon', 'province' => 'Maluku', 'lat' => -3.6954, 'lng' => 128.1814],
        ['city' => 'Ternate', 'province' => 'Maluku Utara', 'lat' => 0.7893, 'lng' => 127.3872],
        ['city' => 'Jayapura', 'province' => 'Papua', 'lat' => -2.5916, 'lng' => 140.669],
        ['city' => 'Sorong', 'province' => 'Papua Barat Daya', 'lat' => -0.8762, 'lng' => 131.2558],
    ];

    public static function defaultSystemSettings(): array
    {
        return [
            'anomalyThreshold' => [
                'validMaxScans' => 1,
                'warningMaxScans' => 3,
                'suspiciousMinScans' => 4,
                'cooldownSeconds' => 60,
                'enableImpossibleTravel' => true,
                'maxVelocityKmh' => 300,
                'consecutiveFailureAlert' => 3,
            ],
            'locationPolicy' => [
                'mode' => 'hybrid',
                'requireGpsByDefault' => true,
                'enforceIndonesiaOnly' => true,
                'maxAccuracyMeters' => 150,
                'detectMockLocation' => true,
            ],
            'notifications' => [
                'emailEnabled' => true,
                'emailRecipients' => '',
                'telegramEnabled' => false,
                'telegramBotToken' => '',
                'telegramChatId' => '',
                'whatsappEnabled' => false,
                'whatsappNumber' => '',
                'whatsappGatewayUrl' => 'https://api.fonnte.com/send',
                'whatsappApiToken' => '',
                'triggerOnSuspicious' => true,
                'triggerOnImpossibleTravel' => true,
                'throttleMinutes' => 5,
            ],
            'backupInfo' => [
                'lastBackupDate' => 'Belum ada cadangan',
                'autoBackupDaily' => true,
                'retentionDays' => 30,
            ],
        ];
    }

    public static function mergeSystemSettings(mixed $raw): array
    {
        $defaults = self::defaultSystemSettings();
        $src = is_array($raw) ? $raw : [];

        return [
            'anomalyThreshold' => array_merge($defaults['anomalyThreshold'], is_array($src['anomalyThreshold'] ?? null) ? $src['anomalyThreshold'] : []),
            'locationPolicy' => array_merge($defaults['locationPolicy'], is_array($src['locationPolicy'] ?? null) ? $src['locationPolicy'] : []),
            'notifications' => array_merge($defaults['notifications'], is_array($src['notifications'] ?? null) ? $src['notifications'] : []),
            'backupInfo' => array_merge($defaults['backupInfo'], is_array($src['backupInfo'] ?? null) ? $src['backupInfo'] : []),
        ];
    }

    public static function loadActiveSettings(): array
    {
        try {
            return Cache::remember(self::SETTINGS_CACHE_KEY, 300, static function (): array {
                $row = DB::table('system_settings')->where('key', 'app_settings')->first();
                if ($row && !empty($row->value)) {
                    $decoded = json_decode((string) $row->value, true);
                    if (is_array($decoded)) {
                        return self::mergeSystemSettings($decoded);
                    }
                }
                return self::mergeSystemSettings(self::defaultSystemSettings());
            });
        } catch (\Throwable) {
        }
        return self::mergeSystemSettings(self::defaultSystemSettings());
    }

    public static function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return $r * $c;
    }

    public static function isWithinIndonesia(float $lat, float $lng): bool
    {
        if (!is_finite($lat) || !is_finite($lng)) {
            return false;
        }
        return $lat >= -11.2 && $lat <= 6.2 && $lng >= 94.8 && $lng <= 141.2;
    }

    /**
     * @return array{city: string, province: string, distanceKm: float}
     */
    public static function nearestIndonesianCity(float $lat, float $lng): array
    {
        $best = self::INDONESIAN_CITIES[0];
        $bestDist = INF;

        foreach (self::INDONESIAN_CITIES as $item) {
            $d = self::haversineDistanceKm($lat, $lng, $item['lat'], $item['lng']);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $item;
            }
        }

        if (!self::isWithinIndonesia($lat, $lng)) {
            return ['city' => 'Luar Indonesia', 'province' => 'Internasional', 'distanceKm' => $bestDist];
        }

        return ['city' => $best['city'], 'province' => $best['province'], 'distanceKm' => $bestDist];
    }

    /**
     * @return array{device: string, browser: string}
     */
    public static function parseDeviceAndBrowser(string $userAgent): array
    {
        $ua = trim($userAgent);
        $device = 'Desktop Browser';
        if (preg_match('/iPhone/i', $ua)) {
            $device = 'iPhone (iOS)';
        } elseif (preg_match('/iPad/i', $ua)) {
            $device = 'iPad (iPadOS)';
        } elseif (preg_match('/Android/i', $ua)) {
            if (preg_match('/Android\s[\d.]+;\s*([^;)]+)/i', $ua, $m)) {
                $model = trim($m[1]);
                // Chrome 110+ uses User-Agent Reduction ("Android 10; K") instead of real model
                if ($model === '' || strtoupper($model) === 'K' || strtolower($model) === 'wv' || strtolower($model) === 'mobile') {
                    $device = 'Android Mobile';
                } else {
                    $device = 'Android (' . mb_substr($model, 0, 32) . ')';
                }
            } else {
                $device = 'Android Mobile';
            }
        } elseif (preg_match('/Macintosh/i', $ua)) {
            $device = 'macOS Desktop';
        } elseif (preg_match('/Windows/i', $ua)) {
            $device = 'Windows Desktop';
        } elseif (preg_match('/Linux/i', $ua)) {
            $device = 'Linux Desktop';
        }

        $browser = 'Web Browser';
        if (preg_match('/SamsungBrowser\//i', $ua)) {
            $browser = 'Samsung Internet';
        } elseif (preg_match('/EdgA\/|EdgiOS\/|Edg\//i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/OPR\/|Opera|OPT\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/UCBrowser\//i', $ua)) {
            $browser = 'UC Browser';
        } elseif (preg_match('/Firefox\/|FxiOS\//i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/CriOS\//i', $ua) || (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium/i', $ua))) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\/|CriOS\/|Android/i', $ua)) {
            $browser = 'Safari';
        }

        return ['device' => $device, 'browser' => $browser];
    }

    public static function getGeoIpFromRequest(Request $request): array
    {
        $cfLat = $request->header('cf-iplatitude');
        $cfLng = $request->header('cf-iplongitude');
        if (is_numeric($cfLat) && is_numeric($cfLng)) {
            return [
                'city' => (string) ($request->header('cf-ipcity') ?: 'Lokasi IP'),
                'province' => (string) ($request->header('cf-region') ?: ''),
                'countryCode' => strtoupper((string) ($request->header('cf-ipcountry') ?: 'ID')),
                'lat' => (float) $cfLat,
                'lng' => (float) $cfLng,
                'isp' => 'ISP Publik',
                'isFallbackDefault' => false,
            ];
        }

        return [
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'countryCode' => strtoupper((string) ($request->header('cf-ipcountry') ?: 'ID')),
            'lat' => -6.2088,
            'lng' => 106.8456,
            'isp' => 'ISP Publik',
            'isFallbackDefault' => true,
        ];
    }

    /**
     * @return string[]
     */
    public static function detectMockLocationSignals(array $input): array
    {
        $signals = [];

        if (!empty($input['isMockLocation'])) {
            $signals[] = 'Flag Mock GPS aktif pada perangkat';
        }
        if (!empty($input['webdriver'])) {
            $signals[] = 'Browser otomatisasi / WebDriver terdeteksi';
        }

        $ua = (string) ($input['userAgent'] ?? '');
        if (preg_match('/HeadlessChrome|PhantomJS|Android SDK built for|Genymotion|BlueStacks|NoxPlayer|Emulator/i', $ua)) {
            $signals[] = 'Perangkat teridentifikasi sebagai emulator atau headless browser';
        }

        $gpsGranted = !empty($input['gpsGranted']);
        $accuracy = $input['accuracyMeters'] ?? null;
        if ($gpsGranted && is_numeric($accuracy) && (float) $accuracy === 0.0) {
            $signals[] = 'Akurasi GPS bernilai 0m (pola koordinat injeksi/palsu)';
        }

        $speed = $input['speedMps'] ?? null;
        if ($gpsGranted && is_numeric($speed) && (float) $speed > 100) {
            $kmh = round((float) $speed * 3.6);
            $signals[] = "Kecepatan sensor GPS tidak wajar ({$kmh} km/jam)";
        }

        $lat = $input['lat'] ?? null;
        $lng = $input['lng'] ?? null;
        if ($gpsGranted && is_numeric($lat) && is_numeric($lng)) {
            $fLat = (float) $lat;
            $fLng = (float) $lng;
            if (is_finite($fLat) && is_finite($fLng) && floor($fLat) === $fLat && floor($fLng) === $fLng) {
                $signals[] = 'Koordinat GPS berupa bilangan bulat sempurna tanpa variasi satelit';
            }
            $geoIp = $input['geoIp'] ?? null;
            if (is_array($geoIp) && empty($geoIp['isFallbackDefault'])) {
                $mismatchKm = self::haversineDistanceKm($fLat, $fLng, (float) $geoIp['lat'], (float) $geoIp['lng']);
                if ($mismatchKm > 1500) {
                    $rounded = round($mismatchKm);
                    $locLabel = $geoIp['city'] ?: ($geoIp['countryCode'] ?? 'IP');
                    $signals[] = "Selisih ekstrem antara koordinat GPS dan jaringan IP ({$rounded} km dari {$locLabel})";
                }
            }
        }

        return $signals;
    }

    public static function evaluateScanAgainstPolicy(array $input): array
    {
        $settings = $input['settings'];
        $nowMs = $input['nowMs'] ?? (int) round(microtime(true) * 1000);
        $nowSec = (int) floor($nowMs / 1000);
        $nowIso = gmdate('Y-m-d\TH:i:s\Z', $nowSec);
        $nowLabel = RbacService::formatIndonesianTimestamp($nowMs);

        $batchEnableGps = $input['batchEnableGps'] ?? true;
        $effectiveMode = $batchEnableGps === false ? 'flexible' : ($settings['locationPolicy']['mode'] ?? 'hybrid');

        $latInput = $input['lat'] ?? null;
        $lngInput = $input['lng'] ?? null;
        $hasValidGps = !empty($input['gpsGranted'])
            && is_numeric($latInput)
            && is_numeric($lngInput)
            && is_finite((float) $latInput)
            && is_finite((float) $lngInput)
            && abs((float) $latInput) <= 90
            && abs((float) $lngInput) <= 180;

        if ($effectiveMode === 'strict' && !$hasValidGps) {
            return [
                'blocked' => true,
                'blockCode' => 'GPS_REQUIRED',
                'blockMessage' => 'Mode Wajib GPS (Strict) aktif. Izinkan akses lokasi GPS perangkat Anda untuk memverifikasi keaslian produk.',
                'withinCooldown' => false,
                'effectiveMode' => $effectiveMode,
                'locationSource' => 'GeoIP (Fallback)',
                'status' => $input['existingStatus'] ?? 'valid',
                'anomalyReasons' => ['Akses GPS ditolak pada mode Wajib GPS'],
                'anomalyReasonText' => 'Akses GPS ditolak pada mode Wajib GPS',
                'impossibleTravelDetected' => false,
                'velocityKmh' => null,
                'distanceKm' => null,
                'lat' => -6.2088,
                'lng' => 106.8456,
                'city' => 'Lokasi Diblokir',
                'province' => '',
                'venue' => 'GPS Ditolak',
                'newTotalScans' => (int) ($input['existingTotalScans'] ?? 0),
                'newTodayScans' => (int) ($input['existingTodayScans'] ?? 0),
                'historyEntry' => [
                    'scanNumber' => (int) ($input['existingTotalScans'] ?? 0),
                    'scannedAt' => $nowLabel,
                    'scannedAtIso' => $nowIso,
                    'city' => 'Lokasi Diblokir',
                    'province' => '',
                    'lat' => -6.2088,
                    'lng' => 106.8456,
                    'ipAddress' => $input['ipAddress'] ?? '0.0.0.0',
                    'device' => $input['deviceInfo'] ?? 'Unknown',
                    'status' => 'suspicious',
                    'statusNote' => 'Akses GPS ditolak (Strict Mode)',
                ],
            ];
        }

        $geoIp = $input['geoIp'] ?? null;
        $hasRealExternalGeo = false;

        if ($hasValidGps && $effectiveMode !== 'flexible') {
            $lat = (float) $latInput;
            $lng = (float) $lngInput;
            $locationSource = 'GPS';
            $hasRealExternalGeo = true;
            $nearest = self::nearestIndonesianCity($lat, $lng);
            $city = !empty($input['city']) ? (string) $input['city'] : $nearest['city'];
            $province = !empty($input['province']) ? (string) $input['province'] : $nearest['province'];
        } elseif (is_numeric($latInput) && is_numeric($lngInput) && is_finite((float) $latInput) && is_finite((float) $lngInput) && empty($geoIp)) {
            $lat = (float) $latInput;
            $lng = (float) $lngInput;
            $locationSource = 'Manual';
            $hasRealExternalGeo = true;
            $nearest = self::nearestIndonesianCity($lat, $lng);
            $city = !empty($input['city']) ? (string) $input['city'] : $nearest['city'];
            $province = !empty($input['province']) ? (string) $input['province'] : $nearest['province'];
        } elseif (is_array($geoIp)) {
            $lat = (float) $geoIp['lat'];
            $lng = (float) $geoIp['lng'];
            $city = !empty($input['city']) ? (string) $input['city'] : (string) $geoIp['city'];
            $province = !empty($input['province']) ? (string) $input['province'] : (string) $geoIp['province'];
            $locationSource = $effectiveMode === 'hybrid' ? 'GeoIP (Fallback)' : 'GeoIP';
            $hasRealExternalGeo = empty($geoIp['isFallbackDefault']);
        } else {
            $lat = -6.2088;
            $lng = 106.8456;
            $city = !empty($input['city']) ? (string) $input['city'] : 'Jakarta';
            $province = !empty($input['province']) ? (string) $input['province'] : 'DKI Jakarta';
            $locationSource = $effectiveMode === 'hybrid' ? 'GeoIP (Fallback)' : 'GeoIP';
        }

        $rawDeviceInfo = trim((string) ($input['deviceInfo'] ?? ''));
        $rawUa = trim((string) ($input['userAgent'] ?? ''));
        $isRawUaInDeviceInfo = $rawDeviceInfo !== '' && preg_match('/^Mozilla\/|AppleWebKit\//i', $rawDeviceInfo);
        $effectiveUa = $isRawUaInDeviceInfo ? $rawDeviceInfo : ($rawUa !== '' ? $rawUa : $rawDeviceInfo);
        $parsedUa = self::parseDeviceAndBrowser($effectiveUa);
        $deviceLabel = ($rawDeviceInfo !== '' && !$isRawUaInDeviceInfo)
            ? $rawDeviceInfo
            : $parsedUa['device'];
        $accuracy = $input['accuracyMeters'] ?? null;
        if (!empty($input['venue'])) {
            $venue = (string) $input['venue'];
        } elseif ($locationSource === 'GPS') {
            $accLabel = is_numeric($accuracy) ? '±' . round((float) $accuracy) . 'm' : 'Akurat';
            $venue = "GPS Presisi ({$accLabel})";
        } elseif ($locationSource === 'GeoIP (Fallback)') {
            $venue = 'Estimasi GeoIP (GPS Ditolak)';
        } else {
            $venue = 'Estimasi GeoIP';
        }

        $prevHistory = is_array($input['existingHistory'] ?? null) ? $input['existingHistory'] : [];
        $lastEntry = count($prevHistory) > 0 ? $prevHistory[0] : null;
        $withinCooldown = false;
        $cooldownSeconds = (int) ($settings['anomalyThreshold']['cooldownSeconds'] ?? 60);

        if (is_array($lastEntry) && !empty($lastEntry['scannedAtIso']) && $cooldownSeconds > 0) {
            $prevSec = strtotime((string) $lastEntry['scannedAtIso']);
            if ($prevSec !== false) {
                $elapsedSeconds = ($nowMs / 1000) - $prevSec;
                $sameDevice = strtolower((string) ($lastEntry['device'] ?? '')) === strtolower($deviceLabel);
                $sameIp = (string) ($lastEntry['ipAddress'] ?? '') === (string) ($input['ipAddress'] ?? '');
                if ($elapsedSeconds >= 0 && $elapsedSeconds <= $cooldownSeconds && $sameDevice && $sameIp) {
                    $withinCooldown = true;
                }
            }
        }

        $existingTotal = (int) ($input['existingTotalScans'] ?? 0);
        $existingToday = (int) ($input['existingTodayScans'] ?? 0);
        $newTotalScans = $withinCooldown ? max(1, $existingTotal) : $existingTotal + 1;
        $newTodayScans = $withinCooldown ? max(1, $existingToday) : $existingToday + 1;

        $anomalyReasons = [];
        $status = 'valid';

        $validMax = max(1, (int) ($settings['anomalyThreshold']['validMaxScans'] ?? 1));
        $warningMax = max($validMax + 1, (int) ($settings['anomalyThreshold']['warningMaxScans'] ?? 3));
        $suspiciousMin = max($warningMax + 1, (int) ($settings['anomalyThreshold']['suspiciousMinScans'] ?? ($warningMax + 1)));

        if ($newTotalScans >= $suspiciousMin) {
            $status = 'suspicious';
            $anomalyReasons[] = "Terdeteksi {$newTotalScans}x pemindaian (melebihi ambang batas palsu ≥{$suspiciousMin}x). Indikasi kuat duplikasi tag fisik.";
        } elseif ($newTotalScans > $validMax) {
            $status = 'repeated';
            $anomalyReasons[] = "Terdeteksi {$newTotalScans}x pemindaian pada tag ini (rentang waspada 2–{$warningMax}x).";
        }

        $impossibleTravelDetected = false;
        $velocityKmh = null;
        $distanceKm = null;
        $maxVelocity = (float) ($settings['anomalyThreshold']['maxVelocityKmh'] ?? 300);

        if (
            !$withinCooldown &&
            !empty($settings['anomalyThreshold']['enableImpossibleTravel']) &&
            is_array($lastEntry) &&
            is_numeric($lastEntry['lat'] ?? null) &&
            is_numeric($lastEntry['lng'] ?? null) &&
            !empty($lastEntry['scannedAtIso'])
        ) {
            $prevSec = strtotime((string) $lastEntry['scannedAtIso']);
            if ($prevSec !== false && ($nowMs / 1000) > $prevSec) {
                $dist = self::haversineDistanceKm((float) $lastEntry['lat'], (float) $lastEntry['lng'], $lat, $lng);
                $elapsedHours = max((($nowMs / 1000) - $prevSec) / 3600.0, 1.0 / 3600.0);
                $velocity = $dist / $elapsedHours;
                $distanceKm = round($dist * 10) / 10;
                $velocityKmh = (int) round($velocity);

                if ($dist > 5 && $velocity > $maxVelocity) {
                    $impossibleTravelDetected = true;
                    $status = 'suspicious';
                    $elapsedMinutes = max(1, (int) round((($nowMs / 1000) - $prevSec) / 60));
                    $prevCity = $lastEntry['city'] ?? 'Lokasi sebelumnya';
                    $roundedDist = round($dist);
                    $roundedVel = round($velocity);
                    $anomalyReasons[] = "Impossible Travel terdeteksi: perpindahan {$roundedDist} km ({$prevCity} → {$city}) dalam {$elapsedMinutes} menit ({$roundedVel} km/jam > batas {$maxVelocity} km/jam).";
                }
            }
        }

        $maxAccuracy = (float) ($settings['locationPolicy']['maxAccuracyMeters'] ?? 150);
        if (
            $locationSource === 'GPS' &&
            $effectiveMode !== 'flexible' &&
            is_numeric($accuracy) &&
            (float) $accuracy > $maxAccuracy
        ) {
            if ($status === 'valid') {
                $status = 'repeated';
            }
            $roundedAcc = round((float) $accuracy);
            $anomalyReasons[] = "Akurasi GPS rendah (±{$roundedAcc}m melebihi batas maksimal {$maxAccuracy}m).";
        }

        if (!empty($settings['locationPolicy']['enforceIndonesiaOnly'])) {
            $country = strtoupper((string) ($input['countryCode'] ?? ($geoIp['countryCode'] ?? '')));
            $outsideCountry = $country !== '' && $country !== 'ID';
            $outsideBounds = $hasRealExternalGeo && !self::isWithinIndonesia($lat, $lng);

            if ($outsideCountry || $outsideBounds) {
                $status = 'suspicious';
                $detail = $outsideCountry
                    ? 'negara ' . ($geoIp['countryName'] ?? $country)
                    : 'koordinat ' . number_format($lat, 4) . ', ' . number_format($lng, 4);
                $anomalyReasons[] = "Pemindaian terdeteksi di luar wilayah Indonesia ({$detail}).";
            }
        }

        if (!empty($settings['locationPolicy']['detectMockLocation'])) {
            $mockSignals = self::detectMockLocationSignals([
                'isMockLocation' => !empty($input['isMockLocation']),
                'webdriver' => !empty($input['webdriver']),
                'userAgent' => $input['userAgent'] ?? '',
                'accuracyMeters' => $accuracy,
                'speedMps' => $input['speedMps'] ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'gpsGranted' => $locationSource === 'GPS',
                'geoIp' => $geoIp,
            ]);
            if (count($mockSignals) > 0) {
                $status = 'suspicious';
                $anomalyReasons[] = 'Indikasi Fake GPS / Emulator: ' . implode('; ', $mockSignals) . '.';
            }
        }

        $anomalyReasonText = count($anomalyReasons) > 0 ? implode(' ', $anomalyReasons) : null;

        $historyEntry = [
            'scanNumber' => $newTotalScans,
            'scannedAt' => $nowLabel,
            'scannedAtIso' => $nowIso,
            'city' => $city,
            'province' => $province,
            'venue' => $venue,
            'lat' => $lat,
            'lng' => $lng,
            'ipAddress' => $input['ipAddress'] ?: '0.0.0.0',
            'isp' => $input['isp'] ?? ($geoIp['isp'] ?? 'Jaringan Seluler / ISP'),
            'device' => $deviceLabel,
            'browser' => $parsedUa['browser'],
            'status' => $status,
            'statusNote' => $anomalyReasonText ?? (
                $withinCooldown
                    ? "Dalam sesi cooldown ({$cooldownSeconds} dtk)"
                    : "Verifikasi asli via {$locationSource}"
            ),
            'accuracyMeters' => is_numeric($accuracy) ? (int) round((float) $accuracy) : null,
            'locationSource' => $locationSource,
        ];

        return [
            'blocked' => false,
            'withinCooldown' => $withinCooldown,
            'effectiveMode' => $effectiveMode,
            'locationSource' => $locationSource,
            'status' => $status,
            'anomalyReasons' => $anomalyReasons,
            'anomalyReasonText' => $anomalyReasonText,
            'impossibleTravelDetected' => $impossibleTravelDetected,
            'velocityKmh' => $velocityKmh,
            'distanceKm' => $distanceKm,
            'lat' => $lat,
            'lng' => $lng,
            'city' => $city,
            'province' => $province,
            'venue' => $venue,
            'newTotalScans' => $newTotalScans,
            'newTodayScans' => $newTodayScans,
            'historyEntry' => $historyEntry,
        ];
    }

    public static function buildQrVerificationHtml(array $params): string
    {
        $safeTag = SecurityHardeningService::escapeHtml($params['tagNumber'] ?? '');
        $safeBrand = SecurityHardeningService::escapeHtml($params['brand'] ?? 'Produk');
        $safeSku = SecurityHardeningService::escapeHtml($params['sku'] ?? '');
        $mode = ($params['mode'] ?? 'hybrid') === 'strict' ? 'strict' : 'hybrid';
        $isStrict = $mode === 'strict';
        $jsonTag = json_encode($params['tagNumber'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsonMode = json_encode($mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $descText = $isStrict
            ? 'Mohon izinkan akses GPS pada perangkat Anda agar sistem dapat memvalidasi keaslian produk.'
            : 'Memeriksa lokasi perangkat untuk mencatat verifikasi keaslian produk Anda...';
        $skuSuffix = $safeSku !== '' ? ' · ' . $safeSku : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verifikasi Keaslian · {$safeTag}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, sans-serif;
      background: #f8fafc;
      color: #0f172a;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    @media (prefers-color-scheme: dark) {
      body { background: #09090b; color: #f4f4f5; }
      .card { background: #18181b; border-color: #27272a; }
      .meta { color: #a1a1aa; }
      .tag-box { background: #27272a; border-color: #3f3f46; color: #e4e4e7; }
      .err-box { background: rgba(244,63,94,0.12); border-color: rgba(244,63,94,0.35); color: #fda4af; }
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 20px;
      padding: 28px 24px;
      text-align: center;
    }
    .spinner {
      width: 44px;
      height: 44px;
      border: 3px solid rgba(194, 142, 98, 0.22);
      border-top-color: #c28e62;
      border-radius: 50%;
      margin: 0 auto 18px;
      animation: spin 0.85s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    h1 {
      margin: 0 0 8px;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: -0.01em;
    }
    .meta {
      margin: 0 0 18px;
      font-size: 13px;
      line-height: 1.55;
      color: #64748b;
    }
    .tag-box {
      display: inline-block;
      padding: 6px 14px;
      border-radius: 999px;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 12px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 16px;
    }
    .err-box {
      display: none;
      margin-top: 14px;
      padding: 12px 14px;
      border-radius: 14px;
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #be123c;
      font-size: 12.5px;
      line-height: 1.5;
      text-align: left;
    }
    .btn {
      display: none;
      width: 100%;
      margin-top: 14px;
      height: 44px;
      border: none;
      border-radius: 999px;
      background: #c28e62;
      color: #ffffff;
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
    }
    .btn:hover { background: #b07d53; }
    .sub {
      margin-top: 14px;
      font-size: 11.5px;
      color: #94a3b8;
    }
  </style>
</head>
<body>
  <div class="card">
    <div id="spinner" class="spinner"></div>
    <div class="tag-box">{$safeTag}</div>
    <h1 id="title">Memverifikasi Lokasi &amp; Keaslian</h1>
    <p id="desc" class="meta">{$descText}</p>
    <div id="errorBox" class="err-box"></div>
    <button id="retryBtn" type="button" class="btn">Coba Izinkan GPS Lagi</button>
    <div class="sub">{$safeBrand}{$skuSuffix}</div>
  </div>

  <script>
    (function() {
      var tagNumber = {$jsonTag};
      var mode = {$jsonMode};
      var titleEl = document.getElementById('title');
      var descEl = document.getElementById('desc');
      var errEl = document.getElementById('errorBox');
      var retryBtn = document.getElementById('retryBtn');
      var spinner = document.getElementById('spinner');

      function showBlocked(msg) {
        spinner.style.display = 'none';
        titleEl.textContent = 'Izin GPS Diperlukan';
        descEl.textContent = 'Kebijakan keamanan mewajibkan akses GPS aktif sebelum hasil verifikasi produk dapat ditampilkan.';
        errEl.textContent = msg;
        errEl.style.display = 'block';
        retryBtn.style.display = 'block';
      }

      function submitVerification(payload) {
        fetch('/api/q/' + encodeURIComponent(tagNumber) + '/verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        })
        .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, status: res.status, data: data }; }); })
        .then(function(result) {
          if (result.ok && result.data && typeof result.data.redirectUrl === 'string' && /^https?:\/\//i.test(result.data.redirectUrl)) {
            titleEl.textContent = 'Verifikasi Berhasil';
            descEl.textContent = 'Mengalihkan ke halaman hasil verifikasi...';
            window.location.replace(result.data.redirectUrl);
          } else {
            showBlocked((result.data && result.data.message) || 'Gagal memverifikasi lokasi GPS. Silakan coba lagi.');
          }
        })
        .catch(function() {
          showBlocked('Koneksi ke server verifikasi terganggu. Periksa jaringan Anda lalu coba lagi.');
        });
      }

      function requestLocation() {
        spinner.style.display = 'block';
        errEl.style.display = 'none';
        retryBtn.style.display = 'none';
        titleEl.textContent = 'Memverifikasi Lokasi & Keaslian';

        if (!navigator.geolocation) {
          if (mode === 'strict') {
            showBlocked('Browser atau perangkat Anda tidak mendukung GPS. Gunakan browser seluler modern dengan GPS aktif.');
          } else {
            submitVerification({ gpsGranted: false, webdriver: Boolean(navigator.webdriver) });
          }
          return;
        }

        var bestPos = null;
        var finished = false;
        var watchId = null;
        var fallbackTimer = null;

        function finishWithPos(pos) {
          if (finished) return;
          finished = true;
          if (watchId !== null) navigator.geolocation.clearWatch(watchId);
          if (fallbackTimer !== null) clearTimeout(fallbackTimer);
          submitVerification({
            gpsGranted: true,
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracyMeters: pos.coords.accuracy,
            speedMps: pos.coords.speed,
            webdriver: Boolean(navigator.webdriver)
          });
        }

        function finishWithError(err) {
          if (finished) return;
          if (bestPos) {
            finishWithPos(bestPos);
            return;
          }
          finished = true;
          if (watchId !== null) navigator.geolocation.clearWatch(watchId);
          if (fallbackTimer !== null) clearTimeout(fallbackTimer);
          if (mode === 'strict') {
            var reason = 'Akses lokasi ditolak oleh browser. Aktifkan izin Lokasi/GPS di pengaturan browser lalu tekan tombol di bawah.';
            if (err && err.code === 2) reason = 'Sinyal GPS perangkat tidak tersedia. Pastikan layanan Lokasi (GPS) aktif.';
            if (err && err.code === 3) reason = 'Waktu tunggu sinyal GPS habis. Coba lagi di area dengan sinyal lebih baik.';
            showBlocked(reason);
          } else {
            submitVerification({
              gpsGranted: false,
              fallbackReason: err ? err.message : 'GPS ditolak',
              webdriver: Boolean(navigator.webdriver)
            });
          }
        }

        // Wait up to 12s for a high-accuracy GPS fix (<= 50m), keeping the most accurate reading received
        fallbackTimer = setTimeout(function() {
          if (bestPos) {
            finishWithPos(bestPos);
          } else {
            finishWithError({ code: 3, message: 'Timeout menunggu kunci satelit GPS' });
          }
        }, 12000);

        watchId = navigator.geolocation.watchPosition(
          function(pos) {
            if (!bestPos || (typeof pos.coords.accuracy === 'number' && pos.coords.accuracy < bestPos.coords.accuracy)) {
              bestPos = pos;
            }
            if (typeof pos.coords.accuracy === 'number' && pos.coords.accuracy <= 50) {
              finishWithPos(pos);
            }
          },
          function(err) {
            // Permission denied (code 1) fails immediately; timeout/unavailable waits for timer if watch still active
            if (err && err.code === 1) {
              finishWithError(err);
            }
          },
          { enableHighAccuracy: true, timeout: 11000, maximumAge: 0 }
        );
      }

      retryBtn.addEventListener('click', requestLocation);
      requestLocation();
    })();
  </script>
</body>
</html>
HTML;
    }

    public static function parseFlexibleScanDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            try {
                $target = strlen($raw) === 10 ? "{$raw}T12:00:00Z" : $raw;
                return (new \DateTimeImmutable($target))->setTimezone(new \DateTimeZone('Asia/Jakarta'));
            } catch (\Throwable) {
            }
        }

        $monthMap = [
            'jan' => 1, 'januari' => 1,
            'feb' => 2, 'februari' => 2,
            'mar' => 3, 'maret' => 3,
            'apr' => 4, 'april' => 4,
            'mei' => 5, 'may' => 5,
            'jun' => 6, 'juni' => 6,
            'jul' => 7, 'juli' => 7,
            'agt' => 8, 'aug' => 8, 'agustus' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'okt' => 10, 'oct' => 10, 'oktober' => 10,
            'nov' => 11, 'november' => 11,
            'des' => 12, 'dec' => 12, 'desember' => 12,
        ];

        if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $raw, $m)) {
            $day = (int) $m[1];
            $mon = $monthMap[strtolower($m[2])] ?? null;
            $year = (int) $m[3];
            if ($mon !== null && $day >= 1 && $day <= 31) {
                $dt = \DateTimeImmutable::createFromFormat('!Y-n-j H:i:s', "{$year}-{$mon}-{$day} 12:00:00", new \DateTimeZone('Asia/Jakarta'));
                if ($dt !== false) {
                    return $dt;
                }
            }
        }

        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param iterable<object|array> $scanPoints
     */
    public static function aggregateScanActivityFromPoints(iterable $scanPoints, string $rangeInput, ?string $brandFilter = null): array
    {
        $range = in_array($rangeInput, ['30d', '12m'], true) ? $rangeInput : '7d';
        $normalizedFilter = strtolower(trim((string) $brandFilter));
        $tz = new \DateTimeZone('Asia/Jakarta');
        $nowDate = new \DateTimeImmutable('now', $tz);

        $events = [];
        foreach ($scanPoints as $ptObj) {
            $pt = (array) $ptObj;
            $brand = trim((string) ($pt['brand'] ?? 'Lainnya')) ?: 'Lainnya';
            if ($normalizedFilter !== '' && strtolower($brand) !== $normalizedFilter) {
                continue;
            }

            $parsedHistory = [];
            if (!empty($pt['scanHistory'])) {
                $h = is_string($pt['scanHistory']) ? json_decode($pt['scanHistory'], true) : $pt['scanHistory'];
                if (is_array($h)) {
                    $parsedHistory = $h;
                }
            }

            if (count($parsedHistory) > 0) {
                foreach ($parsedHistory as $h) {
                    $d = self::parseFlexibleScanDate($h['scannedAtIso'] ?? null)
                        ?? self::parseFlexibleScanDate($h['scannedAt'] ?? null)
                        ?? self::parseFlexibleScanDate($pt['createdAt'] ?? null)
                        ?? $nowDate;
                    $events[] = [
                        'ts' => $d->getTimestamp(),
                        'brand' => $brand,
                        'isValid' => (($h['status'] ?? ($pt['status'] ?? 'valid')) === 'valid'),
                    ];
                }
            } else {
                $d = self::parseFlexibleScanDate($pt['createdAt'] ?? null)
                    ?? self::parseFlexibleScanDate($pt['latestTime'] ?? null)
                    ?? $nowDate;
                $count = max(1, (int) ($pt['totalScans'] ?? 1));
                $isValid = (($pt['status'] ?? 'valid') === 'valid');
                for ($i = 0; $i < $count; $i++) {
                    $events[] = [
                        'ts' => $d->getTimestamp(),
                        'brand' => $brand,
                        'isValid' => $i === 0 ? true : $isValid,
                    ];
                }
            }
        }

        $todayStart = $nowDate->setTime(0, 0, 0);
        $anchorDay = $todayStart;
        if (count($events) > 0) {
            $latestDayTs = 0;
            foreach ($events as $ev) {
                $evDay = (new \DateTimeImmutable('@' . $ev['ts']))->setTimezone($tz)->setTime(0, 0, 0)->getTimestamp();
                if ($evDay > $latestDayTs) {
                    $latestDayTs = $evDay;
                }
            }
            $diffDays = ($todayStart->getTimestamp() - $latestDayTs) / 86400;
            if ($range === '7d' && $diffDays > 6) {
                $anchorDay = (new \DateTimeImmutable('@' . $latestDayTs))->setTimezone($tz)->setTime(0, 0, 0);
            } elseif ($latestDayTs > $todayStart->getTimestamp()) {
                $anchorDay = (new \DateTimeImmutable('@' . $latestDayTs))->setTimezone($tz)->setTime(0, 0, 0);
            }
        }

        $shortDays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        $shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
        $fullMonths = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $pickTopBrand = static function (array $counts): string {
            $top = '-';
            $max = 0;
            foreach ($counts as $b => $c) {
                if ($c > $max) {
                    $max = $c;
                    $top = $b;
                }
            }
            return $top;
        };

        if ($range === '7d') {
            $buckets = [];
            for ($i = 6; $i >= 0; $i--) {
                $day = $anchorDay->modify("-{$i} days");
                $dayStartTs = $day->getTimestamp();
                $dayEndTs = $dayStartTs + 86400;

                $scans = 0;
                $valid = 0;
                $brandMap = [];
                foreach ($events as $ev) {
                    if ($ev['ts'] >= $dayStartTs && $ev['ts'] < $dayEndTs) {
                        $scans++;
                        if ($ev['isValid']) {
                            $valid++;
                        }
                        $brandMap[$ev['brand']] = ($brandMap[$ev['brand']] ?? 0) + 1;
                    }
                }

                $dd = $day->format('d');
                $mm = $day->format('m');
                $dow = (int) $day->format('w');
                $monIdx = (int) $day->format('n') - 1;
                $buckets[] = [
                    'rangeType' => '7d',
                    'label' => "{$shortDays[$dow]} ({$dd}/{$mm})",
                    'fullDate' => "{$dd} {$shortMonths[$monIdx]} {$day->format('Y')}",
                    'scans' => $scans,
                    'valid' => $valid,
                    'topBrand' => $pickTopBrand($brandMap),
                    'sortOrder' => 7 - $i,
                ];
            }
            return $buckets;
        }

        if ($range === '30d') {
            $buckets = [];
            $endDay = $todayStart;
            $startDay = $endDay->modify('-29 days');

            for ($w = 0; $w < 5; $w++) {
                $segStart = $startDay->modify('+' . ($w * 6) . ' days');
                $segEnd = $w === 4
                    ? $endDay->modify('+1 day')
                    : $startDay->modify('+' . (($w + 1) * 6) . ' days');
                $segEndDisplay = $segEnd->modify('-1 second');

                $scans = 0;
                $valid = 0;
                $brandMap = [];
                foreach ($events as $ev) {
                    if ($ev['ts'] >= $segStart->getTimestamp() && $ev['ts'] < $segEnd->getTimestamp()) {
                        $scans++;
                        if ($ev['isValid']) {
                            $valid++;
                        }
                        $brandMap[$ev['brand']] = ($brandMap[$ev['brand']] ?? 0) + 1;
                    }
                }

                $d1 = $segStart->format('d');
                $m1 = $shortMonths[(int) $segStart->format('n') - 1];
                $d2 = $segEndDisplay->format('d');
                $m2 = $shortMonths[(int) $segEndDisplay->format('n') - 1];
                $buckets[] = [
                    'rangeType' => '30d',
                    'label' => 'Periode ' . ($w + 1),
                    'fullDate' => "{$d1} {$m1} – {$d2} {$m2} {$segEndDisplay->format('Y')}",
                    'scans' => $scans,
                    'valid' => $valid,
                    'topBrand' => $pickTopBrand($brandMap),
                    'sortOrder' => $w + 1,
                ];
            }
            return $buckets;
        }

        $buckets = [];
        $baseMonth = $todayStart->setDate((int) $todayStart->format('Y'), (int) $todayStart->format('n'), 1);
        for ($m = 11; $m >= 0; $m--) {
            $monthStart = $baseMonth->modify("-{$m} months");
            $monthEnd = $monthStart->modify('+1 month');

            $scans = 0;
            $valid = 0;
            $brandMap = [];
            foreach ($events as $ev) {
                if ($ev['ts'] >= $monthStart->getTimestamp() && $ev['ts'] < $monthEnd->getTimestamp()) {
                    $scans++;
                    if ($ev['isValid']) {
                        $valid++;
                    }
                    $brandMap[$ev['brand']] = ($brandMap[$ev['brand']] ?? 0) + 1;
                }
            }

            $monIdx = (int) $monthStart->format('n') - 1;
            $buckets[] = [
                'rangeType' => '12m',
                'label' => $shortMonths[$monIdx],
                'fullDate' => "{$fullMonths[$monIdx]} {$monthStart->format('Y')}",
                'scans' => $scans,
                'valid' => $valid,
                'topBrand' => $pickTopBrand($brandMap),
                'sortOrder' => 12 - $m,
            ];
        }

        return $buckets;
    }
}
