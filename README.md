# Keepsake

A year-round capture app for quotes, anecdotes, and photos that compiles
automatically into an annually printed photo book — so the book doesn't have
to be assembled from scratch at year's end. Single-user (Kathryn only).

Part of Kathryn's self-hosted app suite (RSS Reader, Grocery, Personal CRM,
Inspiration Board, Book Tracker, Workout Generator) — same PHP/MySQL stack,
same design system, same auth pattern. Built with an eye toward eventually
being shared as public, open-source code, which is why configuration is used
in preference to hardcoded values throughout and why no secrets are ever
committed (see "A note for a stranger cloning this" below).

All eight build phases are complete — see `PLAN.md`'s Status Tracker for the
phase-by-phase history, `keepsake-brief.md` for the original product brief,
and `docs/SCHEMA.md` for the database schema. **If you're picking this project
back up for further work, read `PLAN.md` first** — it's the running memory of
every decision made along the way, including several the brief itself doesn't
capture (Section 2.5's photo-caption-vs-standalone-text revision, for one).

## What it does

Keepsake is built around one idea: **a year is a project.** Every quote,
anecdote, snapshot, and photo you capture gets auto-assigned to a
`year_project` from its own date (EXIF date for photos, so old photos can be
backfilled without manually picking a year), and each year's content, review
state, and generated book layout are completely independent of every other
year's — regenerating 2024's book can never touch a single row of 2023's or
2025's (`docs/SCHEMA.md` and `tools/verify-schema.php` both make this claim
directly and prove it).

Four content types, per `keepsake-brief.md` §2:

- **Quotes** — something Kathryn or Emma said, dated.
- **Anecdotes** — a sentence or two about something that happened, dated.
- **Snapshots** — birthday or school-year fact sheets (age/height, or
  grade/school/teacher/favorite class/dream job), each with a manually
  chosen hero photo. These always render as their own dedicated full page in
  the book, using the same template year over year.
- **Photos** — multi-select upload, EXIF date/GPS extraction, a crop tool, a
  "skip for book" toggle (excluded from the layout without being deleted),
  and an optional "full page" flag for a standout shot.

The hard part is the **book layout engine** (`lib/layout.php`): photos get
auto-grouped into "events" by date-gap clustering
(`lib/grouping.php`) with reverse-geocoded names via OpenStreetMap Nominatim
(`lib/geocode.php`), then arranged onto pages using orientation-matching as
the primary driver and a density/variety heuristic as the secondary one, so
the book reads as intentional 1-4-photo spreads instead of "one photo per
page." Quotes and anecdotes are woven in by date, as a styled card sharing a
page's slot (or their own full page, past a tunable length). Everything is
manually correctable afterward — rename/merge/split a group, drag a photo to
a different page, "reflow from here" to regenerate everything downstream of a
point without touching what came before it — and layout generation can be
re-run any number of times per year to compare results, since every run is a
new, versioned `book_layouts` row rather than an overwrite.

Export produces a print-ready PDF sized for both Lulu's and Mixam's 8.5"×8.5"
softcover specs, so the same file can be uploaded to either printer to
compare quality/price before ordering.

## Screens

Three screens Kathryn actually uses, plus login/dashboard:

- **`public/index.php` — the year-project dashboard.** Lands here after
  login; one row per year that has at least one captured entry (a year
  "starts existing" the moment something is dated into it — there's no
  status column and no synthesized placeholder for years with nothing in
  them yet), linking into that year's review screen and, once it has one, its
  book layout.
- **`public/capture.php` — the mobile-first quick-add screen.** One page,
  four accordion sections (quote / anecdote / snapshot / photos). Photo
  upload supports multi-select, a crop tool, and a batch step-through that
  walks one photo at a time for caption/crop before finishing — ported from
  Inspiration Board's upload/crop pattern per the brief. Every date field
  defaults to today and stays editable.
