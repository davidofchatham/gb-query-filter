# Future work tracker

**Not a roadmap.** No committed timeline on anything here. This is a single *visible* index of non-bug work: the tracked, reviewable surface over detail homes that are mostly private (`.scratch/` is gitignored). It duplicates no detail; open the linked home for the full design and rationale. One heading block per **`FW-N` id**.

- **Bugs do NOT go here** → GitHub Issues. See `docs/agents/issue-tracker.md` for the split and why.
- **Detail lives in its home**: a `.scratch/<slug>/` spec and its tickets, or a `.scratch/plans/*.md` file. An item states only *that the work exists, what gates it, what it touches, where it stands, and where to read more*.
- **Cross-refs use ids, never prose**, so a reworded item never orphans a reference. `FW-7` is an item here · `#7` is a GitHub issue · `<slug>/03` is a local ticket. A bare integer is ambiguous between all three.
- **Ids are permanent.** A shipped or cut item's id retires to the Closed / retired ledger, never reused or reassigned.
- **Fork-only.** This file, `CLAUDE.md`, `docs/agents/`, `docs/design-history/` and `tools/guards/` exist on this fork's `main` only. Never include them in a pull request to `claytonschase/gb-query-filter`.

## Index

- [Item shape](#item-shape)
- [Progress, not status](#progress-not-status)
- [Trackers](#trackers)
  - [Filtering and targeting](#filtering-and-targeting)
  - [Accessibility](#accessibility)
- [Closed / retired](#closed--retired)
- [Maintenance](#maintenance)

## Item shape

Each item is a `#### FW-N — <title>` heading followed by a fixed set of labeled lines:

- A **description** paragraph (1-3 sentences): what the item IS. Stable, rarely re-edited; not current state, not history.
- **Detail home:** where the design/rationale and the implicit certainty (concept vs planned) live. Never duplicated here.
- **Progress:** where the work actually stands. Always present, even if just "Not started." Rules below.
- **Open:** what's still undecided or unbuilt, when there's a real done/open split. Omitted when there's nothing beyond Progress worth stating separately.
- **Blocked by:** hard prerequisite, typed (below). **Interacts with:** soft coupling (reshapes, or is reshaped by) as `FW-N` ids, never a gate. Both on one line; `—` means unblocked.

### Typed blockers

A blocker is written as `<type>:<referent>`, because this field is agent-maintained and the type is what tells an agent whether it may act on the cell:

| Type | Means | Clears when |
|---|---|---|
| `row:FW-N` | Another item must land first | That item reaches Closed / retired; **an agent may clear it unasked** |
| `ship:X.Y.Z` | A version must ship first | That version is released; **an agent may clear it unasked** |
| `decision:<what>` | An open human choice | A human makes it. **Never auto-cleared, never auto-started** |
| `code:<condition>` | A state elsewhere that does not hold yet | The condition holds. Re-derivable by grep, so an agent may clear it, but must state the check in Progress |
| `—` | Unblocked | — |

**A blocker states a CODE FACT, never a scheduling preference**: "this cannot land until X", not "do this after X". If the ordering is a preference, it belongs in the description, not here.

> **Agent pickup:** an item is startable when `Blocked by:` is `—` or every `row:`/`ship:`/`code:` gate it names is satisfied. **A `decision:` gate is human-resolved. Do not auto-start it**, and do not resolve the decision yourself to unblock it. `Interacts with:` never blocks. An item whose Progress says a build is live is already being worked; read its Detail home for real state before touching it.

## Progress, not status

A `Progress:` line is allowed, but only for statements that stay true FOREVER once true: a half shipped, a measurement taken, a condition met. "Phase 2 of 3, ~60% done" is stale the moment work continues, so live estimates, phase names, percent-done and remaining-task counts belong in the branch, the plan, or the commit that owns the real state. **Progress is a few lines, never a build log.**

**There is no In-flight section.** Lifecycle is two-valued: an item is in a tracker section, or it is in **Closed / retired**. Progress carries the "someone is building this" bit by naming the branch or commit that holds the real state.

## Trackers

### Filtering and targeting

#### FW-1 — Filtered Query: a prebuilt Query Loop with its filter attached

A ready-made GenerateBlocks Element variation that holds a filter block and a Query Loop and wires them together by construction, so an author types no `targetId` and obeys no document-order rule. Adds to the existing standalone-filter route; does not replace it.

Detail home: `.scratch/filtered-query-wrapper/spec.md` and its tickets under `.scratch/filtered-query-wrapper/issues/`

Progress: Specified in a grilling session on 2026-10-14; the tickets are framed and none is started.

Blocked by: —  •  Interacts with: FW-2 (that gap is why v1 allows one filter block per container), FW-3 (the AJAX live region must sit outside the region the wrapper swaps), FW-5 (closed) (starter templates rely on the page-key fix)

#### FW-2 — Two filter blocks on one loop interfere

Two GBQF filter blocks with the same `targetId` overwrite each other's custom-field ownership in the registry, and one block's submit wipes its sibling's URL params. It fails closed, not open, but it is a functional gap.

Detail home: `.scratch/shared-target-filter-blocks/issues/01-two-filter-blocks-one-loop.md`

Progress: Not started. Found 2026-10-14.

Open: Whether two blocks on one loop is a supported configuration at all. If not, the fix is an editor and debug-log warning plus a doc line; if so, per-target field merging and per-block key sets.

Blocked by: decision:whether two filter blocks on one loop are supported  •  Interacts with: FW-1

### Accessibility

#### FW-3 — WCAG 2.2 AA baseline and its follow-ups

[ADR-0003](adr/0003-accessibility-target-current-wcag-aa.md) gates new and changed code on current WCAG AA but records the existing plugin as unaudited. This item carries the baseline audit. The defects it found are GitHub issues, not rows here: #4 (AJAX live region and focus), #5 (auto-apply keyboard browsing and the fetch race) and #6 (search label and control contrast).

Detail home: `.scratch/accessibility-audit/` (the baseline findings file and the baseline ticket under `issues/`)

Progress: An agent pass ran 2026-10-15 against 0.4.0 (`61715a8`) on GeneratePress with GenerateBlocks 2.4.1. Taxonomy checkbox group names fixed in `a3ecbb9`. The follow-ups were filed as #4, #5 and #6 on 2026-10-23. The pass is not a conformance claim.

Open: The human pass over the rows the agent pass marked needs-AT or needs-judgment.

Blocked by: —  •  Interacts with: FW-1

## Closed / retired

Append-only ledger. Ids are **permanent**: a retired `FW-N` is never reused or reassigned, and this ledger is the only record of the high-water mark.

| ID | Item | Outcome | Landed / detail home |
|---|---|---|---|
| FW-4 | Targeting module follow-ups | Three follow-ups to the 0.4.0 targeting module: the deprecated `Filters::register_target()` shim dropped, a repo-wide WPCS pass with a blocking phpcs workflow, and field labels built from the rendered control instead of a predicate that re-derived the render cascade. | `2b06351`, PR #3 (`7583ae2`), `61715a8`; `docs/design-history/targeting-module.md` |
| FW-5 | Reset the Query page key on filter change | Only the AJAX path failed: it kept `query-{id}-page`, so narrowing a filter from page 3 to a one-page result came back empty. Fixed in JS and in the server-rendered Reset URL, with a paging fixture page. | `68cb68f`; `docs/design-history/ajax-page-reset.md` |

## Maintenance

- **Order by id.** Items within a tracker section, and rows in Closed / retired, sit in ascending `FW-N` order. A new item goes at the end of its section; a closing row slots in by number.
- **New non-bug idea** → add a `#### FW-N` heading block under the right tracker section, using **(highest live id ∪ highest retired id) + 1**; never reuse a retired id. Put the detail in a home, not here. Don't let work exist *only* in a hidden file with no item here.
- **Build starts** → say so in `Progress:`, naming the branch or commit that owns the real state. Do not record phase, commit or remaining-task counts in the item.
- **Ships** → move the item to **Closed / retired** as a row, collapsing description + progress into one Outcome cell, and point Landed at the CHANGELOG version and the lifted `docs/design-history/` record. Then append ` (closed)` to its id wherever a live item's `Interacts with:` names it.
- **`Interacts with:` is recorded on both sides.** A one-sided link is invisible from the item that needed to see it.
- **An item may carry a COUNT only when the count IS the deliverable.** An argument count, cited as evidence for a claim, decays into a false statement that still reads as current.
- **Cut work retires too.** A withdrawn item moves to the ledger with its outcome; a deleted item reads as an id that never existed.

<!--
Detail-home paths under `.scratch/` are gitignored and unreadable to anyone but
the author -- that is the point of this file, not a defect in it. A committed
file elsewhere in the repo must cite the FW id, never the path.
-->
