<?php
/**
 * Live content pass: search chrome, covers, how-to posts.
 * Run on the server: php tools/deploy-search-and-posts.php
 */
define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/lib/bootstrap.php';

$cover = static function (string $name): string {
    return '/uploads/cover-' . $name . '.png';
};

$header = SnippetRepo::get('site-header');
$headerContent = $header['content'];
if (!str_contains($headerContent, 'href="/search"')) {
    $headerContent = str_replace(
        '<li><a href="/blog">Blog</a></li>',
        '<li><a href="/blog">Blog</a></li>' . "\n      <li><a href=\"/search\">Search</a></li>",
        $headerContent
    );
    SnippetRepo::save('site-header', $header['shortcode'], $headerContent);
}

$footer = SnippetRepo::get('site-footer');
$footerContent = $footer['content'];
if (!str_contains($footerContent, 'href="/search"')) {
    $footerContent = str_replace(
        '<a href="/blog">Blog</a>',
        '<a href="/blog">Blog</a>' . "\n      <a href=\"/search\">Search</a>",
        $footerContent
    );
    SnippetRepo::save('site-footer', $footer['shortcode'], $footerContent);
}

SnippetRepo::save('search-ui', 'search-ui', Render::defaultSearchUiSnippet());
SnippetRepo::save('search', 'search', <<<'HTML'
[[search-ui]]
<form class="fx-search-box" role="search" action="/search" method="get"
      hx-get="/search" hx-target="#fx-search-results" hx-push-url="true"
      hx-trigger="submit, keyup changed delay:280ms from:input[name='q']">
  <label class="fx-search-label" for="fx-search-q">Search</label>
  <div class="fx-search-row">
    <input id="fx-search-q" type="search" name="q" value="{{ query|default('') }}" placeholder="Search pages, posts, episodes…" autocomplete="off" aria-label="Search" enterkeyhint="search">
    <button type="submit">Search</button>
  </div>
</form>
<div id="fx-search-results">{{ results_html|default('')|raw }}</div>
HTML);

PageRepo::save('search-page', Render::defaultSearchPageTemplate(), 'html', null, [
    'title' => 'Search',
    'seo_title' => 'Search Forma',
    'seo_description' => 'Search pages, posts, and episodes on Forma.',
    'robots' => 'noindex,follow',
]);
PageRepo::save('search-results', Render::defaultSearchResultsTemplate(), 'html', null, [
    'title' => 'Search results',
    'robots' => 'noindex,follow',
]);

$imageUpdates = [
    'html-cache-static-speed' => $cover('html-cache'),
    'search-one-shortcode' => $cover('search'),
    'snippets-twig-design-system' => $cover('snippets'),
    'seo-launch-checklist' => $cover('seo'),
];
foreach ($imageUpdates as $filename => $img) {
    $row = BlogRepo::get($filename);
    if (!$row) {
        continue;
    }
    $seo = json_decode($row['seo_json'] ?? '{}', true) ?: [];
    $seo['og_image'] = $img;
    $seo['featured_image'] = $img;
    BlogRepo::save(['filename' => $filename, 'seo' => $seo]);
}

