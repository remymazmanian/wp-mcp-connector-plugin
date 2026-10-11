# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] - 2026-10-11

Security release. Update as soon as you can. Your settings, tokens and OAuth clients carry over, and nothing needs migrating.

### Security

- **Option writes.** `wp_update_option` and `wp_get_option` now refuse options that could take over the site, even when the allowlist is `*` or names them exactly. That covers the site address, the admin email, user roles, open registration, the default role, active plugins and themes, auto-updates, cron, upload paths, this plugin's own settings, and any option whose name looks like a secret, token, password, salt or key. Before, an allowlist of `*` let an Administrator-profile client change `siteurl` or `users_can_register`.
- **The Application Passwords setting is enforced.** Switching off **Allow Application Passwords** now blocks those requests. Before, they still authenticated.
- **Abilities API and MCP Adapter.** Abilities exposed through the WordPress REST API and the official MCP Adapter now go through the same checks as the plugin's own endpoint: HTTPS, Origin, the Application Passwords setting and the site capability. The adapter server is registered with the current adapter signature and its HTTP transport, using that same check.
- **OAuth dynamic client registration** is limited. It allows 10 registrations per IP per hour (filter `wpmcp_dcr_rate_limit`), at most 10 redirect URIs per client, capped lengths for URIs and names, and a 64 KB request body. Dynamically registered clients older than a week with no active grant are removed, and new registrations stop while 500 remain.
- **Refresh token reuse is detected.** Presenting a refresh token that was already rotated now revokes the whole grant, as OAuth 2.1 recommends for public clients. A client that retries a lost response within 2 minutes still gets the same new tokens back. The window is set by the `wpmcp_refresh_grace` filter, and 0 turns it off. A refresh token presented by a different client is refused.
- **OAuth consent page** can no longer be framed by another site, which blocks clickjacking of the Approve button.
- **OAuth discovery documents** at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` are served only while the OAuth server is switched on.
- **Media downloads.** When **Media download hosts** is set, every redirect is checked against it, so an allowed host can no longer redirect a download to some other server. Downloads also stop at the upload size limit instead of reading the whole file into memory first.
- **Tokens issued from the admin screen** are created only for users the current admin is allowed to edit.
- **`wp_publish_article`** accepts only the post types this plugin exposes. Before, it could create other post types, such as templates and navigation menus.

### Added

- Support for MCP protocol revision 2025-11-25. Clients that ask for it get it, and older revisions keep working.
- `uninstall.php`. Deleting the plugin from the Plugins screen removes its settings, Bearer tokens, OAuth clients, grants and tokens, activity log, sessions, and any half-finished uploads, on every site of a multisite network. Deactivating still keeps them.
- `SECURITY.md` explaining how to report a vulnerability privately.
- End-to-end tests that run WordPress in Docker and drive the MCP endpoint and the OAuth server, plus CI that checks PHP syntax on 7.4 to 8.5, builds the bridge and runs the end-to-end tests.
- README entries for the `wpmcp_media_derivative_suffixes`, `wpmcp_dcr_rate_limit` and `wpmcp_refresh_grace` filters.

### Changed

- Legacy HTTP+SSE is off by default on new installs, because each open stream holds a PHP worker. Existing sites keep their current setting.
- `wp_rename_media` no longer moves `-social.jpg` and `-pin.jpg` siblings by default. Those suffixes came from one particular theme. On another site, a separate image with a matching name could be renamed by mistake. If your theme builds files like these, list their suffixes with the `wpmcp_media_derivative_suffixes` filter.
- Deactivating no longer flushes the whole object cache, which signed out users and slowed the site on hosts with Redis or Memcached. Open MCP sessions are still ended.
- The `MCP-Protocol-Version` header is checked. An unsupported version gets a `400` response, as the specification requires.

### Fixed

- Nested object arguments are validated against their schema, including required keys and types. Before, only top-level arguments were checked.
- Chunked uploads enforce their 4,000-call ceiling. The constant was defined but never checked.
- Responses carry the protocol version the session agreed on in their `MCP-Protocol-Version` header, instead of always the newest.
- `wp_publish_article` reports a failed in-article image given by attachment ID or upload ID correctly. It used to log a PHP warning and report the image as `null`.
- README description of chunked uploads: they resume from a character offset (`next_offset`), not a chunk index, accept chunk sizes from 500 to 400,000 characters, and expire after 6 hours of inactivity. The expired-upload error said 30 minutes and now says 6 hours.
- README and code comments called the rate limiter a sliding window. It is a fixed window.
- Missing translator comment on one translatable string.

## [1.0.1] - 2026-08-19

Compatibility release for WordPress 7.1, with corrected documentation. Updating needs no settings change and no migration.

### Added

- `public` flag on every ability the plugin registers. WordPress 7.1 uses this flag to mark abilities as available to external clients, and the official MCP Adapter reads it from its next release. `show_in_rest` stays in place, so sites on WordPress 6.9 and 7.0 keep exposing abilities as before.
- `Tested up to: 7.1` plugin header, so WordPress 7.1 no longer flags the plugin as untested.
- `NOTICE` file with the copyright notice and redistribution notes, shipped with the plugin, and copyright notices in the plugin and bridge source headers.
- Link in the README to the step-by-step connection guide on remymazmanian.com.

### Changed

- `LICENSE` now holds only the standard GPL-2.0 text, so GitHub detects the licence correctly. The licence itself is unchanged.

### Fixed

- Tool counts in the README, corrected to 32 tools in total: 12 for Read only, 25 for Author, 29 for Editor and 32 for Administrator. The README previously listed 25.
- Missing `wp_publish_article` entry in the README tool reference. It builds a finished post, including featured and in-article images, categories, tags and SEO metadata, in one call.
- README description of authentication, which now lists all three routes: Application Passwords, Bearer tokens and the built-in OAuth 2.1 server.

[Unreleased]: https://github.com/remymazmanian/wp-mcp-connector-plugin/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/remymazmanian/wp-mcp-connector-plugin/releases/tag/v1.0.2
[1.0.1]: https://github.com/remymazmanian/wp-mcp-connector-plugin/releases/tag/v1.0.1
