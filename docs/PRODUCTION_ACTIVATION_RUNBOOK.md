# EAC Production Activation Runbook

Last reviewed: 2026-07-20
Application: Laravel 13 / Filament 5
Production deployment target: `/var/www/html/eac` from the `master` branch

## Purpose

This runbook describes the infrastructure, credentials, operating processes, and activation sequence required to turn up a production EAC instance. It is based on the application's current code, `.env.example`, GitHub Actions workflows, and `deploy.php`.

For routine maintenance, incident triage, debugging, and recovery after activation, use `APPLICATION_MAINTENANCE_RUNBOOK.md`. For the standard development, staging, production, tagging, and release-note process, use `RELEASE_WORKFLOW.md`.

Do not copy credentials from the test environment. Production must have its own application key, database, Stripe live-mode resources, storage credentials, monitoring environment, and verified mail configuration.

## Launch blockers

Production is not ready to receive traffic until every item below is complete.

- [ ] DNS, TLS, web server, PHP-FPM, and the production virtual host are active.
- [ ] The web root is `/var/www/html/eac/current/public`; it must never be the repository root.
- [ ] `/var/www/html/eac/shared/.env` exists with production-only values before the first deployment.
- [ ] The production database and least-privilege database user exist and are backed up.
- [ ] The deploy user can write the Deployer release tree and grant `www-data` access to Laravel's writable directories.
- [ ] The remote host can install the two private Composer packages, `kyle/filament-mail-manager` and `kyle/filament-theme-builder`.
- [ ] `composer validate` passes without a stale-lock error for the exact release being deployed.
- [ ] A supervised `queue:work` process is running. Email delivery depends on it.
- [ ] Laravel's scheduler runs once per minute on exactly one production host.
- [ ] Production media storage has been selected and both public and private upload paths have been tested.
- [ ] Textmagic production sending credentials and verified sender identities are configured.
- [ ] Stripe live-mode keys are configured and the live webhook endpoint is registered.
- [ ] The one-time production seed has run and the initial administrator password has been changed.
- [ ] Backups, log retention, uptime checks, error monitoring, and restore ownership are assigned.

## 1. Infrastructure to provision

### Application host

Provision a Linux host or equivalent managed runtime with:

- PHP 8.4 CLI and PHP-FPM. Composer currently declares `^8.3`, while CI and the supported application stack use PHP 8.4.
- PHP extensions required by the installed production dependencies: cURL, DOM/XML, Exif, Fileinfo, Intl, OpenSSL, SimpleXML, XMLReader, Zip, and the normal core extensions. Also install the PDO extension for the selected database and GD for the configured media image driver.
- Composer 2, Git, unzip, ACL tools (`setfacl`), Node.js `^20.19` or `>=22.12`, and npm. Vite 8 enforces this Node.js range.
- Nginx, Apache, Caddy, or an equivalent reverse proxy connected to PHP-FPM.
- A process monitor such as Supervisor or systemd for the queue worker.
- Cron, unless the platform supplies a managed scheduler.

The current deploy performs `npm ci && npm run build` on the application host, so Node.js and npm are production-host requirements unless deployment is later changed to upload prebuilt artifacts.

Allow inbound TCP 80/443 and restricted administrative SSH. Allow outbound HTTPS to GitHub/Composer sources, Stripe, Textmagic, Sentry when enabled, and the selected object-storage endpoint.

### Database

Provision a dedicated production database and a least-privilege application user. Laravel supports SQLite, MySQL/MariaDB, and PostgreSQL, but a server database such as MySQL or PostgreSQL is recommended for production concurrency and operational backups.

The database stores application data plus sessions, cache entries, queue jobs, failed jobs, and scheduler overlap locks under the current configuration. Size and back up it accordingly. If Redis is adopted later, change the corresponding Laravel drivers and provision Redis before switching them.

Required database environment variables:

```dotenv
DB_CONNECTION=mysql
DB_HOST=production-db.internal
DB_PORT=3306
DB_DATABASE=eac_production
DB_USERNAME=eac_application
DB_PASSWORD=<secret>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Use `pgsql` and port `5432` instead when PostgreSQL is selected. Install the matching PHP PDO extension.

### Persistent file storage

The application has separate public and private media abstractions:

- Public media includes storefront/course/event/costume imagery and dashboard appearance assets.
- Private media includes user files, staff photos, and private course/event/product attachments.

Two production patterns are supported by the current code:

1. S3-compatible IONOS object storage: set `MEDIA_PUBLIC_DISK=ionos_public` and `MEDIA_PRIVATE_DISK=ionos_private`, ideally using separate public and private buckets and separate least-privilege credentials.
2. Server-local persistent storage: set `MEDIA_PUBLIC_DISK=public` and `MEDIA_PRIVATE_DISK=local`. Deployer shares the entire `storage` directory between releases and creates `public/storage` during deployment. This option requires filesystem backup and prevents simple multi-host scaling.

For IONOS, provision and set all applicable variables:

```dotenv
MEDIA_PUBLIC_DISK=ionos_public
MEDIA_PRIVATE_DISK=ionos_private
IONOS_ENDPOINT=<s3-compatible-endpoint>
IONOS_REGION=us-central-1
IONOS_USE_PATH_STYLE_ENDPOINT=true
IONOS_PUBLIC_ACCESS_KEY_ID=<secret>
IONOS_PUBLIC_SECRET_ACCESS_KEY=<secret>
IONOS_PUBLIC_BUCKET=<public-bucket>
IONOS_PUBLIC_URL=<public-base-url>
IONOS_PRIVATE_ACCESS_KEY_ID=<secret>
IONOS_PRIVATE_SECRET_ACCESS_KEY=<secret>
IONOS_PRIVATE_BUCKET=<private-bucket>
IONOS_PRIVATE_URL=<only-if-required-by-provider>
```

Do not make the private bucket public. Confirm that private downloads use temporary/signed application access and that public URLs render from an anonymous browser session.

The commented `AWS_*` placeholders in `.env.example` do not map to a configured filesystem disk and are not a substitute for the `IONOS_*` values.

### Mail delivery

Transactional and handcrafted application mail are queued and have independent mailer profiles. The current production transport integration is Textmagic.

Provision a Textmagic account/API credential and verified transactional and handcrafted sender identities. Complete any sender-domain validation required by the provider, including SPF, DKIM, and DMARC DNS records. Set:

```dotenv
MAIL_MAILER=transactional
MAIL_TRANSACTIONAL_TRANSPORT=textmagic
MAIL_HANDCRAFTED_TRANSPORT=textmagic
MAIL_FROM_ADDRESS=<production-from-address>
MAIL_FROM_NAME="${APP_NAME}"
MAIL_HANDCRAFTED_ARCHIVE_TO=<optional-archive-address>
MAIL_PAYMENT_PLAN_PAST_DUE_RECIPIENT=<staff-address>
MAIL_PRODUCT_PURCHASE_RECIPIENT=<staff-address>

