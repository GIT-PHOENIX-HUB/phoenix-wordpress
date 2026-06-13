#!/usr/bin/env node
/**
 * Phoenix WordPress MCP
 * ---------------------
 * A local (stdio) MCP server that exposes the phoenixelectric.life WordPress
 * site through the WP REST API, authenticated with a WordPress Application
 * Password (the "Kindle" credential).
 *
 * Credentials are read from the environment — NEVER hardcoded:
 *   WP_BASE_URL       e.g. https://phoenixelectric.life
 *   WP_USERNAME       e.g. shane
 *   WP_APP_PASSWORD   the Application Password (spaces are fine; they're stripped)
 *
 * Built by Claude (the Builder) for Shane Warehime / Phoenix Electric.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// ---------------------------------------------------------------------------
// Config (from env only)
// ---------------------------------------------------------------------------
const BASE = (process.env.WP_BASE_URL || "").replace(/\/+$/, "");
const USER = process.env.WP_USERNAME || "";
// WP shows app passwords with spaces ("abcd efgh ...") — they work either way,
// but strip spaces to be safe.
const APP_PW = (process.env.WP_APP_PASSWORD || "").replace(/\s+/g, "");

function isConfigured() {
  return Boolean(BASE && USER && APP_PW);
}

function authHeader() {
  return "Basic " + Buffer.from(`${USER}:${APP_PW}`).toString("base64");
}

/**
 * Call the WP REST API. Returns parsed JSON, or throws an actionable Error.
 */
