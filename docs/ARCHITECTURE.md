# Forma architecture

How the pieces fit. Read this before changing anything under `lib/`, `admin/`, or `api/`. For *what agents should do with a running site* see [`../AGENTS.md`](../AGENTS.md); for shipping see [`RELEASE.md`](RELEASE.md); for verifying a change see [`TESTING.md`](TESTING.md).

## One-paragraph version

Forma is a single-process PHP 8.1+ app over one SQLite file. `index.php` is the only public entry point (Apache rewrites everything to it; `router.php` does the same for `php -S`). Content lives in SQLite and is rendered by `Render` through Twig/Markdown with `[[shortcode]]` expansion and `Seo` head injection. Every save also writes a derived static copy under `fallback/` that Apache serves before PHP is even invoked. The admin is server-rendered htmx partials. Agents reach the same repos through a scoped Bearer/OAuth API and two MCP surfaces.

## Request lifecycle

```
Apache .htaccess (lib/Htaccess.php generates it)
  ├─ /fallback/<path>.html exists?  → served as a static file, PHP never runs
  ├─ /uploads/*                      → static, PHP execution denied by uploads/.htaccess
  └─ everything else                 → index.php
index.php
  ├─ /up                             heartbeat; also runs selfHeal(), publishScheduledIfDue(), housekeeping()
  ├─ /.well-known/oauth-*, /oauth/*  api/oauth/index.php  (AgentOAuth)
  ├─ redirects table                 RedirectRepo::match() → 301/302/307/308
  ├─ /api/v1/*                       api/v1/index.php     (Agent, AgentMcp, AgentOpenApi)
  ├─ /admin*                         admin/index.php      (Auth session)
  ├─ /robots.txt /sitemap.xml /llms.txt /feed.xml /feed.json /feeds/podcast.xml
  ├─ /blog, /blog/{slug}             BlogRepo + Render::renderBlogArchive/Post
  ├─ /podcast, /podcast/{id}         PodcastRepo (license-gated)
  ├─ /search                         Search (FTS5) — always PHP, never static
  └─ /{slug}                         PageRepo::getBySlug → Render::renderPageRow
```

Error pages are regular rows (`_404`, `_403`, `_500`) rendered via `Render::sendError()`; `StaticFallback::publishErrorPages()` also writes them as `fallback/_404.html` etc. and `Htaccess` wires those as `ErrorDocument`s.

## Directory map

| Path | Role | Shipped by updater? |
|------|------|---------------------|
| `index.php`, `router.php`, `config.php`, `version.php` | Entry points + constants | yes (`Updater::rootFiles()`) |
| `lib/` | All application classes (no autoloader — `lib/bootstrap.php` requires each) | yes |
| `lib/Twig/`, `lib/Parsedown.php` | Vendored deps. No Composer. | yes |
| `admin/` | htmx admin shell, partials, actions, CodeMirror editors | yes |
| `api/v1/index.php` | Agent API router + help doc | yes |
| `api/oauth/index.php` | OAuth 2.1 server endpoints | yes |
| `mcp/server.mjs` | Local Cursor MCP client (Node) — wraps the HTTP API | yes (`mcp/`), minus `node_modules/` |
| `templates/` | Seed Twig for blog templates + demo page | yes |
| `tools/` | CLI (`formax.php`), tests, release script — only an allowlist is updated | partial (`Updater::toolFiles()`) |
| `docs/` | This folder. **Never copied to a web root** (it would shadow a `/docs` page). | no |
| `database/` | `forma.db` (+ WAL), `agent-checkpoint.db`, `updates/` backups | **never** |
| `uploads/`, `feeds/`, `fallback/` | Media / generated RSS / derived HTML cache | **never** |
| `.htaccess` | Generated per vhost by `Htaccess::ensureDefault()`; operators may add rules | **never** |

`Updater::neverTouch()` and `Updater::isDenied()` are the authority on the last column.

## Class map (`lib/`)

