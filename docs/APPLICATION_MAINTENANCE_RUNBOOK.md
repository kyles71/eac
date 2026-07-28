# EAC Application Maintenance and Debugging Runbook

Last reviewed: 2026-07-20
Application: Laravel 13 / Filament 5
Host stack: Ubuntu, Apache, PHP 8.4, local MySQL, Supervisor, cron, and Deployer

## Purpose

This runbook is the day-to-day companion to `PRODUCTION_ACTIVATION_RUNBOOK.md`. It covers routine checks, incident triage, safe recovery, and debugging for both deployed environments. The standard development, staging, production, tagging, and release-note process is documented in `RELEASE_WORKFLOW.md`.

Use this guide to gather evidence before changing state. Preserve timestamps, error messages, request or payment identifiers, the active release number, and relevant log excerpts in incident notes.

## Environment inventory

| Concern | Production | Staging |
| --- | --- | --- |
| Branch | `master` | `dev` |
| Deployment root | `/var/www/html/eac` | `/var/www/html/eac-test` |
| Active release | `/var/www/html/eac/current` | `/var/www/html/eac-test/current` |
| Shared state | `/var/www/html/eac/shared` | `/var/www/html/eac-test/shared` |
| Laravel logs | `shared/storage/logs` | `shared/storage/logs` |
| Supervisor program | `eac-laravel-worker` | `eac-test-laravel-worker` |
| Scheduler owner | `www-data` personal crontab | `www-data` personal crontab |
| Database | Local MySQL | Local MySQL |
| Error reporting | Sentry, environment `production` | Sentry, staging environment/project |

Confirm hostnames from each environment's `APP_URL`; do not infer them from memory during an incident.

## Operating rules

1. Run Laravel runtime commands as `www-data`:

   ```bash
   cd /var/www/html/eac/current
   sudo -u www-data /usr/bin/php8.4 artisan about
   ```

2. Use `deployer` for deployments and release-tree management. Use `kyle` for SSH administration. Do not broaden `kyle`'s application ACL merely to avoid `sudo -u www-data`.
3. Do not run bare `sudo php artisan ...`. Root-created log, cache, session, and temporary files can break later web, queue, and scheduler processes.
4. Start with production read-only checks. Reproduce in staging when possible before applying a production fix.
5. Never display, copy into tickets, or log `.env`, Stripe keys, webhook secrets, database passwords, backup passwords, or object-storage credentials.
6. Before a state-changing command, confirm all three of these:
   - the environment and active release;
   - the expected scope and user impact;
   - the rollback or recovery path.
7. Do not use `migrate:fresh`, `db:wipe`, `migrate:reset`, `queue:clear`, or `queue:flush` in production.
8. Do not delete anything under `shared`, `releases`, or `current` manually. Use Deployer and documented retention procedures.

## 1. First-response checklist

### Capture the state before restarting anything

```bash
date --iso-8601=seconds
hostnamectl
uptime
free -h
df -h
df -i
sudo systemctl --failed
sudo supervisorctl status
sudo apache2ctl configtest
```

Record the active releases:

```bash
readlink -f /var/www/html/eac/current
readlink -f /var/www/html/eac-test/current
ls -lt /var/www/html/eac/releases | head
ls -lt /var/www/html/eac-test/releases | head
```

Check Laravel without dumping configuration secrets:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan about --only=environment,drivers
sudo -u www-data /usr/bin/php8.4 artisan migrate:status
sudo -u www-data /usr/bin/php8.4 artisan queue:failed
sudo -u www-data /usr/bin/php8.4 artisan schedule:list
```

Repeat against `/var/www/html/eac-test/current` when staging is also affected. `schedule:list` uses scheduler mutex infrastructure and may fail when the database-backed cache is unavailable; treat that as database evidence rather than immediately clearing locks.

### Check health from both sides of the host

From the server, use the canonical URLs stored in `APP_URL`:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan config:show app.url
curl --fail --silent --show-error --head https://<production-host>/up
curl --fail --silent --show-error --head https://<staging-host>/up
```

Also test from another network. A local success with an external failure points toward DNS, TLS, firewall, proxy, or load-balancer behavior rather than Laravel itself.

