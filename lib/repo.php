<?php
/* Repo layer for Phase 2's four content types, plus the one piece of logic
 * every single one of them shares: resolving which year_project a row
 * belongs to from its own date.
 *
 * THE YEAR-ASSIGNMENT RULE (brief §3): a piece of content's year is
 * auto-determined from its date — EXIF year for photos, submission-date
 * year for everything else — and that is what makes backfilling 2020-2025
 * practical with no manual year picker. year_project_get_or_create() is the
 * ONE place that logic lives; every *_create() function below calls it
 * rather than re-deriving a year itself, so there is exactly one place to
 * get this right.
 *
 * Kept deliberately free of any HTTP/$_POST handling — public/api/*.php
 * files parse and validate the request, then call these. That split is what
 * lets tools/verify-capture.php prove the year math against the SQLite
 * test-harness database with no web server involved at all.
 */

declare(strict_types=1);

/** The calendar year out of a 'Y-m-d' or 'Y-m-d H:i:s' string. Pure. */
function year_from_date(string $date): int
{
    return (int) substr($date, 0, 4);
}

/**
 * Get or create the year_projects row for the year a date string falls in,
 * returning its id.
 *
 * INSERT IGNORE + re-select, not INSERT ... ON DUPLICATE KEY, because there
 * is nothing to update on a collision — year_projects' only unique key is
 * the year itself, and a second "New year" for a year that already exists
 * should just resolve to the existing row (schema.sql's own comment on
 * uniq_year: "what makes an accidental double click a no-op instead of a
 * duplicate").
 */
function year_project_get_or_create(string $date): int
{
    $year = year_from_date($date);

    $row = q('SELECT id FROM year_projects WHERE year = ?', array($year))->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    q('INSERT IGNORE INTO year_projects (year) VALUES (?)', array($year));

    $row = q('SELECT id FROM year_projects WHERE year = ?', array($year))->fetch();
    if (!$row) {
        // Only reachable if the insert itself failed for a reason IGNORE
        // doesn't swallow (e.g. the table is missing) — surfacing this is
        // more useful than returning a fake id that every foreign key below
        // would then fail against anyway.
        throw new RuntimeException('could not resolve year_project for year ' . $year);
    }
    return (int) $row['id'];
}

/**
 * Every year_projects row, newest first — Phase 3's dashboard (public/index.php).
 * No filtering: a year with zero content simply never got a row in the first
 * place (year_project_get_or_create() is the only thing that inserts one), so
 * "every row that exists" and "every year worth showing" are the same list —
 * see PLAN.md/this phase's report for why no placeholder rows are synthesized
 * for years with nothing captured yet.
 */
function year_project_list(): array
{
    return q('SELECT * FROM year_projects ORDER BY year DESC')->fetchAll();
}

