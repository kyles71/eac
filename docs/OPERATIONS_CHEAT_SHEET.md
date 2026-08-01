# EAC Operations Cheat Sheet

Last reviewed: 2026-08-01

Start here for common commands and credential rotation. Use the linked runbook section for unusual, destructive, or incompletely understood situations.

- [Deployment secrets](#deployment-secrets)
- [Rotate an SSH deployment key](#rotate-an-ssh-deployment-key)
- [Rotate the private-repository token](#rotate-the-private-repository-token)
- [Rotate the Updates feed token](#rotate-the-updates-feed-token)
- [Deploy and verify](#deploy-and-verify)
- [Resolve a feature-to-dev conflict](#resolve-a-feature-to-dev-conflict)
- [Common server commands](#common-server-commands)
- [Deployment recovery](#deployment-recovery)

Replace `<PLACEHOLDERS>`. Never paste a private key, token, `.env`, or password into a commit, ticket, chat, or workflow log.

## Environment map

| Concern | Production | Dev |
| --- | --- | --- |
| Branch | `master` | `dev` |
| GitHub Environment | `production` | `dev` |
| Root | `/var/www/html/eac` | `/var/www/html/eac-test` |
| Active release | `/var/www/html/eac/current` | `/var/www/html/eac-test/current` |
| Worker | `eac-laravel-worker` | `eac-test-laravel-worker` |

Run workstation commands locally. On the server, use `kyle` for administration, `deployer` for deployment access, and `sudo -u www-data` for Laravel runtime commands.

More detail: [Maintenance — Environment inventory](APPLICATION_MAINTENANCE_RUNBOOK.md#environment-inventory).

## Deployment secrets

Location: **Repository → Settings → Environments → `dev` or `production` → Environment secrets**

| Secret | Exact value type |
| --- | --- |
| `DEPLOY_HOST` | SSH hostname or IP only; no scheme, username, or path |
| `DEPLOY_USER` | Linux deployment account, normally `deployer` |
| `PRIVATE_KEY` | Complete unencrypted private SSH key with BEGIN/END lines |
| `MY_PRIVATE_GH_TOKEN` | Fine-grained token with read access to both private Composer repositories |

`DEPLOY_PASSWORD` is not required.

```text
Local private key  → GitHub PRIVATE_KEY
Local public key   → Server deployer's ~/.ssh/authorized_keys
```

The private key does not belong on the server. The public `.pub` key does not belong in GitHub.

More detail:

- [Production activation — Deployment access](PRODUCTION_ACTIVATION_RUNBOOK.md#4-deployment-access-and-cicd)
- [Release workflow — GitHub Environments](RELEASE_WORKFLOW.md#github-environments)

## Rotate an SSH deployment key

Prefer separate `dev` and `production` keys. Both public keys can be authorized for the same server user.

### 1. Generate — Local

Set the environment to `dev` or `production`:

```bash
EAC_DEPLOY_ENV="dev"
EAC_DEPLOY_KEY="$HOME/.ssh/eac_github_actions_${EAC_DEPLOY_ENV}_$(date +%Y%m%d)"

ssh-keygen \
    -t ed25519 \
    -C "github-actions-eac-${EAC_DEPLOY_ENV}" \
    -f "$EAC_DEPLOY_KEY" \
    -N ""

ssh-keygen -lf "${EAC_DEPLOY_KEY}.pub"
```

Stop if asked to overwrite a file. Record the fingerprint securely.

### 2. Authorize — Local

```bash
ssh-copy-id \
    -i "${EAC_DEPLOY_KEY}.pub" \
    <DEPLOY_USER>@<DEPLOY_HOST>
```

If that login is unavailable, display the public key locally:

```bash
cat "${EAC_DEPLOY_KEY}.pub"
```

Then install it through an administrative server session:

```bash
sudo -iu <DEPLOY_USER>
mkdir -p ~/.ssh
chmod 700 ~/.ssh
nano ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
exit
```

### 3. Verify — Server admin and Local

List server-authorized fingerprints:

```bash
sudo -iu <DEPLOY_USER> sh -c \
    'ssh-keygen -lf "$HOME/.ssh/authorized_keys"'
```

Test the exact private key locally:

```bash
ssh \
    -o IdentitiesOnly=yes \
    -i "$EAC_DEPLOY_KEY" \
    <DEPLOY_USER>@<DEPLOY_HOST>
```

Do not continue until this connects without the server account password.

### 4. Update and test — GitHub

Update the target environment's `PRIVATE_KEY` with the complete contents of:

```bash
less "$EAC_DEPLOY_KEY"
```

The value begins with `-----BEGIN OPENSSH PRIVATE KEY-----` and ends with `-----END OPENSSH PRIVATE KEY-----`. Do not use the `.pub` file or add quotes.

For `dev`, manually run **Deploy dev branch**. For `production`, locally test its separate key and use it for the next approved production release.

### 5. Retire the old key — Server admin

After a successful deployment, edit the deploy user's `authorized_keys` and remove only the old line identified by its recorded fingerprint:

```bash
sudo -iu <DEPLOY_USER>
nano ~/.ssh/authorized_keys
exit
```

If Actions reports `Permission denied (publickey,password)`, compare fingerprints, confirm `DEPLOY_USER`, confirm `.ssh` is `700` and `authorized_keys` is `600`, run the exact local SSH test, then inspect:

```bash
sudo journalctl -u ssh --since "10 minutes ago" --no-pager
```

Use `-u sshd` instead when that is the host's service name.

More detail: [Maintenance — Failed deployment](APPLICATION_MAINTENANCE_RUNBOOK.md#failed-deployment).

## Rotate the private-repository token

The token must read:

- `kyles71/filament-mail-manager`
- `kyles71/filament-themes`

### 1. Create — GitHub

Open **Profile → Settings → Developer settings → Personal access tokens → Fine-grained tokens**.

Use resource owner `kyles71`, select only the two repositories above, grant **Contents: Read-only**, and set a recorded expiration.

### 2. Update both authentication locations

In GitHub, update `MY_PRIVATE_GH_TOKEN` in both the `dev` and `production` Environments.

The remote `deployer` account has separate Composer authentication. Update it without printing the existing token:

```bash
sudo -iu deployer
EDITOR=nano composer config --global --auth --editor
exit
```

Update only:

```json
{
    "github-oauth": {
        "github.com": "<NEW_TOKEN>"
    }
}
```

Do not commit `auth.json`.

### 3. Verify and revoke

1. Run **Deploy dev branch** and confirm both Composer install steps succeed.
2. Validate the token during the next approved production deployment.
3. Revoke the old token only after both locations work.

More detail: [Production activation — Deployment access](PRODUCTION_ACTIVATION_RUNBOOK.md#4-deployment-access-and-cicd).

## Rotate the Updates feed token

This token is separate from `MY_PRIVATE_GH_TOKEN`. Restrict it to `kyles71/eac` with read-only access to Contents, Pull Requests, and Deployments.

1. Create the replacement fine-grained token with a recorded expiration.
2. Update `GITHUB_UPDATES_TOKEN` in `/var/www/html/eac-test/shared/.env` and `/var/www/html/eac/shared/.env` through the approved secret-management process.
3. Run `sudo -u www-data php artisan config:clear` from each active release.
4. Open **Updates** in each admin panel, use **Refresh**, and confirm dev and production entries load.
5. Revoke the old token only after both environments work.

Never reuse the deployment/Composer token for the application feed; the feed token needs no write access and no access to the private package repositories.

## Deploy and verify

| Goal | Action |
| --- | --- |
| Deploy normal dev | Merge the feature PR into `dev` |
| Resolve a conflicting dev PR | Locally merge the feature into `dev` with `--no-ff`, resolve, and push `dev` |
| Merge GitHub-only automation/docs | Apply `skip-deployment` before merging; also apply the appropriate update-note label |
| Redeploy current dev | Run **Actions → Deploy dev branch → Run workflow** |
| Deploy one feature to production | Merge that tested feature branch's PR into `master` |
| Publish the release | Review smoke tests and the automated draft GitHub Release |

Use `skip-deployment` only when the PR changes GitHub-only automation or documentation and the servers do not need the commit. Never use it for application code, dependencies, built assets, migrations, seeders, configuration, queues, schedules, or worker behavior. A production skip also skips tagging and draft Release creation. Manual dev redeployments ignore the label and always run.

Inspect active releases on the server:

```bash
readlink -f /var/www/html/eac/current
readlink -f /var/www/html/eac-test/current
ls -lt /var/www/html/eac/releases | head
ls -lt /var/www/html/eac-test/releases | head
```

Verify release tags locally:

```bash
git fetch origin master --tags
git tag --list 'v*' --sort=version:refname
git show --no-patch --pretty=fuller <VERSION_TAG>
git rev-parse '<VERSION_TAG>^{}'
git rev-parse origin/master
```

More detail:

- [Release workflow — Feature lifecycle](RELEASE_WORKFLOW.md#standard-feature-lifecycle)
- [Release workflow — Production deployment](RELEASE_WORKFLOW.md#7-production-deployment-and-release)

## Resolve a feature-to-dev conflict

Use this when Feature A is already on `dev`, Feature B is still based on `master`, and Feature B's dev PR conflicts. Do not merge `dev` into Feature B and do not use GitHub's **Update branch** action. Keep Feature B clean for its independent master PR.

Keep the original dev PR open. Starting with a clean working tree, merge Feature B directly into local `dev`:

```bash
git status --short
git fetch origin
git switch dev
git pull --ff-only origin dev
git merge --no-ff --no-commit origin/feature/feature-b
```

Resolve the combined dev behavior, then:

```bash
git status
git add path/to/resolved-file
git commit -m "Merge feature/feature-b into dev for QA"
git push origin dev
```

The push automatically deploys `dev`. The automation traces the merge commit's second parent to the original dev PR, copies its marked user-facing and operational note blocks, then creates or updates the master draft from the clean Feature B branch. Existing valid notes on the master draft are preserved. GitHub will normally recognize the original dev PR as merged; do not create a replacement dev PR.

Conflict-only changes remain in the merge commit on `dev`; production-required changes must also be committed to Feature B and redeployed. Use `git merge --abort` before committing if the resolution is wrong. For subsequent Feature B fixes, open another dev PR and repeat the direct merge only if it conflicts. See [Release workflow — Feature B conflicts with Feature A already on dev](RELEASE_WORKFLOW.md#feature-b-conflicts-with-feature-a-already-on-dev) for rationale and edge cases.

## Common server commands

Set production or dev once:

```bash
EAC_ROOT="/var/www/html/eac"
```

Use `/var/www/html/eac-test` for dev.

### Application health — read-only

```bash
cd "$EAC_ROOT/current"
sudo -u www-data /usr/bin/php8.4 artisan about --only=environment,drivers
sudo -u www-data /usr/bin/php8.4 artisan migrate:status
sudo -u www-data /usr/bin/php8.4 artisan queue:failed
sudo -u www-data /usr/bin/php8.4 artisan schedule:list
```

### Host and logs — read-only

```bash
date --iso-8601=seconds
uptime
free -h
df -h
df -i
sudo systemctl --failed
sudo supervisorctl status
sudo apache2ctl configtest
sudo tail -n 200 "$EAC_ROOT/shared/storage/logs/laravel-$(date +%F).log"
sudo journalctl -u apache2 --since "30 minutes ago" --no-pager
sudo tail -n 200 /var/log/apache2/error.log
```

### Queue — inspect, then gracefully restart

```bash
sudo supervisorctl status 'eac-laravel-worker:*'
sudo supervisorctl status 'eac-test-laravel-worker:*'

cd "$EAC_ROOT/current"
sudo -u www-data /usr/bin/php8.4 artisan queue:monitor database:default --max=100 --json
sudo -u www-data /usr/bin/php8.4 artisan queue:restart
```

### Backups

Read-only:

```bash
cd "$EAC_ROOT/current"
sudo -u www-data /usr/bin/php8.4 artisan backup:list
sudo -u www-data /usr/bin/php8.4 artisan backup:monitor -vvv
```

Create and upload a database backup:

```bash
sudo -u www-data /usr/bin/php8.4 artisan backup:database -vvv
```

Do not run `backup:clean` merely to test connectivity.

### Approved `.env` change

```bash
cd "$EAC_ROOT/current"
sudo -u www-data /usr/bin/php8.4 artisan config:cache
```

Do not casually run `cache:clear` or `optimize:clear`.

Detailed commands:

- [Maintenance — First response](APPLICATION_MAINTENANCE_RUNBOOK.md#1-first-response-checklist)
- [Maintenance — Logs](APPLICATION_MAINTENANCE_RUNBOOK.md#2-logs-and-evidence)
- [Maintenance — Queues](APPLICATION_MAINTENANCE_RUNBOOK.md#6-queue-workers-and-delayed-email)
- [Maintenance — Backups](APPLICATION_MAINTENANCE_RUNBOOK.md#9-database-backups-and-restoration)
- [Maintenance — Caches](APPLICATION_MAINTENANCE_RUNBOOK.md#4-laravel-application-state-and-caches)

## Deployment recovery

From a trusted local checkout, load the correct key without putting secrets on the command line:

```bash
cd <LOCAL_EAC_REPOSITORY>
eval "$(ssh-agent -s)"
ssh-add <PRIVATE_KEY_FILE>
export DEPLOY_HOST="<DEPLOY_HOST>"
export DEPLOY_USER="<DEPLOY_USER>"
```

After proving no deployment is active, remove a stale lock:

```bash
vendor/bin/dep deploy:unlock env=dev
```

Use `env=production` only for a proven-stale production lock.

Rollback changes production. Review migration compatibility first:

```bash
vendor/bin/dep rollback env=production
```

Verify the active symlink, health, migrations, queues, scheduler, and logs afterward.

More detail: [Maintenance — Deployments and rollback](APPLICATION_MAINTENANCE_RUNBOOK.md#10-deployments-and-rollback).

## Never use as routine production troubleshooting

```text
php artisan migrate:fresh
php artisan migrate:reset
php artisan db:wipe
php artisan queue:clear
php artisan queue:flush
php artisan cache:clear
php artisan optimize:clear
```

Do not manually delete anything under `shared`, `releases`, `.dep`, or `current`.
