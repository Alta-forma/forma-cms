#!/usr/bin/env node
/**
 * Forma MCP — full Agent API surface for Cursor.
 * Env: FORMA_X_URL, FORMA_X_TOKEN
 */
import { writeFileSync, readFileSync } from "fs";
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";

const BASE = (process.env.FORMA_X_URL || "").replace(/\/$/, "");
const TOKEN = process.env.FORMA_X_TOKEN || "";

async function api(method, path, body, { multipart } = {}) {
  if (!BASE || !TOKEN) throw new Error("Set FORMA_X_URL and FORMA_X_TOKEN");
  const headers = {
    Authorization: `Bearer ${TOKEN}`,
    "X-Forma-Token": TOKEN,
    Accept: "application/json",
  };
  let payload;
  if (multipart) {
    payload = multipart;
  } else if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }
  const res = await fetch(`${BASE}/api/v1${path}`, { method, headers, body: payload });
  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    data = { raw: text };
  }
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

const seoProps = {
  seo_title: { type: "string" },
  seo_description: { type: "string" },
  og_title: { type: "string" },
  og_description: { type: "string" },
  og_image: { type: "string" },
  canonical: { type: "string" },
  robots: { type: "string", description: "e.g. index,follow or noindex,nofollow" },
  twitter_card: { type: "string" },
};

