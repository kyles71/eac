# EAC Release Workflow

Last reviewed: 2026-07-31

Application: EAC Plié Portal

Production branch and server: `master` / production

Integration branch and server: `dev` / dev

## Purpose

This document defines how independently releasable work moves through development, dev QA, production, tagging, and user-facing update notes. EAC has exactly two remote application environments: dev and production. There is no third remote environment or intermediate deployment branch.

The process is designed for one developer and a small group of acceptance testers. Pull requests provide an intentional checkpoint and audit trail; they do not require another developer's approval.

Use the [Operations Cheat Sheet](OPERATIONS_CHEAT_SHEET.md) for routine commands, `PRODUCTION_ACTIVATION_RUNBOOK.md` for initial production setup, and `APPLICATION_MAINTENANCE_RUNBOOK.md` for incidents and rollback mechanics.

## Release model

Each feature or fix remains independently releasable even when dev contains several changes:

```text
feature branch created from master
        |
        +--> merge-commit PR into dev --> automatic dev deployment --> QA
        |
        +--> early draft PR into master --> update note generated and reviewed
                                                        |
                         QA fixes stay on feature branch |
                         and are merged into dev again   |
                                                        v
                                      merge feature PR into master
                                                        |
                                                        v
                                      automatic production deployment
                                                        |
                                                        v
                                      tag and draft GitHub Release
                                                        |
                                                        v
                                      smoke test and publish Release
```

Repository behavior:

- A push to `dev` deploys the current `dev` branch to the dev server.
- A push to `master` deploys the current `master` branch to production.
- Tags and GitHub Releases never deploy the application.
- Production tags are created only after a successful production deployment.
- GitHub Releases remain drafts until production smoke tests and notes are reviewed.
- Dev may contain multiple independently releasable feature branches at once.
- A feature reaches production from its own branch, never by promoting the entire `dev` branch unless an exceptional batch release is explicitly intended.

## Branch and merge rules

- Create every releasable feature, fix, or hotfix branch from current `master`.
- Merge feature branches into both `dev` and `master` with **merge commits**.
- Keep squash and rebase merging disabled for these branches. The Updates page uses commit ancestry to prove that the current feature head is on the latest successful dev deployment.
- Keep the feature branch until its production Release has been published.
- Do not automatically delete a feature branch when its dev PR merges; its master PR and any QA fixes still need that branch.
- Never force-push `master`, `dev`, a published tag, or a feature branch already under QA.
- Do not merge `dev` into an individual feature branch. That would import unrelated, unreleased work.

When `master` advances while another feature is under QA:

1. Merge current `master` into the waiting feature branch; do not rebase it.
2. Resolve conflicts on the feature branch.
3. Open a follow-up PR into `dev` and deploy the updated head.
4. Repeat the affected QA checks.
5. Merge the existing master PR only after its latest head is approved on dev.

## Standard feature lifecycle

### 1. Start from production

```bash
git fetch origin
git switch master
git pull --ff-only origin master
git switch -c feature/<short-name>
```

Keep each branch focused on one production outcome. Do not branch from `dev` because it may contain unrelated features.

### 2. Complete local verification

- Run the focused Pest tests for the changed behavior.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run PHPStan for affected PHP code.
- Run `npm run build` for frontend changes.
- Review migrations, queues, schedules, external services, and rollback considerations.

### 3. Open both pull requests when dev QA begins

Open:

1. A normal PR from the feature branch into `dev`.
2. A draft PR from the same feature branch into `master`.

The early master PR is the canonical production and update-note record. Do not merge it yet.

The Update Notes workflow generates a draft user-facing note when the master PR opens. It may use the PR description, commit messages, changed filenames, and a bounded diff. The model does not decide whether code is deployed, tested, approved, or released.

Review the generated sections in the PR body:

- User-facing title and summary.
- Observable highlights.
- Concrete dev testing focus.
- Operational, migration, configuration, monitoring, and smoke-test notes.

Apply exactly one label before production merge:

- `updates-approved` after the note has been reviewed.
- `skip-updates` for changes that should not appear on the Updates page.

Use `generate-update-note` to replace the generated sections after substantial QA changes. Pushing another commit automatically removes `updates-approved` so the note and code must be reviewed together again.

