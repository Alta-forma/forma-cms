# Forma MCP (Cursor)

Local MCP server that calls a remote Forma install’s Agent API — **full site control** when the token has the right scopes.

## Setup

```bash
cd mcp
npm install
```

Create a token in the remote site: **Admin → Settings → Agents**  
(Recommended scopes: `content:read`, `content:write`, `media:write`, `settings:write`, `backup:read`, and `podcast:write` if needed.)

Add to `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "formax": {
      "command": "/opt/homebrew/bin/node",
      "args": ["/absolute/path/to/this-repo/mcp/server.mjs"],
      "env": {
        "FORMA_X_URL": "https://your-site.com",
        "FORMA_X_TOKEN": "fx_..."
      }
    }
  }
}
```

(`FORMA_X_URL` / `FORMA_X_TOKEN` and the `formax_*` tool names are stable aliases.)

Restart Cursor MCP. First tool to call: **`formax_help`**.

## Tools

| Tool | Scope |
|------|--------|
| `formax_help` / `formax_site` | content:read |
| pages / posts / snippets CRUD | content:read / write |
| media list / upload (base64) / delete | content:read / media:write |
| settings get / update | content:read / settings:write |
| `formax_get_seo` / `formax_update_seo` | content:read / settings:write |
| episodes list / update / delete | content:read / podcast:write |
| `formax_flush_cache` | settings:write |
| `formax_export` | backup:read (JSON, no binaries) |
| `formax_export_site` | backup:read — writes local `.zip` (DB + uploads + manifest) |

## Security

- Tokens are hashed at rest; shown once.
- Prefer HTTPS; Agent API rejects plain HTTP for non-local requests when `agent_https_only` is on.
- No shell access — content/settings/media only.
