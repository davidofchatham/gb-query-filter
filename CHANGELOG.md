# Changelog

All notable changes to GB Query Filter are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

---

## [0.4.0] — 2026-08-13

### Security
- **A Query Loop that no filter block targets could be filtered from the URL.** Any loop carrying a
  class matching `gbqf-target-*` passed the gate without any check that a filter block had
  registered that name, and was then filtered by flat `?gbqf_search=` (and sibling) parameters.
  This broke security invariant 2 in [CONTEXT.md](CONTEXT.md) — "no filtering an unconnected loop" —
  and the intent of [ADR-0001](docs/adr/0001-targeted-scope-default.md).

  The legacy `gbqf-target-*` branch is **removed**. It protected nothing: upstream never gated on it
  (`should_apply_to_attributes()` returned `true` unconditionally), the only written form of the
  convention was a stale docblock naming a bare `gbqf-target` class that the `gbqf-target-` prefix
  does not even match, and the check was introduced by this fork in 0.2.0. A loop is now claimed
  only by a **registered** HTML ID or a **registered** target name appearing as one of its classes.

- **An unrecognised filter scope failed open.** Any scope value other than `all` or `targeted` — a
  typo in a `gbqf_filter_scope` callback, a stale `data-gbqf-scope` attribute — fell through to
  "apply to every Query Loop", reinstating exactly the blanket behaviour ADR-0001 forbids. Unknown
  scope values are now treated as `targeted` (fail closed).

### Fixed
- **Class-based scoped targeting never filtered anything.** A Query Loop claimed by *class* rather
  than by HTML ID received no filter state: the `Params` scope was taken from the loop's own id
  instead of the matched target key, so the filter form wrote `gbqf[<target>][…]` while the query
  read `gbqf[<loop-id>][…]`. Silent no-op, no error. Callers are now handed a `Target` carrying the
  registered key and have no means to re-derive an id, so the mistake is not expressible.

### Added
- **`Targeting` and `Target`** ([includes/class-gbqf-targeting.php](includes/class-gbqf-targeting.php),
  [includes/class-gbqf-target.php](includes/class-gbqf-target.php)). `Targeting::match( $attributes )`
  returns a `Target` or `null` — one question, one answer, one place. It replaces a gate
  (`should_apply_to_attributes()`) and a separate lookup (`get_matched_target()`) that walked the
  same match chain independently and disagreed, plus a third re-derivation of the loop id in the
  caller. Both defects above were consequences of that split; neither is expressible now.

  `Targeting` owns the registry, the scope decision, the per-block `data-gbqf-*` overrides and the
  `gbqf_should_apply_to_block` filter. `Filters` sheds ~180 lines and keeps query mutation. Scope is
  resolved **per Query Loop, at render time** — `gbqf_filter_scope` keeps working from a theme's
  `functions.php` or from `init`, both of which run after the plugin bootstraps.

- **`query-filters` fixture blueprint** (`tools/fixtures/query-filters/`) — the first automated
  coverage of the targeting rule. One page, five Query Loops over the same posts, differing only in
  how a filter block claims them, so any difference in the rendered result set is attributable to
  the targeting rule and nothing else. Dev-only; excluded from the distributable ZIP.

  Both defects above were **committed as failing tests before being fixed**, so "this was broken and
  now is not" is a diff between two runs rather than an assertion. Against 0.3.0 the blueprint
  reports 2 failed / 23 passed; against this release, 25 passed.

  Split into `verify.php` (the fixtures are real) and `render-surface.sh` (behaviour, over real
  HTTP) because wp-cli is structurally blind here: registration happens in the filter block's
  `render_callback` and the targeting decision inside `generateblocks_query_wp_query_args` during a
  render. Under wp-cli neither fires, so every "this loop was not filtered" check passes vacuously.

### Changed
- Flat (`gbqf_*`) URL parameters are reachable **only** under the legacy `all` scope, or when a
  Query Loop is force-enabled. Under the default `targeted` scope an unscoped filter block claims
  nothing, so its flat parameters reach no loop. This is not new behaviour — it is ADR-0001 working
  as designed — but CONTEXT.md previously described flat mode as a generally supported
  backward-compatible mode, which it is not.

