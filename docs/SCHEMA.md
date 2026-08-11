# Keepsake — schema reference

Table-by-table summary of `schema.sql`. This is the quick-reference map —
`schema.sql` itself carries the *why* behind every non-obvious decision (why
a column is nullable, why something isn't a foreign key, why a table has no
`year_project_id` at all); read this for the shape, read the schema file's
comments for the reasoning. Nothing below should ever disagree with
`schema.sql` — if it does, `schema.sql` is right and this file is stale.

## Year isolation, in one paragraph

Every table below is one of three shapes:

1. **Carries `year_project_id` directly** — `event_groups`, `photos`,
   `quotes`, `anecdotes`, `snapshots`, `book_layouts`. These are rows Kathryn
   (or an algorithm acting on her behalf) creates independently; nothing else
   could carry the year for them.
2. **Derives its year unambiguously through a parent** — `book_pages` and
   `book_page_photos` (via `book_layout_id`, one or two joins up to
   `book_layouts.year_project_id`). These are always children of a row from
   group 1, so a second copy of the year would only ever risk drifting from
   the parent's.
3. **Shared reference data, not year-scoped at all** — `geocode_cache`,
   `login_attempts`. Neither is "content"; a lat/lon resolves to the same
   place name regardless of which year asked, and a login attempt isn't
   anyone's memory of 2024.

`tools/verify-schema.php` proves this holds: it seeds two year_projects with
one of everything each, confirms every group-1 and group-2 table resolves
each row to the correct year and never the other one, and confirms deleting
a year_project cascades everywhere inside that year and nowhere outside it.

## Tables

### `login_attempts` (auth, not year-scoped)
No `users` table — the password itself lives in `config.php` as
`password_hash`, matching every sibling app. `login_attempts` exists purely
for login throttling: one row per attempt (`ip`, `succeeded`, `attempted_at`),
pruned opportunistically. See `lib/auth.php` for the throttle curve.

### `year_projects`
The root. One row per calendar year: `year` (unique), `title`, `subtitle`,
`cover_photo_id` and `active_book_layout_id` (the last two plain nullable
pointers, deliberately not foreign keys — see schema.sql). `title` is NULL
until the book is renamed, and NULL means "call it by its year" — read it
through `year_project_title()`, never directly. No status column; status is
derived from what exists underneath a year, not stored.

### `event_groups`
A cluster of photos from one outing. `year_project_id`, `name` (auto or
manually renamed), `start_date`/`end_date` (re-derived from members),
`location_name` (from `geocode_cache`, or NULL), `is_manual_name` (protects a
rename from being overwritten by a later auto-naming pass). Created before
`photos` in the file because `photos.event_group_id` needs it to exist.

