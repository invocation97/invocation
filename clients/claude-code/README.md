# Invocation — Claude Code plugin

Drive your WordPress site's [Invocation](https://github.com/invocation97/invocation) tools from Claude Code: generate, fill, and refine on-theme Gutenberg layouts, look up your theme tokens, patterns, media, and internal links, and create/update pages — all over MCP.

## Prerequisites (on the WordPress site)

1. WordPress **7.0+** with an AI provider configured under **Settings → Connectors**.
2. The **Invocation** plugin installed and active. The MCP server is **built in** — there is nothing else to install (the MCP Adapter ships inside Invocation).
3. An **Application Password** for your user: *Users → Profile → Application Passwords*.

The MCP endpoint is:

```
https://YOUR-SITE/wp-json/invocation/mcp
```

If your site doesn't use pretty permalinks, use `https://YOUR-SITE/?rest_route=/invocation/mcp` instead.

## Install the Claude Code plugin

```bash
/plugin marketplace add invocation97/invocation
/plugin install invocation@invocation
```

At install you'll be prompted for `WP_API_URL`, `WP_API_USERNAME`, and `WP_API_PASSWORD` (your Application Password). The bundled `.mcp.json` uses the official `@automattic/mcp-wordpress-remote` proxy, which handles auth and works regardless of the client's transport support.

## Connecting from a local WordPress site

Local sites (Local by Flywheel, wp-env, a Docker stack, etc.) work the same way — point the endpoint at the local URL. Two things to know:

1. **Application Passwords require HTTPS or a "local" environment.** Local by Flywheel serves HTTPS out of the box (use the `https://…` site URL). On a plain-HTTP local box, app passwords are disabled by default; either enable HTTPS, or, for development only, allow them with a tiny mu-plugin: `add_filter( 'wp_is_application_passwords_available', '__return_true' );`
2. **Permalinks.** If the local site uses plain permalinks (no rewrite rules), use the `?rest_route=` form of the URL.

### Option A — direct HTTP (no Node)

```bash
claude mcp add --transport http invocation \
  "https://my-site.local/wp-json/invocation/mcp" \
  --header "Authorization: Basic $(printf 'USERNAME:APP PASSWORD' | base64)"
```

Then restart Claude Code and run `/mcp` — you should see `invocation` connected.

### Option B — the proxy (this plugin's default)

Set the `env` values in `.mcp.json` (or when prompted):

```json
{
  "mcpServers": {
    "invocation": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://my-site.local/wp-json/invocation/mcp",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your application password"
      }
    }
  }
}
```

### Local TLS note

Local by Flywheel uses a self-signed certificate. If the proxy fails to connect over HTTPS, either trust the certificate in your OS keychain, or (dev only) start Claude Code with `NODE_TLS_REJECT_UNAUTHORIZED=0`. Alternatively, use the site's HTTP URL with the `?rest_route=` form.

### Multiple local sites

Give each server a distinct name (`invocation-clienta`, `invocation-clientb`, …) so you can target the right site.

## Tools

`invocation-generate-layout`, `invocation-refine-block`, `invocation-list-patterns`, `invocation-search-media`, `invocation-search-internal-links`, `invocation-get-theme-context`, `invocation-list-blocks`, `invocation-gather-site-context`, `invocation-create-page`, `invocation-update-page`.

Try: `/invocation:build-section a pricing section with three plan cards`
