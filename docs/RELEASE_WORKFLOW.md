# EAC Release Workflow

Last reviewed: 2026-07-24

Application: EAC Plié Portal

Production branch: `master`

Staging branch: `dev`

## Purpose

This document defines the standard process for developing, testing, deploying, tagging, documenting, and, when necessary, rolling back an EAC release.

The process is designed for one developer and zero to two acceptance testers. Pull requests provide an intentional checkpoint and an audit trail; they do not require another developer's approval.

For production infrastructure and initial activation, see `PRODUCTION_ACTIVATION_RUNBOOK.md`. For routine operations, incidents, and rollback mechanics, see `APPLICATION_MAINTENANCE_RUNBOOK.md`.

## Release model

Selective releases are the standard:

```text
feature or fix branch created from master
        |
        v
merge-commit pull request into dev for integration testing
        |
        v
release/<name> branch created from master
and merged with selected feature branches
        |
        v
automatic release-candidate deployment to staging
        |
        v
release pull request into master
        |
        v
automatic production deployment
        |
        v
annotated Git tag and GitHub Release
```

Repository behavior:

- A push to `dev` starts the staging deployment workflow.
- A push to a single-segment `release/*` branch deploys that branch to staging as a release candidate.
- The **Deploy dev branch** workflow can be run manually to restore `dev` on staging after release-candidate testing.
- A push to `master` starts the production deployment workflow.
- Creating or pushing a tag does not deploy the application under the current workflows.
- Publishing a GitHub Release does not deploy the application under the current workflows.

Staging has one deployment target, so a `dev` deployment and a release-candidate deployment replace one another. Do not merge changes into `dev` during release-candidate acceptance testing. Do not merge another release into `master` while a production deployment is running. A release tag represents a successful production deployment, not merely a merge or an attempted deployment.

A direct `dev` to `master` release is an explicit batch exception, not the default. Use it only when every change on `dev` has been reviewed and deliberately selected for the same production release.

## Version names

Use calendar-based release tags with a stable three-part structure:

```text
v<generation>.<YYMMDD>.<daily-sequence>
```

The parts have these meanings:

- `generation` identifies a fundamental generation of the application. Ordinary features and fixes do not increment it.
- `YYMMDD` is the production deployment date in the `America/New_York` time zone.
- `daily-sequence` starts at `1` and increments for each additional production release on the same date.

Examples:

```text
v1.260720.1
v1.260724.1
v1.260724.2
```

Rules:

- Use the actual production deployment date in the `America/New_York` time zone.
- Always include the daily sequence, including `.1` for the first release of a day.
- Reset the daily sequence to `.1` on each new production deployment date.
- Reserve a generation change such as `v2` for a fundamental application generation, not a routine release.
- Never reuse a version.
- Never move a published release tag to another commit.
- Tag only commits that were successfully deployed to production.
- A rollback does not erase or move the original tag.
- A corrective deployment receives a new version.

The established initial production release is:

```text
v1.260720.1
```

It identifies application generation `1`, the July 20, 2026 production deployment date, and the first release of that date. The convention is structurally compatible with three-part semantic-version tooling, but its numbers intentionally use calendar-version semantics. Revisit this decision if external consumers begin depending on a semantic compatibility contract.

## GitHub setup checklist

The deployment workflows already reference the `dev` and `production` GitHub Environments. The remaining settings must be confirmed in the GitHub repository because environment, ruleset, and secret configuration is not stored completely in Git.

### Repository settings

In **Settings → General**:

- [X] Set `master` as the default branch.
- [X] Allow **Merge commits** for feature integration and release pull requests.
- [X] Disable **Squash merging** for releasable feature and fix branches.
- [X] Disable **Rebase merging** for releasable feature and fix branches.
- [X] Disable **Automatically delete head branches**; delete feature branches manually after they reach production or are abandoned.

The same feature commits must be able to reach both `dev` and `master`. Merge commits preserve that ancestry. Squash or rebase merging into `dev` rewrites commit identity and forces later selective releases to use duplicate cherry-picked commits.

### `master` branch ruleset

In **Settings → Rules → Rulesets**, create an active branch ruleset targeting `master`:

