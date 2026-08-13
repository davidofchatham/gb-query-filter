#!/usr/bin/env bash
#
# query-filters — render-level test harness for the TARGETING rule.
#
# This is the only place the rule is observable at all. wp-cli is structurally
# blind to it, and not by a little:
#
#   * `Filters::register_target()` is called from the filter block's
#     render_callback. No render, no registered targets, so the gate
#     `should_apply_to_attributes()` sees an empty registry and declines every
#     loop. Under wp-cli that is indistinguishable from a working targeted mode
#     correctly declining an unclaimed loop.
#   * The decision itself runs inside `generateblocks_query_wp_query_args`,
#     which GB fires while rendering a `generateblocks/query` block. wp-cli
#     renders no blocks.
#
# So every assertion here is a string count against a real response body, and
# every one of them would pass vacuously against an empty body. Section 0
# proves the body is real before anything else runs.
#
# WHAT IT MEASURES. One page, four Query Loops over the same four posts,
# differing only in how a filter block claims them (see manifest.php). Each loop
# emits `<MARKER>::<post title>` per row, so the rendered row count per loop is
# a substring count and never an HTML parse.
#
# The search term is 'Bravo' throughout: it matches exactly one of the four
# fixture titles. A loop that filtered shows 1 row; a loop that did not shows 4.
# There is no third outcome, which is what makes an unexpected count worth
# stopping on rather than interpreting.
#
# BLUEPRINT v3 adds a second axis: field OWNERSHIP (sections 7-9). A Target
# carries not just which loop a block may touch but which FIELDS that block
# filters on, and the two custom fields split the posts 2/2 — so a field filter
# reads as 2 rows, distinguishable from both 4 (nothing applied) and 1 (the
# search). Sections 7 and 8 assert the owned field filters; section 9 asserts
# the unowned one does not, on a request that targeting cannot decline.
#
# CHARACTERIZATION, NOT SPECIFICATION. Sections 2 and 4 assert what the code
# currently does, and say so in their labels — they exist because
# `should_apply_to_attributes()` and `get_matched_target()` walk the same match
# chain separately and do not obviously agree. Section 4 is the one to read
# first: it is the case where the two disagreeing rules could combine to filter
# a loop that no filter block claims, which is security invariant 2 in
# CONTEXT.md and the subject of ADR-0001.
#
# Usage:
#   tools/fixtures/query-filters/render-surface.sh --site testbed
#
# Run from the wp-litespeed env root, or pass --env-root.
# Preconditions: query-filters seeded, gb-query-filter active, scope 'targeted'.
#
set -euo pipefail

# Git Bash (MSYS2) rewrites POSIX-looking args into Windows paths before exec,
# mangling container paths. Disable for this script. Same reason as smoke.sh.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

SITE=''
ENV_ROOT="${WP_LITESPEED_ROOT:-${HOME}/wp-litespeed}"
PASS=0
FAIL=0

ok(){   echo -e "  \033[32mPASS\033[0m ${*}"; PASS=$((PASS+1)); }
bad(){  echo -e "  \033[31mFAIL\033[0m ${*}"; FAIL=$((FAIL+1)); }
note(){ echo -e "  \033[36m ->  \033[0m${*}"; }
err(){  echo -e "\033[31m[X]\033[0m ${*}" >&2; exit 1; }

while [ ${#} -gt 0 ]; do
    case "${1}" in
        -s|--site)  shift; SITE="${1:-}" ;;
        --env-root) shift; ENV_ROOT="${1:-}" ;;
        -h|--help)  echo "Usage: render-surface.sh --site <site> [--env-root <path>]"; exit 0 ;;
        *) err "Unknown option: ${1}" ;;
    esac
    shift
done

[ -n "${SITE}" ]     || err "--site is required"
[ -d "${ENV_ROOT}" ] || err "env root not found: ${ENV_ROOT} (pass --env-root or set WP_LITESPEED_ROOT)"

cd "${ENV_ROOT}"