### Establish the incident boundary

- Both environments down: investigate host resources, Apache, PHP, MySQL, certificates, and shared infrastructure first.
- One environment down: compare its active release, `.env` link, logs, Apache virtual host, Supervisor program, and database.
- Web works but email is delayed: investigate the queue worker and provider.
- Web works but scheduled work is missing: investigate cron, scheduler locks, and the failing command.
- Only checkout or billing fails: preserve Stripe identifiers and inspect webhook/API activity before changing orders.
- Only uploads or backups fail: inspect IONOS access and Laravel writable directories.

## 2. Logs and evidence

### Laravel logs

Laravel uses daily logs. List files before choosing one so an old filename is not mistaken for current evidence:

```bash
sudo ls -lht /var/www/html/eac/shared/storage/logs | head -20
sudo tail -n 200 /var/www/html/eac/shared/storage/logs/laravel-$(date +%F).log
sudo ls -lht /var/www/html/eac-test/shared/storage/logs | head -20
sudo tail -n 200 /var/www/html/eac-test/shared/storage/logs/laravel-$(date +%F).log
```

Follow a log only while reproducing a known request:

```bash
sudo tail -f /var/www/html/eac/shared/storage/logs/laravel-$(date +%F).log
```

Do not publish complete logs without reviewing them for personal information, signed URLs, request payloads, and provider identifiers.

### Apache and operating-system logs

```bash
sudo journalctl -u apache2 --since '30 minutes ago' --no-pager
sudo tail -n 200 /var/log/apache2/error.log
sudo tail -n 200 /var/log/apache2/access.log
sudo journalctl -p warning --since '30 minutes ago' --no-pager
```

Use a narrower time window and request path when logs are busy. Apache access logs establish HTTP status, client, path, and timing; Laravel or PHP logs usually establish the application cause.

### Supervisor logs

Find the configured log paths instead of assuming them:

```bash
sudo grep -RnsE 'program:eac|stdout_logfile|stderr_logfile|command=|user=' \
    /etc/supervisor/conf.d /etc/supervisor/supervisord.conf
sudo supervisorctl status
```

### Sentry

For each issue, record:

- environment and release;
- first and most recent occurrence;
- command, route, or transaction;
- relevant user/order/payment identifiers without copying secrets;
- whether the exception shown is the original failure or a secondary logging/reporting failure.

Generate a deliberate non-sensitive test event only when validation is necessary:

```bash
cd /var/www/html/eac-test/current
sudo -u www-data /usr/bin/php8.4 artisan sentry:test
```

Test staging first. A test event changes external monitoring state and may trigger alerts.

## 3. Apache, PHP-FPM, and HTTP failures

### What PHP-FPM is

PHP-FPM is the FastCGI Process Manager. It keeps PHP worker processes running and lets Apache forward PHP requests to them, commonly through a socket such as `/run/php/php8.4-fpm.sock`. Browser requests pass through Apache and, when configured, PHP-FPM. CLI commands such as `php artisan` use the CLI binary directly and do not pass through Apache or FPM.

The repository requires PHP 8.4 but cannot determine whether the host uses FPM or Apache's in-process `mod_php`. Detect the actual handler:

```bash
sudo apache2ctl -M | grep -E 'proxy_fcgi|php'
sudo grep -RnsE 'SetHandler|ProxyPassMatch|php.*fpm.sock' \
    /etc/apache2/sites-enabled /etc/apache2/conf-enabled
systemctl list-unit-files 'php*-fpm.service'
systemctl list-units --type=service --all 'php*-fpm.service'
```

Interpretation:

- `proxy_fcgi_module` plus a PHP socket or proxy handler means Apache uses FPM.
- A module such as `php_module` usually means Apache uses `mod_php` and there may be no FPM service.
- If `/run/php/php8.4-fpm.sock` is configured, the likely service is `php8.4-fpm`.

Once identified, inspect it without restarting:

```bash
sudo systemctl status php8.4-fpm --no-pager
sudo journalctl -u php8.4-fpm --since '30 minutes ago' --no-pager
```