- [X] Require a pull request before merging.
- [X] Set required approvals to `0`; this is a solo-developer repository.
- [X] Require all configured status checks to pass.
- [X] Require conversation resolution before merging.
- [X] Do not require linear history; the release process intentionally uses merge commits.
- [X] Block force pushes.
- [X] Restrict branch deletion.
- [ ] Do not permit routine direct pushes, including administrative bypasses.
- [ ] Document how an emergency bypass will be recorded if bypass access is retained.

The pull request is the deliberate production release control. It remains useful without a second developer because it displays the complete production diff, CI result, testing record, and deployment considerations before the merge.

### `dev` branch ruleset

Create an active branch ruleset targeting `dev`:

- [X] Require a pull request before merging.
- [X] Set required approvals to `0`.
- [ ] Require all configured status checks to pass.
- [X] Do not require linear history; integration pull requests intentionally use merge commits.
- [X] Block force pushes.
- [X] Restrict branch deletion.

Testers provide acceptance results; they are not required GitHub code reviewers and do not need repository write access.

### Release-candidate branches

Release-candidate branches:

- Use the single-segment format `release/<short-name>`, such as `release/260726-enrollment-fix`.
- Are created from current `master`, not from `dev`.
- Merge only the preserved feature or fix branches selected for the release.
- Deploy to staging but never deploy directly to production.
- Reach production only through a pull request into protected `master`.
- Are deleted after the production release or abandonment.

Do not use additional slashes such as `release/260726/enrollment-fix`; deployment validation intentionally rejects nested release branch names.

### Release tag ruleset

After historical tags and releases have been backfilled, create an active tag ruleset targeting `v*`:

- [X] Allow new version tags to be created.
- [X] Restrict updates to existing tags.
- [X] Restrict deletion of existing tags.
- [X] Block force updates.
- [X] Limit bypass access to the repository owner and use it only for a documented recovery.

Optionally enable GitHub immutable releases after the backfill is complete. With immutable releases enabled, publish each release from a draft only after its tag, notes, and assets have been checked. Published immutable release tags cannot be moved or deleted while the release exists.

### Continuous integration

The repository currently contains deployment workflows but no separate pull-request CI workflow. Add one before making status checks mandatory.

The CI workflow should run for pull requests targeting both `dev` and `master` and for pushes to `release/*`, and should include at least:

- [ ] `composer validate`
- [ ] Dependency installation from the committed lock files
- [ ] `vendor/bin/pest --no-progress`
- [ ] `vendor/bin/phpstan analyse --no-progress --error-format=raw`
- [ ] `npm ci`
- [ ] `npm run build`

After the workflow has run successfully at least once, add its stable job name as a required status check in both branch rulesets. Keep required job names unique across workflows.

### GitHub Environments

In **Settings → Environments**:

For `dev`:

- [X] Restrict deployment branches to `dev` and the branch pattern `release/*`.
- [X] Store only staging deployment secrets in this environment.
- [ ] Confirm the environment identifies the staging URL.

For `production`:

- [X] Restrict deployment branches to `master`.
- [X] Store only production deployment secrets in this environment.
- [ ] Confirm the environment identifies the production URL.
- [ ] Consider a manual deployment approval as an intentional final pause, if the repository plan supports it.
- [ ] If self-approval is needed, do not enable **Prevent self-review**.

Manual approval by the sole developer is not independent review, but it can prevent an accidental merge from immediately changing production.

Confirm these existing environment secrets are present, scoped correctly, and periodically rotated:

- [ ] `DEPLOY_HOST`
- [ ] `DEPLOY_USER`
- [ ] `PRIVATE_KEY`
- [ ] `MY_PRIVATE_GH_TOKEN`
- [ ] `DEPLOY_PASSWORD`, only if it is still genuinely required

### GitHub Actions safety

- [ ] Give each workflow and job only the permissions it needs.
- [X] Keep production and staging secrets in their respective environments.
- [ ] Prevent overlapping deployments to the same environment.
- [ ] Configure production so a newer run does not cancel an in-progress production migration or deployment.
- [ ] Update aging actions in a dedicated reviewed change.
- [ ] Pin third-party actions to reviewed full commit SHAs where practical.
- [ ] Enable Dependabot alerts and dependency review appropriate to the repository plan.
- [ ] Require two-factor authentication or a passkey on the repository owner's GitHub account.
- [ ] Store GitHub recovery codes and deployment-key recovery instructions securely outside the repository.

