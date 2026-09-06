<?php
/**
 * Forma OAuth 2.1 authorization server for remote MCP connectors.
 *
 * Public clients use authorization-code + PKCE S256. Access, authorization,
 * and refresh credentials are opaque random values stored only as SHA-256
 * hashes. OAuth grants are limited to the non-destructive Site editor preset.
 */
class AgentOAuth {
    public const ACCESS_TTL = 3600;
    public const CODE_TTL = 300;
    public const REFRESH_TTL = 2592000;
    public const SITE_EDITOR_SCOPES = [
        'content:read',
        'content:write',
        'media:write',
        'site:write',
        'rollback:write',
    ];

    public static function issuer(): string {
        return rtrim(forma_public_url('/'), '/');
    }

    public static function resourceUri(): string {
        return self::issuer() . '/api/v1/mcp';
    }

    public static function requireSecureTransport(): void {
        $security = Database::get()->getSetting('security');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $local = in_array($ip, ['127.0.0.1', '::1'], true)
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.');
        if (($security['agent_https_only'] ?? true) && !$https && !$local) {
            throw new OAuthException('invalid_request', 'OAuth requires HTTPS', 403);
        }
    }

    public static function protectedResourceMetadata(): array {
        return [
            'resource' => self::resourceUri(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => self::SITE_EDITOR_SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => self::issuer() . '/admin/index.php?section=settings&sub=access',
        ];
    }

    public static function authorizationServerMetadata(): array {
        $issuer = self::issuer();
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'scopes_supported' => self::SITE_EDITOR_SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $issuer . '/admin/index.php?section=settings&sub=access',
        ];
    }

    public static function register(array $input, string $ip): array {
        self::prune();
        $db = Database::get();
        $since = time() - 3600;
        $recent = $db->queryOne(
            'SELECT COUNT(*) AS c FROM oauth_clients WHERE registration_ip = ? AND created_at >= ?',
            [$ip, $since]
        );
        $total = $db->queryOne('SELECT COUNT(*) AS c FROM oauth_clients WHERE revoked_at IS NULL');
        if ((int)($recent['c'] ?? 0) >= 20 || (int)($total['c'] ?? 0) >= 500) {
            throw new OAuthException('invalid_client_metadata', 'Registration limit reached', 429);
        }

        $uris = $input['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > 10) {
            throw new OAuthException('invalid_redirect_uri', 'One to ten redirect_uris are required');
        }
        $redirects = [];
        foreach ($uris as $uri) {
            $redirects[] = self::validateRedirectUri((string)$uri);
        }
        $redirects = array_values(array_unique($redirects));

        $authMethod = (string)($input['token_endpoint_auth_method'] ?? 'none');
        if ($authMethod !== 'none') {
            throw new OAuthException('invalid_client_metadata', 'Only public PKCE clients are supported');
        }
        $grantTypes = $input['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grantTypes) || !in_array('authorization_code', $grantTypes, true)) {
            throw new OAuthException('invalid_client_metadata', 'authorization_code grant is required');
        }
        $name = trim(strip_tags((string)($input['client_name'] ?? 'AI connector')));
        $name = substr($name !== '' ? $name : 'AI connector', 0, 100);
        $scopes = self::requestedScopes((string)($input['scope'] ?? ''));
        if (!$scopes) {
            $scopes = self::SITE_EDITOR_SCOPES;
        }

        $clientId = 'fxc_' . bin2hex(random_bytes(24));
        $db->execute(
            'INSERT INTO oauth_clients
                (client_id, client_name, redirect_uris, scopes, registration_ip)
             VALUES (?, ?, ?, ?, ?)',
            [$clientId, $name, json_encode($redirects), json_encode($scopes), $ip]
        );
        return [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $redirects,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', $scopes),
        ];
    }

