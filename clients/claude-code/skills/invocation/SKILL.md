---
description: Build and edit WordPress page content on a connected site using Invocation. Use when the user wants to generate Gutenberg sections or full pages, fill a section pattern, refine a block, or look up the site's theme tokens, patterns, media, or internal links.
---

# Invocation for WordPress

The `invocation` MCP server connects to a WordPress site running the Invocation plugin. Use its tools to build valid, on-theme Gutenberg block markup grounded in the site's own theme, patterns, media and links.

## Tools

- `invocation-generate-layout` — build a section or full page. Key inputs: `prompt`, `scope` (`section` | `full-page` | `fill-from-pattern`), `patternName` (for fill), `tone`.
- `invocation-refine-block` — revise existing block markup. Inputs: `blockMarkup`, `instruction`.
- `invocation-list-patterns` — list the site's section patterns (title, categories, blocks used).
- `invocation-search-media` — find real images in the media library.
- `invocation-search-internal-links` — get real internal URLs (pages/posts).
- `invocation-get-theme-context` — theme colors, fonts, layout sizes.
- `invocation-list-blocks` — registered block types (incl. custom blocks).
- `invocation-gather-site-context` — (re)build the site's brand brief.

## Workflow

1. To build from an existing design, call `invocation-list-patterns`, pick a fitting pattern, then `invocation-generate-layout` with `scope: "fill-from-pattern"` and that `patternName`.
2. To build from scratch, call `invocation-generate-layout` with `scope: "section"` (or `"full-page"`).
3. The result is Gutenberg block markup. Present it to the user to paste into the editor, or save it to a page if a page-writing tool is available.

All tools return real, on-theme data — never invent block names, image URLs, or internal links; use what the tools return.
