# Targeting module follow-ups (FW-4)

> **DESIGN HISTORY — A RECORD, NOT A CURRENT-STATE SOURCE.** This file says what was decided and built when it was written, and is not corrected when code later moves. Cite it for provenance, never as a statement of how the code works now. For current state read [CONTEXT.md](../../CONTEXT.md), the [ADRs](../adr/), and the code.

**Provenance.** Three tickets framed after the 0.4.0 targeting-module release, worked in sequence 01, 02, 03 between 2026-10-14 and 2026-10-15. They were private local tickets and had no separate spec; they are lifted here whole, headings demoted one level. Landed as `2b06351` (01), PR #3 / `7583ae2` (02, branch `chore/wpcs-phpcs`) and `61715a8` (03). Links and one path were repaired when lifted.

---

## Drop the deprecated `Filters::register_target()` shim

Status: done (2b06351)

### Context

0.4.0 moved target registration from `Filters` to the new `Targeting` module
([ADR-0001](../adr/0001-targeted-scope-default.md), amended). The old
entry point was kept as a forwarder rather than deleted:

```php
public static function register_target( $target_id, $args = [] ) {
    _deprecated_function( __METHOD__, '0.4.0', 'GBQF\Targeting::register' );
    Targeting::register( $target_id, $args );
}
```

It was kept because 0.4.0 already carried a behaviour change (the legacy
`gbqf-target-*` match removed, unknown scope values failing closed), and adding a
fatal on top would have made any breakage report harder to attribute. It is the
only **public** member that moved; the six `protected` ones were deleted outright.

### Why it should go

`Filters` should have nothing to do with targeting. While the shim exists the
deletion test is not clean: `Filters` still names the concept, and a reader can
still reach registration through it. That is a small version of exactly the shape
that produced the 0.4.0 defects — two doors into one decision.

### Decisions (reassessed before implementation)

- **Drop it now.** The earlier "two releases of deprecation notice" note is
  overridden: 0.4.0 is two months old and the next release is a minor (0.5.0) that
  bundles several changes, so the removal rides along with it.
- Sequence: **01 → 02 → 03.** This goes first so the 02 whitespace pass doesn't
  churn `class-gbqf-filters.php` after the fact.
- Branching: small, confirmed, short-lived → **commit direct to `main`**.
- Versioning: the CHANGELOG entry goes under `[Unreleased]`. Do **not** set or
  bump a version; the maintainer sets it at release time.

### Scope

- Delete `Filters::register_target()` from `includes/class-gbqf-filters.php`.
- Confirm no in-repo callers remain (`Blocks` moved to `Targeting::register()` in
  0.4.0; verify nothing has crept back).
- Fix the stale references that still describe `Filters::register_target()` as the
  thing the filter block's `render_callback` calls — it has been
  `Targeting::register()` since 0.4.0:
  - `tools/fixtures/query-filters/manifest.php` (~line 28)
  - `tools/fixtures/query-filters/verify.php` (~line 6)
  - `tools/fixtures/query-filters/README.md` (~line 13)
  - `tools/fixtures/query-filters/render-surface.sh` (~line 8)
