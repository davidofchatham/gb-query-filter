#!/usr/bin/env bash
#
# Fail if a committed file cites a private path.
#
# .scratch/ and .claude/ resolve for exactly one person. A committed file pointing
# there fails SILENTLY for every other reader: it looks like a working pointer, so
# nothing ever reports it.
#
# Cite a live plan through its FW-N row in docs/future-work.md.
#
# WHAT IS NOT A VIOLATION: naming the CONVENTION. docs/agents/ has to write
# ".scratch/<slug>/spec.md" to describe the layout at all, so a path containing
# a <placeholder> or a * glob is a pattern, not a citation. Only fully literal paths
# are citations, and only those fail.
#
# ONE EXEMPTION: docs/future-work.md -- the tracker IS the sanctioned index over
# private homes. Pointing at them is its entire job; docs/agents/issue-tracker.md
# makes it the readable surface everything else cites instead. It is the one file
# allowed to hold the path, which is exactly what lets every other file be held to
# the rule.
#
# Copy-in of the shared tracker guards, adapted for this repo (scope, allowlists, paths).
# Usage: bash tools/guards/check-private-citations.sh

set -uo pipefail

# Every committed surface: the whole tree. git grep honors .gitignore, so
# .scratch/ itself is never scanned.
SCOPE=(.)

# A literal path: no '<', no '*'. Anchored on a .md so a bare directory mention
# ("no copy stays under .scratch/plans/") is prose, not a pointer. Matches
# any .claude/ subtree, not just .claude/plans/ -- the private harness tree has
# several and all are equally unreadable to anyone else.
PATTERN='(\.scratch|\.claude)(/[A-Za-z0-9._-]+)+\.md'

# --untracked so a NEW file's violations surface before it is committed rather than
# after.
#
# git grep exits 1 for "no matches" and >1 for a real error. Collapsing both to
# "clean" would permanent-green this guard the day a path argument goes stale --
# the same silent-failure class it exists to catch, one level up.
raw=$(git grep -nIE --untracked "$PATTERN" -- "${SCOPE[@]}" 2>&1)
status=$?
if [ "$status" -gt 1 ]; then
	echo "::error::git grep failed (exit ${status}); the guard did NOT run"
	printf '%s\n' "$raw" | sed 's/^/    /'
	exit 2
fi
[ "$status" -eq 1 ] && raw=""

# This script's own header names the paths; the tracker is the only other exemption.
hits=$(printf '%s\n' "$raw" \
	| grep -v '^docs/future-work\.md:' \
	| grep -v '^tools/guards/check-private-citations\.sh:' || true)

if [ -n "$hits" ]; then
	echo "::error::Committed file cites a private path"
	printf '%s\n' "$hits" | sed 's/^/    /'
	cat <<-'MSG'

	These resolve for one person and fail silently for everyone else. Repair:
	  - live plan      -> cite its FW-N row in docs/future-work.md
	  - a memory file  -> name it, do not link it: memory lives outside the repo
	MSG
	exit 1
fi

echo "No private paths cited by committed files."
