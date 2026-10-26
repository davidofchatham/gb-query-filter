# Changelog

All notable changes to GB Query Filter are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- **Editor sidebar reorganized into parallel groups: Search, Taxonomies, Custom Fields and Result Updates.** Taxonomies are one list, Categories and Tags included, each with its own control type, chosen from every public taxonomy through a searchable combobox paired with an Add button. Custom Fields merges Meta Box and ACF into one list: pick a field from the discovered fields instead of typing its name, and a field whose source no longer exists is flagged for removal. The source (Meta Box or ACF) is shown only when both integrations supply fields. A long field list shows the first 25 immediately, searches the whole set as you type and loads more on scroll. Result Updates replaces the two stacked toggles with a "When *Apply* is clicked / Immediately on selection" choice, an AJAX toggle and a plain-language summary of the combination.
- A taxonomy list is now stored in a new `taxonomies` block attribute (`[ { slug, controlType } ]`). Blocks saved earlier keep rendering from `enableCategories`, `enableTags` and `extraTaxonomies` until they are next edited, then use the new attribute; their front-end markup and URL parameters are unchanged. Taxonomy order now follows the order in the list, and Categories and Tags are labeled by their registered taxonomy label.
- A newly inserted block starts with every filter off, search included, and with AJAX updates off, instead of with search on. Existing blocks are unaffected.
- Taxonomy filters for Categories and Tags are labeled from the taxonomy's registered label (core's "Categories" and "Tags", including translations and relabeling) instead of fixed strings. The markup is otherwise unchanged.
- Adding a custom field in the editor drops any fields still stored behind a switched-off Meta Box or ACF filter, which the new sidebar cannot show and the front end was not rendering. Panels open on load only when they hold something or are set away from their defaults.
- ACF layout-only fields (`tab`, `message`, `accordion`) are no longer offered in the editor's field list.

### Fixed
- **The editor's AJAX toggle disagreed with the front end.** The editor defaulted `enableAjax` to off while the PHP registration defaulted it to on, so a block with AJAX switched off omitted the attribute and rendered with AJAX on. The editor default now matches, so blocks show what visitors get; no front-end behavior changes.
- The control-type dropdowns for Categories, Tags and Additional taxonomies were bare `<select>` elements whose `<label>` had no `for`, so none had an accessible name. They are now labeled `SelectControl`s (WCAG 1.3.1, 4.1.2).

## [0.5.0] — 2026-10-23

### Added
- **`buttons` control type.** A single-select group of styled buttons for short choice sets, with a leading "Any" that clears the field. Available for Categories, Tags, extra taxonomies, and the per-field control override of Meta Box and ACF fields that have choices (including ACF true/false, rendered as Yes/No). Built from native radios inside labels in a named `role="group"`, so keyboard, labels and the no-JavaScript submit work as they do for the select, and the URL parameters are identical. Multi-select stays `checkboxes`.
- **Button style classes.** Three block-wide attributes, set in a new "Button Styles" editor panel, take space-separated CSS class names (GenerateBlocks styles or any theme class): `buttonClass` (every option), `applyClass` (the Apply button) and `resetClass` (the Reset link). Tokens are sanitized and a leading `.` is stripped, so a pasted `.my-button` works. Style the selected option with `.your-class:has(input:checked)`; with no class set, the plugin's zero-specificity default look applies, so any author class wins. The checked option is always bold, so selection never rests on color alone. Known gap: keyboard focus shows the plugin's outline, not the class's own `:focus` effect.
- [fork] **In-dashboard updates from GitHub Releases.** Sites running a release build now see new versions under Dashboard → Updates, via the vendored Plugin Update Checker 5.7 (`libs/plugin-update-checker/`). Only a release carrying the `gb-query-filter-<version>.zip` built by the release workflow is offered; a bare tag offers nothing.

### Changed
- Taxonomy selects are now named by a real `<label for>` whose visible text is the name, instead of an `aria-label` beside a bare `<span>`, so clicking the label focuses the control.
- Field labels (taxonomy, Meta Box, ACF) are now all `<label class="gbqf-filter-label">`. Meta Box and ACF labels pick up that class's styling (semibold, 13px), so they match the taxonomy labels; override `.gbqf-filter-label` if your theme styled them differently.
- Meta Box and ACF field labels are now built from the control actually rendered, so a future control type cannot produce a dangling `for`. No markup change.
- PHP now follows the WordPress coding standards (tabs, long arrays, docblocks), checked by `composer lint` (phpcs). No behavior change.
- [fork] `composer lint` runs as a blocking GitHub Actions check.

### Removed
- `Filters::register_target()`, deprecated in 0.4.0. Call `Targeting::register()` instead; `Filters` no longer has any part in target registration.

