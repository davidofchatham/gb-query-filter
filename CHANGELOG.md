# Changelog

All notable changes to GB Query Filter are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
