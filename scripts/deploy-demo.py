#!/usr/bin/env python3
"""PUT templates/demo-admin.html to Forma as page demo (@ /demo)."""
import json, os, urllib.request, urllib.error
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
URL = os.environ.get("FORMA_X_URL", "https://forma-cms.me").rstrip("/")
TOKEN = os.environ.get("FORMA_X_TOKEN", "")
if not TOKEN:
    raise SystemExit("Set FORMA_X_TOKEN")
content = (ROOT / "templates" / "demo-admin.html").read_text()
body = json.dumps({
    "content": content,
    "content_type": "html",
    "slug": "/demo",
    "title": "Forma Admin Demo",
}).encode()
req = urllib.request.Request(
    URL + "/api/v1/pages/demo", data=body, method="PUT",
    headers={"Authorization": f"Bearer {TOKEN}", "X-Forma-Token": TOKEN,
             "Content-Type": "application/json"},
)
with urllib.request.urlopen(req) as r:
    print(r.read().decode()[:200])
req2 = urllib.request.Request(
    URL + "/api/v1/cache/flush", data=b"{}", method="POST",
    headers={"Authorization": f"Bearer {TOKEN}", "X-Forma-Token": TOKEN,
             "Content-Type": "application/json"},
)
urllib.request.urlopen(req2).read()
print("OK", URL + "/demo")
