# Releasing Blocksmith

This guide covers cutting a release of the Blocksmith WordPress plugin (and the companion Claude Code plugin).

## 0. What ships

The distributed plugin zip contains only: `blocksmith.php`, `readme.txt`, `inc/`, `build/` (controlled by the `files` field in `package.json`). Dev files (`src/`, `node_modules/`, `clients/`, `.gitignore`, `docker-compose.yml`, `README.md`, `package*.json`) are **not** shipped.

## 1. Bump the version (keep these three in sync)

The version must match in all three places or WordPress.org will reject the release:

- `blocksmith.php` header — `Version: X.Y.Z`
- `readme.txt` — `Stable tag: X.Y.Z`
- `package.json` — `"version": "X.Y.Z"`

Also update `readme.txt` → `Tested up to:` to the latest WordPress version you've tested against, and add a `== Changelog ==` entry.

## 2. Build

```bash
npm ci
npm run build        # compiles src/index.js + src/admin.js -> build/
```

## 3. Pre-flight checks

```bash
npm run lint:js                       # JS lint
# PHP lint (each file)
find inc blocksmith.php -name '*.php' -exec php -l {} \;
```

Run **Plugin Check** against the packaged file set (this is what reviewers run). Locally via the Docker dev env:

```bash
docker compose run --rm --user 33:33 -e HOME=/tmp wpcli \
  wp plugin check blocksmith --format=csv \
  --exclude-files=.gitignore,README.md,docker-compose.yml,package.json,package-lock.json,blocksmith.zip \
  --exclude-directories=node_modules,src,clients
```

Expected: `No errors found.` Fix anything reported before continuing.

Manual smoke test on a clean WordPress 7.0 site:
1. Activate the plugin; confirm no fatal and the admin notice behaves (shown only until a Site Brief exists / MCP Adapter missing).
2. Settings: open **Blocksmith → Generate from my site → Save**.
3. Editor: open the **Blocksmith** sidebar; generate a section, a full page, and fill a pattern.
4. Toolbar: select a block and **Refine**.

## 4. Package

```bash
npm run plugin-zip   # -> blocksmith.zip
unzip -Z1 blocksmith.zip   # verify: only blocksmith.php, readme.txt, inc/**, build/** (no hidden/dev files)
```

## 5. Tag + GitHub release

```bash
git tag -a vX.Y.Z -m "Blocksmith vX.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z blocksmith.zip --title "vX.Y.Z" --notes "…changelog…"
```

## 6. WordPress.org submission (first release)

1. Submit the plugin for review at https://wordpress.org/plugins/developers/add/ (upload `blocksmith.zip`). First review is manual.
2. **Disclosure (required):** Blocksmith sends page context and prompts to the AI provider the user configures under Settings → Connectors. Add a "uses an external service" section to `readme.txt` describing this (what data is sent, to which provider, and their terms/privacy links) before submitting — reviewers require this for plugins that contact third-party services.
3. Once approved you get an SVN repo. Release flow:
   ```bash
   svn co https://plugins.svn.wordpress.org/blocksmith blocksmith-svn
   # copy the packaged files into trunk/ (the zip's contents, not the zip)
   rsync -a --delete --exclude='.svn' <unzipped blocksmith>/ blocksmith-svn/trunk/
   cd blocksmith-svn
   svn add --force trunk/*
   svn cp trunk tags/X.Y.Z
   svn ci -m "Release X.Y.Z"
   ```
4. **Assets** (not in the plugin zip) go in the SVN `assets/` dir: `icon-256x256.png`, `banner-772x250.png`, `screenshot-1.png` … (referenced in readme's `== Screenshots ==`).
5. Confirm the live `Stable tag` in trunk `readme.txt` matches the tag you created.

### Notes for WP.org
- The **MCP Adapter** is an optional dependency (GitHub-distributed). Do **not** add a `Requires Plugins` header for it — that would block activation. The graceful `class_exists` check + admin notice is correct; document the manual install in the readme.
- No secrets ship in the repo; keys are the user's, held by core Connectors.

## 7. Claude Code plugin (companion, in `clients/claude-code/`)

This is distributed separately from the WP plugin (it's not in the zip).

1. Keep `clients/claude-code/.claude-plugin/plugin.json` `version` in sync with the WP plugin (or version it independently).
2. Users install it from this repo:
   ```
   /plugin marketplace add invocation97/blocksmith-plugin
   /plugin install blocksmith@blocksmith
   ```
   For that to resolve, ensure a `.claude-plugin/marketplace.json` is discoverable at the path the marketplace points to (currently under `clients/claude-code/`). For public GitHub install you may move/copy the marketplace manifest to the repo root, or host the Claude plugin in its own repo.
3. It requires the site to have Blocksmith + the MCP Adapter active and an Application Password.

## 8. Post-release

- Install `blocksmith.zip` on a fresh WP 7.0 site (`wp plugin install blocksmith.zip --activate`) and re-run the smoke test.
- Bump to the next dev version.

## Quick checklist

- [ ] Version synced in `blocksmith.php`, `readme.txt` (Stable tag), `package.json`
- [ ] `readme.txt` Tested up to + Changelog updated
- [ ] `npm ci && npm run build`
- [ ] Lint + `php -l` clean
- [ ] Plugin Check: No errors found
- [ ] Manual smoke test on WP 7.0
- [ ] `npm run plugin-zip` + verified zip contents
- [ ] External-service disclosure in `readme.txt` (WP.org)
- [ ] Git tag + GitHub release with `blocksmith.zip`
- [ ] WP.org trunk + tag committed; assets uploaded
