# Changelog

All notable changes to this plugin are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-11-13

### Changed

- Update `@s3rgiosan/theme-json-editor-ui` to 1.7.3 ([#8](https://github.com/s3rgiosan/wp-theme-json-editor/pull/8), [#9](https://github.com/s3rgiosan/wp-theme-json-editor/pull/9)).

### Fixed

- CSS variable names in autocomplete and in the preset preview match the names WordPress generates, so a font size slug `2xl` shows as `--wp--preset--font-size--2-xl`.
- The preset preview shows duotone variable names with the slug as written in theme.json, such as `--wp--preset--duotone--blueOrange`.
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

[Unreleased]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.2.1...HEAD
[1.2.1]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/s3rgiosan/wp-theme-json-editor/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/s3rgiosan/wp-theme-json-editor/releases/tag/1.0.0
