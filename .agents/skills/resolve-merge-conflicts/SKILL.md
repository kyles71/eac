---
name: resolve-merge-conflicts
description: Resolve an in-progress Git merge and audit for silent semantic regressions from clean auto-merges, deleted or relocated behavior, stale consumers, and ordering-sensitive files. Use when asked to resolve, finish, or validate a merge beyond its conflict markers; do not use for an ordinary code review with no merge in scope.
---

# Resolve and audit a merge

Preserve the intent of both branches. Removing conflict markers is only the first half of the task; the result must also retain behavior that moved, was renamed, or was changed independently.

## Establish the merge

Read the repository instructions and path-specific rules before editing. Inspect `git status`, `MERGE_HEAD`, the merge base, and both branch histories. Never use a destructive reset or blanket `--ours` / `--theirs` resolution unless the user explicitly chose that outcome.

Run [scripts/audit-merge.sh](scripts/audit-merge.sh) from the repository, using the resolved skill path. It defaults to `MERGE_HEAD`; pass the other branch or commit as its only argument when necessary. Save its pre-resolution output for comparison after edits.

## Resolve marked conflicts

For every unmerged path:

1. Read the base, current-side, and incoming-side stages with `git show :1:path`, `:2:path`, and `:3:path` where available.
2. Inspect the commits that introduced each side's change and the surrounding consumers.
3. Build the coherent integrated result. Preserve additive rules, registrations, tests, and behavior from both sides when they are compatible.
4. Stage a path only after its contents are resolved. Do not hide unresolved work by staging marker-free but semantically incomplete files.

## Audit conflicts without markers

Use the audit report and branch diffs to inspect both direct path overlap and conceptual overlap:

- For deleted or renamed classes, views, routes, configuration keys, events, and commands, search the whole repository for imports, strings, factories, tests, registrations, and dependency injection references.
- When one branch moved or consolidated behavior, transplant unique changes from the old location into the new owner. A modify/delete conflict often signals this migration rather than a choice between keeping or deleting one file.
- Review cleanly auto-merged paths changed by both branches, plus their tests. Adjacent hunks can compose syntactically while violating an invariant.
- Review ordered structures where placement is behavior: CSS source order and media boundaries, middleware/provider registration, route order, navigation arrays, configuration precedence, migrations, and event/listener wiring.
- Check boundary contracts such as method signatures, constructor dependencies, enum cases, view data shapes, database columns, and serialized keys against every consumer, including files untouched by either branch.
- Treat existing tests as behavioral specifications. If a branch adds behavior to a component the other branch replaces, migrate the behavior and update its tests to target the replacement.

Do not turn unrelated cleanup or warnings into part of the merge. Make only changes required for a coherent integration unless the user separately authorizes follow-up work.

## Verify the integrated result

Repeat the audit helper and require no unmerged paths, conflict markers, or `git diff --check` errors. Then use the repository's own formatter, syntax checks, application boot check, static analysis, and test commands.

Start with tests for resolved paths and their discovered consumers. Include untouched tests implicated by deleted symbols or moved behavior. Run the full suite when practical because it is the strongest check for silent consumers and ordering regressions. For a failure, compare the failing behavior against the merge base and both tips before deciding whether it belongs to the merge.

If a full suite failure is fixed, rerun the failing test and any affected integration or browser coverage. Rerun the full suite when the change could affect unrelated consumers; otherwise report the earlier passing count and the focused rerun precisely.

## Finish

Commit only when requested. Keep the merge resolution in the merge commit, including fixes required to preserve both branches' behavior. Put unrelated follow-up work in separate commits.

Report:

- which marked conflicts were resolved;
- which silent semantic conflicts were found and how behavior was preserved;
- the exact verification run and any limitations;
- the resulting commit hash when a commit was requested.