Do not create a public `phpinfo()` page. It exposes paths, extensions, environment details, and potentially sensitive configuration.

### Status-code guide

- `403`: Apache authorization, filesystem traversal, or document-root configuration.
- `404`: wrong virtual host/document root, stale routes, or an application route mismatch.
- `500`: inspect Laravel, PHP, and Sentry first.
- `502` or `proxy_fcgi` errors: inspect the configured FPM service/socket.
- `503`: maintenance mode, exhausted workers, or an unavailable upstream.
- Redirect loops or mixed HTTP/HTTPS URLs: inspect TLS termination, `APP_URL`, and trusted-proxy handling.

### Safe Apache recovery

Validate configuration before reload:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Use a restart only when a reload cannot apply the required change:

```bash
sudo systemctl restart apache2
```

If FPM is in use, reload it after a PHP configuration change:

```bash
sudo systemctl reload php8.4-fpm
```

Replace the service name only after detection. Never repeatedly restart Apache, FPM, and MySQL together; doing so destroys evidence and obscures the failing layer.

## 4. Laravel application state and caches

### Verify environment and release

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan env
sudo -u www-data /usr/bin/php8.4 artisan about --only=environment,drivers
readlink -f /var/www/html/eac/current
readlink -f /var/www/html/eac/current/.env
readlink -f /var/www/html/eac/current/storage
```

Expected production links point into `/var/www/html/eac/shared`. Repeat with `eac-test` for staging.

### Configuration changes

Laravel production configuration is cached. After an approved `.env` change:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan config:cache
```

Then validate the specific behavior and check logs. Do not run `config:show` for entire service configurations because output may contain secrets.

### Targeted cache recovery

Prefer the narrowest command:

```bash
sudo -u www-data /usr/bin/php8.4 artisan config:clear
sudo -u www-data /usr/bin/php8.4 artisan config:cache
sudo -u www-data /usr/bin/php8.4 artisan route:clear
sudo -u www-data /usr/bin/php8.4 artisan route:cache
sudo -u www-data /usr/bin/php8.4 artisan view:clear
sudo -u www-data /usr/bin/php8.4 artisan view:cache
sudo -u www-data /usr/bin/php8.4 artisan event:clear
sudo -u www-data /usr/bin/php8.4 artisan event:cache
```

Do not casually run `cache:clear` or `optimize:clear` in production. The default cache is database-backed and contains scheduler locks, queue restart signals, rate-limit state, and application cache entries. Determine why a full flush is necessary first.

### Maintenance mode

Put production into maintenance mode before an approved recovery that cannot safely run with traffic:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan down --retry=60 --refresh=15
```

Restore service after validation:

```bash
sudo -u www-data /usr/bin/php8.4 artisan up
```

Do not forget that a shared `storage` directory can preserve maintenance state across release changes. Record who enabled maintenance mode and why.

## 5. Filesystem ownership and ACLs

Apache, PHP-FPM, queues, scheduled commands, and runtime Artisan diagnostics should operate as `www-data`. Deployer grants `www-data` and the `deployer` account access to Laravel's writable paths with ACLs. Application code does not need to be owned by `www-data`.

Inspect every path component and the effective ACL:

```bash
namei -l /var/www/html/eac/current/storage/logs
sudo stat -c '%U:%G %a %A %n' \
    /var/www/html/eac/shared/storage \
    /var/www/html/eac/shared/storage/logs \
    /var/www/html/eac/current/bootstrap/cache
sudo getfacl /var/www/html/eac/shared/storage/logs
sudo -u www-data test -w /var/www/html/eac/shared/storage/logs \
    && echo writable || echo not-writable
sudo -u www-data test -w /var/www/html/eac/shared/storage/app \
    && echo writable || echo not-writable
```

ACL entries provide named-user permissions in addition to owner/group/other mode bits:

- `u:www-data:rwX`: read/write, plus directory traversal.
- `u:deployer:rwX`: lets Deployer maintain writable runtime paths.
- `d:u:www-data:rwX`: a default ACL inherited by new files and directories.
- `X`: adds execute only for directories or already-executable files; it does not make ordinary files executable.

If effective tests fail, repair only Laravel's writable paths after confirming targets:

```bash
sudo setfacl -R -m u:www-data:rwX,u:deployer:rwX \
    /var/www/html/eac/shared/storage \
    /var/www/html/eac/current/bootstrap/cache