### `photos`
`year_project_id`, `original_path` (kept — Phase 7 needs full resolution),
`thumb_path` (nullable until processed), `width`/`height` (orientation is
derived from these, not stored separately), `captured_at` (DATETIME — EXIF
date+time or submission time, editable; time-of-day matters for Phase 5's
day/close-timing sub-grouping), `gps_lat`/`gps_lon`, `location_text`
(manually typed, independent of the event group's `location_name`),
`caption` (the ONLY captioning mechanism a photo has, typed during upload —
see below), `skip_for_book` (default included), `full_page`, `event_group_id`
(nullable, `SET NULL` on group delete).

### `quotes`
`year_project_id`, `quote_text`, `who_said_it` (`ENUM('Kathryn','Emma')` —
a closed set of two, not a lookup table, per the brief), `entry_date` (DATE;
defaults to submission date, editable).

### `anecdotes`
`year_project_id`, `anecdote_text`, `entry_date`. Same shape as `quotes`
minus `who_said_it` — an anecdote has no speaker attribution.

**No `photo_text_bundles` table.** An earlier version of this schema had
one — a junction turning a quote or anecdote into a photo's caption instead
of a standalone entry. Removed on request: a quote or anecdote is never
attached to a photo. `entry_date` is the only thing that relates a
quote/anecdote to nearby photos (Phase 5's layout groups by date), and a
photo's only caption mechanism is its own `caption` column, typed directly
during upload.

### `snapshots`
One table for both templates (`type` = `birthday` or `school_year`).
Shared: `year_project_id`, `entry_date`, `hero_photo_id` (nullable, `SET
NULL` on photo delete), `notes`. Birthday-only: `age`, `height` (free text).
School-year-only: `grade`, `school`, `teacher`, `favorite_color`,
`dream_job`, `favorite_class`. No cross-field CHECK forcing the other
template's columns NULL — see schema.sql for why that's a deliberate
omission, not an oversight.

### `geocode_cache` (reference data, not year-scoped)
Reverse-geocoding cache keyed on `(lat_rounded, lon_rounded)`, rounded to 3
decimal places (~110m) by the **column type itself** (`DECIMAL(6,3)` /
`DECIMAL(7,3)`), not by convention. `location_name` is what Nominatim
returned. Shared across every year, same category as Grocery's
`grocery_dictionary`.

### `book_layouts`
One row per "Create Book Layout" run. `year_project_id`, `version` (1, 2, 3…
per year, computed in app code, unique per year). Regenerating never deletes
an older version — it inserts a new row.

### `book_pages`
One row per generated interior page (the cover is NOT a row here — it is read
straight off `year_projects` at export time, and it is the only front matter
the book has). `book_layout_id`,
`page_number` (unique per layout), `page_type` (`photos` / `text` /
`snapshot`), `snapshot_id` (set only when `page_type = 'snapshot'`, enforced
by a `CHECK`). No `year_project_id` — derived through `book_layout_id`.

### `book_page_photos`
One row per filled slot on a page (1–4 for `page_type = 'photos'`, exactly 1
for `page_type = 'text'`). `book_page_id`, `slot_number` (1–4, `CHECK`-ed),
exactly one of `photo_id` / `quote_id` / `anecdote_id` (`CHECK`-ed — a slot
is usually a photo, occasionally a short standalone text card sharing the
page per brief §4.3). No caption column: a photo slot's caption comes from
`photos.caption` alone. No `year_project_id` — derived through
`book_page_id` → `book_layout_id`.

`crop_x`/`crop_y`/`crop_w`/`crop_h` (nullable `DECIMAL(6,5)`, added
post-launch — see `PLAN.md`): Kathryn's manual "adjust crop" override for a
photo slot, normalized 0–1 fractions of `photos.original_path`, same
convention as `imageproc_crop_photo()`'s rect. NULL (the default) means
"auto-fit to this slot's own shape" — see `lib/layout_render.php`'s
`layout_auto_crop_rect()`. Non-destructive: unlike `imageproc_crop_photo()`,
which bakes a crop into new `original_path`/`thumb_path` files, these columns
only affect how THIS placement of the photo is windowed on THIS page.
Meaningless for a text-card slot; not `CHECK`-enforced (see the column's own
comment in `schema.sql`) since the renderer simply never reads it there.

## Relationships (text form)

```
year_projects (1) ──< event_groups
year_projects (1) ──< photos ──> event_groups (nullable, SET NULL)
year_projects (1) ──< quotes
year_projects (1) ──< anecdotes
year_projects (1) ──< snapshots ──> photos (hero_photo_id, nullable, SET NULL)
year_projects (1) ──< book_layouts

book_layouts (1) ──< book_pages ──> snapshots (nullable, only page_type='snapshot')
book_pages (1) ──< book_page_photos ──> photos    (exactly one of these three)
                                     ├─> quotes
                                     └─> anecdotes

year_projects.cover_photo_id ─ ─ ─▶ photos.id           (pointer, not an FK)
year_projects.active_book_layout_id ─ ─ ─▶ book_layouts.id  (pointer, not an FK)

geocode_cache                                    (standalone; not year-scoped)
login_attempts                                   (standalone; not year-scoped)
```

`──<` is one-to-many, `──1`/`──` is one-to-one-ish (a unique FK), `─ ─ ─▶` is
a plain pointer column with no foreign-key constraint (documented reasons in
`schema.sql`).

## Verifying the schema

```
mysql -u root keepsake < schema.sql          # the real target
php tools/verify-schema.php                  # SQLite smoke test + year-isolation proof, no MySQL needed
```

`tools/verify-schema.php` loads `schema.sql` through `tools/test-harness.php`
(the same SQLite-translation approach the sibling apps use — see that file's
header before touching either), seeds two year_projects with one of every
content type each, and asserts every query scoped to one year is blind to
the other's rows — including for the tables that derive their year instead
of storing it, and including that deleting a year_project cascades inside
its own year and nowhere else.
