# Testing Forma

There is no test framework and no CI. Verification is a handful of scripts plus a disposable local install. Do all of this **outside** the live tree — `database/`, `uploads/`, and `fallback/` in a real install are data, not fixtures.

## 1. Lint

```bash
for f in $(git ls-files '*.php'); do php -l "$f" | grep -v 'No syntax errors'; done
node --check mcp/server.mjs
```

PHP 8.1 is the floor. If you only have 8.5 locally, remember `setAccessible()` and dynamic properties behave differently — don't rely on 8.5-only syntax.

## 2. Disposable install

```bash
rm -rf /tmp/forma-smoke && mkdir /tmp/forma-smoke
rsync -a --exclude .git --exclude database --exclude uploads --exclude fallback \
      --exclude feeds --exclude node_modules --exclude .htaccess ./ /tmp/forma-smoke/
cd /tmp/forma-smoke && mkdir database uploads feeds fallback
php -S 127.0.0.1:8787 router.php
```

First request seeds the DB (`admin` / `admin`). Minimum route checklist:

| Check | Expect |
|-------|--------|
| `GET /` `/blog` `/podcast` `/podcast/` | 200 |
| `GET /blog-single` `/_404` `/search-results` | 404 (templates are not pages) |
| `GET /does-not-exist` | 404 **status**, rendered `_404` page |
| `GET /up` | JSON `ok:true`, `fallback.enabled`, `search_engine` |
| `GET /search?q=forma` with `HX-Request: true` | `<ul class="fx-search-results">` fragment with a `<mark>` excerpt |
| `GET /sitemap.xml` `/robots.txt` `/llms.txt` `/feed.xml` | 200, well-formed |
| `GET /admin/logout.php` without `?t=` | 302 and **still logged in** |
| `GET /api/v1/help` without a token | 401 |

Scan the server log for `Warning|Fatal|Deprecated|Notice` after every run — warnings in `php -S` output are bugs.

### Minting a test token

```bash
TOK="fx_$(openssl rand -hex 24)"; HASH=$(printf %s "$TOK" | shasum -a 256 | cut -d' ' -f1)
sqlite3 database/forma.db "INSERT INTO api_tokens (name, token_hash, scopes) VALUES ('smoke','$HASH','[\"content:read\",\"content:write\",\"settings:write\",\"backup:read\"]');"
```

Then exercise: `PUT /api/v1/pages/x` with only `{"seo":{…}}` (content must survive), `GET /api/v1/settings/security` (403), a self-loop redirect (`400`), `GET /api/v1/export/site` → mutate → `POST /api/v1/import/site` (content restored, `PRAGMA integrity_check` = ok).

## 3. Scripts

| Script | What it proves | Safe where |
|--------|----------------|-----------|
| `php tools/test-agent-safety.php` | Checkpoint create / restore / accept, DB + media restore, fallback cleanup | **Dev installs only.** Refuses if a real rollback point exists. Creates and deletes temp rows. |
| `php tools/test-oauth.php` | HTTPS enforcement, discovery, DCR, PKCE, replay defence, refresh rotation, revocation | Dev installs |
| `php tools/test-robots-placeholder.php` | Robots placeholder behaviour | Dev installs |
| `php tools/watch-php.php <url>…` | Heartbeat monitor; exit 2 = PHP down while static stamp is up | Anywhere |

Run the first two before any release that touches `lib/Agent*`, `api/`, or `AgentCheckpoint`.

## 4. Upgrade simulation (do this before every release)

What a customer's click on **Settings → Forma core → Update** will actually do, using the *old* install's `Updater` against your new tree.

```bash
# 1. Old install from the last released tag
rm -rf /tmp/fx-up && mkdir -p /tmp/fx-up/old /tmp/fx-up/zip/forma-cms-x
git archive $(git describe --tags --abbrev=0) | tar -x -C /tmp/fx-up/old
cd /tmp/fx-up/old && mkdir -p database uploads feeds fallback && php -S 127.0.0.1:8788 router.php &

# 2. Seed it: a page with SEO, a post, a future-dated post, a redirect, a token,
#    an edited stock snippet, a changed admin password. (API or sqlite3 — see §2.)

# 3. Release zip from the working tree, GitHub zipball layout (one top-level folder)
cd <repo> && rsync -a --exclude .git --exclude database --exclude uploads --exclude fallback \
    --exclude feeds --exclude node_modules --exclude .htaccess ./ /tmp/fx-up/zip/forma-cms-x/
(cd /tmp/fx-up/zip && zip -qr ../new.zip forma-cms-x)
```

Apply with the old code (mirror of `Updater::apply()` minus the GitHub download):

```php
<?php // /tmp/fx-up/apply.php
define('ROOT_DIR', '/tmp/fx-up/old');
require_once ROOT_DIR . '/lib/bootstrap.php';
$r = new ReflectionClass('Updater');
$call = fn(string $m, ...$a) => $r->getMethod($m)->invoke(null, ...$a);
$staging = Updater::dir() . '/staging-test';
$call('extractZip', '/tmp/fx-up/new.zip', $staging);
$root = $call('findReleaseRoot', $staging);
$to = $call('versionFromFile', $root . '/version.php');
echo "zip $to, installed ", Updater::installedVersion(), "\n";
$backup = $call('backupCurrent', Updater::installedVersion());
$call('copyAllowlist', $root, ROOT_DIR);
Database::get()->flushCache();
echo $call('versionFromFile', ROOT_DIR . '/version.php') === $to ? "UPDATE OK\n" : "FAILED\n";
```

Then, **without restarting the server**:

- `GET /up` reports the new version; `PRAGMA user_version` moved to the new stamp; `PRAGMA integrity_check` = ok
- Every seeded item still renders (page + its SEO title, post, redirect follows, snippet edit intact, site title)
- The pre-upgrade token works on REST and on `/api/v1/mcp` (`initialize`, `tools/list`, one edit)
- The existing admin session is still logged in; nag bar reflects the changed password
- The future-dated post goes live on the next `/up` after its time
- `Updater::rollback()` returns to the old version and serves it; re-apply works
- `tools/test-agent-safety.php` and `tools/test-oauth.php` pass on the upgraded DB

Note `php -S` honours `opcache.enable`; allow `revalidate_freq` (2s default) after swapping files before judging a stale response.

## 5. Admin UI

No automated UI tests. For CSS/JS changes take before/after screenshots of every section (`pages`, `blog`, `uploads`, `snippets`, `settings` × each sub-pane, login, OAuth consent) with headless Chrome or the Cursor browser tool. `docs/SCREENSHOTS.md` covers marketing shots, which are a different thing.

## Clean up

`rm -rf /tmp/forma-smoke /tmp/fx-up`. Never leave a minted token in a real `api_tokens` table — revoke it in Settings → Access when done.