const tools = [
  {
    name: "formax_help",
    description:
      "How Forma works + full Agent API map. Call this first when unfamiliar with the install.",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_site",
    description: "Site metadata, SEO summary, public URLs, version",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_list_pages",
    description: "List all pages",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_get_page",
    description: "Get a page (includes META + resolved SEO)",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_update_page",
    description: "Create/update a page. Pass seo{} for SEO meta fields.",
    inputSchema: {
      type: "object",
      properties: {
        filename: { type: "string" },
        content: { type: "string" },
        content_type: { type: "string", enum: ["html", "md"] },
        slug: { type: "string" },
        title: { type: "string" },
        seo: { type: "object", properties: seoProps },
      },
      required: ["filename", "content"],
    },
  },
  {
    name: "formax_delete_page",
    description: "Delete a page (not system pages)",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_list_posts",
    description: "List blog posts",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_get_post",
    description: "Get a blog post",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_update_post",
    description: "Create/update Markdown blog post. Include seo{} for SEO. Omit date or set empty to draft.",
    inputSchema: {
      type: "object",
      properties: {
        filename: { type: "string" },
        title: { type: "string" },
        slug: { type: "string" },
        body: { type: "string" },
        description: { type: "string" },
        author: { type: "string" },
        date: { type: "string" },
        categories: { type: "array", items: { type: "string" } },
        tags: { type: "array", items: { type: "string" } },
        seo: { type: "object", properties: seoProps },
      },
      required: ["filename", "title", "body"],
    },
  },
  {
    name: "formax_delete_post",
    description: "Delete a blog post",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_list_snippets",
    description: "List snippets (shortcodes)",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_get_snippet",
    description: "Get a snippet by filename",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_update_snippet",
    description: "Create/update a snippet",
    inputSchema: {
      type: "object",
      properties: {
        filename: { type: "string" },
        shortcode: { type: "string" },
        content: { type: "string" },
      },
      required: ["filename", "content"],
    },
  },
  {
    name: "formax_delete_snippet",
    description: "Delete a snippet",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_list_media",
    description: "List uploaded media files",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_upload_media",
    description: "Upload a file via base64 (filename + content_base64)",
    inputSchema: {
      type: "object",
      properties: {
        filename: { type: "string" },
        content_base64: { type: "string" },
        content_type: { type: "string" },
      },
      required: ["filename", "content_base64"],
    },
  },
  {
    name: "formax_delete_media",
    description: "Delete an uploaded file",
    inputSchema: {
      type: "object",
      properties: { filename: { type: "string" } },
      required: ["filename"],
    },
  },
  {
    name: "formax_get_settings",
    description: "Get a settings section (site, blog, seo, cache, podcast, …) or all if section omitted",
    inputSchema: {
      type: "object",
      properties: { section: { type: "string" } },
    },
  },
  {
    name: "formax_update_settings",
    description: "Merge-update a settings section",
    inputSchema: {
      type: "object",
      properties: {
        section: { type: "string" },
        value: { type: "object" },
      },
      required: ["section", "value"],
    },
  },
  {
    name: "formax_get_seo",
    description: "Get sitewide SEO settings, health report, redirects, robots.txt + sitemap.xml + llms.txt previews",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_list_redirects",
    description: "List all 301/302 redirects",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_save_redirect",
    description: "Create or update a redirect. Pass id to update an existing one.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number", description: "Omit to create a new redirect" },
        from_path: { type: "string", description: "e.g. /old-page" },
        to_url: { type: "string", description: "Absolute URL or path to send visitors to" },
        status: { type: "number", description: "301, 302, 307, or 308 (default 301)" },
        enabled: { type: "boolean" },
        note: { type: "string" },
      },
      required: ["from_path", "to_url"],
    },
  },
  {
    name: "formax_delete_redirect",
    description: "Delete a redirect by id",
    inputSchema: {
      type: "object",
      properties: { id: { type: "number" } },
      required: ["id"],
    },
  },
  {
    name: "formax_update_seo",
    description: "Update sitewide SEO. Mostly automatic (robots/sitemap/meta). Optional: favicon, default_og_image, schema_type (person|organization|local_business), place_id, same_as.",
    inputSchema: {
      type: "object",
      properties: {
        robots_auto: { type: "boolean" },
        robots_manual: { type: "string" },
        robots_index: { type: "boolean" },
        robots_follow: { type: "boolean" },
        robots_extra: { type: "string" },
        sitemap_auto: { type: "boolean" },
        sitemap_manual: { type: "string" },
        sitemap_enabled: { type: "boolean" },
        sitemap_include_pages: { type: "boolean" },
        sitemap_include_posts: { type: "boolean" },
        sitemap_include_podcast: { type: "boolean" },
        sitemap_include_images: { type: "boolean" },
        title_separator: { type: "string" },
        title_suffix: { type: "boolean" },
        favicon: { type: "string" },
        apple_touch_icon: { type: "string" },
        default_og_image: { type: "string" },
        twitter_site: { type: "string" },
        twitter_card: { type: "string" },
        google_site_verification: { type: "string" },
        bing_site_verification: { type: "string" },
        json_ld_website: { type: "boolean" },
        schema_type: { type: "string" },
        organization_name: { type: "string" },
        organization_logo: { type: "string" },
        same_as: { type: "string" },
        place_id: { type: "string" },
        gbp_url: { type: "string" },
        noindex_paths: { type: "string" },
      },
    },
  },
  {
    name: "formax_list_episodes",
    description: "List podcast episodes",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_update_episode",
    description: "Create/update a podcast episode",
    inputSchema: {
      type: "object",
      properties: {
        episode_id: { type: "string" },
        title: { type: "string" },
        description: { type: "string" },
        show_notes: { type: "string" },
        audio_file: { type: "string" },
        duration: { type: "string" },
        episode_number: { type: "number" },
        season_number: { type: "number" },
        date: { type: "string" },
      },
      required: ["episode_id"],
    },
  },
  {
    name: "formax_delete_episode",
    description: "Delete a podcast episode",
    inputSchema: {
      type: "object",
      properties: { episode_id: { type: "string" } },
      required: ["episode_id"],
    },
  },
  {
    name: "formax_flush_cache",
    description: "Flush the Forma page cache",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_export",
    description: "Versioned JSON site export (no binaries)",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "formax_export_site",
    description:
      "Download full site package zip (DB + uploads + manifest) to a local path. Requires backup:read.",
    inputSchema: {
      type: "object",
      properties: {
        path: {
          type: "string",
          description: "Local filesystem path for the .zip (e.g. /tmp/formax-site.zip)",
        },
      },
      required: ["path"],
    },
  },
  {
    name: "formax_import_site",
    description:
      "Restore/migrate from a full site package zip on the local filesystem (as produced by formax_export_site). Requires settings:write. Careful: replace_database defaults to true.",
    inputSchema: {
      type: "object",
      properties: {
        path: { type: "string", description: "Local filesystem path to the .zip to upload" },
        replace_database: { type: "boolean", description: "Default true — overwrite database/forma.db" },
        merge_uploads: { type: "boolean", description: "Default true — merge uploads/ instead of wiping" },
      },
      required: ["path"],
    },
  },
  {
    name: "formax_health",
    description: "Filesystem sanity check — catches bad uploads / nested folders (lib/lib, admin/admin) after a manual FTP deploy",
    inputSchema: { type: "object", properties: {} },
  },
];

