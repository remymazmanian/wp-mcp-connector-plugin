# WP MCP Connector

Serve a self-hosted WordPress site to AI clients over the Model Context Protocol.

Claude Desktop, Claude Code, Cursor, Grok and anything else that speaks MCP can read and manage the site through 25 permission-gated tools: posts, pages, media, taxonomies, comments, SEO metadata, plugin and theme inventory, site health, and a set of emulated WP-CLI commands.

- **No Composer. No npm. No build step.** The plugin is plain PHP and runs on a stock WordPress install.
- **Two transports.** Streamable HTTP (current spec) and HTTP+SSE (legacy clients).
- **Two auth methods.** Application Passwords (primary) and optional Bearer tokens.
- **Modern where it counts.** On WordPress 6.9+ every tool is also registered with the core Abilities API, so the same definitions are reachable from `/wp-json/wp-abilities/v1/` and from the official MCP Adapter if you install it.

---

## Contents

1. [Architecture](#architecture)
2. [Requirements](#requirements)
3. [Install and activate](#install-and-activate)
4. [Configure](#configure)
5. [Generate an Application Password](#generate-an-application-password)
6. [Credential storage](#credential-storage)
7. [Connect a client](#connect-a-client)
8. [The stdio bridge](#the-stdio-bridge)
9. [Tool reference](#tool-reference)
10. [Security model](#security-model)
11. [Example prompts](#example-prompts)
12. [Troubleshooting](#troubleshooting)
13. [Extending](#extending)

---

## Architecture

```
Claude Desktop / Claude Code / Cursor / Grok
        │
        ├─ (A) native remote MCP ──► POST /wp-json/mcp/v1/mcp     Streamable HTTP
        ├─ (B) legacy clients ─────► GET  /wp-json/mcp/v1/sse      HTTP+SSE
        └─ (C) stdio-only clients ─► npx wp-mcp-bridge ──► (A)     zero-dep TS proxy
                                              │
                          ┌───────────────────┴──────────────────┐
                          │  WPMCP_Server  (JSON-RPC 2.0 engine) │
                          │  initialize / tools.list / tools.call │
                          └───────────────────┬──────────────────┘
              ┌──────────────┬────────────────┼──────────────┬─────────────┐
           Auth          Rate limiter     Registry        Abilities     Logger
      (App Password    (sliding window   (25 tools,       mirror →
       + Bearer)        per user)         cap-gated)      WP core Abilities API
```

The protocol engine is transport agnostic. Streamable HTTP and legacy SSE both hand messages to the same `WPMCP_Server`, and differ only in how they frame the reply, so behaviour cannot drift between them.

### Why a self-contained server rather than only the MCP Adapter

The official `wordpress/mcp-adapter` package is the direction of travel, but it needs Composer and a feature plugin, and its API has moved between releases. This plugin therefore does both:

| | Self-contained endpoints | Abilities API mirror |
|---|---|---|
| Requires | nothing | WordPress 6.9+ |
| Endpoint | `/wp-json/mcp/v1/mcp` | `/wp-json/wp-abilities/v1/abilities/…/run` |
| Always available | yes | when core supports it |
| Used by MCP Adapter | no | yes, if installed |

One tool definition feeds all three paths. Adding a tool once exposes it everywhere.

### File layout

```
wp-mcp-connector/
├── wp-mcp-connector.php              bootstrap, constants, autoloader
├── includes/
│   ├── class-wpmcp-plugin.php        orchestrator, tool registry owner
│   ├── class-wpmcp-server.php        JSON-RPC 2.0 / MCP protocol engine
│   ├── class-wpmcp-rest.php          Streamable HTTP + legacy SSE transports
│   ├── class-wpmcp-auth.php          Application Passwords + Bearer tokens
│   ├── class-wpmcp-settings.php      settings, permission profiles
│   ├── class-wpmcp-registry.php      tool registry and exposure rules
│   ├── class-wpmcp-schema.php        JSON Schema builders and validator
│   ├── class-wpmcp-session.php       MCP sessions + legacy SSE message queue
│   ├── class-wpmcp-rate-limiter.php  per-user sliding window
│   ├── class-wpmcp-logger.php        rolling activity log
│   ├── class-wpmcp-seo.php           SEO adapter (Yoast / Rank Math / SEOPress / custom)
│   ├── class-wpmcp-abilities.php     core Abilities API mirror + MCP Adapter handoff
│   ├── class-wpmcp-admin.php         settings screen
│   └── tools/
│       ├── class-wpmcp-tools-content.php
│       ├── class-wpmcp-tools-taxonomy.php
│       ├── class-wpmcp-tools-media.php
│       ├── class-wpmcp-tools-site.php
│       ├── class-wpmcp-tools-comments.php
│       └── class-wpmcp-tools-maintenance.php
└── bridge/                           optional TypeScript stdio bridge
    ├── package.json
    ├── tsconfig.json
    └── src/index.ts
```

---

## Requirements

| | Minimum | Notes |
|---|---|---|
| WordPress | 6.4 | 6.9+ additionally registers abilities in core |
| PHP | 7.4 | tested on 8.2 and 8.4 |
| HTTPS | required in production | local environments are exempt |
| Composer | not needed | — |
| npm | only for the optional bridge | Node 18+ |

---

## Install and activate

**Upload the folder:**

```bash
rsync -av wp-mcp-connector/ user@host:/path/to/wp-content/plugins/wp-mcp-connector/
```

Or zip it and use **Plugins → Add New → Upload Plugin**.

**Activate**, then open **Settings → MCP Connector**.

The server ships **switched off** and on the least-privileged profile. Nothing is reachable until you enable it.

### Verify it is running

```bash
curl -u 'USERNAME:APP_PASSWORD' https://example.com/wp-json/mcp/v1/health
```

A healthy response reports the WordPress and PHP versions, which auth method was used, the active profile, and the exact list of tools that user can currently call.

---

## Configure

**Settings → MCP Connector.**

### Permission profiles

A profile decides which tools are *offered*. The connected WordPress user still needs the matching capability, so a profile can never grant more than the account already has.

| Profile | Tools | Use for |
|---|---|---|
| **Read only** | 12 | research, audits, reporting |
| **Author** | 18 | drafting and editing content, uploading media |
| **Editor** | 22 | the above plus trashing content and moderating comments |
| **Administrator** | 25 | everything, including permanent deletion and option writes |
| **Custom** | your choice | tick exactly the tools you want |

The default is **Author**: it can create and update content but cannot delete anything, cannot touch options, and cannot run the CLI emulator.

### Other settings worth setting deliberately

- **Required capability** — the floor for reaching the endpoints at all. Default `edit_posts`.
- **Rate limit** — default 120 tool calls per 60 seconds per user. An agent stuck in a retry loop hits this instead of your database.
- **Readable and writable options** — an allowlist. `wp_get_option` and `wp_update_option` refuse everything not on it, and the refusal names what is allowed.
- **Media download hosts** — leave empty to allow any public host. Private and loopback addresses are always blocked regardless.
- **Legacy SSE transport** — each open stream holds a PHP worker for the configured duration. Turn it off unless a client needs it.

---

## Generate an Application Password

Application Passwords are built into WordPress and are the recommended credential. They are per-application, revocable individually, and never expose the account's real password.

1. **Users → Profile** (or **Users → All Users → edit the user**).
2. Scroll to **Application Passwords**.
3. Enter a name that identifies the client, for example `Claude Desktop, work laptop`.
4. Click **Add New Application Password**.
5. Copy the value shown. It looks like `abcd EFGH ijkl MNOP qrst UVWX` and **is displayed once only**.

The spaces are cosmetic and WordPress ignores them. Use it as the HTTP Basic password with the account's **username** (not email):

```bash
curl -u 'remy:abcdEFGHijklMNOPqrstUVWX' https://example.com/wp-json/mcp/v1/health
```

**Create a dedicated account for this.** An account with the Author or Editor role, used only by the AI client, means a leaked credential cannot install plugins or read user emails, and revoking it does not disturb your own login.

> **If the Application Passwords section is missing:** WordPress hides it when the request is not over HTTPS and the environment type is not `local`. Fix the HTTPS, or set `WP_ENVIRONMENT_TYPE` to `local` in `wp-config.php` for local development.

### Bearer tokens (optional)

Required for hosted clients such as Grok, and useful for any client you want to hold on a shorter leash. Enable **Bearer tokens** in settings, then issue one at the bottom of the settings page: pick a user, a label, a scope and an expiry. The token is shown once and only a SHA-256 hash is stored.

Bearer tokens work on `/wp-json/mcp/v1/*` and nowhere else. That is deliberate, and it is the main reason to prefer them for anything you do not control: a token that also unlocked the whole REST API, including plugin installation and every ability registered by every other plugin, would be a far larger credential than the job needs. See [Connecting an administrator account safely](#connecting-an-administrator-account-safely).

---

## Credential storage

### What an Application Password is and is not

It is **not** your WordPress password. It is a separate 24-character credential that cannot be used at `wp-login.php`, does not reveal your real password, and can be revoked on its own without disturbing your login or any other client.

But be clear about what it *can* do. An Application Password authenticates against the **whole** REST API, not just this plugin's endpoints. If it belongs to an administrator, it can reach `/wp/v2/plugins` to install and activate plugins, `/wp/v2/users` to read every account's email address, and `/wp/v2/users/me/application-passwords` to mint more credentials — none of which this plugin's permission profiles constrain, because those routes are WordPress core's, not ours.

**So the single most effective thing you can do is not use an administrator account.** An Editor-role Application Password cannot touch `/wp/v2/plugins` at all: the capability check fails before your configuration is even consulted. Every tool in the Editor profile still works.

| Account role | Worst case if the credential leaks |
|---|---|
| Administrator | Full site compromise: plugin installation, user data, new credentials |
| Editor | Content and comments can be altered; no code execution, no user emails |
| Author | Its own drafts can be altered |

### Where the secret ends up

Ordinary MCP client setup writes the credential to disk in plaintext:

| Client | File | Format |
|---|---|---|
| Claude Code (`--scope user`) | `~/.claude.json` | plaintext JSON |
| Claude Code (`--scope project`) | `.mcp.json` | plaintext JSON, easy to commit by accident |
| Claude Desktop | `claude_desktop_config.json` | plaintext JSON |
| Cursor | `~/.cursor/mcp.json` | plaintext JSON |

A `claude mcp add … --header "Authorization: Basic $(…)"` command also lands in your shell history.

### Keychain-backed credentials (macOS)

The bridge can keep the secret in the login keychain instead, so no config file and no shell history ever contains it. Store it once:

```bash
node bridge/dist/index.js --save-credential \
  --url https://example.com/wp-json/mcp/v1/mcp \
  --user YOUR_USERNAME
```

`security` is spawned with the terminal attached and does its own no-echo, type-it-twice prompt. The secret therefore never passes through `argv` (where `ps` could see it), the environment, a Node string, or your history — it goes straight from your keyboard into the keychain.

From then on the client config holds only non-secret values:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/absolute/path/to/bridge/dist/index.js"],
      "env": {
        "WP_MCP_URL": "https://example.com/wp-json/mcp/v1/mcp",
        "WP_MCP_USERNAME": "YOUR_USERNAME"
      }
    }
  }
}
```

Verify, and remove, with:

```bash
node bridge/dist/index.js --probe --url https://example.com --user YOUR_USERNAME
node bridge/dist/index.js --delete-credential --url https://example.com --user YOUR_USERNAME
```

Credentials are stored under the service name `wp-mcp-bridge`, keyed by `username@host`, so one machine can hold several sites and several users without collision. They are visible and revocable in Keychain Access. An explicit `WP_MCP_APP_PASSWORD` or `WP_MCP_TOKEN` still wins when set, so CI and existing setups are unaffected.

On Linux and Windows the keychain flags are refused with an explanation; use environment variables from your OS credential store there.

### Keeping the secret out of shell history

If you do use the header approach, avoid pasting the credential on a command line:

```bash
read -rs -p 'App password: ' APW
claude mcp add --scope user --transport http wordpress \
  https://example.com/wp-json/mcp/v1/mcp \
  --header "Authorization: Basic $(printf '%s' "USERNAME:$APW" | base64)"
unset APW
```

`read -rs` does not echo and does not record. The credential still lands in `~/.claude.json`, so this is a partial measure — the keychain route above is the one that removes it entirely.

---

## Connect a client

Endpoint: `https://example.com/wp-json/mcp/v1/mcp`

### Claude Code

```bash
claude mcp add --transport http wordpress https://example.com/wp-json/mcp/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'USERNAME:APPPASSWORD' | base64)"
```

With a Bearer token instead:

```bash
claude mcp add --transport http wordpress https://example.com/wp-json/mcp/v1/mcp \
  --header "Authorization: Bearer wpmcp_xxxxxxxxxxxx_yyyy"
```

Scope it to your user config so it is available in every project:

```bash
claude mcp add --scope user --transport http wordpress https://example.com/wp-json/mcp/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'USERNAME:APPPASSWORD' | base64)"
```

Then check it:

```bash
claude mcp list
```

### Claude Desktop — custom connector (recommended)

**Settings → Connectors → Add custom connector**, then give it the endpoint URL. This is the path that needs no local Node install.

If the connector dialog does not offer a place to set an `Authorization` header, use the stdio bridge below instead — it attaches credentials for you.

### Claude Desktop — stdio bridge

Edit the config file:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/absolute/path/to/wp-mcp-connector/bridge/dist/index.js"],
      "env": {
        "WP_MCP_URL": "https://example.com/wp-json/mcp/v1/mcp",
        "WP_MCP_USERNAME": "remy",
        "WP_MCP_APP_PASSWORD": "abcd EFGH ijkl MNOP qrst UVWX"
      }
    }
  }
}
```

Restart Claude Desktop. The tools appear under the connector menu.

### Cursor

`~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (per project):

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://example.com/wp-json/mcp/v1/mcp",
      "headers": {
        "Authorization": "Basic BASE64_OF_USERNAME_COLON_APPPASSWORD"
      }
    }
  }
}
```

Produce the base64 value with:

```bash
printf '%s' 'USERNAME:APPPASSWORD' | base64
```

### Grok, and hosted clients generally

Grok connects to remote MCP servers as a custom connector: **Settings → Connectors → Add custom connector** (wording varies between Grok releases), then supply:

- **URL** — `https://example.com/wp-json/mcp/v1/mcp`

If Grok answers with an **OAuth Credentials Required** dialog, that is the expected path: enable OAuth (see [OAuth 2.1](#oauth-21-what-groks-custom-connector-dialog-wants) below) and it will either discover everything itself or take the five values listed there. **Never invent values for that dialog** — without OAuth enabled there is no authorization server to describe, and Save & Connect will fail.

If your Grok build accepts a header instead, use `Authorization: Bearer wpmcp_xxxxxxxxxxxx_yyyy`, not an Application Password. If it only speaks the older SSE style, enable **Legacy SSE transport** and point it at `https://example.com/wp-json/mcp/v1/sse`.

Grok will not accept a plain-HTTP endpoint. HTTPS is mandatory for any hosted client.

**Never give a hosted client an Application Password.** A hosted connector is a different trust decision from a local one: the credential has to be stored on a third party's servers, where you cannot revoke it from your own machine or see how it is held. Bearer tokens are built for exactly this case:

| | Application Password | Bearer token |
|---|---|---|
| Reaches `/wp/v2/plugins`, `/wp/v2/users`, `/wp/v2/settings` | **yes** | **no — 401** |
| Reaches `/wp-abilities/v1/` | yes | no — 401 |
| Reaches `/mcp/v1/` | yes | yes |
| Can be given an expiry | no | yes |
| Can be scoped to fewer tools than the site profile | no | yes |

A Bearer token is refused on every WordPress route except this plugin's. So even a token bound to an administrator cannot install a plugin, read user emails, or mint further credentials — the tools you expose are the entire reachable surface.

### OAuth 2.1 (what Grok's "Custom Connector" dialog wants)

Hosted clients increasingly refuse static credentials: the MCP specification expects a remote server to be an OAuth 2.1 authorization server, advertised through discovery documents. This plugin is one. Turn it on under **Settings → MCP Connector → Authentication → OAuth 2.1**.

**Most clients need nothing typed at all.** With OAuth enabled, a 401 from the MCP endpoint carries a `WWW-Authenticate` header pointing at `/.well-known/oauth-protected-resource`, the client follows it to `/.well-known/oauth-authorization-server`, registers itself, and opens the consent screen. Paste the endpoint URL, click through the WordPress approval, done.

If a client asks you to fill the fields in by hand:

| Field | Value |
|---|---|
| Client ID | from **Settings → MCP Connector → OAuth clients** (register the client's redirect URI there first) |
| Client Secret | **leave empty** — public client, PKCE only, no secret exists |
| Authorization Endpoint | `https://example.com/mcp-oauth/authorize` |
| Token Endpoint | `https://example.com/mcp-oauth/token` |
| Scopes | one of `mcp:read_only`, `mcp:author`, `mcp:editor`, `mcp:admin` |
| Token Auth Method | `none (PKCE only)` |

To get a Client ID by hand: **Settings → MCP Connector → OAuth clients**, enter a name and the **exact** redirect URI the client shows you, and register. Redirect URIs are matched character for character — prefix matching is how open redirects get built, so a near miss is refused.

**What happens when you connect:** the client sends you to a consent screen on your own site. It names the application, the WordPress account you are signed in as, the access level, how many tools that grants, and lists any destructive ones explicitly. Approving mints a token; declining sends the client away with nothing. Every approval appears under **Authorized connections**, and revoking one kills its access token immediately — not at the end of the hour.

**Scopes map onto the permission profiles**, so `mcp:author` produces exactly the 18-tool Author surface, and the same narrowing rule applies: a scope can never exceed the site profile.

What is deliberately not implemented: implicit grant, password grant, client credentials grant, and `plain` PKCE. OAuth 2.1 removes the first three; the fourth is downgrade bait. Authorization codes are single use with a 90-second life, refresh tokens rotate on every use, and access tokens last an hour.

### Connecting an administrator account safely

If you want the AI to act as an administrator — because you want it managing options, running the CLI emulator, or working across other people's content — do it like this rather than with an admin Application Password:

1. Enable **Bearer tokens** in **Settings → MCP Connector**.
2. Issue a token for the admin user, with a **scope** and an **expiry**.
3. Give the token to the connector. Never give a hosted client an Application Password.

A scope narrows a token to fewer tools than the site profile allows, and **can never widen it**. Both gates are evaluated, so the effective surface is the intersection:

```
site profile = admin  +  token scope = author   →  18 tools
site profile = admin  +  token scope = read_only →  12 tools
site profile = read_only + token scope = admin   →  12 tools   (the token cannot widen)
```

That lets one administrator account serve several clients at different levels: your local editor unscoped, a hosted connector scoped to `author`, a reporting integration scoped to `read_only`. `/wp-json/mcp/v1/health` reports the scope actually in force, and a tool refused by scope says so by name:

> The tool "wp_run_cli_command" is outside the scope of the credential you are using ("grok-readonly", scoped to read_only). Do not retry.

**Also worth doing for a hosted client:**

- **Set an expiry.** 30 or 90 days. A forgotten connector then stops working instead of remaining a live key indefinitely.
- **Lower the rate limit.** The default 120/60s is sized for an interactive local editor.
- **Watch the activity log** for the first few days. Every call is recorded with the tool, the acting user, and the auth method.
- **Revoke rather than reconfigure** if anything looks wrong. Revocation is immediate and affects only that one client.

### Any other MCP client

Streamable HTTP, `POST` to `/wp-json/mcp/v1/mcp`, `Authorization` header, JSON-RPC 2.0 body. Sessions use the `Mcp-Session-Id` response header from `initialize`, echoed back on subsequent requests. `DELETE` the same URL to end a session.

---

## The stdio bridge

A dependency-free TypeScript proxy: stdin/stdout JSON-RPC in, authenticated HTTPS out. Use it when a client only speaks stdio, or when attaching an `Authorization` header in the client UI is awkward.

```bash
cd bridge
npm install     # devDependencies only: typescript and @types/node
npm run build
```

Test the connection before wiring it into anything:

```bash
WP_MCP_URL=https://example.com/wp-json/mcp/v1/mcp \
WP_MCP_USERNAME=remy \
WP_MCP_APP_PASSWORD='abcd EFGH ijkl MNOP qrst UVWX' \
node dist/index.js --probe
```

```
Connected to: Example Site (https://example.com/)
WordPress:    7.0.3 on PHP 8.2.29
Plugin:       1.0.0
Authenticated as: remy (administrator) via application-password
Profile:      author
Tools:        18 of 25 available
Abilities API: yes
```

| Variable | Purpose |
|---|---|
| `WP_MCP_URL` | Endpoint. A bare domain is expanded to the full REST path. |
| `WP_MCP_USERNAME` | WordPress username. |
| `WP_MCP_APP_PASSWORD` | Application Password. Spaces are stripped. |
| `WP_MCP_TOKEN` | Bearer token, as an alternative to the two above. |
| `WP_MCP_TIMEOUT_MS` | Request timeout. Default 60000. |
| `WP_MCP_VERBOSE` | `1` to log every message to stderr. |

If neither the password nor the token is set, the bridge looks in the macOS keychain before giving up. See [Credential storage](#credential-storage).

Flags `--url`, `--user`, `--password`, `--token`, `--probe`, `--verbose`, `--save-credential` and `--delete-credential` override the environment, so one install can serve several sites.

**Required packages:** none at runtime. The bridge uses only Node 18+ built-ins. `typescript` and `@types/node` are devDependencies for the build.

---

## Tool reference

25 tools. Each is gated by a WordPress capability *and* by the active profile.

### Content

| Tool | Capability | Profiles |
|---|---|---|
| `wp_list_posts` | `edit_posts` | read_only + |
| `wp_get_post` | `edit_posts` | read_only + |
| `wp_search_content` | `edit_posts` | read_only + |
| `wp_create_post` | `edit_posts` | author + |
| `wp_update_post` | `edit_posts` | author + |
| `wp_update_seo_meta` | `edit_posts` | author + |
| `wp_delete_post` | `delete_posts` | editor + |

`wp_create_post` and `wp_update_post` handle title, body, excerpt, status, slug, date, author, categories, tags, featured image, parent, menu order, page template, comment status and SEO metadata in one call. Missing categories and tags are created automatically.

### Taxonomy

| Tool | Capability | Profiles |
|---|---|---|
| `wp_list_terms` | `edit_posts` | read_only + |
| `wp_create_term` | `manage_categories` | author + |

### Media

| Tool | Capability | Profiles |
|---|---|---|
| `wp_list_media` | `upload_files` | read_only + |
| `wp_upload_media` | `upload_files` | author + |
| `wp_begin_media_upload` | `upload_files` | author + |
| `wp_append_media_chunk` | `upload_files` | author + |
| `wp_media_upload_status` | `upload_files` | author + |
| `wp_finish_media_upload` | `upload_files` | author + |
| `wp_update_media` | `upload_files` | author + |
| `wp_insert_media_into_post` | `edit_posts` | author + |
| `wp_rename_media` | `upload_files` | author + |
| `wp_delete_media` | `delete_posts` | admin |

**`wp_insert_media_into_post`** places an already-uploaded image into a post body as a real `core/image` block, at the end, at the start, or after a numbered paragraph. It is built on `parse_blocks`/`serialize_blocks` rather than string surgery, because splicing HTML into block markup by offset is how block comments get orphaned and the editor starts reporting recovery errors. Alt text comes from the attachment, and the response warns when there is none rather than silently inserting an image nobody can read.

**`wp_rename_media`** fixes the one image-SEO field that is genuinely hard to change later, because it is baked into the stored URL of the original and every generated size. It moves the original, every registered size, and sibling derivatives the theme builds from the same stem (`-social.jpg`, `-pin.jpg` here, filterable via `wpmcp_media_derivative_suffixes`), updates `_wp_attached_file`, the size metadata and the guid, and then rewrites any post content that referenced an old URL. Skipping that last step would leave a tidy filename and a broken image.

`wp_upload_media` accepts a URL or base64 data. URL fetches go through `wp_safe_remote_get`, which refuses loopback and private-network addresses, and are additionally checked against the host allowlist if one is configured. Uploads without alt text come back with a warning attached.

**Always pass `url`, not `base64_data`, for a real image.** This is the single most common way an image upload fails, and the failure is silent from the model's side. A modest 83 KB photograph base64-encodes to about 111 KB of text, which is roughly 28,000 tokens the client has to emit inside one tool call. Hosted clients cap tool arguments far below that, so the call dies mid-argument and the model tends to blame whatever it can see, typically the site being unreachable. With `url` the server does the download and the client sends a few dozen characters.

A 401 or 403 on a fetch returns `wpmcp_download_forbidden` with instructions not to retry, and a fetch that returns HTML rather than a file is reported as a login wall rather than as a success. The fetch sends a browser-shaped `User-Agent`, because many CDNs and object stores refuse the default `WordPress/x.y` agent outright, which looks from the caller's side exactly like the file not existing.

### Chunked upload, for images the client holds itself

An AI client that generates an image usually cannot publish it at a URL, and cannot base64 it either: a photograph exceeds the per-message output limit long before the call completes. That combination used to be a dead end, with the file having to travel through a human.

Three tools remove the dead end by splitting the transfer at the call boundary, where the limit actually lives:

```
wp_begin_media_upload    once   filename, title, alt_text, optional sha256,
                                optional post_id + set_featured
wp_append_media_chunk    xN     upload_id, chunk_index, ~24,000 chars of base64
wp_finish_media_upload   once   upload_id
```

Total tokens are unchanged, but no single message is oversized, so the transfer completes. A 123 KB photograph is seven chunks.

A fourth tool, `wp_media_upload_status`, reports where an interrupted transfer got to.

The design points worth knowing:

- **Chunk size is negotiable, not fixed.** Pass `chunk_characters` to `wp_begin_media_upload` and the server uses that size, anywhere from 4,000 to 200,000. This matters more than it sounds: a fixed size is a guess about someone else's output limit, and guessing too high makes every transfer fragile while guessing too low makes them all slow. A client that knows it truncates above 8,000 characters asks for 8,000 and becomes reliable; one that can emit 100,000 does the same file in two calls.
- **Corruption is caught at the chunk, not at the end.** Send `sha256` with each chunk and a truncated call is rejected on the spot, naming the index to resend. Without it a bad chunk is only discovered after every remaining chunk has been sent and the whole transfer fails.
- **A lost response is not a failure.** If the reply never arrives the client resends, which used to be an out-of-order error that killed the upload. A byte-identical resend of the chunk already stored is now acknowledged as a duplicate and changes nothing.
- **Losing your place is recoverable.** `wp_media_upload_status` returns the next index expected and the bytes already stored, so an interrupted client resumes rather than restarting.
- **Integrity is still checked end to end.** Supply `sha256` at the start and the assembled file is verified before anything is stored. Without it the file is still type-sniffed, so corrupt bytes cannot be published as an image.
- **Uploads are owned.** A session belongs to the account that opened it, expires after 30 minutes of inactivity, and is capped by `max_upload_bytes` and a 400-chunk ceiling.
- **One flow, not three.** Pass `post_id` and `set_featured` at the start and the finished image is attached and set as featured without further calls.

Verified on a real 123 KB photograph at a client-requested 8,000-character chunk size: 21 chunks, a deliberately truncated call rejected immediately as `wpmcp_chunk_corrupt`, a resent chunk accepted as a duplicate without corrupting the stream, a status query resuming correctly, and the assembled file byte-identical to the source by SHA-256.

Verified on a real 123 KB image: seven chunks, assembled file byte-identical to the source by SHA-256, out-of-order chunks refused, and a deliberately truncated upload rejected by checksum rather than stored.

### Site

| Tool | Capability | Profiles |
|---|---|---|
| `wp_get_site_info` | `read` | read_only + |
| `wp_list_plugins` | `activate_plugins` | read_only + |
| `wp_list_themes` | `switch_themes` | read_only + |
| `wp_list_users` | `list_users` | read_only + |

All four are read only. The plugin cannot activate, deactivate, install or update a plugin or theme by design: remote code installation driven by a language model is not a risk worth taking for the convenience.

### Comments

| Tool | Capability | Profiles |
|---|---|---|
| `wp_list_comments` | `moderate_comments` | read_only + |
| `wp_moderate_comment` | `moderate_comments` | editor + |
| `wp_reply_to_comment` | `moderate_comments` | editor + |

### Maintenance

| Tool | Capability | Profiles |
|---|---|---|
| `wp_get_site_health` | `manage_options` | read_only + |
| `wp_get_option` | `manage_options` | read_only + |
| `wp_flush_caches` | `manage_options` | editor + |
| `wp_update_option` | `manage_options` | admin |
| `wp_run_cli_command` | `manage_options` | admin |

### The CLI emulator

`wp_run_cli_command` is **not a shell**. It never calls `exec`, `proc_open` or the `wp` binary. Each supported command maps to a WordPress function call:

```
wp core version              wp plugin list               wp cache flush
wp core check-update         wp theme list                wp rewrite flush
wp option get <name>         wp user list                 wp transient delete --expired
wp post list                 wp db check                  wp site health
```

Anything else is refused with the list of what is available. Strings containing shell metacharacters (`;`, `|`, `&`, backticks, redirects) are rejected before parsing.

### Resources and prompts

Two resources — `wordpress://site/info` and `wordpress://content/recent` — and three prompts: `draft_post`, `seo_audit` and `comment_triage`.

### SEO metadata

The `seo` object on `wp_get_post`, `wp_create_post`, `wp_update_post` and `wp_update_seo_meta` uses one canonical vocabulary and writes through whichever SEO plugin the site runs: Yoast, Rank Math, SEOPress, or a detected custom plugin. Fields you omit are left untouched; an explicit empty string clears one.

```
seo_title  meta_description  canonical_url  robots_index  robots_follow
og_title   og_description    og_image
twitter_title  twitter_description  twitter_image  schema_type
```

`wp_get_site_info` reports which backend was detected.

---

## Security model

**Least privilege is the default.** The server ships disabled, on the Author profile, with Bearer tokens off and HTTPS required.

**Every tool call passes five gates, in order:**

1. Is the server enabled?
2. Is the transport secure, and is the `Origin` acceptable?
3. Did the request authenticate by header (not by cookie)?
4. Is the tool exposed under the active profile?
5. Does the acting user hold the tool's capability?

Then the arguments are validated against the tool's JSON Schema, and per-object checks (`current_user_can( 'edit_post', $id )`) run inside the tool itself.

**Cookie sessions are rejected.** WordPress already requires a nonce for cookie-authenticated REST writes, but an MCP endpoint that accepted a logged-in admin's browser session would be a CSRF surface with an unusually large blast radius. Only header credentials are accepted.

**Cross-origin browser requests are blocked.** Native MCP clients send no `Origin` header, so the check only ever fires for a browser — exactly the DNS-rebinding case worth stopping.

**No credentials are stored in the plugin.** Application Passwords live in WordPress core. Bearer tokens are stored as SHA-256 hashes and compared in constant time; the plaintext is shown once and never persisted.

**Rate limiting** is per acting user, sliding window, backed by the object cache. Exceeding it returns a `429` with a `retryAfter` the client can honour.

**SSRF protection** on media downloads comes from `wp_safe_remote_get`, plus an optional host allowlist.

**Nothing installs code.** No plugin or theme installation, activation, deactivation or update. No file writes outside the media library. No shell.

**Auditing.** The last 100 tool calls are recorded with the tool name, acting user, auth method, argument summary and result. Arguments whose names look like credentials are redacted before storage.

### Hardening in code

Pin configuration in an mu-plugin so it cannot be loosened from the admin screen:

```php
add_filter( 'wpmcp_settings', function ( $settings ) {
    $settings['profile']             = 'read_only';
    $settings['rate_limit_requests'] = 30;
    $settings['allowed_media_hosts'] = array( 'images.example.com' );
    return $settings;
} );
```

Or remove individual tools:

```php
add_filter( 'wpmcp_is_tool_enabled', function ( $enabled, $tool ) {
    return 'wp_run_cli_command' === $tool['name'] ? false : $enabled;
}, 10, 2 );
```

---

## Example prompts

Once connected, try:

- *"What's on this WordPress site? Give me the post types, how many published posts there are, and which SEO plugin it uses."*
- *"Find every published post that mentions pricing and tell me which ones are missing a meta description."*
- *"Draft a post on how to choose a supplier. Check what we already have on the topic first so it doesn't overlap. Leave it as a draft and give me the edit link."*
- *"Take post 142 and rewrite the introduction to be two sentences shorter. Keep all the block markup intact."*
- *"Go through the pending comment queue. Quote each one and tell me approve, spam or trash with a reason. Don't act on any of them yet."*
- *"Which images in the media library have no alt text? List them with their IDs and where they're used."*
- *"Set the SEO title and meta description on the five most recent posts. Show me each one before you write it."*
- *"Run the site health check and tell me what actually needs attention, in priority order."*
- *"List the active plugins and flag anything with an update waiting."*
- *"Upload https://example.com/photo.jpg as a new media item with alt text describing it, then set it as the featured image on post 88."*

Being explicit about what should **not** happen works well: *"don't publish anything"*, *"show me the wording first"*, *"trash it, don't delete it"*.

---

## Troubleshooting

**`401 Unauthorized`**
The credential did not resolve to a user. Check you are using the **username**, not the email address, and that the Application Password was copied in full. Run the bridge's `--probe`, or `curl -u 'user:pass' .../health`.

**`403` with "Browser cookie sessions cannot be used for MCP"**
The request carried a WordPress cookie but no `Authorization` header. Send the credential as a header.

**`503` "The MCP server is switched off"**
Enable it in **Settings → MCP Connector**.

**The Application Passwords section is missing from the profile screen**
WordPress hides it on non-HTTPS requests. Fix HTTPS, or set `WP_ENVIRONMENT_TYPE` to `local` in `wp-config.php` for local development.

**`Authorization` header never arrives (Apache with CGI/FastCGI)**
Add to `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

The plugin already reads `REDIRECT_HTTP_AUTHORIZATION` and `apache_request_headers()`, so this is only needed on stubborn setups.

**A tool is missing from `tools/list`**
Two possible reasons, both intentional: the profile does not expose it, or the connected user lacks its capability. `/wp-json/mcp/v1/health` lists exactly what that user can currently see.

**The SSE stream connects but no replies arrive**
Check that a reverse proxy is not buffering. The plugin sends `X-Accel-Buffering: no` for nginx; other proxies may need their own directive. Streamable HTTP has no such issue, so prefer it.

**Changes do not show on the front end after `wp_flush_caches`**
That tool clears the object cache and expired transients only. Page cache plugins, CDNs and PHP opcache are untouched and need their own purge.

---

## Extending

Add tools without forking:

```php
add_filter( 'wpmcp_tool_providers', function ( $providers ) {
    $providers[] = new My_Custom_Tools();
    return $providers;
} );

class My_Custom_Tools {
    public function register( WPMCP_Registry $registry ) {
        $registry->add( array(
            'name'         => 'my_export_orders',
            'title'        => 'Export recent orders',
            'description'  => 'Return the last N WooCommerce orders as structured data. Use this for revenue questions rather than reading order pages one at a time.',
            'group'        => 'content',
            'capability'   => 'manage_woocommerce',
            'profiles'     => array( 'editor', 'admin' ),
            'annotations'  => array( 'readOnlyHint' => true ),
            'callback'     => array( $this, 'export_orders' ),
            'input_schema' => WPMCP_Schema::object( array(
                'days' => WPMCP_Schema::integer( 'How many days back to look.', 1, 365 ),
            ) ),
        ) );
    }

    public function export_orders( array $args ) {
        // Return an array, or a WP_Error whose message tells the model how to fix the call.
        return array( 'orders' => array() );
    }
}
```

A tool registered this way is automatically exposed over both transports, mirrored into the Abilities API, rate limited, capability checked, schema validated and logged.

### Filters

| Filter | Purpose |
|---|---|
| `wpmcp_settings` | Pin effective configuration. |
| `wpmcp_is_tool_enabled` | Allow or deny an individual tool. |
| `wpmcp_tool_providers` | Register custom tool classes. |
| `wpmcp_exposed_post_types` | Control which post types content tools accept. |
| `wpmcp_allowed_option_keys` | Extend the option allowlist. |
| `wpmcp_allowed_origins` | Permit additional browser origins. |
| `wpmcp_seo_backend` | Force a specific SEO backend. |

---

## Writing good tool descriptions

The `description` field is what the model reasons over when deciding whether to call a tool. The descriptions in this plugin follow three rules that are worth keeping if you add your own:

1. **Say when to use it and when not to.** *"Reach for this rather than listing everything when you are looking for where a topic is mentioned."*
2. **Warn about consequences in the description, not just the annotation.** *"There is no trash for media, so this cannot be undone."*
3. **Point at the tool that should have come first.** *"Always call this before editing something, so that your update preserves the existing block markup."*

Error messages follow the same principle: they say what went wrong, what is allowed instead, and whether retrying is worth it.

---

## Licence

Copyright (C) 2026 Remy Mazmanian.

Released under **GPL-2.0-or-later**, matching WordPress. The full text is in
[`LICENSE`](LICENSE).

You are free to use, modify and redistribute this plugin, including
commercially. In exchange the licence asks two things of anyone who passes it
on:

- **Keep the copyright notice intact and visible** on every copy you
  redistribute, in the source headers and in `LICENSE` (GPL-2.0 §1).
- **Mark what you changed.** Modified files must carry prominent notices saying
  they were changed, and the date (GPL-2.0 §2a).

Derivative works must also be distributed under the same licence (§2b). If you
build something on top of this, a line crediting the original and linking back
is appreciated beyond what the licence strictly requires.
