# Phoenix WordPress MCP

A local **MCP server** that gives an agent (Claude Desktop, Coworker/Kindle) direct,
programmatic control of the **phoenixelectric.life** WordPress site through the WP REST
API — no browser automation. Authenticates with a WordPress **Application Password**
(the "Kindle" credential). Runs locally over stdio; no hosting required.

## What it can do (v1)

| Tool | What it does |
|------|--------------|
| `wp_list_posts` | List posts (search / status / pagination) |
| `wp_get_post` | Read one post incl. full content |
| `wp_create_post` | Create a post (defaults to **draft**) |
| `wp_update_post` | Update a post by ID |
| `wp_list_pages` | List pages |
| `wp_list_users` | List users + roles (access audits) |
| `wp_list_plugins` | List plugins + active/inactive status |
| `wp_set_plugin_status` | Activate / deactivate a plugin |
| `wp_get_settings` | Read core site settings |

Coming next: reading the **Estimate Entries** (custom table — needs a small REST route
added to the estimate-form plugin), media upload, and a remote (HTTP) transport if we
ever want it always-on.

## Setup

### 1. Install
```bash
cd "mcp-server"
npm install
npm run check      # syntax check
```

### 2. Credentials (you supply these — never commit them)
The server reads three env vars. Use the **"Kindle" Application Password** (WP Admin →
Users → your profile → Application Passwords). The username is your WP login (`shane`).

- `WP_BASE_URL` = `https://phoenixelectric.life`
- `WP_USERNAME` = `shane`
- `WP_APP_PASSWORD` = the Application Password (spaces are fine)

### 3. Wire it into Claude Desktop
Add this to `claude_desktop_config.json` (Claude Desktop → Settings → Developer → Edit
Config), filling in the App Password:

```json
{
  "mcpServers": {
    "phoenix-wordpress": {
      "command": "node",
      "args": ["/Users/shanewarehime/Developer/GITHUB (GIT)/Phoenix-WordPress/mcp-server/src/index.js"],
      "env": {
        "WP_BASE_URL": "https://phoenixelectric.life",
        "WP_USERNAME": "shane",
        "WP_APP_PASSWORD": "PASTE THE KINDLE APP PASSWORD HERE"
      }
    }
  }
}
```
Restart Claude Desktop; the `phoenix-wordpress` tools appear. **The App Password lives only
in this config on your machine — never in the repo, never in chat.**

### 4. Test it standalone (optional, before wiring into Claude)
```bash
WP_BASE_URL="https://phoenixelectric.life" WP_USERNAME="shane" WP_APP_PASSWORD="…" \
  npx @modelcontextprotocol/inspector node src/index.js
```
The MCP Inspector opens a UI to call each tool and confirm it hits the live site.

## Security notes
- **No secrets in this repo.** The App Password is read from the env block in the Claude
  Desktop config (or the env you pass when testing).
- Every action runs as the WP user behind the App Password — so its capabilities are
  exactly that user's. Write/destructive tools are annotated; `wp_create_post` defaults to
  draft on purpose.

Built by Claude (the Builder) for Phoenix Electric — 2026-06-13.