- **`public/review.php` — the desktop review/browse screen.** Three views —
  chronological timeline (default), a flat grid filterable by type, and event
  group review (rename/merge/split auto-detected groups) — sharing one set of
  render functions so there's exactly one markup for "edit an entry" in the
  whole app. This is also where the photo grid's at-a-glance
  skip-for-book/full-page toggles live, and where the book's subtitle gets
  set before layout generation.
- **`public/layout.php` — book layouts.** Lists every generated version for
  a year with "Create book layout" / "Use this one" (brief §4.5: generating
  never overwrites, so comparing old and new costs nothing), and for the
  version being inspected, a real spread-by-spread visual rendering:
  photo/text slots at their own aspect ratio, drag-and-drop to swap or move a
  photo, "Reflow from here" per page, and the title/subtitle/cover-photo
  picker and PDF export link, both final steps before printing.

## Folder structure

```
public/                 Web root — point your web server here. Nothing
                         outside this directory should be web-accessible.
  index.php               Year-project dashboard (requires login).
  capture.php             Mobile-first quick-add screen.
  review.php              Desktop review/browse screen.
  layout.php              Book layouts: versions, spread view, export link.
  login.php               Login form + auth handling.
  logout.php              Destroys the session (POST only).
  uploads/                Uploaded/derived photo files. Gitignored except
                           for a .gitkeep; must be writable by the web server.
  assets/                 CSS/JS served directly.
    styles.css              The ENTIRE house stylesheet — design tokens
                             shared with every sibling app, plus Keepsake's
                             own capture/review/layout sections (folded in
                             from three separate files in Phase 8 — see the
                             file's own header for why it now diverges from
                             the siblings' byte-identical copy). The only CSS
                             file in this app; no <style> blocks, no
                             structural inline style= anywhere.
    api.js                   fetch() wrapper for JSON endpoints.
    inline-edit.js           Tap-the-text-to-edit-it behavior.
    swipe.js                 Swipe-to-delete with a 5-second undo snackbar.
    reorder.js                Drag-handle manual reordering.
    menu.js                  Hamburger-menu sheet pattern (ported, unused so
                             far — Keepsake has nothing app-level to put in
                             one yet).
    crop.js                  The crop-overlay drag interaction (ported from
                             Inspiration Board).
    photo-batch.js            Multi-photo upload's one-at-a-time step-through.
    photo-picker.js           Recent-photos picker grid (snapshot hero photo,
                             book cover photo).
    capture.js, review.js,
    layout.js                Per-screen controllers.

  api/                    JSON endpoints, one file per action (POST unless
                           noted), e.g. photos-upload.php, quotes-update.php,
                           event-groups-merge.php, book-layouts-create.php,
                           book-page-slots-move.php, export.php (GET, streams
                           the PDF). Every endpoint requires login and, for
                           non-GET requests, a same-origin check.

lib/                     Application code, not web-accessible (outside
                         public/, one level up). Plain functions throughout —
                         no classes, matching the rest of the suite.
  bootstrap.php            Loads config, wires up DB + auth. Required by
                           every entry point in public/ and tools/.
  db.php                   PDO connection (db(), q()).
  auth.php                 Session-based auth: one password in config.php
                           (no `users` table), plus IP-keyed login throttling.
  repo.php                 Repo-layer CRUD for every table — quotes,
                           anecdotes, snapshots, photos, event_groups,
                           year_projects, book_layouts/pages/page_photos.
                           Where every year/version-isolation check lives.
  exif.php                 EXIF date/GPS extraction for photo upload.
  imageproc.php            Upload sniffing, thumbnailing, and cropping
                           (Imagick preferred, GD fallback).
  geocode.php              Reverse geocoding via OpenStreetMap Nominatim,
                           with a persistent MySQL cache.
  grouping.php             Date-gap event-clustering engine (brief §4.1),
                           also the generalized clustering primitive Phase 5
                           reuses for day/close-timing sub-grouping.
  layout.php               The book layout / auto-arrange engine (brief
                           §4.3) — the most novel piece of this build.
  pdfexport.php            Renders the reviewed/reflowed layout tables to a
                           print-ready PDF via mPDF. Never recomputes layout.

schema.sql               The whole database schema, one file, no migrations
                         directory — CREATE TABLE IF NOT EXISTS throughout,
                         so re-applying it after a pull is always safe.

config.example.php       Committed template — copy to config.php locally.
config.php               Real config with DB credentials, the password
                         hash, and every tunable value below. Gitignored,
                         never committed.

composer.json             This app's only Composer dependency: mPDF, for
                         PDF export (Phase 7). Run `composer install` before
                         first use; vendor/ and composer.lock are gitignored.

tools/                   CLI-only helper scripts (not web-accessible; each
                         refuses to run outside PHP_SAPI === 'cli').
  make-hash.php            Prints a password hash to paste into config.php.
  test-harness.php         Translates schema.sql into an in-memory SQLite
                           database so the app is testable without MySQL —
                           MySQL is still the only production target; see
                           that file's own header before ever touching it.
  verify-schema.php        Loads schema.sql and proves year-project
                           isolation with real inserts/deletes/cascades.
  verify-capture.php       Proves year-auto-assignment (incl. EXIF-year-wins)
                           and EXIF edge cases (uninitialized clock,
                           malformed GPS).
  verify-review.php        Proves every editable field's repo-layer logic,
                           skip_for_book/full_page persistence, and event
                           group rename/merge/split (incl. cross-year
                           rejection).
  verify-grouping.php      Proves the date-gap clustering / join-vs-new
                           heuristic, manual-override invariants, and the
                           geocode cache-before-network behavior.
  verify-layout.php        Proves the pure arrangement heuristics and runs
                           the whole engine end to end on a synthetic year:
                           dense page numbering, no photo lost or duplicated,
                           regeneration never mutates an earlier version,
                           reflow-from-N never touches pages before N.
  verify-page-review.php   Proves the drag-and-drop repo functions
                           (swap/move, incl. cross-layout refusal) and the
                           emptied-page auto-delete-and-renumber behavior.
  verify-export.php        Proves the PDF geometry/fail-soft logic directly,
                           then exports a real synthetic year and inspects
                           the actual PDF bytes (page count, MediaBox size).

docs/
  SCHEMA.md                Table-by-table schema reference — read this for
                           the shape, read schema.sql's own comments for the
                           reasoning behind any non-obvious decision.

keepsake-brief.md          The original product brief.
PLAN.md                    The phased build plan, decision log, and Status
                           Tracker — the single source of truth for where
                           the build stands and why anything non-obvious is
                           the way it is.
```

