# Invocation — Claude Code plugin

Drive your WordPress site's [Invocation](https://github.com/invocation97/invocation) tools from Claude Code: generate, fill, and refine on-theme Gutenberg layouts, and read your site's theme tokens, patterns, media, and internal links.

It connects to Invocation's MCP server, which is provided by the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).

## Prerequisites (on the WordPress site)

1. WordPress 7.0+ with an AI provider configured under **Settings → Connectors**.
2. The **Invocation** plugin installed and active.
3. The **MCP Adapter** plugin installed and active — this exposes Invocation's tools over MCP at `/wp-json/invocation/mcp`.
4. An **Application Password** for your user: *Users → Profile → Application Passwords*.

## Install the Claude Code plugin

```bash
# Add this directory (or repo) as a marketplace, then install
/plugin marketplace add invocation97/invocation
/plugin install invocation@invocation
```

For local development you can point the marketplace at this folder directly:

```bash
/plugin marketplace add /path/to/blocksmith-plugin/clients/claude-code
```

At install you'll be prompted for three values (used by the connection):

- `WP_API_URL` — `https://your-site.com/wp-json/invocation/mcp`
- `WP_API_USERNAME` — your WordPress username
- `WP_API_PASSWORD` — the Application Password you created

## How it connects

By default this plugin uses the official [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) proxy (run via `npx`), which bridges Claude Code to the WordPress MCP endpoint using Application Password auth. This is the most broadly compatible option and needs Node.js available locally.

### Alternative: direct HTTP

If you prefer no local proxy, you can connect Claude Code straight to the endpoint by replacing `.mcp.json` with:

```json
{
  "mcpServers": {
    "invocation": {
      "type": "http",
      "url": "${WP_SITE_URL}/wp-json/invocation/mcp",
      "headers": { "Authorization": "Basic ${WP_AUTH}" },
      "env": { "WP_SITE_URL": "", "WP_AUTH": "" }
    }
  }
}
```

where `WP_AUTH` is base64 of `username:application-password`. If your site doesn't use pretty permalinks, use `${WP_SITE_URL}/?rest_route=/invocation/mcp` instead.

## Tools

`invocation-generate-layout`, `invocation-refine-block`, `invocation-list-patterns`, `invocation-search-media`, `invocation-search-internal-links`, `invocation-get-theme-context`, `invocation-list-blocks`, `invocation-gather-site-context`.

Try: `/invocation:build-section a pricing section with three plan cards`
