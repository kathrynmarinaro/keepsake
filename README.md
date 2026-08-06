# Keepsake

A year-round capture app for quotes, anecdotes, and photos that compiles
automatically into an annually printed photo book. Single-user (Kathryn
only). Part of Kathryn's self-hosted app suite. See `keepsake-brief.md` for
the full product brief and `PLAN.md` for the phased build plan and current
status — **read `PLAN.md` first** if you're picking this project back up.

This README currently documents **Phase 0 (foundations/scaffolding) only**:
folder layout, config, auth, and an empty dashboard shell. No content types,
photo uploads, event grouping, or book layout exist yet — see `PLAN.md` for
what's next.

## Folder structure

```
public/            Web root — point your web server here, nothing outside
                    this directory should be web-accessible.
  index.php         Year-project dashboard (requires login).
  login.php         Login form + auth handling.
  logout.php        Destroys the session (POST only).
  assets/           CSS/JS/images served directly.
    css/app.css     Base stylesheet — see "Suite conventions" below.

src/                Application code, not web-accessible directly.
  bootstrap.php     Loads config, wires up DB + session + auth. Required by
                     every entry point in public/ and scripts/.
  lib/
    Database.php    PDO connection wrapper.
    Auth.php        Session-based auth (single user).
    helpers.php      e(), config(), asset() view helpers.
  views/            Plain-PHP templates, included by public/*.php.
    partials/       header.php / footer.php shared page shell.
    login.php
    dashboard.php

config/
  config.php.example   Committed template — copy to config.php locally.
  config.php           Real config with DB credentials. Gitignored, never
                        committed.

migrations/         Numbered .sql files, one change per file, applied in
                     filename order by scripts/migrate.php.

scripts/             CLI-only helper scripts (not web-accessible).
  migrate.php         Applies pending migrations.
  seed_user.php        Creates/updates the single allowed login.
```

Why this layout: `public/` as the only web-exposed directory is the
standard way to keep PHP app/config code out of reach of the web server
even under naive vhost configs (no `.htaccess`/rewrite trickery required to
hide it). `src/` for app code and `views/` for templates is a common,
boring split that keeps logic and markup separate without pulling in a
framework. `migrations/` + a tiny custom runner (rather than a full
migration framework) matches "no framework beyond what the suite already
uses" from `PLAN.md`'s Architecture decisions — this can be swapped for
whatever the sibling apps actually use once they're reachable.

## Requirements

- PHP 8.1+ (developed/tested against PHP 8.4) with the `pdo_mysql`
  extension.
- MySQL (or MariaDB) 5.7+/10.3+.
- No Composer dependency currently — everything is plain PHP with manual
  `require`s. If a later phase adds a package (e.g. an EXIF or PDF
  library), Composer will be introduced then; `.gitignore` already
  reserves `/vendor/`.

## Setup

1. Create a MySQL database and a user with access to it.
2. Copy the config template and fill in real values:
   ```
   cp config/config.php.example config/config.php
   ```
   Edit `config/config.php` with your DB host/credentials. Never commit
   this file — it's gitignored.
3. Run migrations:
   ```
   php scripts/migrate.php
   ```
   This creates the `users` table (and a `schema_migrations` tracking
   table).
4. Seed the one allowed login:
   ```
   php scripts/seed_user.php <username> <password>
   ```
   Or run it with no arguments to be prompted interactively. Password must
   be at least 8 characters. Re-running with the same username updates the
   password (upsert), so this is also how you reset the password later.
5. Serve the app. For local development, PHP's built-in server works and
   needs no web server config:
   ```
   php -S localhost:8000 -t public
   ```
   Then visit `http://localhost:8000/`. For real deployment, point your
   web server's document root at `public/` (Apache/Nginx vhost, same
   pattern as the other suite apps).

## Manual test plan (no browser available in this build session — verify by hand)

1. With no session cookie (private/incognito window, or after clearing
   cookies), visit `/index.php` (or just `/`, once a router/rewrite sends
   `/` to `index.php` — for now use `/index.php` directly).
   **Expected:** redirected to `/login.php?redirect=%2Findex.php`.
2. On the login page, submit an unknown username or wrong password.
   **Expected:** redisplays the login form with "Incorrect username or
   password." — no crash, no partial login.
3. Submit the username/password seeded in step 4 of Setup.
   **Expected:** redirected to `/index.php`, the dashboard renders showing
   the "Year Projects" heading, a preview notice, and year cards for
   2020–2026 (placeholder data — see `src/views/dashboard.php`).
4. Reload `/index.php` directly.
   **Expected:** stays on the dashboard (session persists across
   requests) — no redirect back to login.
5. Click "Log out" (top-right nav).
   **Expected:** redirected to `/login.php`.
6. Try visiting `/index.php` again after logging out.
   **Expected:** redirected back to `/login.php` — confirms the session
   was actually destroyed, not just the nav link hidden client-side.

### What was actually verified in this session (no MySQL server available here)

- Every `.php` file passes `php -l` (no syntax errors).
- `GET /index.php` while logged out returns a `302` to
  `/login.php?redirect=%2Findex.php` (verified with `php -S` + `curl`).
- `GET /login.php` renders the login form with a `200`.
- Missing `config/config.php` produces a clear `500` with setup
  instructions instead of a confusing crash (verified with `curl`).
- The core auth logic — the username/password lookup query plus
  `password_verify()` — and the login page's open-redirect guard were
  exercised directly against a throwaway SQLite database with a
  reimplementation of the same logic (this sandbox has no MySQL server to
  connect to), covering: correct login succeeds, wrong password fails,
  unknown username fails, empty credentials fail, and the redirect-target
  guard rejects `//host`, `https://host` and accepts local paths only.
- The actual `POST /login.php` → MySQL round trip and full click-through
  (steps 1–6 above) were **not** exercised end-to-end against a real MySQL
  server, since none is available in this environment. A human with a real
  MySQL instance should still walk through the manual test plan above once
  before trusting it fully.

## Suite conventions — placeholder status

**The CSS, auth page markup, and file/folder conventions in this Phase 0
build are placeholders, not the real suite conventions.** `PLAN.md`'s
original plan was to pull these from `kathrynmarinaro/inspiration` and
`kathrynmarinaro/personal-cms`, but repo access wasn't available in this
build session. See `PLAN.md`'s "Suite conventions" section for the full
list of what to reconcile once those repos are reachable — in short: swap
the color tokens and font stack in `public/assets/css/app.css`, swap the
login page markup/flow if the sibling apps do something different, and
port Inspiration Board's upload/crop/batch-caption component wholesale
before Phase 2 rather than building a new one.

## Login credentials

Not stored in this repo (by design — see `.gitignore` / "no secrets
committed"). Whoever runs `scripts/seed_user.php` chooses them; pass
whatever username/password you want Kathryn to use, then tell her directly
(not via a commit or an issue).