TEXTMAGIC_USERNAME=<secret>
TEXTMAGIC_API_KEY=<secret>
TEXTMAGIC_TRANSACTIONAL_EMAIL_SENDER_ID=<provider-sender-id>
TEXTMAGIC_TRANSACTIONAL_FROM_NAME=<display-name>
TEXTMAGIC_TRANSACTIONAL_REPLY_TO=<reply-address>
TEXTMAGIC_HANDCRAFTED_EMAIL_SENDER_ID=<provider-sender-id>
TEXTMAGIC_HANDCRAFTED_FROM_NAME=<display-name>
TEXTMAGIC_HANDCRAFTED_REPLY_TO=<reply-address>
```

Set `MAIL_PAYMENT_PLAN_PAST_DUE_RECIPIENT` explicitly so operational notices do not fall back to the code default.

### Stripe

Create or select the production Stripe account and use live-mode credentials. Do not reuse test-mode keys or a test webhook secret.

```dotenv
STRIPE_KEY=<live-publishable-key>
STRIPE_SECRET=<live-secret-key>
STRIPE_WEBHOOK_SECRET=<live-endpoint-signing-secret>
```

After TLS and the domain are active, register this live webhook endpoint in Stripe:

```text
https://<production-host>/stripe/webhook
```

Subscribe it to exactly these events used by the controller:

- `payment_intent.succeeded`
- `payment_intent.payment_failed`

The webhook is responsible for completing paid orders, creating payment plans, marking installments paid or failed, and queueing receipts and related notifications. Confirm that the Stripe endpoint reports HTTP 2xx for a live-mode verification event before accepting payments.

### Monitoring and backups

Sentry is integrated and should have a distinct production project/environment:

```dotenv
SENTRY_LARAVEL_DSN=<production-dsn>
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=<release-or-commit-id>
SENTRY_SAMPLE_RATE=1.0
SENTRY_TRACES_SAMPLE_RATE=<approved-rate-or-null>
```

Monitor `GET /up` for process-level availability. The current health route proves Laravel can boot, but it does not explicitly check database, cache, queue freshness, scheduler freshness, Stripe, Textmagic, or object storage. Add separate operational alerts for those dependencies.

Back up:

- The production database, with retention and periodic restore tests.
- `/var/www/html/eac/shared/storage` when any local media disk is used.
- Object-storage buckets according to the provider's versioning/retention capabilities.
- The production `.env` through the approved secret-management process, not source control.

Deployer keeps five code releases, but rollback only changes the code symlink. It does not reverse database migrations. Take a database backup before migrations with destructive or non-backward-compatible changes.

## 2. Production environment

Create `/var/www/html/eac/shared/.env` before the first deploy. It is shared between releases and is intentionally not committed.

At minimum, review these core values:

```dotenv
APP_NAME="EAC Plié Portal"
APP_ENV=production
APP_KEY=<unique-generated-key>
APP_DEBUG=false
APP_URL=https://<production-host>
APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=America/New_York
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
SESSION_SECURE_COOKIE=true

LOG_CHANNEL=daily
LOG_DAILY_DAYS=7
LOG_LEVEL=info
DEBUGBAR_ENABLED=false

