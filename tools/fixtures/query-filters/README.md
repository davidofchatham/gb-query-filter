# query-filters blueprint

Fixtures for the one thing in this plugin that must not be wrong: **which
GenerateBlocks Query Loop a filter block is allowed to touch**. That is
[ADR-0001](../../../docs/adr/0001-targeted-scope-default.md) and security
invariant 2 in [CONTEXT.md](../../../CONTEXT.md).

## Why it needs a render harness

`Filters::register_target()` runs from the filter block's `render_callback`,
and the targeting decision runs inside `generateblocks_query_wp_query_args`
while GenerateBlocks renders a `generateblocks/query` block. Under wp-cli
neither fires — so all four loops look identical there no matter what the
plugin does, and every "this loop was not filtered" check passes vacuously.

So the split is deliberate:

| file | asserts |
|---|---|
| `verify.php` | the fixtures are real (posts, page, four loops, two filter blocks) |
| `render-surface.sh` | the targeting rule, against real HTTP responses |

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
that did not shows 4. There is no third outcome, which is what makes an
unexpected count worth stopping on rather than interpreting.

## Current result

Measured on `testbed` (GenerateBlocks 2.4.0, scope `targeted`), blueprint v2:
**2 failed, 23 passed**. Both failures trace to the same root — the targeting
decision is derived more than once, from more than one place.

**§4 — security invariant 2 is violated.** The `legacy` loop, which no filter
block targets, is filtered by flat `?gbqf_search=` params.
`should_apply_to_attributes()` accepts the `gbqf-target-` class prefix without
checking that anything registered that name; `get_matched_target()` has no such
rule and returns a `scoped=false` struct, so `Params` reads the flat namespace.

**§5 — class-based scoped matching never filters.** The `classmatch` loop is
claimed by class, but [`class-gbqf-filters.php:416`](../../../includes/class-gbqf-filters.php#L416)
takes the `Params` scope from the **loop's own id** rather than the **matched
target key**. The form writes `gbqf[gbqf-alias][…]`; the query reads
`gbqf[gbqf-loop-classmatch][…]`, which nothing writes. Silent no-op.

Sections 1 and 3 pass — scoped targeting works by id, and forged params for an
unregistered target are correctly ignored — which is what makes §4 and §5
attributable to the targeting rule rather than to the plugin being broken
generally.

## Running it

```bash
# from the wp-litespeed env root, in WSL
bin/seed.sh testbed query-filters
bin/wp.sh testbed eval-file /plugins/gb-query-filter/tools/fixtures/query-filters/verify.php
tools/fixtures/query-filters/render-surface.sh --site testbed
```

Preconditions the seed asserts rather than assumes: gb-query-filter active,
GenerateBlocks 2.0+, the `gbqf/query-filter` block registered, and filter scope
`targeted`. Each one, if wrong, turns the whole run green for the wrong reason.

## Known gaps

- **No ACF or Meta Box coverage.** The filter blocks enable search only, so the
  ACF branch never renders and the bare-`<label>` check in §5 reports SKIPPED
  rather than passed. Reaching the defect at `class-gbqf-blocks.php:851` needs a
  seeded ACF field group — blueprint v2. Meta Box is not installed on `testbed`
  at all.
- **AJAX is off** on every fixture filter block. The AJAX path re-derives
  targeting in JS (`assets/js/gbqf-frontend.js`), and this blueprint measures
  the PHP rule; leaving it on would let a JS-side decision colour a PHP-side
  result. The JS rule is untested.
- **Not registered in `seed-all.sh`.** That list lives in the wp-litespeed env
  repo, which is a separate repo — adding `query-filters` to `BLUEPRINTS` there
  is an env-side change to hand off. `bin/seed.sh` is plugin-blind and finds this
  blueprint by glob today.