The current reusable workflow uses `cancel-in-progress: true` for both environments. Before relying on serialized production releases, change production behavior so a second run waits instead of interrupting an active production deployment.

### Pull request and release-note conventions

- [ ] Add a standard feature/fix pull request template.
- [ ] Add a standard release pull request template.
- [ ] Create the release-note labels listed below.
- [ ] Add `.github/release.yml` to categorize generated release notes.
- [ ] Keep pull request titles understandable to a non-developer.
- [ ] Require each user-visible pull request to include testing instructions.

Recommended labels:

- `release-feature`
- `release-change`
- `release-fix`
- `release-security`
- `release-internal`
- `skip-release-notes`

A suggested `.github/release.yml` configuration is:

```yaml
changelog:
  exclude:
    labels:
      - skip-release-notes
  categories:
    - title: New features
      labels:
        - release-feature
    - title: Changes
      labels:
        - release-change
    - title: Fixes
      labels:
        - release-fix
    - title: Security
      labels:
        - release-security
    - title: Internal changes
      labels:
        - release-internal
    - title: Other changes
      labels:
        - "*"
```

## Historical release records and backfill

The initial production baseline is:

| Version | Production deployment time | Production commit SHA | Status |
| --- | --- | --- | --- |
| `v1.260720.1` | 2026-07-20 00:33 EDT (`-04:00`) | `6b4ff26a32a60f4962f5ec792b8686f8022b2f7a` | Annotated tag published |

### 1. Establish the true deployment boundaries

Do not choose a release commit solely from its commit date. A commit timestamp records when the commit was created, not when production deployed it.

For each historical production release, record:

| Version | Actual production deployment time | Production commit SHA | Evidence |
| --- | --- | --- | --- |
| `v1.YYMMDD.N` | ISO 8601 time with offset | Full SHA | GitHub Actions run or deployment record |

Use evidence in this order:

1. The successful **Deploy to production** GitHub Actions run and its triggering commit SHA.
2. GitHub's production Environment deployment history.
3. Deployer release records and the active release on the server.
4. Commit history, incident notes, and known production observations as supporting evidence.

Fetch the current remote history and display likely production commits:

```bash
git fetch origin master --tags
git log origin/master \
    --first-parent \
    --date=iso-local \
    --pretty=format:'%h  %ad  %s'
```

Inspect a candidate:

```bash
git show --stat --oneline <commit-sha>
git branch --remotes --contains <commit-sha>
```

Confirm that an older candidate is an ancestor of the next release:

```bash
git merge-base --is-ancestor <older-sha> <newer-sha>
```

A zero exit status means it is an ancestor. If it is not, stop and investigate instead of constructing a misleading release sequence.

### 2. Preview the release contents

For the first reconstructed release, treat it as the initial production baseline. Review the commits that established that baseline and summarize the capabilities that were actually available at launch:

```bash
git log --oneline --reverse <release-sha>
git show --stat --oneline <release-sha>
```

For later releases:

```bash
git log --oneline --reverse <previous-release-sha>..<release-sha>
git diff --stat <previous-release-sha>..<release-sha>
```

Review migrations, configuration changes, dependency changes, and user-visible behavior. Commit messages are evidence, not finished release notes.

### 3. Create an annotated tag on the historical commit

An annotated tag is the standard release tag because it records the tagger, a message, and a tag creation date.

To tag a historical commit while truthfully recording that the tag was created today, replace the example version and SHA:

```bash
git tag -a v1.260724.1 <commit-sha> \
    -m 'Production release v1.260724.1'
```

To backdate the annotated tag metadata to a known original deployment time:

```bash
GIT_COMMITTER_DATE='2026-07-24T14:30:00-04:00' \
    git tag -a v1.260724.1 <commit-sha> \
    -m 'Production release v1.260724.1'
```

Only set `GIT_COMMITTER_DATE` when the actual deployment timestamp and UTC offset are supported by a deployment record. Do not infer the deployment time from the commit time. Eastern time is `-04:00` or `-05:00` depending on daylight-saving time, so use the offset from the historical record.

If tag signing is configured, use `git tag -s` instead of `git tag -a`.

### 4. Verify before pushing

Verify the tag annotation and the commit it resolves to:

```bash
git show --no-patch --pretty=fuller v1.260724.1
git rev-parse v1.260724.1^{}
git rev-parse <commit-sha>
```