$now = time();
$posts = [
[
    'filename' => 'when-php-died-fastcgi',
    'slug' => 'when-php-died-fastcgi-went-silent',
    'title' => 'When PHP died: the afternoon FastCGI went silent',
    'description' => 'DreamHost returned “No input file specified.” PHP was gone. Here is what broke, what we built so it cannot take a Forma site down again, and how to watch for it.',
    'author' => 'Forma',
    'categories' => ['Forma', 'Hosting', 'Story'],
    'tags' => ['fastcgi', 'dreamhost', 'html-cache', 'uptime', 'php'],
    'published_at' => $now - 90,
    'seo' => [
        'seo_title' => 'When PHP died: Forma vs FastCGI silence',
        'seo_description' => 'A real FastCGI outage, the DreamHost error it produced, and how Forma’s HTML cache and /up heartbeat keep a site readable when PHP disappears.',
        'og_title' => 'When PHP died',
        'og_description' => 'The afternoon FastCGI went silent — and why Forma now writes real HTML files.',
        'og_image' => $cover('fastcgi'),
        'featured_image' => $cover('fastcgi'),
        'robots' => 'index,follow',
        'twitter_card' => 'summary_large_image',
        'schema_type' => 'BlogPosting',
    ],
    'body' => <<<'MD'
DreamHost has a special way of telling you PHP is gone. The page is not a Forma 500. It is not your branded error template. It is a blank white screen and five words:

**No input file specified.**

That is FastCGI failing to hand Apache a script filename. Every `.php` URL on the vhost is unreachable — admin, Agent API, `/up`, the homepage, everything that still has to execute PHP. Changing the PHP version in the panel does not always fix it. Killing PHP processes does not always fix it. Sometimes you wait on a ticket while the public site is a ghost.

We watched it happen on forma-cms.me. That is why Forma now treats PHP as optional for *reading* a site.

## What actually broke

Forma is PHP + SQLite. The front controller is `index.php`. Apache asks FastCGI for that file. If the mapping is wrong — a re-provisioned vhost, a stale wrapper, a panel change that did not stick — FastCGI answers with nothing. Apache has no document to serve.

A SQLite page cache does not help here. That cache still needs PHP to look up the row and print the HTML. The site is “cached” and still dead.

The only thing Apache can serve without PHP is a **file that already exists**.

## The rule we shipped

**Files for real URLs. PHP for ad-hoc questions.**

When HTML cache is on, a save writes a real `.html` file under `fallback/`. Apache is told to prefer that file. The homepage becomes `fallback/index.html`. A blog post becomes a file. The 404 page becomes a file.

These stay dynamic, on purpose:

- `/admin`
- `/api`
- `/search?q=…`
- feeds, `robots.txt`, `sitemap.xml`
- `/up`

Search is a question. Admin is a question. A product page that only changes when an editor hits Save is a document.

If FastCGI dies again, the documents stay up. The questions wait.

## How you know PHP is sick

Hit `/up`. If PHP is alive you get JSON: product, version, timestamp, HTML-cache status. If `/up` fails but `fallback/php-ok.json` is still there, you know when PHP last completed a request. That is the difference between “the server is down” and “PHP-FPM had a bad afternoon.”

Hosting Check in admin also reports the heartbeat and whether the HTML cache is actually written.

## What to do on a client site

1. Turn on **Settings → Cache → HTML cache**.
2. Click **Rebuild HTML cache**.
3. Confirm `/`, a page, and a post still load.
4. Bookmark `/up` for the monitor.

You still need backups. You still file the host ticket. But the client’s homepage should not be a FastCGI error message while you do it.

That afternoon was ugly. The site does not have to be the next time.
MD
],
[
    'filename' => 'first-forma-page',
    'slug' => 'your-first-forma-page',
    'title' => 'Your first Forma page',
    'description' => 'Create a page, set the slug and SEO, drop in snippets, and ship HTML — the whole Forma page workflow without a theme framework.',
    'author' => 'Forma',
    'categories' => ['Forma', 'How-to'],
    'tags' => ['pages', 'snippets', 'seo', 'meta', 'getting-started'],
    'published_at' => $now - 60,
    'seo' => [
        'seo_title' => 'Your first Forma page: slugs, META, snippets',
        'seo_description' => 'A practical walkthrough for creating a Forma page: filename, slug, META SEO fields, snippets, and how HTML cache publishes the file.',
        'og_title' => 'Your first Forma page',
        'og_description' => 'META, slugs, and shipping HTML — the page workflow without a theme framework.',
        'og_image' => $cover('pages'),
        'featured_image' => $cover('pages'),
        'robots' => 'index,follow',
        'twitter_card' => 'summary_large_image',
        'schema_type' => 'BlogPosting',
    ],
    'body' => <<<'MD'
A Forma page is an HTML (or Markdown) document in SQLite. It is not a theme file, a builder JSON blob, or a WordPress “template + content” split. You write the page. Forma stores it. Apache can serve the published file.

## Create the page

In **Admin → Pages**, add a filename such as `about`. That is the internal id. The public URL comes from the slug.

Open the META block at the top — or the SEO fields in the editor — and set at least:

- `title` — what humans see
- `slug` — `/about`
- `seo_title` — the `<title>` tag, usually shorter and more specific
- `seo_description` — one sentence that earns the click
- `canonical` if the automatic URL is not the one you want

Do not leave the homepage slug as a preview domain after launch. Forma uses the site URL to build canonicals, the sitemap, and social tags.

## Write the document

You can ship a full HTML document with its own `<head>`, or a fragment that Forma wraps. This site’s homepage is a full document. Most interior pages are too, because they include the shared chrome:

```text
[[site-head]]
[[site-header]]
…
[[site-footer]]
```

Those are snippets. Edit them once; every page that includes them updates together. If HTML cache is on, saving a snippet republishes the derived files.

Need a search box on the page? Drop in the built-in snippet:

```text
[[search]]
```

Code fences keep that text literal. In prose, write `[[!search]]` when you want the characters `[[search]]` on the page instead of the live box.

## Twig, only when it pays

If the page needs a loop — recent posts, a filtered list — Twig is there. Keep it boring:

```twig
{% for post in posts|slice(0, 3) %}
  <a href="/blog/{{ post.slug }}">{{ post.title }}</a>
{% endfor %}
```

HTML for structure. Snippets for reuse. Twig for data. That is the whole design system.

## Publish

Save. If HTML cache is enabled, Forma writes `fallback/about.html` (or whatever the slug maps to) and Apache can serve it without waking PHP. Flush or rebuild if you have just changed sitewide SEO or chrome.

Then open the public URL, view source, and check the title, description, and canonical. Fifteen seconds. That is the page workflow.
MD
],
[
    'filename' => 'publish-a-blog-post',
    'slug' => 'publish-a-forma-blog-post',
    'title' => 'Publish a Forma blog post in ten minutes',
    'description' => 'Markdown body, a real description, a featured image, categories, and the SEO fields that actually show up in search and shares.',
    'author' => 'Forma',
    'categories' => ['Forma', 'How-to', 'Writing'],
    'tags' => ['blog', 'markdown', 'featured-image', 'seo', 'rss'],
    'published_at' => $now - 30,
    'seo' => [
        'seo_title' => 'Publish a Forma blog post in ten minutes',
        'seo_description' => 'Write Markdown, set a description and featured image, fill the SEO fields, and publish. Forma handles the archive, feeds, search index, and HTML cache.',
        'og_title' => 'Write a post. Ship it.',
        'og_description' => 'Markdown in. A live article, RSS item, and search document out.',
        'og_image' => $cover('blogging'),
        'featured_image' => $cover('blogging'),
        'robots' => 'index,follow',
        'twitter_card' => 'summary_large_image',
        'schema_type' => 'BlogPosting',
    ],
    'body' => <<<'MD'
Forma’s blog is Markdown-first because longform should be portable. You write in the admin (or via the Agent API). Forma renders HTML, lists the post on `/blog`, adds it to RSS and JSON Feed, indexes it for search, and — if HTML cache is on — writes a static file.

## The ten-minute pass

1. **Admin → Blog → New.** Filename is the internal id (`publish-a-blog-post`). Slug is the URL (`publish-a-forma-blog-post`).
2. Write a title a human would click.
3. Write a **description** even if the first paragraph is good. Archives, feeds, search, and social cards use it.
4. Add categories and a few tags. The archive chips and search haystack both read them.
5. Paste Markdown. Headings, lists, fenced code. Keep shortcodes in fences when you are *talking about* them.
6. Set a featured image — 1200×630, dark enough that the title overlay still reads. This becomes the cover and the Open Graph image if you do not override it.
7. Fill SEO title and SEO description if the editorial title is too long or too cute for a search result.
8. Set **published** to now. Drafts stay out of the public site, the sitemap, and search.

Save. Visit `/blog/your-slug`. Check `/feed.xml`. Search for a distinctive word from the post.

## Images that do not fight the title

The single-post template is a full-bleed cover with a dark gradient. A busy stock photo under white type is how you lose the headline. Prefer:

- a dark field;
- one strong graphic (the brand mark, a texture, a single object);
- room in the lower third.

If you skip the image, Forma falls back to a gradient. That is fine. A generic stock handshake is worse than no image.

## From Cursor, not just the admin

A scoped token can `PUT /api/v1/posts/{filename}` with `title`, `body`, `description`, `categories`, `tags`, `published_at`, and `seo`. Same rules as the admin. The Agent API is not a second CMS — it is the same save path with a leash.

That is the whole publishing loop: write, describe, image, publish, verify. Ten minutes if you already know what you want to say.
MD
],
[
    'filename' => 'cursor-agent-api-howto',
    'slug' => 'let-cursor-edit-forma-with-a-token',
    'title' => 'Let Cursor edit Forma — with a token, not a shell',
    'description' => 'Create a scoped Agent API token, point Cursor at /api/v1/help, and let an agent edit pages, posts, and SEO without SSH or FTP.',
    'author' => 'Forma',
    'categories' => ['Forma', 'AI', 'How-to'],
    'tags' => ['cursor', 'agent-api', 'tokens', 'mcp', 'security'],
    'published_at' => $now,
    'seo' => [
        'seo_title' => 'Let Cursor edit Forma with a scoped token',
        'seo_description' => 'How to create a Forma Agent API token, call /api/v1/help, and let Cursor edit content without giving it SSH or the database file.',
        'og_title' => 'A scoped token is a leash',
        'og_description' => 'Cursor can edit Forma over HTTPS. Scopes decide what it is allowed to touch.',
        'og_image' => $cover('agents'),
        'featured_image' => $cover('agents'),
        'robots' => 'index,follow',
        'twitter_card' => 'summary_large_image',
        'schema_type' => 'BlogPosting',
    ],
    'body' => <<<'MD'
SSH is a privilege. FTP is a habit. An agent that needs either one can also read `.env`, rewrite `.htaccess`, and delete the database. Forma’s Agent API exists so Cursor can do CMS work over HTTPS with a leash.

## Create the token

In **Admin → Settings → API / Agents**, create a token with only the scopes the job needs:

- `content:read` / `content:write` — pages, posts, snippets
- `media:write` — uploads
- `settings:write` — site and SEO
- `backup:read` — site packages
- `podcast:write` — if the site is licensed

Give a writing agent `content:write` and maybe `media:write`. Do not hand it `settings:write` because it is drafting a blog post.

Treat the token like a password. HTTPS only. Revoke it when the job is done.

## First call

```http
GET /api/v1/help
Authorization: Bearer fx_…
```

That response is the map: every endpoint, the SEO field names, the scopes, and how pages differ from posts. Read it before improvising. Agents that skip `/help` invent field names and then wonder why META did not update.

DreamHost-safe alternative header: `X-Forma-Token: fx_…` if `Authorization` gets stripped.

## What a good session looks like

1. `GET /api/v1/site` — confirm you are on the right install.
2. `GET /api/v1/posts` or `/pages` — see what exists.
3. `GET` the one document you will change.
4. `PUT` with the full fields you mean to set. For posts, omit `body` if you are only changing SEO or the featured image.
5. Hit the public URL. Search for a distinctive phrase. Check `/sitemap.xml` if it is a new published post.

The MCP server (`formax_*` tools) is the same API with friendlier names. If MCP is down, curl still works. The token is the contract, not the transport.

## What the token cannot do

It cannot SSH. It cannot run `push.sh`. It cannot read some other site’s database. Unpublished posts stay unpublished. `/search` and `/admin` stay off the token’s write surface.

That is the point of the leash. An agent that can ship a blog post should not also be able to take the server apart.
MD
],
];

$saved = [];
foreach ($posts as $post) {
    $row = BlogRepo::save($post);
    $saved[] = ['filename' => $row['filename'], 'slug' => $row['slug']];
}

$publish = StaticFallback::publishAll();
$health = Seo::healthReport();

echo json_encode([
    'posts' => $saved,
    'publish' => $publish,
    'seo_health' => $health,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
