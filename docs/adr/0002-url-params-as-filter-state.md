# ADR-0002: Filter state lives in URL params

**Status:** Accepted

## Context

Front-end filter selections need somewhere to live. Options: server session, cookies, POST-only form state, or URL query params.

## Decision

Filter state lives entirely in URL query params (flat `gbqf_*` or scoped `gbqf[targetId][...]`). AJAX updates the URL via `history.replaceState`; the form also works as a plain GET submit with JS disabled.

The reset URL — and any plugin-built URL — is constructed with `home_url()`, never from `$_SERVER['HTTP_HOST']`.

## Consequences

- Filtered views are shareable and bookmarkable; back/forward works.
- No server-side state to store or expire.
- **Security:** because the entire filter state is attacker-controllable (it's in the URL), every param must be sanitized before reaching `WP_Query`, and the filter must never let a param override a loop's preset filters (invariant 1 in [CONTEXT.md](../../CONTEXT.md)). This is the cost of URL state and is accepted deliberately.
- **Host Header Injection:** building URLs from `home_url()` rather than `$_SERVER['HTTP_HOST']` means a forged `Host` header cannot poison the reset link or any generated URL.

## Alternatives rejected

- **Cookies / session:** not shareable, not bookmarkable, adds server state and consent surface.
- **POST-only:** breaks bookmarking and the no-JS fallback.
- **`$_SERVER['HTTP_HOST']` for URL building:** convenient but exposes Host Header Injection.