PAGE='gbqf-targeting'
M_CONTROL='GBQFX-CONTROL'
M_UNSCOPED='GBQFX-UNSCOPED'
M_SCOPED='GBQFX-SCOPED'
M_LEGACY='GBQFX-LEGACY'
M_CLASSMATCH='GBQFX-CLASSMATCH'   # v2

# Resolve the vhost domain from the OLS config, the same source bin/hosts.ps1
# and bin/smoke.sh read, so the URL can never drift from what OLS serves.
DOMAINS=$(docker compose exec -T litespeed \
    bash -c "sed -n '/^  member ${SITE} {/,/^  }/p' /usr/local/lsws/conf/httpd_config.conf | grep vhDomain | awk '{print \$2}'" \
    2>/dev/null | tr -d '\r' || true)
[ -n "${DOMAINS}" ] || err "no vhost for site '${SITE}'"
# shellcheck disable=SC2206
DOMAIN_ARR=(${DOMAINS//,/ })
MAIN="${DOMAIN_ARR[0]}"

# ALL http goes through a container curl, never the host's. Windows curl is
# built against schannel: it ignores --cacert, fails --resolve outright, and
# returns EMPTY OUTPUT rather than an error — which here would read as "zero
# rows rendered" and turn every count assertion into a confident wrong answer.
#
# --network host is required, not incidental: --resolve points at 127.0.0.1,
# which inside a bare container is the container itself.
#
# -k deliberate: this tests rendered output, not certificate trust.
CURL_IMG='curlimages/curl:latest'
NONCE="qf-$$"

# opcache: this container runs opcache.revalidate_freq=120, and it is
# asymmetric in the worst way — opcache.enable_cli=Off, so wp-cli reads fresh
# source while a request inside the window is served by the previous bytecode.
# A CLI check and an HTTP check of the same edit can disagree, with the CLI
# correct and the render stale. Recycle rather than hope 120s elapsed.
echo ""
echo "recycling lsphp workers (defeats opcache.revalidate_freq=120)"
docker compose exec -T litespeed bash -c 'killall lsphp 2>/dev/null; true' >/dev/null 2>&1 || true
sleep 2

# fetch <query-string>  — empty for the unfiltered page.
fetch(){
    local qs="${1:-}"
    local url="https://${MAIN}/${PAGE}/?nocache=${NONCE}"
    [ -n "${qs}" ] && url="${url}&${qs}"
    docker run --rm --network host "${CURL_IMG}" \
        -sS -k --resolve "${MAIN}:443:127.0.0.1" "${url}" 2>/dev/null
}

# rows <body> <marker>  — how many rows that loop rendered.
#
# `|| true` is load-bearing, not defensive noise. Under `set -o pipefail` a grep
# that matches NOTHING fails the whole pipeline, and `set -e` then kills the
# script inside the command substitution — so a loop filtered to zero rows would
# abort the harness instead of reporting 0. That is the single most interesting
# outcome this file can measure, and without this it is the one outcome it
# cannot survive.
rows(){
    # -o + wc, not grep -c: -c counts matching LINES, and the rendered rows are
    # not reliably one per line.
    printf '%s' "${1}" | grep -o "${2}::" | wc -l | tr -d ' ' || true
}

# expect <body> <marker> <n> <label>
expect(){
    local got; got=$(rows "${1}" "${2}")
    if [ "${got}" = "${3}" ]; then
        ok "${4} (${got} row(s))"
    else
        bad "${4} — expected ${3} row(s), got ${got}"
    fi
}

# ---------------------------------------------------------------------------
# 0. Preconditions.
#
# Hard-abort, not FAIL: a bad body invalidates the whole run rather than
# producing one bad result, and every count assertion below would otherwise
# pass or fail for reasons that have nothing to do with targeting.
# ---------------------------------------------------------------------------
echo ""
echo "0. preconditions (site: ${SITE}, domain: ${MAIN}, page: /${PAGE}/)"

BASE=$(fetch || true)
[ -n "${BASE}" ] || err "empty response for /${PAGE}/ — check --network host and that the stack is up."

case "${BASE}" in
    *'</html>'*) ok "response is a complete HTML document ($(printf '%s' "${BASE}" | wc -c) bytes)" ;;
    *) err "response is not complete HTML — refusing to count rows in a truncated body" ;;
