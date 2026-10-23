# CLAUDE.md

Guidance for Claude Code in this repository. This file is tracked on the fork's `main` only: the repo is not ours, so it holds agent workflow, not project knowledge, and it never goes into a pull request to `claytonschase/gb-query-filter` or into the release ZIP (`.distignore`). **Project knowledge lives in tracked docs; read those first:**

- [CONTEXT.md](CONTEXT.md) — what the plugin is, architecture (4 classes + JS), security & accessibility invariants, non-obvious gotchas, URL param conventions, conventions, testing.
- [docs/adr/](docs/adr/) — the *why* behind key decisions (targeted scope, URL-param state + Host Header Injection avoidance, WCAG 2.2 AA, hook priority 20).
- [docs/developer-filters.md](docs/developer-filters.md) — developer filters, per-block data attributes, query-arg merging.
- [docs/integrations.md](docs/integrations.md) — Meta Box / ACF field discovery and query mechanics.

Do not duplicate that material here. If a project fact changes, update CONTEXT.md / the relevant ADR, not this file.

## Working style

- Pure PHP + vanilla JS — **no build process**, edit files directly.
- Treat the security and accessibility invariants in [CONTEXT.md](CONTEXT.md) as non-negotiable: an a11y or security miss is a blocker, not a nit.
- Block attributes are defined where the block is registered in [class-gbqf-blocks.php](includes/class-gbqf-blocks.php) — read them there rather than maintaining a duplicate list.

## Agent skills

### Issue tracker

Hybrid: bugs on the fork's GitHub Issues, specs and tickets as markdown under `.scratch/<feature>/` (gitignored), non-bug work indexed in the committed `docs/future-work.md`. See `docs/agents/issue-tracker.md`.

### Spec lifecycle

- A spec dies at merge. Decide where each part lands before deleting its directory.
- A finished spec (or its tickets, where there was no spec) **lifts into `docs/design-history/` at merge**, as the normal path. A lifted file moves, is frozen, and an incorrect identifier is repaired in place while a stale claim is not. Never lift an unbuilt plan.
- Where there is a PR, the PR body is the second record, not a substitute.
- A committed file never cites a `.scratch/` or `.claude/` path: cite the `FW-N` item or the `docs/design-history/` file. `docs/future-work.md` is the one exemption.
- Ids are never bare: `FW-7` (tracker item), `#7` (GitHub issue), `<slug>/03` (local ticket).
- Tracked prose is scanned by `tools/guards/check-tracked-pii.sh` and `tools/guards/check-private-citations.sh` in CI, `CLAUDE.md` included. This file is the policy home for the PII guard.

### Triage labels

Five canonical triage roles, each mapped to its default string (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` + `docs/adr/` at repo root. See `docs/agents/domain.md`.
