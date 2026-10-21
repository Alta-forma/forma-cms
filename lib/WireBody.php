<?php
/**
 * Request bodies that host firewalls won't false-positive on.
 *
 * A WAF (mod_security and friends) inspects the raw HTTP body and drops the
 * request before PHP runs when it sees HTML or JavaScript — `function(){` in
 * a nav snippet is enough. The status is usually 418 with an HTML page, not a
 * Forma JSON error. Nothing in PHP can "allow" that request.
 *
 * So writers encode, and this class decodes:
 *   - `{"fxb":"<base64 of the real JSON>"}` — whole body. First-party clients
 *     (admin, Cursor MCP, tools/formax.php) send this. The wire sees only the
 *     base64 alphabet.
 *   - gzip (`Content-Encoding: gzip` or a gzip magic header) — same idea.
 *   - `content_b64` / `body_b64` fields — for remote chatbots, which build the
 *     JSON themselves and can only encode individual arguments.
 *   - The same field names on admin form posts.
 *
 * Raw JSON still works. Encoding is a dodge for the firewall, not access control.
 */
class WireBody {
    public static function unwrap(string $raw): string {
        $enc = strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING'] ?? '')));
        if ($enc === 'gzip' || str_starts_with($raw, "\x1f\x8b")) {
            $out = gzdecode($raw);
            if ($out === false) {
                throw new InvalidArgumentException('Could not decompress the gzip request body');
            }
            $raw = $out;
        }
        if (!str_starts_with(ltrim($raw), '{')) {
            return $raw;
        }
        $probe = json_decode($raw, true);
        if (!is_array($probe) || count($probe) !== 1 || !is_string($probe['fxb'] ?? null)) {
            return $raw;
        }
        $inner = base64_decode($probe['fxb'], true);
        if ($inner === false || $inner === '') {
            throw new InvalidArgumentException('fxb envelope was not valid base64');
        }
        return $inner;
    }

    /** @param array<string,mixed> $data */
    public static function expandFields(array $data): array {
        foreach (['content', 'body'] as $key) {
            $encoded = $data[$key . '_b64'] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                continue;
            }
            $data[$key] = self::decodeField($encoded, $key . '_b64');
            unset($data[$key . '_b64']);
        }
        return $data;
    }

    /**
     * Admin form field. `content_b64` wins over `content` when both are posted.
     * $missing is returned when neither key is present (pass null to detect that).
     */
    public static function posted(string $key, ?string $missing = ''): ?string {
        $encoded = $_POST[$key . '_b64'] ?? null;
        if (is_string($encoded) && $encoded !== '') {
            return self::decodeField($encoded, $key . '_b64');
        }
        if (array_key_exists($key, $_POST)) {
            return (string)$_POST[$key];
        }
        return $missing;
    }

    private static function decodeField(string $encoded, string $name): string {
        $encoded = preg_replace('/\s+/', '', $encoded) ?? '';
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new InvalidArgumentException($name . ' was not valid base64');
        }
        return $raw;
    }
}
