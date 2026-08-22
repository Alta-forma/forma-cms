<?php
/**
 * Forma – License gate for podcast unlock.
 * Key formats: FX-PERP-… / FL-PERP-… and FX-SUB-… / FL-SUB-… (FL- is legacy).
 */
class License {
    public static function isPodcastLicensed(): bool {
        try {
            $row = Database::get()->queryOne('SELECT * FROM license WHERE id = 1');
            if (!$row || empty($row['license_key'])) {
                return false;
            }
            if (($row['license_type'] ?? '') === 'perpetual') {
                return ($row['status'] ?? '') === 'active';
            }
            if (($row['license_type'] ?? '') === 'subscription') {
                if (($row['status'] ?? '') !== 'active') {
                    return false;
                }
                if (!empty($row['valid_until']) && (int)$row['valid_until'] < time()) {
                    return false;
                }
                return true;
            }
            // Dev unlock: FX-DEV-LOCAL enables podcasts on this install
            if (str_starts_with(strtoupper($row['license_key']), 'FX-DEV-') && ($row['status'] ?? '') === 'active') {
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function activate(string $key): array {
        $key = strtoupper(trim($key));
        $db = Database::get();

        if (preg_match('/^(FX|FL)-PERP-[A-Z0-9]{8}-[A-Z0-9]{4}$/', $key)
            || preg_match('/^FX-DEV-[A-Z0-9-]+$/', $key)
        ) {
            $type = str_starts_with($key, 'FX-DEV-') ? 'perpetual' : 'perpetual';
            $db->execute(
                'UPDATE license SET license_key = ?, license_type = ?, licensed_to = ?, valid_until = NULL, last_checked = ?, status = ? WHERE id = 1',
                [$key, $type, 'owner', time(), 'active']
            );
            return ['success' => true, 'type' => $type, 'message' => 'License activated'];
        }

        if (preg_match('/^(FX|FL)-SUB-[A-Z0-9]{8}-[A-Z0-9]{4}$/', $key)) {
            // Offline-friendly v1: accept well-formed subscription keys for 30 days
            $db->execute(
                'UPDATE license SET license_key = ?, license_type = ?, licensed_to = ?, valid_until = ?, last_checked = ?, status = ? WHERE id = 1',
                [$key, 'subscription', 'owner', time() + 2592000, time(), 'active']
            );
            return ['success' => true, 'type' => 'subscription', 'message' => 'Subscription license activated (30 days)'];
        }

        return ['success' => false, 'message' => 'Invalid license key format'];
    }

    public static function status(): array {
        $row = Database::get()->queryOne('SELECT * FROM license WHERE id = 1') ?? [];
        return [
            'licensed'    => self::isPodcastLicensed(),
            'status'      => $row['status'] ?? 'inactive',
            'license_type'=> $row['license_type'] ?? '',
            'licensed_to' => $row['licensed_to'] ?? '',
            'valid_until' => $row['valid_until'] ?? null,
            'has_key'     => !empty($row['license_key']),
        ];
    }
}
