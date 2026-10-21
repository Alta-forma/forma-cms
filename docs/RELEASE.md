# Releasing Forma to the world

This is the only supported way to ship CMS code. **Do not rsync `main` onto customer sites.** `main` can be half-done. Installs only pull **GitHub Releases**.

Repo: https://github.com/Alta-forma/forma-cms  
Button: every Forma admin → **Settings → Update**

## Never overwrite on a live site

| Keep | Why |
|------|-----|
| `database/` | The site. SQLite + updater backups |
| `uploads/` | Media |
| `feeds/`, `fallback/` | Generated |
| `.htaccess` | Per-vhost rules (preview bypass, etc.) |
| `.env`, `config.local.php`, `lib/LicenseHMACSecret.hex` | Secrets |
| `tools/watch-sites.txt`, `mcp/node_modules/` | Local |

The Update button already refuses to touch those. Manual rsync must too.

## Ship a release

1. Work is actually done. Lint, a disposable `php -S` install, and the **upgrade simulation** in [`TESTING.md`](TESTING.md) §4 — seed an install from the last tag, apply your tree with the *old* `Updater`, confirm content/tokens/sessions survive and rollback works. For agent-protocol releases also run `php tools/test-agent-safety.php` and `php tools/test-oauth.php`, then verify OAuth metadata + PKCE connection, `/api/v1/openapi.json`, MCP `initialize` + `tools/list`, a live test edit, and `formax_put_it_back`. Merge to `main`.
2. Bump `version.php` (`FORMA_VERSION` + `FORMA_VERSION_DATE`) and the `version` string in `mcp/server.mjs`. The in-admin button compares this number to the GitHub tag. Add a section to `CHANGELOG.md`. Bump `FORMA_SCHEMA_VERSION` only if table shape changed; bump `Database::SEED_REVISION` if seeds/defaults gained rows.
3. From the repo root, on `main`, with a clean tree:

   ```bash
   ./tools/release.sh
   ```

   That tags `vX.Y.Z` (must match `version.php`) and runs `gh release create`. If the script errors, **stop**. Do not push a tag by hand unless you know why the script failed.
4. Confirm https://github.com/Alta-forma/forma-cms/releases/latest shows that tag, and that the zipball's `version.php` matches (`Updater::apply()` refuses a mismatch). `--generate-notes` only produces a compare link — replace it with real notes: `gh release edit vX.Y.Z --notes-file -` pasting the CHANGELOG section.
5. On **each** live install: Settings → **Forma core** → Check for updates → **Update Forma core**. Read the confirm dialog. Wait for the toast. forma-cms.me is also fine to update from SSH with `php -r 'define("ROOT_DIR", getcwd()); require "lib/bootstrap.php"; print_r(Updater::apply());'` from its web root — same code path as the button. Run any follow-up (`StaticFallback::publishAll()`, checks) as a **separate** `php -r` — the process that ran `apply()` still has the old classes loaded.
6. Update forma-cms.me content if the release changes anything a visitor sees (install commands, feature list, docs page, a release post, `softwareVersion` in the home page's JSON-LD). Agent API writes with HTML bodies get a 418 from DreamHost's WAF there — use `PageRepo::save()` over SSH (see `DEPLOY.md`).

First-time sites that are still on 0.1.x do not have this pane. Bootstrap them **once** with the 0.2.0+ app files (same allowlist as the updater), then use the button forever after.

## If Update fails

- Settings → Update shows the last run log.
- **Rollback last app backup** restores the previous PHP tree. Content is untouched.
- Settings → Backup still exists for a full site package (DB + uploads). Take one before a scary release.

## Do not

- Tag from a dirty or feature branch
- Point the updater at `main.zip`
- Copy `database/` or `uploads/` when “just updating code”
- Click **Write .htaccess** after an update unless you intend to replace that vhost’s rules
- Copy a `docs/` (or `blog/`) folder into a site web root — it will shadow the CMS page and Apache 403s the URL

## Versioning

Semver in `version.php`:

- **patch** (0.2.1) — bugfix, safe for every install
- **minor** (0.3.0) — features, still safe if they click Update
- **major** (1.0.0) — breaking; say so in the release notes and bump `FORMA_SCHEMA_VERSION` only when SQLite shape changes

## What survives an update (and what doesn't)

- Code under `admin/ lib/ api/ mcp/ templates/`, root files, and the `tools/` allowlist are replaced. Extra files an operator added inside those dirs are **kept** (copy, not sync) — e.g. forma-cms.me's `api/license/validate.php`.
- `lib/LicenseHMACSecret.hex`, `.htaccess`, `.env`, `config.local.php` are never written.
- Seeded content (stock snippets, templates) is only inserted when missing. An install that edited `search-ui` keeps its edit; it does not receive the new default.
- The first request after update re-runs `Database::initSchema()` once (stamp mismatch) — new tables/columns appear then, not during the copy.