### 4. Merge to dev and QA

Merge the dev PR with a merge commit. The dev workflow deploys the combined `dev` branch, so testers may continue evaluating other features while this one is under review.

Record acceptance results in the master PR or its linked dev PR. Confirm:

- The **Deploy dev branch** workflow succeeded.
- The Updates page lists the branch under **Available for testing**.
- The expected behavior works for the intended roles and data.
- Dev logs, Sentry, queues, scheduler behavior, and migrations are healthy.
- The testing-focus items in the update note pass.

The Updates page lists a feature only when its approved current head is contained in the latest successful dev deployment. A failed deployment does not replace the previous successful state.

### 5. Handle QA changes

Keep fixes on the original feature branch:

1. Commit and push the fix.
2. Open another PR from that branch into `dev` for the new commits.
3. Merge it with a merge commit and wait for the dev deployment.
4. Repeat the affected QA checks.
5. Regenerate or edit the master PR note and reapply `updates-approved`.

The open master PR updates automatically as the feature branch changes. Do not create a replacement master PR.

### 6. Release only that feature

Before merging the master PR:

- [ ] Its latest head is deployed successfully to dev.
- [ ] Acceptance testing is complete.
- [ ] The complete production diff contains only intended work.
- [ ] The `updates-note` check passes.
- [ ] Migrations and rollback impact are understood.
- [ ] Required production configuration is already present.
- [ ] No production deployment is currently running.

Convert the draft master PR to ready and merge it with a merge commit. Unrelated features already on `dev` are not included because the production PR comes from the individual master-based feature branch.

### 7. Production deployment and Release

A push to `master` starts the production workflow. The workflow:

1. Deploys `master` to production.
2. Creates an annotated version tag for the deployed commit.
3. Collects approved update-note blocks from master PRs included since the prior production tag.
4. Creates a draft GitHub Release with user-facing notes, operational notes, and the generated technical changelog.

If an approved note cannot be found, tagging still records the successful deployment and the draft Release contains a warning. Correct the draft before publishing it.

Complete the production smoke tests:

- [ ] Production `/up` responds successfully.
- [ ] Login and the admin dashboard load.
- [ ] The primary changed workflow succeeds for an authorized user.
- [ ] Permission boundaries still hold for an unauthorized user where applicable.
- [ ] Queues, scheduler, logs, Sentry, and external integrations are healthy.
- [ ] The production commit and Release tag match.
- [ ] User-facing and operational notes are accurate.

Publish the GitHub Release only after these checks pass. The admin Updates page displays published, non-prerelease Releases with valid user-facing note blocks; it never displays drafts.

## Version names

Use:

```text
v<generation>.<YYMMDD>.<daily-sequence>
```

Examples:

```text
v1.260720.1
v1.260729.1
v1.260729.2
```

- `generation` changes only for a fundamental application generation.
- `YYMMDD` is the production deployment date in `America/New_York`.
- `daily-sequence` begins at `1` each day and increments for additional deployments.
- Never reuse, move, or delete a published production tag.
- A corrective production deployment receives a new version.
- A rollback does not erase the original Release.

Known production records:

| Version | Production commit | Deployment | Release state |
| --- | --- | --- | --- |
| `v1.260720.1` | Initial production baseline | 2026-07-20 | Established release |
| `v1.260729.1` | `e637210` | 2026-07-29 13:58 EDT | Backfill the note below before publishing |

## GitHub repository setup

### Merge settings

In **Settings → General**:

- Allow merge commits.
- Disable squash merging for releasable branches.
- Disable rebase merging for releasable branches.
- Disable automatic head-branch deletion.

### Branch rulesets

For both `master` and `dev`:

- Require pull requests with zero required approvals for the solo-developer workflow.
- Do not require linear history.
- Block force pushes and branch deletion.

For `master` also require:

- Conversation resolution.
- The stable `updates-note` status check after it has completed successfully at least once.
- Existing CI checks once their stable job names are available.

### GitHub Environments

For `dev`:

- Restrict deployments to the `dev` branch only.
- Store only dev deployment secrets.
- Configure the dev server URL.

For `production`:

- Restrict deployments to `master`.
- Store only production deployment secrets.
- Configure the production URL.
- Optionally require a manual deployment approval if the repository plan permits owner self-approval.

### Update-note automation

Create these labels exactly:

- `generate-update-note`
- `updates-approved`
- `skip-updates`

Enable GitHub Models for the repository. The workflow defaults to `openai/gpt-4.1`; set the repository variable `UPDATES_AI_MODEL` to change it without editing the workflow. The workflow uses only the built-in `GITHUB_TOKEN` and declares `models: read`.

For the bootstrap PR that introduces this workflow, manually replace the template placeholders with reviewed note blocks if generation cannot run before the workflow reaches `master`. Add `updates-note` to the master ruleset only after that stable check name has completed at least once.

For the private GitHub feed, create a fine-grained token restricted to `kyles71/eac` with read-only access to Contents, Pull Requests, and Deployments. Add these values to the shared `.env` on both servers:

```dotenv
GITHUB_UPDATES_REPOSITORY=kyles71/eac
GITHUB_UPDATES_TOKEN=<fine-grained-read-only-token>
GITHUB_UPDATES_CACHE_TTL=300
GITHUB_UPDATES_RELEASE_LIMIT=20
```

Run `php artisan config:clear` after changing these values outside a deployment. Assign `View:Updates` to QA staff who need the admin page; owners and super administrators receive it by default.

## Hotfixes

Use the same selective path for a hotfix:

1. Branch from current `master`.
2. Open the dev and draft master PRs.
3. Deploy and test the hotfix on dev.
4. Approve its update note or explicitly skip it.
5. Merge the hotfix PR into `master`.
6. Smoke-test production and publish the corrective Release.
7. Merge current `master` into any waiting feature branches, redeploy them to dev, and retest affected behavior.

If an emergency makes dev testing impossible, record the reason and risk in the master PR. Do not edit production files directly or move an existing tag.

## Rollback

Use the rollback procedure in `APPLICATION_MAINTENANCE_RUNBOOK.md`. After rollback:

- Keep the failed deployment's tag and Release record.
- Record the rollback time, reason, and restored commit.
- Fix forward from current `master` through the normal dev path.
- Give the corrective deployment a new version.

## Initial Updates-page backfill

Before publishing the existing `v1.260729.1` draft, add a valid user-facing note block covering:

- Clear password requirements and reactive feedback anywhere passwords are set.
- User-list role filtering and clearer account/profile details.
- Teacher course visibility and active/concluded filtering.
- Immediate refresh of roles, permissions, and access-dependent content.
- The global-search reliability fix.

Keep dependency updates, backup-notification configuration, and release automation in operational or technical notes. Complete the production smoke tests, then publish the Release so it becomes the first production entry on the Updates page.

Copy-ready user-facing block:

```markdown
<!-- eac-update-note:start -->
### Clearer password security and user management

Password guidance and staff account-management tools are now clearer, more responsive, and easier to use.

#### Highlights
- Password requirements now appear during registration, password reset, profile password changes, and admin user management.
- Password feedback begins neutral, turns green as requirements are met, and shows relevant problems without displaying unnecessary warnings.
- The user list now supports role filtering and uses the clearer “Member Since” label.
- User profiles now show MFA use, last login, membership date, and teaching courses when applicable.
- Role and permission changes take effect immediately without requiring a page reload, and global search is more reliable.

#### Testing focus
- Set passwords from registration, reset, profile, and admin user forms and verify length and breach checks.
- Filter the user list by role and review the updated profile details.
- Confirm teachers show active courses and can be filtered between active and concluded courses.
- Add and remove the teacher role and confirm teaching courses appear or disappear immediately.
- Search globally across available admin records and confirm results open without errors.
<!-- eac-update-note:end -->
```

Copy-ready operational block:

```markdown
<!-- eac-operational-notes:start -->
- Includes dependency updates for shell-quote, concurrently, PostCSS, and Axios.
- Refactors backup-notification configuration and its tests.
- Adds production tagging and draft GitHub Release automation.
- Complete focused smoke tests for password breach validation, role refresh behavior, and global search before publishing.
<!-- eac-operational-notes:end -->
```
