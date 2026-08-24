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

1. Work is actually done. Tests / a local `php -S` sanity check. Merge to `main`.
2. Bump `version.php` (`FORMA_VERSION` + `FORMA_VERSION_DATE`). The in-admin button compares this number to the GitHub tag.
3. From the repo root, on `main`, with a clean tree:

   ```bash
   ./tools/release.sh
   ```

   That tags `vX.Y.Z` (must match `version.php`) and runs `gh release create`. If the script errors, **stop**. Do not push a tag by hand unless you know why the script failed.
4. Confirm https://github.com/Alta-forma/forma-cms/releases/latest shows that tag.
5. On **each** live install: Settings → **Forma core** → Check for updates → **Update Forma core**. Read the confirm dialog. Wait for the toast.

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
