# Production deployment (Linux VPS)

This guide describes the current Ravandnama architecture: a PHP application
served by `public/index.php`, static files in `public/assets/`, server-side PHP
sessions, and MySQL. It assumes Linux, Nginx, PHP-FPM 8.3+, Composer, and MySQL
8+. It does not require Docker or a separate frontend build.

## Prerequisites

- Linux with Nginx and PHP-FPM 8.3 or later.
- PHP extensions: PDO, `pdo_mysql`, and sessions. JSON and password hashing
  support are also required by the runtime. Verify enabled modules with
  `php -m` for CLI and the PHP-FPM service separately.
- Composer, for installing dependencies and generating `vendor/autoload.php`.
- MySQL 8+ with InnoDB and `utf8mb4_unicode_ci` support.
- A TLS certificate and private key for the deployed hostname.
- A release directory whose web root is only its `public/` subdirectory.

Composer currently declares PHP `>=8.3` and no third-party runtime packages.
The application requires `pdo_mysql` at runtime even though it is not currently
declared as a Composer platform requirement.

## Application environment variables

These are the application variables currently read at runtime:

| Variable | Purpose | Default/limit |
| --- | --- | --- |
| `DB_HOST` | MySQL hostname | No usable default; required |
| `DB_PORT` | MySQL port | No usable default; required |
| `DB_DATABASE` | Application database name | No usable default; required |
| `DB_USERNAME` | Database account | No usable default; required |
| `DB_PASSWORD` | Database account password | Empty if omitted; set a secret in production |
| `API_MAX_BODY_BYTES` | Maximum request body accepted by the API | 1,048,576 bytes by default; valid configured range is 1 through 2,097,152 bytes |

The app loads a simple `KEY=value` file from the project root at `.env`, but
existing process environment values take precedence. Keep `.env` untracked and
private; alternatively inject the values through the PHP-FPM/service environment.
Do not commit production credentials. `.env.example` is a local placeholder
file, not a production configuration to use unchanged.

`APP_NAME`, `APP_ENV`, `APP_DEBUG`, and `APP_URL` appear in `.env.example` but
are not consumed by the current application configuration. In particular,
`APP_DEBUG=false` does not control PHP error display.

PHP and Nginx settings are separate from the application variables above. The
production PHP configuration must set `display_errors=Off`, `log_errors=On`,
and a protected `error_log` destination. Keep the Nginx `client_max_body_size`
and any PHP/FPM request limits compatible with `API_MAX_BODY_BYTES`; the
provided Nginx template allows 2 MiB, the application's maximum configurable
limit. The application does not accept file uploads, so upload-size settings
are not currently relevant. Do not raise `post_max_size`/`upload_max_filesize`
for a feature the app does not have.

## Initial deployment

1. Clone the repository into a release directory. Keep the repository root
   outside the web root; configure Nginx `root` to that release's `public/`
   directory only.
2. Install the Composer autoloader from the repository root:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

