# Phoenix-WordPress

> WordPress code for **phoenixelectric.life** — Phoenix Electric's website.

Dedicated home for the live site's custom WordPress code: plugins now, and
later themes and site configuration. Kept separate from `phoenix-toolbox`
(agent capabilities) on purpose — different concern, clean history, safe to
push.

## Structure

```
plugins/    Custom WordPress plugins — one folder per plugin (the source of truth)
releases/   Built, upload-ready .zip artifacts for WP admin → Upload Plugin
```

## Plugins

### phoenix-electric-estimate-form — v1.1.0

Self-hosted estimate-request form. Replaces WPForms on the Free Estimate page
(`/free-estimate/`, page 272) — no annual license, no third-party UI tax.

- **Shortcode:** `[phoenix_estimate_form]`
- **Fields:** First Name, Last Name, Street Address, City, State, ZIP, Phone,
  Email, Description of Work.
- **Storage:** saves every submission to `{prefix}_pe_estimate_entries` with
  timestamp + submitter IP.
- **Notify:** emails **contact@phoenixelectric.life** on each submit, Reply-To
  set to the customer.
- **Admin:** an **Estimate Entries** screen with a sortable, paginated table and
  one-click **CSV export**.
- **Security:** WordPress nonce, full server-side sanitization, escaped output,
  prepared SQL, hidden honeypot for bots.
- **Source:** `plugins/phoenix-electric-estimate-form/`
- **Build:** `releases/phoenix-electric-estimate-form-v1.1.0.zip`

### phoenix-mail — v1.0.0

Routes all WordPress email through **Microsoft Graph** (app-only / client credentials)
instead of SMTP — a self-hosted replacement for WP Mail SMTP for Microsoft 365 mailboxes.

- **Settings:** Settings → Phoenix Mail — Tenant ID, Client ID, Client Secret, Sender
  mailbox, an Enable toggle, and a **Send test email** button.
- **Secrets:** entered in the admin screen (stored in WordPress options) or, for tighter
  security, defined in `wp-config.php` as `PHOENIX_MAIL_TENANT_ID`, `PHOENIX_MAIL_CLIENT_ID`,
  `PHOENIX_MAIL_CLIENT_SECRET`. **Never committed to this repo.**
- **How it works:** hooks `pre_wp_mail`; when enabled + configured, sends via Graph
  `users/{sender}/sendMail`, else falls through to the default mailer. Token cached; 401 retried once.
- **Azure prerequisite:** the app registration needs Microsoft Graph **`Mail.Send` application
  permission** (admin consent), and the sender must be a real M365 mailbox.
- **Cutover:** install → activate → enter creds → **Send test** → confirm receipt → enable the
  override → deactivate WP Mail SMTP.
- **Source:** `plugins/phoenix-mail/` · **Build:** `releases/phoenix-mail-v1.0.0.zip`

## Install

WP admin → **Plugins → Add New → Upload Plugin** → choose the release zip →
**Install Now** → **Activate** (creates the database table). Then add
`[phoenix_estimate_form]` to any page. Activating does **not** affect the live
page until you place the shortcode.

## Re-build a plugin zip after editing

```bash
cd plugins
zip -r ../releases/<plugin-name>-vX.Y.Z.zip <plugin-name> -x "*.DS_Store"
```

## Status / verification

- **phoenix-electric-estimate-form v1.1.0** — syntax verified via glayzzle
  php-parser (713 lines, equivalent to `php -l`). Runtime gate = live activation
  + test submission on the site.

## Provenance

Migrated out of `phoenix-toolbox/wordpress-plugins/` on 2026-06-13, to give the
website code an independent repo free of unrelated toolbox history.
