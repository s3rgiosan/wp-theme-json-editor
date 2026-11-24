# Changelog

All notable changes to this plugin are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-11-24

### Changed

- Update `@s3rgiosan/theme-json-editor-ui` to 1.8.0 ([#12](https://github.com/s3rgiosan/wp-theme-json-editor/pull/12)).
- The core-scan snapshot lists `settings.transform.rotate` and `styles.transform.rotate` in `properties`, and records the type of each property in a new `types` map.

### Fixed

- Undocumented properties get the field that matches their type. Boolean settings such as `settings.transform.rotate` show a toggle, and properties that take an array, an object, or more than one type, such as `settings.typography.textShadowPresets`, show a JSON field.
- `styles.transform` is available for each block and for block style variations. At the root of `styles`, it shows only in a style variation file with `blockTypes`.
- Search no longer lists properties the editor hides.

## [1.2.1] - 2026-11-17

### Changed

- Update `@s3rgiosan/theme-json-editor-ui` to 1.7.4 ([#8](https://github.com/s3rgiosan/wp-theme-json-editor/pull/8), [#9](https://github.com/s3rgiosan/wp-theme-json-editor/pull/9), [#11](https://github.com/s3rgiosan/wp-theme-json-editor/pull/11)).
- The core-scan snapshot lists `styles.typography.textWrap` in `properties`.

### Fixed

- CSS variable names in autocomplete and in the preset preview match the names WordPress generates, so a font size slug `2xl` shows as `--wp--preset--font-size--2-xl`.
- The preset preview shows duotone variable names with the slug as written in theme.json, such as `--wp--preset--duotone--blueOrange`.
- Autocomplete and the preset preview show no CSS variable name for a duotone preset set on a single block (`settings.blocks.<block>.color.duotone`), because WordPress declares duotone variables only for root presets.
- The Vite build gives the same `build/admin.js` for the same input ([#10](https://github.com/s3rgiosan/wp-theme-json-editor/pull/10)).

## [1.2.0] - 2026-09-29

### Changed

- Update `@s3rgiosan/theme-json-editor-ui` to 1.7.1. The core-scan snapshot lists every supported theme.json property in `properties`, and the editor flags only those the loaded schema does not document as undocumented.

## [1.1.0] - 2026-06-14

### Added

- Edit a theme's style variations (`styles/*.json`) alongside its root `theme.json`, via a file switcher that lists every variation found on disk.
- "Try in WordPress Playground" README button backed by `.wp-playground/blueprint.json`, which boots the demo straight into the **Appearance → Theme JSON Editor** screen.

## [1.0.0] - 2026-05-08

Initial release.

[Unreleased]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.3.0...HEAD
[1.3.0]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.2.1...1.3.0
[1.2.1]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/s3rgiosan/wp-theme-json-editor/releases/tag/1.0.0