async function wp(path, { method = "GET", query, body } = {}) {
  if (!isConfigured()) {
    throw new Error(
      "Phoenix WordPress MCP is not configured. Set WP_BASE_URL, WP_USERNAME, and " +
      "WP_APP_PASSWORD in this server's env block (use the 'Kindle' Application Password)."
    );
  }
  const url = new URL(`${BASE}/wp-json${path}`);
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
    }
  }
  let res;
  try {
    res = await fetch(url, {
      method,
      headers: {
        Authorization: authHeader(),
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new Error(`Could not reach WordPress at ${BASE} (${method} ${path}): ${e.message}`);
  }

  const raw = await res.text();
  let data = null;
  if (raw) {
    try { data = JSON.parse(raw); } catch { data = raw; }
  }

  if (!res.ok) {
    const msg = data && data.message ? data.message : `HTTP ${res.status}`;
    const code = data && data.code ? ` [${data.code}]` : "";
    let hint = "";
    if (res.status === 401) hint = " — check WP_USERNAME and the Application Password.";
    else if (res.status === 403) hint = " — that account lacks the capability for this action.";
    throw new Error(`WordPress API error${code}: ${msg} (${method} ${path})${hint}`);
  }
  return data;
}

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------
const ok = (data) => ({
  content: [{ type: "text", text: typeof data === "string" ? data : JSON.stringify(data, null, 2) }],
});
const fail = (err) => ({
  content: [{ type: "text", text: `Error: ${err && err.message ? err.message : String(err)}` }],
  isError: true,
});

// ---------------------------------------------------------------------------
// Server + tools
// ---------------------------------------------------------------------------
const server = new McpServer({ name: "phoenix-wordpress", version: "0.1.0" });

const POST_FIELDS = "id,date,modified,status,type,link,title";

server.registerTool(
  "wp_list_posts",
  {
    title: "List posts",
    description: "List WordPress posts. Optional search term, status filter, and pagination. Returns id/date/status/title/link.",
    inputSchema: {
      search: z.string().optional().describe("Search term"),
      status: z.enum(["publish", "draft", "pending", "private", "future", "any"]).optional().describe("Post status (default: publish)"),
      per_page: z.number().int().min(1).max(100).optional().describe("Results per page (default 10)"),
      page: z.number().int().min(1).optional().describe("Page number (default 1)"),
    },
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async ({ search, status, per_page, page }) => {
    try {
      return ok(await wp("/wp/v2/posts", { query: { search, status, per_page: per_page ?? 10, page, _fields: POST_FIELDS } }));
    } catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_get_post",
  {
    title: "Get a post",
    description: "Fetch a single WordPress post by ID, including its full content.",
    inputSchema: { id: z.number().int().describe("Post ID") },
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async ({ id }) => {
    try { return ok(await wp(`/wp/v2/posts/${id}`, { query: { context: "edit", _fields: "id,date,status,link,title,content" } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_create_post",
  {
    title: "Create a post",
    description: "Create a new WordPress post. Defaults to 'draft' so nothing publishes by accident.",
    inputSchema: {
      title: z.string().describe("Post title"),
      content: z.string().describe("Post body (HTML allowed)"),
      status: z.enum(["draft", "publish", "pending", "private"]).optional().describe("Status (default: draft)"),
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  },
  async ({ title, content, status }) => {
    try { return ok(await wp("/wp/v2/posts", { method: "POST", body: { title, content, status: status ?? "draft" } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_update_post",
  {
    title: "Update a post",
    description: "Update an existing WordPress post by ID. Only the fields you pass are changed.",
    inputSchema: {
      id: z.number().int().describe("Post ID"),
      title: z.string().optional().describe("New title"),
      content: z.string().optional().describe("New body (HTML allowed)"),
      status: z.enum(["draft", "publish", "pending", "private"]).optional().describe("New status"),
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  async ({ id, title, content, status }) => {
    try {
      const body = {};
      if (title !== undefined) body.title = title;
      if (content !== undefined) body.content = content;
      if (status !== undefined) body.status = status;
      return ok(await wp(`/wp/v2/posts/${id}`, { method: "POST", body }));
    } catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_list_pages",
  {
    title: "List pages",
    description: "List WordPress pages. Optional search term and pagination. Returns id/status/title/link.",
    inputSchema: {
      search: z.string().optional().describe("Search term"),
      per_page: z.number().int().min(1).max(100).optional().describe("Results per page (default 20)"),
      page: z.number().int().min(1).optional().describe("Page number"),
    },
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async ({ search, per_page, page }) => {
    try { return ok(await wp("/wp/v2/pages", { query: { search, per_page: per_page ?? 20, page, status: "any", _fields: "id,status,title,link" } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_list_users",
  {
    title: "List users",
    description: "List WordPress users (id, name, slug, roles, registered date). Useful for access audits.",
    inputSchema: {
      per_page: z.number().int().min(1).max(100).optional().describe("Results per page (default 50)"),
    },
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async ({ per_page }) => {
    try { return ok(await wp("/wp/v2/users", { query: { context: "edit", per_page: per_page ?? 50, _fields: "id,name,slug,email,roles,registered_date" } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_list_plugins",
  {
    title: "List plugins",
    description: "List installed plugins with their status (active/inactive). Plugin identifier is the 'plugin' field (e.g. 'phoenix-mail/phoenix-mail').",
    inputSchema: {},
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async () => {
    try { return ok(await wp("/wp/v2/plugins", { query: { _fields: "plugin,name,status,version" } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_set_plugin_status",
  {
    title: "Activate or deactivate a plugin",
    description: "Set a plugin active or inactive. Use the 'plugin' identifier from wp_list_plugins (e.g. 'wp-mail-smtp/wp_mail_smtp').",
    inputSchema: {
      plugin: z.string().describe("Plugin identifier, e.g. 'phoenix-mail/phoenix-mail'"),
      status: z.enum(["active", "inactive"]).describe("Target status"),
    },
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  async ({ plugin, status }) => {
    try { return ok(await wp(`/wp/v2/plugins/${plugin}`, { method: "POST", body: { status } })); }
    catch (e) { return fail(e); }
  }
);

server.registerTool(
  "wp_get_settings",
  {
    title: "Get site settings",
    description: "Read core site settings (title, tagline, admin email, timezone, etc.).",
    inputSchema: {},
    annotations: { readOnlyHint: true, openWorldHint: true },
  },
  async () => {
    try { return ok(await wp("/wp/v2/settings")); }
    catch (e) { return fail(e); }
  }
);

// ---------------------------------------------------------------------------
// Start (stdio)
// ---------------------------------------------------------------------------
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // Note: do not write to stdout — stdio transport owns it. Logs go to stderr.
  console.error(
    `[phoenix-wordpress mcp] ready` +
    (isConfigured() ? ` (target: ${BASE} as ${USER})` : " (NOT configured — set WP_BASE_URL/WP_USERNAME/WP_APP_PASSWORD)")
  );
}

main().catch((err) => {
  console.error("[phoenix-wordpress mcp] fatal:", err);
  process.exit(1);
});
