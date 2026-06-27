# Security Policy

## Reporting a vulnerability

Please report security issues privately rather than opening a public issue. Use GitHub's **Report a vulnerability** (Security → Advisories) on this repository, or email the maintainer. We aim to acknowledge reports within a few days.

## Supported versions

The latest released version receives security fixes.

## Trust model

- **Bring your own AI key.** Blocksmith never stores AI provider credentials. Keys live in WordPress 7.0 core Connectors (Settings → Connectors); generation goes through core's AI Client.
- **Capabilities.** Every ability enforces a `permission_callback`:
  - Read/context abilities require `edit_posts`.
  - Generative abilities (`generate-layout`, `refine-block`) require the capability returned by the `blocksmith_generation_capability` filter (default `edit_posts`) — raise it to limit who can spend the AI budget.
  - `create-page` checks the target post type's create capability; `update-page` checks `edit_post` for the specific post; `gather-site-context` (writes the Site Brief option) requires `manage_options`.
- **REST & MCP.** Abilities are exposed via the WordPress REST API and, optionally, the official MCP Adapter. Both use standard WordPress authentication (cookie + nonce, or Application Passwords). Unauthenticated requests are rejected, and each ability still re-checks its own capability.
- **Generated content.** AI/agent-supplied block markup is saved through `wp_insert_post` / `wp_update_post`, so it passes core KSES filtering for users without `unfiltered_html`.

## Known, contained considerations

- **Prompt content.** Site text (alt text, link/term titles, pattern content, published-post excerpts) is included in AI prompts. Model output is only ever returned as block markup (KSES-filtered on save) and is never executed in an automated tool loop, so the impact is limited to content quality.
- **Cost.** Generative abilities call your configured AI provider, which may be billed per request. Use the `blocksmith_generation_capability` filter and/or your own rate limiting if untrusted lower-privilege users have access.