esac

# All four loops must render all four posts with no filter applied. If any loop
# is short here, the fixture is wrong and every later count is uninterpretable.
for pair in "${M_CONTROL}:control" "${M_UNSCOPED}:unscoped" "${M_SCOPED}:scoped" "${M_LEGACY}:legacy" "${M_CLASSMATCH}:classmatch"; do
    marker="${pair%%:*}"; name="${pair##*:}"
    n=$(rows "${BASE}" "${marker}")
    [ "${n}" = "4" ] || err "loop '${name}' rendered ${n} rows unfiltered, expected 4 — reseed the blueprint (v2+) before trusting anything below."
done
ok 'all five loops render 4 rows with no filter params'

# The filter form itself must be on the page, or the unscoped/scoped sections
# are testing a page with no filter blocks on it.
case "${BASE}" in
    *'gbqf-query-filter-block'*) ok 'filter blocks rendered' ;;
    *) err 'no .gbqf-query-filter-block in the response — the filter blocks did not render, so no target was ever registered' ;;
esac

# ---------------------------------------------------------------------------
# 1. Scoped targeting — the happy path.
#
# Establishes that filtering works at all on this stack. If this section fails,
# nothing below distinguishes "correctly declined" from "plugin is inert", and
# sections 2 and 4 both go green for the wrong reason.
# ---------------------------------------------------------------------------
echo ""
echo "1. scoped filter block, scoped params (the happy path)"

SCOPED=$(fetch 'gbqf%5Bgbqf-loop-scoped%5D%5Bsearch%5D=Bravo' || true)
[ -n "${SCOPED}" ] || err 'empty response for the scoped request'

expect "${SCOPED}" "${M_SCOPED}"     1 'scoped loop filters on its own scoped params'
expect "${SCOPED}" "${M_CONTROL}"    4 'control loop untouched by another loop scoped params'
expect "${SCOPED}" "${M_UNSCOPED}"   4 'unscoped loop untouched by another loop scoped params'
expect "${SCOPED}" "${M_LEGACY}"     4 'legacy loop untouched by another loop scoped params'
expect "${SCOPED}" "${M_CLASSMATCH}" 4 'classmatch loop untouched by another loop scoped params'

# ---------------------------------------------------------------------------
# 2. Unscoped filter block, flat params — CHARACTERIZATION.
#
# A filter block with targetId '' registers under the key ''. The gate resolves
# a Query Loop to a non-empty id (htmlAttributes.id, anchor, or the
# reconstructed 'gb-query-{uniqueId}'), and no non-empty id can equal ''. So the
# flat mode CONTEXT.md documents ('?gbqf_search=kw', backward-compatible) may
# have no loop it can reach under the default targeted scope.
#
# Whichever way this lands it is worth pinning: 4 rows means the flat URL
# convention and the flat form field names Blocks renders are a dead path;
# 1 row means some route reaches the loop and the gate is wider than reading the
# source suggests.
# ---------------------------------------------------------------------------
echo ""
echo "2. unscoped filter block, flat params (characterization)"

FLAT=$(fetch 'gbqf_search=Bravo' || true)
[ -n "${FLAT}" ] || err 'empty response for the flat request'

n_uns=$(rows "${FLAT}" "${M_UNSCOPED}")
if [ "${n_uns}" = "4" ]; then
    ok "unscoped loop NOT filtered by flat params (4 rows) — flat mode is inert under targeted scope"
    note "consistent with ADR-0001, but the flat param convention and the flat"
    note "field names Blocks renders for an unscoped block are then unreachable."
elif [ "${n_uns}" = "1" ]; then
    ok "unscoped loop IS filtered by flat params (1 row) — flat mode reaches the loop"
else
    bad "unscoped loop rendered ${n_uns} rows on flat params — expected 4 (inert) or 1 (filtered)"
fi

