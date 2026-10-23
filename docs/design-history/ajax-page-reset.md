# Reset the Query page key on filter change (FW-5)

> **DESIGN HISTORY — A RECORD, NOT A CURRENT-STATE SOURCE.** This file says what was decided and built when it was written, and is not corrected when code later moves. Cite it for provenance, never as a statement of how the code works now. For current state read [CONTEXT.md](../../CONTEXT.md), the [ADRs](../adr/), and the code.

**Provenance.** One ticket, found 2026-10-14 while grilling the filtered-query wrapper (FW-1) and fixed in `68cb68f` on 2026-10-15. It was a private local ticket and had no separate spec; it is lifted whole, headings demoted one level. Links were repaired when lifted.

---

## Changing a filter keeps the Query's page parameter

Status: done (68cb68f)

### Context

Found 2026-10-14 while grilling the filtered-query wrapper (FW-1). Exists on the current targeting route; needs no wrapper to reproduce.

GB's Query block pages through `query-{uniqueId}-page` (see `GenerateBlocks_Block_Query::render_block`). In [gbqf-frontend.js](../../assets/js/gbqf-frontend.js) the AJAX request starts from `window.location.href`, strips only the filter block's own `gbqf` keys, then appends the form values. The page key is untouched. A grep of `gbqf-frontend.js`, [class-gbqf-params.php](../../includes/class-gbqf-params.php) and [class-gbqf-filters.php](../../includes/class-gbqf-filters.php) finds no handling of it.

### Failure

On page 3 of a loop, the user narrows a filter so the results fit on one page. The request keeps `query-xxxx-page=3`, WordPress finds no page 3, and the loop comes back empty with the filter still applied. The non-AJAX path (a plain GET submit) is a separate case: a GET form replaces the query string, so the page key is dropped there. Confirm which path actually fails before fixing.

### Scope

- Remove every `query-*-page` key for the target loop when a filter changes, on the AJAX path. The Query's key depends on its `uniqueId`; the filter block knows the target's id, so work out whether it can derive the key or should drop all `query-*-page` params on a filter change.
- Decide whether Reset also clears it (it should).
- Verify on both paths, AJAX on and off.

### Acceptance

Filtering from page 3 to a result set of one page shows that result set. Reset returns to page 1. No change to behavior when no page key is present.

### Comments

#### Resolution (2026-10-15)

Only the AJAX path failed; the non-AJAX GET form already dropped the key. Reset kept it on both paths.

- JS `removePageParams()` runs on every `ajaxSubmit` and in `updateResetUrl()`. It removes `query-{id}-page`, with `{id}` taken from the target element's own `gb-query-{id}` class, or every `query-*-page` when no such class exists.
- PHP `Params::get_reset_url()` strips every `query-*-page` (it cannot map a target to a `uniqueId`); JS narrows the href at init.
- Fixture blueprint v4 adds `/gbqf-paging/` (1 post per page, 4 pages, AJAX on). `render-surface.sh` §10 pins the stale-page state and the server-rendered Reset href: red before the PHP change (2 failed), green after (52 passed).

Manual browser checklist, run with chrome-devtools-mcp on `testbed` (all passed):

- [x] `?query-gbqfpag0-page=3`, search `Bravo`, Enter: one fetch to `/gbqf-paging/?gbqf[gbqf-loop-paging][search]=Bravo` (no page key), loop shows Bravo, `replaceState` URL matches.
- [x] Same from a URL that also carries `query-other0000-page=2`: the other key is kept in the request, the address bar and the Reset href; only `query-gbqfpag0-page` is dropped. The stale-page URL rendered an empty loop before the submit.
- [x] Reset href on load at page 3 is `/gbqf-paging/` (JS narrowing at init); with another Query's key present it keeps only that key.
- [x] Non-AJAX: native `form.submit()` from page 3 lands on `?gbqf[...][search]=Bravo` with no page key and shows Bravo.
- [x] Target wrapper carries `gb-query gb-query-gbqfpag0` as saved by the editor (fixture mirrors that markup; not yet confirmed against an editor-authored page).
