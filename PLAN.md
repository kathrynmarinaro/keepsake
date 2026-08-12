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
that actually happens and stays legible to retune. **(Superseded in Round 5,
below: page size is now a hard 2-3 constraint enumerated and scored as whole
partitions, and `layout_choose_page_size()`, `orphan_page_penalty` and
`singles_penalty` are gone. Everything else in this paragraph still holds.)**

**Greedy in book order, not optimal per group, on purpose.** A DP could
partition one page-group optimally, but the variety heuristic is a function
of the pages already emitted ACROSS the book — event groups, full-page
photos and snapshot pages interleaved — so per-group optimality optimises
the wrong thing. Everything (page-groups, full-page photos, snapshots,
standalone text) is merged into ONE chronological block list before any page
is emitted, and the book is walked once in reading order. **(Round 5 makes
the WITHIN-group step a real search — the hard bounds shrink the candidate
set to single digits — while keeping the across-book walk exactly as
described here, which was always the load-bearing half of this decision.)**

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

**Round 3 — book layout algorithm: asymmetric composition + adjustable
crop.** Two complaints from a real generated layout: too many single-image
pages, and every photo cropped into a square regardless of its own
orientation ("I want to maintain the orientation of the image"). Both
traced to one root cause: a page's slots were rendered as a flat set of
EQUAL-SIZE cells (an HTML `<table>`/CSS grid of identical boxes), so a
mismatched portrait+landscape pair had nowhere good to go — the on-screen
preview covered the mismatch with `aspect-ratio:1; object-fit:cover`
(forcing every photo into a square), and the engine leaned on 1-up pages
partly to dodge that cost in the first place.

Before writing any code, built a comparison Artifact showing two candidate
fixes side by side — "letterboxed" (same equal-cell grid, switched from
crop to contain-fit, so mismatches show visible bars) vs. "asymmetric" (the
grid itself sized to the actual photos on it, cells hug their shape, no
bars). Kathryn chose **asymmetric**, and said a LITTLE crop is fine as
long as she can adjust it herself.

**The fix — one composition tree, two renderers.** New `lib/layout_render.php`:
a page's slots become a binary-split TREE (`layout_build_tree()`) — a row
split shares height and sizes children by their own aspect ratio, a col
split shares width and sizes children by `1/aspect`, following exactly the
shapes `lib/layout.php`'s own `layout_orientation_table()` comments already
named as good pages (two landscapes stack; a lone photo spans beside a
stacked pair of the other orientation; a 2+2 groups into two uniform rows).
Deliberately NOT a general bin-packer — a small set of named branches for
1-4 elements, the same "small pure function, easy to retune" shape as the
rest of the engine. `public/layout.php`'s preview walks the tree into
nested `flex` divs (CSS does the sizing math); `lib/pdfexport.php` walks
the SAME tree into nested `<table>`s with explicit millimeter widths/
heights (`layout_resolve_geometry()`) since mPDF has no flexbox — one tree,
never two opinions about the same page, which is exactly the kind of drift
Phase 8's CSS-class audit caught once already (see that phase's own note
above).

**Deliberately NOT stored.** A page's resolved roles (which flex photo
became portrait vs. landscape) and its tree shape are recomputed at RENDER
TIME from the page's CURRENT slots, every time — not decided once at
generate time and persisted. Same reasoning `photos.width`/`height` having
no stored `orientation` column already uses (schema.sql): a decision stored
once can disagree with what's actually in the slot after a Phase 6
swap/move. The only per-page "memory" is a mirror bit for visual variety
between same-shaped pages, derived from `page_number % 2` — free, and
can't go stale either. Net effect: **no schema change was needed for the
composition itself**, only for the crop override below.

