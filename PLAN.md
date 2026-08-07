# Keepsake — Build Plan

> **Read this file first, every session.** It is the single source of truth for
> where the build stands. Because Claude Code on the web runs in ephemeral
> containers, nothing survives between sessions except what's committed to
> git — so this file (plus the code and commit history) *is* the memory of
> the project across usage-limit resets and multi-day work.

> **✔ ALL EIGHT PHASES COMPLETE (as of 2026-08-07).** Phase 8's polish and
> open-source-readiness pass closed out the build — secret scan clean across
> full git history, every brief-flagged tunable confirmed present and
> documented, year-project isolation spot-checked through Phases 4-6's write
> paths, `capture.css`/`review.css`/`layout.css` folded into `styles.css`
> (`public/assets/` now holds exactly one CSS file, per every sibling's
> convention), the Phase 6 emptied-page gap fixed and tested, and a real
> top-level README written for a public-GitHub audience. See the Status
> Tracker's Phase 8 entry at the bottom for the full account, and this file's
> "How to resume" section below still applies if further work ever picks this
> back up — `git log --oneline` is more current than any prose here.

## How to resume

1. Read the **Status Tracker** at the bottom of this file to see the last
   completed phase and what's next.
2. Read `git log --oneline -20` to see recent work in case the tracker is
   stale (always update the tracker, but verify against reality — the
   tracker is written by hand/agent and can lag).
3. Spawn the subagent listed for the next incomplete phase, using the prompt
   template in that phase's section.
4. When the subagent finishes: review its diff, run/sanity-check it, **commit
   immediately**, update the Status Tracker checkbox, commit that too, and
   push. Do this before starting the next phase — a finished-but-uncommitted
   phase is invisible to the next session.
5. If a phase is large, break it into sub-commits rather than one giant
   commit at the end — if a session ends mid-phase (usage limit, disconnect),
   partial committed progress is recoverable; uncommitted work is not.
6. Never use `isolation: "worktree"` for phase agents unless you plan to
   merge that worktree back before the session ends — worktrees that aren't
   merged don't persist into the next session.

## Suite conventions

Keepsake is part of Kathryn's self-hosted app suite (RSS Reader, Personal
CRM, Inspiration Board, Book Tracker, Workout App). It should look and feel
like a sibling of those apps, not a new design.

- `kathrynmarinaro/inspiration` (Inspiration Board) and
  `kathrynmarinaro/personal-cms` (Personal CRM) exist as GitHub repos and are
  the primary sources for the shared CSS design system, layout conventions,
  auth pattern, and — critically — Inspiration Board's multi-photo
  upload/crop/batch-caption UI, which Section 2.4/5.1 of the brief says to
  reuse directly rather than reinvent.
- **Action for Phase 0**: attempt to attach those two repos to the working
  session (`add_repo` tool). The CSS is a **verbatim copy, not an adaptation**
  — `public/assets/styles.css` is identical byte-for-byte across every
  sibling app, so it gets copied into Keepsake at that same path unmodified,
  the same way Personal CRM copied it from Grocery. Auth scaffolding and the
  upload/crop JS may need real adaptation (Keepsake's own tables, its own
  callback wiring) — the CSS does not; treat it as Foundation-owned and
  complete the moment it's copied in. If repo access isn't available in a
  given session, ask Kathryn to paste the shared stylesheet and the
  Inspiration Board upload/crop component before starting Phase 0 — don't
  invent a parallel design system from scratch, since that creates rework
  later.
- Once conventions are confirmed, record the concrete decisions here
  (color tokens, layout grid, auth table/session pattern, file upload
  helper) so every later phase's agent can be pointed at this section
  instead of re-deriving it.

### Phase 0 outcome, reconciled: sibling repos read and matched

`kathrynmarinaro/inspiration` and `kathrynmarinaro/personal-cms` were both
reachable in this session (checked out locally alongside this repo) and read
in full — `personal-cms` as primary reference (it documents itself as a
verbatim port of Grocery, "the newest of the suite"), `inspiration` as a
secondary cross-check. Phase 0's placeholders were replaced with the real
suite conventions, not adapted or re-themed:

1. **The house stylesheet, adopted verbatim.** `public/assets/css/app.css`
   (the invented warm-neutral palette) is deleted, along with the now-empty
   `css/` directory. `public/assets/styles.css` is a byte-for-byte copy of
   `personal-cms/public/assets/styles.css` (verified with `diff`). Every
   `<link>` now points at `assets/styles.css` via the `asset()` helper. No
   `<style>` blocks, no structural inline `style=`, and the file itself is
   not edited — matching every sibling.
2. **The four other shared JS modules, ported ahead of need.** `api.js`,
   `inline-edit.js`, `swipe.js`, `reorder.js` and `menu.js` are copied
   verbatim from `personal-cms/public/assets/` into
   `public/assets/`. None are wired up yet — Phase 1 has no swipeable rows or
   inline-editable text — but they cost one file each to have sitting ready,
   the same reasoning `personal-cms/CLAUDE.md` gives for porting `reorder.js`
   before gift ideas needed it.
