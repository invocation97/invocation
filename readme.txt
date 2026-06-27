=== Blocksmith ===
Contributors: invocation97
Tags: ai, gutenberg, blocks, patterns, content
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build styled Gutenberg content with AI, grounded in your own block theme and patterns. Bring your own AI key via WordPress Connectors.

== Description ==

Blocksmith is a self-hosted, open-source AI assistant for the WordPress block editor. It uses WordPress 7.0 core for everything provider-related — connect your own OpenAI, Anthropic, or Google key once under Settings → Connectors — and focuses on the part core does not do: turning your site's registered blocks, theme.json design tokens, and section patterns into valid, on-theme Gutenberg layouts.

Features:

* Generate a section or a full page from a prompt, grounded in your theme.
* Fill a chosen section pattern with real content.
* Refine any block in place from the editor toolbar.
* Uses real media and real internal links — never invented URLs.
* A Site Brief that keeps every generation on-brand.

Everything runs on your server. No external service, database, or Docker required. Capabilities are registered as WordPress Abilities, so they are also available over REST and to MCP agents.

== Installation ==

1. Ensure you are running WordPress 7.0 or later and have configured an AI provider under Settings → Connectors.
2. Upload the plugin to the `wp-content/plugins/blocksmith` directory, or install it through the Plugins screen.
3. Activate the plugin through the Plugins screen.
4. Open Blocksmith from the admin menu and generate your Site Brief.

== Changelog ==

= 0.1.0 =
* Initial release.
