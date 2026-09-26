# Ravandnama (روندنما)

Ravandnama is an open-source personal life management application.

## Requirements

- PHP 8.4 or newer
- Composer
- MySQL 8 or newer (required for database-backed endpoints)

## Local setup

```bash
composer install
```

Copy `.env.example` to `.env` and set the local database values. Then start the
development server:

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000> for the welcome page. The API health endpoints are
`GET /api/health` and `GET /api/health/db`.

## Backend layout

- `public/index.php` is the front controller and starts the application.
- `app/Core/Request.php` and `Response.php` represent incoming requests and
  outgoing responses.
- `app/Core/Router.php` maps HTTP methods and paths to callback or controller
  handlers.
- `app/Core/Database.php` creates and reuses a PDO MySQL connection with
  `utf8mb4`, exception mode, and native prepared statements.
- `app/Core/Environment.php` loads simple `KEY=value` entries from the local
  `.env` file without an additional package.
- `app/Core/Application.php` bootstraps configuration and routes while keeping
  the existing welcome page available outside `/api`.
- `routes/api.php` declares the API health routes; `config/database.php` reads
  connection settings from environment variables.

`.env` is local-only and ignored by Git. Do not commit real credentials.

## MySQL database

Create a MySQL 8 database if it does not already exist:

```sql
CREATE DATABASE IF NOT EXISTS ravandnama
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;
```

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in
`.env`. The committed `.env.example` contains local placeholders; copy it to
`.env` and replace values as needed.

Run pending migrations from the project root:

```bash
php database/migrate.php
```

Migration files live in `database/migrations/` and are applied in filename
order. The `migrations` table records each completed migration so the command
skips it on later runs. Each migration returns a list of readable SQL
statements; schema migrations should use idempotent SQL because MySQL DDL may
commit independently of the migration record.

The initial `users` table contains an auto-incrementing unsigned `id`,
`name`, unique `email`, `password_hash`, and microsecond `created_at` and
`updated_at` timestamps. Password hashing belongs in the registration flow;
the database schema stores only the resulting hash, never a plaintext password.

## Register API

Create a user with `POST /api/auth/register`. Send a JSON object with the
`Content-Type: application/json` header:

```http
POST /api/auth/register
Content-Type: application/json
```

```json
{
  "name": "Yasin",
  "email": "yasin@example.com",
  "password": "example-password"
}
```

A successful request returns `201 Created` and only public user fields:

```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "user": {
      "id": 1,
      "name": "Yasin",
      "email": "yasin@example.com"
    }
  }
}
```

Invalid JSON or a non-JSON Content-Type returns `400`; validation failures
return `422`; an email already in use returns `409`. Passwords are hashed with
PHP's `password_hash()` and are never included in API responses.

## Session authentication

Authentication uses a server-side PHP session. After a successful login, the
server regenerates the session ID and stores the authenticated user ID in the
session. Session data is stored in the private `storage/sessions/` directory,
outside the public document root. The browser keeps only the session cookie;
user passwords and hashes are never placed in that cookie or returned by these
endpoints. Cookies use `HttpOnly` and `SameSite=Lax`; `Secure` is enabled when
the request uses HTTPS.

- `POST /api/auth/login` accepts JSON containing `email` and `password` and
  returns the public user fields on success.
- `GET /api/auth/me` returns the current public user or `401` if unauthenticated.
- `POST /api/auth/logout` clears the server session and expires its cookie.

Send the session cookie received from login with later requests. State-changing
authentication requests also require the CSRF token described below.

## CSRF protection

Session cookies are sent automatically by browsers, so a malicious site could
otherwise cause a browser to submit an authenticated request. Ravandnama uses
the synchronizer token pattern: a cryptographically random token is stored in
the server-side session and sent back in the `X-CSRF-Token` header. The token
is never placed in the URL, cookie, database, or logs.

1. Call `GET /api/auth/csrf` and retain both the session cookie and returned
   `data.csrf_token`.
2. Send `POST /api/auth/register` or `POST /api/auth/login` with that session
   cookie, the `X-CSRF-Token` header, and the JSON body.
3. Call `GET /api/auth/me` with the session cookie. GET requests do not require
   a CSRF token because they must not change state.
4. Send `POST /api/auth/logout` with the same session cookie and CSRF header.

Register, Login, and Logout are CSRF-protected. The shared `Csrf::protect()`
route wrapper is method-independent and can also wrap future POST, PUT, PATCH,
and DELETE handlers. Missing or invalid tokens return `403` with a generic
message. The token remains in session when Login regenerates the session ID,
because the session data is preserved; Logout destroys the session and token.
`SameSite=Lax` is useful defense in depth, but does not replace CSRF validation.

## Auth regression checks

With `.env` configured and the database migrations applied, run the repeatable
Auth regression suite from the project root:

```bash
php tests/run-auth-regression.php
```

The suite makes one temporary user with a unique email and removes it and its
test session when finished. It checks Register, Login, `/me`, Logout, CSRF,
password hashing, and session security settings without adding a test library.
