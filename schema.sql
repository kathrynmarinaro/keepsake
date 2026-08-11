-- Keepsake — schema
-- Load with:  mysql -u root keepsake < schema.sql
--
-- MySQL/MariaDB is the production target: InnoDB, utf8mb4, every DATETIME
-- default is CURRENT_TIMESTAMP so the server clock is the only clock. Note
-- that tools/test-harness.php translates this file into SQLite for the test
-- run, because the build environment has no MySQL. That translation is a
-- TEST convenience and proves nothing about MySQL — it is not a second
-- supported backend, and this file must stay written for MySQL. See
-- tools/test-harness.php's own header before editing this file: never make a
-- construct easier for the translator by weakening what's written here —
-- teach the translator instead.
--
-- Single schema file, no migrations directory: matching the rest of the
-- suite (Grocery, Personal CRM, Inspiration Board), which each ship one
-- schema.sql with no migration runner. Every CREATE TABLE is IF NOT EXISTS
-- so re-applying this file is safe.


-- ========================================================== AUTH ============

-- ----------------------------------------------------------------- login_attempts

-- No `users` table: Keepsake is one person, one password, and the password
-- itself lives in config.php as 'password_hash' — matching every sibling app
-- exactly (Phase 0 originally built a real users table with a username; that
-- divergence has since been dropped on request, see lib/auth.php).
--
-- login_attempts exists purely for throttling. One row per attempt, pruned
-- opportunistically by auth_record_attempt(). Counting has to be keyed to the
-- client address server-side: a session counter protects nothing, because an
-- attacker simply discards the cookie between guesses. Shape matches the
-- sibling apps exactly (ported from Personal CRM, which ports it from
-- Grocery) — lib/auth.php's queries assume these column names.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL,     -- 45 chars covers IPv6
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),

  -- Serves auth_throttle_state():
  --   SELECT COUNT(*) ... WHERE ip = ? AND succeeded = 0
  --                         AND attempted_at > NOW() - INTERVAL ? MINUTE
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===================================================== YEAR PROJECTS =========
--
-- THE YEAR-ISOLATION RULE (PLAN.md "Architecture decisions"): every table
-- below that holds real content resolves to exactly one year_project_id,
-- either as a real column or as an unambiguous derivation through a parent
-- row — documented at each table, never left to be worked out later. This is
-- what makes "regenerate 2024's book" a query that can never touch a 2023 or
-- 2026 row by construction, not by careful WHERE-clause discipline.
--
-- CHECK CONSTRAINTS ARE USED IN A FEW PLACES BELOW (book_page_photos,
-- book_pages) to make the database enforce an invariant
-- that would otherwise be "every caller has to remember it" — same reasoning
-- as Personal CRM's reminder_sends composite key. This assumes MySQL 8.0.16+
-- or MariaDB 10.2.1+, both of which enforce CHECK; older versions parse and
-- silently ignore it. Worth confirming against the real deploy target before
-- this app holds anything Kathryn would notice going wrong.

-- ------------------------------------------------------------- year_projects