SEED_DEMO_DATA=false
ENROLLMENT_UNASSIGN_CUTOFF_DAYS=7
```

Generate a new key without copying another environment's key. One safe method is to run `php artisan key:generate --show` from a matching release and place the result in the production secret store/`.env`. Back up this value securely and do not rotate it as a routine deployment step; Laravel uses it for encrypted application data, including authentication-related secrets.

Do not leave `APP_DISPLAY_TIMEZONE` blank, because a blank environment value overrides the application's default. The schedules themselves are explicitly set to `America/New_York`; confirm this is the intended EAC business timezone.

Use a temporary, strong value for the initial administrator seed:

```dotenv
DEFAULT_USER_FIRST_NAME=<first-name>
DEFAULT_USER_LAST_NAME=<last-name>
DEFAULT_USER_EMAIL=<production-admin-email>
DEFAULT_USER_PASSWORD=<one-time-strong-secret>
```

Change that password immediately after first login. The default-user variables may then be removed from production configuration; do not run the full production seeder a second time without reviewing it because parts of `DatabaseSeeder` are not idempotent.

## 3. Web server and PHP settings

Configure the production site with:

- Document root `/var/www/html/eac/current/public`.
- TLS certificate, HTTP-to-HTTPS redirect, and the canonical hostname used by `APP_URL`.
- PHP-FPM execution only through `public/index.php`; deny access to `.env`, dotfiles, source files, `storage`, and `vendor`.
- `client_max_body_size`/equivalent above 250 MB.
- PHP `upload_max_filesize` at least 256 MB and `post_max_size` above that value. The application permits ordinary uploads up to 20 MB and video uploads up to 250 MB.
- PHP memory and request timeouts sized for large uploads; enable OPcache.
- Write access for `www-data` to shared `storage` and the active release's `bootstrap/cache`.

If TLS terminates at a load balancer or proxy, verify Laravel sees requests as HTTPS and generates HTTPS links. The application does not currently declare trusted proxy addresses and does not force the HTTPS scheme in code. Resolve that application/proxy configuration before launch rather than relying only on forwarded headers.

## 4. Deployment access and CI/CD

The production workflow runs on pushes to `master` and selects the `production` GitHub Environment. Configure these GitHub Environment secrets:

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `PRIVATE_KEY`
- `THEMES_TOKEN`, a scoped GitHub token that can read both private `kyle/*` Composer repositories

`DEPLOY_PASSWORD` is passed through the current workflow but the deployment action is configured with an SSH private key. Do not depend on password authentication unless the workflow is deliberately changed.

The deploy user needs:

- SSH access to the production host.
- Write access under `/var/www/html/eac`.
- Git access to the EAC repository used by Deployer.
- The ability to use ACLs so `www-data` can write Laravel runtime directories.

The workflow configures Composer GitHub authentication on the GitHub runner, but `deploy:vendors` runs Composer again on the remote host. Provision remote Composer authentication for the private package downloads, for example through a host-level `COMPOSER_AUTH` secret or a deploy-user Composer auth configuration. Never commit `auth.json` or print its token in deployment logs.

The existing Deployer recipe performs:

1. Checkout of `master` into a new release.
2. Production Composer install with `--no-dev` and optimized autoloading.
3. `npm ci` and the Vite production build.
4. `storage:link`.
5. Configuration, route, view, and event caches.
6. `php artisan migrate --force`.
7. Atomic publication of the release symlink.
8. `php artisan queue:restart` after publication.
9. Retention of five releases.

It does not create the database, create `.env`, seed baseline data, register Stripe webhooks, configure the web server, install cron, or install a queue process monitor.

## 5. Long-running and recurring processes

### Queue worker

All managed, welcome, password-reset, verification, receipt, reminder, gift-card, installment, and handcrafted mail is queued. Without a worker, mail accumulates in the `jobs` table.

Example Supervisor program:

```ini
[program:eac-laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.4 /var/www/html/eac/current/artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600
directory=/var/www/html/eac/current
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=130
redirect_stderr=true
stdout_logfile=/var/www/html/eac/shared/storage/logs/queue-worker.log
```

Confirm the PHP binary path and user for the target host. The deployment's `queue:restart` hook assumes Supervisor/systemd will automatically start a replacement worker when the old process exits.

### Scheduler

Install the scheduler in `www-data`'s personal crontab on exactly one production host:

```bash
sudo crontab -u www-data -e
```

Add this entry. A personal crontab does not include a username column:

```cron
* * * * * cd /var/www/html/eac/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1
```

The alternative system-crontab format used in `/etc/crontab` or `/etc/cron.d/*` does include `www-data` between the schedule and command. Do not paste a system-crontab entry into a personal crontab.

The current schedule includes:

- 12:01 a.m. Eastern: process payment-plan installments.
- 12:01 a.m. Eastern: cancel orders abandoned for more than 24 hours.
- 3:10 a.m. Eastern: remove database backups outside the retention policy.
- 3:40 a.m. Eastern: create an encrypted production database backup.
- 6:10 a.m. Eastern: monitor production backup age and retained size.
- 8:00 a.m. Eastern: notify staff about newly past-due installments.
- 8:00 a.m. Eastern: send two-week event reminders.
- 8:00 a.m. Eastern: remind users about unassigned open enrollments.
- 8:00 a.m. Eastern: send abandoned-cart reminders.

Run `sudo -u www-data /usr/bin/php8.4 artisan schedule:list` after deployment and confirm the displayed UTC execution times correspond to Eastern time, including daylight-saving behavior.

## 6. First activation sequence

1. Provision DNS, TLS, host packages, PHP-FPM, web server, database, storage, mail, Stripe, Sentry, backups, cron, and Supervisor/systemd.
2. Create `/var/www/html/eac/shared/.env` with all production values. Ensure `APP_ENV=production`, `APP_DEBUG=false`, and `SEED_DEMO_DATA=false`.
3. Configure GitHub's `production` Environment and required secrets. Apply branch/environment approval protections if desired.
4. Confirm remote access to the EAC repository and both private Composer packages.
5. Trigger the first production deployment by merging/pushing the reviewed release to `master`, or run the equivalent Deployer production target through the approved release process.
6. Confirm the deployment completed its migrations, caches, frontend build, storage link, and `current` symlink publication.
7. Run the baseline seed exactly once from the current release:

   ```bash
   cd /var/www/html/eac/current
   php artisan db:seed --force
   ```

   This creates baseline permissions/roles, system calendars, required legal-document records, an initial waiver form, and the initial super administrator. It does not seed demo data in production when `SEED_DEMO_DATA=false`.

8. Run `php artisan permissions:sync --dry-run`; the expected result immediately after seeding is no missing catalog permissions. Resolve discrepancies before staff use.
9. Start/reload the queue monitor and cron. Verify the worker remains running after `php artisan queue:restart`.
10. Register the Stripe live webhook, copy its live signing secret into production configuration, redeploy/re-cache configuration, and deliver test events.
11. Log in as the initial administrator, change the one-time password, configure staff access, and publish/review production legal documents and editable managed-mail content.
12. Perform the smoke tests below before switching public DNS or announcing availability.

## 7. Go-live smoke tests

Run these without displaying secrets:

```bash
cd /var/www/html/eac/current
php artisan about --only=environment,drivers
php artisan migrate:status
php artisan schedule:list
php artisan permissions:sync --dry-run
php artisan queue:failed
```

Expected configuration includes production environment, debug disabled, the chosen server database, database/Redis-backed queue and cache, and a non-log production mail path.

Validate from outside the host:

- [ ] `https://<production-host>/up` returns HTTP 200.
- [ ] HTTP redirects to the canonical HTTPS hostname.
- [ ] `/dancefam` loads and a new user can register, log in, log out, and reset a password.
- [ ] `/admin` rejects unauthorized users and accepts the production administrator.
- [ ] A queued test email leaves the `jobs` table and arrives with correct links, sender, reply-to, Unicode, and formatting.
- [ ] A public image upload renders in an anonymous browser.
- [ ] A private upload is accessible only through an authorized application path and not by a permanent public URL.
- [ ] A low-value live Stripe purchase succeeds end to end; the order completes only once, the receipt is delivered, and the Stripe webhook reports HTTP 2xx.
- [ ] A Stripe failed-payment test reaches the endpoint and records the expected failure path.
- [ ] `queue:failed` remains empty after the tests.
- [ ] Sentry receives a controlled non-sensitive test event tagged `production` and with the correct release.
- [ ] Database and media backup jobs have completed at least once, and the restore procedure has an owner.

## 8. Routine deployment and rollback

For each release:

1. Review migrations for backward compatibility and take a pre-deploy database backup when appropriate.
2. Merge the approved `release/*` candidate into `master`. A direct `dev` batch is an exception requiring explicit approval of every included change.
3. Monitor the GitHub production deployment through migration, publication, and queue restart.
4. Check `/up`, Sentry, web server/PHP logs, queue failures, scheduler output, and critical user flows.
5. Tag the successfully deployed commit using `v<generation>.<YYMMDD>.<daily-sequence>` and publish its GitHub Release. The initial production release is `v1.260720.1`.

If code rollback is required, use the Deployer rollback procedure to move the `current` symlink to a retained release. Assess database compatibility first: code rollback does not roll back migrations. Never run `migrate:rollback` automatically during an incident without reviewing the exact migration and data impact.

## 9. Known configuration gaps to resolve or consciously accept

These findings do not all block a single-server launch, but they should be tracked:
- The deployment restarts queue workers but does not provision or health-check them.
- The scheduler is required but is not provisioned or health-checked by deployment automation.
- `/up` checks application boot only; it is not a full dependency/readiness check.
- Trusted proxy handling/HTTPS generation must be verified if TLS terminates before the application host.
- Remote Composer authentication for private packages is an external host prerequisite and is not installed by the workflow.
- `composer validate` currently reports that `composer.lock` is not up to date with `composer.json`; reconcile and review the resulting lock-file change before the production release. It also warns that both private `kyle/*` requirements use unbounded `*` constraints, although the lock file pins the deployed commits.
- The production seed is a manual, one-time step and the full seeder is not safe to treat as a repeatable deployment task.
- GitHub Actions uses `actions/checkout@v3`; schedule a normal CI maintenance update independently of production activation.

## References

- EAC application maintenance and debugging: `APPLICATION_MAINTENANCE_RUNBOOK.md`
- Release workflow and release notes: `RELEASE_WORKFLOW.md`
- Laravel 13 deployment: <https://laravel.com/docs/13.x/deployment>
- Laravel 13 queues and process monitoring: <https://laravel.com/docs/13.x/queues>
- Laravel 13 filesystem: <https://laravel.com/docs/13.x/filesystem>
- Deployer Laravel recipe: <https://deployer.org/docs/7.x/recipe/laravel>
- Stripe webhook setup: <https://docs.stripe.com/webhooks>
