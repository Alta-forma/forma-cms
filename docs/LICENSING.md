# Forma paid unlock

Forma the CMS is free (MIT). A **$39 one-time** key unlocks paid features.

Buy: https://buy.stripe.com/7sY4gA87290N6a17Qk7N608

After checkout you get an email with a key (`FX-PERP-…`). Paste email + key under **Settings → General**.

**Today that key unlocks Podcast hosting.** Later it will also unlock Forms (same key, same Stripe product — not a second SKU).

Home of Forma: https://forma-cms.me

## How Forma checks a key

`lib/License.php`. Activate needs the checkout email and the key.

- If this install can see an HMAC secret on disk (`lib/LicenseHMACSecret.hex`, **not in git**, or a host-only path the operator configures), it verifies locally.
- Otherwise it POSTs `{email,key}` to `https://forma-cms.me/api/license/validate.php`.

A successful activate is stored in SQLite (`license` row id 1). After that, `isPodcastLicensed()` is true without calling home on every page load.

Local/dev: `FX-DEV-LOCAL`. That never requires Stripe.

**Do not ship `lib/LicenseHMACSecret.hex`.** It is gitignored. If it is in a public clone, anyone can mint keys.

## Later — Forms (not started)

Same $39 unlock as Podcast (`isPodcastLicensed()` / license row id 1). Not a second Stripe product, not a second HMAC prefix.

Local inbox in SQLite on each install, plus optional email copy to the site owner via PHP `mail()` / SMTP on that vhost. Not a Formspree relay through forma-cms.me. No VPS.

## What is not in this repo

The HMAC secret, Stripe webhook secrets, and Agent API tokens. Keys are minted after a real checkout; this tree only verifies them.