### Fixed
- **Changing a filter from a later page rendered an empty loop.** The AJAX request kept the Query's page param (`query-{uniqueId}-page`), so narrowing from page 3 to a one-page result asked for a page that no longer exists. A filter change now drops the target Query's page key, and Reset drops it too.
- **Taxonomy checkbox sets (Categories, Tags, extra taxonomies) had no group name.** Each option was announced ("GBQF Fixture, checkbox") without the set's name ("Categories"). The set is now a `role="group"` named by its visible label, the same as the Meta Box and ACF checkbox and radio groups (WCAG 1.3.1, 4.1.2).

## [0.4.0] — 2026-08-13

### Added
- **`Targeting` and `Target`** ([includes/class-gbqf-targeting.php](includes/class-gbqf-targeting.php), [includes/class-gbqf-target.php](includes/class-gbqf-target.php)). `Targeting::match( $attributes )` returns a `Target` or `null`, replacing a gate and a separate lookup that walked the same match chain independently and disagreed (the cause of the class-targeting and field-ownership defects below). `Targeting` owns the registry, the scope decision, the per-block `data-gbqf-*` overrides and the `gbqf_should_apply_to_block` filter; `Filters` keeps query mutation. Scope is resolved per Query Loop at render time, so `gbqf_filter_scope` works from a theme's `functions.php` or from `init`.
- [fork] **`query-filters` fixture blueprint** (`tools/fixtures/query-filters/`): the first automated coverage of the targeting rule, with five Query Loops over the same posts that differ only in how a filter block claims them, plus a Meta Box and an ACF field owned by two blocks. Checks run over real HTTP, since wp-cli never fires the render-time hooks. Every defect in this release was committed as a failing test before its fix. Dev-only; excluded from the ZIP.

### Changed
- Flat (`gbqf_*`) URL parameters reach a loop **only** under the legacy `all` scope or when the loop is force-enabled. Under the default `targeted` scope an unscoped filter block claims nothing. Not new behavior (ADR-0001 as designed); CONTEXT.md had wrongly described flat mode as generally supported.

### Deprecated
- `Filters::register_target()`. Use `Targeting::register()`. Removed in 0.5.0.

### Removed
- `Filters::should_apply_to_attributes()`, `get_matched_target()`, `is_targeted()`, `get_block_id_from_attributes()`, `class_contains()` and `get_block_override()` (all `protected`, undocumented). Their behavior moved into `Targeting`, mostly as private methods.

### Fixed
- **Custom-field filter labels were not associated with their control** ([ADR-0003](docs/adr/0003-accessibility-target-current-wcag-aa.md)). ACF labels had no `for`, and the Meta Box radio label pointed `for` at an id no element carried. Labels now set `for` only over a single control; group controls (Meta Box radio; ACF radio, checkboxes, true/false) are named by `role="group"` + `aria-labelledby`.
- **Two filter blocks on one page produced duplicate control ids**, so a label could point at a control in the other form. Ids now carry a per-block prefix. Nothing documented, styled or scripted used the old ids.
- **Class-based scoped targeting never filtered anything.** The query read its scope from the loop's own id instead of the matched target key, so the form wrote `gbqf[<target>][…]` while the query read `gbqf[<loop-id>][…]`. Callers now receive a `Target` carrying the registered key.

### Security
- **Any post meta key was filterable through a block that declared no fields.** An empty `metaBoxFields` / `acfFields` list meant "process all keys", and empty is the default, so `gbqf[<target>][meta][<any-key>]=<value>` filtered results on any claimed loop, including protected `_`-prefixed keys. Comparing row counts let an unauthenticated visitor confirm or deny meta values (security invariant 1 in [CONTEXT.md](CONTEXT.md)). An empty list now means the block owns no fields. This applies per list: a block declaring only ACF fields no longer runs URL meta keys through `get_meta_filters()` as well, which could also add a duplicate meta clause.
- **A Query Loop no filter block targets could be filtered from the URL.** Any loop with a `gbqf-target-*` class passed the gate without a registration check and took flat `?gbqf_search=`-style parameters (security invariant 2 in [CONTEXT.md](CONTEXT.md), [ADR-0001](docs/adr/0001-targeted-scope-default.md)). The legacy `gbqf-target-*` branch, added by this fork in 0.2.0 and never relied on upstream, is removed. A loop is claimed only by a **registered** HTML ID or target name among its classes.
- **An unrecognized filter scope failed open.** Any value other than `all` or `targeted` (a callback typo, a stale `data-gbqf-scope`) applied filtering to every Query Loop. Unknown scopes are now treated as `targeted`.

## [0.3.0] — 2026-08-13

