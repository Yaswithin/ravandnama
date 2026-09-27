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

For Linux VPS production deployment with Nginx, PHP-FPM, and MySQL, see
[`docs/deployment/DEPLOYMENT.md`](docs/deployment/DEPLOYMENT.md).

The API rejects request bodies larger than `API_MAX_BODY_BYTES` with `413`.
The default is 1,048,576 bytes (1 MiB); configure a positive value up to 2 MiB
in `.env` if a deployment needs a different limit. The default accommodates
Notes content up to 50,000 Unicode characters, including JSON escaping for
supplementary Unicode characters, while bounding how much request data PHP reads
and decodes.

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

## Tasks API

All task endpoints require an authenticated session. Send the session cookie
from Login; `POST`, `PUT`, and `DELETE` also require the session's
`X-CSRF-Token` obtained from `GET /api/auth/csrf`. GET requests do not require a
CSRF token.

| Method | Endpoint | Result |
| --- | --- | --- |
| `GET` | `/api/tasks` | List the authenticated user's tasks |
| `POST` | `/api/tasks` | Create a pending task (`201`) |
| `GET` | `/api/tasks/{id}` | Get one owned task |
| `PUT` | `/api/tasks/{id}` | Partially update supplied fields |
| `DELETE` | `/api/tasks/{id}` | Delete one owned task |

Create a task with JSON:

```json
{
  "title": "Study English",
  "description": "Practice for 30 minutes",
  "due_at": "2026-09-27 18:00:00",
  "project_id": 3
}
```

`title` is required and limited to 200 characters. `description` is optional,
trimmed, and limited to 10,000 characters. `due_at` is optional and accepts
`Y-m-d H:i:s` (for example `2026-09-27 18:00:00`) or `null`. It has no timezone
offset and is interpreted in the PHP application's configured local timezone.
New tasks start as `pending`. Send `status: "completed"` or
`status: "pending"` in a `PUT` request to change completion state; clients
cannot set `completed_at` directly. Completion timestamps are managed by the
service. `PUT` is a partial update: omitted fields retain their current values;
send `null` to clear `description`, `due_at`, or `project_id`. `project_id` is
optional and may be `null`; a positive integer assigns the Task to a Project
owned by the authenticated user. Inaccessible or nonexistent Projects return
the same `404` response. Omitting `project_id` when creating a Task leaves it
unassigned.

For example, to complete a task:

```json
{
  "status": "completed"
}
```

Successful responses use the standard `{ "success": true, "data": ... }`
envelope. Task representations include `id`, `project_id`, `title`,
`description`, `status`, `due_at`, `completed_at`, `created_at`, and `updated_at`; the internal
`user_id` is not returned. Lists contain only the current user's tasks. Every
single-task lookup, update, and delete scopes by both task ID and authenticated
user ID; nonexistent and other users' tasks both return `404`.

Create returns `201` with the created task:

```json
{
  "success": true,
  "data": {
    "task": {
      "id": 1,
      "project_id": null,
      "title": "Study English",
      "description": "Practice for 30 minutes",
      "status": "pending",
      "due_at": "2026-09-27 18:00:00.000000",
      "completed_at": null,
      "created_at": "2026-09-26 12:00:00.000000",
      "updated_at": "2026-09-26 12:00:00.000000"
    }
  }
}
```

`GET /api/tasks` returns the same representation in `data.tasks`, an array.

Validation errors return `422`, unauthenticated requests `401`, and missing or
invalid CSRF tokens `403`. Invalid JSON or a non-JSON Content-Type returns
`400`. Internal errors return a generic `500` response.

## Projects API

Projects are independent resources and belong to the authenticated user. Every
endpoint requires the session cookie from Login. `POST`, `PUT`, and `DELETE`
also require the session's `X-CSRF-Token` from `GET /api/auth/csrf`; GET
requests do not require a CSRF token.

| Method | Endpoint | Result |
| --- | --- | --- |
| `GET` | `/api/projects` | List the authenticated user's projects |
| `POST` | `/api/projects` | Create a project (`201`) |
| `GET` | `/api/projects/{id}` | Get one owned project |
| `PUT` | `/api/projects/{id}` | Partially update supplied fields |
| `DELETE` | `/api/projects/{id}` | Delete one owned project |

Create a project with JSON:

```json
{
  "name": "Ravandnama",
  "description": "Personal Life OS project"
}
```

`name` is required, trimmed, and limited to 200 characters. `description` is
optional, trimmed, and limited to 10,000 characters; `null` or an empty string
clears it. Unknown fields such as `user_id` are rejected with `422`; ownership
always comes from the server-side authenticated session. `PUT` is a partial
update: omitted fields retain their current values.

A successful create returns `201` with the public Project representation:

```json
{
  "success": true,
  "data": {
    "project": {
      "id": 1,
      "name": "Ravandnama",
      "description": "Personal Life OS project",
      "created_at": "2026-09-26 12:00:00.000000",
      "updated_at": "2026-09-26 12:00:00.000000"
    }
  }
}
```

`GET /api/projects` returns the same representation in `data.projects`. The
public representation excludes `user_id`. Lookups, updates, and deletions
scope by both Project ID and authenticated user ID; a missing Project and
another user's Project both return `404`. Other successful reads and updates
return `200`; deletes return `200`. Unauthenticated requests return `401`,
invalid CSRF tokens `403`, validation errors `422`, and unexpected errors a
generic `500` response.

The Task-to-Project relationship is optional. Deleting a Project does not
delete its Tasks; MySQL sets their `project_id` to `NULL`.

```text
User
 ├── Projects
 └── Tasks

Project
 └── Tasks (optional)
```

Run the Projects regression suite after applying migrations:

```bash
php tests/run-project-regression.php
```

## Notes API

Notes belong to the authenticated user. The server derives ownership from the
session; request bodies cannot set `user_id`. Every mutation requires the
session's `X-CSRF-Token` from `GET /api/auth/csrf`.

| Method | Endpoint | Result |
| --- | --- | --- |
| `GET` | `/api/notes` | List the authenticated user's notes |
| `POST` | `/api/notes` | Create a note (`201`) |
| `GET` | `/api/notes/{id}` | Get one owned note |
| `PUT` | `/api/notes/{id}` | Partially update supplied fields |
| `DELETE` | `/api/notes/{id}` | Delete one owned note |

`title` is required, trimmed, and limited to 200 Unicode characters. `content`
is required as a string and limited to 50,000 Unicode characters; an empty
string is allowed for a title-only note. Timestamps are managed by MySQL. Lists,
reads, updates, and deletes are scoped to the current user; an unknown or
another user's note returns the same `404` response. `PUT` is partial: omitted
fields retain their value, an empty request object is rejected, and `content: ""`
clears the note body. Unknown fields including `user_id` return `422`.

The `notes` table uses `utf8mb4`, an index on `(user_id, updated_at)`, and a
foreign key to `users.id` with `ON DELETE CASCADE`. Apply it with
`php database/migrate.php`. The Notes page is available at `#/notes`; its
search filters the notes already loaded in the browser and does not add an API
search endpoint.

Run the Notes regression suite after applying migrations:

```bash
php tests/run-note-regression.php
```