3. Create a MySQL 8 database with `utf8mb4` and `utf8mb4_unicode_ci`. Supply
   `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to the
   migration command and PHP-FPM. The migration account needs schema DDL and
   migration-table read/write privileges. The running app currently uses the
   same configured database connection, so use a restricted application
   account and grant migration privileges only to the deployment command where
   operationally possible.
4. Ensure `storage/sessions/` exists and is writable by the PHP-FPM account.
   Do not make the whole project writable by the web process. The app does not
   currently write to `storage/logs/` or `storage/cache/`; errors use PHP's
   `error_log()` instead.
5. Copy and adapt
   [`nginx/ravandnama.conf.example`](nginx/ravandnama.conf.example). Replace
   `server_name _;`, certificate paths, project release path, and PHP-FPM
   socket/upstream with server values. Validate and reload Nginx after the
   certificate and PHP-FPM service are available.
6. Configure PHP-FPM production settings as described below. Make sure the
   HTTPS request reaches PHP as HTTPS. When a trusted reverse proxy terminates
   TLS, configure the proxy/web-server-to-FPM path to set HTTPS based only on
   the trusted proxy connection; do not trust a client-supplied forwarding
   header directly.
7. Enable HTTPS before accepting logins. The Nginx template redirects HTTP to
   HTTPS and passes its `$https` state to PHP-FPM. `app/Core/Session.php` sets
   session cookie `Secure` only when PHP sees `HTTPS` as on (or server port
   443); it also sets `HttpOnly` and `SameSite=Lax` and restricts sessions to
   cookies.
8. Run the migrations as described in [Database operations](#database-operations).
9. Verify the HTTP response, static assets, health endpoints, login, and
   session cookie behavior over HTTPS as described in [Verification](#verification).

### PHP-FPM production settings

Use the server's PHP configuration or FPM pool configuration; do not change a
developer workstation configuration as part of deployment. At minimum:

```ini
display_errors = Off
log_errors = On
error_log = /var/log/php/ravandnama-error.log  ; replace with protected server path
post_max_size = 3M
```

Ensure the PHP-FPM account can append to the selected error log, while log
readers are restricted to authorized operators. `post_max_size` is shown above
the app's 2 MiB maximum to avoid an upstream limit below the accepted JSON body
size. The app does not upload files, so `upload_max_filesize` is not currently
part of its request contract. Session settings relevant to authentication are
set by `app/Core/Session.php`; HTTPS visibility still depends on correct
web-server/FPM configuration.

## Database operations

### Migrations

Take a verified database backup before schema changes. From the repository
root, run the migration runner with the production migration credentials
available in the command's environment or its private `.env`:

```sh
php database/migrate.php
```

The runner applies files in filename order and records completed migrations in
the `migrations` table. Review its output, inspect the table for the expected
migration names, and verify `GET /api/health/db` afterward. MySQL DDL may commit
independently from the migration record; review migration output and the schema
before retrying a failed migration.

### Backup

Use a protected option file or another secret-safe mechanism for MySQL
credentials; do not put passwords directly in shell history or command-line
arguments. Example template (replace placeholders, including the destination,
and protect the resulting backup):

```sh
mysqldump --defaults-extra-file=/secure/path/mysql-backup.cnf \
  --host=DB_HOST --port=DB_PORT --single-transaction --routines --triggers \
  DB_DATABASE > /secure/backups/ravandnama-YYYYMMDD-HHMMSS.sql
```

For an InnoDB-only schema, `--single-transaction` provides a consistent
transactional dump without locking tables. Restrict backup access and retention,
store copies away from the application server, and periodically test restores.

### Restore and rollback

Restore only to the intended database after verifying the target name and
backup. Stop or place the application in maintenance mode, preserve a copy of
the current database if possible, restore with a protected MySQL option file,
then verify the schema, migration records, and application health before
reopening traffic. A restore overwrites database contents in its target.

Command template (replace every placeholder and verify the target database
before running):

```sh
mysql --defaults-extra-file=/secure/path/mysql-restore.cnf \
  --host=DB_HOST --port=DB_PORT DB_DATABASE \
  < /secure/backups/ravandnama-YYYYMMDD-HHMMSS.sql
```

The current project has **no down migrations**. For a schema rollback, restore
a verified pre-migration database backup and coordinate the application
revision with that schema. For an application-only rollback, deploy the
previous known-good application revision. Do not assume migrations can be
automatically reversed.

## Logging and health checks

- PHP application/API and migration failures use PHP `error_log()`. Configure
  PHP-FPM to write to a protected log destination and rotate/retain it.
- Nginx access and error logs should be enabled, writable by the Nginx service,
  and rotated with bounded retention appropriate to the server.
- Restrict log and backup readers. Never log or copy session IDs, CSRF tokens,
  passwords, or production credentials into tickets or shell history.
- `GET /api/health` confirms that the API route is reachable; it does not check
  the database.
- `GET /api/health/db` performs `SELECT 1` and returns success or an unavailable
  response. Use it for database connectivity checks. Avoid high-frequency
  polling beyond operational need.

## Verification

Run these checks against the deployed HTTPS hostname. Replace the placeholder
host and asset path with a real static asset from `public/assets/`.

```sh
curl --fail --show-error --head https://YOUR_HOSTNAME/
curl --fail --show-error https://YOUR_HOSTNAME/api/health
curl --fail --show-error https://YOUR_HOSTNAME/api/health/db
curl --fail --show-error --head https://YOUR_HOSTNAME/assets/css/base.css
```

Then perform a real login in the browser and confirm that the session cookie
has `Secure`, `HttpOnly`, and `SameSite=Lax`, that authenticated requests remain
authenticated, and that logout invalidates the session. Do not paste cookie
values into logs or reports. Confirm HTTP requests redirect to HTTPS and review
Nginx/PHP-FPM logs for request failures.
