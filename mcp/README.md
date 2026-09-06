# Forma MCP (Cursor)

Local MCP server that calls a remote Forma install’s Agent API — **full site control** when the token has the right scopes.

## Setup

```bash
cd mcp
npm install
```

Create a token in the remote site: **Admin → Settings → Access**
(Full-control scopes: `content:read`, `content:write`, `content:delete`, `media:write`, `media:delete`, `site:write`, `rollback:write`, `settings:write`, `backup:read`, and `podcast:write` if needed. Existing pre-0.5 tokens keep their old delete ability during migration.)

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
| pages / posts / snippets CRUD | content:read / content:write / content:delete |
| media list / upload (base64) / delete | content:read / media:write / media:delete |
| settings get / update | content:read / settings:write |
| `formax_get_seo` / `formax_update_seo` | content:read / settings:write |
| `formax_list_redirects` / `formax_save_redirect` / `formax_delete_redirect` | content:read / settings:write |
| episodes list / update / delete | content:read / podcast:write |
| `formax_flush_cache` | settings:write |
| `formax_export` | backup:read (JSON, no binaries) |
| `formax_export_site` | backup:read — writes local `.zip` (DB + uploads + manifest) |
| `formax_import_site` | settings:write — uploads a local `.zip` (restore/migrate) |
| `formax_health` | content:read — filesystem sanity check |
| `formax_rollback_status` | content:read — inspect the one last-known-good point |
| `formax_put_it_back` / `formax_this_looks_good` | rollback:write |

## Subscription chatbots (no Node process)

Forma also serves MCP directly from PHP at `https://your-site.com/api/v1/mcp` using stateless Streamable HTTP. Add that URL as a custom/remote connector. Forma’s OAuth 2.1 metadata, dynamic client registration, PKCE, admin sign-in, and consent flow are discovered automatically—there is no Site editor token to copy into the chatbot.

The remote endpoint exposes the safer Site editor subset: no delete, security, import, backup, or server tools. Public identity and SEO use the curated `site:write` scope. ChatGPT Custom GPT Actions can instead import `https://your-site.com/api/v1/openapi.json`.

Edits are live. Forma automatically protects one last-known-good point before the first write. `formax_put_it_back` restores it; `formax_this_looks_good` moves it and must only be used after the human explicitly approves the public site.

## Security

- Tokens are hashed at rest; shown once.
- OAuth access tokens expire after one hour and refresh tokens rotate on every use.
- OAuth callbacks must match the dynamically registered HTTPS or loopback URI exactly; PKCE `S256` is mandatory.
- Prefer HTTPS; Agent API rejects plain HTTP for non-local requests when `agent_https_only` is on.
- No shell access — content/settings/media only.
