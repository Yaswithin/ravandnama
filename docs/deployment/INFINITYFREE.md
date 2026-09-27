# InfinityFree shared Apache deployment

This guide is for InfinityFree/shared Apache hosting with PHP 8.3, `.htaccess`
support, `mod_rewrite`, and the subdomain document root set to `htdocs/`. It
does not replace the existing [Linux VPS/Nginx deployment guide](DEPLOYMENT.md)
or its Nginx configuration example.

## Upload layout

Keep the existing public entry point at `public/index.php`. Upload only the
runtime files needed by that entry point:

- `.htaccess` at the `htdocs/` root
- `app/`, `config/`, `routes/`, and `public/`
- `storage/sessions/` (create it if needed)
- `vendor/` with the locally generated Composer autoloader
- a private `.env` file at the `htdocs/` root

Do not upload `.git/`, `docs/`, `tests/`, `database/`, `.env.example`, or
Composer's `composer.json` and `composer.lock`. The app loads its environment
from the project root and its front controller loads `vendor/autoload.php`.
Create/select the hosting database separately in cPanel/phpMyAdmin. The
deployment-oriented schema import is described below; no database is created
by this repository or guide.

Prepare Composer files locally, where Composer is available:

```sh
composer install --no-dev --optimize-autoloader --no-interaction
```

Upload the resulting `vendor/` directory. The host does not need Composer or a
CLI deployment workflow. Do not upload the project `.env.example` as `.env`;
create `.env` privately with the database settings supplied for the hosting
account. Never put real credentials in the repository.

## Apache routing and access

The root `.htaccess` is intended to live in `htdocs/`. It denies dotfiles,
private project directories, Composer metadata, and direct `/public/...`
requests. It maps the app's `/assets/` CSS, JavaScript, PNG, and SVG URLs to
`public/assets/`, routes `/` and `/api/...` to `public/index.php`, and rejects
other paths. The `[END]` flag stops per-directory rewrite processing after an
internal rewrite, avoiding a rewrite loop. Directory URLs are not served as
public content, so this allowlist also prevents directory browsing; no server
configuration outside `.htaccess` is required by the rules.

The rules require Apache 2.4 or later with `mod_rewrite` and permission for
these rewrite rules in `.htaccess`. Hash routes such as `/#/tasks` are sent by
the browser as a request for `/`; the fragment is handled by the existing
frontend router and needs no Apache route rule. Keep the private application
directories outside the set of URLs served by the allowlist. Do not expose
`.env`, source directories, session files, logs, or Composer metadata.

## Runtime requirements

- Select PHP 8.3 in the hosting control panel and enable PDO with `pdo_mysql`,
  sessions, JSON, and the standard password hashing support used by PHP.
- Create the root `.env` with the hosting database host, port, database name,
  username, and password. Keep it private and never paste secrets into logs or
  documentation.
- Ensure `storage/sessions/` exists and is writable by PHP. The application
  stores server-side sessions there.
- Enable HTTPS before accepting logins. The app marks session cookies Secure
  when PHP sees HTTPS (or port 443); verify the host passes that state through.
- Database creation and schema setup are a separate phpMyAdmin stage. Do not
  assume the database exists until that stage has been completed.

## phpMyAdmin schema import

Before importing, create/select a new empty database and confirm its server
product/version and support for `utf8mb4_0900_ai_ci` in phpMyAdmin (for example,
run `SELECT VERSION();` and `SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci';`). The
current migrations explicitly use that collation. The checked-in
[`database-schema.sql`](database-schema.sql) is a complete fresh-schema import
rendered in migration filename order, including the `migrations` tracking
table and the five applied migration names. It contains no users or other
application data. Import it once into the selected empty database; do not use
it to upgrade an existing database or retry after a partial import. Future
schema changes must be represented in the PHP migrations and this import file
must be regenerated from those migration statements.

The import uses ordinary `CREATE TABLE`, `ALTER TABLE`, and `INSERT` statements;
it does not require `DELIMITER`, routines, triggers, or a CLI. DDL can commit
statement-by-statement, so a failed/partial import must be inspected before any
retry. The generated file preserves `utf8mb4_0900_ai_ci` exactly and must not be
imported on a server that does not recognize it.

MySQL documents this collation as available from MySQL 8.0; MySQL 5.7 does not
recognize it. MariaDB documents support for the MySQL 8.0 UCA 9 collation names
starting with MariaDB 11.4.5, but collation mappings/behavior can differ. Do
not assume that a MariaDB compatibility alias has identical sorting or
comparison results; confirm the hosting version and compare semantics before
using the import there. The exact InfinityFree database product/version is
currently unknown.

## Post-upload checks

After uploading and configuring the database separately, check these URLs over
HTTPS:

- `/` — application shell
- `/api/health` — API health response
- `/api/health/db` — database connectivity response
- `/assets/css/base.css` — public static asset
- `/composer.json`, `/.env`, `/config/`, `/storage/sessions/`, and `/public/`
  — must not disclose file contents (they should be denied)

Then register/login and confirm authenticated requests work and the session
cookie has `Secure`, `HttpOnly`, and `SameSite=Lax`. Apache/InfinityFree runtime
behavior must be confirmed on the hosting account; these local deployment
rules have not been exercised by an Apache server.

## Replacing the temporary page

During the later deployment cutover, back up the current `htdocs/.htaccess`,
`htdocs/index.php`, `htdocs/image.php`, and `htdocs/assets/` first. Those files
belong to the temporary Coming Soon page. Replace them only when the app files,
private `.env`, writable session directory, and separately prepared database
are ready. Upload into `htdocs/`, not the subdomain's parent directory, and
install this repository's root `.htaccess` as `htdocs/.htaccess`.
