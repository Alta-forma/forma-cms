<?php
/**
 * OpenAPI 3.1 contract for subscription-chatbot Actions.
 *
 * This deliberately exposes the safe site-editor surface, not every
 * administrative Agent API endpoint. There are no delete/settings/import
 * operations here. The same Bearer token and repository logic back both.
 */
class AgentOpenApi {
    public static function document(): array {
        $base = rtrim(forma_public_url('/'), '/') . '/api/v1';

        $seoProperties = [];
        foreach (Seo::PAGE_META_KEYS as $key) {
            $seoProperties[$key] = ['type' => 'string'];
        }
        $seo = ['type' => 'object', 'additionalProperties' => false, 'properties' => $seoProperties];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Forma Site Editor',
                'version' => FORMA_VERSION,
                'description' => 'Edit this Forma website over HTTPS. Read /help first. Edits publish immediately; Forma automatically preserves one last-known-good rollback point before the first write.',
            ],
            'servers' => [['url' => $base]],
            'security' => [['bearerAuth' => []]],
            'paths' => [
                '/help' => self::get('formaHelp', 'Read this first: capabilities, guardrails, SEO rules, and rollback workflow.'),
                '/site' => self::get('getSite', 'Read site identity and public URLs before editing.'),
                '/site-settings' => [
                    'get' => self::operation('getSiteSettings', 'Read editable public identity fields.', true),
                    'put' => self::operation('updateSiteSettings', 'Update public site identity. Canonical site URL and security settings are intentionally unavailable.', false, [], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'timezone' => ['type' => 'string'],
                                'language' => ['type' => 'string'],
                                'default_author' => ['type' => 'string'],
                            ],
                        ]]],
                    ]),
                ],
                '/pages' => self::get('listPages', 'List pages and their SEO health.'),
                '/pages/{filename}' => [
                    'get' => self::operation('getPage', 'Read a complete page before changing it.', true, [
                        self::pathParameter('filename', 'Page filename without extension'),
                    ]),
                    'put' => self::operation('updatePage', 'Create or update a page. Publishes immediately and automatically starts the single rollback point.', false, [
                        self::pathParameter('filename', 'Page filename without extension'),
                    ], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['content'],
                            'properties' => [
                                'content' => ['type' => 'string'],
                                'content_type' => ['type' => 'string', 'enum' => ['html', 'md'], 'default' => 'html'],
                                'slug' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'seo' => $seo,
                            ],
                        ]]],
                    ]),
                ],
                '/posts' => self::get('listPosts', 'List blog posts and their SEO health.'),
                '/posts/{filename}' => [
                    'get' => self::operation('getPost', 'Read a complete blog post before changing it.', true, [
                        self::pathParameter('filename', 'Post filename without extension'),
                    ]),
                    'put' => self::operation('updatePost', 'Create or update a Markdown blog post. Publishes only when published_at/date is set.', false, [
                        self::pathParameter('filename', 'Post filename without extension'),
                    ], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['title', 'body'],
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'author' => ['type' => 'string'],
                                'date' => ['type' => 'string', 'description' => 'ISO-style date; omit or empty for draft'],
                                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'seo' => $seo,
                            ],
                        ]]],
                    ]),
                ],
                '/snippets' => self::get('listSnippets', 'List reusable snippets. A snippet edit can affect many pages.'),
                '/snippets/{filename}' => [
                    'get' => self::operation('getSnippet', 'Read a reusable snippet before changing it.', true, [
                        self::pathParameter('filename', 'Snippet filename without extension'),
                    ]),
                    'put' => self::operation('updateSnippet', 'Create or update a reusable snippet. Check every page that uses its [[shortcode]].', false, [
                        self::pathParameter('filename', 'Snippet filename without extension'),
                    ], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['content'],
                            'properties' => [
                                'shortcode' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                            ],
                        ]]],
                    ]),
                ],
                '/media' => [
                    'get' => self::operation('listMedia', 'List uploaded media and public URLs.', true),
                    'post' => self::operation('uploadMedia', 'Upload one file as base64. Use the returned randomized public URL in page content.', false, [], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['filename', 'content_base64'],
                            'properties' => [
                                'filename' => ['type' => 'string'],
                                'content_base64' => ['type' => 'string', 'format' => 'byte'],
                                'content_type' => ['type' => 'string'],
                            ],
                        ]]],
                    ]),
                ],
                '/seo' => [
                    'get' => self::operation('getSeoHealth', 'Read sitewide SEO settings and health.', true),
                    'put' => self::operation('updateSiteSeo', 'Update sitewide SEO and rebuild every cached public page. Prefer per-page seo{} when only one document changes.', false, [], [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'description' => 'Sitewide SEO fields documented by formaHelp. Supports titles, favicon/share images, robots, sitemap, social profiles, and schema organization/local-business details.',
                            'additionalProperties' => true,
                        ]]],
                    ]),
                ],
                '/checkpoint' => self::get('getRollbackStatus', 'Check whether agent edits are pending and whether Put it back is available.'),
                '/checkpoint/restore' => self::post('restoreLastGoodSite', 'Put the entire site back to the last-known-good point. Use when the human asks to undo or says the edits are wrong.'),
                '/checkpoint/accept' => self::post('acceptCurrentSite', 'Move the rollback point to the current live site. NEVER call unless the human explicitly says the site looks good.'),
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Forma token (fx_...)',
                    ],
                ],
            ],
        ];
    }

    /** Dependency-free YAML for importers that choose a parser by extension. */
    public static function yaml(): string {
        return self::dumpYaml(self::document()) . "\n";
    }

    private static function get(string $operationId, string $summary): array {
        return ['get' => self::operation($operationId, $summary, true)];
    }

    private static function post(string $operationId, string $summary): array {
        return ['post' => self::operation($operationId, $summary, false)];
    }

    private static function operation(
        string $operationId,
        string $summary,
        bool $readOnly,
        array $parameters = [],
        ?array $requestBody = null
    ): array {
        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'parameters' => $parameters,
            'responses' => [
                '200' => [
                    'description' => 'Success',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
                '400' => ['description' => 'Invalid request'],
                '401' => ['description' => 'Missing or invalid Forma token'],
                '403' => ['description' => 'Token lacks the required scope'],
            ],
            'x-openai-isConsequential' => !$readOnly,
        ];
        $scopeByOperation = [
            'formaHelp' => null,
            'getSite' => 'content:read',
            'getSiteSettings' => 'content:read',
            'updateSiteSettings' => 'site:write',
            'listPages' => 'content:read',
            'getPage' => 'content:read',
            'updatePage' => 'content:write',
            'listPosts' => 'content:read',
            'getPost' => 'content:read',
            'updatePost' => 'content:write',
            'listSnippets' => 'content:read',
            'getSnippet' => 'content:read',
            'updateSnippet' => 'content:write',
            'listMedia' => 'content:read',
            'uploadMedia' => 'media:write',
            'getSeoHealth' => 'content:read',
            'updateSiteSeo' => 'site:write',
            'getRollbackStatus' => 'content:read',
            'restoreLastGoodSite' => 'rollback:write',
            'acceptCurrentSite' => 'rollback:write',
        ];
        if (!empty($scopeByOperation[$operationId])) {
            $operation['x-forma-scope'] = $scopeByOperation[$operationId];
        }
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }
        return $operation;
    }

    private static function pathParameter(string $name, string $description): array {
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'description' => $description,
            'schema' => ['type' => 'string'],
        ];
    }

    private static function dumpYaml(mixed $value, int $indent = 0): string {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return str_repeat(' ', $indent) . self::yamlScalar($value);
        }
        if ($value === []) {
            return str_repeat(' ', $indent) . '[]';
        }
        $pad = str_repeat(' ', $indent);
        $isList = array_keys($value) === range(0, count($value) - 1);
        $lines = [];
        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $item = (array)$item;
            }
            $nested = is_array($item) && $item !== [];
            if ($isList) {
                $lines[] = $nested
                    ? $pad . "-\n" . self::dumpYaml($item, $indent + 2)
                    : $pad . '- ' . (is_array($item) ? '[]' : self::yamlScalar($item));
            } else {
                $prefix = $pad . self::yamlScalar((string)$key) . ':';
                $lines[] = $nested
                    ? $prefix . "\n" . self::dumpYaml($item, $indent + 2)
                    : $prefix . ' ' . (is_array($item) ? '[]' : self::yamlScalar($item));
            }
        }
        return implode("\n", $lines);
    }

    private static function yamlScalar(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }
}
