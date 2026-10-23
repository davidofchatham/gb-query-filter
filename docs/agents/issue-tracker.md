# Issue tracker: hybrid, local specs and GitHub bugs

Two homes, split by ONE question: **must the record outlive the change?** The operative rules for this repo are this file plus `CLAUDE.md` §Spec lifecycle; process decisions live in tracked prose and `git log`, never in an ADR.

| Work | Home | Why |
|---|---|---|
| Bugs; anything someone outside waits on; a defect class on its 2nd instance | GitHub Issues on the fork (`gh` CLI) | Durable, externally visible |
| A spec for one in-flight piece of work | `.scratch/<feature-slug>/spec.md` | Rewritable in place; dies at merge |
| The build tickets it slices into | `.scratch/<feature-slug>/issues/<NN>-<slug>.md` | Numbered per feature from `01`, so they never collide with `#N` |
| A wayfinder effort (local only, never against GitHub) | `.scratch/<effort>/map.md` + `issues/<NN>-<slug>.md` | Same shape; an effort answers questions rather than building |
| Enhancements, open questions | a `.scratch/` home **plus** a `docs/future-work.md` item | The committed index; without it the work is invisible, not private |
| Long-lived design plans | `.scratch/plans/*.md`, finished ones in `plans/archive/` | Outlast any one feature |
| PUBLICATION of finished work | a PR body where there is a PR, **and** a lift into `docs/design-history/` | See §Publication |

`.scratch/` is gitignored. **Nothing under it is ever committed.** Unsure where something goes → ask. Do not default to GitHub.

## The local half

- One feature or effort per directory: `.scratch/<slug>/`
- Spec at `.scratch/<slug>/spec.md`
- Tickets one file each at `issues/<NN>-<slug>.md`, `01` up, dependency order, never one combined file
- Triage state is a `Status:` line near the top (role strings in `triage-labels.md`)
- Blocking is a `Blocked by: NN, NN` line near the top, naming same-directory ticket numbers
- Conversation appends under a `## Comments` heading
- Done: `Status: done (<sha>)` plus a `### Resolution` comment

## The GitHub half

This is a fork. Always pass `--repo davidofchatham/gb-query-filter`: with both `origin` and `upstream` configured, `gh` may otherwise target `claytonschase/gb-query-filter`. Never open an issue or PR against upstream unless asked.

- Create: `gh issue create --repo davidofchatham/gb-query-filter --title "..." --body "..."`
- Read: `gh issue view <number> --repo davidofchatham/gb-query-filter --comments`
- List: `gh issue list --repo davidofchatham/gb-query-filter --state open`
- Comment: `gh issue comment <number> --repo davidofchatham/gb-query-filter --body "..."`
- Label: `gh issue edit <number> --repo davidofchatham/gb-query-filter --add-label "..."`
- Close: `gh issue close <number> --repo davidofchatham/gb-query-filter --comment "..."`

**PRs as a request surface: no.** _(Set to `yes` if this repo treats external PRs as feature requests; `/triage` reads this flag.)_ GitHub shares one number space across issues and PRs, so a bare `#42` may be either: resolve with `gh pr view 42`, falling back to `gh issue view 42`.

**Text that will be POSTED is not hard-wrapped.** One paragraph, one line; blank lines separate blocks; tables and list items keep their own lines.

## IDs never appear bare

Three sequences coexist. Every citation carries its marker, always:

- `FW-7`: a `docs/future-work.md` tracker item
- `#7`: a GitHub issue
- `<feature-slug>/03`: a local ticket

A bare integer is ambiguous between all three.

## Committed files never cite a private path

`.scratch/` and `.claude/` resolve for one person and fail silently for everyone else. A committed file cites a live plan through its `FW-N` item, and a finished spec through its `docs/design-history/` file. `docs/future-work.md` is the one exempt file. Enforced by `tools/guards/check-private-citations.sh` in CI. Naming the convention is fine: a path with a `<placeholder>` or a `*` is a pattern, and only fully literal paths ending in `.md` fail.

## When a skill says "publish to the issue tracker"

- A **spec** → write `.scratch/<feature-slug>/spec.md`, and add its `docs/future-work.md` item.
- **Build tickets** → one file each under `.scratch/<feature-slug>/issues/`.
- A **bug**, or anything whose record must outlive the change → `gh issue create`.
- Unsure → ask.

## When a skill says "fetch the relevant ticket"

A path → read the file. A bare `#42` → `gh issue view 42 --repo davidofchatham/gb-query-filter --comments`.

## Wayfinding operations

Used by `/wayfinder`. The **map** is a file with one **child** per ticket. Local only.

- **Map**: `.scratch/<effort>/map.md`, with Notes / Decisions-so-far / Fog.
- **Child**: `.scratch/<effort>/issues/NN-<slug>.md`, question in the body. `Type:` records `research`/`prototype`/`grilling`/`task`; `Status:` records `open`/`claimed`/`resolved`.
- **Blocking**: `Blocked by: NN, NN`. Unblocked when every listed file is `resolved`.
- **Frontier**: open, unblocked, unclaimed; lowest number wins.
- **Claim**: set `Status: claimed` and save before any work.
- **Resolve**: append the answer under `## Answer`, set `Status: resolved`, then append a context pointer to Decisions-so-far in `map.md`.

## Publication: what survives the merge

A spec dies at merge. **Decide where each part lands BEFORE deleting its directory**, or the reasoning goes with it.

- **Lifting the finished spec (or its tickets, where there was no spec) into `docs/design-history/<slug>.md` is the normal path at merge**, not a special case.
- **The PR body is the second record, not a substitute.** It holds the review conversation and what changed under challenge. Where there is a PR, write both.
- **Load-bearing parts still migrate out**: invariants → PHPDoc or `CONTEXT.md`; decision and rationale → the ADR or `CONTEXT.md` entry that owns it; user-facing delta → CHANGELOG; why this change, now → the commit body.
- **A lifted file moves.** No copy stays in `.scratch/`. A partial lift leaves the remainder and says it is an extract. Lifting subjects the file to the PII guard private files never face, so sanitize as part of the lift.
- **A lifted file is frozen**, except that an INCORRECT identifier (renamed version, wrong SHA, dead issue number, moved path) is repaired in place silently. A STALE claim is not: a design the code has since moved past was true when written.
- **Never lift an unbuilt plan.** `docs/design-history/` contracts never to be corrected, so an unshipped intention filed there reads as a shipped decision.
- **Code cites anchors** (`CONTEXT.md`, an ADR, PHPDoc), not spec coordinates.
- On ship, move the item's row to Closed / retired in `docs/future-work.md` and point Landed at the CHANGELOG version and the lifted record.
