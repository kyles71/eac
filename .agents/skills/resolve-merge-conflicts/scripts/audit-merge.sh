#!/usr/bin/env bash

set -euo pipefail

if ! repository_root=$(git rev-parse --show-toplevel 2>/dev/null); then
    printf 'error: run this helper from inside a Git repository\n' >&2
    exit 1
fi

cd "$repository_root"

other_ref=${1:-MERGE_HEAD}

if ! current_commit=$(git rev-parse --verify 'HEAD^{commit}' 2>/dev/null); then
    printf 'error: HEAD does not resolve to a commit\n' >&2
    exit 1
fi

if ! other_commit=$(git rev-parse --verify "${other_ref}^{commit}" 2>/dev/null); then
    printf 'error: %s does not resolve to a commit; pass the branch or commit being merged\n' "$other_ref" >&2
    exit 1
fi

merge_base=$(git merge-base "$current_commit" "$other_commit")
audit_directory=$(mktemp -d)

cleanup() {
    rm -rf -- "$audit_directory"
}

trap cleanup EXIT

git diff --name-only "$merge_base" "$current_commit" | LC_ALL=C sort -u > "$audit_directory/current-paths"
git diff --name-only "$merge_base" "$other_commit" | LC_ALL=C sort -u > "$audit_directory/incoming-paths"

heading() {
    printf '\n== %s ==\n' "$1"
}

heading 'Merge topology'
printf 'repository: %s\n' "$repository_root"
printf 'current:    %s\n' "$current_commit"
printf 'incoming:   %s (%s)\n' "$other_commit" "$other_ref"
printf 'base:       %s\n' "$merge_base"

heading 'Working tree'
git status --short

heading 'Unmerged paths'
git diff --name-only --diff-filter=U

heading 'Paths changed on both sides'
comm -12 "$audit_directory/current-paths" "$audit_directory/incoming-paths"

heading 'Paths changed only on the current side'
comm -23 "$audit_directory/current-paths" "$audit_directory/incoming-paths"

heading 'Paths changed only on the incoming side'
comm -13 "$audit_directory/current-paths" "$audit_directory/incoming-paths"

heading 'Current-side changes'
git diff --name-status --find-renames "$merge_base" "$current_commit"

heading 'Incoming-side changes'
git diff --name-status --find-renames "$merge_base" "$other_commit"

heading 'Deletes and renames requiring stale-reference searches'
git diff --name-status --find-renames --diff-filter=DR "$merge_base" "$current_commit"
git diff --name-status --find-renames --diff-filter=DR "$merge_base" "$other_commit"

heading 'Conflict markers still present'
if command -v rg >/dev/null 2>&1; then
    rg -n '^(<<<<<<<|=======|>>>>>>>)' . \
        --hidden \
        --glob '!.git/**' \
        --glob '!vendor/**' \
        --glob '!node_modules/**' || true
else
    grep -RInE '^(<<<<<<<|=======|>>>>>>>)' . \
        --exclude-dir=.git \
        --exclude-dir=vendor \
        --exclude-dir=node_modules || true
fi

heading 'Whitespace and unresolved-diff errors'
git diff --check || true
git diff --cached --check || true