- CHANGELOG `Removed` entry under `[Unreleased]`, noting it was deprecated in
  0.4.0. Update (don't duplicate) the existing 0.4.0 line that says the method
  "still forwards".

### Acceptance

- `grep -rn 'register_target' includes/ tools/` returns nothing.
- `tools/fixtures/query-filters/render-surface.sh --site testbed` still passes in
  full — the fixture exercises registration on every request, so a broken move
  shows up as loops that stop filtering rather than as a silent regression.
- No version bump in this change.

### Notes

- Not a security matter — the shim forwarded correctly. This is a tidiness/locality
  issue: while it exists, `Filters` still names targeting and there are two doors
  into one decision.
- `register_target()` was public in 0.2.0 and 0.3.0, so an unknown third-party
  caller is conceivable but unlikely; the maintainer has accepted that.

### Comments

#### Resolution (2026-10-15)

Committed direct to `main` as `2b06351`; no version bump.

- `Filters::register_target()` and its docblock deleted. No in-repo callers remained; `grep -rn 'register_target' includes/ tools/` is empty.
- The four fixture references now name `Targeting::register()`. The fixture README's prose was unwrapped while touched (whitespace only).
- CHANGELOG: `Removed` entry under `[Unreleased]`; the 0.4.0 `Deprecated` line reworded in place to say the method has since been removed.
- `render-surface.sh --site testbed`: **52 passed**. It must run from WSL (`wsl -e bash -lc 'cd <repo> && bash tools/fixtures/query-filters/render-surface.sh --site testbed'`); from Git Bash it looks for a retired copy of the local environment and fails.
- Code review (standards + spec): no spec gaps; standards nits (wrapped changelog bullet, dangling `[Unreleased]` link text) fixed before commit.

---

## Repo-wide WPCS pass + a phpcs config

Status: done (7583ae2)

### Context

[CONTEXT.md](../../CONTEXT.md) names "WordPress coding standards" as a
convention, but nothing enforces it and the tree does not follow it
consistently. Indentation is the visible split:

- `includes/class-gbqf-settings.php` — tabs (WPCS)
- everything else in `includes/` — 4 spaces
- `tools/fixtures/query-filters/*.php` — tabs

The 0.4.0 targeting work added `class-gbqf-target.php` and
`class-gbqf-targeting.php` using 4 spaces, following the local precedent set by
`class-gbqf-filters.php` — which a code review flagged against the documented
standard.

### Why this was deferred rather than fixed there

Converting two files makes the repo *more* inconsistent, not less, and it would
have buried a security fix under whitespace churn. The split is pre-existing and
repo-wide; it wants one deliberate pass, on its own, where the diff is
reviewable as exactly what it is.

### Decisions (reassessed before implementation)

- Sequence: **01 → 02 → 03.** Start only after 01 is on `main` (01 edits
  `class-gbqf-filters.php`); 03 must wait for this to merge (it edits
  `class-gbqf-blocks.php`, which this reindents).
- Branching: multi-commit and adds CI → **own branch + PR**. `main` is clean and no
  other feature branch is open, so the "no open branch" precondition holds.
- **Tabs in all PHP.** `class-gbqf-settings.php` and `tools/fixtures/*.php` already
  use tabs; convert the 4-space files (`class-gbqf-blocks`, `-filters`, `-params`,
  `-plugin`, `-target`, `-targeting`, and `gb-query-filter.php`).
- **PHP only.** JS (`assets/js/`, 4-space) and CSS are out of scope — WPCS JS/CSS
  rules need an ESLint/stylelint toolchain. File a separate issue if wanted.
- **CI is blocking**, with an `<exclude>` list in `phpcs.xml` for anything knowingly
  declined; every exclude carries a one-line reason.
- phpcs is **not installed locally** (php and composer are). Add a minimal
  `composer.json` (`require-dev` only: `wp-coding-standards/wpcs` plus the
  installer plugin, and a `lint` script) and commit `composer.lock`.
- Versioning: CHANGELOG entry (if any) under `[Unreleased]`. Do **not** set or bump
  a version.

### Scope

Commit order matters — keep the whitespace commit pure:

1. **Whitespace commit (only):** convert PHP to tabs, plus `*.php text eol=lf` in
   `.gitattributes`. No logic, no docblock, no phpcs-config changes in it.
2. `composer.json` + `composer.lock` + `phpcs.xml` (`WordPress-Extra` /
   `WordPress-Docs`, scoped to `includes/`, `gb-query-filter.php`, `tools/`;
   `vendor/` gitignored).
3. Fix or explicitly exclude whatever else phpcs reports — expect Yoda conditions,
   docblock gaps, and inline-comment punctuation.
4. New blocking GitHub Actions workflow running `composer lint` on PRs/pushes.
   (`release.yml` is the only workflow today, so this is a new file.) Confirm
   `release.yml` packaging keeps `vendor/` and `composer.*` out of the release ZIP.

### Acceptance

- `phpcs` runs clean (or with a documented, justified ignore list).
- The whitespace commit contains no logic change — verify with
  `git show --stat` and `git diff -w` being empty for that commit.
- The new workflow fails a PR on a phpcs error.
- The release ZIP contents are unchanged.
- `tools/fixtures/query-filters/render-surface.sh --site testbed` still passes
  in full afterwards.
- No version bump in this change.

### Notes

- `.gitattributes` currently has only the `*.sh` rule.
- Do this when no feature branch is open, or it will conflict with everything.

### Comments

#### Resolution

Done on `chore/wpcs-phpcs`. Whitespace commit is pure (`git diff -w` empty for PHP, token stream identical). phpcs is clean with a justified exclude list in `phpcs.xml`. `lint.yml` is the new blocking check; `.distignore` keeps `composer.*`, `phpcs.xml` and `vendor` out of the ZIP (rsync file list identical before/after). `render-surface.sh --site testbed`: 52 passed; `verify.php`: 43 passed. phpcbf had turned literal-newline strings in `seed.php` into single-quoted literal newlines; rebuilt with `implode()`. Left for the user: mark the `PHP lint` check required in branch protection.

---

## Label markup predicts the render cascade instead of reading it

Status: done (61715a8)

### Context

**The original subject of this issue — group controls having no accessible
name — was fixed in 0.4.0.** Meta Box radio and ACF radio/checkboxes/true_false
now emit `role="group"` + `aria-labelledby` pointing back at the field label,
and `render-surface.sh` §6 renders a radio group and asserts it.

What is left is the structural weakness a code review flagged in that fix.

`Blocks::render_filter_form()` emits the field label *before* the control, so it
has to decide up front whether a `for` will be valid. It does that by
re-deriving the branch condition:

```php
$has_labellable_control = ! ( $has_options && ! empty( $options ) && 'radio' === $control_type );
```

```php
$has_labellable_control = ( 'true_false' !== $field_type )
    && ! ( $has_choices && ! empty( $choices ) && in_array( $control_type, [ 'checkboxes', 'radio' ], true ) );
```

So "which control types emit `id="{cid}mb_{field}"`" is now stated **twice** per
branch: once as this negated predicate, once as the `if/elseif` cascade 10–90
lines below that actually emits the id. Both are correct today — every branch
was traced — but nothing forces them to stay in agreement.

Adding a `checkboxes` control type to the Meta Box cascade, or a new ACF field
type that renders a group, silently reproduces a dangling `for` — the precise
defect 0.4.0 fixed.

### Decisions (reassessed before implementation)

- Sequence: **01 → 02 → 03.** Start only after 02 has merged — it reindents
  `class-gbqf-blocks.php`, and doing this first would make both diffs unreadable.
- Branching: one refactor, covered by the harness → **commit direct to `main`**.
- **Standalone.** The "candidate 4 of the architecture review (control-rendering
  module)" referenced below is not recorded anywhere in the repo, so do not wait
  for it. Refactor `render_filter_form()` in place.
