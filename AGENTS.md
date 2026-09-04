# Forma — notes for coding agents

You are talking to a **Forma** install: a portable PHP + SQLite CMS. Prefer the **Agent API** / MCP tools over SSH/FTP when a token is available.

## First call

```
GET /api/v1/help
Authorization: Bearer fx_…
```

(or MCP tool `formax_help`)

That returns scopes, every endpoint, SEO field names, and how the product is structured.

## Mental model

| Piece | Where | Notes |
|-------|--------|--------|
| Pages | `pages` table | HTML/Twig or Markdown. Optional `<!--META … -->` for slug + SEO. Full HTML templates should contain `[[seo]]` on its own line in `<head>`. |
| Posts | `blog_posts` | Markdown. Public only if `published_at <= now`. Head tags come from `blog-single`’s `[[seo]]` slot plus this post’s `seo_json`. |
| Snippets | `snippets` | Insert with `[[shortcode]]` in page/post HTML. `[[seo]]` is reserved (not a snippet). Nesting max 4; cycles become an HTML comment. |
| Uploads | `/uploads` | Media files; use media API |
| Settings | `settings` JSON sections | `site`, `seo`, `blog`, `podcast`, `cache`, … |
| SEO | Settings → SEO + per doc + `[[seo]]` | Auto meta/favicon/schema. Pages that never had `[[seo]]` still auto-inject. After the slot is pinned, deleting it turns head tags off (`warnings: ["seo_slot_removed"]` on PUT). |
| Uptime | `GET /up` + `fallback/` | Heartbeat JSON incl. `fallback` status. If `/up` dies but `/fallback/php-ok.json` is 200, PHP/FastCGI is down. |
| HTML cache | Settings → Cache (`cache.static_fallback`) | Every save writes real `.html` under `fallback/`; Apache serves those files first (see `fallback/.enabled`). Paths without a built file fall through to PHP — SQLite is always the source of truth, `fallback/` is a derived cache you can delete and rebuild with "Rebuild HTML cache". |
| Search | `GET /search?q=…` + `[[search]]` snippet | SQLite FTS5 (LIKE fallback) over pages/posts/episodes. Always PHP, never published as a file. htmx fragment via `HX-Request: true`. To write the shortcode as text, use a code fence / `<code>` or `[[!search]]`. Descriptions, titles, and meta never expand shortcodes. |
| FAQ | Visible HTML — no separate data | `FAQPage` JSON-LD is generated automatically from `<div data-fx-faq>` blocks of `<details class="fx-faq-item"><summary>Q</summary><div class="fx-faq-a"><p>A</p></div></details>`. Include `[[faq-ui]]` once for the accordion styling (native `<details>`, no JS). One copy of the text — the visible markup is the schema source. Toolbar → Snippets → "FAQ block" inserts a starter. |
| Custom JSON-LD | `<script type="application/ld+json" data-fx-schema>` in content | Escape hatch for schema.org types Forma has no UI for (Event, Recipe, Course…). Validated on save (bad JSON / missing `@type` → warning, dropped, not published) and merged into the one generated `<script>` in `<head>`; the raw tag is stripped from the body. Accepts one object, an array, or `{"@graph":[…]}`. |
| Redirects | `redirects` table | 301/302/307/308. `GET/PUT /api/v1/redirects`, `DELETE /api/v1/redirects/{id}`. Can't target `/admin` or `/api`. |
| SEO health | `GET /api/v1/seo` → `health` | Sitewide report (dupes, missing favicon/description, schema fields, `[[seo]]` slot status per template). `GET /api/v1/pages` and `/api/v1/posts` also attach `seo_ok` + `seo_issues[]` per row for a cheap "what needs work" scan without fetching every doc. |
| Hosting / security nags | `HostingCheck::adminAlerts()`, admin-only | A red bar on every admin screen for a still-default `admin`/`admin` password or a failing hosting check (world-writable `database`/`uploads`/`feeds`/`fallback`, `display_errors` on, leftover `install.php`, missing `.htaccess`). No API surface for the full report — that's Settings → Server only. `GET /api/v1/health` is the lighter, agent-facing filesystem check (bad upload paths, nested `lib/lib`/`admin/admin` from a bad manual deploy). Fix perms with `chmod 755` dirs / `640` the db file — never `chmod -R 777`. |

## Auth

- Header: `Authorization: Bearer fx_…`
- DreamHost-safe alt: `X-Forma-Token: fx_…`
- Scopes: `content:read|write`, `media:write`, `settings:write`, `backup:read`, `podcast:write`

## SEO fields (pages & posts)

`seo_title`, `seo_description`, `og_title`, `og_description`, `og_image` / `featured_image`, `canonical`, `robots`, `twitter_card`, `schema_type`

- Pages: stored in META block (or pass `seo:{}` on PUT `/pages/{file}`)
- Posts: stored in `seo_json` (or pass `seo:{}` on PUT `/posts/{file}`)
- Sitewide: PUT `/api/v1/seo` or Settings → SEO

## MCP tools

`formax_help`, `formax_site`, pages/posts/snippets CRUD, media list/upload/delete, settings get/update, SEO get/update, redirects list/save/delete, episodes, cache flush, export/import site, health.

(Tool names still start with `formax_` so existing Cursor configs keep working.)

## Site packages (backup / migrate)

- `GET /api/v1/export/site` → zip (`backup:read`): `manifest.json` + `data.json` + `database/forma.db` + `uploads/`
- `GET /api/v1/export` → versioned JSON only (no binaries)
- `POST /api/v1/import/site` multipart `package` = zip (`settings:write`)
- Manifest fields: `format`=`formax-site`, `format_version`, `schema_version`, `app_version`
- Future app versions migrate using `schema_version`; reject packages newer than the running app

## Updating the CMS

Installs pull **GitHub Releases** only (`Alta-forma/forma-cms`). Admin → **Settings → Forma core**. Never rsync `main` onto a live vhost. Never copy `database/`, `uploads/`, or a customized `.htaccess` when updating code.

AltaForma: bump `version.php`, merge to `main`, `./tools/release.sh`. Full checklist: [`docs/RELEASE.md`](docs/RELEASE.md).

## Don’t

- Don’t rsync `main` or overwrite `database/` / `uploads/` / live `.htaccess` to “update Forma”. Use Settings → Forma core after a GitHub Release.
- Don’t rsync hotfixes to every vhost. **forma-cms.me only.** Other installs (Eden, Friends, alta-forma.com, …) click Settings → Forma core.
- Don’t scrape `/admin` HTML when the API works.
- Don’t delete `home`, `_404`, `_403`, `_500`.
- Don’t remove `[[seo]]` from a template unless you mean to stop emitting `<head>` SEO on that template.
- Don’t commit tokens.
- Don’t assume unpublished posts are public — they 404 by design.
- Don’t edit files under `fallback/` directly — they’re regenerated from SQLite on every save, or all at once via "Rebuild HTML cache" / `StaticFallback::publishAll()`.
- Don’t index or publish `snippets` — they’re building blocks, not content.
- Don’t hand-write a bare `<script type="application/ld+json">` and expect it to survive — Forma strips *any* raw ld+json tag on render (it owns that slot). Add `data-fx-schema` to the tag to have it validated and merged into the generated block instead.
- Don’t write FAQ JSON-LD without the matching visible `<details class="fx-faq-item">` markup — Google requires them to match, and Forma reads the visible markup, not a JSON blob, so there’s nothing to keep in sync by hand.
