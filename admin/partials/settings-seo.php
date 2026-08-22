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
$richTest = 'https://search.google.com/test/rich-results?url=' . rawurlencode($base . '/');
$gscSitemap = $base . '/sitemap.xml';
$score = (int)$health['score'];
$scoreClass = $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'off');
$healthGlow = $score >= 80 ? 'card-glow-pass' : (((int)$health['counts']['fail'] > 0) ? 'card-glow-fail' : 'card-glow-warn');
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('search', 'SEO', 'Paperwork for Google. Most of it is automatic — paste the rest once.'); ?>

<div class="settings-card seo-health-card <?php echo h($healthGlow); ?>">
    <h3><i class="fas fa-heartbeat"></i> Health</h3>
    <p class="card-sub">What’s done, what still needs a paste, and titles that are too long.</p>
    <div class="seo-health-summary">
        <div class="seo-score status-badge <?php echo h($scoreClass); ?>"><?php echo $score; ?> / 100</div>
        <span class="status-badge off"><?php echo (int)$health['counts']['fail']; ?> fail</span>
        <span class="status-badge warn"><?php echo (int)$health['counts']['warn']; ?> warn</span>
        <span class="status-badge ok"><?php echo (int)$health['counts']['info']; ?> info</span>
    </div>
    <?php if (empty($health['issues'])): ?>
        <p class="hint" style="margin:0.75rem 0 0">Looking good — no issues found.</p>
    <?php else: ?>
        <details class="seo-health-details">
            <summary><?php echo count($health['issues']); ?> items — click to review</summary>
            <div class="seo-issue-list">
                <?php foreach ($health['issues'] as $issue): ?>
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
        </details>
    <?php endif; ?>
</div>