sudo find /var/www/html/eac/shared/storage -type d \
    -exec setfacl -m d:u:www-data:rwX,d:u:deployer:rwX {} +

sudo setfacl -R -m u:www-data:rwX,u:deployer:rwX \
    /var/www/html/eac-test/shared/storage \
    /var/www/html/eac-test/current/bootstrap/cache
sudo find /var/www/html/eac-test/shared/storage -type d \
    -exec setfacl -m d:u:www-data:rwX,d:u:deployer:rwX {} +
```

These commands do not change ownership and do not grant access to everyone. Do not recursively `chown` the entire deployment tree to `www-data`, and do not use `chmod -R 777`.

## 6. Queue workers and delayed email

### Inspect workers

```bash
sudo supervisorctl status 'eac-laravel-worker:*'
sudo supervisorctl status 'eac-test-laravel-worker:*'
sudo grep -RnsE 'program:eac|command=|directory=|user=|stdout_logfile=' \
    /etc/supervisor/conf.d
```

Expected workers are `RUNNING`, execute from the matching `current` path, and run as `www-data`.

Inspect application and configured worker logs before restarting. Common causes include database unavailability, unwritable logs, provider errors, worker timeouts, and code changes loaded by a long-running old process.

### Inspect queue state

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan queue:failed
sudo -u www-data /usr/bin/php8.4 artisan queue:monitor database:default --max=100 --json
```

Repeat in staging when appropriate. A growing backlog with a running worker often means a repeatedly failing or long-running job; a growing backlog with no running worker indicates Supervisor or worker startup failure.

### Safe worker recovery

Request a graceful restart first:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan queue:restart
sudo supervisorctl status 'eac-laravel-worker:*'
```

Supervisor should automatically start the replacement process. If it does not, fix Supervisor before retrying jobs.

Restart the Supervisor group only when graceful restart is insufficient:

```bash
sudo supervisorctl restart 'eac-laravel-worker:*'
```

For staging, substitute `eac-test-laravel-worker:*`.

### Failed jobs

Review the exception and whether the operation is safe to repeat. Retry one reviewed job:

```bash
sudo -u www-data /usr/bin/php8.4 artisan queue:retry <failed-job-uuid>
```

Do not use `queue:retry all` until every failure class has been assessed for duplicate mail, payment, order, or notification side effects. `queue:forget`, `queue:flush`, and `queue:clear` destroy records or queued work and require an explicit recovery decision.

## 7. Scheduler and cron

Both environments are installed in `www-data`'s personal crontab:

```bash
sudo crontab -u www-data -l
```

Expected entries:

```cron
* * * * * cd /var/www/html/eac-test/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
* * * * * cd /var/www/html/eac/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

A personal crontab must not contain a username column. A username such as `www-data` is included only in `/etc/crontab` or files under `/etc/cron.d`.

Audit for duplicate or incorrectly owned schedulers:

```bash
sudo grep -Rns 'artisan schedule:run' \
    /etc/crontab /etc/cron.d /var/spool/cron/crontabs 2>/dev/null
sudo journalctl -u cron --since '15 minutes ago' --no-pager \
    | grep 'schedule:run'
```

The journal should show `(www-data)` for both environments once per minute.

List tasks without executing them:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan schedule:list
cd /var/www/html/eac-test/current
sudo -u www-data /usr/bin/php8.4 artisan schedule:list
```

`schedule:test` and `schedule:run` can execute state-changing commands. Do not use them as read-only diagnostics. Prefer running a specific known command directly as `www-data`.

If a stale `withoutOverlapping` lock is suspected, first prove the task is not still running. Only then clear scheduler mutexes:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan schedule:clear-cache
```

This clears all scheduler mutexes for that environment, not just one task.

