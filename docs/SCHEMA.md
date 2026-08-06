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
2. **Derives its year unambiguously through a parent** — `photo_text_bundles`
   (via `photo_id`), `book_pages` and `book_page_photos` (via `book_layout_id`,
   one or two joins up to `book_layouts.year_project_id`). These are always
   children of a row from group 1, so a second copy of the year would only
   ever risk drifting from the parent's.
3. **Shared reference data, not year-scoped at all** — `geocode_cache`,
   `users`. Neither is "content"; a lat/lon resolves to the same place name
   regardless of which year asked, and a login isn't anyone's memory of 2024.

`tools/verify-schema.php` proves this holds: it seeds two year_projects with
one of everything each, confirms every group-1 and group-2 table resolves
each row to the correct year and never the other one, and confirms deleting
a year_project cascades everywhere inside that year and nowhere outside it.

## Tables

### `users` (auth, not year-scoped)
One row per allowed login (`username`, `password_hash`). See
`lib/auth.php` for why Keepsake keeps a real table here instead of the
siblings' single `password_hash` config value.

### `year_projects`
The root. One row per calendar year: `year` (unique), `subtitle`,
`cover_photo_id` and `active_book_layout_id` (both plain nullable pointers,
deliberately not foreign keys — see schema.sql). No status column; status is
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
`caption`, `skip_for_book` (default included), `full_page`, `event_group_id`
(nullable, `SET NULL` on group delete).

### `quotes`
`year_project_id`, `quote_text`, `who_said_it` (`ENUM('Kathryn','Emma')` —
a closed set of two, not a lookup table, per the brief), `entry_date` (DATE;
defaults to submission date, editable).

### `anecdotes`
`year_project_id`, `anecdote_text`, `entry_date`. Same shape as `quotes`
minus `who_said_it` — an anecdote has no speaker attribution.

### `photo_text_bundles`
Junction that turns a quote or anecdote into a photo's caption instead of a
standalone entry. `photo_id` (unique), exactly one of `quote_id` /
`anecdote_id` (unique each, enforced by a `CHECK`). No `year_project_id` —
derived through `photo_id`. A quote/anecdote's presence *in this table* is
what makes it "bundled"; there's no flag on `quotes`/`anecdotes` to keep in
sync separately.

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
One row per generated interior page (cover/title pages are NOT rows here —
they're read straight off `year_projects` at export time). `book_layout_id`,
`page_number` (unique per layout), `page_type` (`photos` / `text` /
`snapshot`), `snapshot_id` (set only when `page_type = 'snapshot'`, enforced
by a `CHECK`). No `year_project_id` — derived through `book_layout_id`.

### `book_page_photos`
One row per filled slot on a page (1–4 for `page_type = 'photos'`, exactly 1
for `page_type = 'text'`). `book_page_id`, `slot_number` (1–4, `CHECK`-ed),
exactly one of `photo_id` / `quote_id` / `anecdote_id` (`CHECK`-ed — a slot
is usually a photo, occasionally a short standalone text card sharing the
page per brief §4.3). No caption column: a photo slot's caption comes from
`photos.caption` or a `photo_text_bundles` row, both already resolvable from
`photo_id` alone. No `year_project_id` — derived through `book_page_id` →
`book_layout_id`.

## Relationships (text form)

```
year_projects (1) ──< event_groups
year_projects (1) ──< photos ──> event_groups (nullable, SET NULL)
year_projects (1) ──< quotes
year_projects (1) ──< anecdotes
year_projects (1) ──< snapshots ──> photos (hero_photo_id, nullable, SET NULL)
year_projects (1) ──< book_layouts

photos (1) ──1 photo_text_bundles ──1 quotes    (exactly one of these two)
                                  └─1 anecdotes

book_layouts (1) ──< book_pages ──> snapshots (nullable, only page_type='snapshot')
book_pages (1) ──< book_page_photos ──> photos    (exactly one of these three)
                                     ├─> quotes
                                     └─> anecdotes

year_projects.cover_photo_id ─ ─ ─▶ photos.id           (pointer, not an FK)
year_projects.active_book_layout_id ─ ─ ─▶ book_layouts.id  (pointer, not an FK)

geocode_cache                                    (standalone; not year-scoped)
users                                            (standalone; not year-scoped)
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
