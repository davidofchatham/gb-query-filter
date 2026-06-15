# ADR-0003: Accessibility target is current WCAG AA (WCAG 2.2 AA as of 2026-06)

**Status:** Accepted

## Context

The plugin renders interactive form controls (selects, checkboxes, search inputs, reset buttons) on public-facing pages, and swaps Query Loop content via AJAX. These are exactly the surfaces where accessibility regressions are easy to introduce and hard to notice.

Two things need separating, and the original version of this ADR conflated them:

1. **What standard we hold to** — and the fact that the standard itself moves over time.
2. **What we have actually verified about the existing code** — which, so far, is nothing formal.

## Decision

**Target the current WCAG AA level, not a frozen version.** As of this ADR (2026-06) that is [WCAG 2.2 AA](https://www.w3.org/WAI/WCAG22/quickref/). When a newer version becomes the W3C Recommendation (e.g. WCAG 3.0), the target follows it; this ADR is then superseded by one naming the new version. Treat "WCAG 2.2 AA" below as the snapshot at write-time, not an eternal pin.

**AA is an invariant for code we add or change — not a verified property of the existing plugin.** Concretely:

- Any control or rendered output that this work *adds or modifies* must meet current WCAG AA before it ships. Treat an a11y miss in your own diff like a security miss — a blocker.
- Practical checklist: every form input has an associated `<label>`; controls without a visible label get an `aria-label`; visually-hidden labels use `.screen-reader-text`; all controls keyboard-navigable with a visible focus indicator; AJAX content updates announce via an ARIA live region; colour contrast meets AA.

**The existing codebase is unaudited.** No formal WCAG audit has been run against the current plugin. Compliance of code we have not touched is *unverified* — do not assume it conforms, and do not state in docs or release notes that the plugin "is WCAG 2.2 AA compliant." A full baseline audit is a separate, open task (track it as an issue under `.scratch/` per the issue-tracker convention).

## Consequences

- The gate is enforceable because it applies to diffs we control, not to a whole codebase we have not measured.
- Until the baseline audit happens, the honest claim is "new and changed code targets WCAG AA; the plugin as a whole is not yet audited."
- When the standard version moves, write a superseding ADR rather than silently editing the version here.

## Alternatives rejected

- **Hard-pin WCAG 2.2 AA forever:** ages as the standard advances; forces the target to lie once 2.2 is superseded.
- **Claim the existing plugin is already AA-compliant:** unverified; would be a false assurance.
- **Downgrade AA to a non-binding goal:** too weak — it removes the gate on new work, which is the one place we *can* guarantee compliance.
- **WCAG 2.1 AA / AAA:** 2.1 is an older baseline (2.2 adds focus-appearance and target-size criteria relevant to these controls); AAA is stricter than the public-web norm and not warranted for this control set.
