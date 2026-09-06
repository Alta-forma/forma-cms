<?php
/**
 * Stateless MCP Streamable HTTP adapter for cloud subscription chatbots.
 *
 * Endpoint: POST /api/v1/mcp with the normal Forma Bearer token. This is a
 * thin tool adapter over the same repositories used by the Agent API; it is
 * intentionally limited to the safe Site editor surface (no delete, settings,
 * import, server, or core-update tools).
 */
class AgentMcp {
    private const PROTOCOL_VERSION = '2025-06-18';

    /** Return a JSON-RPC response, or null for a notification (HTTP 202). */
    public static function handle(array $token, array $request): ?array {
        $id = $request['id'] ?? null;
        $method = (string)($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if (($request['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid JSON-RPC request');
        }
        if ($method === 'notifications/initialized' || str_starts_with($method, 'notifications/')) {
            return null;
        }
        if ($method === 'initialize') {
            return self::result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'forma', 'version' => FORMA_VERSION],
                'instructions' => 'Call formax_help first. Edits publish immediately. Forma automatically protects one last-known-good site before the first write. Never mark the site good unless the human explicitly says it looks right.',
            ]);
        }
        if ($method === 'ping') {
            return self::result($id, (object)[]);
        }
        if ($method === 'tools/list') {
            return self::result($id, ['tools' => self::tools($token)]);
        }
        if ($method === 'tools/call') {
            $name = (string)($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $value = self::callTool($token, $name, $arguments);
                return self::result($id, [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]],
                    'isError' => false,
                ]);
            } catch (Throwable $e) {
                return self::result($id, [
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true,
                ]);
            }
        }
        return self::error($id, -32601, 'Method not found');
    }

    public static function tools(array $token): array {
        $tools = [
            self::tool('formax_help', 'Call first. Explains Forma, every capability, safety rules, SEO, and the one-point rollback workflow.', [], null, true),
            self::tool('formax_site', 'Read site identity, SEO summary, and public URLs before editing.', [], 'content:read', true),
            self::tool('formax_get_site_settings', 'Read editable public site identity fields.', [], 'content:read', true),
            self::tool('formax_update_site_settings', 'Update public site identity fields. Canonical URL and security settings are unavailable.', [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'default_author' => ['type' => 'string'],
            ], 'site:write', false),
            self::tool('formax_list_pages', 'List all pages and their SEO health.', [], 'content:read', true),
            self::tool('formax_get_page', 'Read a complete page before changing it.', [
                'filename' => ['type' => 'string'],
            ], 'content:read', true, ['filename']),
            self::tool('formax_update_page', 'Create or update a page. Publishes immediately; the first write automatically protects the last-known-good site.', [
                'filename' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'content_type' => ['type' => 'string', 'enum' => ['html', 'md']],
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'seo' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ], 'content:write', false, ['filename', 'content']),
            self::tool('formax_list_posts', 'List blog posts and SEO health.', [], 'content:read', true),
            self::tool('formax_get_post', 'Read a complete post before changing it.', [
                'filename' => ['type' => 'string'],
            ], 'content:read', true, ['filename']),
            self::tool('formax_update_post', 'Create or update a Markdown post. Omit/empty date for draft. Publishes immediately when dated.', [
                'filename' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'author' => ['type' => 'string'],
                'date' => ['type' => 'string'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'seo' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ], 'content:write', false, ['filename', 'title', 'body']),
            self::tool('formax_list_snippets', 'List reusable snippets. Changing one may affect many pages.', [], 'content:read', true),
            self::tool('formax_get_snippet', 'Read a reusable snippet before changing it.', [
                'filename' => ['type' => 'string'],
            ], 'content:read', true, ['filename']),
            self::tool('formax_update_snippet', 'Create or update a reusable snippet. Check pages that use its [[shortcode]].', [
                'filename' => ['type' => 'string'],
                'shortcode' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], 'content:write', false, ['filename', 'content']),
            self::tool('formax_list_media', 'List uploads and their public URLs.', [], 'content:read', true),
            self::tool('formax_upload_media', 'Upload one base64 file. Use the returned randomized URL; do not guess the filename.', [
                'filename' => ['type' => 'string'],
                'content_base64' => ['type' => 'string'],
                'content_type' => ['type' => 'string'],
            ], 'media:write', false, ['filename', 'content_base64']),
            self::tool('formax_get_seo', 'Read sitewide SEO settings and the health report.', [], 'content:read', true),
            self::tool('formax_update_seo', 'Update sitewide SEO and rebuild cached public pages. Prefer per-page seo{} when only one document changes.', [
                'value' => ['type' => 'object', 'additionalProperties' => true],
            ], 'site:write', false, ['value']),
            self::tool('formax_rollback_status', 'Check whether edits are pending and whether Put it back is available.', [], 'content:read', true),
            self::tool('formax_put_it_back', 'Restore the entire site to the last-known-good point. Use when the human asks to undo or says the edits are wrong.', [], 'rollback:write', false),
            self::tool('formax_this_looks_good', 'Move the rollback point to the current live site. NEVER call unless the human explicitly says the site looks good.', [], 'rollback:write', false),
        ];

        return array_values(array_filter($tools, function (array $tool) use ($token): bool {
            $scope = $tool['_scope'];
            return $scope === null || in_array($scope, $token['scopes'] ?? [], true);
        }));
    }

    public static function callTool(array $token, string $name, array $a): array {
        $required = [];
        foreach (self::tools($token) as $tool) {
            if ($tool['name'] === $name) {
                $required = $tool['inputSchema']['required'] ?? [];
                foreach ($required as $field) {
                    if (!array_key_exists($field, $a)) {
                        throw new InvalidArgumentException($field . ' is required');
                    }
                }
                return self::dispatch($token, $name, $a);
            }
        }
        throw new RuntimeException('Unknown tool or token lacks its required scope: ' . $name);
    }

    private static function dispatch(array $token, string $name, array $a): array {
        return match ($name) {
            'formax_help' => Agent::helpDocument($token),
            'formax_site' => [
                'product' => FORMA_PRODUCT,
                'version' => FORMA_VERSION,
                'site' => Database::get()->getSetting('site'),
                'seo' => Seo::settings(),
                'checkpoint' => AgentCheckpoint::status(),
            ],
            'formax_get_site_settings' => self::siteSettings(),
            'formax_update_site_settings' => self::updateSiteSettings($token, $a),
            'formax_list_pages' => self::listPages(),
            'formax_get_page' => self::getPage((string)$a['filename']),
            'formax_update_page' => self::updatePage($token, $a),
            'formax_list_posts' => self::listPosts(),
            'formax_get_post' => self::getPost((string)$a['filename']),
            'formax_update_post' => self::updatePost($token, $a),
            'formax_list_snippets' => ['snippets' => SnippetRepo::list()],
            'formax_get_snippet' => self::requireRow(SnippetRepo::get((string)$a['filename']), 'Snippet'),
            'formax_update_snippet' => self::updateSnippet($token, $a),
            'formax_list_media' => ['files' => MediaRepo::list()],
            'formax_upload_media' => self::uploadMedia($token, $a),
            'formax_get_seo' => [
                'seo' => Seo::settings(),
                'health' => Seo::healthReport(),
                'redirects' => RedirectRepo::list(),
            ],
            'formax_update_seo' => self::updateSeo($token, (array)$a['value']),
            'formax_rollback_status' => ['checkpoint' => AgentCheckpoint::status()],
            'formax_put_it_back' => AgentCheckpoint::restore((string)($token['name'] ?? 'Agent')),
            'formax_this_looks_good' => ['success' => true, 'checkpoint' => AgentCheckpoint::accept((string)($token['name'] ?? 'Agent'))],
            default => throw new RuntimeException('Unknown tool: ' . $name),
        };
    }

    private static function listPages(): array {
        $pages = PageRepo::list();
        foreach ($pages as &$page) {
            $full = PageRepo::get((string)($page['filename'] ?? ''));
            if ($full) {
                $health = Seo::quickHealth(Seo::forPage($full), (string)($full['content'] ?? ''));
                $page['seo_ok'] = $health['ok'];
                $page['seo_issues'] = $health['issues'];
            }
        }
        return ['pages' => $pages];
    }

    private static function siteSettings(): array {
        $site = Database::get()->getSetting('site');
        unset($site['url']);
        return ['site' => $site];
    }

    private static function updateSiteSettings(array $token, array $a): array {
        $allowed = ['title', 'description', 'timezone', 'language', 'default_author'];
        $safe = array_intersect_key($a, array_flip($allowed));
        if (!$safe) {
            throw new InvalidArgumentException('Supply at least one editable site field');
        }
        foreach ($safe as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException($key . ' must be a string');
            }
            $safe[$key] = trim((string)$value);
        }
        AgentCheckpoint::beforeMutation($token);
        $merged = array_merge(Database::get()->getSetting('site'), $safe);
        Database::get()->saveSetting('site', $merged);
        Database::get()->flushCache();
        Feed::maybeRegenerateBlog();
        Feed::maybeRegeneratePodcast();
        StaticFallback::republishIfEnabled();
        unset($merged['url']);
        Agent::audit($token, 'mcp.site-settings.save', '/api/v1/mcp');
        return ['success' => true, 'site' => $merged, 'checkpoint' => AgentCheckpoint::status()];
    }

    private static function updateSeo(array $token, array $value): array {
        AgentCheckpoint::beforeMutation($token);
        $merged = array_merge(Seo::settings(), $value);
        foreach ([
            'robots_auto', 'robots_index', 'robots_follow',
            'sitemap_auto', 'sitemap_enabled', 'sitemap_include_pages',
            'sitemap_include_posts', 'sitemap_include_podcast', 'sitemap_include_images',
            'llms_auto', 'llms_enabled', 'title_suffix', 'json_ld_website', 'json_ld_organization',
        ] as $boolKey) {
            if (array_key_exists($boolKey, $value)) {
                $merged[$boolKey] = filter_var($value[$boolKey], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $merged = Seo::normalizeSettings($merged);
        Database::get()->saveSetting('seo', $merged);
        Database::get()->flushCache();
        StaticFallback::republishIfEnabled();
        Agent::audit($token, 'mcp.seo.save', '/api/v1/mcp');
        return [
            'success' => true,
            'value' => $merged,
            'health' => Seo::healthReport(),
            'checkpoint' => AgentCheckpoint::status(),
        ];
    }

    private static function getPage(string $filename): array {
        $row = self::requireRow(PageRepo::get($filename), 'Page');
        $row['meta'] = PageRepo::extractMeta((string)($row['content'] ?? ''));
        $row['seo'] = Seo::forPage($row);
        return $row;
    }

    private static function updatePage(array $token, array $a): array {
        AgentCheckpoint::beforeMutation($token);
        $extra = [];
        foreach ((array)($a['seo'] ?? []) as $key => $value) {
            if (in_array($key, Seo::PAGE_META_KEYS, true)) {
                $extra[$key] = (string)$value;
            }
        }
        if (!empty($a['title'])) {
            $extra['title'] = (string)$a['title'];
        }
        $row = PageRepo::save(
            (string)$a['filename'],
            (string)$a['content'],
            (string)($a['content_type'] ?? 'html'),
            isset($a['slug']) ? (string)$a['slug'] : null,
            $extra
        );
        $warnings = $row['_warnings'] ?? [];
        unset($row['_warnings']);
        Agent::audit($token, 'mcp.pages.save', '/api/v1/mcp', (string)$a['filename']);
        return ['success' => true, 'page' => $row, 'warnings' => $warnings, 'checkpoint' => AgentCheckpoint::status()];
    }

    private static function listPosts(): array {
        $posts = BlogRepo::list(false);
        foreach ($posts as &$post) {
            $health = Seo::quickHealth(Seo::forPost($post));
            $post['seo_ok'] = $health['ok'];
            $post['seo_issues'] = $health['issues'];
        }
        return ['posts' => $posts];
    }

    private static function getPost(string $filename): array {
        $row = self::requireRow(BlogRepo::get($filename), 'Post');
        $row['seo'] = json_decode($row['seo_json'] ?? '{}', true) ?: [];
        return $row;
    }

    private static function updatePost(array $token, array $a): array {
        AgentCheckpoint::beforeMutation($token);
        $row = BlogRepo::save($a);
        $warnings = $row['_warnings'] ?? [];
        unset($row['_warnings']);
        Agent::audit($token, 'mcp.posts.save', '/api/v1/mcp', (string)$a['filename']);
        return ['success' => true, 'post' => $row, 'warnings' => $warnings, 'checkpoint' => AgentCheckpoint::status()];
    }

    private static function updateSnippet(array $token, array $a): array {
        AgentCheckpoint::beforeMutation($token);
        $row = SnippetRepo::save(
            (string)$a['filename'],
            (string)($a['shortcode'] ?? $a['filename']),
            (string)$a['content']
        );
        Agent::audit($token, 'mcp.snippets.save', '/api/v1/mcp', (string)$a['filename']);
        return ['success' => true, 'snippet' => $row, 'checkpoint' => AgentCheckpoint::status()];
    }

    private static function uploadMedia(array $token, array $a): array {
        AgentCheckpoint::beforeMutation($token);
        $file = MediaRepo::saveBase64(
            (string)$a['filename'],
            (string)$a['content_base64'],
            (string)($a['content_type'] ?? 'application/octet-stream')
        );
        Agent::audit($token, 'mcp.media.upload', '/api/v1/mcp', (string)$file['filename']);
        return ['success' => true, 'file' => $file, 'checkpoint' => AgentCheckpoint::status()];
    }

    private static function requireRow(?array $row, string $type): array {
        if (!$row) {
            throw new RuntimeException($type . ' not found');
        }
        return $row;
    }

    private static function tool(
        string $name,
        string $description,
        array $properties,
        ?string $scope,
        bool $readOnly,
        array $required = []
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object)$properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $name === 'formax_put_it_back',
                'idempotentHint' => $readOnly,
            ],
            '_scope' => $scope,
        ];
    }

    private static function result(mixed $id, mixed $result): array {
        if (is_array($result) && isset($result['tools'])) {
            foreach ($result['tools'] as &$tool) {
                unset($tool['_scope']);
            }
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
