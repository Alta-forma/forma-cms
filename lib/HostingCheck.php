<?php
/**
 * Hosting compatibility diagnostics for shared platforms (DreamHost, cPanel, SiteGround, etc.).
 */
class HostingCheck {

    private const MIN_PHP = '8.1.0';

    /** @return list<array<string,mixed>> */
    public static function run(Database $db): array {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = [
            'id'          => 'php_version',
            'level'       => $phpOk ? 'pass' : 'fail',
            'title'       => 'PHP version',
            'detail'      => PHP_VERSION . ($phpOk ? '' : ' (need ' . self::MIN_PHP . '+)'),
            'fix_steps'   => $phpOk ? [] : [
                'Forma requires PHP ' . self::MIN_PHP . ' or newer (8.2+ recommended).',
                'In your host panel, open PHP version / MultiPHP / Select PHP Version and choose 8.1 or newer.',
                'DreamHost: Domains → Manage → PHP. cPanel: MultiPHP Manager. SiteGround: Site Tools → Devs → PHP Manager. Plesk: PHP Settings.',
            ],
            'fix_action'  => null,
        ];

        $requiredExt = [
            'pdo'        => 'Required for the database layer.',
            'pdo_sqlite' => 'Required for SQLite (your content database).',
            'json'       => 'Required for settings and the admin API.',
            'mbstring'   => 'Required for UTF-8 text and Twig templates.',
        ];
        foreach ($requiredExt as $ext => $why) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'id'         => 'ext_' . $ext,
                'level'      => $loaded ? 'pass' : 'fail',
                'title'      => 'PHP extension: ' . $ext,
                'detail'     => $loaded ? 'Loaded' : 'Not loaded',
                'fix_steps'  => $loaded ? [] : array_merge([
                    $why,
                ], [
                    'Enable this extension in the same place you pick the PHP version (tick pdo_sqlite, mbstring, etc.).',
                    'If there is no toggle, open a support ticket: “Please enable the ' . $ext . ' extension for my account.”',
                ]),
                'fix_action' => null,
            ];
        }

        $openssl = extension_loaded('openssl');
        $checks[] = [
            'id'         => 'openssl',
            'level'      => $openssl ? 'pass' : 'warn',
            'title'      => 'PHP extension: openssl',
            'detail'     => $openssl ? 'Loaded' : 'Not loaded',
            'fix_steps'  => $openssl ? [] : [
                'Needed for HTTPS outbound calls (e.g. license validation to formax.app).',
                'Enable the openssl extension in your PHP configuration, or ask your host to turn it on.',
            ],
            'fix_action' => null,
        ];

        $httpOut = extension_loaded('curl')
            || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
        $checks[] = [
            'id'         => 'http_outbound',
            'level'      => $httpOut ? 'pass' : 'fail',
            'title'      => 'Outbound HTTP(S)',
            'detail'     => $httpOut
                ? (extension_loaded('curl') ? 'curl available' : 'allow_url_fopen=On')
                : 'No curl and allow_url_fopen is Off',
            'fix_steps'  => $httpOut ? [] : [
                'License checks need either the curl extension or allow_url_fopen=On in php.ini.',
                'cPanel: MultiPHP INI Editor. Or ask support to enable one of these.',
            ],
            'fix_action' => null,
        ];

        $dirs = [
            'database' => ['path' => dirname(DB_FILE), 'label' => 'database/ (folder for forma.db)'],
            'uploads'  => ['path' => UPLOADS_DIR, 'label' => 'uploads/'],
            'feeds'    => ['path' => FEEDS_DIR, 'label' => 'feeds/'],
            'fallback' => ['path' => FALLBACK_DIR, 'label' => 'fallback/ (last-good HTML if PHP dies)'],
        ];
        foreach ($dirs as $key => $info) {
            $path     = $info['path'];
            $exists   = is_dir($path);
            $writable = $exists && is_writable($path);
            $level    = $writable ? 'pass' : ($exists ? 'fail' : 'warn');
            $checks[] = [
                'id'         => 'dir_' . $key,
                'level'      => $level,
                'title'      => 'Writable folder: ' . $info['label'],
                'detail'     => !$exists ? 'Missing' : ($writable ? 'Exists and writable' : 'Exists but not writable'),
                'fix_steps'  => $writable ? [] : array_merge(
                    !$exists
                        ? ['The folder `' . basename($path) . '` should exist under your site root next to index.php.']
                        : ['The web server user must be allowed to create and change files here (uploads, RSS feeds, DB directory).'],
                    [
                        'Set permissions to 755 for folders (or 775 if the server runs as a different group).',
                        'On SSH: chmod 755 database uploads feeds fallback (from the site root).',
                        'On cPanel File Manager: right-click folder → Change Permissions → 755.',
                        'If it still fails, ask the host which user PHP runs as and ensure that user owns these folders.',
                    ]
                ),
                'fix_action' => (!$exists || !$writable) ? ['id' => 'ensure_directories', 'label' => 'Create folders (try fix)'] : null,
            ];
        }

        $dbFile   = DB_FILE;
        $dbExists = file_exists($dbFile);
        $dbWrite  = $dbExists && is_writable($dbFile);
        $level    = $dbWrite ? 'pass' : ($dbExists ? 'fail' : 'warn');
        $checks[] = [
            'id'         => 'database_file',
            'level'      => $level,
            'title'      => 'Database file (forma.db)',
            'detail'     => !$dbExists ? 'Not created yet (will be created on first use)' : ($dbWrite ? 'Writable' : 'Not writable'),
            'fix_steps'  => ($dbExists && $dbWrite) || !$dbExists ? [] : [
                'The SQLite file must be writable so the admin can save pages and settings.',
                'Set database/forma.db to 644 or 640 and ensure the database/ folder is writable.',
                'Never leave the database world-writable on production; 640 is ideal if your host allows it.',
            ],
            'fix_action' => ($dbExists && !$dbWrite) ? ['id' => 'chmod_database', 'label' => 'Try chmod 0640 on database file'] : null,
        ];

        $htPath = ROOT_DIR . '/.htaccess';
        $htOk   = file_exists($htPath);
        $checks[] = [
            'id'         => 'htaccess',
            'level'      => $htOk ? 'pass' : 'warn',
            'title'      => 'Root .htaccess',
            'detail'     => $htOk ? 'Present' : 'Missing',
            'fix_steps'  => $htOk ? [] : [
                'Without .htaccess, pretty URLs, feed routes, and some security rules may not run on Apache.',
                'Use the button below, or go to Settings → Server and click Save .htaccess.',
                'Nginx hosts do not use .htaccess — add equivalent rewrite rules in the Nginx config (see your host’s docs).',
            ],
            'fix_action' => $htOk ? null : ['id' => 'ensure_htaccess', 'label' => 'Create default .htaccess'],
        ];

        $fbRules = Htaccess::hasStaticFallbackRules();
        $fb = StaticFallback::status();
        $checks[] = [
            'id'         => 'static_fallback',
            'level'      => ($fbRules && !empty($fb['writable'])) ? 'pass' : 'warn',
            'title'      => 'HTML cache + PHP heartbeat',
            'detail'     => ($fbRules ? '.htaccess publish rules present' : '.htaccess missing publish rules')
                . ' · marker ' . (!empty($fb['marker']) ? 'on' : 'off')
                . ' · /up heartbeat · stamp ' . (!empty($fb['stamp']) ? 'written' : 'not yet')
                . ' · last full publish ' . ($fb['last_published'] ? date('Y-m-d H:i', $fb['last_published']) : 'never'),
            'fix_steps'  => $fbRules && !empty($fb['writable']) ? [
                'If this page loaded, PHP is running right now. DreamHost can still break FastCGI later (“No input file specified.”) even when the panel shows PHP 8.3.',
                'Watch GET /up. If it dies but /fallback/php-ok.json still returns 200, the vhost FastCGI map is empty — open a DreamHost ticket; do not wipe database/ or uploads/.',
                'php tools/watch-php.php https://this-site',
            ] : [
                'When HTML cache is on, Apache serves fallback/*.html directly and falls back to PHP for anything not yet built.',
                'Use the button below, or Settings → Cache → enable HTML cache and click "Rebuild HTML cache".',
            ],
            'fix_action' => (!$fbRules) ? ['id' => 'ensure_static_fallback', 'label' => 'Add rules + build HTML cache'] : null,
        ];

        $ftsOk = class_exists('Search') && Search::fts5Available();
        $checks[] = [
            'id'         => 'search_fts5',
            'level'      => $ftsOk ? 'pass' : 'warn',
            'title'      => 'Site search engine',
            'detail'     => $ftsOk
                ? 'SQLite FTS5 available — ranked full-text search'
                : 'FTS5 not available — using LIKE fallback (works, less relevant ranking)',
            'fix_steps'  => $ftsOk ? [] : [
                'Most hosts (DreamHost, cPanel, SiteGround) ship SQLite with FTS5 built in — nothing to do.',
                'If you are stuck on the LIKE fallback long-term, ask your host to enable the FTS5 compile option for php-sqlite3 / pdo_sqlite.',
            ],
            'fix_action' => null,
        ];

        $staticSeo = Htaccess::staticSeoFiles();
        $hasSeoRoutes = Htaccess::hasSeoPassthrough();
        $staticNames = array_keys(array_filter($staticSeo));
        if ($staticNames !== [] && !$hasSeoRoutes) {
            $checks[] = [
                'id'         => 'static_seo_files',
                'level'      => 'fail',
                'title'      => 'Static robots.txt / sitemap.xml shadowing Forma',
                'detail'     => 'On disk: ' . implode(', ', array_map(static fn($k) => $k === 'robots' ? 'robots.txt' : 'sitemap.xml', $staticNames))
                    . ' — Apache serves these files instead of Forma.',
                'fix_steps'  => [
                    'Open Settings → Server and click “Delete static robots/sitemap”, or use the button below.',
                    'Or delete robots.txt (and sitemap.xml if present) from the site root via FTP / File Manager.',
                    'Also add SEO routes to .htaccess so a re-upload can’t shadow Forma again.',
                ],
                'fix_action' => ['id' => 'remove_static_seo', 'label' => 'Delete static SEO files + add routes'],
            ];
        } elseif ($staticNames !== [] && $hasSeoRoutes) {
            $checks[] = [
                'id'         => 'static_seo_files',
                'level'      => 'warn',
                'title'      => 'Static robots.txt / sitemap.xml still on disk',
                'detail'     => '.htaccess routes them to Forma, but leftover files can confuse FTP users. Safe to delete.',
                'fix_steps'  => [
                    'Optional: Settings → Server → Delete static robots/sitemap.',
                ],
                'fix_action' => ['id' => 'remove_static_seo', 'label' => 'Delete static SEO files'],
            ];
        } elseif (!$hasSeoRoutes) {
            $checks[] = [
                'id'         => 'static_seo_files',
                'level'      => 'warn',
                'title'      => 'SEO rewrite routes missing',
                'detail'     => '.htaccess does not force /robots.txt and /sitemap.xml through Forma.',
                'fix_steps'  => [
                    'Settings → Server → Add SEO routes to .htaccess, or Reset to default + Write.',
                ],
                'fix_action' => ['id' => 'ensure_seo_routes', 'label' => 'Add SEO routes to .htaccess'],
            ];
        } else {
            $checks[] = [
                'id'         => 'static_seo_files',
                'level'      => 'pass',
                'title'      => 'Dynamic robots.txt / sitemap.xml',
                'detail'     => 'Forma serves both via PHP (Settings → SEO).',
                'fix_steps'  => [],
                'fix_action' => null,
            ];
        }

        // Run the pretty-URL self-test early so mod_rewrite can use the result
        $prettyOk    = null;
        $prettyDetail = '';
        $cfg  = $db->getConfig();
        $siteUrl = trim($cfg['site']['url'] ?? '');
        if ($siteUrl !== '') {
            $testUrl = rtrim($siteUrl, '/') . '/blog';
            $ctx = @stream_context_create([
                'http' => [
                    'timeout'       => 4,
                    'ignore_errors' => true,
                    'header'        => "User-Agent: Forma-HostingCheck\r\n",
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $resp = @file_get_contents($testUrl, false, $ctx);
            if ($resp !== false) {
                $status = 0;
                foreach ($http_response_header ?? [] as $h) {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) {
                        $status = (int)$m[1];
                    }
                }
                $prettyOk     = ($status >= 200 && $status < 400);
                $prettyDetail = $testUrl . ' → HTTP ' . $status;
            } else {
                $prettyOk     = false;
                $prettyDetail = 'Could not reach ' . $testUrl;
            }
        }

        $rewriteKnown = function_exists('apache_get_modules')
            && in_array('mod_rewrite', apache_get_modules(), true);
        if ($rewriteKnown) {
            $rewriteLevel  = 'pass';
            $rewriteDetail = 'Loaded (detected via apache_get_modules)';
        } elseif ($prettyOk === true) {
            $rewriteLevel  = 'pass';
            $rewriteDetail = 'Working (confirmed by pretty-URL self-test)';
        } else {
            $rewriteLevel  = 'warn';
            $rewriteDetail = 'Could not detect (common on PHP-FPM / nginx proxy)';
        }
        $checks[] = [
            'id'         => 'mod_rewrite',
            'level'      => $rewriteLevel,
            'title'      => 'Apache mod_rewrite',
            'detail'     => $rewriteDetail,
            'fix_steps'  => $rewriteLevel === 'pass' ? [] : [
                'Pretty URLs need mod_rewrite and AllowOverride so .htaccess is read.',
                'If inner links 404 but /index.php works, enable AllowOverride All for your web root (cPanel Apache Configuration, or a support ticket).',
                'LiteSpeed and OpenLiteSpeed usually honor .htaccess like Apache.',
            ],
            'fix_action' => null,
        ];

        $ob = (string)ini_get('open_basedir');
        if ($ob !== '') {
            $checks[] = [
                'id'         => 'open_basedir',
                'level'      => 'warn',
                'title'      => 'open_basedir restriction',
                'detail'     => 'Active (paths limited)',
                'fix_steps'  => [
                    'Your host limits which directories PHP may read. Forma must be able to read **its own** install folder.',
                    'If you see “permission denied” or missing files, ask support to include your site root in **open_basedir**, or relax it for this vhost.',
                ],
                'fix_action' => null,
            ];
        }

        $uploadMax = ini_get('upload_max_filesize');
        $postMax   = ini_get('post_max_size');
        $checks[] = [
            'id'         => 'upload_limits',
            'level'      => 'pass',
            'title'      => 'Upload limits (php.ini)',
            'detail'     => 'upload_max_filesize=' . $uploadMax . ', post_max_size=' . $postMax,
            'fix_steps'  => [],
            'fix_action' => null,
        ];

        $mem = ini_get('memory_limit');
        $checks[] = [
            'id'         => 'memory_limit',
            'level'      => 'pass',
            'title'      => 'memory_limit',
            'detail'     => (string)$mem,
            'fix_steps'  => [],
            'fix_action' => null,
        ];

        $disp = (bool)filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
        if ($disp) {
            $checks[] = [
                'id'         => 'display_errors',
                'level'      => 'warn',
                'title'      => 'display_errors',
                'detail'     => 'On (errors may leak to visitors)',
                'fix_steps'  => [
                    'For production, set display_errors=Off in php.ini and keep log_errors=On.',
                    'cPanel: MultiPHP INI Editor. Or add php_flag display_errors off in .htaccess if your host allows it.',
                ],
                'fix_action' => null,
            ];
        }

        $url  = trim($cfg['site']['url'] ?? '');
        $httpsNow = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if ($url === '') {
            $checks[] = [
                'id'         => 'site_url',
                'level'      => 'warn',
                'title'      => 'Site URL (settings)',
                'detail'     => 'Not set',
                'fix_steps'  => [
                    'Set Site URL under Settings → General to your real public URL, e.g. https://example.com (include https://; omit trailing slash unless your host uses a subpath).',
                    'Feeds, podcast metadata, and some links use this value.',
                ],
                'fix_action' => null,
            ];
        } else {
            $isHttpsUrl = str_starts_with(strtolower($url), 'https://');
            $level      = 'pass';
            $fix        = [];
            if ($httpsNow && ! $isHttpsUrl) {
                $level = 'warn';
                $fix   = [
                    'You are browsing over HTTPS but the configured Site URL starts with http://. Update it to https:// so feeds and canonical URLs stay correct.',
                ];
            }
            $checks[] = [
                'id'         => 'site_url',
                'level'      => $level,
                'title'      => 'Site URL (settings)',
                'detail'     => $url,
                'fix_steps'  => $fix,
                'fix_action' => null,
            ];
        }

        $installPhp = ROOT_DIR . '/install.php';
        if (file_exists($installPhp)) {
            $checks[] = [
                'id'         => 'install_php',
                'level'      => 'warn',
                'title'      => 'install.php still on server',
                'detail'     => 'Present in site root',
                'fix_steps'  => [
                    'After first login, install.php should be renamed to install.php.bak or deleted.',
                    'Use Settings → Server → Remove installation files, or delete it in FTP/File Manager.',
                    'Leaving it with a weak password is a security risk.',
                ],
                'fix_action' => null,
            ];
        }

        $free = @disk_free_space(ROOT_DIR);
        if ($free !== false && $free < 50 * 1024 * 1024) {
            $checks[] = [
                'id'         => 'disk_space',
                'level'      => 'warn',
                'title'      => 'Disk space',
                'detail'     => 'Less than ~50 MB free on site volume',
                'fix_steps'  => [
                    'Low disk space can cause failed uploads and database writes.',
                    'Free space in your hosting control panel or upgrade the plan.',
                ],
                'fix_action' => null,
            ];
        }

        $sub = forma_site_base_path();
        $atRoot = $sub === '';
        $checks[] = [
            'id'         => 'subfolder',
            'level'      => $atRoot ? 'pass' : 'warn',
            'title'      => 'Detected URL path prefix',
            'detail'     => $atRoot ? '(site at domain root)' : $sub,
            'fix_steps'  => $atRoot ? [] : [
                'The app is installed in a subfolder, not at the domain root.',
                'In Settings → Server, set Rewrite base to match (e.g. ' . $sub . '/) and click Save .htaccess.',
                'In Settings → General, set Site URL to include this path, e.g. https://example.com' . $sub,
            ],
            'fix_action' => null,
        ];

        // Static-host detection (GitHub Pages, Netlify, Vercel, S3, etc.)
        $serverSw  = $_SERVER['SERVER_SOFTWARE'] ?? '';
        $isApache  = stripos($serverSw, 'apache') !== false || stripos($serverSw, 'litespeed') !== false;
        $isNginx   = stripos($serverSw, 'nginx') !== false;
        $isStatic  = false;
        $staticHint = '';
        foreach (['HTTP_X_VERCEL_ID', 'HTTP_X_NETLIFY', 'HTTP_X_AMZN_TRACE_ID'] as $h) {
            if (!empty($_SERVER[$h])) { $isStatic = true; break; }
        }
        if (!$isApache && !$isNginx && $serverSw === '') {
            $isStatic = true;
        }
        if ($isStatic) {
            $staticHint = 'Server header: ' . ($serverSw ?: '(empty)');
            $checks[] = [
                'id'         => 'static_host',
                'level'      => 'warn',
                'title'      => 'Possible static / serverless host',
                'detail'     => $staticHint,
                'fix_steps'  => [
                    'Forma requires PHP running on the server (Apache, LiteSpeed, or Nginx with php-fpm).',
                    'Static hosts like Vercel, Netlify, GitHub Pages, and S3 cannot execute PHP.',
                    'If this is a false positive (you *are* on shared hosting), you can ignore this warning.',
                ],
                'fix_action' => null,
            ];
        }

        // Pretty-URL self-test result (test was run earlier for mod_rewrite)
        if ($prettyOk !== null) {
            $checks[] = [
                'id'         => 'pretty_url_test',
                'level'      => $prettyOk ? 'pass' : 'warn',
                'title'      => 'Pretty-URL self-test (/blog)',
                'detail'     => $prettyDetail,
                'fix_steps'  => $prettyOk ? [] : [
                    'The self-test fetched your /blog URL and got an unexpected response.',
                    'Ensure .htaccess is present and AllowOverride is enabled.',
                    'If you just deployed, the URL may not be publicly reachable yet — recheck later.',
                ],
                'fix_action' => null,
            ];
        }

        return $checks;
    }

    /** @param list<array<string,mixed>> $checks */
    public static function summarize(array $checks): array {
        $fail = $warn = $pass = 0;
        foreach ($checks as $c) {
            $lvl = $c['level'] ?? 'pass';
            if ($lvl === 'fail') {
                $fail++;
            } elseif ($lvl === 'warn') {
                $warn++;
            } else {
                $pass++;
            }
        }
        return compact('fail', 'warn', 'pass');
    }
}
