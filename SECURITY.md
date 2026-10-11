# Security policy

## Reporting a vulnerability

Please report security problems privately through GitHub: open the [Security tab](https://github.com/remymazmanian/wp-mcp-connector-plugin/security) and choose **Report a vulnerability**. Don't open a public issue for a vulnerability.

Include the plugin, WordPress and PHP versions, which transport and authentication method you used, what you found and, if you can, a request that reproduces it. You'll get a reply within a few days, and fixes are released as soon as they're ready.

## Supported versions

Security fixes go into the latest release.

## Scope

Especially welcome:

- authentication and authorization (`includes/class-wpmcp-auth.php`, `includes/class-wpmcp-rest.php`): ways to call tools without valid header credentials, with a cookie session, from a browser origin, or beyond the acting user's capabilities and profile
- the OAuth 2.1 server (`includes/class-wpmcp-oauth.php`): PKCE, redirect URI handling, dynamic client registration, consent, and access and refresh token handling
- tools that write (`includes/tools/`): option writes outside the allowlist, edits to posts the user can't edit, and media downloads that reach private addresses or hosts outside the allowlist
- the Abilities API and MCP Adapter integration (`includes/class-wpmcp-abilities.php`)
- the stdio bridge (`bridge/`): credential handling and anything it sends to a server other than the configured one
