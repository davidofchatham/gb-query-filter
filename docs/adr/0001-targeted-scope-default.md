# ADR-0001: Targeted scope is the default

**Status:** Accepted

## Context

A filter block modifies a GenerateBlocks Query Loop. The naive implementation hooks `generateblocks_query_wp_query_args` and applies the filter to *every* Query Loop on the page. That breaks security invariant 2 in [CONTEXT.md](../../CONTEXT.md): a user could filter a loop that has no filter block connected to it — including loops the author never intended to be user-filterable, exposing or reshaping content via URL params or DOM injection.

## Decision

Default filter scope is `'targeted'`. A filter only affects the Query Loop whose HTML ID or class matches the filter block's `targetId`. `should_apply_to_attributes()` gates this, checking: HTML ID → `targetId`-as-class → `gbqf-target-*` (legacy).

A blanket `'all'` mode still exists but only as a legacy escape hatch, reachable via the `gbqf_filter_scope` PHP filter. It is not exposed in the admin UI. Targeted is the contract.

## Consequences

- A loop with no connected filter block is never touched, satisfying invariant 2.
- Multiple independent filters can coexist on one page (each targets its own loop), which motivates the scoped URL params (`gbqf[targetId][...]`).
- Do not reintroduce blanket "apply to every loop" behavior. If a future feature needs cross-loop filtering, it must re-establish how invariant 2 stays intact.

## Alternatives rejected

- **Apply to all loops (original behavior):** simplest, but violates invariant 2.
- **Opt-in per loop via a loop-side attribute:** would require modifying GenerateBlocks core or loop markup; the plugin deliberately does not modify GB core (hooks only).