The final two commands must print the same full commit SHA.

List all reconstructed releases in version order:

```bash
git tag --list \
    --sort=version:refname \
    --format='%(refname:short)  %(objectname:short)  %(creatordate:iso8601)  %(subject)'
```

If an unpushed tag is wrong, delete it locally and recreate it:

```bash
git tag --delete v1.260724.1
```

### 5. Push one reviewed tag at a time

```bash
git push origin refs/tags/v1.260724.1
```

Do not use `git push --tags` for this process. An explicit ref prevents unrelated local tags from being published accidentally.

After pushing, confirm the tag appears under **Releases → Tags** and points to the expected full commit SHA.

Once a tag has been pushed or used for a GitHub Release, treat it as immutable. If published release history is materially wrong, prefer a new corrected version and mark the old GitHub Release as superseded. Do not silently force-move the tag.

### 6. Create historical GitHub Releases

Create historical releases from oldest to newest:

1. Open **Releases → Draft a new release**.
2. Select the existing tag.
3. Select the correct previous tag.
4. Enter a release title.
5. Generate release notes.
6. Replace raw technical wording with the release-note format in this document.
7. Add `Originally deployed: YYYY-MM-DD HH:MM America/New_York`.
8. Publish the release.
9. Do not mark an older reconstructed release as the latest release.

A Git tag date and a GitHub Release publication date are separate. A historical tag may point to and describe an old deployment, while the GitHub Release correctly shows that its notes were published later.

Complete the full historical backfill before enabling immutable releases or strict tag rules.

## Standard feature and fix workflow

### 1. Start from production

```bash
git switch master
git pull --ff-only origin master
git switch --create feature/<short-description>
```

Use `fix/<short-description>` for a bug fix. A branch created from `master` contains only production plus that branch's work, so it can later be selected independently. Keep unrelated work in separate branches and pull requests.

### 2. Develop and verify locally

- Implement the smallest complete change.
- Add or update automated tests.
- Review migrations for backward and rollback compatibility.
- Update `.env.example` when configuration requirements change, without adding secrets.
- Run the focused tests and static analysis.
- Build frontend assets when frontend code changes.
- Review the diff for debug output, credentials, generated files, and unrelated changes.

### 3. Open a pull request into `dev`

Push the branch:

```bash
git push --set-upstream origin feature/<short-description>
```

The pull request should include:

```markdown
## Summary

Describe the user or operational outcome.

## How to test

- [ ] A specific successful path
- [ ] A relevant validation or failure path
- [ ] A regression-sensitive existing path

## Deployment considerations

- Database migrations: Yes/No
- New or changed environment variables: Yes/No
- Queue or scheduled-task changes: Yes/No
- External service changes: Yes/No
- Backup or rollback concern: None/Describe

## Release note

One plain-language bullet, or `Not user-visible`.
```

Apply the appropriate release-note label. Merge into `dev` only after required checks pass.

Use **Create a merge commit** for the integration pull request. Do not squash or rebase it. Keep the original feature branch after the `dev` merge because its preserved commits are the selectable production unit.

Do not merge `dev` back into the feature branch merely to make it current. That would contaminate the feature with unrelated unreleased work. If integration conflicts require resolution, resolve them on a temporary branch created from `dev`, merge the feature into that temporary branch, and use the temporary branch only for the `dev` integration pull request.

### 4. Validate staging

After merging:

- [ ] Confirm the **Deploy dev branch** workflow succeeds.
- [ ] Confirm staging `/up` responds successfully.
- [ ] Check recent staging errors and failed queue jobs.
- [ ] Execute the pull request's testing instructions.
- [ ] Ask available testers to execute the relevant user flows.
- [ ] Record tester name, date, result, and discovered issues against the feature branch or pull request.

Commit feature-specific corrections to the original feature branch and merge those additional commits into `dev` through another merge-commit pull request. This keeps the branch selected for production identical to the complete feature. Do not edit deployed files directly.

## Standard selective production release workflow

### 1. Select preserved feature branches

Choose the feature and fix branches approved for this production release. Confirm each branch:

- Was created from `master`.
- Contains only its named feature or fix.
- Has been integrated into and tested on `dev`.
- Includes all corrections discovered during integration testing.
- Does not depend on another unselected branch.

Inspect its production diff:

```bash
git fetch origin
git log --oneline --reverse origin/master..origin/feature/<short-description>
git diff --stat origin/master...origin/feature/<short-description>
```

Repeat for every selected branch.

### 2. Build the release branch from production

```bash
git switch master
git pull --ff-only origin master
git switch --create release/<YYMMDD-short-name>
git merge --no-ff origin/feature/<short-description> \
    -m 'Include feature/<short-description>'
```

Repeat the merge for each selected feature or fix branch in dependency order. Merging preserves the exact feature commits already integrated into `dev`; it does not create duplicate cherry-picked commits.

Review the exact candidate relative to production:

```bash
git log --oneline --reverse origin/master..HEAD
git diff --stat origin/master...HEAD
```

Push the candidate:

```bash
git push --set-upstream origin release/<YYMMDD-short-name>
```

The **Deploy release candidate** workflow deploys this branch to staging. Its name must contain exactly one slash.

### 3. Test the exact release candidate

- [ ] Confirm the release-candidate deployment succeeded.
- [ ] Confirm the staging deployment identifies the expected branch and commit.
- [ ] Repeat the selected changes' acceptance tests.
- [ ] Run critical regression smoke tests.
- [ ] Check staging logs, Sentry, queues, scheduler behavior, and migrations.
- [ ] Record tester results against the release candidate.

While this candidate is being tested, do not merge another pull request into `dev`; doing so would redeploy `dev` and replace the candidate on staging.

Prefer to fix the owning feature branch, merge that fix into both the release branch and `dev`, and redeploy. If an emergency fix must be committed directly to the release branch, immediately apply an equivalent reviewed change to `dev`.

### 4. Promote only the candidate

Open the production release pull request from `release/<YYMMDD-short-name>` into `master`. Use the standard release pull-request body below and confirm:

- The base is `master`.
- The head is the release branch, not `dev`.
- The diff contains only the selected release.
- CI and acceptance testing pass.

Use **Create a merge commit**. Merging starts the normal production deployment.

After production succeeds:

1. Complete the production smoke tests.
2. Tag and publish the release normally.
3. Delete the release branch.
4. Manually run **Deploy dev branch** to restore the full `dev` integration state on staging.
5. Delete the selected feature branches after confirming their commits are reachable from `master`.
6. Confirm any release-only conflict resolution or follow-up fix has also reached `dev`.

Because the same feature commits are now reachable from both `dev` and `master`, future releases can distinguish the remaining unreleased feature branches without duplicate commit identities.

## Exceptional full-dev batch release

A direct pull request from `dev` into `master` is permitted only when every feature and fix currently on `dev` is deliberately approved for the same release. Do not use this as a shortcut around selecting and reviewing branches.

The pull request must use **Create a merge commit**. Do not squash or rebase it. Then follow the production deployment and publication process below.

## Production deployment and publication

### 1. Open the release pull request

For the standard selective process, open a draft pull request from `release/<YYMMDD-short-name>` into `master`. For the exceptional batch process, the head is `dev`. Its title should be:

```text
Release YYYY-MM-DD
```

Use this body:

```markdown
## User-visible changes

### New
- ...

### Changed
- ...

### Fixed
- ...

## Acceptance testing

- [ ] Staging deployment succeeded
- [ ] Critical user flows passed
- [ ] Available tester feedback recorded
- [ ] Known issues documented and accepted

## Deployment review

- [ ] CI passes
- [ ] Migrations reviewed
- [ ] Production backup confirmed when required
- [ ] Environment variables and secrets are ready
- [ ] Queue and scheduler impact reviewed
- [ ] External-service changes are ready
- [ ] Rollback compatibility reviewed

## Post-deployment smoke tests

- [ ] `/up`
- [ ] Authentication
- [ ] Admin access
- [ ] Relevant changed workflows
- [ ] Queue health
- [ ] Sentry and application logs
```

The release pull request is the acceptance record. Testers may report results outside GitHub; copy the outcome into the pull request. List and link every selected feature or fix pull request in the release description.

### 2. Review the production diff

- Confirm the pull request base is `master`.
- Confirm the head is the tested `release/*` candidate, or `dev` only for an explicitly approved batch release.
- Confirm the diff contains only the selected and accepted changes.
- Review all migrations and operational requirements.
- Ensure `master` has not received an unmerged hotfix.
- Confirm the previous production deployment is complete.
- Resolve all open conversations and failed checks.