expect "${FLAT}" "${M_CONTROL}"    4 'control loop untouched by flat params'
expect "${FLAT}" "${M_SCOPED}"     4 'scoped loop untouched by flat params (it reads its own namespace)'
expect "${FLAT}" "${M_CLASSMATCH}" 4 'classmatch loop untouched by flat params'

# ---------------------------------------------------------------------------
# 3. Scoped params naming a loop that no filter block targets.
#
# Invariant 2, attacked directly: hand-crafted params for a namespace nothing
# registered. Must change nothing anywhere.
# ---------------------------------------------------------------------------
echo ""
echo "3. scoped params for an unregistered target (invariant 2)"

FORGED=$(fetch 'gbqf%5Bgbqf-loop-control%5D%5Bsearch%5D=Bravo' || true)
[ -n "${FORGED}" ] || err 'empty response for the forged request'

expect "${FORGED}" "${M_CONTROL}"    4 'control loop ignores params forged in its own name'
expect "${FORGED}" "${M_UNSCOPED}"   4 'unscoped loop ignores forged params'
expect "${FORGED}" "${M_SCOPED}"     4 'scoped loop ignores forged params'
expect "${FORGED}" "${M_LEGACY}"     4 'legacy loop ignores forged params'
expect "${FORGED}" "${M_CLASSMATCH}" 4 'classmatch loop ignores forged params'

# ---------------------------------------------------------------------------
# 4. Legacy gbqf-target-* class on an UNCLAIMED loop — the one that matters.
#
# `should_apply_to_attributes()` returns true for any loop whose class contains
# 'gbqf-target-', with no check that a filter block registered that name.
# `get_matched_target()` has no such rule, so it falls through to the unscoped
# '' entry — which the unscoped filter block on this page has registered — and
# hands back scoped=false. Params then reads the FLAT namespace.
#
# If those two combine, a loop that no filter block claims is filtered by flat
# URL params. That is exactly what security invariant 2 forbids, so this
# section is the reason the whole blueprint exists.
#
# The legacy loop here carries `gbqf-target-orphan`. Nothing registers 'orphan'.
# ---------------------------------------------------------------------------
echo ""
echo "4. legacy gbqf-target-* class, flat params (invariant 2 — the divergence)"

n_leg=$(rows "${FLAT}" "${M_LEGACY}")
if [ "${n_leg}" = "4" ]; then
    ok "legacy-class loop NOT filtered by flat params (4 rows) — invariant 2 holds"
elif [ "${n_leg}" = "1" ]; then
    bad "legacy-class loop WAS filtered by flat params (1 row) — a loop no filter block claims was filtered via the URL. Invariant 2 (CONTEXT.md) violated; see ADR-0001."
    note 'something is claiming this loop that should not be. Historically (pre-0.4.0)'
    note "a gate accepted any 'gbqf-target-' class without checking that a filter"
    note 'block had registered that name; the rule was removed in 0.4.0. Check the'
    note 'match rules in Targeting::match() — a loop is claimed only by a REGISTERED'
    note 'HTML id or a REGISTERED target name appearing as one of its classes.'
else
    bad "legacy-class loop rendered ${n_leg} rows — expected 4 (held) or 1 (violated)"
fi

# Same class, but with the unscoped filter block's registration as the only ''
# entry: re-run without ANY gbqf param to be sure section 4's result is caused
# by the params and not by the class alone.
expect "${BASE}" "${M_LEGACY}" 4 'legacy-class loop unaffected with no params present'

# ---------------------------------------------------------------------------
# 5. Scoped match by CLASS, where the loop id differs from the target name.
#    (blueprint v2)
#
# The filter block registers targetId 'gbqf-alias' and renders its form fields
# as gbqf[gbqf-alias][...]. The loop matches because 'gbqf-alias' is one of its
# classes — but the loop's own HTML id is 'gbqf-loop-classmatch'.
#
# So the two identities diverge, and which one is handed to Params decides
# whether the filter works: the matched TARGET KEY ('gbqf-alias') reads the
# namespace the form actually writes; the LOOP's id reads
# gbqf[gbqf-loop-classmatch][...], which nothing ever writes.
#
# Every section above matches by id, where the two are equal, so this is the
# first assertion that can tell them apart. Written before the answer was known.
#
# 4 rows here is NOT "correctly declined" — the loop IS claimed, by class, and
# section 0 already proved it renders. 4 rows means claimed-but-unfiltered.
# ---------------------------------------------------------------------------
echo ""
echo "5. scoped match by class, loop id differs from target name (v2)"