### Deprecated
- `Filters::register_target()` — use `Targeting::register()`. The old method still forwards and
  emits a deprecation notice; it will be removed in a future release.

### Removed
- `Filters::should_apply_to_attributes()`, `Filters::get_matched_target()`, `Filters::is_targeted()`,
  `Filters::get_block_id_from_attributes()`, `Filters::class_contains()` and
  `Filters::get_block_override()` (all `protected`, undocumented). Their behaviour lives in
  `Targeting`, mostly as private methods — `get_block_id_from_attributes()` in particular is now
  unreachable from callers by design, since its reachability is what caused the class-match defect.

---

## [0.3.0] — 2026-08-13

### Changed
- Plugin display name is now **GB Query Filter** (was "GB Query Filters"), matching the README and
  the repository. `Plugin URI` now points at <https://github.com/davidofchatham/gb-query-filter>.
  The admin menu entry stays **GenerateBlocks → Query Filters**.
- **Slug normalized to `gb-query-filter` (singular) everywhere.** The main plugin file is renamed
  `gb-query-filters.php` → `gb-query-filter.php` and the text domain `gb-query-filters` →
  `gb-query-filter`, matching the repository name, the Plugin URI, the settings page slug and the
  install directory. Block name (`gbqf/query-filter`), all `gbqf_*` option keys and all script and
  style handles are unchanged, so **no settings are lost** — but because WordPress identifies a
  plugin by its main file path, an existing install will show the plugin as deactivated after the
  update and must be reactivated once.

### Added
- GitHub Actions release workflow: pushing a `v*` tag builds a clean distributable ZIP
  (`gb-query-filter-<version>.zip`, excluding dev files listed in `.distignore`) and attaches it to
  the GitHub release. The build fails if the tag version does not match both the plugin header
  `Version:` and `GBQF_VERSION`. Untrusted workflow input (the `workflow_dispatch` tag) reaches the
  shell only through the environment, never by template interpolation, and the tag must match
  `v<digits>…`; `contents: write` is scoped to the build job rather than the whole workflow.
- README documents the upgrade step this rename requires and points to the release asset rather
  than the repository ZIP.

---

## [0.2.1] — 2026-08-13

### Fixed
- Block now registers with `apiVersion` / `api_version` 3, clearing the WordPress 6.9
  "Block with API version 2 or lower is deprecated" console warning and making the block
  iframe-editor compatible. The editor preview is wrapped in a `useBlockProps()` element so
  block selection works inside the iframe.
- PHP 8 deprecation emitted on every request (including every WP-CLI call): optional parameter
  `$allowed_field_names` was declared before required `$raw_meta` in `Filters::get_meta_filters()`
  and `Filters::get_acf_filters()`. Both now default to `[]`.

- Editor control deprecation warnings ("36px default size ... is deprecated since version 6.8"):
  all `TextControl`, `SelectControl`, `ComboboxControl` and `FormTokenField` instances now opt in
  via `__next40pxDefaultSize`, and all of those plus `ToggleControl` via `__nextHasNoMarginBottom`.

### Changed
- Editor script dependencies: `wp-editor` → `wp-block-editor`, added `wp-server-side-render`
  so `ServerSideRender` resolves from its own package rather than the deprecated
  `wp.components` alias.
- Removed a dead hidden `TextControl` in the Additional Taxonomies panel that rendered with
  `display: none` and a no-op `onChange` — the adjacent `FormTokenField` already carries the label.

---

## [0.2.0] — 2026-06-15

All changes in this release were developed on top of the upstream 0.1.2 baseline.
There were no intervening tagged releases, so every addition, change, and fix
below is collapsed into 0.2.0.

### Added
- Advanced Custom Fields (ACF) integration
- Targeted mode (default): filters only apply to Query Loop blocks with a matching HTML ID or class
- Scoped URL parameters (`gbqf[targetId][key]`) enabling multiple independent filter groups per page
- Class-based targeting for GenerateBlocks' unique `gb-query-*` class identifiers
- `gbqf_filter_scope`, `gbqf_filter_priority`, `gbqf_preserve_search`, `gbqf_should_apply_to_block`, `gbqf_enable_debug_logging` developer filters
- Per-Query-Loop block overrides via `data-gbqf-enabled`, `data-gbqf-scope`, `data-gbqf-priority` HTML attributes
- `Settings` class with static accessors for all global options
- Admin settings page under GenerateBlocks → Query Filters
- `Settings::is_debug_enabled()` static method backed by `gbqf_enable_debug_logging` option
- `window.GBQF_DEBUG` JS flag driven by the debug logging setting — gates frontend console output
- Client-side reset URL refresh registry (`window.GBQF_resetUpdaters`): after each AJAX submission all filter blocks recompute their reset link href from the live URL, ensuring each block's reset button removes only its own scoped parameters
- `GBQF\Params` class centralizing all scoped/flat URL parameter reads and URL construction