-- One row per calendar year Kathryn is capturing or has captured. The root
-- of the isolation rule above: every other content table either points here
-- directly or through a table that does.
CREATE TABLE IF NOT EXISTS year_projects (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- The calendar year this book covers, and NULL when it doesn't cover one.
  --
  -- NULLABLE, which it did not used to be. The original model was that a
  -- project IS a year: year_project_get_or_create() derived it from the date
  -- on whatever was being saved, and there was no other way to make one.
  -- Kathryn asked to be able to make a book for a trip — content she picks by
  -- hand rather than content that happens to share a year — so "which project
  -- does this belong to" had to stop being a function of the date. NULL here
  -- is that: a project with no year, whose membership is only ever explicit.
  --
  -- NULL rather than a sentinel year (0, or 9999): those sort, compare and
  -- GROUP BY as if they were real years, so every query that touches this
  -- column would need to remember to exclude them, and the one that forgets
  -- files a trip book under the year 9999 forever. NULL is refused by
  -- comparison rather than quietly succeeding, which is the behaviour that
  -- catches the mistake.
  --
  -- A project with no year MUST have a title (the app enforces it in
  -- year_project_create(); the database can't express "one of these two" as a
  -- constraint without a CHECK that MariaDB versions disagree about). That is
  -- why year_project_title() can still promise to return something.
  year            SMALLINT UNSIGNED NULL,

  -- The book's name on the cover. NULL — the default — means "use the year",
  -- which is brief §4.6's "Title: defaults to the year". Kathryn asked to be
  -- able to change it after using the app for a while, so it is now a real
  -- column instead of being derived from `year` at every render.
  --
  -- NULL rather than a copy of the year written at creation time: a row whose
  -- title happens to read "2025" cannot then be told apart from one she typed
  -- "2025" into on purpose, and a book she never renamed should follow the
  -- year column if that is ever corrected. Empty string is normalized to NULL
  -- on the way in (see year_project_update_title) so there is exactly one way
  -- to say "no title of my own".
  --
  -- 190, like subtitle, is the utf8mb4 index-safe width the suite uses
  -- everywhere; nothing indexes this, but a cover title long enough to need
  -- more than 190 characters is not a cover title.
  title           VARCHAR(190) NULL,

  subtitle        VARCHAR(190) NULL,

  -- Cover photo, manually selected (brief §4.6: "not auto-selected", same
  -- pattern as a snapshot's hero photo). DELIBERATELY NOT A FOREIGN KEY: it
  -- points at photos.id, and photos.year_project_id points back here, which
  -- would make this the second half of a circular FK dependency between two
  -- CREATE TABLEs. Same call Personal CRM makes for import_drafts.dup_person_id
  -- — a single admin-selected pointer written by app code that already knows
  -- it's picking from this year's own photos, not something that needs the
  -- database to police. NULL until Kathryn picks one.
  cover_photo_id  INT UNSIGNED NULL,


  -- Kathryn's crop of the COVER photo, normalized 0..1 like every other crop
  -- in this app (crop.js, book_page_photos.crop_x/y/w/h).
  --
  -- NULL, the default, means "centre-crop to the page shape". The cover is the
  -- one place in the book that fills its frame rather than showing the whole
  -- photo — a cover with white edges is not a cover — so SOMETHING is always
  -- cropped off a photo that is not square, and this is how she says what.
  --
  -- On year_projects rather than on a slot because the cover is not a page:
  -- it has no book_pages row, is not part of a layout version, and survives
  -- regenerating the book. Putting it here means choosing a cover and framing
  -- it are the same kind of decision, kept in the same place, and neither is
  -- lost by a reflow.
  cover_crop_x     DECIMAL(6,5) NULL,
  cover_crop_y     DECIMAL(6,5) NULL,
  cover_crop_w     DECIMAL(6,5) NULL,
  cover_crop_h     DECIMAL(6,5) NULL,
  -- The book layout Kathryn is actively reviewing/exporting, when more than
  -- one exists for this year (brief §4.5: layout generation "can be re-run
  -- multiple times ... to preview/compare"). Same non-FK reasoning as
  -- cover_photo_id: book_layouts.year_project_id already points here, and
  -- this is a single pointer among that year's own layout rows, not a
  -- relationship the database needs to police. NULL until a layout exists.
  --
  -- Deliberately NOT "the highest version number" computed on the fly:
  -- Kathryn might generate v2, dislike it, and want v1 to stay the active one
  -- to keep reviewing — "most recent" and "the one I'm working from" are
  -- different facts once more than one exists.
  active_book_layout_id INT UNSIGNED NULL,

  -- NO STATUS COLUMN. "In progress" / "not started" / "ready to export" is a
  -- fact derivable from whether the year has any content, any event groups,
  -- any book_layouts row, etc. — deriving it in a dashboard query costs a
  -- handful of EXISTS() checks and can never drift from the tables it
  -- describes; storing it as a column would be a second place to remember
  -- to update every time content changes, and the entire failure mode above
  -- (a reminder that silently doesn't fire) is what the suite's "make it the
  -- database's job" rule exists to prevent.
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- One project per calendar year. Also what makes an accidental double
  -- "New year project" click a no-op instead of a duplicate.
  --
  -- Still correct now that `year` is nullable, and deliberately unchanged:
  -- both MySQL and SQLite permit any number of NULLs in a UNIQUE index, so
  -- this goes on enforcing "at most one project per year" for the projects
  -- that have one while placing no limit at all on how many trip books exist.
  -- That is exactly the rule wanted, and it needs no second index to say so.
  UNIQUE KEY uniq_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================== EVENTS =============

-- -------------------------------------------------------------- event_groups

-- A cluster of photos from the same outing, auto-detected by date-gap
-- (brief §4.1) and freely renamed/merged/split by hand afterwards. Created
-- BEFORE photos below despite being populated mostly by an algorithm that
-- reads photos, because photos.event_group_id needs somewhere to point — the
-- table has to exist first even though it's usually empty until Phase 4 runs.
CREATE TABLE IF NOT EXISTS event_groups (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id INT UNSIGNED NOT NULL,

  -- Auto-generated as "date range + location" (brief §4.1), e.g.
  -- "Jul 4–6 · Myrtle Beach", falling back to the date range alone with no
  -- GPS. Free text, not two columns (date range + location name) kept
  -- separately: the moment Kathryn renames a group by hand ("Fourth of July
  -- at the lake house"), the auto-derived pieces stop being meaningful parts
  -- of the string and it's just a name again.
  name           VARCHAR(190) NOT NULL,

  -- Re-derived from member photos' captured_at every time the grouping
  -- engine runs (brief §4.1's date-gap detection) or a merge/split changes
  -- membership — NOT the primary record of which photos belong here (that's
  -- photos.event_group_id, read the other direction). Kept as columns rather
  -- than computed with MIN/MAX(photos.captured_at) on every read because the
  -- event list is read far more often than groups change membership, and
  -- because a group can briefly have zero members mid-split with nothing to
  -- aggregate from.
  start_date     DATE NOT NULL,
  end_date       DATE NOT NULL,

  -- Snapshot string from reverse-geocoding (see geocode_cache below), or
  -- NULL when no member photo carried GPS. NOT a foreign key or a lat/lon
  -- pair: geocode_cache is keyed on rounded coordinates and may serve many
  -- groups across many years, and this column only needs the resolved text
  -- that went into `name` at generation time.
  location_name  VARCHAR(190) NULL,

  -- Set the moment Kathryn renames a group by hand, and never cleared
  -- automatically. This is what stops a later re-run of the auto-naming pass
  -- (brief §4.1, "iteration expected" per §7) from silently overwriting a
  -- rename — the engine must check this flag and skip `name` (though it may
  -- still update start_date/end_date/location_name) for any group where it's
  -- set. One boolean is enough here, unlike Grocery's grocery_corrections
  -- table: there's exactly one mutable field to protect (the name), not a
  -- whole dictionary of independently-correctable rows.
  is_manual_name TINYINT(1)   NOT NULL DEFAULT 0,

  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the year's event-review list, chronological:
  --   SELECT * FROM event_groups WHERE year_project_id = ? ORDER BY start_date
  KEY idx_year_date (year_project_id, start_date),

  CONSTRAINT fk_eg_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================== CONTENT ============
--
-- Four content types (brief §2): quotes, anecdotes, snapshots and photos.
-- Each is independently created by Kathryn and each carries its OWN
-- year_project_id — deliberately not derived from anything, because each one
-- is exactly the kind of row the isolation rule is protecting: something a
-- capture-flow screen inserts on its own, with no other row that could carry
-- the year for it.

-- ------------------------------------------------------------------- photos

CREATE TABLE IF NOT EXISTS photos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id  INT UNSIGNED NOT NULL,

  -- Relative to public/, same convention as Inspiration Board's images
  -- table: "uploads/original/<name>.jpg". Kept (not deleted after
  -- thumbnailing, unlike Inspiration's original_path) because Phase 7's PDF
  -- export needs full resolution — this app's "original" is a keeper, not a
  -- working file to be discarded once a thumbnail exists.
  original_path    VARCHAR(255) NOT NULL,

  -- NULL until Phase 2's upload pipeline generates it. Same nullable-until-
  -- processed convention as Inspiration's thumb_path.
  thumb_path       VARCHAR(255) NULL,

  -- Populated alongside thumb_path. Used for orientation (portrait vs.
  -- landscape, brief §4.3's primary layout driver) — deliberately NOT a
  -- separate stored `orientation` enum, since width/height already say it
  -- unambiguously (width < height = portrait) and a derived fact stored
  -- twice is a fact that can disagree with itself after a manual crop
  -- changes the image's actual dimensions but nobody remembers to flip the
  -- enum.
  width            SMALLINT UNSIGNED NULL,
  height           SMALLINT UNSIGNED NULL,

  -- THE resolved date: EXIF DateTimeOriginal if present, else submission
  -- time (brief §3), editable afterwards either way. A DATETIME, not a DATE
  -- — unlike quotes/anecdotes/snapshots below. Section 4.3's page-arrangement
  -- logic sub-groups an event's photos by "day / close timing", and "close
  -- timing" (a beach morning vs. a dinner that evening, same day) needs the
  -- time of day EXIF actually carries; collapsing it to a bare date here
  -- would throw that array before Phase 5 ever gets to use it.
  captured_at      DATETIME NOT NULL,

  -- GPS from EXIF, if present. Rounded to geocode_cache's 3-decimal grid
  -- (~110m) by the app before it's used as a cache key — these columns keep
  -- the photo's own exact reading; see geocode_cache below for the rounding.
  gps_lat          DECIMAL(9,6) NULL,
  gps_lon          DECIMAL(9,6) NULL,

  -- Manually entered (brief §2.4: "Location — optional, can be manually
  -- entered"). Independent of event_groups.location_name, which is a
  -- GROUP-level auto/renamed label — a photo can carry its own location text
  -- regardless of which event it lands in, or none at all.
  location_text    VARCHAR(190) NULL,

  -- Brief §2.4/§2.5: the ONLY captioning mechanism a photo has, typed
  -- directly during upload (see public/assets/photo-batch.js). A quote or
  -- anecdote is never a photo's caption — it's always its own standalone,
  -- dated entry (see quotes/anecdotes above); an earlier design had a
  -- separate photo_text_bundles table for linking one to a photo as a
  -- caption, and that was removed on request rather than left as dead
  -- schema — see keepsake-brief.md §2.5 and PLAN.md for the history.
  caption          TEXT NULL,

  -- Default TRUE (included) on every upload — brief §2.4: "Kathryn only
  -- uploads photos she intends to use." Toggled false via desktop review
  -- (§5.2) to exclude from layout generation without deleting the row.
  skip_for_book    TINYINT(1)   NOT NULL DEFAULT 0,

  -- Brief §2.4: "marks a photo as a standout that should get its own full
  -- page ... breaking out of the grouping logic at that point" (§4.3).
  -- Independent of skip_for_book: a photo can be flagged full-page without
  -- being excluded, and (in principle) both at once, in which case
  -- skip_for_book wins — Phase 5's arrangement code reads skip_for_book
  -- first and never gets to look at this flag for a skipped photo.
  full_page        TINYINT(1)   NOT NULL DEFAULT 0,

  -- Which event group (if any) this photo belongs to. NULL until Phase 4's
  -- grouping engine runs, or for a photo added after the last run. ON DELETE
  -- SET NULL rather than CASCADE: deleting a group (a merge's cleanup step,
  -- or an explicit "ungroup") must never delete the PHOTOS in it — it just
  -- un-assigns them, same as they'd be before grouping ran at all.
  event_group_id   INT UNSIGNED NULL,

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the year timeline (brief §5.2, chronological view) and Phase 4's
  -- date-gap scan, which reads a year's photos in date order:
  --   SELECT * FROM photos WHERE year_project_id = ? ORDER BY captured_at
  KEY idx_year_captured (year_project_id, captured_at),

  -- Serves "all photos in this event group", read constantly by Phase 5's
  -- page-arrangement pass:
  --   SELECT * FROM photos WHERE event_group_id = ?
  KEY idx_event_group (event_group_id),

  CONSTRAINT fk_photo_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_photo_event_group FOREIGN KEY (event_group_id)
    REFERENCES event_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------------- quotes

-- Brief §2.1. "Who said it" is a closed set of exactly two people (Kathryn
-- or Emma) with no stated path to a third — unlike Personal CRM's
-- relationship_tags, which the brief there explicitly says can grow, this
-- has no such requirement, so a plain ENUM is the honest shape rather than a
-- lookup table standing in for a set of two.
CREATE TABLE IF NOT EXISTS quotes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id  INT UNSIGNED NOT NULL,

  quote_text       TEXT NOT NULL,
  who_said_it      ENUM('Kathryn','Emma') NOT NULL,

  -- Defaults to submission date, editable (brief §3). DATE, not DATETIME —
  -- unlike photos.captured_at, nothing about a quote's page placement needs
  -- time-of-day: it either falls within an event's date range or it's a
  -- standalone entry for a single day (brief §4.3).
  entry_date       DATE NOT NULL,

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the year timeline and Phase 5's "standalone text entries in this
  -- event's date range" read:
  --   SELECT * FROM quotes WHERE year_project_id = ? ORDER BY entry_date
  KEY idx_year_date (year_project_id, entry_date),

  CONSTRAINT fk_quote_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------- anecdotes

-- Brief §2.2: "a sentence or two describing something that happened." No
-- who-said-it field — that's specific to a quote, not every piece of text.
CREATE TABLE IF NOT EXISTS anecdotes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id  INT UNSIGNED NOT NULL,

  anecdote_text    TEXT NOT NULL,
  entry_date       DATE NOT NULL,

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  KEY idx_year_date (year_project_id, entry_date),

  CONSTRAINT fk_anecdote_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------------ snapshots

-- Brief §2.3: two fixed templates (birthday, school year), both with a set
-- of OPTIONAL fields plus freeform notes and a manually-picked hero photo.
-- ONE TABLE, NOT TWO, for the two templates: they share every non-template
-- field (type, entry_date, hero_photo_id, notes) and the type-specific
-- fields are few enough (2 for birthday, 6 for school year) that a second
-- table joined 1:1 back to a base row would be more indirection than the
-- data justifies. The `type` column is what a screen reads to know which
-- fields to show; there is deliberately NO cross-field CHECK forcing the
-- other template's columns to NULL (unlike book_page_photos' CHECK below)
-- — that would be one more thing to get exactly right for six columns'
-- worth of low-stakes optional data, for a constraint whose only job is
-- catching a bug the UI already prevents by only ever showing one
-- template's fields at a time.
CREATE TABLE IF NOT EXISTS snapshots (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id  INT UNSIGNED NOT NULL,

  type             ENUM('birthday','school_year') NOT NULL,
  entry_date       DATE NOT NULL,

  -- Brief §2.3: "both paired with a hero photo Kathryn selects manually at
  -- submission time (not auto-pulled)." Nullable anyway (rather than
  -- NOT NULL, which the plain reading of that sentence would suggest):
  -- Phase 2 hasn't built the capture flow yet, and forcing a photo to exist
  -- before a snapshot can be saved forecloses a "save the fields now, attach
  -- a hero photo later" path that costs nothing to leave open at the schema
  -- level. ON DELETE SET NULL for the same "fail soft" reason as photos
  -- above — the snapshot's real content is the fields and notes, not
  -- fundamentally the photo.
  hero_photo_id    INT UNSIGNED NULL,

  -- ---- birthday-only (brief §2.3) ----
  age              SMALLINT UNSIGNED NULL,
  -- Free text, not a number: "3'2\"", "97 cm" and "just shy of 40in" are all
  -- things a parent actually writes down, and the brief names no unit.
  height           VARCHAR(32)  NULL,

  -- ---- school-year-only (brief §2.3) ----
  grade            VARCHAR(32)  NULL,
  school           VARCHAR(190) NULL,
  teacher          VARCHAR(190) NULL,
  favorite_color   VARCHAR(64)  NULL,
  dream_job        VARCHAR(190) NULL,
  favorite_class   VARCHAR(190) NULL,

  -- Shared by both templates.
  notes            TEXT NULL,

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  KEY idx_year_date (year_project_id, entry_date),

  CONSTRAINT fk_snapshot_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_snapshot_hero_photo FOREIGN KEY (hero_photo_id)
    REFERENCES photos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==================================================== LOCATION CACHE =========

-- ------------------------------------------------------------- geocode_cache

-- Brief §4.1/§7: reverse-geocode photo GPS via OpenStreetMap Nominatim to
-- name an event group, cached so the same coordinates are never looked up
-- twice — Nominatim's usage policy requires this, and it's the same
-- dataset-caching pattern the brief points at (free-exercise-db, the
-- Hugging Face GroceryList dataset in the sibling apps).
--
-- NO year_project_id COLUMN — and unlike book_pages below, NOT because it
-- derives from one either. This table is INFRASTRUCTURE, not
-- CONTENT: the isolation rule in PLAN.md protects Kathryn's own captured
-- material from leaking across years, and a lat/lon-to-place-name mapping is
-- neither hers nor year-specific — the coffee shop on the corner resolves to
-- the same name whether the photo is from 2021 or 2026. Same category as
-- Grocery's grocery_dictionary or Personal CRM's relationship_tags: shared
-- reference data, not a year's content.
--
-- ROUNDED TO 3 DECIMAL PLACES (~110m) IN THE COLUMN TYPES THEMSELVES, not by
-- convention the app has to remember: DECIMAL(6,3)/DECIMAL(7,3) cannot hold
-- a fourth decimal digit, so a lookup either hits the cache or it doesn't —
-- there's no way for slightly-different-precision app code to silently
-- start missing the cache it already populated. 3 decimals groups GPS
-- readings from the same outing (a beach, a house, a park) into one lookup
-- without merging genuinely different places a few streets apart.
CREATE TABLE IF NOT EXISTS geocode_cache (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  lat_rounded    DECIMAL(6,3) NOT NULL,
  lon_rounded    DECIMAL(7,3) NOT NULL,

  location_name  VARCHAR(190) NOT NULL,

  fetched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- The cache key. Serves the lookup Phase 4 does before ever calling
  -- Nominatim:
  --   SELECT location_name FROM geocode_cache WHERE lat_rounded = ? AND lon_rounded = ?
  UNIQUE KEY uniq_coords (lat_rounded, lon_rounded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===================================================== BOOK LAYOUT ===========
--
-- Three tables, versioned (brief §4.5): running "Create Book Layout" again
-- for the same year INSERTS a new book_layouts row rather than overwriting
-- one, so a prior generation is never destroyed. "Reflow from here" (brief
-- §4.5) stays WITHIN one version — it deletes and re-inserts book_pages rows
-- from a given page_number onward inside the SAME book_layouts.id, which is
-- plain DELETE-then-INSERT application logic and needs no extra schema
-- construct beyond the ability to delete a page (cascading to its slots)
-- without touching the rows before it.
--
-- COVER AND TITLE PAGES ARE NOT book_pages ROWS. Both are single, fixed
-- facts about the YEAR (year_projects.cover_photo_id, year_projects.subtitle)
-- rather than generated content — there's nothing for Phase 5's arrangement
-- algorithm to decide about them, so Phase 7's export reads them directly
-- off year_projects and prepends them, and book_pages holds only the
-- generated interior pages.

-- --------------------------------------------------------------- book_layouts

CREATE TABLE IF NOT EXISTS book_layouts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_project_id  INT UNSIGNED NOT NULL,

  -- 1, 2, 3, ... per year_project, assigned by app code as
  -- (SELECT COALESCE(MAX(version), 0) + 1 ...) at insert time — computed in
  -- PHP rather than an auto-increment column of its own, because it has to
  -- restart at 1 for every year rather than counting across all years the
  -- way a single AUTO_INCREMENT id would.
  version          INT UNSIGNED NOT NULL,

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Makes a version-numbering race the database's problem too, not just
  -- app code's: two concurrent "Create Book Layout" clicks for the same year
  -- can't both succeed at inserting version 4.
  UNIQUE KEY uniq_year_version (year_project_id, version),

  CONSTRAINT fk_layout_year FOREIGN KEY (year_project_id)
    REFERENCES year_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------- book_pages

-- One row per generated interior page. NO year_project_id COLUMN: derives
-- unambiguously through book_layout_id -> book_layouts.year_project_id — a
-- page cannot exist without a layout, and a layout's year never changes
-- underneath it (regenerating makes a new book_layouts row rather than
-- repointing an old one).
CREATE TABLE IF NOT EXISTS book_pages (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_layout_id   INT UNSIGNED NOT NULL,

  page_number      SMALLINT UNSIGNED NOT NULL,

  -- 'photos'   — 1-4 slots, filled by book_page_photos rows below. May
  --              include ONE text-card slot mixed in among the photos
  --              (brief §4.3: a short standalone entry "occupies one of the
  --              page's slots" rather than a full page).
  -- 'text'     — a standalone quote/anecdote that exceeded the ~180-char
  --              threshold (brief §4.3) and got a full page of its own.
  --              Still represented as a single book_page_photos row (one
  --              slot, holding quote_id or anecdote_id) rather than a
  --              separate schema shape, so Phase 5/6/7 read "what's on this
  --              page" the same way regardless of type.
  -- 'snapshot' — brief §2.3's fixed full-page template; see snapshot_id.
  page_type        ENUM('photos','text','snapshot') NOT NULL,

  -- Set only for page_type = 'snapshot'. CASCADE: if the snapshot itself is
  -- later deleted, this generated page is meaningless and goes with it —
  -- the next "Create Book Layout" run (or a manual reflow) regenerates
  -- around its absence rather than leaving an empty template page behind.
  snapshot_id      INT UNSIGNED NULL,

  -- Kathryn's rewrite of the line that prints at the foot of the page.
  --
  -- NULL, the default for every page, means "no rewrite yet": the foot line is
  -- DERIVED at render time by joining the captions of the photos on the page,
  -- in slot order. Captions are therefore still authored PER PHOTO, which is
  -- what she asked for and for a concrete reason — she will not know which
  -- photo lands on which page, so a page-level caption box would ask her to
  -- describe a page she cannot picture. While this stays NULL, editing a
  -- photo's caption keeps flowing through to the page.
  --
  -- Setting it freezes that page's line to exactly what she typed. That is the
  -- point of it: the derived join is a first draft, and the reason she asked to
  -- edit the line in place is that it reads differently sitting under the
  -- photos than it does in a caption field.
  --
  -- NOT a contradiction of book_page_photos' "NO CAPTION COLUMN" note below.
  -- That rule is about a SLOT's caption, which is still photos.caption alone
  -- and still has exactly one home. This is page-level text with no other
  -- source, and it deliberately does NOT write back into the photos it was
  -- derived from: one edited page must never silently rewrite a caption that
  -- also appears under that photo on another page or in the timeline.
  --
  -- A reflow can move photos out from under a frozen line, leaving it
  -- describing photos that are no longer there. Kept anyway, and visibly, for
  -- the same reason the manual crop override is kept: something she typed is
  -- worth more than the tidiness of discarding it, and a wrong line on a page
  -- she is looking at is a one-tap fix.
  caption_override TEXT NULL,

  PRIMARY KEY (id),

  -- Serves rendering a layout in order (Phase 6's page-by-page review, and
  -- Phase 7's export) and "reflow from here"'s
  --   DELETE FROM book_pages WHERE book_layout_id = ? AND page_number >= ?
  UNIQUE KEY uniq_layout_page (book_layout_id, page_number),

  CHECK (
    (page_type = 'snapshot' AND snapshot_id IS NOT NULL) OR
    (page_type <> 'snapshot' AND snapshot_id IS NULL)
  ),

  CONSTRAINT fk_page_layout FOREIGN KEY (book_layout_id)
    REFERENCES book_layouts(id) ON DELETE CASCADE,
  CONSTRAINT fk_page_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES snapshots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------- book_page_photos

-- One row per FILLED SLOT on a page (1-4 per page_type='photos' page, or
-- exactly 1 for page_type='text'; page_type='snapshot' pages have none —
-- everything they need is snapshot_id on book_pages itself). The name is
-- inherited from the brief's table list; despite it, a slot here is usually
-- a photo but occasionally a standalone text card (see page_type='photos'
-- above) — hence the "exactly one of three" CHECK below (photo_id XOR
-- quote_id XOR anecdote_id), enforcing that a slot is a photo OR a
-- standalone quote-as-text-card OR a standalone anecdote-as-text-card,
-- never more than one.
--
-- NO year_project_id COLUMN: derives through book_page_id -> book_pages ->
-- book_layouts.year_project_id.
--
-- NO CAPTION COLUMN. A photo slot's caption (if any) comes from photos.caption
-- alone — the only captioning mechanism a photo has (see photos.caption
-- above) — so duplicating it here would just be a second place the same
-- text could go stale.
CREATE TABLE IF NOT EXISTS book_page_photos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_page_id  INT UNSIGNED NOT NULL,

  -- Position within the page, 1-4. Also what an "swap these two photos"
  -- manual edit (brief §5.4) changes: two rows trade slot_number, nothing
  -- else moves.
  slot_number   TINYINT UNSIGNED NOT NULL,

  photo_id      INT UNSIGNED NULL,
  quote_id      INT UNSIGNED NULL,
  anecdote_id   INT UNSIGNED NULL,

  -- Kathryn's manual "adjust crop" override for a PHOTO slot (public/layout.php,
  -- lib/pdfexport.php) — normalized 0..1 fractions of the photo's own
  -- original_path, same convention as crop.js's openCropper()/
  -- imageproc_crop_photo()'s rect. NULL (the default for every slot) means
  -- "no override yet": the renderer computes a centered crop to fit this
  -- slot's own shape instead (lib/layout_render.php's
  -- layout_auto_crop_rect()) — deliberately NOT backfilled with that
  -- computed rect at write time, because the auto crop depends on the
  -- SLOT's shape, which can change out from under a photo (a Phase 6
  -- swap/move, a reflow) without anyone touching this row; a stale baked-in
  -- rect would silently crop the wrong region after that, where NULL just
  -- means "keep auto-fitting to wherever this photo ends up."
  --
  -- NON-DESTRUCTIVE, unlike imageproc_crop_photo(): that function bakes a
  -- crop into a NEW original_path/thumb_path for the photo everywhere it
  -- appears in the app. This one only changes how THIS placement of the
  -- photo is windowed on THIS page — the same photo can sit uncropped in
  -- the year timeline and adjusted here, and reflowing this page to a
  -- different slot shape just makes the override reinterpreted (or ignored,
  -- if the new shape doesn't need it) rather than wrong.
  --
  -- Meaningless for a text-card slot (quote_id/anecdote_id set): nothing
  -- enforces that in the database — a CHECK tying four nullable columns to
  -- three other nullable columns is more schema than the fact is worth —
  -- the renderer simply never reads crop_* for a slot whose photo_id is NULL.
  crop_x        DECIMAL(6,5) NULL,
  crop_y        DECIMAL(6,5) NULL,
  crop_w        DECIMAL(6,5) NULL,
  crop_h        DECIMAL(6,5) NULL,

  PRIMARY KEY (id),

  -- One occupant per slot per page.
  UNIQUE KEY uniq_page_slot (book_page_id, slot_number),

  CHECK (slot_number BETWEEN 1 AND 4),

  CHECK (
    (photo_id IS NOT NULL AND quote_id IS NULL AND anecdote_id IS NULL) OR
    (photo_id IS NULL AND quote_id IS NOT NULL AND anecdote_id IS NULL) OR
    (photo_id IS NULL AND quote_id IS NULL AND anecdote_id IS NOT NULL)
  ),

  CONSTRAINT fk_bpp_page FOREIGN KEY (book_page_id)
    REFERENCES book_pages(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpp_photo FOREIGN KEY (photo_id)
    REFERENCES photos(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpp_quote FOREIGN KEY (quote_id)
    REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpp_anecdote FOREIGN KEY (anecdote_id)
    REFERENCES anecdotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
