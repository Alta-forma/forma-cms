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
| Pages | `pages` table | HTML/Twig or Markdown. Optional `<!--META … -->` for slug + SEO |
| Posts | `blog_posts` | Markdown. Public only if `published_at <= now` |
| Snippets | `snippets` | Insert with `[[shortcode]]` in page/post HTML |
| Uploads | `/uploads` | Media files; use media API |
| Settings | `settings` JSON sections | `site`, `seo`, `blog`, `podcast`, `cache`, … |
| SEO | Settings → SEO + per doc | Auto meta/favicon/schema; health dashboard; image sitemap; redirects; `/robots.txt` + `/sitemap.xml` |
| Uptime | `GET /up` + `fallback/` | Heartbeat JSON incl. `fallback` status. If `/up` dies but `/fallback/php-ok.json` is 200, PHP/FastCGI is down. |
| Publish mode | Settings → Cache & Publish (`cache.static_fallback`) | Every save writes real `.html` under `fallback/`; Apache serves those files first (see `fallback/.enabled`). Unpublished paths fall through to PHP — SQLite is always the source of truth, `fallback/` is a derived cache you can delete and rebuild with "Publish now". |
| Search | `GET /search?q=…` + `[[search]]` snippet | SQLite FTS5 (LIKE fallback) over pages/posts/episodes. Always PHP, never published as a file. htmx fragment via `HX-Request: true`. |

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

`formax_help`, `formax_site`, pages/posts/snippets CRUD, media list/upload/delete, settings get/update, SEO get/update, episodes, cache flush, export.

(Tool names still start with `formax_` so existing Cursor configs keep working.)

## Site packages (backup / migrate)

- `GET /api/v1/export/site` → zip (`backup:read`): `manifest.json` + `data.json` + `database/forma.db` + `uploads/`
- `GET /api/v1/export` → versioned JSON only (no binaries)
- `POST /api/v1/import/site` multipart `package` = zip (`settings:write`)
- Manifest fields: `format`=`formax-site`, `format_version`, `schema_version`, `app_version`
- Future app versions migrate using `schema_version`; reject packages newer than the running app

## Don’t

- Don’t scrape `/admin` HTML when the API works.
- Don’t delete `home`, `_404`, `_403`, `_500`.
- Don’t commit tokens.
- Don’t assume unpublished posts are public — they 404 by design.
- Don’t edit files under `fallback/` directly — they’re regenerated from SQLite on every save, or all at once via "Publish now" / `StaticFallback::publishAll()`.
- Don’t index or publish `snippets` — they’re building blocks, not content.