### Added
- [fork] GitHub Actions release workflow: pushing a `v*` tag builds `gb-query-filter-<version>.zip` (excluding `.distignore` paths) and attaches it to the release. The build fails unless the tag matches both the header `Version:` and `GBQF_VERSION`. Workflow input reaches the shell only through the environment, and `contents: write` is scoped to the build job.
- [fork] README documents the upgrade step the rename needs and points to the release asset instead of the repository ZIP.

### Changed
- Plugin display name is now **GB Query Filter** (was "GB Query Filters"). The admin menu entry stays **GenerateBlocks → Query Filters**.
- [fork] `Plugin URI` points at <https://github.com/davidofchatham/gb-query-filter>.
- **Slug is now `gb-query-filter` (singular) everywhere:** main file `gb-query-filters.php` → `gb-query-filter.php`, text domain `gb-query-filters` → `gb-query-filter`. The block name, `gbqf_*` options and script/style handles are unchanged, so no settings are lost, but WordPress tracks a plugin by its main file, so existing installs show it deactivated after updating and must reactivate it once.

## [0.2.1] — 2026-08-13

### Changed
- Editor script dependencies: `wp-editor` → `wp-block-editor`, plus `wp-server-side-render`, so `ServerSideRender` no longer comes from the deprecated `wp.components` alias.
- Removed a hidden, no-op `TextControl` from the Additional Taxonomies panel.

### Fixed
- Block registers with API version 3: clears the WordPress 6.9 "API version 2 or lower is deprecated" warning and works in the iframed editor (preview wrapped in `useBlockProps()`).
- PHP 8 deprecation on every request: optional `$allowed_field_names` preceded required `$raw_meta` in `Filters::get_meta_filters()` and `get_acf_filters()`. Both now default to `[]`.
- Editor "36px default size" deprecation warnings: controls opt in to `__next40pxDefaultSize` and `__nextHasNoMarginBottom`.

## [0.2.0] — 2026-06-15

Everything built on top of the upstream 0.1.2 baseline before the first tagged fork release, collapsed into one version.

### Added
- Advanced Custom Fields (ACF) integration
- Targeted mode (default): filters apply only to Query Loops with a matching HTML ID or class
- Scoped URL parameters (`gbqf[targetId][key]`) for multiple independent filter groups per page
- Class-based targeting via GenerateBlocks' `gb-query-*` classes
- Developer filters: `gbqf_filter_scope`, `gbqf_filter_priority`, `gbqf_preserve_search`, `gbqf_should_apply_to_block`, `gbqf_enable_debug_logging`
- Per-Query-Loop overrides via `data-gbqf-enabled`, `data-gbqf-scope`, `data-gbqf-priority`
- Admin settings page under GenerateBlocks → Query Filters, read through a `Settings` class of static accessors
- Debug logging setting, which also sets `window.GBQF_DEBUG` to gate frontend console output
- After each AJAX submit, every filter block recomputes its Reset href from the live URL (`window.GBQF_resetUpdaters`), so each Reset clears only its own scoped parameters
- `GBQF\Params` class for all scoped/flat URL parameter reads and URL building

### Changed
- Default filter scope: "all" → "targeted"
- Default hook priority: 10 → 20 (runs after most other plugins)
- ACF fields identified by field name, not field key
- Meta Box and ACF share the `gbqf_meta` URL namespace
- `Filters` reads options through `Settings::*()`, not `get_option()`

### Deprecated
- `gbqf_acf[field]` URL parameters (ignored; use `gbqf_meta[field]`)

### Removed
- Always-on `GBQF_META_FIELDS` console.log in the editor

### Fixed
- LICENSE shipped GPLv3 while the plugin declared GPL-2.0-or-later; now GPLv2, with `License` / `License URI` headers
- Internal `gbqf_preserve_existing_search` filter renamed to the canonical `gbqf_preserve_search`
- Duplicate `gbqf-editor` style registration
- Reset URL built from `home_url()` instead of `$_SERVER['HTTP_HOST']` (Host Header Injection)
- After AJAX `history.replaceState`, Reset links kept stale server-rendered URLs
- Stale numeric-indexed params (`[0]=31`) blocked clearing a filter over AJAX
- Multiple checkbox values dropped from AJAX params (`params.set` → `params.append`)
- `http_build_query()` honored `arg_separator.output`, producing `&#038;` in scoped Reset URLs on some servers; now uses `'&'` and decodes `home_url()` entities, and stray `#038;` fragments are stripped
- Class-based targeting failed for GB Query blocks without a manual HTML ID; `gb-query-{uniqueId}` is now derived
- PHP default for `enableApplyButton` was `true` while the editor's was `false`; aligned to `false` so auto-apply works for new blocks
- Empty values (cleared inputs, "Any" options) added `key=` entries to the AJAX URL

## [0.1.2] — Upstream baseline

Inherited from the upstream source; no earlier history exists. Reconstructed from the imported code.

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