### Changed
- Default filter scope changed from "all" to "targeted"
- Default hook priority changed from 10 to 20 (runs after most other plugins)
- ACF fields now identified by field name instead of field key
- Unified `gbqf_meta` URL namespace for both Meta Box and ACF fields (replaces preliminary `gbqf_acf` parameter)
- `class-gbqf-filters.php` now routes option reads through `Settings::*()` methods instead of calling `get_option()` directly

### Deprecated
- `gbqf_acf[field]` URL parameters (silently ignored; use `gbqf_meta[field]`)

### Removed
- Always-on `GBQF_META_FIELDS` dev console.log from block editor JS

### Fixed
- LICENSE file shipped GPLv3 text while the plugin declared GPL-2.0-or-later; replaced with canonical GPLv2 and added `License`/`License URI` headers to the plugin file
- `gbqf_preserve_existing_search` filter renamed to canonical `gbqf_preserve_search` (was an undocumented internal name)
- Duplicate `gbqf-editor` style registration removed from `class-gbqf-blocks.php`
- Reset URL construction now uses `home_url()` instead of `$_SERVER['HTTP_HOST']` to prevent HTTP Host Header Injection in misconfigured environments
- AJAX reset links pointed to stale PHP-rendered URLs after `history.replaceState` updates; reset hrefs are now recomputed from the live URL after each AJAX submission
- AJAX URL building retained stale numeric-indexed params (e.g. `[0]=31` from `http_build_query`) that prevented filter clearing via AJAX; stale params for the submitting block are now removed before appending fresh form values
- Multiple checkbox values were silently dropped when building AJAX params (`params.set` replaced with `params.append`)
- `http_build_query()` used `arg_separator.output` ini setting which produces `&amp;` separators on some servers, causing double-encoded ampersands (`&#038;`) in scoped reset URLs; now uses explicit `'&'` separator and decodes HTML entities in the `home_url()` output before URL parsing
- URL fragments introduced by double-encoded ampersands (`#038;...`) are now stripped from the AJAX request URL and reset link href before use
- `get_block_id_from_attributes()` did not handle GenerateBlocks' `uniqueId` attribute, preventing class-based targeting for GB Query blocks without a manually set HTML ID; added fallback that constructs `gb-query-{uniqueId}`
- `enableApplyButton` PHP attribute default was `true` (always show Apply button) while the JS block default was `false` (auto-apply mode); PHP default aligned to `false` — auto-apply now works correctly for new blocks
- Empty form field values (cleared text inputs, "any" select options) were appended to the AJAX request URL, polluting it with `key=` entries; empty values are now skipped when building AJAX params

---

## [0.1.2] — Upstream baseline

The starting point inherited from the upstream source (initial import). No granular
release history exists prior to this point; the features below describe what 0.1.2
shipped, reconstructed from the imported code.

### Added
- `gbqf/query-filter` block with server-side rendering
- Search field filtering (`gbqf_search`)
- Category and tag filtering (`gbqf_cat`, `gbqf_tag`)
- Extra (custom) taxonomy filtering (`gbqf_tax[slug][]`)
- Checkbox and select control types for taxonomy filters
- Meta Box field filtering (`gbqf_meta[field]`), via `metaBoxFieldId` (legacy CSV) and `metaBoxFields` (per-field control type) attributes
- AJAX filtering with full-page-reload fallback
- Apply button / auto-apply modes
- GenerateBlocks 1.x (`generateblocks_query_loop_args`) and 2.0+ (`generateblocks_query_wp_query_args`) hook support
- `targetId` block attribute (defined; scoping logic added in 0.2.0)
- `Settings::is_metabox_enabled()` Meta Box integration toggle
