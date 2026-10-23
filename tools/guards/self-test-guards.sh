#!/usr/bin/env bash
#
# Prove the two guards still work: green on a clean tree, red on a seeded violation.
#
# A guard nobody has watched fail is a guard nobody knows still works. Runs in a
# throwaway git repo, so the real tree is never touched. Each probe must make the
# guard exit exactly 1: exit 2 is a crashed guard, and a crash is not a pass.
# Every PII rule gets its own probe, so one rule cannot rot behind another's red.
#
# Probes are assembled from pieces so this file does not trip the guards itself.
#
# Usage: bash tools/guards/self-test-guards.sh

set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/tools/guards" "$work/docs"
cp "$here/check-private-citations.sh" "$here/check-tracked-pii.sh" "$work/tools/guards/"
cd "$work" || exit 2
git init -q

failed=0
# expect <want-exit> <label> <guard>
expect() {
	bash "tools/guards/$3" > /dev/null 2>&1
	got=$?
	if [ "$got" -eq "$1" ]; then
		echo "ok    $2 (exit $got)"
	else
		echo "FAIL  $2: wanted exit $1, got $got"
		failed=1
	fi
}

# Clean tree. README.md names a placeholder path, which is the convention, not a citation.
# docs/future-work.md holds a literal private path: the tracker is the
# one exempt file, so this also proves the exemption.
printf '# Clean\n\nLayout: .scratch/<slug>/spec.md\n' > README.md
printf 'Detail home: `.scratch/plans/%s.md`\n' x > docs/future-work.md
expect 0 "citation guard, clean tree" check-private-citations.sh
expect 0 "pii guard, clean tree" check-tracked-pii.sh

# probe <label> <guard> <file> <printf-format> <arg>
probe() {
	printf "$4\n" "$5" > "$3"
	expect 1 "$1" "$2"
	rm "$3"
}
probe "citation guard, literal private path" check-private-citations.sh docs/probe.md 'See `.scratch/plans/%s.md`' x
probe "citation guard, literal .claude path" check-private-citations.sh docs/probe.md 'See `.claude/plans/%s.md`' x
probe "pii guard, absolute local path" check-tracked-pii.sh probe.md 'C:/Us%s' ers/x
probe "pii guard, email address" check-tracked-pii.sh probe.md 'probe@wordpress.%s' org
probe "pii guard, unlisted domain" check-tracked-pii.sh probe.md 'guard-probe.%s' io

# Seeding then removing must leave the tree green again, or a probe leaked state.
expect 0 "citation guard, clean again" check-private-citations.sh
expect 0 "pii guard, clean again" check-tracked-pii.sh

[ "$failed" -eq 0 ] && echo "Guards fire on violations and pass on a clean tree."
exit "$failed"
