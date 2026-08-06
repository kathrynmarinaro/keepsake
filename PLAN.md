# Keepsake — Build Plan

> **Read this file first, every session.** It is the single source of truth for
> where the build stands. Because Claude Code on the web runs in ephemeral
> containers, nothing survives between sessions except what's committed to
> git — so this file (plus the code and commit history) *is* the memory of
> the project across usage-limit resets and multi-day work.

> **▶ RESUMED (as of 2026-08-06).** The sibling repos `kathrynmarinaro/inspiration`
> and `kathrynmarinaro/personal-cms` were reachable this session (checked out
> locally alongside this one), and the reconciliation pass this file's pause
> note called for is done — see "Suite conventions" below for what changed.
> Phase 1 proceeded immediately afterward, on explicit instruction to do both
> in one session; see the Status Tracker at the bottom for where that landed.
> If you're a future session picking this up mid-phase, `git log --oneline`
> is more current than this prose.

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
3. **Login page markup/flow, matched to Personal CRM's shape**, not copied
   wholesale: Personal CRM's login is a single site-wide password with no
   username, stored as `password_hash` directly in `config.php`. Keepsake
   keeps a real `username` + `password_hash` **table** instead (Phase 0's own
   decision) — see item 6 below and `lib/auth.php`'s header comment for why
   that one piece was deliberately *not* replaced. Everything else about the
   login screen (markup, `.login-*` classes, the safe-redirect guard, failing
   open when unconfigured) now matches the sibling pattern.
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
   gone; the `users` table now lives directly in `schema.sql`, written
   `CREATE TABLE IF NOT EXISTS` so re-applying the file is always safe — no
   runner needed, matching `personal-cms/schema.sql` and
   `inspiration/schema.sql`.
8. **`scripts/` renamed to `tools/`.** `seed_user.php` moved and rewritten
   against the new `lib/`, behavior unchanged (upsert by username, CLI-only).
   No `apply-schema.php` was added: neither sibling has one either — both
   just document `mysql -u root <db> < schema.sql` in prose, so Keepsake does
   the same rather than inventing a new pattern (README's Setup section).

**One deliberate non-adoption, flagged rather than silently decided:**
Keepsake keeps its own `users` table (`id`, `username`, `password_hash`)
instead of switching to the siblings' single `password_hash` value in
`config.php`. The task that drove this reconciliation pass was explicit that
auth *semantics* should be preserved through the code-shape rewrite, and a
real table was Phase 0's considered choice, not an oversight — a username
costs nothing extra for a single-user app, and it's orthogonal to the
function-vs-class question the rest of this pass exists to fix. What *was*
adopted from the siblings: **the gate now fails open** when no user is
seeded yet (`auth_is_configured()`), matching every sibling's "an
unconfigured deploy must not be able to lock you out of your own app"
stance — Phase 0 hadn't implemented that at all. Login throttling
(`personal-cms`'s escalating-delay curve and `login_attempts` table) was
**not** ported in this pass — flagged in `lib/auth.php` as worth adding
before this app is reachable on the open internet, but out of scope for a
pass whose job was code shape, not new behavior.

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
of the brief: within each event group, sub-group photos by day/close
timing; arrange onto pages using orientation-matching as primary driver,
with a variety heuristic that varies page density (1–4 photos) so the book
doesn't monotonously repeat one layout; manually flagged 'full page'
photos always get their own page; standalone text entries in an event's
date range occupy a page slot as a styled card unless they exceed the
tunable ~180-character threshold (then they get a full page); photo+text
bundles render the text as a caption within the photo's existing slot, not
a separate slot; snapshot entries always get their fixed full-page
template regardless of surrounding grouping. Persist layout as versioned
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
eventual public GitHub audience in mind per the brief's Section 'Purpose'."
**Exit criteria**: fresh clone + documented setup steps actually works;
`git log -p | grep`-style secret scan is clean; README is something a
stranger could follow.

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
- [ ] Phase 2 — Capture flow (mobile-first)
- [ ] Phase 3 — Review/browse (desktop)
- [ ] Phase 4 — Event grouping & geocoding
- [ ] Phase 5 — Book layout engine
- [ ] Phase 6 — Page review UI
- [ ] Phase 7 — PDF export
- [ ] Phase 8 — Polish & open-source readiness

**Last updated**: 2026-08-06 (Phase 1 complete, in the same session as the
Phase 0 reconciliation pass above. `schema.sql` now holds year_projects,
event_groups, photos, quotes, anecdotes, photo_text_bundles, snapshots,
geocode_cache, book_layouts, book_pages and book_page_photos alongside
Phase 0's `users` table. Documented in `docs/SCHEMA.md`. Year isolation
verified with `tools/verify-schema.php` — ported `test-harness.php` from
personal-cms, seeded two year_projects with one of everything each, all
checks pass, including for the tables that derive their year through a
parent row instead of storing it, and including cascade-delete staying
inside its own year. Next: Phase 2's capture flow, which is also the point
`PLAN.md`'s Suite Conventions section flagged as needing the Inspiration
Board upload/crop component specifically — pull it from
`kathrynmarinaro/inspiration` rather than building a new one.)
