# Button filter control (FW-6)

> **DESIGN HISTORY — A RECORD, NOT A CURRENT-STATE SOURCE.** This file says what was decided and built when it was written, and is not corrected when code later moves. Cite it for provenance, never as a statement of how the code works now. For current state read [CONTEXT.md](../../CONTEXT.md), the [ADRs](../adr/), and the code.

**Provenance.** A spec from a grilling session on 2026-10-23 and its five tickets, built the same day on branch `feat/button-filters` and merged as PR #9 (`5a5bd5d`). They were private local files; the spec and tickets are lifted here whole, headings demoted one level, ticket status lines moved under their headings. A mid-build revision (Q15, 2026-10-23) dropped the separate active-state class and the JS toggle in favor of one `buttonClass` and CSS `:has(input:checked)`. The spec records that revision in decisions 5 and 7, but the tickets were written before it and still name four class attributes; ticket 02 was closed as wontfix for that reason. The deferred picker in decision 9 is tracked as FW-7. One local spec path was removed and one private plugin reference removed when lifted.

---

## Spec

Status: ready-for-agent

Author-chosen `buttons` control type for short choice sets (a handful of terms), shown as a row of styled buttons with a leading "Any", plus author-supplied GenerateBlocks style classes for the option buttons, the Apply button and the Reset link.

### Decisions (settled in grilling)

1. **Where.** New `buttons` value beside `select` / `checkboxes` on `categoriesControlType`, `tagsControlType`, `extraTaxonomiesControlType`, and in the Meta Box / ACF per-field control override (fields that already offer `select` / `radio`). Search stays a text input. Explicit opt-in only: no auto-switch by term count, no hiding of one-term filters.
2. **Semantics.** Single-select, one active at a time, leading "Any" (`value=""`, existing translatable string) that clears the field. Multi-select stays `checkboxes`. No PHP change to value handling: `Params` already drops empty values (`array_filter( absint )`) and the AJAX path already skips empty strings.
3. **Markup.** Native radios, not `aria-pressed` buttons: `<div role="group" aria-labelledby=…>` of `<label class="gbqf-filter-option [classes]"><input type="radio" class="screen-reader-text"><span>…</span></label>`. Keyboard, labels and the no-JS form submit come for free (ADR-0003). Reuse `taxonomy_field()`'s group/label pattern. Your classes go on the label.
4. **Focus.** The focused element is the hidden input, so a GB class's own `:focus` rule does not fire on keyboard focus. Ship `label:has(:focus-visible)` outline in our CSS. Accepted gap: no brightness-on-focus effect for keyboard users in the first cut.
5. **Class attributes (block-wide, not per field).** Three free-text, space-separated strings: `buttonClass` (every option, checked or not), `applyClass` (Apply `<button>`), `resetClass` (Reset `<a>`). Revised 2026-10-23 (Q15): no separate active-class box, since a GB style is one class carrying its own states. The selected look lives inside the author's GB style as `&:has(input:checked) { … }`; the checked option is always bold regardless of classes (non-color floor). Each token: strip a leading `.`, then `sanitize_html_class`. Hover/focus come from the author's GB style itself; we add none when classes are set.
6. **Default CSS.** In `gbqf-frontend.css`, wrapped in `:where()` (zero specificity, so any author class wins). Flex-wrap row, 44px min target, visible focus ring, checked state by fill **and** bold (not color alone). Colors from `--gbqf-*` custom properties with neutral fallbacks. Applies fully when the class boxes are empty.
7. **No active-state JS** (revised by Q15). The selected state is pure CSS via `:has(:checked)`, so no toggle script and no `data-gbqf-*` class attributes.
8. **Editor.** One "Button Styles" `PanelBody` (Title Case, like the other panels) in `gbqf-query-filter-block.js`, shown only when some control is `buttons` or Apply is enabled. `TextControl` per class box with help text ("Space-separated GenerateBlocks style class names"); the Apply box only when Apply is on.
9. **Deferred.** A `FormTokenField` picker suggesting the site's GB global-style class names (as GB itself shows). Only if GB exposes the list to the editor, unchecked. Attributes stay plain strings so it can replace the text boxes later. Log as an `FW-N` item.

### Out of scope

Client-side narrowing of options, one-term-filter hiding, `aria-pressed` buttons, multi-select buttons, per-field class overrides.

### Touch points

- `includes/class-gbqf-blocks.php`: register three attributes; `taxonomy_field()` and the Meta Box / ACF choice rendering gain `buttons`; Apply `<button>` (~line 1004) and Reset `<a>` (~line 1008) take class strings; one class-token sanitizer.
- `assets/js/gbqf-query-filter-block.js`: `buttons` option in the control selects and field overrides; "Button styles" panel.
- `assets/css/gbqf-frontend.css` (+ `gbqf-editor.css` if the editor preview needs it): decision 6.
- `CHANGELOG.md`, `docs/developer-filters.md` (new attributes), version bump.

### Acceptance

- A taxonomy set to `buttons` renders a labelled `role="group"` of radios with an "Any" `value=""` option; selecting a term filters, "Any" clears, URL params identical to the `select` control.
- Works with Apply on or off, AJAX on or off, and with JS disabled (plain submit).
- Class tokens appear on every option / Apply / Reset, sanitized; `.gbp-button--primary` pasted with its dot works; the checked option is styled via `:has(input:checked)` and always bold.
- Keyboard: arrow keys move within the radio group; focus is visible.
- Meets ADR-0003 for every control added; the invariants in `CONTEXT.md` unaffected (Targeting untouched).

