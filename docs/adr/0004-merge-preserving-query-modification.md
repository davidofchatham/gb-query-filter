# ADR-0004: Merge-preserving query modification; priority 20 is a best-effort default

**Status:** Accepted

## Context

GBQF modifies `WP_Query` args via `generateblocks_query_loop_args` (GB 1.x) and `generateblocks_query_wp_query_args` (GB 2.0+). Other plugins (membership/portal, etc.) also modify the same query, typically at the WordPress default priority 10.

Two problems hide here, and they are not the same problem:

1. **Correctness of the merge** — when GBQF runs, does it preserve what's already in the args, or stomp it?
2. **Ordering** — does GBQF run early or late relative to the other plugins?

Conflating them leads to treating "run last" as if it were a guarantee. It isn't.

## Decision

**The contract is the merge semantics, not the priority.** Whenever GBQF's callback runs, it merges *onto* the args it receives rather than replacing them:

- `category__in` / `tag__in` — `array_merge` with existing values.
- `tax_query` / `meta_query` — append clauses, preserve existing `relation`.
- Search `s` — replaced, unless `gbqf_preserve_search` is enabled.

This holds at **any** priority. It is the property that must never regress.

**Priority is a best-effort default, configurable.** GBQF registers at priority 20 by default (`Settings::get_filter_priority()`, overridable via the `gbqf_filter_priority` filter or the admin option). 20 is chosen so GBQF runs after the priority-10 crowd and its merge is the last word in the common case. This is a heuristic, not a guarantee.

## Consequences

- **Known limitation — priority 20 can still conflict.** A plugin registering at >20, or another callback that also picks 20 (tie → undefined registration order), defeats "run last." There is no priority value that wins universally.
- **Failure mode is benign for security.** If ordering is lost and a later plugin overwrites GBQF's additions, the visible effect is that the filter does nothing for that loop — it does **not** breach the security invariants in [CONTEXT.md](../../CONTEXT.md). Invariant 1 (no privilege leak) and invariant 2 (no filtering an unconnected loop) are enforced in `should_apply_to_attributes()` and input sanitization, independent of priority. Priority affects *whether the filter visibly applies*, never *what a user is allowed to see*.
- A site with a known conflicting plugin can retune via `gbqf_filter_priority`; there is no setting that removes the underlying fragility.

## Alternatives rejected

- **Default priority 10:** undefined ordering vs other query plugins; observed clobbering.
- **Very high priority (e.g. 999):** still loses to any plugin that also goes high, and to ties; trades one arbitrary number for another while implying a guarantee that doesn't exist.
- **Treat "run last" as a contract:** rejected — no priority guarantees last-run across an unknown plugin ecosystem. The durable guarantee is the merge, so that is what the ADR commits to.