- **No behavior change; do not fold in the taxonomy groups.** The "Related, not
  covered here" section stays out of scope; the baseline audit
  (FW-3) already says to
  start with the taxonomy branches.
- Versioning: CHANGELOG entry (if any) under `[Unreleased]`. Do **not** set or bump
  a version.
- Current predicates to replace: `includes/class-gbqf-blocks.php` ~lines 830 (Meta
  Box) and ~900 (ACF) — line numbers will shift after 02.

### Scope

Invert the dependency: let the rendering branch report what it produced, rather
than having the label predict it.

- Render the control into a variable first, setting `$control_id` (or leaving it
  empty for a group) as a by-product of the branch that actually emits it.
- Then build the label from `$control_id`: `for` when set, `id` + a group
  `aria-labelledby` when not.
- Concatenate label + control.

That leaves exactly one place that knows which control shapes carry an id.

### Why this was deferred

Found by review on a branch whose subject is targeting. The 0.4.0 change fixed
the *observable* accessibility defect and is asserted by the harness; this is a
refactor of how the markup is assembled, with no behaviour change, and it lands
naturally with **candidate 4 of the architecture review** (the control-rendering
module) rather than as extra churn on a security branch.

### Acceptance

- Only one expression in the file decides whether a control has a labellable id.
- Adding a new group-rendering control type cannot produce a dangling `for`
  without also failing a test.
- `render-surface.sh --site testbed` still passes in full, including §6's
  "every label reference resolves" and "every referenced control id is unique"
  assertions.

### Related, not covered here

The categories / tags / extra-taxonomy branches render `<span
class="gbqf-filter-label">` above a checkbox group — a visual label with no
programmatic association at all, and no `role="group"`. Untouched by 0.4.0, so
the accessibility gate did not apply, and no fixture renders them (they would
emit a checkbox per term on the site). Worth folding into candidate 4 or the
baseline WCAG audit.

### Comments

#### Resolution

Done. `render_filter_form()` now renders each Meta Box / ACF control into `$control` first; only the branch that emits the single `id` sets `$label_for`, and a new `Blocks::field_label()` builds `for` or `id` from it. The two negated predicates are gone. A new branch defaults to a labelled group, so a dangling `for` cannot arise by omission; the mirror slip (a single control that forgets `$label_for`) leaves it unnamed, and `render-surface.sh` §6 catches that only for control shapes the fixtures render. No new test was added. Markup is unchanged. phpcs clean; `render-surface.sh --site testbed`: 52 passed. Taxonomy groups left alone, as scoped.

---

