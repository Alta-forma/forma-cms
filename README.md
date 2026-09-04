# Forma

A portable, SQLite-powered CMS for people who are willing to hack a little. Dark admin (htmx), Markdown-first blogging, correct RSS, built-in SEO, and an Agent API for Cursor.

**AltaForma** is the company that builds and hosts sites on Forma. This repo is the CMS.

- Site: [forma-cms.me](https://forma-cms.me)
- Download: [latest GitHub Release](https://github.com/Alta-forma/forma-cms/releases/latest) (not `main`)
- Clone: `git clone https://github.com/Alta-forma/forma-cms.git`
- Podcast unlock ($39): [Buy on Stripe](https://buy.stripe.com/7sY4gA87290N6a17Qk7N608)

The 2025 flat-file experiment still lives at [onechrisjones/forma](https://github.com/onechrisjones/forma). This repo is the current PHP + SQLite product.

Deploy: [docs/DEPLOY.md](docs/DEPLOY.md) · **Ship updates:** [docs/RELEASE.md](docs/RELEASE.md) · Podcast keys: [docs/LICENSING.md](docs/LICENSING.md)

## Principles

- **One SQLite file** is a feature
- Simplicity and readability over feature maximalism
- Server-rendered + progressive enhancement (htmx)
- Markdown-first for longform
- Clean RSS (podcasts are a paid unlock)
- Agent-friendly: scoped HTTPS API, no shell
- SEO-first: `/robots.txt`, `/sitemap.xml`, `/llms.txt`, Open Graph / Twitter / JSON-LD, Settings → SEO
- Nags, doesn't just log: a sticky admin bar calls out a default password or bad hosting until you fix it

## Requirements

- PHP 8.1+ (8.2+ recommended)
- Extensions: `pdo_sqlite`, `json`, `fileinfo`, `session`, `zip` (updates + site packages), `curl` (outbound HTTPS)
- Apache `mod_rewrite` (or Nginx `try_files`) — or PHP built-in server via `router.php`

## Quick start

```bash
git clone https://github.com/Alta-forma/forma-cms.git
cd forma-cms
chmod -R 775 database uploads feeds
php -S localhost:8787 router.php
```

Or download the **latest Release** zip from [GitHub Releases](https://github.com/Alta-forma/forma-cms/releases/latest) — not “Code → Download ZIP” on the repo (that is `main`, which can be unfinished).

Open http://localhost:8787/admin — login `admin` / `admin` (change immediately).

DreamHost / Apache / Nginx: [docs/DEPLOY.md](docs/DEPLOY.md).

## Layout

```
├── index.php           # Public front controller
├── router.php          # PHP built-in server router
├── admin/              # htmx admin (partials + actions)
├── api/v1/             # Agent API (Bearer tokens)
├── lib/                # Database, repos, Render, Feed, Agent…
├── mcp/                # Cursor MCP server (full site control)
├── AGENTS.md           # How agents should use Forma
├── tools/              # remote CLI + import from older installs
├── database/forma.db   # created on first request
├── uploads/
├── feeds/
└── fallback/           # published HTML + php-ok.json (runtime, derived — safe to delete)
```

## Admin

Top nav loads sections via htmx into `#main`. Editors use CodeMirror; save/delete return HTML fragments (no JSON admin SPA).

`[[shortcode]]` tokens and the reserved `[[seo]]` slot render as inline chips instead of raw text, and the leading `<!--META-->` block collapses into its own "Page details" chip — the editor stays readable even when a page is full of snippets. Quick-add (page / post / snippet) opens as an OS-style modal.

## Security

Every admin screen carries a **non-dismissible red bar** until these are fixed:

- Default `admin` / `admin` login still works
- A hosting check is failing (world-writable `database/`/`uploads`/`feeds`/`fallback`, `display_errors` on, leftover `install.php`, missing `.htaccess`, …)

Forma also drops a deny-PHP `.htaccess` into `uploads/` on write, so an uploaded `.php` file can never execute even if someone gets a bad file past the upload filter. Full diagnostics: Settings → Server (`HostingCheck::run()`); `GET /api/v1/health` gives agents a lightweight filesystem check (bad upload paths, nested `lib/lib`-style folders from a bad manual deploy).

## Public routes

| Path | Purpose |
|------|---------|
| `/` | Page by slug (home) |
| `/blog`, `/blog/{slug}` | Blog archive / post |
| `/feed.xml`, `/feed.json` | Dynamic blog feeds |
| `/robots.txt`, `/sitemap.xml`, `/llms.txt` | SEO (generated) |
| `/feeds/podcast.xml` | Podcast RSS |
| `/podcast`, `/podcast/{id}` | Podcast pages (templates in DB) |
| `/admin` | Admin |
| `/api/v1/*` | Agent API (`GET /api/v1/help` for the map) |
| `/search` | Site search (htmx fragment or full page). `[[search]]` snippet renders the box |
| `/up` | PHP heartbeat JSON (no auth). Pair with `/fallback/php-ok.json` |
| `/fallback/index.html` | Published homepage Apache can serve directly, or if PHP/FastCGI dies |

Templates live as pages: `blog-archive`, `blog-single`, `podcast-archive`, `podcast-single`.

## Backup / site packages

Admin → **Settings → Backup** → **Download site package (.zip)**.

The zip is versioned for future restores:

| File | Purpose |
|------|---------|
| `manifest.json` | `format_version` + `schema_version` + counts |
| `data.json` | Portable content (pages, posts, settings, redirects…) |
| `database/forma.db` | SQLite snapshot |
| `uploads/` | All media |

```bash
# Agent / CLI
curl -H "X-Forma-Token: $FORMA_X_TOKEN" -o site.zip "$FORMA_X_URL/api/v1/export/site"
php tools/formax.php export-site ./backup.zip
```

(`FORMA_X_URL` / `FORMA_X_TOKEN` are the env names MCP and the CLI still use.)

Restore from Admin → Backup → Import, or `POST /api/v1/import/site` (multipart `package`).

## Agent API + Cursor

1. Admin → **Settings → Agents** → create token (grant the scopes you need: content, media, settings, backup, podcast)
2. Agents: start with `GET /api/v1/help` or read [`AGENTS.md`](AGENTS.md)
3. CLI:

```bash
export FORMA_X_URL=http://localhost:8787
export FORMA_X_TOKEN=fx_...
php tools/formax.php site
php tools/formax.php posts
php tools/formax.php export-site
```

4. MCP: see [`mcp/README.md`](mcp/README.md) — full CRUD for pages, posts, snippets, media, settings, SEO, redirects, episodes, plus a filesystem `health` check and site export/import

Tokens are stored hashed. HTTPS required for non-local requests when `security.agent_https_only` is true.

## PHP cache vs HTML cache

Settings → **Cache**:

- **PHP cache** (SQLite) skips Twig/Markdown on the next hit. PHP still handles the request.
- **HTML cache** (`cache.static_fallback`) writes every page, post, and podcast episode to real `.html` files under `fallback/`. Apache serves those files directly for anything already built; a path without an HTML file falls through to a live PHP render. Error pages (`fallback/_404.html` etc.) are wired up as Apache `ErrorDocument`s, and redirects are written as real `.htaccess` `RewriteRule`s.
  - SQLite is always the source of truth; `fallback/` is 100% derived. Delete it and click **Rebuild HTML cache** to rebuild from scratch.
  - Heartbeat: `GET /up` (also self-heals the on-disk marker if settings say "on" but nothing's been published yet). Stamp: `/fallback/php-ok.json`.

```bash
php tools/watch-php.php https://alta-forma.com https://client-site.example
# exit 2 = PHP down (static stamp may still be 200)
```

Copy `tools/watch-sites.example.txt` → `tools/watch-sites.txt` and cron / LaunchAgent every 5 minutes.

## Search

`GET /search?q=…` — SQLite FTS5 full-text search (falls back to `LIKE` on hosts without the FTS5 extension) over pages, published posts, and licensed podcast episodes. Snippets are never indexed.

- Drop the `[[search]]` snippet anywhere for a live-as-you-type htmx search box (progressively enhances into a plain GET form if JS/htmx is unavailable).
- Templates are editable pages like everything else: `search-results` (the results fragment, also used for the htmx response) and `search-page` (the full page wrapper).
- Always served by PHP — never published as a static file — and always `noindex`.
- **Rebuild HTML cache** also rebuilds the full search index; per-item saves/deletes keep it in sync automatically.

## SEO

Admin → **Settings → SEO**: health dashboard, favicon/social defaults, schema (Person/Org/LocalBusiness), auto robots/sitemap (with images), redirects. Per-page SEO is optional — titles/descriptions/images fall back automatically.  
Per-page / per-post SEO panels override title, description, OG image, canonical, and robots. Meta tags are injected on every public render. Each page/post editor also shows a contextual **Page health** list (title/description length, missing image, `[[seo]]` status) right where you're editing — not just in the central health dashboard.

**JSON-LD** covers `WebSite`, `Person`/`Organization`/`LocalBusiness`, `BlogPosting`, `BreadcrumbList`, and `PodcastEpisode`/`PodcastSeries` (podcast unlock) automatically. Two more are generated from visible content instead of settings:

- **FAQ** — wrap Q&A pairs in `<div data-fx-faq><details class="fx-faq-item"><summary>Question</summary><div class="fx-faq-a"><p>Answer</p></div></details>…</div>` (toolbar → Snippets → "FAQ block" inserts a starter) plus the `[[faq-ui]]` snippet once for the accordion styling. `FAQPage` JSON-LD is built from that markup — one copy of the text, nothing to keep in sync.
- **Custom JSON-LD** — for anything else (Event, Recipe, Course, …), drop `<script type="application/ld+json" data-fx-schema>{"@type":"Event",…}</script>` in the content. Validated on save (bad JSON or a missing `@type` comes back as a warning and is dropped, not published) and merged into the single generated `<script>` in `<head>`.

Redirects (301/302/307/308) live under Settings → SEO too, or `GET/PUT /api/v1/redirects` + `DELETE /api/v1/redirects/{id}` for agents. `GET /api/v1/pages` and `/api/v1/posts` also attach `seo_ok` + `seo_issues[]` per row, so an agent can scan for what needs SEO work without fetching every document.

## Licenses

Forma itself is MIT. Podcast hosting is a $39 one-time unlock — [buy on Stripe](https://buy.stripe.com/7sY4gA87290N6a17Qk7N608), then paste the key under Settings → General. Local/dev: `FX-DEV-LOCAL`. Details: [docs/LICENSING.md](docs/LICENSING.md).

The HMAC secret that mints keys is **not** in this repo (`lib/LicenseHMACSecret.hex` is gitignored).

## Import from an older install

```bash
# JSON export from Settings → Backup, or raw DB:
php tools/import-formalite.php /path/to/export.json
php tools/import-formalite.php /path/to/forma.db
```

Also available in Admin → Settings → Import.

## Updating an existing install

**Settings → Forma core.** That updates the CMS only (GitHub Releases). Pages, posts, and uploads stay put. A core backup is saved first; **Undo last update** is on the same pane.

AltaForma ships to the world with `./tools/release.sh` after bumping `version.php`. Do not rsync `main` onto live sites. Details: [docs/RELEASE.md](docs/RELEASE.md).

## License

[MIT](LICENSE)