For an approved maintenance window, pause production scheduling without removing the cron entry:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan schedule:pause
```

Cron will continue invoking Laravel, but due tasks will be skipped using the production cache. Resume it explicitly and verify the cron journal afterward:

```bash
sudo -u www-data /usr/bin/php8.4 artisan schedule:resume
```

Record every pause so scheduling cannot be forgotten after maintenance. Production and staging have separate application caches and must be paused or resumed from their respective `current` directories.

## 8. Database troubleshooting

MySQL runs on the application host. Check the service and storage first:

```bash
sudo systemctl status mysql --no-pager
sudo journalctl -u mysql --since '30 minutes ago' --no-pager
sudo mysqladmin ping
df -h
df -i
```

If the service is named `mariadb`, use that name instead. Then test through Laravel as `www-data`:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan migrate:status
sudo -u www-data /usr/bin/php8.4 artisan db:show
```

Common causes:

- `Connection refused`: MySQL stopped, wrong port, or no listener.
- `Access denied`: wrong username/password/host grant or stale cached configuration.
- `Too many connections`: connection leak, slow queries, or inadequate limits.
- Lock wait/deadlock: inspect the exact query and transaction before retrying.
- Disk full: free space safely before restarting MySQL.

Do not run migrations as a speculative repair. Never run destructive migration commands in production. Before manual data repair, take a fresh backup, record the query, use a transaction where possible, and verify the affected rows with a read-only query first.

## 9. Database backups and restoration

Production schedules:

- 3:10 a.m. Eastern: `backup:clean`
- 3:40 a.m. Eastern: encrypted database backup
- 6:10 a.m. Eastern: `backup:monitor`

