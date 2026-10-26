# query-filters blueprint

Fixtures for two rules a filter block must not be able to break:

1. **Which** GenerateBlocks Query Loop it is allowed to touch — [ADR-0001](../../../docs/adr/0001-targeted-scope-default.md) and security invariant 2 in [CONTEXT.md](../../../CONTEXT.md).
2. **Which fields** it is allowed to filter on within a loop it does own — security invariant 3 (blueprint v3).

## Why it needs a render harness

`Targeting::register()` runs from the filter block's `render_callback`, and the targeting decision runs inside `generateblocks_query_wp_query_args` while GenerateBlocks renders a `generateblocks/query` block. Under wp-cli neither fires — so all five loops look identical there no matter what the plugin does, and every "this loop was not filtered" check passes vacuously. The same is true of field ownership: it is read from a rendering block's attributes, so under wp-cli no block owns anything and §9 would pass vacuously too.

So the split is deliberate:

| file | asserts |
|---|---|
| `schema.php` | registers the Meta Box and ACF fields (loaded on every request via a mu-plugin stub, because GBQF reads both registries at *render* time) |
| `verify.php` | the fixtures are real (posts, meta values, page, five loops, three filter blocks, both fields registered, ownership disjoint, the v5 taxonomy page) |
| `render-surface.sh` | the targeting and ownership rules, against real HTTP responses |

## The fixture

One page, `/gbqf-targeting/`, with five Query Loops over the same four posts. They differ only in how a filter block claims them, so a difference in the rendered result set is attributable to the targeting rule and nothing else.

| loop | claimed by | expected |
|---|---|---|
| `control` | nothing | never filtered |
| `unscoped` | a filter block with `targetId ''` | open question |
| `scoped` | a filter block naming its id | filtered by its own scoped params |
| `legacy` | nothing, but carries `gbqf-target-orphan` | open question |
| `classmatch` (v2) | a filter block naming one of its **classes** | open question |

`classmatch` exists because every other loop is claimed by HTML ID, where the matched **target key** and the **loop's own id** happen to be equal — so v1 could not tell which of the two the code actually uses. Here they differ on purpose: target `gbqf-alias`, loop id `gbqf-loop-classmatch`.

Each loop emits `<MARKER>::<post title>` per row, so a rendered row count is a substring count and never an HTML parse. The search term is `Bravo`, which matches exactly one of the four titles: a loop that filtered shows 1 row, a loop that did not shows 4.

**v3** adds two custom fields, and their values are chosen so each filter yields a *distinct* count:

| request | rows |
|---|---|
| no filter | 4 |
| `search=Bravo` | 1 |
| `gbqf_color=red` (Meta Box) | 2 |
| `gbqf_size=large` (ACF) | 2 |
| `_gbqf_undeclared=zulu` (undeclared) | 4 — ignored |

2 is deliberately neither 1 nor 4: a field filter that silently fell back to "no filter" reads as 4, and one that collapsed onto the search reads as 1. The two fields also cut across each other — `{alpha, charlie}` vs `{bravo, charlie}` — so a mixed-up result cannot land on the right count by accident.

Ownership is **disjoint**: the `scoped` block owns only the Meta Box field, the `classmatch` block owns only the ACF field. Each §9 request therefore asks a block to filter on precisely the field the *other* block owns.

### Taxonomy labeling page (v5)

A second page, `/gbqf-taxonomy/`, carries two filter blocks and no loop: one renders Categories, Tags and the extra taxonomy as checkbox **sets**, the other as **selects**. §6 reads it for the two ways a taxonomy control gets its name (a `role="group"` named by its label, a `<label for>` on the select) and for the id-uniqueness check across both blocks. It has its own page because those controls emit a checkbox per term on the site, which would be noise in the targeting page's body.