ALIAS=$(fetch 'gbqf%5Bgbqf-alias%5D%5Bsearch%5D=Bravo' || true)
[ -n "${ALIAS}" ] || err 'empty response for the class-match request'

n_cm=$(rows "${ALIAS}" "${M_CLASSMATCH}")
if [ "${n_cm}" = "1" ]; then
    ok 'class-matched loop filters on its target scoped params (1 row)'
elif [ "${n_cm}" = "4" ]; then
    bad "class-matched loop NOT filtered (4 rows) — the loop is claimed by class but its scoped params did not reach it."
    note 'the Params scope is being taken from the LOOP id, not the matched target key:'
    note '  form writes gbqf[gbqf-alias][...], query reads gbqf[gbqf-loop-classmatch][...]'
    note 'look at whatever chooses the Params scope in Filters, and at Target::scope_id().'
    note '(No line reference on purpose: this pointed at a line number that later'
    note ' moved, which sent a reader to unrelated code.)'
else
    bad "class-matched loop rendered ${n_cm} rows — expected 1 (works) or 4 (namespace mismatch)"
fi

# Nothing else may move on this request.
expect "${ALIAS}" "${M_CONTROL}"  4 'control loop untouched by class-match params'
expect "${ALIAS}" "${M_SCOPED}"   4 'scoped loop untouched by class-match params'
expect "${ALIAS}" "${M_UNSCOPED}" 4 'unscoped loop untouched by class-match params'
expect "${ALIAS}" "${M_LEGACY}"   4 'legacy loop untouched by class-match params'

# ---------------------------------------------------------------------------
# 6. Accessibility of the rendered filter form (ADR-0003).
#
# Cheap to check here because the form is already in the body, and the a11y
# target is a hard gate on any control this plugin renders. Not a full audit —
# see .scratch/accessibility-audit/issues/01-baseline-wcag-audit.md.
# ---------------------------------------------------------------------------
echo ""
echo "6. filter form accessibility (ADR-0003, spot checks)"

case "${BASE}" in
    *'<label for="gbqf_search_input"'*) ok 'search input has an associated label' ;;
    *) bad 'search input label is not associated (no <label for="gbqf_search_input">)' ;;
esac

# A <label> with no `for` and no wrapped control labels nothing.
#
# Through v2 this check was VACUOUS and said so: the defect is in the custom-field
# branches, and v1/v2 filter blocks enabled search only, so the branch never
# rendered and a zero count meant "not reached", not "sound". v3 seeds a Meta Box
# field and an ACF field, both as `select` — a single control with an id — so the
# branch is now genuinely exercised.
#
# STILL SCOPED, in a different place. v3 pins both fields to `select` on purpose
# (manifest.php `fields`). Radio/checkbox control types render a GROUP of inputs
# with no single labellable control, which is an unfixed and separate problem —
# see .scratch/targeting-module/issues/03-group-control-labelling.md. Nothing
# below reaches those branches, and that is deliberate, not an oversight.
# (Numbered 5 through blueprint v1; renumbered to 6 in v2, when the class-match
# section took 5.)
case "${BASE}" in
    *'gbqf-filter-metabox'*) ok 'Meta Box filter control rendered (branch is reachable)' ;;
    *) bad 'no .gbqf-filter-metabox in the response — the Meta Box control did not render, so the checks below are vacuous' ;;
esac

case "${BASE}" in
    *'gbqf-filter-acf'*) ok 'ACF filter control rendered (branch is reachable)' ;;
    *) bad 'no .gbqf-filter-acf in the response — the ACF control did not render, so the checks below are vacuous' ;;
esac