All require Laravel logging and temporary-storage access; backup creation also uses `storage/app/backup-temp`. Run diagnostics as `www-data`:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan backup:list
sudo -u www-data /usr/bin/php8.4 artisan backup:monitor -vvv
sudo -u www-data /usr/bin/php8.4 artisan backup:database -vvv
```

`backup:monitor` does not support `--disable-notifications`. Package notifications are already disabled in the application's backup configuration; scheduled failures are reported through Laravel's failure callback.

`backup:database` creates and uploads a new encrypted backup. Confirm the resulting object, timestamp, size, and health rather than relying only on exit code.

Do not run `backup:clean` merely to test connectivity. It deletes backups according to retention. Manual staging backups must use a distinct `BACKUP_NAME` and reviewed bucket/prefix so they cannot mix with production.

### Backup failure checklist

1. Check the first exception, not only the scheduler's generic failure callback.
2. Verify `www-data` can append to `storage/logs` and write `storage/app/backup-temp`.
3. Check free disk space and inodes.
4. Confirm MySQL connectivity and dump tooling.
5. Confirm the archive password is configured without printing it.
6. Confirm IONOS credentials can list and upload objects.
7. For cleanup, also confirm delete permission.
8. Remove abandoned temporary data only after proving no backup process is active.

### Restore procedure

A successful backup is not proven recoverable until it has been restored and validated. Perform routine restore drills into a separate scratch database, never over production.

1. Download one backup through an approved authenticated path.
2. Verify its expected timestamp and size.
3. Extract it with an AES-256-capable ZIP utility that prompts interactively for the archive password. Do not put the password on the command line or in shell history.
4. Inspect the archive and SQL dump before importing.
5. Create a separate scratch database with no application traffic.
6. Import the SQL into the scratch database.
7. Check table counts, critical relationships, migrations, and representative records.
8. Destroy the scratch copy through the approved database procedure after recording the result.

An emergency production restore requires an incident-specific plan:

1. Confirm the recovery point and accepted data-loss window.
2. Enable maintenance mode.
3. Pause production scheduling with `schedule:pause` and stop the worker with `sudo supervisorctl stop 'eac-laravel-worker:*'`.
4. Take a final snapshot of the damaged/current database if possible.
5. Restore into a new database first and validate it.
6. Switch production only after review; do not drop the old database as the first step.
7. Verify migrations, configuration, critical records, and application health.
8. Start the worker, run `schedule:resume`, disable maintenance mode, and perform smoke tests.

Do not perform an emergency production restore without a second review of the exact target database and backup timestamp.

## 10. Deployments and rollback

Normal deployments run through GitHub Actions and Deployer:

- `master` deploys production.
- `dev` deploys staging.
- A single-segment `release/*` branch assembled from selected master-based feature branches is the standard production candidate and temporarily deploys to staging.
- A direct `dev` to `master` batch release is an exception requiring approval of every included change.
- The manually triggered **Deploy dev branch** workflow restores `dev` to staging after release-candidate testing.
- Successful production releases use `v<generation>.<YYMMDD>.<daily-sequence>` tags; the initial release is `v1.260720.1`.
- `current` is an atomic symlink to a numbered release.
- `.env` and `storage` are shared between releases.
- Deployer retains five releases.

Because `dev` and release candidates share the staging deployment path, the most recent staging deployment replaces the prior one. Confirm the expected branch and commit before interpreting staging results.

### Failed deployment

1. Read the first failed GitHub Actions/Deployer task.
2. Determine whether publication occurred by checking `current`.
3. Check whether migrations ran before failure.
4. Do not edit a numbered release or manually repoint `current`.
5. Fix the underlying issue and redeploy through the normal pipeline.

If a stale deployment lock remains after proving no deployment process is active, use the configured deployment environment to run:

```bash
vendor/bin/dep deploy:unlock env=production
```

Use `env=dev` for staging. This command must run from an authorized environment with the required `DEPLOY_HOST`, `DEPLOY_USER`, and SSH key; do not place those secrets on the command line.

### Code rollback

Use Deployer rollback from the same authorized context:

```bash
vendor/bin/dep rollback env=production
```

Before rollback:

- identify the target release and commit;
- review migrations introduced after that release;
- confirm the old code can operate against the current database schema;
- take a database backup when rollback risk warrants it.

Rollback changes the code symlink; it does not reverse migrations, database writes, external API calls, queued mail, or Stripe activity. Never pair code rollback with an automatic `migrate:rollback`.

After rollback:

```bash
cd /var/www/html/eac/current
sudo -u www-data /usr/bin/php8.4 artisan queue:restart
sudo supervisorctl status 'eac-laravel-worker:*'
curl --fail --silent --show-error --head https://<production-host>/up
```

Then inspect Sentry, logs, queue failures, schedule, and the affected user flow.

## 11. Disk and resource exhaustion

```bash
df -h
df -i
free -h
uptime
sudo du -xhd1 /var/www/html/eac | sort -h
sudo du -xhd1 /var/www/html/eac-test | sort -h
sudo du -xhd1 /var/log | sort -h
sudo journalctl --disk-usage
```

Check for:

- unexpected Laravel or worker log growth;
- abandoned backup temporary files;
- oversized Apache logs;
- five retained releases in each environment;
- MySQL data or binary-log growth;
- inode exhaustion from many small session/cache files, if storage configuration changes from database.

Do not delete numbered releases, shared storage, database files, or current logs during an incident. Prefer configured application log retention, Deployer release retention, system log rotation, and MySQL-supported maintenance. Record anything removed and its recovery implications.

## 12. External-service incidents

### IONOS object storage

Separate these capabilities when diagnosing credentials:

- public media read/write;
- private media read/write and signed access;
- backup list/read/upload;
- backup deletion for cleanup.

A successful upload does not prove list or delete permission. Test the smallest operation related to the failure and verify the correct production or staging bucket before changing credentials.

### Email and Textmagic

1. Confirm the queue worker is running and the queue is not growing.
2. Inspect the failed job and Laravel log.
3. Check provider delivery/activity logs and sender verification.
4. Confirm transactional and handcrafted mail use the intended mailer configuration.
5. Retry one reviewed failed job only after the provider/configuration issue is fixed.

Do not repeatedly send test mail to real users or bulk-retry notification jobs.

### Stripe

The application receives `payment_intent.succeeded` and `payment_intent.payment_failed` at `/stripe/webhook` and verifies the `Stripe-Signature` with the configured webhook secret.

For a payment incident:

1. Record the order ID, PaymentIntent ID, environment/mode, amount, and timestamps.
2. Check the Stripe Dashboard/Workbench request and webhook delivery logs.
3. Confirm the endpoint used the correct live or test webhook secret.
4. Compare Stripe's PaymentIntent state with the local order/installment state.
5. Inspect the exact webhook response and Laravel exception.
6. Replay one webhook only after reviewing idempotency and current local state.
7. Never manually mark an order paid based only on a browser message or customer screenshot.

Keep test and live keys separate. Never print keys, put them in analytics, or embed secret/restricted keys in client-side code. Prefer a least-privilege restricted API key and an infrastructure IP allowlist when the application's required Stripe operations support it. Rotate a potentially exposed key immediately, review Workbench activity, and update cached configuration through the approved secret process.

### Sentry

Sentry remains the canonical error and performance-debugging system. Keep staging and production distinguishable, set release identifiers, control trace sampling, configure actionable alerts, and filter noise rather than ignoring the issue stream.

Sentry confirms that Laravel reported an exception; it does not by itself prove Apache availability, scheduler freshness, queue freshness, database health, or backup recoverability.

## 13. Common incident playbooks

### HTTP 500

1. Note time, URL, user/action, and active release.
2. Check Sentry and today's Laravel log.
3. Check Apache and FPM logs.
4. Run `artisan about` as `www-data`.
5. Verify MySQL, storage write access, and disk space.
6. Reproduce in staging when possible.
7. Roll back only after checking migration compatibility.

### HTTP 502/503

1. Check whether maintenance mode is active with `artisan about`.
2. Run Apache config test and inspect Apache logs.
3. Detect and inspect the configured FPM service/socket.
4. Check memory, disk, and failed services.
5. Reload only the failing service after evidence is captured.

### Scheduled task missing

1. Confirm `www-data`'s crontab contains both entries.
2. Confirm cron journal entries show `(www-data)` every minute.
3. Run `schedule:list` as `www-data`.
4. Inspect the direct command's logs and prerequisites.
5. Check for a genuine overlapping process before clearing locks.

### Queue/email delayed

1. Check both Supervisor programs.
2. Inspect queue size, failed jobs, Laravel logs, and provider logs.
3. Gracefully run `queue:restart`.
4. Confirm Supervisor starts a replacement.
5. Retry only reviewed jobs.

### Permission denied

1. Identify the failing Unix user and exact path.
2. Resolve symlinks into `shared` with `readlink -f` or `namei`.
3. Inspect owner, mode, ACL, and default ACL.
4. Reproduce with `sudo -u www-data test -w <path>`.
5. Repair only `shared/storage` and `bootstrap/cache` when necessary.
6. Find and stop bare root Artisan usage that could recreate the issue.

### Database unavailable

1. Check MySQL status and journal.
2. Check disk and inode usage.
3. Test Laravel connectivity as `www-data`.
4. Inspect connection limits/locks rather than blindly restarting.
5. Enable maintenance mode before invasive recovery.

### Backup unhealthy

1. Check `backup:list` and `backup:monitor` as `www-data`.
2. Check MySQL, temp storage, log permissions, disk, and IONOS permissions.
3. Create one manual production backup when the cause is fixed.
4. Verify the object and schedule a restore drill.

## 14. Routine maintenance cadence

### Weekly

- Review new/unresolved Sentry issues.
- Check Supervisor status and `queue:failed` in both environments.
- Confirm cron journal entries and `schedule:list` in both environments.
- Check disk space and inodes.
- Confirm production backup age, size, and health.

### Monthly

- Review Ubuntu, Apache, PHP, MySQL, Composer, npm, and application security updates in staging first.
- Review Apache, Laravel, worker, and journal log growth/retention.
- Review Sentry volume, sampling, and alert quality.
- Review Stripe Dashboard users, authentication strength, keys, webhook health, and unrecognized activity.
- Confirm IONOS bucket access and least-privilege credentials.
- Confirm certificate expiration and domain renewal status.

### Quarterly

- Restore a production backup into an isolated scratch database and record results.
- Exercise a staging deployment rollback.
- Review administrator, SSH, GitHub, Sentry, Stripe, Textmagic, IONOS, and database access.
- Rotate credentials whose policy or exposure requires rotation; do not rotate `APP_KEY` routinely.
- Review this runbook against the actual server configuration.

## 15. Possible improvements

Prioritize operational visibility before adding product analytics.

### Highest priority

1. Add an external uptime check for production `/up`, the public landing/login flow, and optionally staging. Alert through a channel that is noticed when no one is watching Sentry.
2. Add scheduler heartbeat monitoring. Sentry Crons can provide missed/failed check-in monitoring, or a dedicated heartbeat service can be used.
3. Add queue backlog and worker-presence alerts. `queue:monitor` can detect queue growth, but worker liveness also needs monitoring through Supervisor/system metrics or a heartbeat.
4. Add host monitoring for disk, inodes, memory, load, Apache, PHP-FPM when used, and MySQL.
5. Perform and document the first encrypted database restore drill.

### Hardening and operational consistency

1. Record the actual Apache PHP handler and, if FPM is used, its service/socket name in the environment inventory.
2. Provision Supervisor and cron through versioned infrastructure/configuration management so manual server drift is detectable.
3. Improve scheduled-task failure reporting to retain the command's original output; the current generic failure callbacks can hide the first exception.
4. Add a non-public readiness check for database, cache, queue freshness, scheduler freshness, and object storage. Keep detailed failure information authenticated rather than exposing it through public `/up`.
5. Test whether a restricted Stripe API key with the minimum required permissions can replace the general secret key, and apply an IP allowlist where practical.
6. Reconcile the known Composer lock warning and update aging GitHub Actions in a normal reviewed change.

### PostHog evaluation

PostHog would complement rather than replace Sentry:

- Sentry answers: “What failed, where, for whom, and in which release/trace?”
- PostHog product analytics answers: “Which workflows are used, where do users abandon them, and what improves activation or retention?”

PostHog is worthwhile if there are defined product questions such as registration completion, student-assignment completion, form completion, enrollment conversion, checkout abandonment, or feature adoption. It is not a substitute for external uptime, scheduler, queue, database, or host monitoring.

Recommended rollout:

1. Keep Sentry as the only error/performance tracker.
2. Start with a separate PostHog staging project and a small allowlist of deliberately named product events.
3. Use stable pseudonymous user IDs; do not send names, email addresses, student details, medical/waiver answers, signatures, payment data, free text, or uploaded-file metadata.
4. Use separate staging and production projects/tokens and disable capture in local/tests.
5. Review privacy disclosures, retention, IP capture, cookie/consent requirements, and data-region needs before production.
6. Leave session replay disabled initially.

This portal contains information about minors, medical waivers, payments, and private documents. If session replay is later justified, treat it as a separate privacy-reviewed implementation: mask all inputs and all text by default, block sensitive components/pages, redact URLs and query strings, disable sensitive network payload capture, exclude payment/authentication screens, and verify the result manually in staging. Use either PostHog or Sentry replay for a defined need rather than deploying overlapping replay systems by default.

## References

- Production activation: `PRODUCTION_ACTIVATION_RUNBOOK.md`
- Release workflow and release notes: `RELEASE_WORKFLOW.md`
- Laravel 13 deployment: <https://laravel.com/docs/13.x/deployment>
- Laravel 13 queues: <https://laravel.com/docs/13.x/queues>
- Laravel 13 scheduler: <https://laravel.com/docs/13.x/scheduling>
- Laravel 13 logging: <https://laravel.com/docs/13.x/logging>
- Sentry Laravel: <https://docs.sentry.io/platforms/php/guides/laravel/>
- Sentry Crons: <https://docs.sentry.io/product/crons/>
- PostHog product analytics: <https://posthog.com/docs/product-analytics>
- PostHog Laravel integration: <https://posthog.com/docs/libraries/laravel>
- PostHog product analytics privacy: <https://posthog.com/docs/product-analytics/privacy>
- PostHog session-replay privacy: <https://posthog.com/docs/session-replay/privacy>
- Stripe webhook security: <https://docs.stripe.com/webhooks#verify-events>
- Stripe key security: <https://docs.stripe.com/keys-best-practices>
- Deployer Laravel recipe: <https://deployer.org/docs/7.x/recipe/laravel>