Use **Create a merge commit** for the release pull request. Do not use squash or rebase.

### 3. Deploy production

After merging:

- [ ] Monitor **Deploy to production** until every deployment task succeeds.
- [ ] Record the workflow run and deployed commit SHA.
- [ ] Confirm the active Deployer release changed.
- [ ] Run the post-deployment smoke tests from the release pull request.
- [ ] Check Sentry, Laravel logs, failed queue jobs, and the scheduler.
- [ ] Confirm critical external integrations relevant to the release.

If deployment fails, do not create a release tag. Correct the problem through the normal branch process or follow the maintenance runbook when recovery is required.

### 4. Tag the successful production commit

From an updated local repository:

```bash
git fetch origin master --tags
git switch master
git pull --ff-only origin master
git tag -a v1.YYMMDD.N <deployed-commit-sha> \
    -m 'Production release v1.YYMMDD.N'
git show --no-patch --pretty=fuller v1.YYMMDD.N
git push origin refs/tags/v1.YYMMDD.N
```

Do not assume local `master` is the deployed commit. Compare the full SHA with the successful production workflow run before tagging.

### 5. Publish the GitHub Release

Create a draft GitHub Release from the existing tag, choose the previous production tag, and generate the initial notes.

Use this final format:

```markdown
Originally deployed: YYYY-MM-DD HH:MM America/New_York
Production commit: `<short-sha>`

## Highlights

One to three sentences describing the most important outcome.

## New

- Plain-language user-visible additions.

## Changed

- Plain-language behavior changes.

## Fixed

- Problems users may recognize that are now resolved.

## Operational notes

- Migrations, configuration, integrations, or support considerations.
- Write `None` when there are none.

## Known issues

- Accepted limitations or follow-up work.
- Write `None known` when appropriate.
```

Remove empty user-facing sections if that makes the notes easier to read. Keep generated pull-request links and the full changelog link below the curated summary for traceability.

Publish only after the deployment and smoke tests succeed. Send the highlights to users through their normal communication channel; do not assume they monitor GitHub Releases.

## Hotfix workflow

For an urgent production defect:

1. Branch from `master`, not `dev`.
2. Implement the smallest safe correction with a regression test.
3. Merge the fix branch into `dev` with a merge-commit pull request when time permits integration testing.
4. Create `release/<YYMMDD-hotfix-name>` from current `master` and merge the fix branch into it.
5. Deploy and test the exact hotfix candidate on staging.
6. Open the release candidate into `master` and follow the normal production checks.
7. Ensure the same fix branch commits are reachable from `dev` so the fix cannot be lost in a later release.
8. Tag and document the hotfix with the next `v<generation>.<YYMMDD>.<daily-sequence>` version.

Do not reset `dev`, bypass the release-candidate test without recording the emergency decision, or edit production files directly.

## Rollback and failed-release records

- Do not delete or move a tag for a release that reached production.
- Add a prominent note to the GitHub Release stating when and why it was rolled back.
- Follow `APPLICATION_MAINTENANCE_RUNBOOK.md` for Deployer rollback and database compatibility checks.
- Never assume code rollback reverses a database migration.
- The corrected deployment receives a new version and release notes.
- If a merge never deployed successfully, do not tag it as a production release.

## Periodic release-process review

Quarterly, or after a release incident:

- [ ] Confirm branch and tag rulesets are active.
- [ ] Confirm required CI checks still exist and pass.
- [ ] Review environment access and secrets.
- [ ] Review GitHub Actions versions, permissions, and pinning.
- [ ] Verify production deployments cannot cancel one another.
- [ ] Confirm each production deployment since the last review has exactly one tag and GitHub Release.
- [ ] Confirm release notes remain understandable to users.
- [ ] Exercise the documented staging rollback procedure.
- [ ] Update this document with lessons learned.

## References

- Git tag documentation: <https://git-scm.com/docs/git-tag>
- GitHub releases: <https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository>
- GitHub generated release notes: <https://docs.github.com/en/repositories/releasing-projects-on-github/automatically-generated-release-notes>
- GitHub repository rulesets: <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets>
- GitHub deployment environments: <https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments>
- GitHub immutable releases: <https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases>
- GitHub Actions secure use: <https://docs.github.com/en/actions/reference/security/secure-use>