The tags are seeded onto the four fixture posts. The extra taxonomy is `department`, **borrowed from the core-structures blueprint** (`bws-gb-dynamic-tags-extensions`) for the taxonomy only: seed core-structures first, and never assign `department` a term here — its own term counts are a baseline of theirs, and site-views hides posts that carry one. `seed.php` and `verify.php` fail by name if it is absent, and §6 fails if a branch rendered nothing, rather than passing over a control that never appeared. There is no manifest-version pin: the dependency is "the taxonomy exists and has terms".

### Buttons page (v6)

A third page, `/gbqf-buttons/`, carries one filter block and no loop: Categories as the `buttons` control, Apply on, and `buttonClass` / `applyClass` seeded with dirty values (leading dots, a quote, angle brackets). §6 reads the radio group (`role="group"` named by its label, "Any" first with `value=""` and checked, one radio per term), the sanitized `buttonClass` tokens on every option (the checked one included) and no class data attributes, the Apply button, and the id checks. Rendering `buttons` as a select instead fails 8 of its 12 assertions. The seeder JSON-encodes the block with `JSON_HEX_TAG` and `wp_slash`es it: a raw `<` is stripped by kses under wp-cli, and an unslashed insert eats the JSON backslashes, either of which renders the block with default attributes and no error.

### Taxonomy-list page (v7)

A fourth page, `/gbqf-taxlist/`, carries one filter block and no loop, driven by the `taxonomies` attribute: `[post_tag select, category buttons, an unregistered slug, category again]`, with the legacy attributes seeded to contradict it (categories and tags on, as checkbox sets). §6 reads the rendered form for the four rules the list adds: display order is the list's (tags before categories; the legacy order is the reverse), control type is per taxonomy, the unregistered and repeated entries render nothing extra, and the legacy attributes are ignored once the list exists (any checkbox set means the fallback fired). Blocks saved before the attribute existed take the legacy path, which pages `taxonomy` and `buttons` still exercise.

### Search-label page (v8)

A fifth page, `/gbqf-searchlabel/`, carries two search-only filter blocks and no loop: one leaves `hideSearchLabel` unset (which is also what a block saved before the attribute looks like), the other sets it. §6 counts each shape across the page: one visible `gbqf-filter-label` "Search" label, one `screen-reader-text` label, no placeholder after a visible label, and the placeholder kept after the hidden one. Counting across the page means a visible-label block that kept its placeholder fails as a second placeholder instead of hiding behind the opt-out block. Written first against the old markup, it failed 3 of its 5 assertions (the "no placeholder after a visible label" check passes vacuously until a visible label exists).

## Current result

**98 passed** on `testbed` (GenerateBlocks 2.4.0, Meta Box Lite 2.8.0, ACF Pro 6.8.7, scope `targeted`), blueprint v8, against the `fix/search-label-contrast` branch (the visible search label). Through v7, 92 passed against the `feat/sidebar-redesign` branch (the `taxonomies` attribute). Through v6, 84 passed against the `feat/button-filters` branch (the `buttons` control). Through v5, 72 passed on 0.4.0 plus the pagination-key fix and the taxonomy labeling fix (14 of the 72 fail against the pre-fix markup).

## What it caught

Every defect below was committed here as a **failing** test before being fixed, so "this was broken and now is not" is a diff between two runs rather than a claim. v2 reported **2 failed, 23 passed** against 0.3.0 (`34602da` for §4 only, `28363e8` for §4 and §5); v3 reported **4 failed, 42 passed** before the ownership and label fixes (`be4f8d9`).

**§4 — security invariant 2 was violated.** The `legacy` loop, which no filter block targets, was filtered by flat `?gbqf_search=` params: the gate accepted any class matching `gbqf-target-*` without checking that a filter block had registered that name, and the separate target lookup then returned an unscoped struct, so `Params` read the flat namespace. The legacy rule was removed in 0.4.0.