    /** Validate and normalize an authorization request for session storage. */
    public static function authorizationRequest(array $input): array {
        if ((string)($input['response_type'] ?? '') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'Only response_type=code is supported');
        }
        $client = self::client((string)($input['client_id'] ?? ''));
        $redirect = self::validateRedirectUri((string)($input['redirect_uri'] ?? ''));
        $registered = json_decode((string)$client['redirect_uris'], true) ?: [];
        if (!in_array($redirect, $registered, true)) {
            throw new OAuthException('invalid_request', 'redirect_uri does not match the registered client');
        }
        $challenge = (string)($input['code_challenge'] ?? '');
        if ((string)($input['code_challenge_method'] ?? '') !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)
        ) {
            throw new OAuthException('invalid_request', 'PKCE S256 is required');
        }
        $allowed = json_decode((string)$client['scopes'], true) ?: self::SITE_EDITOR_SCOPES;
        $scopes = self::requestedScopes((string)($input['scope'] ?? ''));
        if (!$scopes) {
            $scopes = $allowed;
        }
        if (array_diff($scopes, $allowed) || array_diff($scopes, self::SITE_EDITOR_SCOPES)) {
            throw new OAuthException('invalid_scope', 'Requested scope is not available');
        }
        $resource = self::normalizeResource((string)($input['resource'] ?? ''));
        return [
            'client_id' => (string)$client['client_id'],
            'client_name' => (string)$client['client_name'],
            'redirect_uri' => $redirect,
            'scope' => implode(' ', $scopes),
            'scopes' => $scopes,
            'state' => substr((string)($input['state'] ?? ''), 0, 1000),
            'code_challenge' => $challenge,
            'resource' => $resource,
        ];
    }

    public static function approve(array $request, string $adminUser): string {
        $raw = 'fxc_code_' . bin2hex(random_bytes(32));
        Database::get()->execute(
            'INSERT INTO oauth_authorization_codes
                (code_hash, client_id, redirect_uri, scopes, code_challenge,
                 resource, admin_user, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                hash('sha256', $raw),
                $request['client_id'],
                $request['redirect_uri'],
                json_encode($request['scopes']),
                $request['code_challenge'],
                $request['resource'],
                $adminUser,
                time() + self::CODE_TTL,
            ]
        );
        Database::get()->execute(
            'UPDATE oauth_clients SET last_used = ? WHERE client_id = ?',
            [time(), $request['client_id']]
        );
        return $raw;
    }

    public static function token(array $input): array {
        self::prune();
        $grant = (string)($input['grant_type'] ?? '');
        if ($grant === 'authorization_code') {
            return self::exchangeCode($input);
        }
        if ($grant === 'refresh_token') {
            return self::refresh($input);
        }
        throw new OAuthException('unsupported_grant_type', 'Unsupported grant_type');
    }

    public static function listConnections(): array {
        return Database::get()->query(
            'SELECT c.client_id, c.client_name, c.redirect_uris, c.scopes,
                    c.created_at, c.last_used, c.revoked_at,
                    (SELECT COUNT(*) FROM api_tokens t
                     WHERE t.oauth_client_id = c.client_id
                       AND t.revoked_at IS NULL
                       AND (t.expires_at IS NULL OR t.expires_at > strftime(\'%s\',\'now\'))) AS active_tokens
             FROM oauth_clients c
             ORDER BY c.created_at DESC'
        );
    }

    public static function revokeClient(string $clientId): void {
        $now = time();
        $db = Database::get();
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $db->execute(
                'UPDATE oauth_clients SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                [$now, $clientId]
            );
            $db->execute(
                'UPDATE api_tokens SET revoked_at = ? WHERE oauth_client_id = ? AND revoked_at IS NULL',
                [$now, $clientId]
            );
            $db->execute(
                'UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                [$now, $clientId]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function errorRedirect(array $request, string $error, string $description = ''): string {
        $params = ['error' => $error, 'iss' => self::issuer()];
        if ($description !== '') {
            $params['error_description'] = $description;
        }
        if (($request['state'] ?? '') !== '') {
            $params['state'] = $request['state'];
        }
        return self::appendQuery((string)$request['redirect_uri'], $params);
    }

    public static function successRedirect(array $request, string $code): string {
        $params = ['code' => $code, 'iss' => self::issuer()];
        if (($request['state'] ?? '') !== '') {
            $params['state'] = $request['state'];
        }
        return self::appendQuery((string)$request['redirect_uri'], $params);
    }

    private static function exchangeCode(array $input): array {
        $clientId = (string)($input['client_id'] ?? '');
        self::client($clientId);
        $rawCode = (string)($input['code'] ?? '');
        $redirect = self::validateRedirectUri((string)($input['redirect_uri'] ?? ''));
        $verifier = (string)($input['code_verifier'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            throw new OAuthException('invalid_grant', 'Invalid PKCE verifier');
        }
        $db = Database::get();
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $row = $db->queryOne(
                'SELECT * FROM oauth_authorization_codes WHERE code_hash = ?',
                [hash('sha256', $rawCode)]
            );
            $valid = $row
                && hash_equals((string)$row['client_id'], $clientId)
                && hash_equals((string)$row['redirect_uri'], $redirect)
                && empty($row['used_at'])
                && (int)$row['expires_at'] >= time()
                && hash_equals(
                    (string)$row['code_challenge'],
                    self::base64Url(hash('sha256', $verifier, true))
                );
            if (!$valid) {
                throw new OAuthException('invalid_grant', 'Authorization code is invalid, expired, or already used');
            }
            self::assertTokenResource($input, (string)$row['resource']);
            $stmt = $pdo->prepare(
                'UPDATE oauth_authorization_codes SET used_at = ?
                 WHERE code_hash = ? AND used_at IS NULL'
            );
            $stmt->execute([time(), hash('sha256', $rawCode)]);
            if ($stmt->rowCount() !== 1) {
                throw new OAuthException('invalid_grant', 'Authorization code was already used');
            }
            $tokens = self::issueTokens(
                $clientId,
                json_decode((string)$row['scopes'], true) ?: [],
                (string)$row['resource']
            );
            $pdo->commit();
            return $tokens;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function refresh(array $input): array {
        $clientId = (string)($input['client_id'] ?? '');
        self::client($clientId);
        $raw = (string)($input['refresh_token'] ?? '');
        $db = Database::get();
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $row = $db->queryOne(
                'SELECT * FROM oauth_refresh_tokens WHERE token_hash = ?',
                [hash('sha256', $raw)]
            );
            if (!$row || !hash_equals((string)$row['client_id'], $clientId)
                || (int)$row['expires_at'] < time()
            ) {
                throw new OAuthException('invalid_grant', 'Refresh token is invalid or expired');
            }
            if (!empty($row['revoked_at'])) {
                $now = time();
                $db->execute(
                    'UPDATE oauth_clients SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                    [$now, $clientId]
                );
                $db->execute(
                    'UPDATE api_tokens SET revoked_at = ? WHERE oauth_client_id = ? AND revoked_at IS NULL',
                    [$now, $clientId]
                );
                $db->execute(
                    'UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL',
                    [$now, $clientId]
                );
                $pdo->commit();
                throw new OAuthException('invalid_grant', 'Refresh token reuse detected; connection revoked');
            }
            self::assertTokenResource($input, (string)$row['resource']);
            $scopes = json_decode((string)$row['scopes'], true) ?: [];
            $requested = self::requestedScopes((string)($input['scope'] ?? ''));
            if ($requested) {
                if (array_diff($requested, $scopes)) {
                    throw new OAuthException('invalid_scope', 'Refresh cannot add scopes');
                }
                $scopes = $requested;
            }
            $claim = $pdo->prepare(
                'UPDATE oauth_refresh_tokens
                 SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL'
            );
            $claim->execute([time(), hash('sha256', $raw)]);
            if ($claim->rowCount() !== 1) {
                throw new OAuthException('invalid_grant', 'Refresh token was already used');
            }
            $tokens = self::issueTokens($clientId, $scopes, (string)$row['resource']);
            $db->execute(
                'UPDATE oauth_refresh_tokens SET replaced_by = ? WHERE token_hash = ?',
                [hash('sha256', $tokens['refresh_token']), hash('sha256', $raw)]
            );
            if (!empty($row['access_token_id'])) {
                $db->execute(
                    'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
                    [time(), (int)$row['access_token_id']]
                );
            }
            $pdo->commit();
            return $tokens;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function issueTokens(string $clientId, array $scopes, string $resource): array {
        $db = Database::get();
        $access = 'fx_oauth_' . bin2hex(random_bytes(32));
        $refresh = 'fx_refresh_' . bin2hex(random_bytes(32));
        $now = time();
        $client = self::client($clientId);
        $db->execute(
            'INSERT INTO api_tokens
                (name, token_hash, scopes, expires_at, audience, oauth_client_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                'OAuth: ' . (string)$client['client_name'],
                hash('sha256', $access),
                json_encode(array_values($scopes)),
                $now + self::ACCESS_TTL,
                $resource,
                $clientId,
            ]
        );
        $accessId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO oauth_refresh_tokens
                (token_hash, access_token_id, client_id, scopes, resource, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                hash('sha256', $refresh),
                $accessId,
                $clientId,
                json_encode(array_values($scopes)),
                $resource,
                $now + self::REFRESH_TTL,
            ]
        );
        $db->execute('UPDATE oauth_clients SET last_used = ? WHERE client_id = ?', [$now, $clientId]);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        ];
    }

    private static function client(string $clientId): array {
        $row = Database::get()->queryOne(
            'SELECT * FROM oauth_clients WHERE client_id = ?',
            [$clientId]
        );
        if (!$row || !empty($row['revoked_at'])) {
            throw new OAuthException('invalid_client', 'Unknown or revoked client', 401);
        }
        return $row;
    }

    private static function parseScopes(string $value): array {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        return array_values(array_unique(array_intersect($parts, self::SITE_EDITOR_SCOPES)));
    }

    private static function requestedScopes(string $value): array {
        $parts = array_values(array_unique(array_filter(preg_split('/\s+/', trim($value)) ?: [])));
        if (array_diff($parts, self::SITE_EDITOR_SCOPES)) {
            throw new OAuthException('invalid_scope', 'Requested scope is not available');
        }
        return self::parseScopes($value);
    }

    private static function normalizeResource(string $resource): string {
        $resource = rtrim(trim($resource), '/');
        $expected = rtrim(self::resourceUri(), '/');
        if ($resource === '') {
            return $expected;
        }
        if (!hash_equals($expected, $resource)) {
            throw new OAuthException('invalid_target', 'Token resource must be the Forma MCP endpoint');
        }
        return $expected;
    }

    private static function assertTokenResource(array $input, string $expected): void {
        $given = (string)($input['resource'] ?? '');
        if ($given !== '' && !hash_equals(rtrim($expected, '/'), rtrim($given, '/'))) {
            throw new OAuthException('invalid_target', 'Token resource does not match authorization');
        }
    }

    private static function validateRedirectUri(string $uri): string {
        if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\x00-\x20]/', $uri)) {
            throw new OAuthException('invalid_redirect_uri', 'Invalid redirect URI');
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !empty($parts['fragment']) || !empty($parts['user']) || !empty($parts['pass'])) {
            throw new OAuthException('invalid_redirect_uri', 'Invalid redirect URI');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new OAuthException('invalid_redirect_uri', 'Redirect URI must use HTTPS or a loopback host');
        }
        if ($host === '') {
            throw new OAuthException('invalid_redirect_uri', 'Redirect URI host is required');
        }
        return $uri;
    }

    private static function appendQuery(string $uri, array $params): string {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function base64Url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function prune(): void {
        $db = Database::get();
        $now = time();
        $db->execute('DELETE FROM oauth_authorization_codes WHERE expires_at < ?', [$now - 86400]);
        $db->execute('DELETE FROM oauth_refresh_tokens WHERE expires_at < ?', [$now - 86400]);
        $db->execute(
            'DELETE FROM oauth_clients
             WHERE last_used IS NULL AND created_at < ? AND client_id NOT IN
                (SELECT DISTINCT client_id FROM oauth_refresh_tokens)',
            [$now - 604800]
        );
    }
}

class OAuthException extends RuntimeException {
    public string $oauthError;
    public int $httpStatus;

    public function __construct(string $oauthError, string $message, int $httpStatus = 400) {
        parent::__construct($message);
        $this->oauthError = $oauthError;
        $this->httpStatus = $httpStatus;
    }
}