case "${BASE}" in
    *'<label for="gbqf_mb_gbqf_color"'*) ok 'Meta Box select has an associated label' ;;
    *) bad 'Meta Box select label is not associated (no <label for="gbqf_mb_gbqf_color">)' ;;
esac

case "${BASE}" in
    *'<label for="gbqf_acf_gbqf_size"'*) ok 'ACF select has an associated label' ;;
    *) bad 'ACF select label is not associated (no <label for="gbqf_acf_gbqf_size">)' ;;
esac

# The `for` must point at something. An id that no element carries is a dangling
# reference — assistive tech follows it to nothing, which is worse than a bare
# label, and it is exactly what the Meta Box radio branch used to emit.
for pair in 'gbqf_mb_gbqf_color:Meta Box' 'gbqf_acf_gbqf_size:ACF'; do
    ctl_id="${pair%%:*}"; ctl_name="${pair##*:}"
    case "${BASE}" in
        *"id=\"${ctl_id}\""*) ok "${ctl_name} label's for= resolves to a real control" ;;
        *) bad "${ctl_name} label points at id='${ctl_id}', but no element carries it (dangling reference)" ;;
    esac
done

bare=$(printf '%s' "${BASE}" | grep -o '<label>' | wc -l | tr -d ' ' || true)
if [ "${bare}" = "0" ]; then
    ok 'no bare <label> tags in the rendered form'
else
    bad "${bare} bare <label> tag(s) with no for= attribute — unassociated labels"
    note 'both fixture fields are selects, so every field label here should carry a for='
fi

# ---------------------------------------------------------------------------
# 7. Meta Box field filter through an ID-matched target. (blueprint v3)
#
# The scoped filter block owns exactly one Meta Box field, gbqf_color, and the
# fixture posts split 2/2 on it. So a working field filter shows 2 rows — a
# count that is neither 4 (no filter applied) nor 1 (the search count), which is
# what makes it unmistakable.
#
# This is the first assertion that a Target carries anything BEYOND a scope: the
# field list travels with the matched target, and get_meta_filters() is handed
# it rather than deriving one.
# ---------------------------------------------------------------------------
echo ""
echo "7. Meta Box field filter, id-matched target (v3)"

MB=$(fetch 'gbqf%5Bgbqf-loop-scoped%5D%5Bmeta%5D%5Bgbqf_color%5D=red' || true)
[ -n "${MB}" ] || err 'empty response for the Meta Box field request'

expect "${MB}" "${M_SCOPED}"     2 'scoped loop filters on its owned Meta Box field'
expect "${MB}" "${M_CONTROL}"    4 'control loop untouched by a Meta Box field filter'
expect "${MB}" "${M_UNSCOPED}"   4 'unscoped loop untouched by a Meta Box field filter'
expect "${MB}" "${M_LEGACY}"     4 'legacy loop untouched by a Meta Box field filter'
expect "${MB}" "${M_CLASSMATCH}" 4 'classmatch loop untouched by another target Meta Box field'

# ---------------------------------------------------------------------------
# 8. ACF field filter through a CLASS-matched target. (blueprint v3)
#
# The same shape as 7, but on the block whose loop matches by CLASS — so the
# matched target key ('gbqf-alias') and the loop's own id
# ('gbqf-loop-classmatch') differ.
#
# Section 5 proved the SCOPE is taken from the target key. This proves the FIELD
# LISTS are too. They are separate reads off the same Target, and a fix that
# corrected one and not the other would pass section 5 and fail here: the loop
# would read the right namespace but be handed an empty acf_fields list, so
# gbqf_size would be skipped as unowned and the count would come back 4.
#
# gbqf_size splits the posts 2/2 as well, but across a DIFFERENT pair than
# gbqf_color — so 2 rows here cannot be the Meta Box filter having leaked.
# ---------------------------------------------------------------------------
echo ""
echo "8. ACF field filter, class-matched target (v3)"

ACF=$(fetch 'gbqf%5Bgbqf-alias%5D%5Bmeta%5D%5Bgbqf_size%5D=large' || true)
[ -n "${ACF}" ] || err 'empty response for the ACF field request'

