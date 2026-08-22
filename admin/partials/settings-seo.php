<?php
require_once __DIR__ . '/_helpers.php';
$s = Seo::settings();
$site = Database::get()->getSetting('site');
$base = rtrim($site['url'] ?? '', '/') ?: 'https://your-domain.com';
$robotsAuto = !array_key_exists('robots_auto', $s) || !empty($s['robots_auto']);
$sitemapAuto = !array_key_exists('sitemap_auto', $s) || !empty($s['sitemap_auto']);
$health = Seo::healthReport();
$redirects = RedirectRepo::list();
$schemaType = $s['schema_type'] ?? 'person';
$previewTitle = $site['title'] ?? 'Site title';
$previewDesc = $site['description'] ?? '';
$previewImage = $s['default_og_image'] ?? '';
$previewUrl = $base . '/';
?>
<?php echo fx_panel_header('search', 'SEO', 'Mostly automatic — set defaults once, override pages only when you care'); ?>

<?php
$score = (int)$health['score'];
$scoreClass = $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'off');
?>
<div class="settings-card">
    <h3><i class="fas fa-heartbeat"></i> SEO health</h3>
    <p class="card-sub">Live audit of titles, descriptions, images, and site basics. Fix these before chasing backlinks.</p>
    <div class="seo-health-summary">
        <div class="seo-score status-badge <?php echo h($scoreClass); ?>"><?php echo $score; ?> / 100</div>
        <span class="status-badge off"><?php echo (int)$health['counts']['fail']; ?> fail</span>
        <span class="status-badge warn"><?php echo (int)$health['counts']['warn']; ?> warn</span>
        <span class="status-badge ok"><?php echo (int)$health['counts']['info']; ?> info</span>
    </div>
    <?php if (empty($health['issues'])): ?>
        <p class="hint" style="margin:0.75rem 0 0">Looking good — no issues found.</p>
    <?php else: ?>
        <div class="seo-issue-list">
            <?php foreach (array_slice($health['issues'], 0, 40) as $issue): ?>
            <div class="seo-issue level-<?php echo h($issue['level']); ?>">
                <span class="lvl"><?php echo h($issue['level']); ?></span>
                <div class="msg">
                    <strong><?php echo h($issue['message']); ?></strong>
                    <?php if (!empty($issue['fix'])): ?><span class="hint"><?php echo h($issue['fix']); ?></span><?php endif; ?>
                </div>
                <?php if (!empty($issue['href'])): ?>
                <a class="standard-btn" style="min-width:auto;padding:4px 10px;font-size:.8rem" href="<?php echo h($issue['href']); ?>">Open</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($health['issues']) > 40): ?>
        <p class="hint">Showing 40 of <?php echo count($health['issues']); ?> issues.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<form hx-post="actions/settings-save.php" hx-target="#settings-panel" hx-swap="innerHTML" id="seo-settings-form" class="seo-settings-form">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="seo">

    <div class="settings-card">
        <h3><i class="fas fa-image"></i> Branding & social</h3>
        <p class="card-sub">Favicon, default share image, and live previews. Per-page featured images override the default.</p>
        <div class="form-row">
            <div class="form-group">
                <?php echo fx_media_field('favicon', $s['favicon'] ?? '', [
                    'label' => 'Favicon',
                    'placeholder' => '/uploads/favicon.ico',
                    'hint' => 'Path under /uploads — .ico, .png, or .svg',
                    'attrs' => 'data-seo-field="favicon"',
                ]); ?>
            </div>
            <div class="form-group">
                <?php echo fx_media_field('apple_touch_icon', $s['apple_touch_icon'] ?? '', [
                    'label' => 'Apple touch icon',
                    'placeholder' => '/uploads/apple-touch-icon.png',
                ]); ?>
            </div>
        </div>
        <div class="form-group">
            <?php echo fx_media_field('default_og_image', $s['default_og_image'] ?? '', [
                'label' => 'Default featured / social image',
                'placeholder' => '/uploads/og-default.jpg',
                'hint' => '1200×630 recommended — used when a page/post has no featured image',
                'attrs' => 'data-seo-field="image"',
            ]); ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Twitter / X @site</label>
                <input type="text" name="twitter_site" value="<?php echo h($s['twitter_site'] ?? ''); ?>" placeholder="@handle">
            </div>
            <div class="form-group">
                <label>Default Twitter card</label>
                <select name="twitter_card">
                    <?php foreach (['summary_large_image', 'summary'] as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo ($s['twitter_card'] ?? '') === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
        $previewTitle = $site['title'] ?? 'Site title';
        $previewDesc = $site['description'] ?? '';
        $previewImage = $s['default_og_image'] ?? '';
        $previewUrl = $base . '/';
        require __DIR__ . '/_seo-preview.php';
        ?>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-project-diagram"></i> Schema & local</h3>
        <p class="card-sub">Structured data for Google. Pick the entity that matches this site. Place ID helps Maps — it is not a ranking cheat code.</p>
        <div class="form-group">
            <label>Primary entity</label>
            <select name="schema_type">
                <?php foreach ([
                    'person' => 'Person (freelancer / portfolio)',
                    'organization' => 'Organization (company / brand)',
                    'local_business' => 'LocalBusiness (storefront / office)',
                    'none' => 'None (WebSite only)',
                ] as $val => $label): ?>
                <option value="<?php echo h($val); ?>" <?php echo $schemaType === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php echo fx_switch('json_ld_website', !empty($s['json_ld_website']), 'Emit WebSite JSON-LD', 'Recommended on'); ?>
        <div class="form-row" style="margin-top:1rem">
            <div class="form-group">
                <label>Display name</label>
                <input type="text" name="organization_name" value="<?php echo h($s['organization_name'] ?? ''); ?>" placeholder="Defaults to site title">
            </div>
            <div class="form-group">
                <?php echo fx_media_field('organization_logo', $s['organization_logo'] ?? '', [
                    'label' => 'Logo / headshot',
                    'placeholder' => '/uploads/logo.png',
                ]); ?>
            </div>
        </div>
        <div class="form-group">
            <label>sameAs profiles</label>
            <textarea name="same_as" rows="3" placeholder="https://www.linkedin.com/in/…&#10;https://www.instagram.com/…"><?php echo h($s['same_as'] ?? ''); ?></textarea>
            <span class="hint">One URL per line — LinkedIn, IMDb, Instagram, etc.</span>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email (schema)</label>
                <input type="email" name="schema_email" value="<?php echo h($s['schema_email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Phone (LocalBusiness)</label>
                <input type="text" name="schema_phone" value="<?php echo h($s['schema_phone'] ?? ''); ?>" placeholder="+1 …">
            </div>
        </div>
        <div class="form-group">
            <label>Street address</label>
            <input type="text" name="schema_address" value="<?php echo h($s['schema_address'] ?? ''); ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>City</label>
                <input type="text" name="schema_city" value="<?php echo h($s['schema_city'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Region / state</label>
                <input type="text" name="schema_region" value="<?php echo h($s['schema_region'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Postal code</label>
                <input type="text" name="schema_postal" value="<?php echo h($s['schema_postal'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Country</label>
                <input type="text" name="schema_country" value="<?php echo h($s['schema_country'] ?? 'US'); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Google Place ID</label>
                <input type="text" name="place_id" value="<?php echo h($s['place_id'] ?? ''); ?>" placeholder="ChIJ…">
                <span class="hint">From Google’s Place ID finder — used in LocalBusiness hasMap</span>
            </div>
            <div class="form-group">
                <label>Google Business profile URL</label>
                <input type="url" name="gbp_url" value="<?php echo h($s['gbp_url'] ?? ''); ?>" placeholder="https://maps.google.com/…">
            </div>
        </div>
        <div class="form-group">
            <label>Maps embed / share URL (optional)</label>
            <input type="url" name="maps_embed_url" value="<?php echo h($s['maps_embed_url'] ?? ''); ?>">
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-robot"></i> robots.txt</h3>
        <?php echo fx_switch('robots_auto', $robotsAuto, 'Auto-generate robots.txt', 'Turn off to edit the full file manually below'); ?>
        <?php echo fx_switch('robots_index', !empty($s['robots_index']), 'Allow search engines to index', 'Uncheck for staging'); ?>
        <?php echo fx_switch('robots_follow', !empty($s['robots_follow']), 'Allow following links', ''); ?>
        <div class="form-group" style="margin-top:1rem">
            <label>Disallow paths</label>
            <input type="text" name="noindex_paths" value="<?php echo h($s['noindex_paths'] ?? '/admin,/api,/old'); ?>">
        </div>
        <div class="form-group">
            <label>Extra robots.txt lines</label>
            <textarea name="robots_extra" rows="3"><?php echo h($s['robots_extra'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label>Manual robots.txt</label>
            <textarea name="robots_manual" rows="6" class="mono" spellcheck="false"><?php echo h($s['robots_manual'] ?? ''); ?></textarea>
            <span class="hint">Used when Auto is off. Empty + Save seeds from auto output.</span>
        </div>
        <?php echo fx_url_pill($base . '/robots.txt'); ?>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-sitemap"></i> Sitemap</h3>
        <?php echo fx_switch('sitemap_enabled', !empty($s['sitemap_enabled']), 'Enable sitemap.xml', ''); ?>
        <?php echo fx_switch('sitemap_auto', $sitemapAuto, 'Auto-generate sitemap.xml', ''); ?>
        <?php echo fx_switch('sitemap_include_pages', !empty($s['sitemap_include_pages']), 'Include pages', ''); ?>
        <?php echo fx_switch('sitemap_include_posts', !empty($s['sitemap_include_posts']), 'Include published blog posts + /blog', ''); ?>
        <?php echo fx_switch('sitemap_include_podcast', !empty($s['sitemap_include_podcast']), 'Include podcast', 'When licensed'); ?>
        <?php echo fx_switch('sitemap_include_images', !array_key_exists('sitemap_include_images', $s) || !empty($s['sitemap_include_images']), 'Include featured images (image sitemap)', 'One image per URL from featured / OG image'); ?>
        <div class="form-group" style="margin-top:1rem">
            <label>Manual sitemap.xml</label>
            <textarea name="sitemap_manual" rows="6" class="mono" spellcheck="false"><?php echo h($s['sitemap_manual'] ?? ''); ?></textarea>
        </div>
        <?php echo fx_url_pill($base . '/sitemap.xml'); ?>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-heading"></i> Titles & verification</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Title separator</label>
                <input type="text" name="title_separator" value="<?php echo h($s['title_separator'] ?? ' — '); ?>">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.35rem">
                <?php echo fx_switch('title_suffix', !empty($s['title_suffix']), 'Append site title to page titles', ''); ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Google site verification</label>
                <input type="text" name="google_site_verification" value="<?php echo h($s['google_site_verification'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Bing site verification</label>
                <input type="text" name="bing_site_verification" value="<?php echo h($s['bing_site_verification'] ?? ''); ?>">
            </div>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save SEO settings</button>
        </div>
    </div>
</form>

<div class="settings-card" id="seo-redirects">
    <h3><i class="fas fa-directions"></i> Redirects</h3>
    <p class="card-sub">301/302 rules applied in PHP before pages load. Prefer this over hand-editing .htaccess.</p>
    <form class="redirect-add-form" hx-post="actions/redirects-save.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="enabled" value="1">
        <div class="form-row">
            <div class="form-group">
                <label>From path</label>
                <input type="text" name="from_path" placeholder="/old-page" required>
            </div>
            <div class="form-group">
                <label>To URL or path</label>
                <input type="text" name="to_url" placeholder="/new-page or https://…" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="301">301 permanent</option>
                    <option value="302">302 temporary</option>
                    <option value="307">307</option>
                    <option value="308">308</option>
                </select>
            </div>
            <div class="form-group">
                <label>Note</label>
                <input type="text" name="note" placeholder="Optional">
            </div>
        </div>
        <div class="card-actions" style="border-top:none;padding-top:0">
            <button type="submit" class="standard-btn"><i class="small fas fa-plus"></i> Add redirect</button>
        </div>
    </form>
    <?php if ($redirects): ?>
    <table class="token-table" style="margin-top:1rem">
        <thead><tr><th>From</th><th>To</th><th>Code</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($redirects as $r): ?>
            <tr class="<?php echo empty($r['enabled']) ? 'revoked' : ''; ?>">
                <td><code><?php echo h($r['from_path']); ?></code></td>
                <td style="word-break:break-all"><?php echo h($r['to_url']); ?></td>
                <td><?php echo (int)$r['status']; ?></td>
                <td>
                    <button type="button" class="delete-btn" style="min-width:auto;padding:4px 8px"
                        hx-post="actions/redirects-save.php"
                        hx-vals='{"action":"delete","id":"<?php echo (int)$r['id']; ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                        hx-target="#settings-panel"
                        hx-swap="innerHTML"
                        hx-confirm="Delete redirect <?php echo h($r['from_path']); ?>?">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="hint" style="margin-top:1rem">No redirects yet.</p>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-info-circle"></i> Per-page / per-post SEO</h3>
    <p class="card-sub" style="margin-bottom:0">Each editor has Featured image, SERP preview, robots, and schema override. Agents use the <code>seo</code> object or <code>formax_update_seo</code>.</p>
</div>
