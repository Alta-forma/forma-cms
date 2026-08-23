<?php
/**
 * Forma – Podcast unlock.
 * Forma itself is free. Podcast is a $39 one-time license.
 * Paid keys are verified at alta-forma.com (the HMAC secret is not in this repo).
 * Local unlock: FX-DEV-LOCAL
 */
class License {
    public const BUY_URL = 'https://buy.stripe.com/7sY4gA87290N6a17Qk7N608';
    public const VALIDATE_URL = 'https://forma-cms.me/api/license/validate.php';

    public static function hmacSecret(): string {
        $paths = [
            '/home/sick_af/altaforma-secrets/license-hmac.secret',
            (defined('ROOT_DIR') ? ROOT_DIR : '') . '/lib/LicenseHMACSecret.hex',
        ];
        foreach ($paths as $p) {
            if ($p === '' || !is_file($p)) continue;
            $t = preg_replace('/\s+/', '', (string)@file_get_contents($p));
            $bin = hex2bin($t ?: '');
            if ($bin !== false && strlen($bin) >= 16) return $bin;
        }
        return '';
    }

    public static function mint(string $email): string {
        $email = strtolower(trim($email));
        $secret = self::hmacSecret();
        if ($secret === '') return '';
        $digest = strtoupper(hash_hmac('sha256', 'forma-podcast|' . $email, $secret));
        return implode('-', str_split(substr($digest, 0, 16), 4));
    }

    public static function normalizeKey(string $key): string {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $key) ?? '');
    }

    public static function isValidPaidKey(string $key, string $email): bool {
        if ($email === '' || $key === '') return false;
        $secret = self::hmacSecret();
        if ($secret !== '') {
            $expect = self::normalizeKey(self::mint($email));
            return $expect !== '' && hash_equals($expect, self::normalizeKey($key));
        }
        return self::remoteValidate($key, $email);
    }

    public static function remoteValidate(string $key, string $email): bool {
        $payload = json_encode(['email' => strtolower(trim($email)), 'key' => strtoupper(trim($key))]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::VALIDATE_URL, false, $ctx);
        if ($raw === false) return false;
        $j = json_decode($raw, true);
        return is_array($j) && !empty($j['ok']);
    }

    public static function isPodcastLicensed(): bool {
        try {
            $row = Database::get()->queryOne('SELECT * FROM license WHERE id = 1');
            if (!$row || empty($row['license_key'])) return false;
            if (($row['status'] ?? '') !== 'active') return false;
            $key = strtoupper(trim($row['license_key']));
            if (str_starts_with($key, 'FX-DEV-')) return true;
            return true; // already activated against alta-forma.com
        } catch (Exception $e) {
            return false;
        }
    }

    public static function activate(string $key, string $email = ''): array {
        $key = strtoupper(trim($key));
        $email = strtolower(trim($email));
        $db = Database::get();

        if (preg_match('/^FX-DEV-[A-Z0-9-]+$/', $key)) {
            $db->execute(
                'UPDATE license SET license_key = ?, license_type = ?, licensed_to = ?, valid_until = NULL, last_checked = ?, status = ? WHERE id = 1',
                [$key, 'perpetual', 'dev', time(), 'active']
            );
            return ['success' => true, 'type' => 'perpetual', 'message' => 'Dev license activated'];
        }

        if (!self::isValidPaidKey($key, $email)) {
            return ['success' => false, 'message' => 'Invalid email or license key'];
        }

        $db->execute(
            'UPDATE license SET license_key = ?, license_type = ?, licensed_to = ?, valid_until = NULL, last_checked = ?, status = ? WHERE id = 1',
            [$key, 'perpetual', $email, time(), 'active']
        );
        return ['success' => true, 'type' => 'perpetual', 'message' => 'Podcast license activated'];
    }

    public static function status(): array {
        $row = Database::get()->queryOne('SELECT * FROM license WHERE id = 1') ?? [];
        return [
            'licensed'     => self::isPodcastLicensed(),
            'status'       => $row['status'] ?? 'inactive',
            'license_type' => $row['license_type'] ?? '',
            'licensed_to'  => $row['licensed_to'] ?? '',
            'valid_until'  => $row['valid_until'] ?? null,
            'has_key'      => !empty($row['license_key']),
            'buy_url'      => self::BUY_URL,
        ];
    }
}