const server = new Server(
  { name: "formax", version: "0.2.0" },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools }));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const name = request.params.name;
  const a = request.params.arguments || {};
  let result;
  switch (name) {
    case "formax_help":
      result = await api("GET", "/help");
      break;
    case "formax_site":
      result = await api("GET", "/site");
      break;
    case "formax_list_pages":
      result = await api("GET", "/pages");
      break;
    case "formax_get_page":
      result = await api("GET", `/pages/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_update_page":
      result = await api("PUT", `/pages/${encodeURIComponent(a.filename)}`, {
        content: a.content,
        content_type: a.content_type || "html",
        slug: a.slug,
        title: a.title,
        seo: a.seo,
      });
      break;
    case "formax_delete_page":
      result = await api("DELETE", `/pages/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_list_posts":
      result = await api("GET", "/posts");
      break;
    case "formax_get_post":
      result = await api("GET", `/posts/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_update_post":
      result = await api("PUT", `/posts/${encodeURIComponent(a.filename)}`, a);
      break;
    case "formax_delete_post":
      result = await api("DELETE", `/posts/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_list_snippets":
      result = await api("GET", "/snippets");
      break;
    case "formax_get_snippet":
      result = await api("GET", `/snippets/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_update_snippet":
      result = await api("PUT", `/snippets/${encodeURIComponent(a.filename)}`, {
        shortcode: a.shortcode || a.filename,
        content: a.content,
      });
      break;
    case "formax_delete_snippet":
      result = await api("DELETE", `/snippets/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_list_media":
      result = await api("GET", "/media");
      break;
    case "formax_upload_media":
      result = await api("POST", "/media", {
        filename: a.filename,
        content_base64: a.content_base64,
        content_type: a.content_type,
      });
      break;
    case "formax_delete_media":
      result = await api("DELETE", `/media/${encodeURIComponent(a.filename)}`);
      break;
    case "formax_get_settings":
      result = a.section
        ? await api("GET", `/settings/${encodeURIComponent(a.section)}`)
        : await api("GET", "/settings");
      break;
    case "formax_update_settings":
      result = await api("PUT", `/settings/${encodeURIComponent(a.section)}`, a.value);
      break;
    case "formax_get_seo":
      result = await api("GET", "/seo");
      break;
    case "formax_update_seo":
      result = await api("PUT", "/seo", a);
      break;
    case "formax_list_redirects":
      result = await api("GET", "/redirects");
      break;
    case "formax_save_redirect":
      result = await api("PUT", "/redirects", a);
      break;
    case "formax_delete_redirect":
      result = await api("DELETE", `/redirects/${encodeURIComponent(a.id)}`);
      break;
    case "formax_health":
      result = await api("GET", "/health");
      break;
    case "formax_list_episodes":
      result = await api("GET", "/episodes");
      break;
    case "formax_update_episode":
      result = await api("PUT", `/episodes/${encodeURIComponent(a.episode_id)}`, a);
      break;
    case "formax_delete_episode":
      result = await api("DELETE", `/episodes/${encodeURIComponent(a.episode_id)}`);
      break;
    case "formax_flush_cache":
      result = await api("POST", "/cache/flush", {});
      break;
    case "formax_export":
      result = await api("GET", "/export");
      break;
    case "formax_export_site": {
      if (!BASE || !TOKEN) throw new Error("Set FORMA_X_URL and FORMA_X_TOKEN");
      const out = String(a.path || "");
      if (!out) throw new Error("path is required");
      const res = await fetch(`${BASE}/api/v1/export/site`, {
        method: "GET",
        headers: {
          Authorization: `Bearer ${TOKEN}`,
          "X-Forma-Token": TOKEN,
          Accept: "application/zip",
        },
      });
      if (!res.ok) {
        const t = await res.text();
        let err = t;
        try {
          err = JSON.parse(t).error || t;
        } catch {}
        throw new Error(err || `HTTP ${res.status}`);
      }
      const buf = Buffer.from(await res.arrayBuffer());
      writeFileSync(out, buf);
      result = { ok: true, path: out, bytes: buf.length };
      break;
    }
    case "formax_import_site": {
      if (!BASE || !TOKEN) throw new Error("Set FORMA_X_URL and FORMA_X_TOKEN");
      const src = String(a.path || "");
      if (!src) throw new Error("path is required");
      const bin = readFileSync(src);
      const form = new FormData();
      form.append("package", new Blob([bin]), "site.zip");
      if (a.replace_database !== undefined) form.append("replace_database", String(!!a.replace_database));
      if (a.merge_uploads !== undefined) form.append("merge_uploads", String(!!a.merge_uploads));
      const res = await fetch(`${BASE}/api/v1/import/site`, {
        method: "POST",
        headers: { Authorization: `Bearer ${TOKEN}`, "X-Forma-Token": TOKEN, Accept: "application/json" },
        body: form,
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        data = { raw: text };
      }
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      result = data;
      break;
    }
    default:
      throw new Error(`Unknown tool: ${name}`);
  }
  return {
    content: [{ type: "text", text: JSON.stringify(result, null, 2) }],
  };
});

const transport = new StdioServerTransport();
await server.connect(transport);