3. **Login page markup/flow, fully matched to Personal CRM's shape**, no
   remaining divergence: Personal CRM's login is a single site-wide password
   with no username, stored as `password_hash` directly in `config.php`.
   Keepsake's first reconciliation pass kept a real `username` +
   `password_hash` **table** instead (Phase 0's own decision); Kathryn later
   asked for the username to be dropped, so a follow-up pass switched
   Keepsake to the same single-password-in-config model as every sibling —
   see `lib/auth.php`'s header comment for the history and item 7 below for
   the schema side. Everything about the login screen (markup, `.login-*`
   classes, the safe-redirect guard, failing open when unconfigured) matches
   the sibling pattern.
4. **Function-based `lib/`, not classes.** `src/lib/Database.php`
   (`Keepsake\Database`) and `src/lib/Auth.php` (`Keepsake\Auth`) are gone.
   `lib/bootstrap.php` + `lib/db.php` + `lib/auth.php` now hold plain
   functions (`db()`, `q()`, `cfg()`, `auth_*()`, `h()`, `asset()`,
   `fatal_error()`, `json_out()`/`json_error()`/`json_body()`,
   `require_method()`), ported from `personal-cms`'s copies of the same
   files with two things deliberately **not** carried over: the
   `MYSQL_ATTR_INIT_COMMAND` timezone pin and `fmt_date()`, both flagged in
   `personal-cms/CLAUDE.md` as *that app's own* divergence from the rest of
   the suite (born from reach-out/birthday due-date arithmetic Keepsake
   doesn't do). `db()` does carry the CLI-only PDO test override — see item 7.
5. **No separate views layer.** `src/views/dashboard.php`, `src/views/login.php`
   and `src/views/partials/{header,footer}.php` are gone. `public/index.php`
   and `public/login.php` are now the templates directly, each a full
   `<!doctype html>`…`</html>` document with inline `<?= h($x) ?>` markup, the
   same shape as `personal-cms/public/index.php`.
6. **`config.example.php` at the repo root**, not `config/config.php.example`.
   `lib/bootstrap.php` loads `APP_ROOT . '/config.php'`. README and this file
   updated to match.
7. **One `schema.sql` at the repo root, no migrations directory.**
   `migrations/001_create_users_table.sql` and `scripts/migrate.php` are
   gone; auth-related tables now live directly in `schema.sql`, written
   `CREATE TABLE IF NOT EXISTS` so re-applying the file is always safe — no
   runner needed, matching `personal-cms/schema.sql` and
   `inspiration/schema.sql`. There is no `users` table any more (see the
   auth update below) — the auth section of `schema.sql` now holds only
   `login_attempts`.
8. **`scripts/` renamed to `tools/`.** Originally `seed_user.php`, since
   replaced by `tools/make-hash.php` (see below) — same CLI-only reasoning.
   No `apply-schema.php` was added: neither sibling has one either — both
   just document `mysql -u root <db> < schema.sql` in prose, so Keepsake does
   the same rather than inventing a new pattern (README's Setup section).

**Auth update, after the reconciliation pass above: the `users` table is
gone, and login throttling is now ported.** The reconciliation pass above
deliberately preserved Keepsake's own `username` + `password_hash` table
instead of switching to the siblings' single `password_hash` value in
`config.php`, since its job was code shape, not auth semantics. Kathryn
subsequently asked for both changes directly:

- **No username.** `schema.sql`'s `users` table is deleted; `config.php`
  gets a `password_hash` value exactly like every sibling. `tools/seed_user.php`
  is replaced by `tools/make-hash.php` (ported from `personal-cms`, which
  ports it from the Workout Generator unchanged) — prints a hash to paste
  into config rather than writing a table row. `lib/auth.php`'s
  `auth_attempt_login()` now takes just a password; `auth_current_user()` is
  gone (there's no username left to return), and callers that only needed to
  know "is anyone logged in" now call `auth_is_logged_in()` directly.
- **Login throttling, ported from `personal-cms`.** The escalating-delay
  curve (`auth_attempt_delay()`, pure and unit-testable) plus a hard lockout
  window, both keyed to `REMOTE_ADDR` server-side in a new `login_attempts`
  table (`ip`, `succeeded`, `attempted_at`) — a session-based counter
  protects nothing, since an attacker just drops the cookie between guesses.
  `public/login.php` checks `auth_blocked_for()` before even looking at the
  posted password, so a locked-out client doesn't get to spend a guess to
  learn it was going to be refused. Constants (`AUTH_WINDOW_MINUTES`,
  `AUTH_LOCK_AFTER`, `AUTH_SLOW_AFTER`, `AUTH_MAX_DELAY`) match
  `personal-cms`'s values exactly rather than being re-tuned from scratch.

## Architecture decisions (fixed, don't relitigate per-phase)

- **Stack**: PHP + MySQL, matching the suite. No framework — confirmed
  against both sibling repos in the Phase 1 reconciliation pass; neither uses
  one.
- **DB access — PDO, not mysqli**, confirmed: both `personal-cms` and
  `inspiration` use PDO with named parameters and `PDO::ERRMODE_EXCEPTION`.
  `lib/db.php` holds a single `db(): PDO` function (module-level `static`,
  not a class) plus `q($sql, $params): PDOStatement`, matching both siblings
  exactly.
- **Folder layout, confirmed against both siblings**: `public/` is the only
  web-exposed document root. `lib/` (not `src/lib/`) holds plain-function PHP
  at the repo root, one level above `public/`. There is no `views/`
  directory — `public/*.php` files are the templates directly. `config.php`
  (gitignored) and `config.example.php` (committed) live at the repo root,
  next to `lib/`, not in a `config/` subdirectory. `schema.sql` is a single
  file at the repo root; there is no `migrations/` directory and no migration
  runner. `tools/` (not `scripts/`) holds CLI-only helpers. No
  Composer/autoloader yet — `lib/bootstrap.php` does explicit `require`s;
  introduce Composer only when a phase actually needs a package (e.g. Phase
  2's EXIF reading or Phase 7's PDF library).
- **Testability without MySQL, ported from the siblings' pattern**: `db()`
  carries a CLI-only override (`$GLOBALS['keepsake_pdo_override']`) so
  `tools/test-harness.php` can install an in-memory SQLite database built
  from `schema.sql`, the same mechanism `personal-cms/tools/test-harness.php`
  uses. Unreachable over HTTP — gated on `PHP_SAPI === 'cli'` and a
  `$GLOBALS` key nothing in a request can set.
- **Config over hardcoding**: DB credentials, geocoding endpoint, tunable
  thresholds (event date-gap, ~180-char text-page threshold), trim size —
  all in `config.php`, sourced from the committed `config.example.php`
  template. No secrets committed, ever — `.gitignore` excludes `/config.php`
  from commit 1.
- **Repo is public-eventually**: keep this in mind for naming, comments, and
  file layout, but don't let it slow down the initial build — it's a
  should, not a blocker, per the brief.
- **Year-project isolation**: every table that holds content carries a
  `year_project_id` (or derives it from date), and regenerating one year's
  book layout must never touch another year's rows. Baked into the schema
  starting with Phase 1 (see that section below for how each table resolves
  its year), not bolted on later.
- **Trim size / print specs**: 8.5"×8.5", bleed/margins compatible with both
  Lulu and Mixam — confirm exact bleed values (likely 0.125" bleed, ~0.5"
  safety margin, but verify against both services' current spec sheets)
  during Phase 7, not guessed earlier.

## Phases & agent delegation

Each phase below is written as a self-contained brief you can hand to a
subagent (`Agent` tool, `general-purpose` unless noted) almost verbatim.
Phases are ordered by dependency, not necessarily by how Kathryn will use
the app day-to-day.

### Phase 0 — Foundations & scaffolding
**Agent**: `general-purpose`
**Depends on**: nothing
**Delegate prompt**: "Set up the Keepsake PHP/MySQL project skeleton per
`PLAN.md`'s Architecture Decisions and Suite Conventions sections. Pull
design/auth/upload-crop conventions from `kathrynmarinaro/inspiration` and
`kathrynmarinaro/personal-cms` if attached to the session; otherwise flag
that Kathryn needs to supply them and stub reasonable defaults. Deliver:
folder structure, `config.php.example`, `.gitignore`, DB connection helper,
login page + session auth (single user), base layout template with the
shared CSS, and an empty year-project dashboard page. Fill in the 'Suite
conventions' section of `PLAN.md` with what you found/decided."
**Exit criteria**: app boots locally-equivalent (or documented how to run),
login works, dashboard renders, conventions section of this file is filled
in.

### Phase 1 — Data model & migrations
**Agent**: `general-purpose`
**Depends on**: Phase 0
**Delegate prompt**: "Design and implement the MySQL schema for Keepsake
per Sections 1–4 and 7 of `keepsake-brief.md` [or paste brief inline]:
year_projects, quotes, anecdotes, snapshots (birthday + school-year
templates), photos (with EXIF date/GPS, skip-for-book, full-page flag),
photo_text_bundles, event_groups (with manual rename/merge/split support),
geocode_cache (lat/lon → location name, to avoid repeat Nominatim calls),
book_layouts/pages/page_photos (versioned so 'create book layout' can be
re-run without destroying a prior generation), and the auth/session table
from Phase 0. Every content table must resolve to exactly one
year_project_id. Write migrations, not just a schema dump, so this is
re-runnable and diffable."
**Exit criteria**: migrations run cleanly, schema documented (ER diagram or
table-by-table doc), year isolation verified with a manual test insert.

### Phase 2 — Capture flow (mobile-first)
**Agent**: `general-purpose`
**Depends on**: Phase 1
**Delegate prompt**: "Build the mobile-first capture flow per Section 5.1:
quick-add for quotes/anecdotes (who-said-it, date defaulting to today,
editable), snapshot forms (birthday + school-year templates with their
specific optional fields, freeform notes, manual hero-photo selection),
and the photo upload flow — multi-select upload, EXIF date extraction
(fallback to submission date), crop tool, batch step-through
one-photo-at-a-time for caption/crop, optional photo+text bundling at
submission time. Port the crop/batch-step UI from Inspiration Board rather
than building new (see PLAN.md Suite Conventions). Auto-assign
year_project_id from the resolved date (EXIF year for photos) on save."
**Exit criteria**: can submit one of each content type from a phone-width
viewport; a photo with EXIF data lands in the correct year automatically; a
batch of 5 photos can be uploaded, cropped, and captioned in one flow.

### Phase 3 — Review/browse (desktop)
**Agent**: `general-purpose`
**Depends on**: Phase 2
**Delegate prompt**: "Build the desktop review/browse views per Section
5.2: chronological timeline/calendar view (default) and a flat
filterable-by-type grid view, full edit on any entry (text, date, type,
location, caption, crop, year override), the 'skip for book' toggle and
'full page' flag on photos, event-group review (rename/merge/split — call
out that groups are populated by Phase 4's algorithm, so stub against a
placeholder grouping if Phase 4 isn't done yet), and the pre-layout
subtitle-editing step for the book title page."
**Exit criteria**: every field described in Sections 2–3 of the brief is
editable from this view; skip-for-book and full-page flags persist and are
reflected in a photo grid at a glance.

### Phase 4 — Event grouping & geocoding engine
**Agent**: `general-purpose` (this is algorithmic but not the hardest piece
— see Phase 5 for where to spend the expensive model)
**Depends on**: Phase 1, benefits from Phase 3's review UI existing
**Delegate prompt**: "Implement the event-grouping engine per Section 4.1
and 7: date-gap detection to cluster photos into event groups (threshold
configurable, not hardcoded — start at a documented default like 3 days
and note it's tunable), reverse geocoding of GPS EXIF via OpenStreetMap
Nominatim with a persistent MySQL cache keyed on rounded lat/lon (respect
Nominatim's usage policy — rate-limit and set a descriptive User-Agent),
auto-naming groups from date range + location (date-range-only fallback),
and manual override support (rename/merge/split) wired into Phase 3's
review UI. Write this so the threshold and naming logic are easy to
re-tune once Kathryn has real backfilled data — Section 4.1/7 of the brief
explicitly expects iteration here."
**Exit criteria**: uploading a backfilled batch of photos from one real
trip produces a single sensible event group; uploading two clearly separate
trips produces two groups; group rename/merge/split from Phase 3 persists
correctly.

### Phase 5 — Book layout engine (the hard part)
**Agent**: `general-purpose`, but **see the Model Guidance section below —
this is the one phase worth considering Opus 5 or Fable 5 for**, given
explicitly in Section 7 as "the most novel/complex piece" needing
iteration.
**Depends on**: Phase 4
**Delegate prompt**: "Implement the auto-arrange algorithm per Section 4.3
of the brief (revised — read the current version, not just this summary):
within each event group, sub-group photos by day/close timing; arrange onto
pages using orientation-matching as primary driver, with a variety
heuristic that varies page density (1–4 photos) so the book doesn't
monotonously repeat one layout; manually flagged 'full page' photos always
get their own page; quotes/anecdotes in an event's date range occupy a page
slot as a styled card unless they exceed the tunable ~180-character
threshold (then they get a full page) — a quote/anecdote is ALWAYS placed
this way, by date, never as a photo's caption; a photo's own typed caption
(photos.caption) renders inline within that photo's existing slot, which
isn't a separate content type competing for a slot; snapshot entries always
get their fixed full-page template regardless of surrounding grouping.
Persist layout as versioned
records (Phase 1's book_layouts/pages/page_photos tables) so regenerating
never destroys a prior version, 'reflow from here' can regenerate
everything downstream of a page without touching earlier pages, and local
edits (swap a photo, move a photo) don't ripple forward by default. Include
a way to preview/compare multiple generated layouts for the same year.
Write the density/variety/orientation heuristic as isolated,
independently-testable functions — Kathryn will want to hand-tune constants
after seeing the first real output."
**Exit criteria**: running layout generation on a real year's worth of
reviewed content produces a spread that isn't "one photo per page" for
everything, respects full-page flags and snapshot templates, and can be
regenerated without wiping manual edits made in already-approved earlier
pages.

### Phase 6 — Page review UI
**Agent**: `general-purpose`
**Depends on**: Phase 5
**Delegate prompt**: "Build the post-generation page-by-page review UI per
Section 5.4: visual spread-by-spread rendering of the generated layout,
drag-and-drop manual adjustment (swap photos, move a photo to another
page), the 'reflow from here' action wired to Phase 5's downstream
regeneration, and cover-photo/title/subtitle selection as the final step
before export."
**Exit criteria**: can visually browse an entire generated book, drag a
photo to a different page, and trigger reflow-from-that-point without
affecting earlier pages.

### Phase 7 — PDF export
**Agent**: `general-purpose`
**Depends on**: Phase 6
**Delegate prompt**: "Implement print-ready PDF export per Section 5.5 and
7: 8.5\"×8.5\" trim size with bleed/margin conventions valid for both Lulu
and Mixam's current softcover specs (look up their current spec pages
rather than assuming — bleed/margin requirements can change) — make trim
size and bleed **config values**, not hardcoded, since the brief flags
printer-agnosticism as a requirement. Use a PHP PDF library already used
elsewhere in the suite if one exists (check sibling repos), otherwise pick
one with solid image-heavy-layout support (e.g. mPDF, TCPDF, or
FPDF-with-extensions — evaluate for 8.5x8.5 support before committing).
Export should read directly from the reviewed/reflowed layout tables from
Phase 6, not recompute layout."
**Exit criteria**: exported PDF opens cleanly, dimensions and bleed
verified against Lulu's and Mixam's spec docs, and a full year's book
exports without manual intervention.

### Phase 8 — Polish & open-source readiness pass
**Agent**: `general-purpose`
**Depends on**: everything else, run last
**Delegate prompt**: "Do a pass over the whole Keepsake codebase: verify no
secrets are committed (grep history too, not just HEAD), verify every
tunable value flagged in the brief (event date-gap threshold, ~180-char
text-page threshold, trim/bleed) is a config value with a documented
default, verify year-project isolation holds everywhere (spot-check that
regenerating one year's layout cannot touch another year's rows), clean up
any TODOs left by earlier phases, and write a top-level README covering
setup, config, and the suite conventions this app follows — written with an
eventual public GitHub audience in mind per the brief's Section 'Purpose'.

**Also, on explicit instruction: combine all of Keepsake's CSS into a single
file.** Phases 2, 3 and 5 each flagged the same gap and each added its own
separate, scoped file rather than editing the house stylesheet
(`capture.css`, `review.css`, `layout.css` — see PLAN.md's Suite Conventions
entries for those phases for why each exists). Fold all three into
`public/assets/styles.css` itself and delete the three separate files, so
Keepsake ends this phase with the one-file convention every sibling app
follows, updating every `<link>` in `public/*.php` to match. Two things to
get right doing this:
  1. **This makes `styles.css` diverge from being byte-identical to Personal
     CRM's** — Keepsake is the first app in the suite that needs
     photo/crop/layout classes at all, so this divergence is expected and
     accepted, not a regression to avoid. Say so plainly in the file's own
     header comment (it currently claims byte-identical parity with the
     sibling copy) rather than leaving that claim stale and wrong.
  2. **Do not silently push these new classes back into
     `kathrynmarinaro/personal-cms` or `kathrynmarinaro/inspiration`.**
     Whether the photo/crop/grid vocabulary this app grew is worth
     backporting to the shared house system is Kathryn's call, not this
     phase's — flag it as a suggestion in the final report if it seems
     worth doing, don't act on it unilaterally."
**Exit criteria**: fresh clone + documented setup steps actually works;
`git log -p | grep`-style secret scan is clean; README is something a
stranger could follow; `public/assets/` contains exactly one CSS file, and
every screen still renders correctly from it (no class dropped in the
merge).

## Model guidance — should you use Fable for any of this?

Short answer: **not by default, but it's worth reaching for once, on Phase
5 specifically.**

`claude-fable-5` is Anthropic's most capable widely-released model, aimed at
the hardest reasoning and longest-horizon agentic work — it is *not* a
narrative/creative-only model despite the name; it's the top tier of the
same reasoning/coding line as Opus and Sonnet, just priced above Opus
($10/$50 per MTok vs. Opus 5's $5/$25, vs. Sonnet 5's $3/$15) and tuned for
runs that can take many minutes per turn.

For a solo-user PHP/MySQL app like this, most of the work (Phases 0–4, 6–8)
is well-scoped CRUD, forms, and standard UI — that's squarely in Sonnet 5's
strike zone (near-Opus coding quality at much lower cost), and there's no
reason to pay Fable-tier prices for it.

**Phase 5 (the book layout engine) is the one place the brief itself flags
as "the most novel/complex piece of this build" that "will likely need
iteration after seeing real output."** That's exactly the profile Fable is
built for: a hard, well-specified, mostly self-contained algorithm-design
problem (event sub-grouping, orientation pairing, density/variety
heuristics) where getting more of it right in the first pass reduces how
many iteration rounds you need later. If you want to spend the extra cost
once to get the strongest possible first draft of that algorithm, invoke
that phase's agent with `model: "fable"`. If you'd rather keep costs down
throughout, Opus 5 (`model: "opus"`) is a very reasonable middle ground for
just that phase and still meaningfully stronger than Sonnet on this kind of
algorithm design — use Fable only if Opus's first pass isn't good enough
after real data comes in and you want one expensive, high-effort re-design
pass rather than many cheaper iterations.

Recommendation: **default every phase to the standard agent model; for
Phase 5 only, run it once at `model: "opus"`, and hold Fable in reserve as
the tool to reach for if the layout algorithm still isn't producing good
spreads after you've tuned the date-gap and density heuristics against real
backfilled data.**

## Status tracker

Update this after every phase. Keep it terse — the phase sections above
have the detail.

- [x] Phase 0 — Foundations & scaffolding
- [x] Phase 1 — Data model & migrations
- [x] Phase 2 — Capture flow (mobile-first)
- [x] Phase 3 — Review/browse (desktop)
- [x] Phase 4 — Event grouping & geocoding
- [x] Phase 5 — Book layout engine
- [x] Phase 6 — Page review UI
- [x] Phase 7 — PDF export
- [x] Phase 8 — Polish & open-source readiness

**Last updated**: 2026-08-06 (Phases 0-5 complete, one session. The entry below is Phase 2's; each later phase appends its own. Phase 2 complete, same session as Phase 0/1
above. `public/capture.php` is one mobile screen, four accordion sections
(quote/anecdote/snapshot/photos), all wired to new `public/api/*.php`
endpoints backed by `lib/repo.php` (year-auto-assignment, brief §3),
`lib/exif.php` (new work — date/GPS extraction, not a port; Inspiration
Board only ever read EXIF orientation) and `lib/imageproc.php` (sniffing,
one synchronous thumbnail, crop — adapted from Inspiration's, no queue, no
separate "detail" copy since Keepsake crops the kept original directly).
`public/assets/crop.js` is a close-to-verbatim port; `photo-batch.js` is a
new module built to `annotate.js`'s session/move/render/close shape, with
caption/location/date/crop fields instead of description/tags/URL.
Exit criteria verified by code trace plus two test scripts, since this
build environment has neither MySQL nor a browser (same constraint every
phase so far has had): `tools/verify-capture.php` proves year-auto-
assignment end to end — including a photo whose captured_at is a simulated
EXIF date from 2019 landing in the 2019 year_project while every other
check in the same run is dated 2026 — plus EXIF edge cases (uninitialized
camera clock, malformed GPS fractions); a scratch script (not committed)
exercised the full upload→thumbnail→crop
pipeline against both a GD-generated JPEG and a real EXIF+GPS-bearing one
(built with Python's piexif) to confirm `lib/imageproc.php`/`lib/exif.php`
work end to end, not just in isolation. Both `tools/verify-schema.php` and
`tools/verify-capture.php` pass.

**One gap flagged, not silently worked around**: the stylesheet Keepsake
inherited (Personal CRM's, byte-for-byte) has zero photo/crop-related
classes — no sibling before Inspiration Board ever needed any, and
Inspiration's own cropper CSS lives inside *its* monolithic styles.css, not
a portable module. Rather than leave the crop tool non-functional or hack
around it with inline `style=`/`<style>` blocks, `public/assets/capture.css`
is a new, separate file — `styles.css` itself is untouched and still
byte-identical to Personal CRM's — scoped strictly to classes this phase's
own JS (`crop.js`/`photo-batch.js`/`photo-picker.js`) introduces. Whether
these rules belong here permanently or should fold into `styles.css` (and
from there back into Inspiration Board) is Kathryn's/Foundation's call; see
that file's header and this phase's session report for the full reasoning.

Next: Phase 3's desktop review/browse screens — full edit on every field
captured here, the skip_for_book/full_page toggles this phase deliberately
left alone, and event-group review once Phase 4 exists to populate it.

**Follow-up after Phase 2 landed: the photo+text bundling feature was
removed.** Kathryn clarified the model directly: a photo's caption is typed
during upload (`photos.caption`, unchanged), and a quote/anecdote is ALWAYS
a standalone, dated entry — never attached to a specific photo as its
caption. This invalidated `photo_text_bundles` (Phase 1's table for linking
a quote/anecdote to a photo as a caption) entirely, since nothing will ever
write to it under the corrected model. Removed rather than left as dead
schema: `schema.sql`'s `photo_text_bundles` table and every comment
referencing it, `lib/repo.php`'s `photo_text_bundle_create()`, the
`photo_id` parameter and bundling logic in `public/api/quotes.php` and
`anecdotes.php`, the "Attach a photo" control on both quick-add forms in
`public/capture.php`/`capture.js`, and the corresponding test block in
`tools/verify-capture.php` (both test scripts re-run clean afterward).
`public/assets/photo-picker.js` stays — its other caller, a snapshot's
manual hero-photo selection, is unaffected. `keepsake-brief.md` §2.5 and
§4.3, and Phase 5's delegate prompt above, are rewritten to match — Phase 5
hasn't run yet, so this matters for its own future correctness, not just
as a historical record.)

**Phase 3 complete (2026-08-06, same session as Phases 0-2 above).**
`lib/repo.php` gained `quote_update()`/`anecdote_update()`/
`snapshot_update()` (plus `_get`/`_delete`/`_for_year` for all three),
following `photo_update()`'s exact partial-update pattern: only keys
present in `$fields` are touched, and editing `entry_date` re-resolves
`year_project_id` through `year_project_get_or_create()` — brief §3's
"auto-assigned year is editable" now holds for every content type, not
just photos. `photo_update()` itself gained `skip_for_book`/`full_page`/
`event_group_id` (it previously only handled caption/location_text/
captured_at, flagged explicitly as Phase 3's job in that function's own
Phase 2 comment). `snapshot_update()` reads the row's own type and only
ever writes that template's columns — the other template's fields, if
sent, are silently ignored, mirroring `snapshot_create()`'s existing
invariant. New `event_groups` CRUD (`event_group_create/_rename/_merge/
_split/_delete`, `event_groups_for_year`) — `_merge()` refuses to cross
the year-isolation boundary (a source group from a different year is
skipped, not merged); `_split()` recomputes both groups' date ranges from
post-split membership. New `year_project_list/_get/_get_by_year/
_update_subtitle/_set_cover_photo`. All backed by `tools/verify-review.php`
(20+ checks: year re-resolution for all three newly-editable types,
snapshot template-field isolation on update, skip_for_book/full_page
persisting independently through repeated partial updates, cover_photo_id
cleanup on delete, merge/split semantics including cross-year rejection)
— passes clean alongside `tools/verify-schema.php` and
`tools/verify-capture.php`.

`public/index.php` now runs a real `SELECT * FROM year_projects ORDER BY
year DESC` (Phase 0's placeholder loop over 2020-current-year is gone).
**Decision**: shows only years with a real row — no synthesized
placeholders for years with nothing captured yet, since
`year_project_get_or_create()` is the only thing that ever creates one and
there is deliberately no status column to fake (`dashboard_status()`
derives in-progress/layout-generated/empty from EXISTS checks instead).
Trade-off flagged in that file's own header: a brand-new deploy with zero
captures shows an empty state with nothing to click, and the fix (a "jump
to a year" input) is noted but not built, since nothing asked for it yet.

`public/review.php` (new) is the desktop review/browse screen (brief §5.2):
three views behind `?year=YYYY&view=timeline|grid|groups`, sharing one set
of `render_entry_*()` functions across all of them so there is exactly one
markup for "edit a quote/anecdote/snapshot/photo" in the whole app, not one
per view. Timeline groups by month (default, chronological). Grid filters
by type; filtering to Photo is the exit-criterion surface — a real
`.photo-grid` of thumbnails with `skip_for_book`/`full_page` visible at a
glance (dimmed/bordered, not hidden) via always-on toggle pills that save
instantly, no Save button needed, backed by `tools/verify-review.php`'s
persistence checks. Groups is event-group review against the real
`event_groups` table/schema — Phase 4 hasn't run, so it's usually empty; a
"New group" form makes manual creation possible (the brief's own fallback:
"nothing else can" populate it yet), and rename/merge/split are wired to
the repo layer above. The book's subtitle (brief §4.6) is tap-to-edit via
`inline-edit.js`, used for the first time in this app.

**Two decisions worth flagging explicitly, both recorded in
`review.php`/`review.js`'s own comments too:**
- **Delete is a plain button + `window.confirm()`, not `swipe.js`'s mobile
  gesture.** `swipe.js` was ported in Phase 0 specifically ahead of this
  screen, but personal-cms's own written convention (its CLAUDE.md) is that
  swipe-to-delete fits a low-stakes, quickly-retyped item on a phone held
  one-handed — not a desktop screen (brief: "Primarily desktop") deleting
  an entry that can carry a caption, a crop and a date nobody wants to
  retype from memory, with no undo. `attachSwipeDelete`/`showSnackbar`
  weren't force-fit in; `showSnackbar` alone is reused for save/error
  toasts, matching the rest of the suite's feedback pattern.
- **Field-edit saves and the two flag toggles patch the DOM in place;
  anything that changes which rows exist or how they relate to each other**
  (delete an entry, create/merge/split/ungroup an event group) **reloads
  the page after the request succeeds**, rather than hand-patching every
  affected row client-side — reloading re-renders from the exact state
  `lib/repo.php`'s merge/split logic just committed, which can't drift from
  it the way a bespoke DOM patch could.

**One CSS gap flagged, not silently worked around** (same pattern as Phase
2's `capture.css`): `public/assets/review.css` is a new, separate file —
`styles.css` stays untouched and byte-identical to Personal CRM's — scoped
to classes this phase's own markup introduces (the photo grid, the
type-filter/view-tab pill rows' spacing, a couple of `width:100%`-on-a-
flex-item overrides for two-button rows, mirroring `capture.css`'s
identical override for its cropper bar). No sibling before Keepsake has
ever needed a photo grid or an at-a-glance inclusion toggle. Flagged in
that file's own header for Kathryn/Foundation to decide whether it folds
into `styles.css` permanently.

Next: Phase 4's event-grouping/geocoding engine, which will populate
`event_groups` automatically (date-gap detection + Nominatim reverse
geocoding) — the manual create/rename/merge/split UI built this phase
should need no changes to keep working once real auto-detected groups
start showing up alongside hand-made ones.

**Phase 4 complete (2026-08-06, same session as Phases 0-3 above).**
`lib/geocode.php` (new) and `lib/grouping.php` (new) implement the engine
described in brief §4.1/§7; nothing in Phase 3's manual create/rename/
merge/split UI needed to change to accommodate it, as hoped.

**The join-existing-vs-new heuristic** (isolated in `lib/grouping.php`,
documented in that file's own header): ungrouped photos are clustered
among *themselves* first, by pure date-gap distance
(`event_grouping_cluster_ungrouped()`). Each resulting cluster's date range
is then tested against every existing group in the year
(`event_grouping_find_join_target()` / `event_grouping_range_gap_days()`,
0 if the ranges overlap) — if the closest existing group is within the
gap threshold, the *whole cluster* joins it (extending the range via
Phase 3's `event_group_recompute_dates()`) instead of spawning a duplicate
group for the same trip; otherwise the cluster becomes a new group.
Clustering-then-testing-the-cluster (rather than deciding photo-by-photo)
is what keeps one gap-connected run of ungrouped photos from being split
across two different fates. Ties (a cluster equidistant between two
existing groups) break toward the earlier-starting group, then the lower
id — deterministic but arbitrary, flagged in the code as exactly the kind
of thing brief §7/§8 expects to need retuning once Kathryn has real
backfilled data (e.g. whether the gap threshold itself should vary by trip
length is an open item there, not decided here).

**`skip_for_book` photos participate in auto-grouping.** Brief §4.2 scopes
`skip_for_book` to book *inclusion* only, and says nothing about
organizational grouping; excluding skipped photos from clustering would
mean toggling that flag could silently remove a group's only GPS reading,
or leave a skipped photo permanently ungrouped. Documented in
`lib/grouping.php`'s own header rather than agonized over further.

**Auto-naming** (`event_grouping_format_name()`/`format_date_range()`/
`resolve_location()`): date range + location, e.g. `"Jul 4–6 · Myrtle
Beach, South Carolina"`, matching schema.sql's own example on
`event_groups.name`; falls back to the date range alone when no member
photo has GPS. Location comes from the first (chronologically earliest)
GPS-bearing member photo, reverse-geocoded — not an average of every
member's coordinates (a multi-stop trip's centroid can land in the ocean
between two coastal stops) and not every GPS photo tried in turn (would
multiply rate-limited Nominatim calls for one group's name). `is_manual_name`
(Phase 3's schema/flag) is respected exactly as documented on that column:
once Kathryn renames a group, `name` is frozen, but `location_name` and the
date range still refresh on a later extend.

**Two trigger points, one function** (PLAN.md: "there is no queue/cron in
this app"), both calling `lib/grouping.php`'s `event_grouping_run()`: (a)
`public/api/photos-upload.php`, automatically at the end of a batch, scoped
to whichever year_project_id(s) that batch actually touched (derived from
each created photo's `entry_date`) — a grouping failure is caught and
logged, never surfaced as an upload failure, since Kathryn's already
watched the upload itself succeed; (b) a new "Group photos" button on
`public/review.php`'s Groups view, calling new
`public/api/event-groups-auto.php`, for re-running over whatever's
currently ungrouped (after a manual ungroup, or a date correction that
moved a photo into the year). Neither caller re-implements any clustering
logic of its own.

**Manual override stays authoritative, verified, not just asserted**: every
DB-touching function in `lib/grouping.php` filters on `event_group_id IS
NULL`, so a photo already in a group — by a prior auto-run, a manual
assignment, or a merge/split — is never read or reassigned by a later run;
`tools/verify-grouping.php` proves this directly (a second run with
nothing newly ungrouped changes zero rows) and proves the escape hatch (a
manually-cleared `event_group_id` becomes eligible again on the next run).

**What the Nominatim-blocked sandbox meant for testing**: this build
environment's outbound-HTTPS proxy returns a 403 for
`nominatim.openstreetmap.org` (an organizational egress policy — the same
category of constraint as "no MySQL"/"no browser" every earlier phase
documented and built around rather than fought). `lib/geocode.php` isolates
the one function that actually calls the network,
`geocode_http_fetch()` — written straight off Nominatim's documented
reverse-geocoding contract, but it has never made a real call against the
real API in any session that built it. Its own header flags this
explicitly, the same way `lib/exif.php`'s header flags what Phase 2
couldn't exercise. `tools/verify-grouping.php` substitutes a stub response
via a CLI-only override (`$GLOBALS['keepsake_geocode_fetch_override']`,
same shape as `lib/db.php`'s SQLite override) and proves everything
*around* that one function directly: the cache is consulted before any
"network" call and written after one, the rate limiter's logic, the
response-parsing (`geocode_parse_response()`, pure, fed synthetic JSON
bodies), and the whole clustering/naming/join heuristic end to end. What
this does **not** prove: that Nominatim's real response shape matches what
`geocode_parse_response()` expects, that the real endpoint accepts the
built URL, or that the User-Agent header actually satisfies Nominatim's
policy checker. A future session — or Kathryn, on a real host where
Nominatim isn't blocked — should sanity-check one real request (and
replace the placeholder contact email in `config.php`) before trusting
this blind.

**No CSS gap this phase.** The "Group photos" button and its surrounding
copy reuse existing classes (`.card`, `.btn-secondary`, `.hint`) exactly as
they already render elsewhere on `review.php` — no new markup shape was
needed, so `review.css` (Phase 3's flagged-but-separate file) is unchanged.

**Exit criteria verified** by code trace plus `tools/verify-grouping.php`
(48 checks: pure clustering/range-gap heuristic, cache-before-network/
write-after-fetch, one-trip-one-group and two-trips-two-groups against the
SQLite harness, the join-vs-new decision at exactly the gap threshold, the
already-grouped/re-eligible-after-ungroup invariants, naming with and
without GPS, and `is_manual_name`'s split protection), plus a clean
unmodified re-run of `tools/verify-schema.php`, `tools/verify-capture.php`
and `tools/verify-review.php` — Phase 3's group rename/merge/split and
every earlier phase's own checks still pass with zero changes to those
scripts.

Next: Phase 5's book layout engine — the hard part. `event_groups` now
populates itself; Phase 5 reads `photos.event_group_id`/`event_groups`
(date range + `skip_for_book`/`full_page`) plus `quotes`/`anecdotes`
(placed by `entry_date` into a group's range) and `snapshots` (always
their own full-page template) to arrange pages. Per PLAN.md's Model
Guidance section, consider running that phase's agent at `model: "opus"`.

**Phase 5 complete (2026-08-06, run at `model: "opus"` per the Model
Guidance section above).** `lib/layout.php` (new) is the auto-arrange engine
of brief §4.3/§4.5, plus book-layout CRUD in `lib/repo.php`, a `layout`
block of tunables in `config.example.php`, and a plain preview surface at
`public/layout.php`.

**Shape: a pipeline with pure decisions in the middle.**
`layout_load_year_content()` (the one place inclusion rules apply) →
`layout_plan()` (PURE — content in, ordered page specs out, no DB at all) →
`layout_write_pages()`. `layout_generate()` runs all three against a new
`book_layouts` row; `layout_reflow_from()` runs them again inside an
existing one. Because the middle is pure, `tools/verify-layout.php` asserts
on a whole book's shape without writing a row, and every scoring function
can be fed synthetic tuning that isn't in any config file.

**Day/close-timing sub-grouping reuses Phase 4's clustering rather than
duplicating it.** `event_grouping_cluster_ungrouped()` was refactored into
`event_grouping_cluster_by($rows, $sortKey, $distance, $threshold)` — one
chain-clustering loop with the METRIC INJECTED. Phase 4 passes its calendar-
day metric and keeps its exact semantics (`tools/verify-grouping.php` passes
unmodified); Phase 5's `layout_subgroup_photos()` passes an elapsed-hours
metric with a ~5h default. A single seconds-based threshold could not serve
both: 07-01 00:01 and 07-04 23:59 are 3 calendar days apart (Phase 4: one
event) but ~4.0 elapsed days. There is deliberately no separate calendar-day
rule — a night's sleep clears 5 hours easily, so days separate themselves,
and a party running 23:30→00:30 correctly stays on one page.

**The heuristic, in the order it decides things** (all pure, all separately
testable, per this phase's own delegate prompt): `layout_orientation()` maps
a photo to portrait/landscape/**flex**, where flex is a wildcard covering
square photos, photos with no stored dimensions (fail soft), and text cards.
`layout_orientation_score()` scores a SET of orientations against a small
table keyed by "<portraits>,<landscapes>" per page size — two portraits side
by side or two landscapes stacked score 1.0, a portrait+landscape pair 0.45
(no arrangement of those two avoids a dead corner on a square page), a 2+2
four-up 0.92 (uniform rows), 3+1 0.60, and **a single photo 0.75,
deliberately below a matched pair — that number is what keeps the engine out
of the one-photo-per-page look brief §4 exists to avoid.**
`layout_variety_penalty()` charges `repeat_penalty` per page in the
immediately preceding RUN of the same size plus half that for other pages of
that size still in the window, so the third 2-up in a row costs twice what
the second did. `layout_page_score()` combines them with
orientation_weight 1.0 (primary, per the brief) and density_weight 0.5
(secondary). `layout_choose_page_size()` tries sizes in preference order
2,3,4,1, ties keeping the earlier, and charges `orphan_page_penalty` against
any size that would strand exactly one photo at the end of a group — a
one-page lookahead rather than a real search, because it catches the case
that actually happens and stays legible to retune.

**Greedy in book order, not optimal per group, on purpose.** A DP could
partition one page-group optimally, but the variety heuristic is a function
of the pages already emitted ACROSS the book — event groups, full-page
photos and snapshot pages interleaved — so per-group optimality optimises
the wrong thing. Everything (page-groups, full-page photos, snapshots,
standalone text) is merged into ONE chronological block list before any page
is emitted, and the book is walked once in reading order.

**Judgement calls worth Kathryn's eyes**, all commented where they live:
- A **snapshot's hero photo is excluded from the loose photo flow** (it is
  already on that snapshot's page). The **cover photo is NOT** excluded — the
  cover isn't a `book_pages` row at all, and a cover reappearing inside a
  photo book is ordinary.
- **Full-page photo pages feed the variety history; snapshot and text pages
  do not.** A run of full-page photos should push the next ordinary page away
  from 1-up; a run of text pages is a different visual language.
- **A text card is scored as a wildcard**, which in practice means a page
  carrying one often takes fewer photos — a quote beside a single photo.
  Intended, and flagged in the code with the lever to change it.
- **A short text with no event and nothing within `text_attach_days` (2) gets
  its own page.** If a first real draft comes back with too many one-quote
  pages, widening that window is the first knob, not the last.
- `active_book_layout_id` is claimed **only by a year's first layout** —
  schema.sql is explicit that "newest" and "the one I'm working from" are
  different facts, so regenerating never yanks the version being reviewed.

**Reflow rests on one rule: everything on the retained pages is spoken for.**
`layout_reflow_from($layoutId, $n)` collects the content used on pages 1..n-1
(including a snapshot page's snapshot), re-plans the year excluding it, seeds
the variety history from those pages' own sizes, then deletes and re-inserts
from page n. Two consequences, both deliberate: a photo dragged onto page 2
by hand cannot reappear on page 40, and a photo dragged OFF page 2 comes back
into the flow rather than vanishing.

**`public/layout.php` is the preview/compare surface and is deliberately not
Phase 6.** Versions with their composition (pages, photo/text/snapshot split,
filled slots), and one version inspected page by page — page type, slots in
slot order, each photo's thumbnail and orientation, captions shown inline
where they belong, text cards as cards. Two actions only: "Create book
layout" and "Use this one", through new `public/api/book-layouts-create.php`
and `-activate.php`. No spread rendering, no drag-and-drop, no cover/title
selection, no reflow button — Phase 6 owns all four, and this markup expects
to be replaced by it. Reached from a "Book layouts" link on `review.php` and
a "Book" link on the dashboard for years that have one.

**Exit criteria verified** by code trace plus `tools/verify-layout.php` (113
checks: every pure function, then a synthetic 2024 with an event group split
across four day/close-timing sub-groups, a full-page flag, a skip_for_book
photo, a birthday snapshot with a hero photo, two short quotes, a >180-char
anecdote and a far-away orphan quote). It proves the arrangement is not
one-photo-per-page, page numbering is dense, every eligible photo appears
exactly once, skipped photos and hero photos appear nowhere, regeneration
INSERTs version 2 while version 1 stays byte-for-byte identical, and a
simulated manual edit (a photo swapped directly in `book_page_photos` before
page N) survives a reflow from N with every earlier page byte-for-byte
untouched. `tools/verify-schema.php`, `verify-capture.php`,
`verify-review.php` and `verify-grouping.php` all pass unmodified. The
layout screen was additionally rendered end to end in a scratch script (not
committed) against the SQLite harness, since this environment has no browser.

**One CSS gap flagged, not silently worked around** (third time, same
pattern as Phase 2's `capture.css` and Phase 3's `review.css`):
`public/assets/layout.css` is a new, separate file — `styles.css` stays
untouched and byte-identical to Personal CRM's — scoped to the page/slot
markup this screen introduces. Given three phases have now needed one, the
question of whether these three files should fold into `styles.css` (and
from there back into the siblings) is worth answering in Phase 8 rather than
being re-flagged a fourth time.

Next: Phase 6's page review UI, which owns the visual spread-by-spread
rendering, drag-and-drop, the "reflow from here" button (the logic is built
and tested — `layout_reflow_from()`), and cover/title/subtitle selection.
Phase 6 should expect to replace `public/layout.php`'s page markup entirely;
the version list and the active-layout switch are the parts worth keeping.

### Phase 6 note

Built in two passes in one session: a subagent ran most of it (repo-layer
`book_page_slot_swap()`/`book_page_slot_move()`, the three new API
endpoints, the visual page-by-page rendering and drag-and-drop in
`public/layout.php`/`layout.js`, the title/cover card) but was cut off by a
usage-limit reset before writing the test script its own doc comments
already referenced by name (`tools/verify-page-review.php`) or committing
anything — the work was sitting complete and lint-clean in the working
tree, just unverified and unpushed. The parent session picked up from
there: read every changed/new file, confirmed the repo-layer functions were
correct by tracing them (same-page reordering correctly excludes the slot's
own current position from "occupied"; cross-layout and wrong-page-type
attempts are refused; a photo can never be duplicated or lost), wrote
`tools/verify-page-review.php` against a REAL `layout_generate()` output
rather than hand-built rows, and ran it alongside all five earlier
`verify-*.php` scripts — all six pass. Then committed in three logical
chunks (repo layer + endpoints; the UI; the test) and pushed, exactly as if
nothing had been interrupted. Flagging the interruption itself here rather
than silently smoothing it over, per this file's own "How to resume" logic
— a future session should be able to trust this log.

**One known, deliberately-unfixed cosmetic gap**: moving the only photo off
a page leaves an empty `page_type='photos'` book_pages row (0 filled
slots) rather than deleting/renumbering it. `public/layout.php`'s renderer
degrades gracefully — an empty page card, not a crash or an error — but it
is a visible blank spot in the book until "Reflow from here" is used
downstream of it, which regenerates around the gap. Brief §4.4 frames
manual adjustment as "rarely needed"; this was judged not worth the added
complexity of auto-deleting-and-renumbering pages under this session's time
constraints, but it's a real, known limitation, not an oversight — worth
fixing in Phase 8's polish pass if it turns out to matter in practice.

CSS was correctly NOT touched here: PLAN.md's Phase 8 section (added last
session, see its own note above) already owns folding `capture.css`/
`review.css`/`layout.css` into `styles.css`, and this phase's new markup
was kept inside the existing `layout.css` rather than adding a fourth file.

Next: Phase 7's PDF export, reading directly from the reviewed/reflowed
layout tables — or Phase 8's polish pass (including the CSS consolidation
and the empty-page gap above) if Kathryn would rather close out loose ends
before export. Either is a valid next step per PLAN.md's own phase
ordering; ask rather than assume which one.

**Phase 7 complete (2026-08-06/07, same session as Phase 6 above).**
`lib/pdfexport.php` (new) reads directly from `lib/repo.php`'s
`book_layout_pages_with_content()` — exactly the shape hoped for — and
never recomputes layout. `composer.json`/`vendor/` (gitignored, installs
from `composer.lock`, also gitignored per this file's existing note) are
this app's first Composer dependency, per this file's own architecture
decision naming Phase 7 as when Composer would first be needed.

**Library: mPDF.** Neither sibling repo (`personal-cms`, `inspiration`) uses
a PDF library or Composer at all — `personal-cms/CLAUDE.md` says its
vendored PHPMailer is deliberately "no Composer, no autoloader" — so there
was nothing to match; this is the suite's first Composer usage, full stop.
mPDF was picked over TCPDF because every page type here (a photo grid, a
text card, a snapshot's two-column fact sheet) is naturally an HTML/CSS
layout problem, and mPDF renders HTML directly rather than requiring
per-element `Image()`/`Cell()` drawing calls; it's pure PHP with no external
binary to shell out to, closer to this app's "no build step" philosophy
than a wkhtmltopdf wrapper. TCPDF wasn't seriously in the running once that
was clear.

**Trim/bleed/safety-margin, verified against Lulu's and Mixam's actual
current spec pages (2026-08-06), not assumed:**
- **Trim: 8.5in x 8.5in** — the brief's own spec; both printers offer it as
  a standard square softcover trim.
- **Bleed: 0.125in on every edge — the two printers agree exactly.** Lulu's
  own 8.5x8.5 interior template ships sized at 8.75in x 8.75in (Lulu Help
  Center, "What is Full Bleed?",
  https://help.lulu.com/en/support/solutions/articles/64000255584-what-is-full-bleed-;
  Lulu Book Creation Guide,
  https://assets.lulu.com/media/guides/en/lulu-book-creation-guide.pdf).
  Mixam: "All print items... require a 0.125in bleed area outside your trim
  line" (Mixam Support, "Full Bleed Printing Explained",
  https://mixam.com/support/bleed), corroborated by Mixam's own digest-size
  worked example (5.5x8.5 trim -> 5.75x8.75 file — the identical
  0.125in-per-edge math).
- **Safety margin: 0.5in, uniform — the one real spec disagreement this
  phase had to reconcile.** Lulu wants a flat 0.5in from the trim edge
  everywhere. Mixam publishes a smaller 0.25in "quiet area" for ordinary
  content but a separate, larger 0.5in "gutter margin" specifically for the
  bound/spine edge of a softcover interior page (Mixam Support, "Print File
  Setup Guide", https://mixam.com/support/filesetup). This app renders one
  page at a time with no per-edge gutter treatment, so it takes the larger,
  uniform number: matches Lulu exactly, and is at least as conservative as
  Mixam on every edge (more generous than its 0.25in quiet area, exactly
  equal to its own 0.5in gutter number on the edge that matters most).
  Flagged here explicitly since it's the one place "verify against both
  services' spec sheets" turned up a real conflict rather than just a
  number to confirm — worth Kathryn's eyes if she wants a tighter margin for
  a specific printer later; it's one config value away
  (`config.example.php`'s `export.safety_margin_in`).

All three values are `config.example.php`'s new `'export'` block — never
hardcoded — with the citations above inline as comments on each value.

**Which layout gets exported: `year_projects.active_book_layout_id`,
always** — confirmed against schema.sql's own comment on that column
("most recent" and "the one I'm working from" are different facts) before
writing a line of export code, per this phase's own instructions. Never
"newest version," never a fresh `layout_generate()` call.
`pdf_export_resolve_layout()` throws a plain `RuntimeException`
(`'no_year_project'` / `'no_active_layout'`) for the two "nothing coherent
to export" cases, the same house pattern `lib/imageproc.php` already uses —
`public/api/export.php` catches it and maps to a `json_error()`.

**Cover and title, built for the first time this phase** (schema.sql's own
comment on `book_pages`: neither is a `book_pages` row) — read straight off
`year_projects.cover_photo_id`/`subtitle`/`year` and prepended ahead of
every generated page. Every `book_pages.page_type` gets its own renderer:
`'photos'` (1-4 slots, a photo's own `caption` inline, a text-card slot
mixed in per brief §4.3, grid shape chosen from slot count plus
`lib/layout.php`'s own `layout_orientation()` — reused directly, not
re-derived), `'text'` (a full-page quote/anecdote over the ~180-char
threshold), `'snapshot'` (birthday age/height or school_year
grade/school/teacher/favorite_color/dream_job/favorite_class, mirroring
`public/layout.php`'s `render_snapshot_page()` field-for-field so the print
matches what was already reviewed on screen — brief §2.3's real template
fields, not just type/date).

**Fail soft, three ways, all documented in `lib/pdfexport.php`'s own
header:**
- A photo file that can't be resolved on disk (moved, deleted, a stale row)
  renders as a **visible labeled placeholder** instead of vanishing
  silently — a missing photo in a print proof is exactly the kind of thing
  Kathryn should notice before paying to print it, so this deliberately
  does NOT hide the gap.
- **No cover photo chosen yet still renders a real cover page** — a plain
  background carrying just the year/subtitle, not a skipped page and not a
  crash — so page numbering downstream never shifts depending on whether a
  cover was picked yet. Choosing "placeholder" over "omit" was this phase's
  own call, flagged here per the delegate instructions.
- **A year whose active layout has zero `book_pages` still exports** — a
  valid 2-page (cover + title) PDF, the honest continuation of what
  `layout_generate()` already does for an empty year rather than a new
  failure mode.

**One mPDF quirk found and worked around, documented in
`pdf_render_cover_html()`'s own header comment**: nesting two
percentage-sized `position:absolute` children inside one
`position:fixed` full-bleed wrapper makes mPDF 8.3.1 silently insert a
phantom extra page — reproduced and isolated directly (a background-color
div plus a second absolutely-positioned text overlay was enough to trigger
it; a single absolute child, or two children in ordinary block flow, were
not). The cover avoids `position:absolute` entirely: one fixed wrapper,
ordinary document flow inside it, the photo/caption overlap done with a
negative top margin instead. `tools/verify-export.php` asserts this shape
directly (zero `position:absolute` anywhere in the cover's HTML) rather
than just hoping the phantom page doesn't come back.

**One deliberate simplification, flagged for Kathryn's eyes**: only the
cover page bleeds a photo to the true physical page edge, matching how a
printed book cover conventionally works. Every interior page — photo
grids, text cards, snapshot templates — stays inside the configured safety
margin using ordinary document flow rather than edge-to-edge bleed.
Nothing in brief §5.5 requires interior bleed, and this keeps every page
renderer simple and uniform against the page-count/dimension invariants
`tools/verify-export.php` checks. Worth reconsidering later if Kathryn
wants a more magazine-style edge-to-edge treatment on interior spreads —
not decided unilaterally here.

**The one action the exit criterion asks for**: `public/api/export.php?year=YYYY`
(a plain GET, gated the same three ways as every other endpoint even though
`require_same_origin()` is a no-op for GET) streams the PDF straight back
with `Content-Disposition: attachment`. `public/layout.php` gets a new
"Export PDF" card — a plain `<a href>` download link when the year has an
active layout, a disabled `<button class="btn-primary" disabled>` (the
stylesheet already styles `:disabled`) when it doesn't. **No new CSS
file** — both reuse existing classes, so there's no fourth `capture.css`/
`review.css`/`layout.css`-style gap to flag this time.

**Testability, the one place `tools/verify-*.php`'s pattern doesn't fully
apply** (no browser, no MySQL, and now no way to eyeball a rendered PDF
either): `tools/verify-export.php` proves the pure/near-pure pieces
directly (geometry math, missing-file fail-soft, the cover's
single-fixed/zero-nested-absolute HTML shape, the text-length safety
valve, which `book_layouts.id` gets resolved and when it refuses), then
generates a real synthetic 2024 through `layout_generate()` — a birthday
snapshot with a real hero photo, a school_year snapshot with no hero photo
(the "No hero photo" placeholder path), an event group with a full-page
and a skip_for_book photo, a short quote riding a photo page as a text
card, a long anecdote on its own text page, one photo with a real
embeddable JPEG fixture and several with none — exports it, and inspects
the actual PDF bytes: starts with `%PDF-`, has the expected page count
(cover + title + every `book_pages` row), and every `/MediaBox` in the file
matches the configured trim+bleed size in points, via the same
grep-parsing approach this phase's own instructions suggested. Also proves
the two fail-soft edges called out by name: a year with zero `book_pages`
still exports a valid 2-page PDF, and a year with no cover photo chosen
still exports cleanly. First verify script that needs `UPLOAD_DIR`/
`PUBLIC_DIR` and the first that writes a real file to disk — one small
fixture JPEG under `public/uploads/original/` (gitignored, deleted via
`register_shutdown_function` regardless of pass/fail).

**Exit criteria verified**: the exported PDF opens cleanly (valid `%PDF-`
header, correct page tree, real `startxref`/`%%EOF` trailer, checked by
hand against a full real export in addition to the test script); dimensions
and bleed verified against Lulu's and Mixam's actual spec pages (citations
above and inline in `config.example.php`); a full year's book exports
without manual intervention (one GET endpoint, tested end to end against a
realistic synthetic year). All seven `verify-*.php` scripts —
`verify-schema`, `verify-capture`, `verify-review`, `verify-grouping`,
`verify-layout`, `verify-page-review`, and the new `verify-export` — pass
clean together, unmodified except for `verify-export.php` itself being new.

Next: Phase 8's polish and open-source-readiness pass — the CSS
consolidation (`capture.css`/`review.css`/`layout.css` into `styles.css`,
already instructed above), Phase 6's known empty-page gap, a secret-commit
scan, tunable-value audit, and a top-level README. One suggestion for that
pass or for Kathryn directly, not acted on here: whether the safety-margin
reconciliation above (uniform 0.5in rather than a spine-aware gutter) is
worth revisiting if a specific printer ever gets chosen as the primary
target.

**Phase 8 complete (2026-08-07, this session) — all eight phases done.**
Every item in this phase's own section above, plus both explicit
instructions added after it (the CSS consolidation, done as its own
instructed sub-task), plus the optional emptied-page fix. In commit order:

**1. Secret scan across full git history — clean, no-op.** `git log --all -p`
over the entire history (not just `HEAD`, and not just a spot-check),
grepped for bcrypt-shaped hash literals, API-key/token/private-key patterns,
and `config.php` ever being added — zero hits beyond `CHANGE_ME` placeholders
and `make-hash.php`'s own instructional prose. `config.php` has never once
been committed, confirmed directly rather than trusted from every prior
phase's incremental spot-check. Nothing to commit for this item; folded into
this note instead of an empty commit.

**2. Tunable-value audit — all three brief-named values confirmed present
and documented, plus the ones Sections 2-4 implied.** Cross-checked
`config.example.php` against brief §7's explicit list line by line rather
than trusting the prior phases' own claims: `grouping.gap_days` (3),
`layout.text_page_chars` (180), and `export.trim_width_in`/
`trim_height_in`/`bleed_in`/`safety_margin_in` (8.5/8.5/0.125/0.5) all
exist, each with its reasoning documented inline (the safety-margin one
carries the Lulu/Mixam citation trail Phase 7 already worked out). Beyond the
three named ones, `layout`'s other seven values (`subgroup_gap_hours`,
`text_attach_days`, `orientation_weight`/`density_weight`,
`density_preference`, the three `variety_*` values, `orphan_page_penalty`)
and `geocode.min_interval_seconds` are also real config, not hardcoded —
Section 4.3/§7's "will likely need iteration" instruction applied to the
whole heuristic, not just the two numbers the brief happened to name. Also a
no-op commit-wise; the audit found nothing missing to add.

**3. Year-project isolation spot-check — holds, confirmed by tracing code,
not re-derived from `docs/SCHEMA.md`'s own claims.** Read
`lib/grouping.php`'s `event_grouping_run()` (scoped to one
`year_project_id` on both the ungrouped-photo query and the existing-group
join candidates), `lib/repo.php`'s `event_group_merge()`/`_split()` (merge
refuses a source group from a different year; split only ever moves photos
that already belong to the source group, which is already year-scoped),
`lib/layout.php`'s `layout_generate()`/`layout_reflow_from()`/
`layout_load_year_content()` (every query filters on `year_project_id`;
reflow reads its layout's year off the layout row itself, never a caller-
supplied value), and confirmed the Phase 6 note's claim about
`book_page_slot_swap()`/`book_page_slot_move()` directly in the current code
— both still refuse any pair of slots whose pages don't share a
`book_layout_id`, which transitively enforces same-year since a layout
belongs to exactly one year. No hole found; nothing to fix here.

**4. TODO grep — still clean.** A full-repo grep for `TODO` (excluding
`vendor/`) turns up nothing in application code, same as the last phase
found. `PLAN.md`'s own prose (this phase's delegate instructions, quoted
above) is the only place the string appears, which isn't a real TODO.

**5. CSS consolidation — done, on the explicit instruction quoted at the top
of this phase's own section.** `capture.css`, `review.css` and `layout.css`
are folded into `public/assets/styles.css` as three clearly-headed sections,
the three files are deleted, and every `<link>` in `public/capture.php`/
`review.php`/`layout.php` is updated to match — `public/assets/` now holds
exactly one CSS file. `styles.css`'s own header no longer claims
byte-for-byte parity with Personal CRM's copy; it explains plainly why that
claim is now stale (Keepsake is the first app in the suite with
photo/crop/layout needs) and points at this note. Nothing was pushed back
into `kathrynmarinaro/personal-cms` or `kathrynmarinaro/inspiration` — see
"Worth Kathryn's eyes" below.

**Class survival checked mechanically, not visually — no browser in this
environment.** Every selector `capture.css` and `review.css` defined was
grepped against the merged file: zero missing, both carried over verbatim.
`layout.css` was different, and this is flagged here rather than smoothed
over: **8 of its 13 classes were already fully dead before this phase
touched anything.** Phase 6 rebuilt `public/layout.php`'s page rendering
under new `ks-`-prefixed class names (`.ks-book`/`.ks-slots`/`.ks-slot`/etc.)
and never added CSS for any of them — its own session note above says "CSS
was correctly NOT touched here," which was true of the *file* but left that
screen's spread/grid layout with no styling underneath the renamed markup,
undetected until this phase actually grepped the old names
(`.book-pages`/`.page-slots`/`.slot`/`.slot-photo`/`.slot-caption`/
`.slot-text`/`.slot-snapshot`) against current `public/*.php` and
`public/assets/*.js` and found zero real hits. Rather than carry dead CSS
forward under a literal reading of "don't drop anything," they're replaced
with working rules under the names the markup actually uses now, restoring
the spread-by-spread rendering Phase 6's own header describes but which had
no CSS behind it — `.version-row`/`.is-open`/`.version-actions`, the two
classes Phase 6 kept using unchanged, carry forward as-is. Documented inline
in `styles.css`'s own layout section header, not just here.

**6. Top-level README rewritten — full pass, not a patch.** Covers what the
app does and why, a walkthrough of all four real screens, the complete
current folder structure (every `lib/*.php` file, every one of the nine
`tools/*.php` scripts including the five added since the old README was last
touched, `public/api/`, and the Composer dependency Phase 7 introduced),
updated requirements (Composer now required; `exif`/`gd`/`mbstring`
extensions; Imagick preferred for HEIC), every brief-flagged tunable in one
place, a testing section naming all seven `verify-*.php` scripts, and a
closing section flagging what a stranger cloning this repo would trip over
(no LICENSE file yet, `lib/geocode.php`'s real Nominatim call has never run
in this sandbox, nothing here has ever been exercised in a real browser).

**7. Optional: the Phase 6 emptied-page gap — fixed, not just documented.**
`lib/repo.php`'s new `book_page_delete_and_renumber()`, called by
`book_page_slot_move()` whenever a cross-page move drains the SOURCE page to
zero filled slots (no photos AND no text card — a page still carrying a lone
text card is left alone, exactly as before). Renumbers every later page in
the same `book_layout_id`, ascending, so `book_pages.uniq_layout_page` is
never hit mid-renumber. `book_page_slot_move()`'s own boolean return
contract is unchanged (every existing `=== true`/`=== false` caller, this
file's own included, keeps working); the new `public/api/
book-page-slots-move.php` response field `page_deleted` is determined by the
endpoint itself (captures the source page id before the call, checks it's
gone after), and `public/assets/layout.js` reloads instead of DOM-patching
when it's true — a page vanishing and everything after it renumbering is a
structural change, matching the "structural changes reload" rule this screen
and `review.js` already establish for delete/merge/split/reflow.
`tools/verify-page-review.php` gained a dedicated synthetic layout (built
directly through the repo layer for exact control over page shape) proving
the drain-delete-renumber path end to end, including that a TEXT page type
renumbers correctly alongside photo pages, that a mixed photo+text-card page
survives losing its photo, and that same-page reordering never triggers
deletion.

**Testability**: all seven `tools/verify-*.php` scripts were re-run after
every commit in this phase (not just once at the end) — `verify-schema`,
`verify-capture`, `verify-review`, `verify-grouping`, `verify-layout`,
`verify-page-review` (with its new Phase 8 checks), and `verify-export` all
pass clean. `composer validate` passes; every `.php` file in the repo passes
`php -l`; `public/assets/layout.js` passes `node --check`.

**Exit criteria verified**: a fresh-clone dry run was walked by hand
(`composer install`, `cp config.example.php config.php`,
`php tools/verify-schema.php` standing in for a real `mysql ... < schema.sql`
since this environment has no MySQL server, `php tools/make-hash.php` — all
work as the rewritten README describes); the secret scan above is clean; the
README is a real walkthrough, not a stub; `public/assets/` contains exactly
`styles.css` and nothing else; every class the three deleted files defined
was either carried forward verbatim or knowingly, visibly replaced (item 5
above) — checked mechanically by grep, not by eye, since there is no browser
in this build environment to click through and confirm visually, the same
constraint every phase since Phase 2 has documented and worked around rather
than fought.

**Worth Kathryn's eyes, now that the whole build is done:**
- **Whether the photo/crop/layout CSS vocabulary Keepsake grew (the cropper,
  the photo grid, the book-page spread/slot layout) is worth backporting
  into `kathrynmarinaro/personal-cms` and `kathrynmarinaro/inspiration`'s
  shared house system.** Not done here, per this phase's own explicit
  instruction not to push it back unilaterally — purely a suggestion.
- **No LICENSE file exists yet.** `composer.json` says `"license":
  "proprietary"`. Worth a decision before this repo actually goes public,
  not urgent before then.
- **`lib/geocode.php`'s reverse-geocoding call has never run against the
  real Nominatim API in any session that built this app** — this sandbox's
  egress policy blocks it outright, and every phase that touched geocoding
  said so rather than papering over it. Worth one real sanity-check (and
  swapping in a real contact email over the `CHANGE_ME@example.com`
  placeholder) before trusting it on a live deploy.
- **The Phase 6 CSS gap this phase found and fixed** (item 5 above) is a
  good example of why "the session that wrote a note about its own work"
  isn't the same as verifying that work — worth keeping in mind for any
  future phase-style build in this suite: a claim like "CSS was correctly
  NOT touched here" needs a class-by-class grep against current markup to
  actually confirm, not just a memory of not having opened the file.
- **Nothing in this app has ever been exercised in a real browser or against
  real MySQL.** Every one of the eight phases worked around that constraint
  carefully and documented it every time, and the `verify-*.php` suite is
  genuinely thorough — but a first real end-to-end run (upload a real photo
  batch, generate a real layout, drag a photo, export a real PDF, open it)
  is still worth doing before trusting this with 2020-2026's actual photos.

## Post-launch fixes (first real usage)

All eight phases were built and tested against a headless SQLite harness
with no browser and no real MySQL — flagged as the single biggest
remaining risk in Phase 8's own note above. This section records what
that first real run actually turned up, since "verified" and "used for
real" turned out to not be quite the same thing, twice now.

**Round 1 — capture flow** (`public/capture.php`, `public/assets/capture.js`):
1. No guidance on upload batch size — the upload is one long synchronous
   HTTP request per batch, no queue, so a large batch risks a server-side
   timeout that loses the whole thing. Added a UI hint recommending
   10-15 photos per batch (informational only, not enforced).
2. No indication the tab has to stay open during upload. Added a
   `beforeunload` guard (native browser prompt) while an upload is in
   flight, plus matching UI copy. **Chunked upload** (splitting one large
   batch into several smaller sequential requests, so a dropped connection
   only costs the current chunk) is documented as a future option in
   `keepsake-brief.md` §8 — explicitly not built, only the hint/warning are.
3. The caption/crop editing panel appeared to hang after the upload
   progress bar hit 100%. Root cause: XHR's upload-progress event tracks
   bytes SENT, not work done — it hits 100% the instant the browser
   finishes transmitting, well before the server has read EXIF, made a
   thumbnail and written a row for every photo in the batch (all
   synchronous). Fixed by switching the status message to "Upload
   complete — processing photos…" once the progress callback reports
   `frac >= 1`, so the gap reads as ongoing work, not a freeze.
4. Reordered the Add page: Photos first and open by default (was Quote)
   — it's what actually gets added most.

**Round 2 — review screen** (`public/review.php`, `public/assets/review.js`,
`public/assets/styles.css`): tapping a photo in the Grid view's "at a
glance" overview used to scroll down to and open a SEPARATE, duplicate
edit accordion in an "Edit photos" list further down the page — losing
your place in the grid every time you edited one photo. Root cause: Phase
3 built two parallel representations of the same photo (`render_photo_cell()`,
a read-mostly grid tile; `render_entry_photo()`, the real editable
accordion) rather than one. Fixed by merging them: `render_photo_cell()`
now IS the same kind of `<details>` accordion `render_entry_photo()` is —
same id/data attributes, same edit form in the body — styled as a compact
grid tile when collapsed (`.photo-cell-head`/`.photo-cell-date`/
`.photo-cell-bar`) and spanning the full grid width when opened
(`.photo-cell-details[open] { grid-column: 1 / -1; }`), so editing a photo
expands it in place instead of jumping anywhere. The separate "Edit
photos" list is gone; there is exactly one markup for "edit a photo" in
every context now, matching the original Phase 3 design goal that Phase 3
itself didn't quite reach for the Grid-view/Photo-filter case specifically.
`jumpToPhoto()` and the duplicate-removal fallbacks in `saveEntry()`/
`deleteEntry()`/`recrop()` (written for the two-parallel-elements world)
are removed as genuinely dead code, not just unused — there is no longer
a scenario where a photo has two DOM representations at once.

Both rounds are the kind of gap `verify-*.php` cannot catch by
construction (no browser exists in the build/test environment) — worth
remembering as a category, not just these two fixed instances, the next
time "all tests pass" is read as "this works."