n_acf=$(rows "${ACF}" "${M_CLASSMATCH}")
if [ "${n_acf}" = "2" ]; then
    ok 'class-matched loop filters on its owned ACF field (2 rows)'
elif [ "${n_acf}" = "4" ]; then
    bad 'class-matched loop NOT filtered by its owned ACF field (4 rows)'
    note 'section 5 passing while this fails means the scope travels off the matched'
    note 'target key but the FIELD LISTS do not — look at what builds the Target and'
    note 'at what Filters passes to get_acf_filters().'
else
    bad "class-matched loop rendered ${n_acf} rows — expected 2 (works) or 4 (field list not carried)"
fi

expect "${ACF}" "${M_CONTROL}"  4 'control loop untouched by an ACF field filter'
expect "${ACF}" "${M_SCOPED}"   4 'scoped loop untouched by another target ACF field'
expect "${ACF}" "${M_UNSCOPED}" 4 'unscoped loop untouched by an ACF field filter'
expect "${ACF}" "${M_LEGACY}"   4 'legacy loop untouched by an ACF field filter'

# ---------------------------------------------------------------------------
# 9. FIELD OWNERSHIP — a block's own namespace does not grant it every field.
#     (blueprint v3)
#
# The ownership rule, and the reason it is not merely tidiness: a filter block
# declares which fields it filters on, and `Filters::get_meta_filters()` /
# `get_acf_filters()` skip any key not in that list. Without it, ANY meta key
# becomes filterable by anyone who can write a URL — including keys the site
# never meant to expose, on a loop the block legitimately owns. That is a
# narrower leak than invariant 2 but the same family, and it is the thing these
# two requests test.
#
# Both requests are well-formed and correctly scoped: they address a block's OWN
# namespace, so targeting cannot decline them. The only thing standing between
# them and a filtered result is the field list on the Target. 4 rows means the
# ownership check held; 2 means an unowned field filtered.
#
# The two blocks own disjoint fields (manifest.php `filter_blocks`), so each
# request asks a block to filter on precisely the field the OTHER block owns.
# ---------------------------------------------------------------------------
echo ""
echo "9. field ownership — unowned keys are ignored (v3)"

OWN_A=$(fetch 'gbqf%5Bgbqf-loop-scoped%5D%5Bmeta%5D%5Bgbqf_size%5D=large' || true)
[ -n "${OWN_A}" ] || err 'empty response for the unowned-ACF-field request'

n_a=$(rows "${OWN_A}" "${M_SCOPED}")
if [ "${n_a}" = "4" ]; then
    ok 'scoped loop ignores gbqf_size — its block owns no ACF field (4 rows)'
else
    bad "scoped loop rendered ${n_a} rows for a field its block never declared — expected 4"
    note 'the ACF field list on the Target is being ignored, or a fallback is'
    note 'processing every key when the list is empty. An empty list must mean'
    note '"owns nothing", never "owns everything".'
fi

OWN_B=$(fetch 'gbqf%5Bgbqf-alias%5D%5Bmeta%5D%5Bgbqf_color%5D=red' || true)
[ -n "${OWN_B}" ] || err 'empty response for the unowned-MetaBox-field request'

n_b=$(rows "${OWN_B}" "${M_CLASSMATCH}")
if [ "${n_b}" = "4" ]; then
    ok 'classmatch loop ignores gbqf_color — its block owns no Meta Box field (4 rows)'
else
    bad "classmatch loop rendered ${n_b} rows for a field its block never declared — expected 4"
    note 'same rule as above, on the Meta Box side.'
fi

# Nothing else may move on either request either.
expect "${OWN_A}" "${M_CONTROL}" 4 'control loop untouched by an unowned-field request'
expect "${OWN_B}" "${M_CONTROL}" 4 'control loop untouched by an unowned-field request (ACF side)'

echo ""
if [ "${FAIL}" -gt 0 ]; then
    echo -e "\033[31m[X]\033[0m ${FAIL} failed, ${PASS} passed"
    exit 1
fi
echo -e "\033[32m[OK]\033[0m ${PASS} passed"
