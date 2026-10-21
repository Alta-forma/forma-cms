# Changelog

Installs update from [GitHub Releases](https://github.com/Alta-forma/forma-cms/releases) via **Settings → Forma core**. Every entry here is safe to apply to an existing site unless it says otherwise. Add a section at the top with each `version.php` bump (see `docs/RELEASE.md`).

## 0.6.1 — 2026-10-20

### Fixed
- **Rebuild HTML cache** now removes template pages that releases before 0.6.0 published as ordinary files (`fallback/blog-single.html`, `blog-archive.html`, `search-page.html`, …). Apache served those 200 copies ahead of PHP, so on upgraded sites `/blog-single` still answered until this. Run Settings → Cache → Rebuild HTML cache once after updating; error pages (`_404` …) are unaffected.

### Docs
- `docs/ARCHITECTURE.md`, `docs/TESTING.md`, `CHANGELOG.md` added; `README.md`, `AGENTS.md`, `DEPLOY.md`, `RELEASE.md`, `mcp/README.md` refreshed for 0.6 behaviour (scheduled publishing, housekeeping, redirect defaults, template pages, MCP `content` requirement, DreamHost mod_security 418 on HTML bodies).

## 0.6.0 — 2026-10-20

Codebase audit release. No schema change; first request after update re-runs `initSchema()` once and stamps `PRAGMA user_version`.

### Fixed
- `/podcast/` and nested podcast paths route correctly.
- Internal template pages (`blog-single`, `search-page`, `_404`, …) are no longer reachable as pages; error pages send their real HTTP status.
- Search excerpts read the right FTS column; template pages are never indexed.
- SEO-only `PUT /api/v1/pages/{file}` no longer blanks content or resets `content_type` (REST and local MCP).
- Redirects: alt-path bind in `match()`, self-loop destinations rejected (relative or absolute to own host), API/MCP-created redirects default to `enabled`.
- `Seo::applyToHtml()` strips existing head tags regardless of attribute order and only inside `<head>`; sitemap lists `/blog` only when posts exist.
- `<!--META-->` values sanitized (no `-->` / newline injection); quick-add uses `PageRepo::withMeta()`.
- Admin: username collision on account save and failed deletes return 400 fragments instead of white screens.
- Feeds: `xmlns:dc` + `<dc:creator>`, UTF-8-safe excerpts.
- Removed duplicate `StaticFallback::refreshSavedPage()` call in `pages-save`.

### Security
- `settings/security` unreachable through the Agent API and the admin `settings-save` allowlist.
- API/MCP exception chain: `PDOException` → logged generic 500; app exceptions → 400 with message; everything else → generic 500.
- Logout requires a CSRF token. `Agent::isLocalRequest()` is loopback-only.
- License secret path via `FORMA_LICENSE_SECRET_FILE` env var instead of a hardcoded home directory.
- Updater aborts oversized downloads mid-stream; `rollback()` resets OPcache like `apply()`.
- Site import takes a `VACUUM INTO` backup, keeps 3 `.bak` files, and swaps tables in one transaction (same strategy as `AgentCheckpoint`).

### Added
- **Scheduled publishing** — posts/episodes with a future `published_at` go live on the next `/up` heartbeat or `/blog` hit (`StaticFallback::publishScheduledIfDue()`).
- `Database::housekeeping()` — daily from `/up`: prunes `api_audit` > 90 d, stale `login_attempts`, expired/revoked OAuth access tokens.
- Schema-stamp guard: `initSchema()` runs only when `PRAGMA user_version` differs.
- Admin nag bar cached per session for 5 min (bypassed by account save / hosting fix).
- `Seo::TEMPLATE_FILENAMES` and `SnippetRepo::STOCK` are single sources of truth; stock snippets (now incl. `faq-ui`) cannot be deleted.
- `search-ui` default snippet loads htmx 2.0.4 (existing installs keep their stored copy).
- Docs: `docs/ARCHITECTURE.md`, `docs/TESTING.md`, this changelog; `DEPLOY.md` permissions aligned (755 / 640).

## 0.5.2 — 2026-09-16
- Keep a `robots.txt` placeholder on disk so hosts (DreamHost) cannot inject a default; Apache still rewrites to PHP.

## 0.5.1 — 2026-09-06
- Forma is its own OAuth 2.1 authorization server for `/api/v1/mcp`: dynamic client registration, PKCE S256, one-time codes, rotating refresh tokens with reuse detection, 1 h audience-bound access tokens (schema v3: `expires_at`/`audience`/`oauth_client_id` on `api_tokens`).
- Grants are capped to the non-destructive Site editor scope set; consent refused while the password is still `admin`.
- Settings → Access rebuilt around Admin login / AI chatbots / API keys, with connected-tool revoke.
- `tools/test-oauth.php`.

## 0.5.0 — 2026-09-06 (merged with 0.5.1)
- `AgentCheckpoint`: one automatic last-known-good SQLite point before the first agent write; **Put it back** / **This looks good**; `rollback:write` + `/checkpoint` endpoints.
- `content:delete` / `media:delete` split from write (one-time migration adds them to legacy write tokens).
- `AgentOpenApi` (`/api/v1/openapi.json|.yaml`) and `AgentMcp` (stateless Streamable HTTP MCP, Site-editor subset).

## 0.4.3 — 2026-09-04
- Admin `core.css` / JS / DOM audit: dead selectors removed, 20 labelled CSS sections, `--ok/--warn/--fail` vars, `fx_list_editor_shell()` helper. No visual change.

## 0.4.2 — 2026-09-04
- `/search` honours the `search-page` page's own META (title, description, OG image, robots).

## 0.4.1 — 2026-09-04
- Agent API base64 media upload left files `0600` (public 403 on hosts where PHP and static serving differ). Now `chmod 0644`. Files uploaded that way before 0.4.1 need a re-upload or manual chmod.

## 0.4.0 — 2026-09-04
- Sticky admin nag bar (default password, world-writable paths, hosting fails).
- Reserved `[[seo]]` slot with editor chips, OS-style quick-add modals, per-page SEO health panel.
- Auto `FAQPage` JSON-LD from visible `<details class="fx-faq-item">`; `data-fx-schema` custom JSON-LD; `PodcastEpisode`/`PodcastSeries` schema.
- `/api/v1/redirects`, `/api/v1/health`, MCP parity.

## 0.2.0 – 0.2.5 — 2026-08-22 → 24
- In-admin updater from GitHub Releases (Settings → Forma core), release script, `docs/` excluded from installs, uploads preview + editor scroll fixes, `HANDOFF.md` kept local.

## 0.1.x — August 2026
- Initial PHP + SQLite baseline: pages, Markdown blog, snippets, podcast unlock, Agent API, static fallback for PHP-death resilience.
