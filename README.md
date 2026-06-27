# Invocation

Build styled Gutenberg page content with AI — grounded in your own block theme.

Invocation is a self-hosted, open-source WordPress plugin. It uses **WordPress 7.0 core** for everything provider-related (you connect your own OpenAI / Anthropic / Google key once under **Settings → Connectors**), and focuses on the one thing core doesn't do: turning your site's actual registered blocks and `theme.json` design tokens into valid, on-theme Gutenberg layouts.

No external service, no database, no Docker. Bring your own AI key; everything runs on your WordPress server.

## Requirements

- WordPress **7.0+** (for the Abilities API, the PHP AI Client, and Connectors)
- PHP **8.1+**
- At least one AI provider configured under **Settings → Connectors**

## How it works

Invocation registers a set of **Abilities** (the WordPress 7.0 capability primitive):

| Ability | Purpose |
| --- | --- |
| `invocation/get-theme-context` | Reads color palette, typography, and layout sizes from `theme.json`. |
| `invocation/list-blocks` | Lists the block types registered on the site. |
| `invocation/generate-layout` *(planned)* | Composes a validated, on-theme Gutenberg layout from a prompt. |

Because these are abilities, they are automatically:

- validated against their JSON schemas,
- exposed over the REST API, and
- surfaced through the core **MCP Adapter** — so external agents like **Claude Code** can drive Invocation with no extra transport code.

## Status

Early development. The original metered-SaaS prototype lived in a separate repo; this is a clean rewrite for the WordPress 7.0 AI era.

## License

GPL-2.0-or-later
