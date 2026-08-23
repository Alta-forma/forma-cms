<?php
/**
 * One-shot AltaForma.com content + chrome pass. Run on the vhost.
 */
define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/lib/bootstrap.php';

$db = Database::get();
$log = static function (string $m): void {
    echo $m, "\n";
};

$head = <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Chakra+Petch:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" href="/uploads/wordmark-w.png">
<style>
:root{
  --gold:#fcbe34;--gold-soft:rgba(252,190,52,.14);--stroke-gold:rgba(252,190,52,.38);
  --bg:#0a0a0b;--text:#f5f5f7;--muted:rgba(245,245,247,.62);--stroke:rgba(255,255,255,.1);
  --font-brand:"Chakra Petch",Inter,system-ui,sans-serif;--nav-h:3.6rem;
}
html,body{background:var(--bg);color:var(--text)}
body.forma-chrome{margin:0;font-family:Inter,system-ui,sans-serif;overflow-x:hidden;min-height:100vh}
a{color:var(--gold);text-decoration:none}
.af-nav{position:sticky;top:0;z-index:40;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0 1.25rem;border-bottom:1px solid var(--stroke);background:rgba(10,10,11,.92);backdrop-filter:blur(12px)}
.af-nav a.mark{display:flex;align-items:center;gap:.65rem;color:#fff;font-weight:700;letter-spacing:.02em}
.af-nav a.mark img{height:28px;width:auto}
.af-nav .links{display:flex;flex-wrap:wrap;gap:.35rem .9rem;align-items:center}
.af-nav .links a{color:var(--muted);font-size:.88rem;font-weight:600}
.af-nav .links a:hover,.af-nav .links a.is-on{color:var(--gold)}
.af-foot{margin-top:3rem;padding:1.75rem 1.25rem 2.5rem;border-top:1px solid var(--stroke);color:var(--muted);font-size:.88rem}
.af-foot .inner{width:min(1100px,calc(100% - 0px));margin:0 auto;display:flex;flex-wrap:wrap;gap:1rem 2rem;justify-content:space-between}
.af-foot a{color:var(--muted)}
.af-foot a:hover{color:var(--gold)}
</style>
HTML;

$header = <<<'HTML'
<header class="af-nav">
  <a class="mark" href="/"><img src="/uploads/wordmark-w.png" alt="AltaForma"> AltaForma</a>
  <nav class="links" aria-label="Primary">
    <a href="/">Home</a>
    <a href="/guides">Guides</a>
    <a href="/blog">Blog</a>
    <a href="/rates">Rates</a>
    <a href="/search">Search</a>
    <a href="/#contact">Contact</a>
  </nav>
</header>
HTML;

$footer = <<<'HTML'
<footer class="af-foot">
  <div class="inner">
    <div>
      <strong style="color:#fff">AltaForma, LLC</strong><br>
      722 E. Comanche · Norman, OK 73071<br>
      <a href="mailto:hello@alta-forma.com">hello@alta-forma.com</a> · (405) 651-4343
    </div>
    <div>
      <a href="/guides">Guides</a> · <a href="/blog">Blog</a> · <a href="/rates">Rates</a> · <a href="/terms">Terms</a>
    </div>
  </div>
</footer>
HTML;

SnippetRepo::save('site-head', 'site-head', $head);
SnippetRepo::save('site-header', 'site-header', $header);
SnippetRepo::save('site-footer', 'site-footer', $footer);
$log('snippets chrome');

$archive = file_get_contents(ROOT_DIR . '/templates/blog-archive.twig');
$archive = str_replace('Notes on portable CMS craft.', 'How we put businesses online.', $archive);
$archive = str_replace(
    'SQLite, agents, SEO without plugin theater, and the small decisions that keep a site shippable.',
    'Previews before you pay, Forma, site care, and the work we actually ship from Norman.',
    $archive
);
$archive = str_replace('Forma Blog', 'AltaForma Blog', $archive);
$single = file_get_contents(ROOT_DIR . '/templates/blog-single.twig');
PageRepo::save('blog-archive', $archive, 'html');
PageRepo::save('blog-single', $single, 'html');
PageRepo::save('search-page', Render::defaultSearchPageTemplate(), 'html');
$db->execute("UPDATE pages SET slug = NULL WHERE filename IN ('blog-archive','blog-single','search-page','search-results','podcast-archive','podcast-single')");
$log('templates');

$guidesDir = ROOT_DIR . '/PAGES/guides';
if (!is_dir($guidesDir)) {
    $guidesDir = getenv('HOME') . '/alta-forma.com/PAGES/guides';
}
$n = 0;
foreach (glob($guidesDir . '/*.html') ?: [] as $file) {
    $html = (string)file_get_contents($file);
    $base = basename($file, '.html');
    $filename = $base === 'index' ? 'guides' : 'guides-' . $base;
    $meta = PageRepo::extractMeta($html);
    $slug = $meta['slug'] ?? ($base === 'index' ? 'guides' : 'guides/' . $base);
    PageRepo::save($filename, $html, 'html', $slug);
    $n++;
}
$log("guides $n");

BlogRepo::save([
    'filename' => 'welcome',
    'published_at' => null,
    'date' => '',
]);
$log('welcome unpublished');

$posts = [
    [
        'filename' => 'websites-before-you-pay',
        'title' => 'We build the site before you pay',
        'description' => 'AltaForma ships a private preview first. You see the page, then you pay to go live — $499 setup plus site care.',
        'categories' => ['Starter sites'],
        'tags' => ['preview', 'how-it-works'],
        'body' => <<<'MD'
Most agencies quote a mystery number, disappear for six weeks, and unveil a site you have not seen. We do the opposite.

We build a real landing page on a private URL. You get a postcard or a QR code. You walk the page on your phone. If it is right, you pay the setup fee and we point your domain at it.

That is the whole pitch: **see it, then pay**.

Setup is $499. Site care is $49/month (or a discounted year). Hosting, SSL, and small copy/image fixes ride along. New pages and redesigns are quoted. The live numbers live on [Rates](/rates).

If you already have a preview link, you are not shopping a mockup. You are looking at the page that can go live.
MD,
    ],
    [
        'filename' => 'what-site-care-covers',
        'title' => 'What $49/month site care actually covers',
        'description' => 'Hosting and SSL, yes. Unlimited redesigns, no. A plain-language list of what is included — and what we quote.',
        'categories' => ['Site care'],
        'tags' => ['rates', 'hosting'],
        'body' => <<<'MD'
Site care is not a blank check for project hours. It is the boring, necessary work that keeps a one-page business site honest.

**Usually included:** hours, phone, address, tagline; swapping a photo you send us; fixing a dead link; small layout tweaks on the page you already have; hosting and SSL on our stack.

**Quoted:** new pages, new sections, new forms, integrations after go-live, ads, “make us rank #1,” and anything that is really a redesign.

If a request is billable, we say so before we do it. Hourly work is $125. The full table is on [Rates](/rates). Terms are on [Terms](/terms).

The point is a live site that does not rot — not a retainer that pretends to be an in-house web team.
MD,
    ],
    [
        'filename' => 'forma-not-wordpress',
        'title' => 'Forma, not WordPress',
        'description' => 'AltaForma sites run on Forma: one SQLite file, a dark admin, SEO that is built in. No plugin carnival.',
        'categories' => ['Forma'],
        'tags' => ['cms', 'hosting'],
        'body' => <<<'MD'
WordPress is fine if you want a CMS that is also an attack surface, a plugin bazaar, and a part-time job. We did not want that for client sites — or for this one.

**Forma** is the CMS we built: PHP, one SQLite file, Markdown when you want it, HTML when you do not. Admin is dark and fast. Search is real (SQLite FTS). `/robots.txt` and `/sitemap.xml` come from settings, not a SEO plugin fighting two other SEO plugins.

The CMS is free. Podcast RSS and a hosted forms relay are paid unlocks. AltaForma customers get forms with the site.

This domain runs on Forma. So does [The Eden Clinic](https://theedenclinic.com/). If your preview goes live, it will too.
MD,
    ],
    [
        'filename' => 'eden-clinic-live',
        'title' => 'Live on Forma: The Eden Clinic',
        'description' => 'A Norman women’s clinic site on Forma — pages, blog, SEO, Google Business Profile wired in. Proof the stack is not a demo.',
        'categories' => ['Client sites'],
        'tags' => ['eden', 'norman'],
        'body' => <<<'MD'
[The Eden Clinic](https://theedenclinic.com/) in Norman is a live Forma site, not a slide.

They needed a calm public site: services that are free, a real address, phone, Google reviews, and a blog people can actually find. Forma handles the pages, the posts, the sitemap, and LocalBusiness schema. HTML cache keeps the public pages on Apache if PHP ever has a bad night.

That is the same stack we put under a $499 starter site. The difference is content and care, not a second CMS.

If you want the same shape for your shop — preview first, then your domain — [get in touch](/#contact).
MD,
    ],
    [
        'filename' => 'free-guides-for-local-shops',
        'title' => 'Free guides for shops that just got a URL',
        'description' => 'Google Business Profile, reviews, photos, launch checklists — written for owners, not agencies. Now on alta-forma.com/guides.',
        'categories' => ['Guides'],
        'tags' => ['seo', 'gbp'],
        'body' => <<<'MD'
A starter site does not magically fill the phone. Google still wants a Business Profile, real photos, and reviews you did not write yourself.

We wrote the short versions:

- [Google Business Profile](/guides/google-business-profile)
- [How to ask for reviews](/guides/request-reviews)
- [Local SEO without the cult](/guides/local-seo-basics)
- [Launch checklist](/guides/launch-checklist)
- [AI phone agent](/guides/ai-phone-agent)

The full list is at [/guides](/guides). Hand them to a partner. Use them on a preview. They are free on purpose.
MD,
    ],
];

$now = time();
foreach ($posts as $i => $p) {
    $p['author'] = 'AltaForma';
    $p['published_at'] = $now - (($i + 1) * 3600);
    $p['seo'] = [
        'seo_title' => $p['title'] . ' | AltaForma',
        'seo_description' => $p['description'],
        'og_image' => '/uploads/forma-social.png',
        'featured_image' => '/uploads/forma-social.png',
    ];
    BlogRepo::save($p);
    $log('post ' . $p['filename']);
}

$home = PageRepo::get('home');
$html = $home['content'];
$html = str_replace('/uploads/Wordmark W.png', '/uploads/wordmark-w.png', $html);
$html = str_replace('/uploads/Wordmark%20W.png', '/uploads/wordmark-w.png', $html);
$html = str_replace('uploads/Wordmark W.png', 'uploads/wordmark-w.png', $html);

$edenCard = <<<'HTML'

                    <!-- The Eden Clinic -->
                    <div class="card portfolio-card featured">
                        <div class="card-bg" style="background-image: url('uploads/eden-mark.png')"></div>
                        <div class="card-overlay">
                            <div class="card-content">
                                <span class="card-category">LIVE CLIENT</span>
                                <h2>The Eden Clinic</h2>
                                <p>Norman women’s clinic — live on Forma. Pages, blog, Google listing, and a site that stays up without WordPress.</p>
                                <div class="portfolio-features">
                                    <div class="feature"><span class="material-icons">favorite</span> Free services</div>
                                    <div class="feature"><span class="material-icons">place</span> Norman, OK</div>
                                </div>
                                <div class="card-cta dual">
                                    <a class="card-button primary" href="https://theedenclinic.com/" target="_blank" rel="noopener">Visit site</a>
                                    <a class="card-button secondary" href="/blog/eden-clinic-live">How we built it</a>
                                </div>
                            </div>
                        </div>
                    </div>
HTML;

if (!str_contains($html, 'The Eden Clinic')) {
    $html = str_replace(
        "                    <!-- Preview sites for local businesses -->",
        $edenCard . "\n                    <!-- Preview sites for local businesses -->",
        $html
    );
}

$guidesCard = <<<'HTML'

                    <!-- Free guides -->
                    <div class="card portfolio-card">
                        <div class="card-content">
                            <span class="card-category">FREE GUIDES</span>
                            <h2>GBP, reviews, launch</h2>
                            <p>Short owner guides — Google Business Profile, asking for reviews, photos, site care. No funnel. Just the steps.</p>
                            <div class="card-cta dual">
                                <a class="card-button primary" href="/guides">Open guides</a>
                                <a class="card-button secondary" href="/blog">Blog</a>
                            </div>
                        </div>
                    </div>
HTML;

if (!str_contains($html, 'Open guides')) {
    $html = str_replace(
        "                    <!-- Forma CMS (PHP + SQLite) -->",
        $guidesCard . "\n                    <!-- Forma CMS (PHP + SQLite) -->",
        $html
    );
}

$menuExtras = <<<'HTML'
                <a class="menu-item" href="/guides" style="text-decoration:none;color:inherit">
                    <div class="menu-icon"><span class="material-icons">menu_book</span></div>
                    <div class="menu-text"><h3>Guides</h3></div>
                </a>
                <a class="menu-item" href="/blog" style="text-decoration:none;color:inherit">
                    <div class="menu-icon"><span class="material-icons">rss_feed</span></div>
                    <div class="menu-text"><h3>Blog</h3></div>
                </a>
                <a class="menu-item" href="/search" style="text-decoration:none;color:inherit">
                    <div class="menu-icon"><span class="material-icons">search</span></div>
                    <div class="menu-text"><h3>Search</h3></div>
                </a>
                <a class="menu-item" href="/rates" style="text-decoration:none;color:inherit">
                    <div class="menu-icon"><span class="material-icons">payments</span></div>
                    <div class="menu-text"><h3>Rates</h3></div>
                </a>
HTML;

if (!str_contains($html, 'href="/guides"')) {
    $html = str_replace(
        "                <div class=\"menu-item\" data-section=\"contact\">",
        $menuExtras . "\n                <div class=\"menu-item\" data-section=\"contact\">",
        $html
    );
}

PageRepo::save('home', $html, 'html', '/');
$log('home updated');

$seo = $db->getSetting('seo');
$seo['favicon'] = '/uploads/wordmark-w.png';
$seo['apple_touch_icon'] = '/uploads/wordmark-w.png';
$seo['default_og_image'] = '/uploads/forma-social.png';
$seo['schema_type'] = 'organization';
$seo['organization_name'] = 'AltaForma, LLC';
$seo['organization_logo'] = '/uploads/wordmark-w.png';
$seo['schema_email'] = 'hello@alta-forma.com';
$seo['schema_phone'] = '(405) 651-4343';
$seo['schema_address'] = '722 E. Comanche';
$seo['schema_city'] = 'Norman';
$seo['schema_region'] = 'OK';
$seo['schema_postal'] = '73071';
$seo['schema_country'] = 'US';
$seo['robots_auto'] = true;
$seo['sitemap_auto'] = true;
$seo['sitemap_enabled'] = true;
$seo['robots_manual'] = '';
$seo['json_ld_website'] = true;
$db->saveSetting('seo', $seo);

$site = $db->getSetting('site');
$site['title'] = 'AltaForma';
$site['url'] = 'https://alta-forma.com';
$site['description'] = 'See your business online before you pay. Starter websites, AI phone agents, and custom apps from AltaForma in Norman, OK.';
$db->saveSetting('site', $site);
$log('seo + site');

$robots = Seo::robotsTxt();
file_put_contents(ROOT_DIR . '/robots.txt', $robots);
$log('wrote robots.txt (' . strlen($robots) . ' bytes)');

$db->flushCache();
if (class_exists('Search')) {
    $idx = Search::reindexAll();
    $log('search ' . json_encode($idx));
}
if (class_exists('StaticFallback')) {
    $r = StaticFallback::publishAll();
    $log('fallback ' . json_encode($r));
}

$log('done');
