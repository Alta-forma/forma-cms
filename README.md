# Forma

A portable, SQLite-powered CMS for people who are willing to hack a little. Dark admin (htmx), Markdown-first blogging, correct RSS, built-in SEO, and an Agent API for Cursor.

**AltaForma** is the company that builds and hosts sites on Forma. This repo is the CMS.

## Principles

- **One SQLite file** is a feature
- Simplicity and readability over feature maximalism
- Server-rendered + progressive enhancement (htmx)
- Markdown-first for longform
- Clean RSS (podcasts are a paid unlock)
- Agent-friendly: scoped HTTPS API, no shell
- SEO-first: `/robots.txt`, `/sitemap.xml`, Open Graph / Twitter / JSON-LD, Settings → SEO

## Requirements

- PHP 8.1+ (8.2+ recommended)
- Extensions: `pdo_sqlite`, `json`, `fileinfo`, `session`, `curl` (CLI remote only)
- Apache `mod_rewrite` (or Nginx `try_files`) — or PHP built-in server via `router.php`

## Quick start

```bash
chmod -R 775 database uploads feeds
php -S localhost:8787 router.php
```

Open http://localhost:8787/admin — login `admin` / `admin` (change immediately).

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

## Public routes

| Path | Purpose |
|------|---------|
| `/` | Page by slug (home) |
| `/blog`, `/blog/{slug}` | Blog archive / post |
| `/feed.xml`, `/feed.json` | Dynamic blog feeds |
| `/robots.txt`, `/sitemap.xml` | SEO (generated) |
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

4. MCP: see [`mcp/README.md`](mcp/README.md) — full CRUD for pages, posts, snippets, media, settings, SEO, episodes

Tokens are stored hashed. HTTPS required for non-local requests when `security.agent_https_only` is true.

## Cache vs Publish mode vs PHP outages

Settings → **Cache & Publish**:

- **Page cache** (SQLite) skips Twig/Markdown on the next hit. **PHP still runs.** It does not help when DreamHost FastCGI returns “No input file specified.”
- **Publish mode** (`cache.static_fallback`) writes every page, post, and podcast episode to real `.html` files under `fallback/`. Apache serves those files directly — before PHP runs at all — for anything already published; a path that hasn't been published yet just falls through to a live PHP render, so turning this on never blanks a site mid-migration. It also means the site survives PHP dying outright. Error pages (`fallback/_404.html` etc.) are wired up as Apache `ErrorDocument`s, and redirects are written as real `.htaccess` `RewriteRule`s — both keep working with PHP fully dead.
  - SQLite is always the source of truth; `fallback/` is 100% derived. Delete it and click **Publish now** to rebuild from scratch.
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
- "Publish now" also rebuilds the full search index; per-item saves/deletes keep it in sync automatically.

## SEO

Admin → **Settings → SEO**: health dashboard, favicon/social defaults, schema (Person/Org/LocalBusiness), auto robots/sitemap (with images), redirects. Per-page SEO is optional — titles/descriptions/images fall back automatically.  
Per-page / per-post SEO panels override title, description, OG image, canonical, and robots. Meta tags are injected on every public render.

## Licenses

Settings → License.

- **Podcast** — paid unlock (`FX-PERP-…`, `FX-SUB-…`, or local `FX-DEV-LOCAL`)
- **Forms** — email relay (included with an AltaForma site; self-serve buys a key or BYO Formspree/Resend)

## Import from an older install

```bash
# JSON export from Settings → Backup, or raw DB:
php tools/import-formalite.php /path/to/export.json
php tools/import-formalite.php /path/to/forma.db
```

Also available in Admin → Settings → Import.

## License

MIT
