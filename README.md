# WP Theme JSON Editor

> A form-driven visual editor for WordPress theme.json files.

> [!WARNING]
>
> This is a developer tool. It writes the active theme's `theme.json` directly to disk via `WP_Filesystem`.
>
> - **No revisions, no backups.** Every Save overwrites the file in place.
> - **No undo across sessions.** Once you save and reload, the previous content is gone.
> - **No content validation beyond shape checks.** A malformed `theme.json` can break the front-end render and the Site Editor.
>
> Treat it like any other code change to your theme: keep the theme under version control (git or a deployment pipeline), and only edit on environments where you can roll back if something goes wrong.
> Do not run it on production without a recent backup of `wp-content/themes/{your-theme}/theme.json`.
> If you want a visual editor that *does* keep revisions, use the WordPress Site Editor (Appearance → Editor → Styles).
> It edits user-level Global Styles instead of the theme file.

## Description

WP Theme JSON Editor mounts a schema-driven form editor under **Appearance → Theme JSON Editor**, so the active block theme's `theme.json` can be edited from the WordPress admin without leaving the dashboard or hand-editing JSON.

The editor itself is the same React app that powers the [VS Code extension](https://github.com/s3rgiosan/vscode-wp-theme-json-editor) — sidebar navigation across `settings`, `styles`, `customTemplates`, `templateParts`, and `patterns`, inline schema validation, search, CSS-variable autocomplete, and optimistic-concurrency conflict detection.

The plugin only provides the WordPress integration: a host adapter that talks to the REST API, capability gating, and styling that follows the active admin colour scheme.

## Requirements

- WordPress 6.7 or later
- PHP 8.1 or later

## Installation

### Manual Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/s3rgiosan/wp-theme-json-editor/releases/latest).
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin area.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin.

### Install with Composer

To include this plugin as a dependency in your Composer-managed WordPress project:

1. Add the plugin to your project using the following command:

```bash
composer require s3rgiosan/wp-theme-json-editor
```

1. Run `composer install`.
2. Activate the plugin from your WordPress admin area or using WP-CLI.

## Quick Start

Once activated, visit **Appearance → Theme JSON Editor**:

1. Pick a section in the sidebar (Settings, Styles, Custom Templates, Template Parts, Patterns).
2. Edit fields in the main panel.
3. The save bar appears when there are unsaved changes; click **Save** to persist via REST.

If the file changes externally between load and save, a conflict banner with a **Reload** action surfaces instead of overwriting silently.

## Capabilities

| Action | Capability | Extra requirement |
|---|---|---|
| View the editor | `edit_themes` | — |
| Save changes | `edit_themes` | `theme.json` writable on disk |

Only roles with `edit_themes` (Administrator on a single site, Super Admin on multisite) see the **Appearance → Theme JSON Editor** menu and can hit the REST endpoints.

The editor is also disabled on production environments. The menu is hidden whenever `wp_get_environment_type()` returns `production`; the REST endpoints reject every request from those environments. Use it on `local`, `development`, or `staging`.

If the site sets `DISALLOW_FILE_EDIT` to `true` in `wp-config.php`, the editor will not be available. WordPress maps `edit_themes` to `do_not_allow` whenever that constant is on, which intentionally locks the plugin (and core's Theme/Plugin file editors) out.

## Admin Colour Scheme

Every accent colour in the editor (button background, link colour, list-active surface, badge background, focus ring, checkbox colour) is routed through `--wp-admin-theme-color` and its `-darker-10` shade exposed by `@wordpress/components`. Switching the user's admin colour scheme (Profile → Admin Color Scheme) changes the editor's accent automatically.

Surfaces (sidebar background, body text) stay neutral so they remain readable across all schemes.

## REST API

All routes live under `wp-theme-json-editor/v1`. Auth follows the standard WordPress REST cookie + `X-WP-Nonce` flow.

### `GET /schema`

Returns the official WordPress `theme.json` schema and the bundled core-scan snapshot of experimental + undocumented properties.

**Query**

- `version` *(string, default `auto`)* — pinned schema version (e.g. `6.7`, `trunk`) or `auto` to derive from `get_bloginfo('version')`.

**Caching**

The schema is fetched from `https://schemas.wp.org/wp/{version}/theme.json` once per 24 hours and stored in a transient. Network failures fall back to a bundled copy.

### `GET /document`

**Response**

```json
{
  "data": { "...theme.json..." },
  "etag": "abcd1234…",
  "path": "wp-content/themes/twentytwentyfive/theme.json"
}
```

`etag` is `md5(filemtime+filesize)`.

### `POST /document`

**Body**

```json
{
  "data": { "...theme.json..." },
  "etag": "abcd1234…"
}
```

Returns `200` with a fresh `etag` on success. Returns `409` with a `server_etag` and `server_data` payload when the etag has drifted (someone else edited the document between load and save) so the UI can render a conflict banner. Payloads larger than 1 MB are rejected with `413`.

## Changelog

A complete listing of all notable changes to this project are documented in [CHANGELOG.md](https://github.com/s3rgiosan/wp-theme-json-editor/blob/main/CHANGELOG.md).

## License and Attribution

This project is licensed under the [GPL-3.0-or-later](https://spdx.org/licenses/GPL-3.0-or-later.html). See the [LICENSE](LICENSE) file for details.