**§5 — class-based scoped matching never filtered.** The `classmatch` loop is claimed by class, but the `Params` scope was taken from the **loop's own id** rather than the **matched target key** — the form wrote `gbqf[gbqf-alias][…]` while the query read `gbqf[gbqf-loop-classmatch][…]`, which nothing writes. Silent no-op. Callers now receive a `Target` carrying the registered key and cannot re-derive an id.

**§9 — an empty ownership list meant "owns everything".** The guard in `get_meta_filters()` / `get_acf_filters()` was skipped entirely when a block declared no fields, so every meta key in that block's URL namespace filtered. The default filter block enables no custom-field filtering at all, so the vulnerable list was the *default* one. Probed separately with an undeclared, protected `_`-prefixed key: correct value → 1 row, wrong value → 0 — a value oracle over arbitrary post meta, unauthenticated. Now: owns nothing, filters nothing.

**§6 — custom-field labels were not associated, and control ids collided.** Unreachable before v3, because no fixture rendered either branch; the check reported SKIPPED rather than passed and said so. Once reachable it found two things: the ACF label was bare in every case, and — separately — all three filter blocks emitted `id="gbqf_search_input"`, so every `for` on the page resolved to whichever control came first. §6 now asserts that each label reference resolves to **exactly one** element, which is the assertion a presence-only check could never make. See [ADR-0003](../../../docs/adr/0003-accessibility-target-current-wcag-aa.md).

§4 and §5 had one root cause: the targeting decision was derived in more than one place. `Targeting::match()` is now the only place it is derived. §9 is the same shape one level down — a Target carries field lists as well as a scope, and §8 exists to prove both travel off the matched target key rather than only the scope.

Sections 1 and 3 pass — scoped targeting works by id, and forged params for an unregistered target are ignored — which is what kept §4 and §5 attributable to the targeting rule rather than to the plugin being broken generally.

## Running it

```bash
# from the wp-litespeed env root, in WSL
bin/seed.sh testbed query-filters
bin/wp.sh testbed eval-file /plugins/gb-query-filter/tools/fixtures/query-filters/verify.php
tools/fixtures/query-filters/render-surface.sh --site testbed
```

Preconditions the seed asserts rather than assumes: gb-query-filter active, GenerateBlocks 2.0+, the `gbqf/query-filter` block registered, filter scope `targeted`, Meta Box and ACF active, and GBQF's own Meta Box and ACF toggles enabled. Each one, if wrong, turns the whole run green for the wrong reason — the integration toggles especially, since §9 asserts that a field does *not* filter, which passes trivially when no field filtering happens at all.

If the field controls render without options, the mu-plugin stub is missing — `seed.php` warns rather than fails when it cannot write it (wp-cli often runs as a different uid than the docroot owner). Installing it by hand is the normal fix.

## Known gaps

- **Two control shapes, not all of them.** The Meta Box field renders as a `select` and the ACF field as a radio group, so both naming mechanisms are exercised (`<label for>` and `role="group"` + `aria-labelledby`). Meta Box `text`, ACF `checkboxes` / `true_false` / `text` and the no-choices fallback are still unrendered by any fixture.
- **AJAX is off** on every fixture filter block except the v4 paging page. The AJAX path re-derives targeting in JS (`assets/js/gbqf-frontend.js`), and the targeting page measures the PHP rule; leaving it on would let a JS-side decision color a PHP-side result. The JS rule is untested. The paging page (`/gbqf-paging/`, blueprint v4) has AJAX on but only pins the server-rendered Reset href; the JS half of the pagination-key fix is a manual browser check (`docs/design-history/ajax-page-reset.md`).
- **Not registered in `seed-all.sh`.** That list lives in the wp-litespeed env repo, which is a separate repo — adding `query-filters` to `BLUEPRINTS` there is an env-side change to hand off, and since v5 it must declare `[query-filters]="core-structures"` so `department` is seeded first (core-structures' own README consumer table should gain a row too). `bin/seed.sh` is plugin-blind and finds this blueprint by glob today.