| Class | Owns |
|-------|------|
| `Database` | PDO singleton, `SCHEMA` DDL, `DEFAULT_SETTINGS`, `initSchema()` (guarded by `PRAGMA user_version` stamp), `migrateColumns()`, seeding, `page_cache`, `housekeeping()` |
| `Auth` | Admin sessions, CSRF token, bcrypt users, `usesDefaultPassword()`, lockout via `login_attempts` |
| `PageRepo` / `BlogRepo` / `SnippetRepo` / `PodcastRepo` / `MediaRepo` / `RedirectRepo` | CRUD per table. Repos are the *only* code that should write content tables; they fan out to `Search`, `StaticFallback`, `Feed`. |
| `Render` | Twig + Parsedown, `[[shortcode]]` expansion (nesting ≤ 4, cycles → HTML comment), site context, blog/podcast/search/error renderers, default templates for stock snippets |
| `Seo` | Per-doc SEO merge (`<!--META-->` / `seo_json` / settings), `applyToHtml()` head injection into the `[[seo]]` slot, JSON-LD (WebSite, Person/Org, BlogPosting, FAQPage from `data-fx-faq`, custom `data-fx-schema`), robots/sitemap/llms generators, sitewide health report, `TEMPLATE_FILENAMES` |
| `Search` | FTS5 `search_index` (or `search_index_plain` LIKE fallback), per-doc index/remove, `reindexAll()` |
| `Feed` | RSS/JSON blog feeds, podcast RSS, `maybeRegenerate*()` throttles |
| `StaticFallback` | Everything under `fallback/`: publish/unpublish per doc, archives, error pages, `publishAll()`, `selfHeal()`, `publishScheduledIfDue()`, `.enabled` / `.published-at` / `.scheduled-at` markers |
| `Htaccess` | Generates/repairs `.htaccess` blocks (front controller, static-fallback rules, redirects block, Authorization passthrough, robots placeholder, `uploads/.htaccess` deny-PHP) |
| `HostingCheck` | Settings → Server diagnostics + `adminAlerts()` (red nag bar; cached in session 5 min) |
| `Agent` | Bearer / `X-Forma-Token` auth, scopes, rate limit, audit log, `isLocalRequest()` (loopback only), JSON helpers |
| `AgentCheckpoint` | The one last-known-good SQLite snapshot before the first agent write; restore/accept; request-wide flock |
| `AgentOAuth` | OAuth 2.1 AS: discovery, DCR, PKCE, codes, rotating refresh tokens, 1h audience-bound access tokens (hashed into `api_tokens`), `prune()` |
| `AgentOpenApi` | OpenAPI 3.0.3 document for ChatGPT Actions |
| `AgentMcp` | Stateless Streamable-HTTP MCP at `/api/v1/mcp`; scope-filtered `tools/list`; Site-editor subset only |
| `License` | Podcast unlock: local HMAC verify (`FORMA_LICENSE_SECRET_FILE` → `lib/LicenseHMACSecret.hex`) or POST to forma-cms.me |
| `SitePackage` | Export/import of the versioned site zip; `replaceContentFromSqlite()` swaps tables transactionally |
| `Importer` | Legacy FormaLITE JSON / DB import |
| `Updater` | GitHub Releases updater: preflight, download, verify `version.php` matches tag, app-file backup, allowlisted copy, OPcache reset, rollback |
| `Urls` | Base-path / absolute URL helpers |

## Data model

Tables created in `Database::SCHEMA` (plus `redirects` in `RedirectRepo::ensureTable()` and the FTS tables in `Search`):

| Table | Notes |
|-------|-------|
| `pages` | `filename` (stable id), `slug`, `content_type` html\|md, `content` (may start with `<!--META … -->`) |
| `blog_posts` | Markdown `body`, `published_at` (NULL = draft; future = scheduled), `seo_json` |
| `podcast_episodes` | License-gated |
| `snippets` | `filename` = shortcode. `SnippetRepo::STOCK` (`search`, `search-ui`, `error-ui`, `faq-ui`) are reseeded if missing and cannot be deleted |
| `settings` | One JSON blob per `section`: `site`, `blog`, `podcast`, `security`, `cache`, `server`, `seo`, `maintenance` (housekeeping stamp) |
| `users`, `login_attempts` | Admin auth |
| `page_cache` | Rendered-HTML cache (the "PHP cache"); cleared by `flushCache()` |
| `api_tokens`, `api_audit` | Manual + OAuth tokens (SHA-256 hashed), per-request audit trail |
| `oauth_clients`, `oauth_authorization_codes`, `oauth_refresh_tokens` | OAuth state |
| `redirects` | `from_path` → `to_url`, status, `enabled` (defaults on when omitted via API) |
| `license` | Podcast key |
| `search_index` / `search_index_plain` | Derived; rebuilt by `Search::reindexAll()` |

**Versioning.** `FORMA_SCHEMA_VERSION` (in `version.php`) bumps only when table shape changes and site packages need migration. `Database::SEED_REVISION` bumps when seeds/defaults gain rows without a shape change. `schemaStamp() = schema*1000 + seed` is written to `PRAGMA user_version`; the constructor runs `initSchema()` only when the stamp differs (fresh DB, upgrade, or restored package). Everything in `initSchema()` must stay idempotent.

**Three kinds of state** — know which one you're touching:

1. *Source of truth*: content tables + `settings` + `uploads/`. Backed up by site packages and the agent checkpoint.
2. *Derived, rebuildable*: `page_cache`, `search_index*`, `feeds/`, `fallback/`, `.htaccess` redirect block. Safe to delete; "Rebuild HTML cache" (`StaticFallback::publishAll()`) + `Search::reindexAll()` regenerate.
3. *Operational*: `users`, `api_tokens`, `api_audit`, `login_attempts`, OAuth tables, `license`, `database/updates/`. Preserved by checkpoint restore and import.

## Write path (what a save triggers)

```
Repo::save()
  ├─ write row
  ├─ Search::indexX()                 keep FTS in sync
  ├─ Database::flushCache()           drop page_cache
  ├─ Render::forgetSiteContext()      per-request memo
  ├─ Feed::maybeRegenerate*()         throttled
  └─ StaticFallback::publishX()       write fallback/<path>.html (+ archive / home refresh)
AgentCheckpoint::beforeMutation()           (Agent API / MCP writes only) snapshot before first write
```

