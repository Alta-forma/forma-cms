# Screenshots still to take

Do not ship fake admin UI. The marketing covers under `covers/` are blog-post art, not product shots. Until the real captures land, forma-cms.me uses those as placeholders.

Shoot these in the **live dark admin** (full window, 1440×900 or retina 2880×1800, no personal tokens visible):

| File to save as | Shot |
|-----------------|------|
| `uploads/shot-login.png` | Login screen |
| `uploads/shot-pages.png` | Pages list |
| `uploads/shot-editor.png` | Page / post editor with CodeMirror |
| `uploads/shot-blog.png` | Blog list + a published post |
| `uploads/shot-seo.png` | Settings → SEO (health + schema) |
| `uploads/shot-search.png` | Public `/search` or the `[[search]]` box |
| `uploads/shot-cache.png` | Settings → Cache (PHP + HTML cache) |
| `uploads/shot-podcast.png` | Locked Podcast pane + Buy button |
| `uploads/shot-agents.png` | Settings → Access / Agents (redact tokens) |

Where they go:

1. Homepage `#product` gallery on [forma-cms.me](https://forma-cms.me/)
2. Docs → Screenshots
3. GitHub README (optional, after the files exist)

After capturing, drop the files in `uploads/` on forma-cms.me (or this repo’s `docs/shots/` if we start committing them) and swap the `src` on the `<img>` tags. Same filenames keep the HTML stable.
