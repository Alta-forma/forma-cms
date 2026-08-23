# Deploy Forma

Forma is PHP 8.1+ and one SQLite file. No Composer, no MySQL.

Download: [github.com/onechrisjones/forma-cms](https://github.com/onechrisjones/forma-cms)
Zip: [forma-cms-main.zip](https://github.com/onechrisjones/forma-cms/archive/refs/heads/main.zip)

## Local

```bash
git clone https://github.com/onechrisjones/forma-cms.git
cd forma-cms
chmod -R 775 database uploads feeds
php -S localhost:8787 router.php
```

Open http://localhost:8787/admin — first boot is `admin` / `admin`. Change that immediately (Settings → Access).

## DreamHost (shared Apache)

1. Create the domain / subdomain and point the document root at the Forma tree (the folder that contains `index.php`).
2. Upload or clone the repo into that folder.
3. Make these writable by PHP:

   ```bash
   chmod -R 775 database uploads feeds
   ```

4. Hit the site once so Forma can write `.htaccess` (Admin → Settings → Server → Write .htaccess). The source of truth is `lib/Htaccess.php` — do not hand-edit `.htaccess` and then expect it to survive a rewrite.
5. Open `/admin`, change the password, then Settings → SEO / Cache as needed.

On later **code** deploys, rsync `admin/`, `lib/`, `api/`, `mcp/`, `templates/`, and the entry PHP files. **Do not overwrite** `database/`, `uploads/`, or a live `.htaccess` you have already customized for a vhost (preview bypasses, etc.). SQLite is the site; the PHP tree is the app.

DreamHost FastCGI often strips `Authorization`. Forma’s generated `.htaccess` copies it back so Agent API Bearer tokens work. The DreamHost-safe header is `X-Forma-Token`.

## Apache (any host)

Need `mod_rewrite`. After first boot, Settings → Server should produce a root `.htaccess` that:

- Serves `fallback/*.html` first when HTML cache is on (`fallback/.enabled`)
- Always sends `/up`, `/admin`, `/api`, `/search`, `/robots.txt`, `/sitemap.xml`, and feeds to PHP
- Denies web access to `database/`, `lib/`, `mcp/`, `tools/`

If a leftover static `robots.txt` is sitting in the web root, delete it so Forma can generate it.

## Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
location ^~ /database/ { deny all; }
location ^~ /lib/      { deny all; }
location ^~ /mcp/      { deny all; }
location ^~ /tools/    { deny all; }
```

HTML cache is an Apache convenience. On Nginx, leave `cache.static_fallback` off unless you add equivalent `try_files` for `fallback/`. PHP cache (Settings → Cache) still works.

## HTML cache + heartbeat

Settings → Cache → **Enable HTML cache** writes real `.html` under `fallback/`. Apache serves those first. SQLite stays the source of truth — you can delete `fallback/` and click **Rebuild HTML cache**.

- `GET /up` — PHP heartbeat JSON (never a static file)
- `/fallback/php-ok.json` — on-disk stamp. If `/up` dies but this is 200, FastCGI/PHP is down and Apache is still serving cached HTML.

## After install

1. Change the admin password
2. Settings → SEO (site name, default share image, schema)
3. Settings → Server (confirm rewrite / Authorization)
4. Settings → Backup → download a site package before you get brave
5. Optional: [Buy Forma Podcast — $39](https://buy.stripe.com/7sY4gA87290N6a17Qk7N608), then paste the key under Settings → General

## What this repo does not contain

- Your SQLite database, uploads, or tokens
- `lib/LicenseHMACSecret.hex` (gitignored). Podcast keys are verified locally only if that file exists, otherwise Forma POSTs to `https://forma-cms.me/api/license/validate.php`

See [LICENSING.md](LICENSING.md) for the podcast unlock. See [SCREENSHOTS.md](SCREENSHOTS.md) for product shots still to take.
