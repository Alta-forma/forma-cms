# Forma Podcast licensing

Forma the CMS is free (MIT). Podcast hosting is a **$39 one-time** unlock.

Buy: https://buy.stripe.com/7sY4gA87290N6a17Qk7N608

After checkout you get an email with a key (`FX-PERP-…`). Paste email + key under **Settings → General**. The locked Podcast screen has the same Buy button.

Home of Forma: https://forma-cms.me

## How Forma checks a key

`lib/License.php`. Activate needs the checkout email and the key.

- If this install can see an HMAC secret on disk (`lib/LicenseHMACSecret.hex`, **not in git**, or a host-only path the operator configures), it verifies locally.
- Otherwise it POSTs `{email,key}` to `https://forma-cms.me/api/license/validate.php`.

A successful activate is stored in SQLite (`license` row id 1). After that, `isPodcastLicensed()` is true without calling home on every page load.

Local/dev: `FX-DEV-LOCAL`. That never requires Stripe.

**Do not ship `lib/LicenseHMACSecret.hex`.** It is gitignored. If it is in a public clone, anyone can mint keys.

## What is not in this repo

The HMAC secret, Stripe webhook secrets, and Agent API tokens. Keys are minted after a real checkout; this tree only verifies them.
