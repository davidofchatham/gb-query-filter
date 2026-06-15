# Context: GB Query Filter

Domain language, architecture, and the invariants this plugin must uphold. Read this before changing code. Implementation *decisions* (the "why this way") live in [docs/adr/](docs/adr/).

## What it is

GB Query Filter is a WordPress plugin that adds front-end filters (taxonomies, custom fields, search) to GenerateBlocks Query Loop blocks, compatible with GenerateBlocks 2.0+.

- Pure PHP + vanilla JS — no build process, edit files directly.
- Does not modify GenerateBlocks core (hooks only).
- Filter state lives in URL params (shareable/bookmarkable) — see [ADR-0002](docs/adr/0002-url-params-as-filter-state.md).
- Optional AJAX filtering; optional Meta Box and ACF integrations.

## Security invariants (must never break)

These are the contract, not a decision to revisit. The rationale for *how* they are enforced lives in the ADRs.

1. **No privilege leak via filtering.** Front-end filtering must not expose anything a user couldn't see without the filter. URL-param tampering must not override a Query Loop's preset filters (e.g. changing post type, unsetting a required taxonomy term).
2. **No filtering an unconnected loop.** A user must not be able to filter a Query Loop that has no GBQF filter block connected to it — not via URL params, DOM injection, or retargeting a filter's attributes at another loop. See [ADR-0001](docs/adr/0001-targeted-scope-default.md).

Enforcement: sanitize all `$_GET`/`$_POST`/`$_SERVER` input (`sanitize_text_field`, `sanitize_key`, `absint`, `esc_url`); escape all output (`esc_html`/`esc_attr`/`esc_url`/`wp_json_encode`); `$wpdb->prepare()` for any raw SQL; nonces + capability checks for state-changing/admin ops. Query args are sanitized before reaching `WP_Query`. The reset URL is built with `home_url()` (not `$_SERVER['HTTP_HOST']`) to avoid Host Header Injection — see [ADR-0002](docs/adr/0002-url-params-as-filter-state.md).

## Accessibility (invariant for code you change)

Target the current WCAG AA level (WCAG 2.2 AA as of 2026-06) — see [ADR-0003](docs/adr/0003-accessibility-target-current-wcag-aa.md). This is a hard gate on any control or output you **add or modify**: every form input needs an associated `<label>`; controls without visible labels need `aria-label`; use `.screen-reader-text` for visually-hidden labels; keyboard-navigable with visible focus; ARIA live regions for AJAX content updates; sufficient contrast.

**The existing plugin is unaudited** — no formal WCAG audit has been run, so do not assume untouched code conforms or claim the plugin "is WCAG 2.2 AA compliant." A full baseline audit is a separate open task.

## Architecture

Singleton bootstrap in [includes/class-gbqf-plugin.php](includes/class-gbqf-plugin.php) loads four classes (all in the `GBQF\` namespace):

- **Settings** ([class-gbqf-settings.php](includes/class-gbqf-settings.php)) — admin page under GenerateBlocks → Query Filters (`admin.php?page=gb-query-filter`). Static accessors (`is_metabox_enabled`, `is_acf_enabled`, `is_debug_enabled`, `get_filter_priority`, `get_filter_scope`, `should_preserve_search`) each wrap `get_option()` + `apply_filters()` so PHP filters still override the stored option. **Never call `get_option()` for these directly elsewhere — go through Settings.**
- **Params** ([class-gbqf-params.php](includes/class-gbqf-params.php)) — single source for reading filter state from the URL (flat or scoped) and building reset/field-name URLs. Constructed with a `$target_id` (`''` = flat mode). Used by both Blocks and Filters; do not re-read `$_GET` for filter params outside this class.
- **Blocks** ([class-gbqf-blocks.php](includes/class-gbqf-blocks.php)) — registers the `gbqf/query-filter` block (server-side render), enqueues assets, renders the filter form, feeds taxonomy/MB/ACF field data to the editor via inline scripts (`window.GBQF_TAXONOMIES`, `window.GBQF_META_FIELDS`).
- **Filters** ([class-gbqf-filters.php](includes/class-gbqf-filters.php)) — hooks `generateblocks_query_loop_args` (GB 1.x) and `generateblocks_query_wp_query_args` (GB 2.0+) to modify `WP_Query` args. `should_apply_to_attributes()` gates which loops a filter touches.

**Frontend JS** ([assets/js/gbqf-frontend.js](assets/js/gbqf-frontend.js)): AJAX fetch + Query Loop content swap + URL update via `history.replaceState`; auto-apply on change/Enter; reset-button visibility; graceful fallback to plain form submit. **Editor JS** ([assets/js/gbqf-query-filter-block.js](assets/js/gbqf-query-filter-block.js)): registers the block and its attribute UI.

## Non-obvious gotchas

- **HTML ID location:** GenerateBlocks stores a block's HTML ID at `attributes['htmlAttributes']['id']`, *not* the standard `attributes['anchor']`. Check both. For GB Query blocks with no manual ID, the unique class is `gb-query-{uniqueId}`.
- **Targeting is the default invariant:** default scope is `'targeted'` — a filter only affects the Query Loop whose HTML ID or class matches the filter block's `targetId`. `'all'` mode exists as a legacy escape hatch via the `gbqf_filter_scope` filter, but targeted is the contract. Do not reintroduce blanket "apply to every loop" behavior. See [ADR-0001](docs/adr/0001-targeted-scope-default.md).
- **Hook priority 20** (not WP default 10, configurable via `gbqf_filter_priority`): a best-effort attempt to run *after* other query-modifying plugins. It is **not** a guarantee — a plugin at >20 or a 20-tie defeats it. The real contract is the *merge semantics* (GBQF preserves existing query args at any priority); losing the ordering race makes the filter silently no-op, never a security breach. See [ADR-0004](docs/adr/0004-merge-preserving-query-modification.md).

## URL parameter conventions

Flat mode (no `targetId`) — prefix `gbqf_`:
- Search `?gbqf_search=kw` · Categories `?gbqf_cat[]=1` · Tags `?gbqf_tag[]=5`
- Custom taxonomies `?gbqf_tax[slug][]=10`
- Meta + ACF (unified, source-agnostic) `?gbqf_meta[field]=value`, arrays `?gbqf_meta[colors][]=red`

Scoped mode (`targetId` set — isolates multiple filters on one page), nested under `gbqf[targetId]`:
- `?gbqf[my-projects][search]=kw` · `[cat][]=1` · `[meta][color]=blue` · `[meta][colors][]=red`

Reset clears only the owning block's params. Legacy `gbqf_acf[...]` params are silently ignored (superseded by `gbqf_meta`); reset still strips them for cleanup.

Full param/data-attribute reference: [docs/developer-filters.md](docs/developer-filters.md).

## Conventions

- `GBQF\` namespace; WordPress coding standards.
- No build step — edit PHP/JS/CSS directly.
- Version constant in [gb-query-filters.php](gb-query-filters.php) (`GBQF_VERSION`). Semantic versioning. Keep [CHANGELOG.md](CHANGELOG.md) current (Keep a Changelog format).
- `gbqf_loaded` action fires once the plugin is initialized.

## Testing

Requires a live WordPress install with GenerateBlocks active, a page with a Query Loop, and the GB Query Filter block above it. Meta Box / ACF optional for those integrations. No automated test suite.
