#!/usr/bin/env python3
"""Push blog-archive / blog-single Twig + home Blog section to a Forma site via Agent API.

Uses a slug→image covers map so cards show featured images even when the
remote Render.php build does not yet expose post.image on public cards.
"""
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
URL = os.environ.get("FORMA_X_URL", "https://forma-cms.me").rstrip("/")
TOKEN = os.environ.get("FORMA_X_TOKEN", "")


def req(method: str, path: str, body: dict | None = None):
    data = None if body is None else json.dumps(body).encode()
    r = urllib.request.Request(
        URL + path,
        data=data,
        method=method,
        headers={
            "Authorization": f"Bearer {TOKEN}",
            "X-Forma-Token": TOKEN,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(r) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        err = e.read().decode("utf-8", "replace")
        raise SystemExit(f"{method} {path} -> {e.code}: {err}") from e


def put_page(filename: str, content: str, content_type: str = "html", **extra):
    meta = req("GET", f"/api/v1/pages/{filename}")
    payload = {
        "content": content,
        "content_type": content_type,
        "slug": meta.get("slug"),
        **extra,
    }
    out = req("PUT", f"/api/v1/pages/{filename}", payload)
    print(f"OK pages/{filename} ({len(content)} bytes)")
    return out


def load_posts():
    posts = req("GET", "/api/v1/posts")["posts"]
    full = []
    for p in posts:
        row = req("GET", f"/api/v1/posts/{p['filename']}")
        seo = row.get("seo") or {}
        cats = row.get("categories") or "[]"
        if isinstance(cats, str):
            try:
                cats = json.loads(cats)
            except json.JSONDecodeError:
                cats = []
        ts = row.get("published_at") or 0
        label = ""
        if ts:
            label = datetime.fromtimestamp(int(ts), tz=timezone.utc).strftime("%b %-d, %Y")
        full.append(
            {
                "slug": row["slug"],
                "title": row["title"],
                "description": row.get("description") or "",
                "author": row.get("author") or "",
                "date_label": label,
                "categories": cats if isinstance(cats, list) else [],
                "image": seo.get("featured_image") or seo.get("og_image") or "",
                "published_at": ts,
            }
        )
    full.sort(key=lambda x: x["published_at"] or 0, reverse=True)
    return full


def covers_block(posts: list[dict]) -> str:
    pairs = []
    for p in posts:
        if not p.get("image"):
            continue
        slug = p["slug"].replace("\\", "\\\\").replace("'", "\\'")
        img = p["image"].replace("\\", "\\\\").replace("'", "\\'")
        pairs.append(f"  '{slug}': '{img}'")
    return "{% set covers = {\n" + ",\n".join(pairs) + "\n} %}\n" if pairs else "{% set covers = {} %}\n"


FEATURED_CSS = """
    /* Blog (featured posts) */
    .journal-band { padding: 5.5rem 0 4.5rem; position: relative; }
    .journal-band::before {
      content: "";
      position: absolute; inset: 0;
      background: radial-gradient(ellipse at 15% 0%, rgba(252,190,52,.1), transparent 45%),
                  radial-gradient(ellipse at 90% 40%, rgba(255,255,255,.03), transparent 40%);
      pointer-events: none;
    }
    .journal-head {
      display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between;
      gap: 1rem 1.5rem; margin-bottom: 2rem; position: relative;
    }
    .journal-head .section-kicker { margin-bottom: .55rem; }
    .journal-head .section-title { margin: 0; max-width: 14ch; }
    .journal-head .section-lede { margin: .75rem 0 0; max-width: 28rem; }
    .journal-head-actions { display:flex; flex-direction:column; align-items:flex-end; gap:.75rem; }
    .journal-all {
      display: inline-flex; align-items: center; gap: .4rem;
      font-weight: 600; color: var(--gold); white-space: nowrap;
      border-bottom: 1px solid transparent;
    }
    .journal-all:hover { border-bottom-color: var(--stroke-gold); color: #ffd060; }
    .home-feed-pills { display:flex; gap:.5rem; }
    .home-feed-pills a {
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.35rem .7rem; border-radius:999px; border:1px solid var(--stroke);
      background:rgba(255,255,255,.04); color:var(--text); font-size:.78rem; font-weight:600;
    }
    .home-feed-pills a:hover { border-color:var(--stroke-gold); color:var(--gold); }
    .home-feed-pills i { color:var(--gold); }
    .journal-grid {
      display: grid; grid-template-columns: 1.35fr 1fr 1fr; gap: 1.1rem; position: relative;
    }
    @media (max-width: 900px) { .journal-grid { grid-template-columns: 1fr; } }
    .j-card {
      display: flex; flex-direction: column; border-radius: 1.25rem; overflow: hidden;
      border: 1px solid var(--stroke);
      background: linear-gradient(180deg, rgba(255,255,255,.045), rgba(255,255,255,.015));
      transition: transform .4s var(--ease-out), border-color .25s var(--ease), box-shadow .4s var(--ease);
      min-height: 100%;
    }
    .j-card:hover {
      transform: translateY(-5px); border-color: var(--stroke-gold);
      box-shadow: 0 20px 50px rgba(0,0,0,.4);
    }
    .j-card.j-feature { min-height: 22rem; }
    .j-cover { aspect-ratio: 16/10; position: relative; overflow: hidden;
      background: linear-gradient(145deg, rgba(252,190,52,.18), rgba(255,255,255,.03)); }
    .j-feature .j-cover { aspect-ratio: auto; flex: 1; min-height: 12rem; }
    .j-cover img { width: 100%; height: 100%; object-fit: cover; transition: transform .65s var(--ease-out); }
    .j-card:hover .j-cover img { transform: scale(1.05); }
    .j-pad { padding: 1.15rem 1.2rem 1.3rem; display: flex; flex-direction: column; gap: .55rem; flex: 1; }
    .j-chip {
      display: inline-flex; width: max-content; padding: .25rem .6rem; border-radius: 999px;
      border: 1px solid var(--stroke); color: var(--gold); font-size: .7rem;
      letter-spacing: .08em; text-transform: uppercase; font-family: var(--font-brand);
    }
    .j-card h3 {
      margin: 0; font-family: var(--font-brand); font-size: 1.2rem; line-height: 1.15;
      letter-spacing: -.02em; color: #fff;
    }
    .j-feature h3 { font-size: clamp(1.35rem, 2.4vw, 1.75rem); }
    .j-card p { margin: 0; color: var(--muted); font-size: .92rem; flex: 1; }
    .j-meta { display: flex; flex-wrap: wrap; gap: .5rem .85rem; color: var(--muted); font-size: .82rem; }
"""


FEATURED_SECTION = """
  <section id="blog" class="journal-band">
    <div class="wrap">
      <div class="journal-head">
        <div>
          <p class="section-kicker reveal">From the blog</p>
          <h2 class="section-title reveal reveal-d1">Ideas worth shipping.</h2>
          <p class="section-lede reveal reveal-d2">CMS craft, agents, SEO, and portability — written like we build. Subscribe via RSS or JSON Feed.</p>
        </div>
        <div class="journal-head-actions reveal reveal-d2">
          <a class="journal-all" href="/blog">All posts →</a>
          <div class="home-feed-pills">
            <a href="/feed.xml"><i class="fas fa-rss" aria-hidden="true"></i> RSS</a>
            <a href="/feed.json"><i class="fas fa-code" aria-hidden="true"></i> JSON</a>
          </div>
        </div>
      </div>
      <div class="journal-grid">
        {% for post in featured_posts %}
        {% set img = post.image|default(covers[post.slug]|default('')) %}
        <a class="j-card{% if loop.first %} j-feature{% endif %} reveal{% if not loop.first %} reveal-d{{ loop.index0 }}{% endif %}" href="/blog/{{ post.slug }}">
          {% if img %}
          <div class="j-cover"><img src="{{ img }}" alt="" loading="lazy"></div>
          {% else %}
          <div class="j-cover" aria-hidden="true"></div>
          {% endif %}
          <div class="j-pad">
            <span class="j-chip">{{ post.categories[0]|default('Blog') }}</span>
            <h3>{{ post.title }}</h3>
            <p>{{ post.description }}</p>
            <div class="j-meta"><span>{{ post.date_label }}</span>{% if post.author %}<span>{{ post.author }}</span>{% endif %}</div>
          </div>
        </a>
        {% else %}
        <p class="section-lede">No posts yet — <a href="/blog">visit the blog</a>.</p>
        {% endfor %}
      </div>
    </div>
  </section>
"""


def patch_home(content: str, covers: str) -> str:
    if ".journal-band" not in content:
        content = content.replace("</style>", FEATURED_CSS + "\n  </style>", 1)
    elif ".home-feed-pills" not in content:
        content = content.replace("</style>", FEATURED_CSS + "\n  </style>", 1)

    section = covers + FEATURED_SECTION
    if 'id="blog"' in content or 'id="journal"' in content:
        content = re.sub(
            r'\s*(?:\{%\s*set covers[\s\S]*?%\}\s*)?<section id="(?:blog|journal)"[\s\S]*?</section>\s*',
            "\n\n" + section + "\n",
            content,
            count=1,
        )
    else:
        marker = '  <section id="get" class="finale">'
        if marker not in content:
            raise SystemExit("Could not find #get finale section on home")
        content = content.replace(marker, section + "\n" + marker, 1)

    # Shared chrome
    content = re.sub(
        r'<header class="site-nav" id="siteNav">[\s\S]*?</header>',
        "[[site-header]]",
        content,
        count=1,
    )
    content = re.sub(r"<footer>[\s\S]*?</footer>", "[[site-footer]]", content, count=1)

    if 'rel="alternate"' not in content.split("</head>")[0] or "/feed.xml" not in content.split("</head>")[0]:
        content = content.replace(
            "</head>",
            '  <link rel="alternate" type="application/rss+xml" title="Forma Blog RSS" href="/feed.xml">\n'
            '  <link rel="alternate" type="application/feed+json" title="Forma Blog JSON Feed" href="/feed.json">\n</head>',
            1,
        )
    return content


def main():
    if not TOKEN:
        raise SystemExit("Set FORMA_X_TOKEN")
    posts = load_posts()
    covers = covers_block(posts)
    archive = covers + (ROOT / "templates" / "blog-archive.twig").read_text()
    single = (ROOT / "templates" / "blog-single.twig").read_text()
    put_page(
        "blog-archive",
        "<!--META\nslug: /blog-archive\ntitle: Blog\n-->\n" + archive,
        seo={
            "seo_title": "Blog — Forma",
            "seo_description": "Notes on portable CMS craft. Subscribe via RSS (/feed.xml) or JSON Feed (/feed.json).",
            "canonical": "https://forma-cms.me/blog",
            "robots": "index,follow",
        },
    )
    put_page("blog-single", "<!--META\nslug: /blog-single\ntitle: Blog post\n-->\n" + single)

    home = req("GET", "/api/v1/pages/home")
    put_page("home", patch_home(home["content"], covers))

    req("POST", "/api/v1/cache/flush", {})
    print("Cache flushed.")
    print("Visit:", URL + "/blog", URL + "/#blog")


if __name__ == "__main__":
    main()
