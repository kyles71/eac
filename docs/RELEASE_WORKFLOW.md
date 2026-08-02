# EAC Release Workflow

Last reviewed: 2026-08-01

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
        v
complete dev PR notes + apply reviewed decision
        |
        v
merge-commit PR into dev --> automatic dev deployment
                                      |
                                      v
                         draft PR into master created
                         + notes and decision transferred
                                      |
                         QA fixes stay on feature branch
                         and are merged into dev again
                                      |
                                      v
                         review copied note and merge to master
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
- A merge from a PR labeled `skip-deployment` records the Git change but skips the server deployment. On `master`, production tagging and draft Release creation are skipped as well.
- Tags and GitHub Releases never deploy the application.
- Production tags are created only after a successful production deployment.
- GitHub Releases remain drafts until production smoke tests and notes are reviewed.
- Dev may contain multiple independently releasable feature branches at once.
- A feature reaches production from its own branch, never by promoting the entire `dev` branch unless an exceptional batch release is explicitly intended.
- Conflict-free feature changes enter `dev` through pull requests. When a dev PR conflicts, the repository owner resolves a local `--no-ff` merge directly on `dev` instead of creating another branch and PR.

## Branch and merge rules

- Create every releasable feature, fix, or hotfix branch from current `master`.
- Merge feature branches into both `dev` and `master` with **merge commits**.
- Keep squash and rebase merging disabled for these branches. The Updates page uses commit ancestry to prove that the current feature head is on the latest successful dev deployment.
- Keep the feature branch until its production Release has been published.
- Do not automatically delete a feature branch when its dev PR merges; its master PR and any QA fixes still need that branch.
- Never force-push `master`, `dev`, a published tag, or a feature branch already under QA.
- Do not merge `dev` into an individual feature branch. That would import unrelated, unreleased work.
- A direct push to `dev` is reserved for the repository owner completing a two-parent conflict-resolution merge from an existing feature-to-dev PR. Do not commit unrelated work directly on `dev`.

### Why merge commits are required

The GitHub **Squash and merge** option copies a pull request's combined changes into a new commit on the base branch. The original feature head is not a parent of that new commit. **Rebase and merge** similarly creates new commit IDs. This workflow deliberately uses ancestry—not merely matching file contents—to prove that the exact feature head is included in the successful dev deployment.

If a feature-to-dev PR is squash-merged or rebase-merged:

- The dev deployment can still succeed.
- The automatic master-draft workflow cannot verify the feature head and will refuse to create or update the master PR.
- The Updates page will not list that feature as available for testing.
- Later PRs from the same long-lived feature branch can repeat commits or conflicts because the original commits never became ancestors of `dev`.

Always choose **Create a merge commit** for PRs into `dev` and `master`. For a conflicting dev PR, use a local `git merge --no-ff` so the resulting dev commit retains the feature head as its second parent. Squash and rebase options are disabled in repository settings to prevent an accidental incompatible merge. It is acceptable to tidy or squash local feature commits before the branch first enters dev QA; once QA begins, preserve its published history and do not force-push it.

When `master` advances while another feature is under QA:

1. Merge current `master` into the waiting feature branch; do not rebase it.
2. Resolve conflicts on the feature branch.
3. Open a follow-up PR into `dev`. If it conflicts, complete the merge directly on local `dev` using the conflict procedure below.
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

### 3. Open the dev pull request

Open a normal PR from the feature branch into `dev`. Do not open the master PR yet. Complete the user-facing and operational note blocks in the dev PR, review them, and apply exactly one of `updates-approved` or `skip-updates`. The trusted `updates-note` check revalidates description edits without removing the decision label, but removes `updates-approved` when feature commits change. Merge with a merge commit only after the decision is valid, then wait for the automatic dev deployment.

