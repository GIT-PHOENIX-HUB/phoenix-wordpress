# phoenix-wordpress MCP — tool reference

All website actions go through the **`phoenix-wordpress`** MCP server (local stdio; source
in `phoenix-wordpress/mcp-server/`). It authenticates as a WordPress admin via the Kindle
Application Password, so treat write tools with the restraint in the SKILL's gate section.

## Read tools — safe, use freely

| Tool | Use |
|------|-----|
| `wp_list_posts` | Find/list posts (search, status, pagination). |
| `wp_get_post` | Read one post's full content by ID. |
| `wp_list_pages` | Find/list pages. |
| `wp_list_users` | List users + roles (access audits). |
| `wp_list_plugins` | List plugins + active/inactive status + the `plugin` identifier. |
| `wp_get_settings` | Read core site settings (title, tagline, admin email, timezone). |

## Write tools — draft is safe, publish is gated

| Tool | Safe as… | Gated when… |
|------|----------|-------------|
| `wp_create_post` | `status: "draft"` (the default) | `status: "publish"` → **Shane's OK** |
| `wp_update_post` | editing into `status: "draft"` | changing to `status: "publish"`, or editing a *live* page → **Shane's OK** |

## Gated tools — always Shane's final button

| Tool | Why gated |
|------|-----------|
| `wp_set_plugin_status` | Activating/deactivating plugins changes live behavior. **Never** deactivate `phoenix-electric-estimate-form` (kills lead capture) or `phoenix-mail` (kills site email) without explicit OK. |

## Not yet available (coming)
- **Read estimate-form leads** — needs a small custom REST route on the estimate-form
  plugin. Until built, leads are worked from the `contact@phoenixelectric.life` inbox.
- **Media upload**, **user create/edit** — not in MCP v0.1.0. User changes stay manual
  (Shane's hand) regardless.

## Quick orientation pattern
Before acting, get the lay of the land with reads: `wp_get_settings`, then
`wp_list_pages` / `wp_list_plugins`. Confirm you're pointed at phoenixelectric.life, then
proceed per the playbook — draft-first, gates respected.
