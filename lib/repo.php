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
 * Batch-step / review edits: caption, manually-typed location text, and a
 * date correction. Only the keys present in $fields are touched.
 *
 * Brief §3: "The auto-assigned year is editable, in case a date correction
 * needs to move an entry into a different year-project" — so changing
 * captured_at here RE-RESOLVES year_project_id through the same
 * year_project_get_or_create() every *_create() function uses, rather than
 * leaving the photo's year stale after its date moves.
 *
 * @param array{caption?:?string, location_text?:?string, captured_at?:string} $fields
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

    if ($sets === array()) {
        return;
    }

    $values[] = $id;
    q('UPDATE photos SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
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
