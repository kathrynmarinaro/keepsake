# Keepsake

A year-round capture app for quotes, anecdotes, and photos that compiles
automatically into an annually printed photo book. Single-user (Kathryn
only). Part of Kathryn's self-hosted app suite (RSS Reader, Grocery, Personal
CRM, Inspiration Board, Book Tracker, Workout Generator) — same PHP/MySQL
stack, same design system, same auth pattern.

See `keepsake-brief.md` for the full product brief, `PLAN.md` for the phased
build plan and current status, and `docs/SCHEMA.md` for the database schema
— **read `PLAN.md` first** if you're picking this project back up.

## Folder structure

```
public/            Web root — point your web server here, nothing outside
                    this directory should be web-accessible.
  index.php         Year-project dashboard (requires login).
  login.php         Login form + auth handling.
  logout.php        Destroys the session (POST only).
  assets/           CSS/JS served directly, verbatim copies of the house
                     design system (see "Suite conventions" below).
    styles.css        The house stylesheet. Foundation-owned; don't edit it.
    api.js            fetch() wrapper for JSON endpoints.
    inline-edit.js     Tap-the-text-to-edit-it behavior.
    swipe.js           Swipe-to-delete with a 5-second undo snackbar.
    reorder.js         Drag-handle manual reordering.
    menu.js            The hamburger-menu sheet pattern for app-level actions.

lib/                Application code, not web-accessible directly (kept
                    outside public/, one level up).
  bootstrap.php     Loads config, wires up DB + auth. Required by every
                     entry point in public/ and tools/.
  db.php            PDO connection (db(), q()).
  auth.php          Session-based auth (single password in config.php,
                     no `users` table — plus login throttling).

schema.sql          The whole database schema, one file, no migrations
                    directory — CREATE TABLE IF NOT EXISTS throughout, so
                    re-applying it is always safe.

config.example.php  Committed template — copy to config.php locally.
config.php          Real config with DB credentials. Gitignored, never
                    committed.

tools/               CLI-only helper scripts (not web-accessible).
  make-hash.php       Prints a password hash to paste into config.php.
  test-harness.php    Translates schema.sql into an in-memory SQLite
                       database for testing without MySQL.
  verify-schema.php   Loads schema.sql via test-harness.php and verifies
                       year-project isolation with a real insert.

docs/
  SCHEMA.md           Table-by-table schema reference.
```

Why this layout: `public/` as the only web-exposed directory keeps app code
and config out of reach of the web server even under naive vhost configs.
`lib/` for PHP functions (not classes — see "Suite conventions") and no
separate `views/` directory: `public/index.php` and `public/login.php` **are**
the templates, the same pattern every sibling app uses. `schema.sql` is a
single file with no migration runner, matching the rest of the suite. This
folder structure was reconciled against `kathrynmarinaro/personal-cms` and
`kathrynmarinaro/inspiration` after Phase 0 — see PLAN.md's "Suite
conventions" section for what changed and why.

## Requirements

- PHP 8.4 with the `pdo_mysql` extension (and `pdo_sqlite` for
  `tools/test-harness.php`, if present).
- MySQL (or MariaDB).
- No Composer dependency currently — everything is plain PHP with manual
  `require`s. If a later phase adds a package (e.g. an EXIF or PDF library),
  Composer will be introduced then; `.gitignore` already reserves `/vendor/`.

## Setup

1. Create a MySQL database and a user with access to it.
2. Copy the config template and fill in real values:
   ```
   cp config.example.php config.php
   ```
   Edit `config.php` with your DB host/credentials. Never commit this file —
   it's gitignored.
3. Load the schema:
   ```
   mysql -u root keepsake < schema.sql
   ```
   `schema.sql` is idempotent (`CREATE TABLE IF NOT EXISTS` throughout), so
   re-running it after a later `git pull` that added tables is always safe.
   No MySQL available? `php tools/verify-schema.php` applies the same file
   to an in-memory SQLite database and checks it end to end, including
   year-project isolation — see `docs/SCHEMA.md`.
4. Set the password:
   ```
   php tools/make-hash.php
   ```
   Prompts interactively (or pass the password as an argument), then prints a
   hash to paste into `config.php` as `'password_hash'`. Re-running it and
   pasting the new hash is also how you change the password later. One
   password, no username, matching every sibling app.

   **The app works with no password configured** — the login gate fails open
   until `password_hash` is set to something other than `'CHANGE_ME'`,
   matching every sibling app, so it can never lock Kathryn out of her own
   deploy. Set a real password before pointing a real domain at this — failed
   attempts against whatever *is* configured are throttled either way (see
   `lib/auth.php`'s `login_attempts` table).
5. Serve the app. For local development, PHP's built-in server works and
   needs no web server config:
   ```
   php -S localhost:8000 -t public
   ```
   Then visit `http://localhost:8000/`. For real deployment, point your web
   server's document root at `public/` (Apache/Nginx vhost, same pattern as
   the other suite apps).

## Suite conventions

Keepsake follows the same conventions as the rest of Kathryn's suite —
`public/assets/styles.css` and the JS modules under `public/assets/` are
byte-for-byte copies of `kathrynmarinaro/personal-cms`'s, `lib/` is
plain functions (no classes/namespaces), and there's one `schema.sql` with no
migration framework. See `PLAN.md`'s "Suite conventions" section for the full
record of what was reconciled against the sibling repos and why, and
`keepsake-brief.md` for the product brief this app is built from.

No divergence remaining on auth: Keepsake originally kept a real `users`
table with a username (a Phase 0 decision preserved through the first
reconciliation pass), but that's since been dropped on request — one
password in `config.php`, no username, exactly like every sibling. See
`lib/auth.php`'s header comment for the history.
