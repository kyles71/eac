---
paths:
  - '**'
---

# General

## Keep custom guidance out of generated Boost blocks
`boost:update` replaces the contents of `<laravel-boost-guidelines>` in agent instruction files. Record settled project decisions with Boost's `record-rule` tool under `.ai/rules`; do not hand-edit custom rules into the generated block. Use `.ai/guidelines` only when a custom instruction truly must be composed inline for every task.

## Ask before choosing a material product behavior
When requirements are ambiguous and multiple materially different business or UX behaviors are reasonable, ask Kyle a targeted question before implementing. Make ordinary low-risk implementation choices from established project conventions without blocking.

## Use strict types in hand-authored PHP
Begin every hand-authored PHP file with `declare(strict_types=1);` immediately after the opening tag. Preserve generated or published vendor-style files rather than rewriting them solely to add strict types.

## Use quiet deterministic quality commands
For direct quality-tool runs use output suited to agent logs: `vendor/bin/pest --no-progress`, `vendor/bin/phpstan analyse --no-progress --error-format=raw`, and `vendor/bin/rector process --no-progress-bar --output-format=github`. For modified PHP, run `vendor/bin/pint --dirty --format agent` as required by the generated Boost guidance.

## Require focused automated verification
Every behavior-changing code or configuration change needs a new or updated automated test, followed by the smallest relevant test run. Documentation- or rule-only changes may instead use a deterministic structural check; do not create application tests that merely assert documentation text.

## Fix Kyle-authored plugins at their source
When a defect originates in an internal `kyle/*` package—including `kyle/filament-form-builder`, `kyle/filament-mail-manager`, and `kyle/filament-theme-builder`—fix and test it in the plugin repository, publish/tag a release, then update the dependency here. Do not add app-local overrides, copied patches, render hooks, or other workarounds unless Kyle explicitly requests temporary containment.

## Keep merge and follow-up commits focused
Commits may be made as work progresses. For a conflicted merge, resolve and verify the conflicts, then complete the merge commit before doing additional research, cleanup, warning removal, dependency upgrades, or unrelated hardening; put each follow-up concern in its own focused commit with a message explaining what changed and why. Keep follow-up work inside the merge commit only when it is required to make the resolution coherent or Kyle explicitly asks for one commit.

## Run socket-based quality tools with elevated permissions
In this managed workspace, Pest and PHPStan open local listening sockets for browser/bootstrap or parallel workers and fail inside the default sandbox. Run `vendor/bin/pest --no-progress ...` and `vendor/bin/phpstan analyse --memory-limit=2G --no-progress --error-format=raw ...` with elevated sandbox permissions on the first attempt. The 2G PHPStan memory limit is the project standard and prevents parallel worker crashes at the default 128M limit.