If GitHub reports conflicts because another feature is already on `dev`, do not update the clean feature branch with `dev`. Follow [Feature B conflicts with Feature A already on dev](#feature-b-conflicts-with-feature-a-already-on-dev) and resolve a local merge directly on `dev`.

After that deployment succeeds, the trusted **Create master draft after dev deployment** workflow:

1. Finds the dev PR that produced the deployed merge commit. For a direct conflict-resolution merge, it traces the merge commit's second parent back to the original dev PR.
2. Verifies the latest clean feature head is contained in that deployment.
3. Rejects a branch that appears to contain a merge from `dev` or another unreleased branch.
4. Copies the marked user-facing and operational note blocks from the dev PR into a new draft PR from the same feature branch into `master`.
5. Backfills those blocks when an existing master draft still contains placeholders, while preserving valid notes already reviewed or edited on the master PR.
6. Creates or updates the master PR's deployment metadata.
7. Carries a valid `updates-approved` or `skip-updates` decision into a newly created master draft and publishes the corresponding `updates-note` status.
8. Leaves a new master draft unapproved with a failing status when the dev PR has no valid decision.

Write and review the note in the dev PR from the tested behavior and keep it understandable to non-technical staff. The automation carries both the note and that explicit human decision into the new master draft, which becomes the canonical production and update-note record. Automation validates and transfers the decision but never infers approval merely because text is present.

Complete and review these sections in the PR body:

- User-facing title and summary.
- Observable highlights.
- Concrete dev testing focus.
- Operational, migration, configuration, monitoring, and smoke-test notes.

Apply exactly one decision label before merging the initial dev PR:

- `updates-approved` after the note has been reviewed.
- `skip-updates` for changes that should not appear on the Updates page.

`skip-deployment` is a separate, optional operational label. Apply it before merging only when the PR changes GitHub-only automation or documentation and has no application runtime effect. It may be combined with either `updates-approved` or `skip-updates`.

Do not use `skip-deployment` for application code, Composer or npm dependencies, built assets, migrations, seeders, environment or configuration expectations, queues, schedules, worker behavior, or anything else the servers must receive. A manual **Deploy dev branch** run always deploys regardless of labels. When a dev deployment is skipped, the master-draft automation does not claim that commit was tested or update its deployment metadata.

A successful follow-up dev deployment updates the deployment metadata, preserves the manually written master note, and removes `updates-approved` from the existing master draft even when the follow-up dev PR was approved. Edit the master note if the tested behavior changed, then review and reapply `updates-approved`. The initial decision is transferred only when the master draft is first created.

### 4. QA on dev

The dev workflow deploys the combined `dev` branch, so testers may continue evaluating other features while this one is under review.

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
5. Confirm the automation updated the existing master PR deployment record and removed `updates-approved`.
6. Edit the master PR note if needed and reapply `updates-approved`; follow-up dev labels are not automatically transferred to an existing master draft.

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

A push to `master` starts the production workflow. Unless the merged source PR has `skip-deployment`, the workflow:

1. Deploys `master` to production.
2. Creates an annotated version tag for the deployed commit.
3. Collects approved update-note blocks from master PRs included since the prior production tag and inserts their contents directly after the deployment metadata.
4. Creates a draft GitHub Release with user-facing notes, operational notes, and the generated technical changelog.

For a `skip-deployment` PR, the production deployment, tag, and draft Release are all skipped because no server state changed.

If no approved update-note block is available, the workflow adds no user-facing placeholder or warning; the deployment metadata and generated technical changelog remain. The required `updates-note` check should make this exceptional unless the included PRs intentionally use `skip-updates`.

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

Use separate rulesets for `master` and `dev` so the direct-push bypass described below applies only to `dev`.

For both `master` and `dev`:

- Require pull requests with zero required approvals for the solo-developer workflow.
- Do not require linear history.
- Block force pushes and branch deletion.
- Require the stable `updates-note` status check after it has completed successfully on that target branch at least once.

For `dev`, add **Repository administrators** to the ruleset bypass list with **Always allow** so the owner can push a local conflict-resolution merge. Do not select **For pull requests only** for this bypass. Continue using PRs for every conflict-free dev merge.

For `master` also require:

- Conversation resolution.
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

- `updates-approved`
- `skip-updates`
- `skip-deployment`

In **Settings → Actions → General → Workflow permissions**, enable **Allow GitHub Actions to create and approve pull requests**. The automation creates draft PRs and transfers an explicit human decision from the dev PR, but never invents or self-approves update-note content.

GitHub may place CI runs triggered by a `GITHUB_TOKEN`-created PR into an approval-required state. If the master draft shows an **Approve workflows** banner, a repository user with write access must approve those runs. The automation publishes the `updates-note` commit status directly, so the transferred decision is validated without depending on a generated PR event.

The write-capable `workflow_run` and `pull_request_target` jobs check out automation from the default branch. Keep them limited to trusted scripts and do not change them to execute code from a pull-request head.

For the bootstrap PR that introduces this workflow, merge and deploy it to dev, then manually open its draft PR into `master` because the `workflow_run` workflow does not become active until it exists on the default branch. Manually replace the template placeholders with reviewed note blocks if necessary. Add `updates-note` to each branch ruleset only after that stable commit status has completed successfully on the corresponding target branch at least once.

For the private GitHub feed, create a fine-grained token restricted to `kyles71/eac` with read-only access to Contents, Pull Requests, and Deployments. Add these values to the shared `.env` on both servers:

```dotenv
GITHUB_UPDATES_REPOSITORY=kyles71/eac
GITHUB_UPDATES_TOKEN=<fine-grained-read-only-token>
GITHUB_UPDATES_CACHE_TTL=300
GITHUB_UPDATES_RELEASE_LIMIT=20
```

Run `php artisan config:clear` after changing these values outside a deployment. Assign `View:AppUpdatesPage` to QA staff who need the admin page; owners and super administrators receive it by default.

## Hotfixes

Use the same selective path for a hotfix:

1. Branch from current `master`.
2. Open the PR into `dev`, complete its notes, and apply `updates-approved` or `skip-updates` after review.
3. Merge it into `dev` and wait for the successful deployment and automatically created draft master PR.
4. Confirm the note and decision were transferred.
5. Merge the hotfix PR into `master`.
6. Smoke-test production and publish the corrective Release.
7. Merge current `master` into any waiting feature branches, redeploy them to dev, and retest affected behavior.

If an emergency makes dev testing impossible, record the reason and risk in the master PR. Do not edit production files directly or move an existing tag.

## Feature B conflicts with Feature A already on dev

Scenario: `feature/feature-a` has been merge-committed into `dev` but has not reached `master`. `feature/feature-b` was correctly created from `master`. When Feature B's PR targets `dev`, GitHub reports conflicts with Feature A.

Do not use GitHub's web conflict editor or an **Update branch** action when either operation would merge `dev` into `feature/feature-b`. Do not resolve the conflict on `feature/feature-b`. Any of those approaches would place Feature A or other unreleased dev history in the branch intended for Feature B's selective master PR.

Keep the original dev PR open. Resolve the merge directly on local `dev`:

```bash
git status --short
git fetch origin
git switch dev
git pull --ff-only origin dev
git merge --no-ff --no-commit origin/feature/feature-b
```

Start only from a clean working tree. Resolve the files so the combined dev environment supports both Feature A and Feature B. Then complete the merge and push `dev`:

```bash
git status
git add path/to/resolved-file
git commit -m "Merge feature/feature-b into dev for QA"
git push origin dev
```

If the resolution is wrong or unclear, use `git merge --abort` before committing and reassess the dependency.

The push automatically deploys `dev`. The automation reads the merge commit's second parent, finds the original feature-to-dev PR, verifies that the clean `feature/feature-b` head is contained in the deployment, and creates or updates the master PR from `feature/feature-b`. GitHub will normally recognize the original dev PR as merged once its head is contained in `dev`; do not create a replacement dev PR.

Keep these boundaries clear:

- Conflict-only changes needed to combine A and B stay in the merge commit on `dev`.
- Changes that Feature B requires in production must also be implemented on `feature/feature-b`, redeployed to dev through another PR or direct conflict merge, and retested.
- If Feature B cannot operate without Feature A, release Feature A first, redesign B to be independent, or explicitly plan a batch release. Git history alone cannot make a runtime dependency independently releasable.
- Keep both original feature branches until their production Releases are published.
- For later Feature B fixes while Feature A remains only on dev, open another feature-to-dev PR. If it conflicts, repeat the direct local merge from the latest `origin/dev`.

This same direct-merge procedure can repair ancestry after an accidental squash merge into `dev`: merge the clean feature head into current local `dev` with `--no-ff`, push the merge commit, and wait for a successful deployment before relying on the Updates page or master-draft automation.

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