**Adjustable crop.** `book_page_photos` gets four new nullable columns —
`crop_x`/`crop_y`/`crop_w`/`crop_h`, normalized fractions, NULL by default
(auto-fit to whatever shape the tree currently gives that slot). Entirely
non-destructive: unlike the existing `imageproc_crop_photo()` (which bakes
a crop into a photo's ORIGINAL file, everywhere it appears), this only
changes how ONE PLACEMENT of a photo is windowed on ONE page. `crop.js`'s
`openCropper()` gained an optional `lockAspect`/`initial` mode (pan/zoom
within a fixed shape, seeded from an existing crop) without changing
behavior for its two existing free-form callers — the trickiest part was
that `lockAspect` is a REAL-WORLD ratio but every box in that file is a
FRACTION of the displayed image, so a non-square photo needs the target
aspect converted through the image's own natural aspect before it means
anything (`lockFraction()`) — caught this by writing a standalone Node
script asserting the real, cut-pixel aspect ratio matched the requested
one, not just that the fraction math ran without throwing. On the PDF
side, each photo is pre-cropped to a real raster (`imageproc_crop_to_temp()`,
reusing `imageproc_crop_photo()`'s own GD/Imagick primitives against a temp
file, never touching `public/uploads/`) rather than leaning on `object-fit`,
which mPDF's `<img>` doesn't reliably honor.

**Consequence for drag-and-drop.** Swap and move used to patch the DOM
directly ("these two nodes traded parents"), because a page used to be a
flat list where that was the whole truth. It no longer is: swapping a
landscape into a slot that held a portrait can flip that page's WHOLE tree
shape (and, for a move, the source page's too). Both now reload on
success, same as reflow/generate/activate already did — there's no longer
a DOM patch that's fully described by "these two nodes changed."

**Retuned** `layout.density_preference[1]` from 0.35 to 0.18 (both
`config.example.php` and `lib/layout.php`'s own default, kept in sync) —
the old value partly existed to make 1-up a viable escape from a bad crop,
which the composition tree removed the need for; "mostly multi-image
pages" needed the number turned down further to actually show up.

**Files:** `lib/layout_render.php` (new), `lib/layout.php`
(`layout_resolve_orientations()`, factored out of `layout_orientation_score()`
so the two can never disagree), `lib/pdfexport.php`, `lib/imageproc.php`
(`imageproc_crop_to_temp()`), `lib/repo.php` (`book_page_slot_set_crop()`),
`public/layout.php`, `public/assets/{layout.js,crop.js,styles.css}`,
`public/api/book-page-photos-crop.php` (new), `schema.sql`, `docs/SCHEMA.md`,
`config.example.php`. New `tools/verify-layout-render.php` covers the tree
builder, geometry resolver, and auto-crop math against the SQLite harness;
`tools/verify-export.php`'s existing real-PDF assertions (page count, exact
MediaBox size) still pass unchanged against the new nested-table renderer.

**Schema change on an app that's already deployed.** This app has no
migration runner (`schema.sql`'s own header) and re-importing it only helps
brand-new tables, not new columns on an existing one — `DEPLOY.txt` now has
a "SCHEMA UPDATES" section with the one-time `ALTER TABLE` Kathryn needs to
run in Hostinger's phpMyAdmin, since a fresh `schema.sql` import silently
does nothing to a table that already exists.

**Still not browser-tested**, same standing caveat as the rest of this app —
the tree math, geometry, and crop-rect math are proven against the SQLite
harness and (for the PDF path) a real rendered PDF; the on-screen drag
interaction, the crop-adjust gesture, and the actual visual result are
traced by hand, not clicked. Worth a real first look before trusting it —
same note Phase 8 left, still true.

**Round 4 — still too many 1-up pages after Round 3.** Lowering
`density_preference[1]` (0.35 → 0.18 in Round 3) wasn't enough on its own.
Root cause, found by working through the scoring formula rather than
guessing: 1-up only ever competes against whatever the VARIETY penalty is
charging other sizes at that moment (`layout_variety_penalty()`), and that
penalty can get large — a run of four same-size pages costs that size up
to ~0.7 at the shipped defaults. A 1-up that hasn't appeared recently pays
NONE of that, so after a run of same-size pages, a fresh 1-up could
out-score a repeated multi-photo page even though the 1-up is the worse
page on its own merits — "avoid monotony" was quietly working against
"avoid singles". Confirmed by writing the exact scenario as a test
(`tools/verify-layout.php`: `layout_choose_page_size()` with
`recentDensities = [2,2,2,2]` and six matched landscapes still left to
place) before fixing anything, watching it fail, then fixing it.

Fix: a new tuning value, `singles_penalty` (default 0.5,
`lib/layout.php`'s `layout_tuning()` and `config.example.php`, kept in
sync as usual), subtracted from a size-1 candidate's score
UNCONDITIONALLY in `layout_choose_page_size()` — on top of, not instead
of, the existing variety penalty. A rest from repetition can no longer be
the reason a single wins. Does nothing to a page-group that genuinely only
has one photo to place (there's no competing size in that case; `size=1`
is the only candidate tried, per `maxPhotos`, and wins by being the only
option). The test above now passes and stays in the suite as a guard
against this specific regression.

If 1-up pages are still too frequent after this, the next lever is
raising `singles_penalty` further in `config.php`'s `layout` block — no
code change needed for that. Past a certain point the honest limit is
structural, not a scoring tweak: a page-group that only ever HAS one
photo (a genuinely sparse day, an event with a single shot) has no
alternative size to reach for. That would need subgroup-merging
(attaching a stray 1-2 photo cluster to its chronological neighbor before
partitioning) rather than a scoring change, and hasn't been built —
flagged here rather than assumed away.

**Round 5 — "2-3 photos per page" stops being a preference and becomes a
rule.** Third time Kathryn has asked for fewer single-image pages. Rounds 3
and 4 both answered with scoring (`density_preference[1]` 0.35 → 0.18, then
an unconditional `singles_penalty` of 0.5), and both worked in the sense that
1-up got rarer and failed in the sense that it still turned up: anything
decided by a score can be won by a score, and with a dozen page-groups in a
book the unlikely candidate gets a dozen chances. Round 4's own closing
paragraph named both halves of what was actually needed — a structural fix
rather than another number, and specifically "subgroup-merging (attaching a
stray 1-2 photo cluster to its chronological neighbor before partitioning)".
This round builds both halves.

**Half one: the partitioner no longer chooses page SIZE at all.**
`layout_partition_subgroup()` used to walk a page-group greedily, asking
`layout_choose_page_size()` for each page's photo count and letting sizes
1-4 compete on score. It now enumerates every partition of the group into
consecutive pages of `page_size_min`..`page_size_max` photos (new config,
defaults **2** and **3**), scores each candidate page by page against the
running book with the SAME `layout_page_score()` as before, and keeps the
best total. Scoring still decides everything it used to decide except
legality. The search is affordable precisely because the constraint is
tight — compositions of n into {2,3} grow like 1.3247ⁿ (Padovan), so an
ordinary page-group has single digits of candidates; past
`LAYOUT_PARTITION_MAX_CANDIDATES` (4000, reached around n=40 photos in ONE
sub-group) it falls back to a greedy walk under the same bounds, which is
the behaviour that shipped anyway. **Ties keep the largest-pages-first
candidate** (candidates are enumerated with sizes descending, comparison is
strictly-greater), so a coin flip resolves toward fewer, fuller pages.

**Half two: `layout_merge_lone_subgroups()`,** a new pure pass in
`layout_plan()` between sub-grouping and text assignment. A page-group
holding exactly one photo merges into a chronological neighbour, because
no page-size rule can help a group that only ever HAS one photo — the exact
limit Round 4 flagged. Two rules, deliberately different: inside an event
group a lone photo merges **always**, however wide the gap (the event is
already the statement that these photos are one occasion, and
`subgroup_gap_hours` is a rhythm heuristic, not a claim of separateness);
ungrouped, it merges only within the new `lone_merge_gap_hours` (default
**24.0**) — with no event asserting anything, the gap is the only evidence,
and a lone shot from a different week has earned its own page. A merge never
crosses the grouped/ungrouped boundary or joins two different event groups.
Nearest neighbour wins, **ties go to the earlier one** (a stray shot reads as
the tail of what just happened, and "the one before it" is predictable
without running the code). Two adjacent lone photos merging into one 2-up
page is the same walk, and is the case this was built for.

**Net effect: exactly two kinds of 1-photo page survive in a book** — a
`photos.full_page` shot (which has always bypassed the partitioner entirely,
unchanged) and a photo with genuinely no neighbour to pair with. Both are
asserted by name in `tools/verify-layout.php`'s end-to-end pass, so a check
that merely tolerated singles can't quietly pass on a book that has gone back
to being full of them.

**Removed rather than retuned:** `layout_choose_page_size()`, and with it the
`orphan_page_penalty` and `singles_penalty` config keys. Both were charges
against outcomes the bounds now forbid — a partition stranding one photo is
never a candidate (`layout_partition_feasible()`), and there is no 1-up
candidate left to charge. A knob that can only be multiplied by zero reads
like a lever and isn't one. Both keys are still safe to leave in the
`config.php` already on Kathryn's server: unknown keys merge over the
defaults and are never read. `layout_estimate_page_count()` gained a
`$maxPageSize` parameter so its "always under-estimate" contract survives
someone raising `page_size_max` — with hard bounds, `ceil(n / max)` is now
the EXACT minimum page count, which is what guarantees every scheduled text
card lands on a page that actually exists.

**Written test-first where it counted:** the `n=2..10` "every page holds 2 or
3 photos, nothing else" loop went into `tools/verify-layout.php` before any
engine change and failed on n=4, 7, 9 and 10 (4-up pages), exactly as
expected. The old `layout_choose_page_size()` checks were rewritten rather
than deleted where the intent survived — "a size that would strand exactly
one photo is avoided" is now `layout_partition_feasible()`'s own unit test,
which asserts the same thing as a structural fact instead of a scoring
outcome.

**Renderer, CSS and PDF are untouched, on purpose.**
`lib/layout_render.php` builds a composition tree from whatever slot count
the partitioner emits and already handles 1-4; narrowing the range it
receives needs nothing from it. `tools/verify-export.php` still builds a real
PDF through the changed engine and passes unmodified.

**Files:** `lib/layout.php`, `config.example.php`, `tools/verify-layout.php`,
`README.md`. Still not browser-tested, same standing caveat as every round
above — the constraint is proven against the SQLite harness and a real
rendered PDF, not looked at on a phone.

---

## Round 6 — the layout Kathryn drew

The rounds above kept tuning a model she had never agreed to. She looked at a
full book built from it and said the photos were being cropped and squeezed
into shapes she did not want, which was the accurate diagnosis: **79% of her
library is portrait 3:4**, so an engine that manufactured shape variety was
mutilating photos that were already a good shape. Then she drew the layouts she
did want on sticky notes, and every frame in them is a natural photo shape with
white space taking up the slack.

**The model inverted.** A photo's real aspect ratio is now an INPUT to the
layout. `lib/compose.php` holds 20 templates — 15 transcribed from her
drawings, 5 derived to fill shape combinations the drawings leave with nowhere
to go — and solves each page from the occupants' own ratios. Four rules, each
settled against a rendered proof rather than argued in the abstract:

1. A slot declares the shape it takes and only ever receives it.
2. Photos keep their own ratio.
3. Except where photos of the same orientation sit side by side: those are
   drawn at one identical size, centre-cropping whichever misses the group's
   target. Padding the odd one out with white space was built first and
   rejected on sight. Across her 123 photos this costs 4 crops.
4. A row of MIXED orientations is never normalised — it makes a rectangle, so
   the lower band of a 2×2 may be shorter than the upper. Forcing equal widths
   there was the one change she rejected outright.

**How it was settled.** `tools/layout-lab2.php` builds a self-contained HTML
proof of the whole book from exported CSV and a folder of thumbnails, with the
solver running in the browser so alignment and margin options are toggles
rather than rebuilds. Five rounds of "look at it, point at a page number" —
pages 10, 11 and 21 each named a real geometric fault — and the page mix she
approved is 44 pages: 5 singles, 14 two-ups, 10 three-ups, 15 four-ups.
`tools/verify-parity.mjs` drives both solvers over the real book and compares
rectangle for rectangle at zero tolerance, so the shipped engine cannot drift
from the proof she signed off on.

**The scorer had to go, not be retuned.** `layout_partition_score()` rates
PAGES, not books — page count was never a term in it. Harmless while the
ceiling was three; wrong the moment it was four, where it chose 2,1,2,1,2 over
4,4 because five pages that each score well beat two that score slightly less
well. This is Round 5's own lesson a second time: anything decided by a score
can be won by a score. Page count is now structural, inside one exact DP that
also refuses any page no template can draw, and `density_preference` survives
as a modifier within it. Candidate enumeration and the greedy fallback are
gone; the DP solves a 60-photo group the old path would not even enumerate.

**One event boundary is now crossed, deliberately.** Two leftover lone photos,
from different occasions, may share a page — never a photo folded INTO an
event, only two orphans seated together, and only when adjacent. Pairs only.

**Captions became a page-level thing.** They are still authored per photo,
because she will not know which photo lands on which page. The foot of the page
joins them, and rewriting that line saves `book_pages.caption_override`.
Clearing it and blanking it are different operations on purpose.

**Two renderers, one layout, structurally.** `compose_solve()` returns
rectangles in percent of the trim; the browser positions them absolutely, and
mPDF — which ignores CSS `left`/`top` entirely — rebuilds the same solved tree
as nested tables sized from those rectangles. Neither renderer does geometry of
its own. `lib/layout_render.php` is down to two crop helpers; its composition
tree, aspect algebra and geometry resolver were deleted rather than left
looking authoritative.

**Cropping is Kathryn's control now.** The engine reshapes a photo only to
match same-shape neighbours. "Adjust crop" changed from "fix what the layout
did to this photo" to "trim this photo because I want it trimmed".

**A real bug worth recording:** the PDF exporter was handed a template choice
never declared as a parameter, so every photo page silently exported as a line
of grey text — and the entire existing export suite still passed, because not
one assertion looked at whether a page had a photograph on it. A book of blank
pages was the most expensive way this exporter could fail and the one thing it
could do unnoticed. Now fenced.

**Events are no longer split by time.** The day/close-timing clustering
(`subgroup_gap_hours`, ~5h) now applies only to UNGROUPED photos. An event group
is already Kathryn's statement that these photos are one occasion, and re-cutting
it by a five-hour gap overrules her with a heuristic — event group 2 is eight
photos from January 15th to 23rd, which clustering turned into eight single-day
groups that could only pair into 2-ups. She reviewed a proof that kept them
together and preferred it. This also settled a disagreement already in the file:
`layout_merge_lone_subgroups()` had always merged across any gap inside an event
on exactly this reasoning, while the line above it split that same event apart on
the gap it then ignored.

**Files:** `lib/compose.php` (new), `lib/layout.php`, `lib/layout_render.php`,
`lib/pdfexport.php`, `lib/repo.php`, `public/layout.php`,
`public/api/book-pages-caption.php` (new), `public/assets/layout.js`,
`public/assets/styles.css`, `schema.sql`, `DEPLOY.txt`, `config.example.php`,
and six `tools/verify-*` scripts. Still not looked at on a phone — the standing
caveat from every round above — and the next real check is generating a book
from the live database and reading the PDF, where file paths, missing photos
and text cards are exercised together for the first time.

## Round 7 — the book's own name

**The printed margin was double-charged.** The solver works in percentages of
the trim and already leaves the margin Kathryn chose in the layout lab
(`COMPOSE_FILL`, 85%). The browser preview mapped those percentages onto the
trim; `pdf_draw_photos_page()` mapped them onto the content box — trim minus the
half-inch safety margin — so 85% on screen printed as 72.9%. Measuring from the
trim edge in both places is the fix, and it costs nothing in safety: 7.5% of
8.5in is 0.64in, wider than the 0.50in printers ask for. Verified by pulling the
image placement matrices back out of a real PDF, not by re-reading the maths.

The geometry test's safety-margin assertion used to hold *by construction* and
therefore said nothing. It now measures against the physical page, so it fails
if `COMPOSE_FILL` is ever loosened past the point where photos would print into
the trim zone.

**A book can be called something other than its year.** `year_projects.title`,
NULL meaning "use the year" — so brief §4.6's "Title: defaults to the year" is
still exactly what an untouched book shows, and a book she never renamed follows
the `year` column if that is ever corrected. Every reader goes through
`year_project_title()`; nothing reads the column. Storing a copy of the year at
creation time was the alternative and it makes "2025" the name she typed
indistinguishable from "2025" the app filled in.

**Clearing a field is its own control.** `inline-edit.js` treats an emptied
input as a cancel, on purpose — in the app it was written for, an emptied row is
a delete in disguise. That rule is right and it is shared with the siblings, so
"Reset" / "Remove" buttons are the separate gesture rather than an exception to
it. Same call `.row-cat` makes in Grocery.

**The preview was drawing the cover title at nearly twice what printed.** The
two renderers each had their own type sizes — 8.6% of the page in `styles.css`,
a flat 30pt in the exporter, which on an 8.75in cover is 4.8%. The sizes now
come out of `cover_band_metrics()` with everything else, and the band's HEIGHT
is derived from them rather than being a tuned constant sitting beside them.
A preview whose whole job is showing where the title falls cannot have its own
opinion about how big the title is; `tools/verify-pdf-geometry.php` now reads
the two line-heights out of the stylesheet and fails if they drift from the PHP
constants, or if a `font-size` reappears next to them.

Because the band's height is the type plus equal padding, the type is centred in
it by construction rather than by arithmetic — so a cover with no subtitle puts
its title dead centre, which is what she asked for. mPDF has no vertical
centring inside a fixed-position box, so the exporter spends that same padding
as an explicit top margin.

**The interior title page is gone.** "I actually don't want an internal title
page, just a cover." The cover already carries both lines. `page_count` dropped
from `2 + pages` to `1 + pages`, and an empty year now exports a single page.

**Files:** `lib/pdfexport.php`, `lib/layout_render.php`, `lib/repo.php`,
`public/layout.php`, `public/review.php`, `public/index.php`,
`public/api/year-projects-update.php`, `public/assets/layout.js`,
`public/assets/review.js`, `public/assets/styles.css`, `schema.sql`,
`DEPLOY.txt`, `docs/SCHEMA.md`, and four `tools/verify-*` scripts. The cover
band's millimetres were checked against three real exports (subtitle, no
subtitle, renamed); the browser side is still traced by hand, not clicked.

## Round 8 — one screen per project, and projects that need not be years

**Two screens became two tabs.** `review.php` and `layout.php` were separate
URLs with no way between them except going up to the list and back down. They
are two readings of one project, so they are the Content and Book tabs of
`project.php`, keyed by `id` rather than `?year=`. Both old URLs 302 with their
query strings intact — a 302, not a 301, because a permanent redirect is cached
forever and "forever" is a long time to be unable to take a merge back. The
templates moved into `lib/views/` unchanged apart from their links.

**A project can be a trip.** `year_projects.year` is nullable. The original
model was that a project IS a year: every `*_create()` derived one from the date
on the row being saved, and there was no other way to make one. Two rules
replace it.

The first is that an explicit `year_project_id` beats the date. That is what the
+ inside a project passes, so a photo taken last December lands in the book you
added it from. Without one the date still decides, which is what adding from the
project list does and what makes backfilling 2020–2025 practical.

The second is subtler and is the one worth remembering: **correcting a date
re-files content that was filed BY date, and leaves content placed BY HAND where
it is.** The test is whether the row still sits where its own date would have put
it. That fact is already in the data, so there is no `pinned` column on four
content tables and nothing to backfill — every row that exists today was filed
by date and reads as unpinned, which is correct. A yearless project can never
equal the date-derived answer, so a trip book never loses a photo to a typo
being fixed; that falls out of the rule rather than needing a case of its own.

The `UNIQUE KEY uniq_year` was deliberately left alone. Both MySQL and SQLite
permit any number of NULLs in a unique index, so it goes on enforcing one
project per year while placing no limit on how many trip books exist — exactly
the rule wanted, with no second index to say so. A sentinel year (0, or 9999)
was the alternative and it sorts, compares and groups as if it were real.

**`lib/page.php`, and the bottom bar is gone.** Four screens hand-rolled their
own doctype, head, header and tab bar, and had already drifted apart doing it —
`index.php` put Log out in `.head-actions`, `layout.php` put a Years link in the
same slot, `review.php` had neither. The tab bar had two entries: "Add" is now
the floating +, because adding is an action and not a place, and "Years" is the
back arrow. A fixed bar costing 56px of a phone screen to hold one link that
says "up" was not paying for itself, and removing it is what makes room for the
+ where a thumb actually is.

The list screen gets a hamburger (app-level: export everything, log out); a
project gets a kebab (its own: rename, export data, delete). Same glyph for the
same scope is the whole convention — ☰ means "this app", ⋮ means "this thing
here" — so a project screen has no hamburger at all. The kebab's three entries
are one module shared with the kebab on that project's card in the list;
`project-menu.js` is separate from `projects.js` precisely because the latter
wires the list screen on import, and a module with side effects is not
importable for its exports.

**Delete says what it is about to destroy.** "123 photos, 4 groups and 2
layouts", from a GET on the same endpoint, because a dialog that can be checked
against the project you meant is worth a round trip and "are you sure?" is not.
No typing to unlock it — Kathryn's call. The rows are the database's job (every
child cascades); the files are not, so they are collected before the DELETE and
unlinked after it, the same order `photos-delete.php` uses.

**Export data is JSON, `SELECT *`.** This is a backup, not an API: the failure
that matters is a column added next month quietly not being exported, and nobody
finding out until they need it. Photo files are not in it — `DEPLOY.txt` already
treats `uploads/` as a separate backup, and zipping a few hundred megabytes on
shared hosting is the same shape as the timeout PDF export had to be taught to
resume from. The paths are in it, so an export can be matched back up against a
copy of `uploads/`.

**The Book tab shows the book first.** Title/subtitle/cover collapse into a
`<details>` — four controls touched once per book, which sat above the version
list and the page grid. Create and Export are one row instead of two stacked
cards, with Export disabled until a layout is active, because that is what it
exports; disabled rather than hidden, so the order of the two steps stays
visible. Versions are a dropdown: nothing is ever overwritten, so after a few
tries the version list was the tallest thing on the screen and all but one row
of it was history.

**Pages move.** Drag the grip in a page header onto another page and the whole
page goes there — photos, captions and crops with it. The client sends the whole
new order rather than "page 7 to position 3": it already knows the answer, and
one renumber on the server beats two implementations of it.
`book_pages_reorder()` parks every page past the end of the book and brings it
back, because `uniq_layout_page` refuses the collision any one-pass renumber
walks into. A list that is not exactly the layout's pages is refused whole
rather than partly applied — a partial apply produces a shuffled book, which
looks like a bug in the engine rather than like a rejected request.

HTML5 drag-and-drop, matching the photo drag beside it, which makes this
desktop-only as that already is. Reordering does not survive regenerating, and
"Reflow from here" discards it from that page onward; both confirmations say so
now.

**View and type are two axes.** They were tangled: "groups" was a third VIEW, so
it could not be combined with the other two, and the type filter only existed
inside the grid — switch to Timeline and it silently vanished along with
whatever it was set to. Groups is a TYPE, because that is what it is. All twelve
combinations are real URLs and `verify-screens.php` renders every one. Type
defaults to All rather than Photos: with the filter permanently visible, a
default that hides three of the four content types without saying so is a filter
you have to notice before you can trust the screen.

**Entries open full screen.** Tapping one used to expand it in place, which on a
phone put the form half off the bottom of the screen and, in a grid of a hundred
photos, reflowed the whole grid under your thumb. `entry-modal.js` MOVES the
`<details>` into a fixed overlay and moves it back on close. It is the same
element, still in the document, so every delegated handler in `review.js` goes
on working without knowing the module exists. Rebuilding the form inside a
dialog would have meant a second copy of markup PHP already renders; cloning
would have left two elements with one `data-id` answering to the same handler.

A photo's three actions — In book / Skipped, Full page, Recrop — are one row
under the enlarged image. Recrop moved out of the form, where it was the only
control wearing a field label despite not being a field.

**`tools/verify-screens.php` is new and is the reason this pass is trustworthy.**
Every other verify file tests logic through repo functions with no template
involved, so a view referencing a variable the merge left behind passes all of
them and fails the first time the page is opened — and `php -l` cannot see it
either, because an undefined variable is a runtime warning. This one renders
each view against the harness database with warnings promoted to failures. Each
render runs in its own process: the views declare functions at the top level, as
the screens they came from always did, and wrapping every helper in
`function_exists()` to please a test would be the test dictating the shape of
the product.

**One `ALTER TABLE` on an existing install**, in `DEPLOY.txt` section 5. It is a
`MODIFY`, so running it twice is harmless.

## Round 9 — a snapshot is whatever the page needs to say

**Snapshots stopped being two fixed templates.** Brief §2.3 gave them nine
columns between them — `age`/`height` for a birthday,
`grade`/`school`/`teacher`/`favorite_color`/`dream_job`/`favorite_class` for a
school year — plus `notes` and a hero photo, with `type` saying which set was
populated. Kathryn wants a page for her own 40th next to Emma's 8th: "It might
be best to have a genericized input: Title, section title, section content."
A fixed column list cannot express that, and "Favorite class" is not a fact
about a fortieth birthday.

So a snapshot is now a **title, a hero photo, a date, and any number of
sections** — heading and body, in `snapshot_sections`. A real table rather than
a JSON column: it would otherwise be the only list in this schema that is not a
list of rows, and `sort_order` would become an array index maintained by hand.

`type` survives, and it is worth being precise about what it means. It no
longer says which columns exist, because there are none — it is the TEMPLATE an
entry was started from, and all it does is decide which headings get pre-filled
when you create one. Nothing reads it afterwards. It stayed because Kathryn
said she likes the dropdown, and starting a birthday with "Age" and "Height"
already typed is most of what she liked about it. `snapshot_update()` refuses
to change it: on a saved snapshot it would either do nothing or silently
rewrite the sections already on the page.

**Sections are replaced wholesale, never diffed.** The client sends the list it
is showing, in the order it is showing it, and that list is the answer — a
section has no identity beyond its position and nothing links to one. A diff
would need stable ids round-tripped through the form purely so the server could
work out what the client already knows. It also makes `sort_order` contiguous
by construction: it is the loop counter.

A row with neither a heading nor a body is a blank line the form left behind
and is dropped on save. A row with a body and no heading is kept — that is what
freeform `notes` was, and it is how `notes` migrates.

**The migration is a script, and it is rehearsed.** `tools/migrate-snapshot-
sections.php` maps each old column to its heading in the order the old page
showed them. It runs once against a database this build environment has never
seen, and it is the only thing standing between nine columns of typed-in facts
and an empty page — so `tools/verify-snapshot-migration.php` builds the OLD
table shape in the harness, fills it with rows of the kind the live database
holds, and checks the mapping, the ordering, and the re-run.

Three properties matter more than the mapping. It **skips any snapshot that
already has sections**, so a run that died halfway can simply be run again and
a snapshot edited since is never overwritten. It **does not drop the old
columns** — that is a separate statement in DEPLOY.txt, to be run after the
pages have been looked at, because a migration that destroys its own source
data in the same breath cannot be checked afterwards. And it is **split into a
library half and a CLI half**, guarded on being the invoked script: the first
version was a top-to-bottom run, and the test had to strip the runnable part
out with a regex and an `eval`, which is testing a rewrite of the script rather
than the script.

**Quotes take any name.** `who_said_it` was `ENUM('Kathryn','Emma')` and the
endpoint rejected everything else; the brief said a closed set of two with no
stated path to a third, and that was true until Kathryn wanted to record
something a grandparent or a teacher said. Now `VARCHAR(190)`, offered through
a `<datalist>` — the browser gives the dropdown, the filtering and the keyboard
behaviour for free, it degrades to a plain text box, and there is no widget to
keep working. The suggestions are `SELECT DISTINCT` off the quotes themselves,
so the list grows by being used. Not a `people` table: that is rows to create,
rename, merge and delete, and a screen to do it on, to hold a label on a quote.

**The hero picker was showing the wrong 24 photos.** It was "the last 24
uploaded", globally, on the reasoning that it is a quick picker and not the
year-browsing gallery. That is the wrong list for the one job it has: a hero
photo for a birthday page is a photo OF that birthday, which is nowhere near
the most recent 24 when a book is assembled months later. Scoped to the
project, newest first, no meaningful cap. The cover picker had the same bug and
got the same fix.

**The printed pages.** A snapshot page is two-up portrait — hero one side,
title and sections the other, headings bold and body copy left as it was. The
preview was drawing it stacked, which is a page the exporter was never going to
print; a preview that disagrees with the PDF is worse than no preview.

A quote on its own page gets **hanging quotation marks**: the opening mark sits
outside the text block's left edge so the first line aligns with the ones under
it. A negative `text-indent` cancelled by an equal `padding-left`, both in `em`
so they track the type size — not an absolutely positioned mark, which mPDF
will not place against a sibling's baseline, and not a two-cell table, whose
mark column stops matching the moment the type size changes.

**The type labels came off every printed page** — "I don't want the type of
content shown on the page". The pill in the page's TOOLBAR stays: it is screen
chrome for telling pages apart while dragging them into a new order, and it is
outside the drawn page. `verify-screens.php` checks the drawn page markup
specifically, not the whole document, so the two cannot be confused.

Anecdotes are **deliberately unchanged**. The plan offered bigger type and more
of the page; the answer was "keep it how it was ... I don't know how much I'll
use anecdotes anyway, so let's not invest time in that". They were split out of
the shared text renderer only so the quote could get its hanging marks without
dragging the anecdote along.

**One edit form for four content types, still.** The sections editor
(`sections.js`) is one module used by the add form and the edit modal, over
markup PHP renders identically in both — so a snapshot's rows are in the page
before any module runs, and the form still submits them with JS off.

**tools/page-lab.php, and what it caught immediately.** The two existing labs
prototype PHOTO pages and need a CSV export of a real library plus a folder of
thumbnails. The pages this round added are text with at most one hero, so a
third lab needs no export, no database and no arguments — it runs before an
upload, which is the point of it.

It renders the exporter's own output rather than a mockup: text pages go
through `pdf_render_page_html()` whole, and snapshot pages — which are
coordinate-drawn — get the same two rectangles computed from the same `$geo`,
with `pdf_render_snapshot_text_html()` filling one of them. A mockup that
drifts from the renderer is worse than no mockup.

Two things came out of looking at it, neither of which any test would have
found:

The first version of the lab drew quote pages by calling the standalone
renderer directly, skipping `pdf_render_text_page_html()`'s wrapper — so it
showed quotes at the top of a page they will never sit at the top of. A lab
that renders a different page from the exporter is the one failure mode that
makes it worse than useless.

And the real one: **the snapshot page was an HTML table in document flow, and
mPDF will not hold a height for one.** A birthday with three sections occupied
the top 40% of an 8.75in square page and left the rest blank — it read as a
page that had failed to finish. It is now coordinate-drawn like a photos page
(`pdf_draw_snapshot_page`), two panels at the full height of the content box,
45/55 with a gutter, the hero cropped to its column. `pdf_draw_photos_page()`
hit exactly this wall for exactly this reason years of commits ago; this is the
same answer, and `verify-pdf-geometry.php` now measures both panels the same
way it measures photo rectangles.


### Round 9, later: the pair is one block

> "If the text is longer than the image, I'd like the text to be top aligned
> with the image."

The page had both panels centred independently, which is right until the text
is the taller of the two — then it starts above the photo's top edge and ends
below its bottom, and the two halves stop reading as a pair.

The rule now is that **the hero and the text are one block, the block is as
tall as whichever panel is taller, and it is the block that gets centred on the
page.** Both panels start at its top. That covers both cases without a branch
and without a seam between them:

- text shorter than the hero — the block is the hero, so the hero sits centred
  exactly where it did before and the text centres against it;
- text longer — the block is the text, so the tops line up as asked, and the
  pair is still centred.

The obvious cheaper implementation is to leave the hero centred and hang the
text off its top edge. It was written that way first, and the page lab caught
it: a nine-section snapshot ran **19mm past the trim**, because hanging off a
centred hero throws away the top third of the page. `verify-pdf-geometry.php`
asserts the overflow the naive version would have produced, so the shortcut
cannot be reintroduced by someone who finds the block arithmetic fussy.

**This needs the text's height before either panel can be placed, and mPDF will
not tell you how big something is until it has drawn it.** So
`pdf_measure_html_height()` draws it — the same wrapper that gets drawn for
real, on a throwaway page three metres long where nothing can paginate — and
reads the flow position off the end. Same engine, same fonts, same width, so
the answer is the real answer rather than a characters-per-line estimate that
drifts the first time a heading wraps. It is cached, it is skipped when mPDF
is not installed, and the rectangles it feeds live in one pure function
(`pdf_snapshot_layout()`) that the exporter, the page lab and the tests all
read instead of keeping three copies of the arithmetic.

Measuring it turned up a bug that had already shipped:

**mPDF's `WriteFixedPosHTML` silently drops `margin` and `padding` on block
elements.** Every gap on the snapshot page — 2mm under the title, 6mm under the
date, 4.5mm between sections — was being thrown away in print, so every line
sat on the same 4.7mm rhythm and the date ran into the first heading. The page
lab did not show it because a browser honours the margins mPDF was discarding;
the numbers only came out when the measured height and the drawn height
disagreed by 40%. Table **cell padding** survives, so the text column is a
one-column table now and the gaps are cell padding, with none under the last
row — a trailing gap would offset the centring by half of itself. The test
reads the printed baselines back out of a real PDF and checks the gaps are
still there, because this is exactly the class of bug that looks fine in every
preview.

The on-screen preview mirrors it with a CSS table (`.ks-snapshot-grid`) rather
than flexbox, for the same reason the PDF needs a cell: `align-items: center`
centres the two columns independently, so a long text sits above the photo's
top edge instead of on it. A row with `vertical-align: top` on the hero and
`vertical-align: middle` on the text is the one construction that does both.

### Round 9, later still: the picker, and centring that costs something

Two things from real use.

**The photo picker's cells were drawing on top of each other.** Two independent
causes, both there since the picker was written:

`.sheet-panel button` dresses every button in a sheet as a full-width tappable
option row — `display:flex`, `min-height`, padding, border — and *a class plus
an element outranks a bare class*, so the `.photopicker-item` rules written to
undo all that had never applied. Not once. Scoping them to
`.sheet-panel .photopicker-item` is the whole fix for that half.

The other half is that the cell had no height the grid could see. `aspect-ratio`
on the button is resolved against its width, which the row sizer does not
consult, so rows came out short and items drew over them. `aspect-ratio` on the
image with `height:auto` made the row depend on when the image decoded — it
measured differently between two runs of the same page. A stated pixel height on
the image is the one thing the grid, the browser and the next reader all agree
about. The grid also gets `align-content: start`, because once enough photos
push it against its `max-height` the default alignment sizes auto rows to FILL
that height: five rows of 101px holding items 142px tall.

Four columns and `object-fit: contain` rather than three and `cover` — "I'm okay
with a smaller image (4 across) but I need to see the whole photo."

**Quotes and anecdotes are now centred in their boxes both ways**, which
**retires the hanging quotation mark** built two rounds ago. That is a real
cost and it was not a free choice: the mark hung outside the words so that every
line and the attribution shared one straight left edge, and centred lines have
no straight left edge for it to hang off. The options were centred text without
the hanging mark, or the mark with the text left-aligned inside a centred block
— which for a full-width anecdote is no visible change at all, and so would not
have been the thing that was asked for. Centring won; the mark is inline again;
the two-cell table and the CSS grid that held it out are deleted rather than
left doing something arbitrary. Both places say what to put back if it is ever
reverted.

The test block that asserted the hanging behaviour is replaced, not loosened.
Centring is hard to assert from a PDF because a text operator gives where a line
STARTS and not how wide it is, so it is measured differentially: set the same
page twice, once with a long quote and once with a short one, and the short one
must start further right. Left-aligned, the two are identical to the hundredth
of a point.

## Backlog — asked for, not built

Logged from real use, in Kathryn's words, with the shape of the work noted so
whoever picks one up is not starting from a one-line wish. Nothing here is
started; nothing here has a hook left in the code for it.

**1. Edit text from the book layout.** "On the book layout, allow me to edit
the text (click and it opens the detail page for the quote/anecdote/etc)."

Today a text slot on the Book tab is inert — to fix a typo in a quote you go to
the Content tab, find it, and open it there. The detail modal that would open
already exists and is already reachable from Content (`entry-modal.js`, opened
by `review.js` from a `.list-row`), so this is mostly a matter of making the
slot a click target and handing the modal a type and an id. Two things to be
careful of: the slot lives inside a page that is drag-reorderable, so a click
has to be distinguished from the start of a drag; and the modal already
dispatches `keepsake:entry-saved`, which the Book tab would need to listen for
so an edited quote re-renders in place rather than going stale until reload.

**2. Drop a photo out of the book from the layout.** "On the book layouts,
allow me to 'X' out a photo and remove it from the book (i.e. it switches its
tag to be 'skipped' and removes it from the page)."

Note what this is NOT: not a delete, and not a change to the layout by hand.
It sets the photo's own flag, which takes it out of the pool the layout engine
draws from. The page it was on therefore has a hole in it until the layout is
regenerated — so the honest version of this either reflows the affected page
immediately (the reflow-from-here machinery exists) or says plainly that it
will take effect on the next layout. Deciding which is the actual design
question here, and it should be decided before any of it is written.

No migration: the flag is `photos.skip_for_book` (a TINYINT, not a status
enum), `photo_update()` in lib/repo.php already writes it, `review.js` already
toggles it from the Content tab, and `layout_load_year_content()` already
excludes it. The work is a control on the Book tab plus the reflow decision
above.

**3. Show the hero photo on a snapshot's detail page.** ~~"Add a preview of the
image for the snapshot on its detail page."~~ **BUILT** — see below; kept here
because the reasoning is the record of why it needed a query change.

Today the detail form proves it worked by printing `Hero photo: #37` — an id,
which tells you a photo is attached and nothing about which one. The point of
picking a hero by hand is deciding whether it is the right picture, and that
decision cannot be made against a number.

Both halves need a thumbnail path the form does not currently have:

- *After picking*, `review.js`'s `pickHero()` already holds the whole photo row
  the picker resolved to, `thumb_url` included, so it only has to set an `<img>`
  src instead of writing text into `[data-role="hero-chosen"]`. Free.
- *On first render*, the form is built from `snapshots_for_year()`, which is a
  plain `SELECT * FROM snapshots` — the id is all it has. It needs the same
  `LEFT JOIN photos hero ON hero.id = s.hero_photo_id` that
  `book_layout_pages_with_content()` already does at lib/repo.php:2063, so the
  view can render the thumbnail on load rather than only after a fresh pick.

Worth doing at the same time: a way to REMOVE the hero once set. There is
currently no path back to no-photo short of editing the hidden input, and a
preview is exactly where you notice you picked the wrong one.

*Built.* `snapshots_for_year()` is no longer `SELECT *` — it left-joins the
photos table for `hero_thumb_path`/`hero_original_path`, so the form can draw
the photo on load and not only after a fresh pick. `pickHero()` sets the `<img>`
src from the row the picker already resolved to. The `<img>` is always in the
markup and it is the wrapper that hides, which keeps "picked one" and "removed
it" at one line of JS each. A Remove control went in at the same time: there was
no path back to no-photo short of editing the hidden input, and clearing is
local to the form, so it saves with everything else and a mis-tap costs a Cancel
rather than a photo. The fixture in `verify-screens.php` now gives one snapshot
a hero — without that, every snapshot in the suite was heroless and the preview
markup was never exercised, which is how a form that only ever printed an id
passed a green suite for four rounds.

**4. Re-roll one page's arrangement, in place.** "On the book layout, I want a
'refresh' button to let me try a different arrangement for one specific page. I
find myself wanting to switch between these two layouts (for PPP) but I can't
without redoing the whole book. I want to refresh it in the layout I'm working
in."

Reported against two real pages: the same three photos drawn as one big plus two
stacked, versus three equal across. Both are legitimate; today choosing between
them means generating a whole new layout version and accepting whatever it does
to every other page.

**The thing that makes this more than a button: a page's arrangement is not
stored anywhere.** `book_pages` holds the page number, the type, the snapshot id
and a caption override — and nothing about how its photos are placed. The
template is re-chosen at render time by `compose_candidates()` from the slots'
shapes plus a `$lastUsed` rotation, so the same page re-renders the same way by
recomputation rather than by memory. Press refresh and it would revert the
moment the page was drawn again.

So the work is a column before it is a control: somewhere on `book_pages` to
record "this page uses THIS template", which the renderer and the exporter both
honour when set and ignore when null. Null keeps today's behaviour exactly —
the rotation picks — so existing layouts are untouched and the migration is one
`ALTER TABLE`.

Then the button is small: cycle to the next candidate `compose_candidates()`
offers for that page's occupants, store its name, re-render the one page. Worth
deciding at the same time whether refresh CYCLES (predictable, and you can get
back to where you were) or picks at random — cycling is almost certainly right
for a page with two or three candidates, which is the common case.

One consequence to be deliberate about: a stored template is a hand edit, and
`book-layouts-reflow.php` currently rebuilds pages from scratch. Reflowing past
a page that has been refreshed should either preserve the choice or say plainly
that it will not.

## Round 10 plan — locked layouts, and the queue

Kathryn, after using the Book tab on a real book: **"I don't want 'reflow'. I do
want layouts to be locked when I create one and go through and rework them how I
like it."**

### The thing all of it turns on

A layout is *almost* already locked. `book_pages` and `book_page_slots` are real
rows written at generation, so which photos are on which page, in what order,
does not move on its own. **One thing is not stored: the arrangement.** The
template each page uses is recomputed at render time by `compose_assign()`, from
the slot shapes plus a rotation over the whole sequence — twice, once in
`lib/views/book.php` and once in `lib/pdfexport.php`.

`compose_assign()`'s own docblock defends that choice, and is worth quoting
because this round overturns it:

> a decision recomputed from current content cannot disagree with itself after a
> manual swap or a reflow moves photos around, where a stored one silently would

That was right when the layout was a thing the machine owned. It is wrong the
moment the layout is a thing Kathryn reworks by hand: swap two photos on page 4
and the arrangement of page 4 — and possibly of every page after it, through the
rotation — can change underneath her. Deriving is only safe while nothing edits;
storing is only safe once nothing regenerates. **Removing reflow and storing the
arrangement are the same decision, and neither is safe without the other.**

Storing it also happens to be the missing piece under two of the queued
features, which is why they are one round and not four.

### Phase 1 — freeze the arrangement (everything else depends on this)

- `book_pages` gains `template_name VARCHAR(40) NULL` and `template_order
  VARCHAR(60) NULL` (the occupant order as a comma-separated list, e.g. `0,2,1`).
  **Null means "decide it the old way"**, so every layout already on the server
  keeps rendering exactly as it does today and there is no data migration — one
  `ALTER TABLE` and nothing else.
- `layout_generate()` writes the choice for each page as it creates it.
- One new reader — `book_page_arrangement()` — replacing the `compose_assign()`
  loop in **both** `lib/views/book.php` and `lib/pdfexport.php`. Stored when
  present, derived when not. Two callers, one rule; today they each re-derive and
  agree only because they run the same function on the same input.
- Test: generate a layout, swap two photos, re-render — the arrangement of every
  page is byte-identical. That test fails today, which is the bug.

### Phase 2 — remove reflow

Delete, not deprecate: `public/api/book-layouts-reflow.php`,
`layout_reflow_from()` in `lib/layout.php`, the "Reflow from here" button in
`lib/views/book.php`, its handler in `public/assets/layout.js`, and the reflow
block in `tools/verify-layout.php`. The generate-a-new-version path stays — that
is how you start over, and it leaves the old version intact to compare against.

### Phase 3 — refresh one page (backlog 4)

With Phase 1 done this is small: an endpoint that asks `compose_candidates()`
for that page's occupants, takes the NEXT one after the stored name, writes it,
and re-renders the one page. **Cycles rather than randomises** — most pages have
two or three candidates, and cycling means you can always get back to the one you
preferred. The button sits where the reflow button was.

### Phase 4 — drop a photo out of the book (backlog 2)

Sets `photos.skip_for_book`, deletes that slot, and re-picks the arrangement for
that page's remaining occupants. The design question this was blocked on — what
happens to the hole it leaves — is answered by Phase 1: the page re-arranges
itself and nothing else in the book moves.

### Phase 5 — edit text from the layout (backlog 1)

Independent of the rest; last because it shares nothing with them. Click a text
slot, open the entry modal that Content already uses, listen for
`keepsake:entry-saved` to re-render in place. The care is in telling a click
apart from the start of a drag.

### Order and risk

1 → 2 → 3 → 4 → 5. Phase 1 is the only one with a schema change and the only one
that can break an existing book; it is deliberately first and deliberately
null-defaulted so that it cannot. Phases 3, 4 and 5 are each independently
shippable after it.

### Round 10, built: Phases 1 and 2

**Phase 1 — the arrangement is frozen.** `book_pages` gained `template_name` and
`template_order`, both nullable; `layout_generate()` writes them through a new
`layout_freeze_arrangements()` pass; and one reader,
`layout_page_arrangements()`, replaced the `compose_assign()` loop that the
preview and the exporter each kept a copy of. Null still means "decide it the
old way", so every layout already on the server renders exactly as before.

Two things worth recording because neither was visible from the plan:

*The freeze has to read the JOINED rows.* `book_pages_for_layout()` returns
`book_page_photos` rows alone, with no width or height on them, so
`compose_occupants()` sees every slot as a shapeless wildcard. Freezing from
that view would have written an arrangement chosen without knowing which photos
were portrait, and it would then have disagreed with the preview and the
exporter, which both read `book_layout_pages_with_content()`. Found by
disabling the stored path to check the new test could fail — and discovering it
could not, because no slot the test could see had a shape at all.

*The obvious version of the test is vacuous.* "Swap two photos and the
arrangement does not change" passes against the bug if the two photos are the
same shape, because the derived answer would not have changed either. The test
now insists on swapping a portrait for a landscape, and that requirement is
written down beside it. Verified both ways: it fails with the stored path
disabled and passes with it.

**Phase 2 — reflow is gone.** `public/api/book-layouts-reflow.php`,
`layout_reflow_from()`, the button, its JS, and its test block are deleted. Two
parameters it used to be the only caller of — `layout_plan()`'s `$historySeed`
and `layout_load_year_content()`'s `$exclude` — are kept, documented as always
empty now, because they are the seams anything that appends to an existing book
would need. `compose_assign()`'s docblock, which argued FOR deriving rather than
storing, now records that the argument was overturned and why.

Phases 3, 4 and 5 (refresh one page, drop a photo, edit text from the layout)
are unstarted and independently shippable.

### Round 10, corrected: adapt, then save — and cycle

Phase 1 as first built was **too rigid**, and Kathryn caught it from the check
instructions rather than the code:

> "I liked that the layout would update based on the photo I dragged into it. I
> don't want to lose that. I just want to save the layout after I've altered it.
> And I want to be able to cycle through the different versions of the layouts
> for those types of photos if I don't like the one it landed on."

Freezing the arrangement outright meant dragging a landscape onto a page of
portraits left it drawn as though the landscape were a portrait. The thing worth
keeping was never "the page never changes" — it was "the page does not change
BEHIND MY BACK", and specifically that a change on page 4 must not redraw page 9.

The fix is to record what an arrangement was chosen FOR. `template_order` now
holds `"PLL:0,2,1"` — the shape signature, then the order — and a stored
arrangement is ignored the moment the page's shapes no longer match it. So:

- drag a landscape onto a page of portraits and **that page re-arranges around
  it**, exactly as before;
- the swap and move endpoints then re-settle the layout, so the new arrangement
  is **saved** rather than re-derived on every future render;
- and **every other page keeps what it had**, because its signature still
  matches — which is the property the round existed for.

One column, not a third: the two-column `ALTER TABLE` had already shipped, and a
mid-deploy change of mind should not cost a second migration.

**Cycling (backlog 4) came with it**, since it is the same machinery:
`layout_cycle_arrangement()` steps one page to the next template its photos
allow and saves it, with the button where "Reflow from here" used to be. It
cycles rather than randomising, and the test proves the property that makes that
worth doing — a full turn visits every arrangement exactly once and lands back
where it started, so you can always get back to the one you liked. A page whose
photos only fit one template says so instead of appearing to do nothing.

Remaining: drop a photo out of the book (backlog 2), and edit text from the
layout (backlog 1).