function year_project_get(int $id): ?array
{
    $row = q('SELECT * FROM year_projects WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

function year_project_get_by_year(int $year): ?array
{
    $row = q('SELECT * FROM year_projects WHERE year = ?', array($year))->fetch();
    return $row ?: null;
}

/**
 * Brief §4.6: "An optional subtitle field is available for editing during
 * the pre-layout content review step" — the book title page's subtitle.
 * Empty string is stored as NULL, matching every other optional text field
 * in this file (photo_update()'s caption/location_text).
 */
function year_project_update_subtitle(int $id, ?string $subtitle): void
{
    $subtitle = ($subtitle === null || trim($subtitle) === '') ? null : trim($subtitle);
    q('UPDATE year_projects SET subtitle = ? WHERE id = ?', array($subtitle, $id));
}

/** Manual cover-photo pick (brief §4.6: "not auto-selected"). NULL clears it. */
function year_project_set_cover_photo(int $id, ?int $photoId): void
{
    q('UPDATE year_projects SET cover_photo_id = ? WHERE id = ?', array($photoId, $id));
}

/* --------------------------------------------------------------- quotes */

/**
 * @param array{quote_text:string, who_said_it:string, entry_date:string} $data
 * @return int the new quote id
 */
function quote_create(array $data): int
{
    $yearId = year_project_get_or_create($data['entry_date']);

    q(
        'INSERT INTO quotes (year_project_id, quote_text, who_said_it, entry_date)
         VALUES (?, ?, ?, ?)',
        array($yearId, $data['quote_text'], $data['who_said_it'], $data['entry_date'])
    );
    return (int) db()->lastInsertId();
}

function quote_get(int $id): ?array
{
    $row = q('SELECT * FROM quotes WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/** All quotes for a year, chronological — Phase 3's timeline/grid view. */
function quotes_for_year(int $yearProjectId): array
{
    return q(
        'SELECT * FROM quotes WHERE year_project_id = ? ORDER BY entry_date, id',
        array($yearProjectId)
    )->fetchAll();
}

/**
 * Partial update, following photo_update()'s exact pattern: only keys
 * present in $fields are touched, and changing entry_date RE-RESOLVES
 * year_project_id (brief §3: "the auto-assigned year is editable") rather
 * than leaving the quote's year stale after its date moves.
 *
 * @param array{quote_text?:string, who_said_it?:string, entry_date?:string} $fields
 */
function quote_update(int $id, array $fields): void
{
    $sets   = array();
    $values = array();

    if (array_key_exists('quote_text', $fields)) {
        $sets[]   = 'quote_text = ?';
        $values[] = $fields['quote_text'];
    }
    if (array_key_exists('who_said_it', $fields)) {
        $sets[]   = 'who_said_it = ?';
        $values[] = $fields['who_said_it'];
    }
    if (array_key_exists('entry_date', $fields) && $fields['entry_date'] !== '') {
        $sets[]   = 'entry_date = ?';
        $values[] = $fields['entry_date'];
        $sets[]   = 'year_project_id = ?';
        $values[] = year_project_get_or_create($fields['entry_date']);
    }

    if ($sets === array()) {
        return;
    }

    $values[] = $id;
    q('UPDATE quotes SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
}

function quote_delete(int $id): void
{
    q('DELETE FROM quotes WHERE id = ?', array($id));
}

/* ------------------------------------------------------------ anecdotes */

/**
 * @param array{anecdote_text:string, entry_date:string} $data
 * @return int the new anecdote id
 */
function anecdote_create(array $data): int
{
    $yearId = year_project_get_or_create($data['entry_date']);

    q(
        'INSERT INTO anecdotes (year_project_id, anecdote_text, entry_date) VALUES (?, ?, ?)',
        array($yearId, $data['anecdote_text'], $data['entry_date'])
    );
    return (int) db()->lastInsertId();
}

function anecdote_get(int $id): ?array
{
    $row = q('SELECT * FROM anecdotes WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/** All anecdotes for a year, chronological — Phase 3's timeline/grid view. */
function anecdotes_for_year(int $yearProjectId): array
{
    return q(
        'SELECT * FROM anecdotes WHERE year_project_id = ? ORDER BY entry_date, id',
        array($yearProjectId)
    )->fetchAll();
}

/**
 * Same partial-update / re-resolve-year-on-date-change shape as
 * quote_update()/photo_update().
 *
 * @param array{anecdote_text?:string, entry_date?:string} $fields
 */
function anecdote_update(int $id, array $fields): void
{
    $sets   = array();
    $values = array();

    if (array_key_exists('anecdote_text', $fields)) {
        $sets[]   = 'anecdote_text = ?';
        $values[] = $fields['anecdote_text'];
    }
    if (array_key_exists('entry_date', $fields) && $fields['entry_date'] !== '') {
        $sets[]   = 'entry_date = ?';
        $values[] = $fields['entry_date'];
        $sets[]   = 'year_project_id = ?';
        $values[] = year_project_get_or_create($fields['entry_date']);
    }

    if ($sets === array()) {
        return;
    }

    $values[] = $id;
    q('UPDATE anecdotes SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
}

function anecdote_delete(int $id): void
{
    q('DELETE FROM anecdotes WHERE id = ?', array($id));
}

/* ------------------------------------------------------------- snapshots */

/** Fields that only make sense for a given snapshot type — see schema.sql. */
const SNAPSHOT_BIRTHDAY_FIELDS   = array('age', 'height');
const SNAPSHOT_SCHOOL_YEAR_FIELDS = array(
    'grade', 'school', 'teacher', 'favorite_color', 'dream_job', 'favorite_class',
);

/**
 * @param array $data type, entry_date, hero_photo_id?, notes?, plus whichever
 *              of SNAPSHOT_BIRTHDAY_FIELDS / SNAPSHOT_SCHOOL_YEAR_FIELDS match
 *              `type` — every one of them optional (brief §2.3).
 * @return int the new snapshot id
 */
function snapshot_create(array $data): int
{
    $type = $data['type'];
    if (!in_array($type, array('birthday', 'school_year'), true)) {
        throw new InvalidArgumentException('bad snapshot type: ' . $type);
    }

    $yearId = year_project_get_or_create($data['entry_date']);

    // Only the fields belonging to this template are ever written — the
    // OTHER template's columns are left NULL rather than trusting the caller
    // to have not sent them. schema.sql deliberately has no CHECK enforcing
    // this (see its comment on the snapshots table); this function is where
    // that invariant actually gets kept.
    $allowed = $type === 'birthday' ? SNAPSHOT_BIRTHDAY_FIELDS : SNAPSHOT_SCHOOL_YEAR_FIELDS;

    $columns = array('year_project_id', 'type', 'entry_date', 'hero_photo_id', 'notes');
    $values  = array(
        $yearId,
        $type,
        $data['entry_date'],
        isset($data['hero_photo_id']) && $data['hero_photo_id'] !== '' ? (int) $data['hero_photo_id'] : null,
        isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
    );

    foreach ($allowed as $field) {
        $columns[] = $field;
        $raw = $data[$field] ?? null;
        $values[] = ($raw === null || $raw === '') ? null : $raw;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    q(
        'INSERT INTO snapshots (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
        $values
    );
    return (int) db()->lastInsertId();
}

function snapshot_get(int $id): ?array
{
    $row = q('SELECT * FROM snapshots WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/** All snapshots for a year, chronological — Phase 3's timeline/grid view. */
function snapshots_for_year(int $yearProjectId): array
{
    return q(
        'SELECT * FROM snapshots WHERE year_project_id = ? ORDER BY entry_date, id',
        array($yearProjectId)
    )->fetchAll();
}

/**
 * Partial update, same shape as quote_update()/anecdote_update()/
 * photo_update(): only keys present in $fields are touched, entry_date
 * re-resolves year_project_id.
 *
 * NO `type` KEY HERE ON PURPOSE. Changing a snapshot's template (birthday <->
 * school_year) after the fact would mean deciding what to do with the OTHER
 * template's now-orphaned fields, and Section 2.3's fixed-template design
 * gives no reason a saved entry would ever need to switch — the review UI
 * offers the type-appropriate fields for whichever template the row was
 * already created as, matching snapshot_create()'s own invariant (only the
 * owning template's columns are ever written) rather than re-deriving it
 * here differently.
 *
 * @param array{
 *   entry_date?:string, hero_photo_id?:?int, notes?:?string,
 *   age?:?int, height?:?string, grade?:?string, school?:?string,
 *   teacher?:?string, favorite_color?:?string, dream_job?:?string,
 *   favorite_class?:?string
 * } $fields
 */
function snapshot_update(int $id, array $fields): void
{
    $existing = snapshot_get($id);
    if ($existing === null) {
        return;
    }

    $sets   = array();
    $values = array();

    if (array_key_exists('entry_date', $fields) && $fields['entry_date'] !== '') {
        $sets[]   = 'entry_date = ?';
        $values[] = $fields['entry_date'];
        $sets[]   = 'year_project_id = ?';
        $values[] = year_project_get_or_create($fields['entry_date']);
    }
    if (array_key_exists('hero_photo_id', $fields)) {
        $sets[]   = 'hero_photo_id = ?';
        $values[] = ($fields['hero_photo_id'] === null || $fields['hero_photo_id'] === '')
            ? null : (int) $fields['hero_photo_id'];
    }
    if (array_key_exists('notes', $fields)) {
        $sets[]   = 'notes = ?';
        $values[] = ($fields['notes'] === null || $fields['notes'] === '') ? null : (string) $fields['notes'];
    }

    // Only this row's own template's fields are ever settable — the same
    // invariant snapshot_create() keeps, applied on the way back in. A field
    // belonging to the OTHER template sent by a confused/hostile client is
    // silently ignored rather than allowed to write across templates.
    $allowed = $existing['type'] === 'birthday' ? SNAPSHOT_BIRTHDAY_FIELDS : SNAPSHOT_SCHOOL_YEAR_FIELDS;
    foreach ($allowed as $field) {
        if (array_key_exists($field, $fields)) {
            $sets[]   = $field . ' = ?';
            $values[] = ($fields[$field] === null || $fields[$field] === '') ? null : $fields[$field];
        }
    }

    if ($sets === array()) {
        return;
    }

    $values[] = $id;
    q('UPDATE snapshots SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
}

function snapshot_delete(int $id): void
{
    q('DELETE FROM snapshots WHERE id = ?', array($id));
}

/* ----------------------------------------------------------------- photos */

/**
 * @param array{
 *   original_path:string, thumb_path:?string, width:?int, height:?int,
 *   captured_at:string, gps_lat:?float, gps_lon:?float
 * } $data
 * @return int the new photo id
 */
function photo_create(array $data): int
{
    // THE year-auto-assignment moment for photos (brief §3): captured_at is
    // already resolved to EXIF date-or-submission-time by the caller (see
    // public/api/photos-upload.php) BEFORE this function ever runs, so the
    // year it derives is correct either way without this function needing
    // to know which source it came from.
    $yearId = year_project_get_or_create($data['captured_at']);

    q(
        'INSERT INTO photos
            (year_project_id, original_path, thumb_path, width, height,
             captured_at, gps_lat, gps_lon)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        array(
            $yearId,
            $data['original_path'],
            $data['thumb_path'] ?? null,
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['captured_at'],
            $data['gps_lat'] ?? null,
            $data['gps_lon'] ?? null,
        )
    );
    return (int) db()->lastInsertId();
}

function photo_get(int $id): ?array
{
    $row = q('SELECT * FROM photos WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/**
 * Batch-step / review edits: caption, manually-typed location text, a date
 * correction, the two book-inclusion flags (brief §2.4), and manual
 * event-group assignment. Only the keys present in $fields are touched.
 *
 * Brief §3: "The auto-assigned year is editable, in case a date correction
 * needs to move an entry into a different year-project" — so changing
 * captured_at here RE-RESOLVES year_project_id through the same
 * year_project_get_or_create() every *_create() function uses, rather than
 * leaving the photo's year stale after its date moves.
 *
 * skip_for_book/full_page were Phase 2's own note as "Phase 3's desktop
 * review screen, not this phase's" (see photos-update.php's original
 * header) — extended onto this SAME function, following its own established
 * partial-update pattern, rather than a separate function for just two
 * columns.
 *
 * event_group_id is Phase 4's column to populate automatically once its
 * grouping engine exists; until then this is how Phase 3's manual
 * event-group review UI assigns/reassigns a photo to a group (or clears it,
 * via null) — the same column, the same partial-update discipline.
 *
 * @param array{
 *   caption?:?string, location_text?:?string, captured_at?:string,
 *   skip_for_book?:bool, full_page?:bool, event_group_id?:?int
 * } $fields
 */
function photo_update(int $id, array $fields): void
{
    $sets   = array();
    $values = array();

    if (array_key_exists('caption', $fields)) {
        $sets[]   = 'caption = ?';
        $values[] = $fields['caption'] === '' ? null : $fields['caption'];
    }
    if (array_key_exists('location_text', $fields)) {
        $sets[]   = 'location_text = ?';
        $values[] = $fields['location_text'] === '' ? null : $fields['location_text'];
    }
    if (array_key_exists('captured_at', $fields) && $fields['captured_at'] !== '') {
        $sets[]   = 'captured_at = ?';
        $values[] = $fields['captured_at'];
        $sets[]   = 'year_project_id = ?';
        $values[] = year_project_get_or_create($fields['captured_at']);
    }
    if (array_key_exists('skip_for_book', $fields)) {
        $sets[]   = 'skip_for_book = ?';
        $values[] = $fields['skip_for_book'] ? 1 : 0;
    }
    if (array_key_exists('full_page', $fields)) {
        $sets[]   = 'full_page = ?';
        $values[] = $fields['full_page'] ? 1 : 0;
    }
    if (array_key_exists('event_group_id', $fields)) {
        $sets[]   = 'event_group_id = ?';
        $values[] = ($fields['event_group_id'] === null || $fields['event_group_id'] === '')
            ? null : (int) $fields['event_group_id'];
    }

    if ($sets === array()) {
        return;
    }

    $values[] = $id;
    q('UPDATE photos SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
}

/**
 * Deletes the row only — NOT the files on disk. Called from
 * public/api/photos-delete.php, which removes original_path/thumb_path
 * itself only after this commits, the same "commit the row first, clean up
 * files after" order photos-crop.php already uses, so a crash between the
 * two leaves an orphaned file rather than a row pointing at nothing.
 */
function photo_delete(int $id): void
{
    q('DELETE FROM photos WHERE id = ?', array($id));

    // year_projects.cover_photo_id is deliberately NOT a real foreign key
    // (schema.sql's comment: avoiding a circular CREATE TABLE dependency
    // between photos and year_projects), so nothing at the database level
    // clears it when the photo it points at is deleted. Do it here instead
    // of leaving a dangling id a later page render would have to guard
    // against.
    q('UPDATE year_projects SET cover_photo_id = NULL WHERE cover_photo_id = ?', array($id));
}

/** All photos for a year, chronological — Phase 3's timeline/grid view. */
function photos_for_year(int $yearProjectId): array
{
    return q(
        'SELECT * FROM photos WHERE year_project_id = ? ORDER BY captured_at, id',
        array($yearProjectId)
    )->fetchAll();
}

/** Overwrite original_path/thumb_path/width/height after a crop. */
function photo_apply_crop(int $id, array $derived): void
{
    q(
        'UPDATE photos SET original_path = ?, thumb_path = ?, width = ?, height = ? WHERE id = ?',
        array(
            $derived['original_path'],
            $derived['thumb_path'],
            $derived['width'],
            $derived['height'],
            $id,
        )
    );
}

/**
 * Most-recently-uploaded photos, for the inline photo picker used by the
 * quick-add bundling option (brief §2.5) and by a snapshot's hero-photo
 * selector (brief §2.3) — both need "pick one of what I just captured",
 * not a full year-browsing UI (that's Phase 3's review/browse screen).
 */
function photos_recent(int $limit = 24): array
{
    $limit = max(1, min(100, $limit));
    // LIMIT can't be bound as a placeholder on every PDO driver in the same
    // way, and it's an internally-clamped int, never user SQL — safe to
    // interpolate.
    return q(
        'SELECT id, thumb_path, original_path, caption, captured_at, location_text
           FROM photos
          ORDER BY created_at DESC, id DESC
          LIMIT ' . $limit
    )->fetchAll();
}

/* No photo_text_bundle_create(): a quote/anecdote is never attached to a
 * photo as its caption. Kathryn's call — a photo's only caption mechanism
 * is photos.caption, typed directly during upload; a quote or anecdote is
 * always a standalone, dated entry. See schema.sql's comment on
 * photos.caption for the removed photo_text_bundles table's history. */

/* ------------------------------------------------------------- event_groups
 *
 * Phase 4's date-gap/geocoding engine hasn't run yet (see PLAN.md), so this
 * table is expected to be empty or hold only whatever's created by hand
 * through the functions below for Phase 3's review UI. Nothing here runs
 * date-gap detection or reverse geocoding — that's Phase 4's job, reusing
 * these same rows and this same is_manual_name flag to know which groups its
 * own auto-naming pass is allowed to touch.
 */

/**
 * A year's event groups, chronological, each carrying its own photo count —
 * a single query rather than N+1, since the review screen always needs the
 * count to render "12 photos" next to a group without a photo grid open.
 */
function event_groups_for_year(int $yearProjectId): array
{
    return q(
        'SELECT eg.*, COUNT(p.id) AS photo_count
           FROM event_groups eg
           LEFT JOIN photos p ON p.event_group_id = eg.id
          WHERE eg.year_project_id = ?
          GROUP BY eg.id
          ORDER BY eg.start_date, eg.id',
        array($yearProjectId)
    )->fetchAll();
}

function event_group_get(int $id): ?array
{
    $row = q('SELECT * FROM event_groups WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/**
 * Manual creation — brief §5.2 says Kathryn can "rename, merge, split
 * auto-detected groups", but Phase 4 (the auto-detector) hasn't run yet, so
 * this is the only way a group exists to review against before then. Created
 * with is_manual_name = 1 unconditionally: every field on a hand-made group
 * is a manual decision, not something a future auto-naming pass should ever
 * overwrite.
 *
 * @param array{
 *   year_project_id:int, name:string, start_date:string, end_date:string,
 *   location_name?:?string
 * } $data
 * @return int the new event_groups id
 */
function event_group_create(array $data): int
{
    q(
        'INSERT INTO event_groups
            (year_project_id, name, start_date, end_date, location_name, is_manual_name)
         VALUES (?, ?, ?, ?, ?, 1)',
        array(
            $data['year_project_id'],
            $data['name'],
            $data['start_date'],
            $data['end_date'],
            (isset($data['location_name']) && $data['location_name'] !== '') ? $data['location_name'] : null,
        )
    );
    return (int) db()->lastInsertId();
}

/**
 * Rename only — the manual override brief §4.1 requires ("Kathryn can rename
 * any group"). Sets is_manual_name so a later run of Phase 4's auto-naming
 * pass skips this group's `name` (it may still refresh start_date/end_date/
 * location_name — see schema.sql's comment on the column).
 */
function event_group_rename(int $id, string $name): void
{
    q(
        'UPDATE event_groups SET name = ?, is_manual_name = 1 WHERE id = ?',
        array($name, $id)
    );
}

/**
 * Recomputes start_date/end_date from current member photos. Called after
 * any membership change (merge, split, manual reassignment via
 * photo_update()'s event_group_id) so the group's displayed date range never
 * goes stale. A group left with zero members keeps its last known range
 * rather than being reset to something meaningless — there's nothing to
 * derive a range FROM at that point, and the group itself is not deleted
 * just because it's momentarily empty (a split leaves the source group
 * exactly this way until it's given new members or removed by hand).
 */
function event_group_recompute_dates(int $id): void
{
    $row = q(
        'SELECT MIN(captured_at) AS min_d, MAX(captured_at) AS max_d
           FROM photos WHERE event_group_id = ?',
        array($id)
    )->fetch();

    if ($row === false || $row['min_d'] === null) {
        return;
    }

    q(
        'UPDATE event_groups SET start_date = ?, end_date = ? WHERE id = ?',
        array(substr((string) $row['min_d'], 0, 10), substr((string) $row['max_d'], 0, 10), $id)
    );
}

/**
 * Merge one or more source groups into a target group: every photo pointing
 * at a source group is reassigned to the target, the source groups are
 * deleted, and the target's date range is recomputed over its new,
 * larger membership. The target's own name/location are left untouched —
 * brief §4.1's manual override is "rename OR merge", not "merging silently
 * renames" — Kathryn renames afterward if the merged name should change.
 *
 * Both sides must belong to the SAME year_project: merging across years
 * would silently move photos between year-project pools, which is exactly
 * what the isolation rule (PLAN.md "Architecture decisions") exists to
 * prevent. A source id that fails this check (wrong year, or doesn't exist)
 * is skipped rather than aborting the whole merge — fail soft, per house
 * style: a bad id in the list shouldn't block merging the good ones.
 */
function event_group_merge(array $sourceIds, int $targetId): void
{
    $target = event_group_get($targetId);
    if ($target === null) {
        return;
    }

    foreach ($sourceIds as $sourceId) {
        $sourceId = (int) $sourceId;
        if ($sourceId === $targetId) {
            continue;
        }
        $source = event_group_get($sourceId);
        if ($source === null || (int) $source['year_project_id'] !== (int) $target['year_project_id']) {
            continue;
        }

        q('UPDATE photos SET event_group_id = ? WHERE event_group_id = ?', array($targetId, $sourceId));
        q('DELETE FROM event_groups WHERE id = ?', array($sourceId));
    }

    event_group_recompute_dates($targetId);
}

/**
 * Split: move the given photos (which must currently belong to $sourceId)
 * out into a brand new group, and recompute both groups' date ranges
 * afterward. Returns the new group's id.
 *
 * The new group always gets is_manual_name = 1 via event_group_create() —
 * a split is, definitionally, a human deciding these photos don't belong
 * together with the rest, which is exactly the kind of naming decision nothing
 * automated should later overwrite.
 *
 * Only photos that actually belong to $sourceId are moved — a photo id from
 * somewhere else in the list (wrong group, wrong year, deleted) is silently
 * skipped rather than failing the whole split, same fail-soft reasoning as
 * event_group_merge().
 */
function event_group_split(int $sourceId, array $photoIds, string $newName): int
{
    $source = event_group_get($sourceId);
    if ($source === null) {
        throw new InvalidArgumentException('unknown source event group: ' . $sourceId);
    }

    $newId = event_group_create(array(
        'year_project_id' => (int) $source['year_project_id'],
        'name'            => $newName,
        // Placeholder range, corrected by the recompute below the moment
        // membership exists — event_group_create() has no members to derive
        // one from yet, the same reason event_group_recompute_dates() leaves
        // a still-empty group's range untouched instead of guessing.
        'start_date'      => $source['start_date'],
        'end_date'        => $source['end_date'],
        'location_name'   => $source['location_name'],
    ));

    foreach ($photoIds as $photoId) {
        $photoId = (int) $photoId;
        $photo = photo_get($photoId);
        if ($photo === null || (int) $photo['event_group_id'] !== $sourceId) {
            continue;
        }
        q('UPDATE photos SET event_group_id = ? WHERE id = ?', array($newId, $photoId));
    }

    event_group_recompute_dates($sourceId);
    event_group_recompute_dates($newId);

    return $newId;
}

/**
 * Deletes the group itself, not its photos — event_group_id is
 * ON DELETE SET NULL (schema.sql: "deleting a group ... must never delete
 * the PHOTOS in it"), so this is an "ungroup", not a bulk photo delete.
 */
function event_group_delete(int $id): void
{
    q('DELETE FROM event_groups WHERE id = ?', array($id));
}
