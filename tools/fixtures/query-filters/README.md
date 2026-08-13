# query-filters blueprint

Fixtures for two rules a filter block must not be able to break:

1. **Which** GenerateBlocks Query Loop it is allowed to touch —
   [ADR-0001](../../../docs/adr/0001-targeted-scope-default.md) and security
   invariant 2 in [CONTEXT.md](../../../CONTEXT.md).
2. **Which fields** it is allowed to filter on within a loop it does own —
   security invariant 3 (blueprint v3).

## Why it needs a render harness

`Filters::register_target()` runs from the filter block's `render_callback`,
and the targeting decision runs inside `generateblocks_query_wp_query_args`
while GenerateBlocks renders a `generateblocks/query` block. Under wp-cli
neither fires — so all five loops look identical there no matter what the
plugin does, and every "this loop was not filtered" check passes vacuously.
The same is true of field ownership: it is read from a rendering block's
attributes, so under wp-cli no block owns anything and §9 would pass vacuously
too.

So the split is deliberate:

| file | asserts |
|---|---|
| `schema.php` | registers the Meta Box and ACF fields (loaded on every request via a mu-plugin stub, because GBQF reads both registries at *render* time) |
| `verify.php` | the fixtures are real (posts, meta values, page, five loops, three filter blocks, both fields registered, ownership disjoint) |
| `render-surface.sh` | the targeting and ownership rules, against real HTTP responses |

## The fixture

One page, `/gbqf-targeting/`, with five Query Loops over the same four posts.
They differ only in how a filter block claims them, so a difference in the
rendered result set is attributable to the targeting rule and nothing else.

| loop | claimed by | expected |
|---|---|---|
| `control` | nothing | never filtered |
| `unscoped` | a filter block with `targetId ''` | open question |
| `scoped` | a filter block naming its id | filtered by its own scoped params |
| `legacy` | nothing, but carries `gbqf-target-orphan` | open question |
| `classmatch` (v2) | a filter block naming one of its **classes** | open question |

`classmatch` exists because every other loop is claimed by HTML ID, where the
matched **target key** and the **loop's own id** happen to be equal — so v1
could not tell which of the two the code actually uses. Here they differ on
purpose: target `gbqf-alias`, loop id `gbqf-loop-classmatch`.

Each loop emits `<MARKER>::<post title>` per row, so a rendered row count is a
substring count and never an HTML parse. The search term is `Bravo`, which
matches exactly one of the four titles: a loop that filtered shows 1 row, a loop
that did not shows 4.

**v3** adds two custom fields, and their values are chosen so each filter yields
a *distinct* count:

| request | rows |
|---|---|
| no filter | 4 |
| `search=Bravo` | 1 |
| `gbqf_color=red` (Meta Box) | 2 |
| `gbqf_size=large` (ACF) | 2 |
| `_gbqf_undeclared=zulu` (undeclared) | 4 — ignored |

2 is deliberately neither 1 nor 4: a field filter that silently fell back to "no
filter" reads as 4, and one that collapsed onto the search reads as 1. The two
fields also cut across each other — `{alpha, charlie}` vs `{bravo, charlie}` —
so a mixed-up result cannot land on the right count by accident.

Ownership is **disjoint**: the `scoped` block owns only the Meta Box field, the
`classmatch` block owns only the ACF field. Each §9 request therefore asks a
block to filter on precisely the field the *other* block owns.

## Current result

**47 passed** on `testbed` (GenerateBlocks 2.4.0, Meta Box Lite 2.8.0, ACF Pro
6.8.7, scope `targeted`), blueprint v3, against 0.4.0.

## What it caught

Every defect below was committed here as a **failing** test before being fixed,
so "this was broken and now is not" is a diff between two runs rather than a
claim. v2 reported **2 failed, 23 passed** against 0.3.0 (`34602da` for §4 only,
`28363e8` for §4 and §5); v3 reported **4 failed, 42 passed** before the
ownership and label fixes (`be4f8d9`).

**§4 — security invariant 2 was violated.** The `legacy` loop, which no filter
block targets, was filtered by flat `?gbqf_search=` params: the gate accepted
any class matching `gbqf-target-*` without checking that a filter block had
registered that name, and the separate target lookup then returned an unscoped
struct, so `Params` read the flat namespace. The legacy rule was removed in
0.4.0.

