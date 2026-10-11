# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/remymazmanian/wp-mcp-connector-plugin/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/remymazmanian/wp-mcp-connector-plugin/releases/tag/v1.0.1