Admin actions (`admin/actions/*.php`) and the Agent API both call the same repos. If you add a side effect, put it in the repo, not in the action or the API handler.

**Scheduled publishing.** Nothing runs in the background. `StaticFallback::publishScheduledIfDue()` is called from `/up` and `/blog`; it compares `.scheduled-at` with `now`, publishes any post/episode whose `published_at` fell in that window (HTML, archive, search, feeds), and advances the stamp. External uptime pings on `/up` are therefore also the scheduler.

**Housekeeping.** `Database::housekeeping()` runs from `/up` at most once a day (stamp in `settings.maintenance`): prunes `api_audit` > 90 days, `login_attempts` > 1 day, expired/revoked OAuth access tokens > 7 days, and calls `AgentOAuth::prune()`.

## Rendering & SEO

- Pages: `Render::renderPageRow()` → Markdown or Twig → `expandShortcodes()` → `Seo::applyToHtml()`.
- The `[[seo]]` token is reserved (not a snippet). Full HTML templates put it on its own line in `<head>`; Forma replaces it with meta/OG/Twitter/canonical/favicon/JSON-LD. Pages that never had the slot still get auto-injection; removing a pinned slot turns head tags off (`seo_head: off` in META, `warnings: ["seo_slot_removed"]` on PUT).
- `applyToHtml()` strips pre-existing head tags attribute-order-agnostically, only inside `<head>`, and strips *any* raw `<script type="application/ld+json">` anywhere (Forma owns that slot — use `data-fx-schema` to contribute).
- `Seo::TEMPLATE_FILENAMES` (`_404 _403 _500 blog-archive blog-single podcast-archive podcast-single search-page search-results`) are pages used as templates: not routable by filename, not indexed, not published as standalone HTML.

## Auth surfaces

| Surface | Mechanism | Scope ceiling |
|---------|-----------|---------------|
| `/admin` | PHP session, bcrypt, CSRF token on every mutating action (incl. logout) | everything |
| Agent API / local MCP | `Authorization: Bearer fx_…` or `X-Forma-Token` (DreamHost strips Authorization unless `.htaccess` passes it) | token scopes; `settings/security` is never exposed |
| Remote MCP / ChatGPT | OAuth 2.1 (DCR + PKCE, consent screen, 1h tokens) | hard-capped to the Site editor set — never delete/settings:write |

Scopes: `content:read|write|delete`, `media:write|delete`, `site:write` (curated identity + SEO), `rollback:write`, `settings:write`, `backup:read`, `podcast:write`.

**Exception taxonomy.** Application code throws only `InvalidArgumentException` (bad input → 400 with message) or `RuntimeException` (operation failed → 400 with message). `PDOException` *extends* `RuntimeException`, so API/MCP catch it first and return a generic logged 500 — never surface SQL. Anything else → generic 500, logged.

## Admin shell

`admin/index.php` renders the shell; nav swaps `admin/partials/*.php` into `#main` via htmx; `admin/actions/*.php` are POST targets returning HTML fragments (often with `hx-swap-oob` for the alert bar / lists). Shared helpers in `admin/partials/_helpers.php` (`fx_admin_alerts_html()`, `fx_list_editor_shell()`). `admin/css/core.css` is organized in labeled sections naming the partials they style — read the banners before adding CSS. JS: `admin.js` (shell, media picker, toasts), `editor-chips.js` (CodeMirror chips for `[[shortcode]]`, `[[seo]]`, META), `editor-toolbar.js` (insert menus; stock list comes from `toolbar-data.php`).

## Update mechanism

`Updater::apply()`: GitHub `releases/latest` → download zipball (size-capped) → extract → require `version.php` in zip == tag → `backupCurrent()` (zip of app files to `database/updates/`, keep 5) → `copyAllowlist()` (atomic per-file copy+rename, deny-list enforced) → `opcache_reset()` → `flushCache()`. On any failure the backup is restored automatically. `rollback()` reverses the newest backup (also resets OPcache). The first request after an update re-runs `initSchema()` because the `user_version` stamp changed.

Hotfixes are never rsynced to customer installs. See [`RELEASE.md`](RELEASE.md).

## Conventions

- No autoloader, no Composer, no build step. Add new classes to `lib/bootstrap.php`.
- Repos own side effects. Actions and API handlers validate + call repos + render.
- Everything derived must be rebuildable from SQLite. If you add a cache, add it to `publishAll()`/`reindexAll()` and to `AgentCheckpoint::restore()`'s rebuild list.
- New admin screens: a partial + an action + a `core.css` section banner. Mutations need `Auth::csrf()` and should return a fragment, not JSON.
- New API endpoints: add to the router *and* the `/help` document in `api/v1/index.php`, and mirror in `AgentMcp` + `mcp/server.mjs` if agents need it.
- Keep `AGENTS.md` (ships to every install) accurate — it's what connected AI tools read first.
