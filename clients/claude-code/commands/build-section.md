---
description: Generate an on-theme WordPress section with Blocksmith
---

Use the `blocksmith` MCP server's `blocksmith-generate-layout` tool to build the following, defaulting to `scope: "section"`:

$ARGUMENTS

If the request matches an existing section pattern, first call `blocksmith-list-patterns`, choose the best fit, and use `scope: "fill-from-pattern"` with its `patternName`. Return the resulting Gutenberg block markup and a short summary of what you built and which theme tokens / pattern it used.