### Delivery

Branch + PR. Commits per the carve rules: (1) core-path `feat:` (PHP, JS, CSS, CHANGELOG, `docs/developer-filters.md`, version bump); (2) separate `test:` commit adding one `buttons` taxonomy render case to `tools/fixtures/query-filters/render-surface.sh` (radio group markup, "Any" option, sanitized class tokens on options and Apply; the JS toggle stays manual); (3) fork-only commit adding the picker `FW-N` item to `docs/future-work.md`. No committed file cites a private client path.

---

## 01 — Server render: `buttons` control, class attributes, default CSS

Status: done (6774d5b, fb7d450)
Blocked by: none

Spec decisions 1-6; the checked-state class is server-rendered here, the live toggle is ticket 02.

- Register `buttonClass`, `buttonActiveClass`, `applyClass`, `resetClass` (string, default `''`) in `includes/class-gbqf-blocks.php`.
- One token sanitizer (strip leading `.`, `sanitize_html_class`, drop empties), used for all four.
- `taxonomy_field()` gains `buttons`: `role="group"` of `<label class="gbqf-filter-option …"><input type="radio" class="screen-reader-text">…</label>`, leading "Any" `value=""`, checked option carries `buttonActiveClass` instead of `buttonClass`. Same name/value contract as the `select`.
- Same `buttons` rendering for the Meta Box / ACF choice fields that already offer `select`/`radio`.
- Apply `<button>` and Reset `<a>` take `applyClass` / `resetClass`.
- `:where()`-wrapped default CSS in `assets/css/gbqf-frontend.css` per spec decision 6, incl. `label:has(:focus-visible)` outline.
- Escape all output; ADR-0003 applies to every control added.

Done when: a block with `categoriesControlType: buttons` renders the group with the right markup over HTTP, filtering via URL params identical to `select`; PHP lints (`composer lint`).

### Comments

#### Resolution

On branch `feat/button-filters`, PR #9. Q15 (2026-10-23) replaced the inactive/active class pair with one `buttonClass` and CSS `:has(input:checked)`; the JS toggle was removed.

---

## 02 — Frontend: active-class toggle

Status: wontfix (2953fcd, superseded by 28f97d3)
Blocked by: 01

Spec decision 7. In `assets/js/gbqf-frontend.js`, on `change` of a radio in a buttons group, recompute the inactive/active class on every label in that group. The class strings must reach the JS (data attributes on the block wrapper, set in ticket 01's markup or here; keep it to the minimum). Must sit **outside** `if ( autoApply )`. No AJAX-sync work is needed.

Done when: with Apply on and with auto-apply on, clicking an option moves the active class; Reset (page reload) shows the server-rendered active class; no console errors. Manual verification (the JS is not covered by fixtures).

### Comments

#### Resolution

On branch `feat/button-filters`, PR #9. Q15 (2026-10-23) replaced the inactive/active class pair with one `buttonClass` and CSS `:has(input:checked)`; the JS toggle was removed.

---

## 03 — Editor UI

Status: done (3ca63c0, 28f97d3)
Blocked by: 01

Spec decisions 1 and 8, in `assets/js/gbqf-query-filter-block.js` (and `gbqf-editor.css` only if the editor preview needs it).

- `buttons` option in the three taxonomy control selects and in the Meta Box / ACF per-field control override.
- "Button styles" `PanelBody`: four `TextControl`s (`buttonClass`, `buttonActiveClass`, `applyClass`, `resetClass`) with help "Space-separated GenerateBlocks style class names"; panel shown only when some control is `buttons` or Apply is on; Apply box only when Apply is on.
- Attribute names exactly as registered in ticket 01 (`class-gbqf-blocks.php`).

Done when: the options and panel appear and persist in the editor, and the saved block renders with them. Manual verification.

### Comments

#### Resolution

On branch `feat/button-filters`, PR #9. Q15 (2026-10-23) replaced the inactive/active class pair with one `buttonClass` and CSS `:has(input:checked)`; the JS toggle was removed.

---

## 04 — Fixture render check

Status: done (609a06e, 5479fa5, 7440aa3)
Blocked by: 01

Spec "Delivery" commit 2. Add one taxonomy field set to `buttons` to the `tools/fixtures/query-filters/` blueprint and one render check in `render-surface.sh`: radio group markup, "Any" `value=""` option, sanitized class tokens on the options and the Apply button. See that directory's README for how the harness runs. This is its own `test:` commit, touching only fork-only paths.

Done when: the new check passes against the branch and fails if `buttons` rendering is removed.

### Comments

#### Resolution

On branch `feat/button-filters`, PR #9. Q15 (2026-10-23) replaced the inactive/active class pair with one `buttonClass` and CSS `:has(input:checked)`; the JS toggle was removed.

---

## 05 — Docs, changelog, version

Status: done (04b177e, 03558d1, 6247dfd)
Blocked by: 01, 02, 03

`CHANGELOG.md` entry (Keep a Changelog), `docs/developer-filters.md` (the four class attributes and the `buttons` control type), `GBQF_VERSION` bump in `gb-query-filter.php`, a line in `README.md` and `CONTEXT.md` only if a fact there changed. No `.scratch/` or private client path cited anywhere committed. American spelling.

Done when: a doc-only core-path commit (or folded into the feat commit per the spec) matches the shipped attribute names.

### Comments

#### Resolution

On branch `feat/button-filters`, PR #9. Q15 (2026-10-23) replaced the inactive/active class pair with one `buttonClass` and CSS `:has(input:checked)`; the JS toggle was removed.