**§5 — class-based scoped matching never filtered.** The `classmatch` loop is
claimed by class, but the `Params` scope was taken from the **loop's own id**
rather than the **matched target key** — the form wrote `gbqf[gbqf-alias][…]`
while the query read `gbqf[gbqf-loop-classmatch][…]`, which nothing writes.
Silent no-op. Callers now receive a `Target` carrying the registered key and
cannot re-derive an id.

**§9 — an empty ownership list meant "owns everything".** The guard in
`get_meta_filters()` / `get_acf_filters()` was skipped entirely when a block
declared no fields, so every meta key in that block's URL namespace filtered.
The default filter block enables no custom-field filtering at all, so the
vulnerable list was the *default* one. Probed separately with an undeclared,
protected `_`-prefixed key: correct value → 1 row, wrong value → 0 — a value
oracle over arbitrary post meta, unauthenticated. Now: owns nothing, filters
nothing.

**§6 — custom-field labels were not associated, and control ids collided.**
Unreachable before v3, because no fixture rendered either branch; the check
reported SKIPPED rather than passed and said so. Once reachable it found two
things: the ACF label was bare in every case, and — separately — all three
filter blocks emitted `id="gbqf_search_input"`, so every `for` on the page
resolved to whichever control came first. §6 now asserts that each label
reference resolves to **exactly one** element, which is the assertion a
presence-only check could never make. See
[ADR-0003](../../../docs/adr/0003-accessibility-target-current-wcag-aa.md).

§4 and §5 had one root cause: the targeting decision was derived in more than
one place. `Targeting::match()` is now the only place it is derived. §9 is the
same shape one level down — a Target carries field lists as well as a scope, and
§8 exists to prove both travel off the matched target key rather than only the
scope.

Sections 1 and 3 pass — scoped targeting works by id, and forged params for an
unregistered target are ignored — which is what kept §4 and §5 attributable to
the targeting rule rather than to the plugin being broken generally.

## Running it

```bash
# from the wp-litespeed env root, in WSL
bin/seed.sh testbed query-filters
bin/wp.sh testbed eval-file /plugins/gb-query-filter/tools/fixtures/query-filters/verify.php
tools/fixtures/query-filters/render-surface.sh --site testbed
```

Preconditions the seed asserts rather than assumes: gb-query-filter active,
GenerateBlocks 2.0+, the `gbqf/query-filter` block registered, filter scope
`targeted`, Meta Box and ACF active, and GBQF's own Meta Box and ACF toggles
enabled. Each one, if wrong, turns the whole run green for the wrong reason —
the integration toggles especially, since §9 asserts that a field does *not*
filter, which passes trivially when no field filtering happens at all.

If the field controls render without options, the mu-plugin stub is missing —
`seed.php` warns rather than fails when it cannot write it (wp-cli often runs as
a different uid than the docroot owner). Installing it by hand is the normal fix.

## Known gaps

- **Two control shapes, not all of them.** The Meta Box field renders as a
  `select` and the ACF field as a radio group, so both naming mechanisms are
  exercised (`<label for>` and `role="group"` + `aria-labelledby`). Meta Box
  `text`, ACF `checkboxes` / `true_false` / `text` and the no-choices fallback
  are still unrendered by any fixture.
- **No coverage of the category / tag / taxonomy controls' labelling.** They are
  disabled here, and they use a `<span>` rather than a `<label>` — an unfixed
  gap noted in
  `.scratch/targeting-module/issues/03-group-control-labelling.md`.
- **No taxonomy coverage.** The category, tag and extra-taxonomy controls are
  disabled on every fixture block: they would render term checkboxes for every
  term on the site, which is noise in a body the harness counts strings in.
- **AJAX is off** on every fixture filter block. The AJAX path re-derives
  targeting in JS (`assets/js/gbqf-frontend.js`), and this blueprint measures
  the PHP rule; leaving it on would let a JS-side decision colour a PHP-side
  result. The JS rule is untested.
- **Not registered in `seed-all.sh`.** That list lives in the wp-litespeed env
  repo, which is a separate repo — adding `query-filters` to `BLUEPRINTS` there
  is an env-side change to hand off. `bin/seed.sh` is plugin-blind and finds this
  blueprint by glob today.
