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
The database schema is handled separately through phpMyAdmin; no database
creation or migration is part of this guide.

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