Why this layout: `public/` as the only web-exposed directory keeps app code
and config out of reach of the web server even under a naive vhost config.
`lib/` holds plain functions (not classes) and there's no separate `views/`
directory — `public/*.php` files **are** the templates, the same pattern
every sibling app in the suite uses. `schema.sql` is a single file with no
migration runner, matching the rest of the suite. This structure was
reconciled against `kathrynmarinaro/personal-cms` and
`kathrynmarinaro/inspiration` starting in Phase 0 — see `PLAN.md`'s "Suite
conventions" section for the full record of what changed and why.

## Requirements

- **PHP 8.4**, with the `pdo_mysql`, `exif`, `gd` (with WebP support) and
  `mbstring` extensions. `Imagick` is preferred over GD for photo
  thumbnailing/cropping when present — in particular, HEIC/HEIF uploads
  (the default format on recent iPhones) only decode correctly through
  Imagick, so install it if Kathryn's phone is shooting HEIC. `pdo_sqlite` is
  needed only to run `tools/test-harness.php`/`tools/verify-*.php` locally
  without a MySQL server.
- **MySQL or MariaDB** for real use — the only production target. SQLite is
  a test-only stand-in (see `tools/test-harness.php`'s own header for why),
  never something to point a real deploy at.
- **Composer**, required starting with this app's PDF export feature —
  [mPDF](https://mpdf.github.io/) is the one dependency (`composer.json`).
  No other build step exists anywhere in this app: no npm, no bundler, no
  transpiler, no CSS preprocessor. Browser JS is hand-written ES modules,
  imported directly with no bundling step.

## Setup

1. Clone the repo and install the one Composer dependency:
   ```
   composer install
   ```
2. Create a MySQL database and a user with access to it.
3. Copy the config template and fill in real values:
   ```
   cp config.example.php config.php
   ```
   Edit `config.php` with your DB host/credentials. **Never commit this
   file** — it's gitignored from commit 1 of this repo, and every one of the
   values below that has a real-looking placeholder (`CHANGE_ME`) needs a
   real one before this is usable.
4. Load the schema:
   ```
   mysql -u root keepsake < schema.sql
   ```
   `schema.sql` is idempotent (`CREATE TABLE IF NOT EXISTS` throughout), so
   re-running it after a later `git pull` that added tables is always safe.
   No MySQL available to try this against? `php tools/verify-schema.php`
   applies the same file to an in-memory SQLite database and checks it end to
   end, including year-project isolation.
5. Set the password:
   ```
   php tools/make-hash.php
   ```
   Prompts interactively (or takes the password as an argument), then prints
   a hash to paste into `config.php` as `'password_hash'`. Re-running it and
   pasting the new hash is also how you change the password later. One
   password, no username, matching every sibling app.

   **The app works with no password configured** — the login gate fails open
   until `password_hash` is set to something other than `'CHANGE_ME'`, the
   same fail-open rule every sibling app uses, so a half-finished config can
   never lock Kathryn out of her own deploy. **Set a real password before
   pointing a real domain at this** — an unconfigured deploy is a *public*
   deploy. Failed login attempts against whatever *is* configured are
   throttled either way (`lib/auth.php`'s escalating-delay curve plus a hard
   lockout window, keyed on IP in the `login_attempts` table).
6. Make sure `public/uploads/` is writable by whatever user your web server
   runs as — every uploaded photo and its generated thumbnail/crop lands
   there.
7. Serve the app. For local development, PHP's built-in server works and
   needs no web server config:
   ```
   php -S localhost:8000 -t public
   ```
   Then visit `http://localhost:8000/`. For real deployment (e.g. Hostinger,
   matching the rest of the suite), point your web server's document root at
   `public/` and deploy by uploading plain files over FTP/SFTP — there is no
   build artifact to produce first.

### Tunable values worth knowing about

Every number the brief flagged as likely needing retuning after real use is
a `config.php` value with its reasoning documented right next to it in
`config.example.php` — none of them are buried as a magic number in `lib/`:

- **`grouping.gap_days`** (default `3`) — the date-gap threshold, in days,
  for splitting photos into separate event groups. The brief itself expects
  this to need tuning once Kathryn has real backfilled data (a multi-week
  vacation probably wants a larger gap than a weekend trip).
- **`layout.text_page_chars`** (default `180`) — the character-count
  threshold past which a quote/anecdote gets its own full page instead of
  sharing a page slot as a text card.
- **`export.trim_width_in` / `trim_height_in` / `bleed_in` /
  `safety_margin_in`** (defaults `8.5` / `8.5` / `0.125` / `0.5`) — the PDF's
  physical geometry, verified against Lulu's and Mixam's actual current spec
  pages (see `config.example.php`'s inline citations, and `lib/pdfexport.php`
  for how they combine into the rendered page size).

`config.example.php`'s `layout` block has several more (sub-grouping gap
hours, orientation/density weighting, variety-penalty tuning, an
orphan-page penalty) — every one of them exists specifically so the book
layout engine can be hand-tuned after seeing a real generated book, per the
brief's own instruction to treat that algorithm as a first draft. The one
tunable that's deliberately **not** in config is the 12-number
orientation-pairing score table — it reads as noise in a config file and as
a real table in code, so it lives at the top of `layout_orientation_score()`
in `lib/layout.php` instead; that function's own header says so.

## Testing

There is no browser and no MySQL server in the environment this app was
built in, so every phase's logic is proven against an in-memory SQLite
database (`tools/test-harness.php` translates `schema.sql` on the fly) rather
than clicked through. Run all seven scripts from the repo root:

```
php tools/verify-schema.php
php tools/verify-capture.php
php tools/verify-review.php
php tools/verify-grouping.php
php tools/verify-layout.php
php tools/verify-page-review.php
php tools/verify-export.php
```

Each prints `ok`/`FAIL` per check and exits non-zero on any failure. A green
run proves the schema and every write path are internally coherent —
**it does not prove MySQL accepts `schema.sql` as written**, since SQLite is
a test-only stand-in with its own (documented, deliberately narrow)
translation quirks. Sanity-check against real MySQL before trusting a schema
change blind. Never edit `schema.sql` to make the SQLite translator's job
easier — extend `tools/test-harness.php` instead; its own header explains
why.

## Suite conventions

Keepsake follows the same conventions as the rest of Kathryn's self-hosted
suite: `lib/` is plain functions (no classes/namespaces), prepared
statements only (`q($sql, $params)` from `lib/db.php` — never interpolate
into SQL), `declare(strict_types=1)` at the top of every file, `array()`
long syntax, and one `schema.sql` with no migration framework. See
`PLAN.md`'s "Suite conventions" and "Architecture decisions" sections for
the full record of what was reconciled against the sibling repos
(`kathrynmarinaro/personal-cms`, `kathrynmarinaro/inspiration`) and why.

**The CSS is the one deliberate divergence, and it's explained in the file
that carries it.** Every other sibling app carries `public/assets/styles.css`
as a byte-for-byte copy of the same house stylesheet. Keepsake started that
way too, but is the first app in the suite with photo-capture, cropping, and
book-layout needs — three build phases each needed a component class no
sibling had a precedent for, and each correctly added its own small scoped
file per house convention rather than editing the inherited one. Phase 8
folded all three into `styles.css` itself (see that file's own header for
the full account, and `PLAN.md`'s Phase 8 section for the instruction behind
it) — `public/assets/` now holds exactly one CSS file, matching every
sibling's one-file convention, even though its *contents* now legitimately
diverge from theirs. Whether the resulting photo/crop/layout vocabulary is
worth backporting into the shared house system is an open question for
Kathryn, not decided here.

## A note for a stranger cloning this

This repo is built with an eye toward eventually being public on GitHub
(`keepsake-brief.md`'s "Purpose" section), which is why every tunable value
is a documented `config.php` entry rather than a hardcoded constant, and why
`config.php` itself has never been committed in any phase of this build —
confirmed with a full `git log --all -p` grep across the entire history, not
just a spot-check of `HEAD`, as part of Phase 8's close-out pass. A few
things worth knowing if you're new here:

- **The repo has no LICENSE file yet** (`composer.json` currently says
  `"license": "proprietary"`), and this app has never had another user or
  contributor — that's Kathryn's call to make whenever "public" actually
  happens, not something this build phase decided on her behalf.
- **`lib/geocode.php`'s real network call has never run against the live
  Nominatim API in any session that built this app** — this build
  environment's outbound-HTTPS proxy blocks `nominatim.openstreetmap.org`
  outright. Every test proves everything *around* that one function (the
  cache, the rate limiter, the response parser, fed a synthetic response
  body) — see that file's own header and `tools/verify-grouping.php`'s. A
  real request, and the placeholder contact email in
  `config.example.php`'s `geocode.user_agent`, both need a first real
  sanity-check on a host where Nominatim isn't blocked.
- **This app has never been exercised in an actual browser.** Every screen
  was built and verified by code trace plus the `tools/verify-*.php` suite
  above; drag-and-drop, the crop tool's touch gestures, and general visual
  polish are things to click through for the first time on a real deploy,
  not things this build could confirm itself.
