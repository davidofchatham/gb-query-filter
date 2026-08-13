# ADR-0001: Targeted scope is the default

**Status:** Accepted

## Context

A filter block modifies a GenerateBlocks Query Loop. The naive implementation hooks `generateblocks_query_wp_query_args` and applies the filter to *every* Query Loop on the page. That breaks security invariant 2 in [CONTEXT.md](../../CONTEXT.md): a user could filter a loop that has no filter block connected to it — including loops the author never intended to be user-filterable, exposing or reshaping content via URL params or DOM injection.

## Decision

Default filter scope is `'targeted'`. A filter only affects the Query Loop whose HTML ID or class matches the filter block's `targetId`. `Targeting::match( $attributes )` decides this, returning a `Target` or `null`, checking: registered HTML ID → registered `targetId`-as-class. Nothing else claims a loop.

A blanket `'all'` mode still exists but only as a legacy escape hatch, reachable via the `gbqf_filter_scope` PHP filter. It is not exposed in the admin UI. Targeted is the contract. An **unrecognised** scope value is treated as `'targeted'`.

**Amended 0.4.0.** As originally implemented this decision was enforced in two places — a gate (`should_apply_to_attributes()`) and a separate lookup for the matched target (`get_matched_target()`) — which walked the same chain independently. They disagreed, and the disagreement broke the invariant this ADR exists to protect: a third match rule in the gate (any class containing `gbqf-target-`, with no check that a filter block had registered that name) admitted loops the lookup could not resolve, which were then filtered from flat URL params. A third re-derivation of the loop ID in the caller separately broke class-based targeting.

Two consequences, both now part of the decision rather than of its implementation:

- **The rule is enforced in exactly one place.** Adding a second "should this apply?" check anywhere reopens the class of bug, whatever the check says.
- **The `gbqf-target-*` legacy rule is removed.** It protected nothing — upstream never gated on it, and the only written form of the convention was a stale docblock naming a bare `gbqf-target` class that the `gbqf-target-` prefix does not even match.

## Consequences

- A loop with no connected filter block is never touched, satisfying invariant 2.
- Multiple independent filters can coexist on one page (each targets its own loop), which motivates the scoped URL params (`gbqf[targetId][...]`).
- Do not reintroduce blanket "apply to every loop" behavior. If a future feature needs cross-loop filtering, it must re-establish how invariant 2 stays intact.
- **An unscoped filter block (`targetId` empty) claims nothing under this scope.** It registers the key `''`, and a loop's derived ID is never empty. Flat `gbqf_*` params are therefore reachable only under `'all'` scope or a force-enabled loop. That follows from the decision rather than being a separate choice, but it was not stated originally and the flat convention was documented as generally available.
- **The invariant is testable.** [tools/fixtures/query-filters/](../../tools/fixtures/query-filters/) renders five Query Loops differing only in how a filter block claims them, and asserts over real HTTP. Both defects found in 0.4.0 were committed as failing tests before being fixed.

## Alternatives rejected

- **Apply to all loops (original behavior):** simplest, but violates invariant 2.
- **Opt-in per loop via a loop-side attribute:** would require modifying GenerateBlocks core or loop markup; the plugin deliberately does not modify GB core (hooks only).