<form hx-post="actions/settings-save.php" hx-target="#settings-panel" hx-swap="innerHTML" id="seo-settings-form" class="seo-settings-form">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="seo">

    <div class="settings-card seo-google-card">
        <h3><i class="fab fa-google"></i> Google</h3>
        <p class="card-sub">Copy from Google, paste here. Full tags and snippets are fine — we keep the ID.</p>
        <?php echo fx_seo_paste('gbp_url', (string)($s['gbp_url'] ?? ''), 'Maps listing', 'Google Maps → your business → Share → copy link.', 'https://maps.app.goo.gl/…'); ?>
        <?php echo fx_seo_paste('review_url', (string)($s['review_url'] ?? ''), 'Reviews link', 'On the listing: reviews / Share. A share.google or writereview URL.', 'https://share.google/…'); ?>
        <?php echo fx_seo_paste('google_site_verification', (string)($s['google_site_verification'] ?? ''), 'Search Console', 'search.google.com/search-console → Settings → Ownership verification → HTML tag. Paste the whole tag or just the content value.', 'content="…" or the token'); ?>
        <?php echo fx_seo_paste('google_analytics', (string)($s['google_analytics'] ?? ''), 'Analytics / Tag Manager', 'A G-XXXXXXXX ID or GTM-XXXXXXX. Pasting the install snippet works.', 'G-… or GTM-…'); ?>
        <?php echo fx_seo_paste('bing_site_verification', (string)($s['bing_site_verification'] ?? ''), 'Bing (optional)', 'Bing Webmaster → add site → HTML meta tag.', ''); ?>
        <details class="seo-advanced">
            <summary>Optional Place ID / embed</summary>
            <div style="margin-top:.75rem">
                <?php echo fx_seo_paste('place_id', (string)($s['place_id'] ?? ''), 'Place ID', 'Skip unless you already have a ChIJ… id. The Maps link is enough.', 'ChIJ…'); ?>
                <?php echo fx_seo_paste('maps_embed_url', (string)($s['maps_embed_url'] ?? ''), 'Maps embed URL', 'Usually leave blank.', ''); ?>
            </div>
        </details>
    </div>

    <div class="settings-card seo-schema-card">
        <h3><i class="fas fa-store"></i> This business</h3>
        <p class="card-sub">Who you are — name, phone, address. Google reads this as structured data.</p>
        <div class="form-group">
            <label>Type</label>
            <select name="schema_type">
                <?php foreach ([
                    'person' => 'Person (freelancer / portfolio)',
                    'organization' => 'Organization (company / brand)',
                    'local_business' => 'Local business (storefront / office)',
                    'none' => 'None (WebSite only)',
                ] as $val => $label): ?>
                <option value="<?php echo h($val); ?>" <?php echo $schemaType === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row" style="margin-top:1rem">
            <div class="form-group">
                <label>Display name</label>
                <input type="text" name="organization_name" value="<?php echo h($s['organization_name'] ?? ''); ?>" placeholder="Defaults to site title">
            </div>
            <div class="form-group seo-inline-media">
                <?php echo fx_media_field('organization_logo', $s['organization_logo'] ?? '', [
                    'label' => 'Logo / headshot',
                    'placeholder' => '/uploads/logo.png',
                ]); ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="schema_email" value="<?php echo h($s['schema_email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="schema_phone" value="<?php echo h($s['schema_phone'] ?? ''); ?>" placeholder="(405) 555-0100">
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
        <div class="form-group">
            <label>Hours</label>
            <textarea name="schema_hours" rows="2" placeholder="Mo-Th 10:00-17:00"><?php echo h($s['schema_hours'] ?? ''); ?></textarea>
            <span class="hint">One line per range, e.g. Mo-Th 10:00-17:00 — used in LocalBusiness JSON-LD.</span>
        </div>
        <div class="form-group">
            <label>Price range</label>
            <input type="text" name="schema_price_range" value="<?php echo h($s['schema_price_range'] ?? ''); ?>" placeholder="Free or $ or $$">
            <span class="hint">Google Rich Results asks for this on LocalBusiness. Use Free if you don’t charge.</span>
        </div>
        <div class="form-group">
            <label>Other profiles</label>
            <textarea name="same_as" rows="3" placeholder="https://www.facebook.com/…&#10;https://www.instagram.com/…"><?php echo h($s['same_as'] ?? ''); ?></textarea>
            <span class="hint">One URL per line — Facebook, Instagram, LinkedIn. Maps and reviews links are added automatically.</span>
        </div>
    </div>

    <div class="settings-card seo-branding-card">
        <h3><i class="fas fa-image"></i> How links look</h3>
        <p class="card-sub">Favicon, share image, and the Google / social preview.</p>
        <div class="seo-media-grid">
            <div class="seo-media-tile">
                <?php echo fx_media_field('favicon', $s['favicon'] ?? '', [
                    'label' => 'Favicon',
                    'placeholder' => '/uploads/favicon.ico',
                    'hint' => 'Path under /uploads — .ico, .png, or .svg',
                    'attrs' => 'data-seo-field="favicon"',
                ]); ?>
            </div>
            <div class="seo-media-tile">
                <?php echo fx_media_field('apple_touch_icon', $s['apple_touch_icon'] ?? '', [
                    'label' => 'Apple touch icon',
                    'placeholder' => '/uploads/apple-touch-icon.png',
                    'hint' => 'Square PNG, ideally 180×180',
                ]); ?>
            </div>
            <div class="seo-media-tile seo-media-tile-wide">
                <?php echo fx_media_field('default_og_image', $s['default_og_image'] ?? '', [
                    'label' => 'Default social image',
                    'placeholder' => '/uploads/og-default.jpg',
                    'hint' => '1200×630 — used when content has no featured image',
                    'attrs' => 'data-seo-field="image"',
                ]); ?>
            </div>
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

    <div class="settings-card seo-auto-card card-glow-pass">
        <h3><i class="fas fa-magic"></i> Forma already does this</h3>
        <p class="card-sub">Canonicals, Open Graph, JSON-LD, robots, sitemap, noindex on /admin. You don’t re-enter these per page unless you want to override.</p>
        <ul class="seo-done-list">
            <li>A unique canonical URL on every public page and post</li>
            <li>Open Graph + Twitter cards from titles, descriptions, and images</li>
            <li>JSON-LD for WebSite plus Person / Organization / LocalBusiness</li>
            <li>robots.txt and sitemap.xml (pages, published posts, images)</li>
            <li>/admin and /api stay out of the index</li>
        </ul>
        <div class="seo-auto-tools">
            <div>
                <div class="seo-preview-label">Live robots.txt</div>
                <?php echo fx_url_pill($base . '/robots.txt'); ?>
            </div>
            <div>
                <div class="seo-preview-label">Submit this sitemap in Search Console</div>
                <?php echo fx_url_pill($gscSitemap); ?>
            </div>
        </div>
        <div class="card-actions seo-rich-actions">
            <a class="standard-btn" href="<?php echo h($richTest); ?>" target="_blank" rel="noopener">
                <i class="small fas fa-flask"></i> Test homepage in Google Rich Results
            </a>
        </div>
        <details class="seo-advanced">
            <summary>Advanced — robots, sitemap, titles</summary>
            <div style="margin-top:1rem">
                <?php echo fx_switch('json_ld_website', !empty($s['json_ld_website']), 'Emit WebSite JSON-LD', 'Recommended on'); ?>
                <?php echo fx_switch('robots_auto', $robotsAuto, 'Auto-generate robots.txt', 'Turn off to edit the full file manually below'); ?>
                <?php echo fx_switch('robots_index', !empty($s['robots_index']), 'Allow search engines to index', 'Uncheck for staging'); ?>
                <?php echo fx_switch('robots_follow', !empty($s['robots_follow']), 'Allow following links', ''); ?>
                <div class="form-group" style="margin-top:1rem">
                    <label>Disallow paths</label>
                    <input type="text" name="noindex_paths" value="<?php echo h($s['noindex_paths'] ?? '/admin,/api,/old'); ?>">
                </div>
                <div class="form-group">
                    <label>Extra robots.txt lines</label>
                    <textarea name="robots_extra" rows="2"><?php echo h($s['robots_extra'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Manual robots.txt</label>
                    <textarea name="robots_manual" rows="5" class="mono" spellcheck="false"><?php echo h($s['robots_manual'] ?? ''); ?></textarea>
                    <span class="hint">Used when Auto is off. Empty + Save seeds from auto output.</span>
                </div>
                <?php echo fx_switch('sitemap_enabled', !empty($s['sitemap_enabled']), 'Enable sitemap.xml', ''); ?>
                <?php echo fx_switch('sitemap_auto', $sitemapAuto, 'Auto-generate sitemap.xml', ''); ?>
                <?php echo fx_switch('sitemap_include_pages', !empty($s['sitemap_include_pages']), 'Include pages', ''); ?>
                <?php echo fx_switch('sitemap_include_posts', !empty($s['sitemap_include_posts']), 'Include published blog posts + /blog', ''); ?>
                <?php echo fx_switch('sitemap_include_podcast', !empty($s['sitemap_include_podcast']), 'Include podcast', 'When licensed'); ?>
                <?php echo fx_switch('sitemap_include_images', !array_key_exists('sitemap_include_images', $s) || !empty($s['sitemap_include_images']), 'Include featured images', 'One image per URL from featured / OG image'); ?>
                <div class="form-group" style="margin-top:1rem">
                    <label>Manual sitemap.xml</label>
                    <textarea name="sitemap_manual" rows="5" class="mono" spellcheck="false"><?php echo h($s['sitemap_manual'] ?? ''); ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Title separator</label>
                        <input type="text" name="title_separator" value="<?php echo h($s['title_separator'] ?? ' — '); ?>">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.35rem">
                        <?php echo fx_switch('title_suffix', !empty($s['title_suffix']), 'Append site title to page titles', ''); ?>
                    </div>
                </div>
            </div>
        </details>
    </div>
</form>

<div class="settings-card" id="seo-redirects">
    <h3><i class="fas fa-directions"></i> Redirects</h3>
    <p class="card-sub">301/302 rules — also written as real Apache <code>RewriteRule</code>s in <code>.htaccess</code>, so they still work if PHP/FastCGI dies.</p>
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
    <h3><i class="fas fa-file-alt"></i> Per page / post</h3>
    <p class="card-sub" style="margin-bottom:0">Each editor still has SEO title, description, featured image, and robots. Site defaults fill in the gaps.</p>
</div>
<?php
fx_settings_scroll_close();
echo fx_settings_footer('seo-settings-form', 'Save SEO settings');

