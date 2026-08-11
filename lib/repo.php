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
 * Which project a NEW piece of content belongs to.
 *
 * An explicit year_project_id wins over the date, always. That is the whole
 * point: adding something from inside a project means putting it in THAT
 * project, whatever its date says — a photo taken last December belongs in the
 * trip book you are looking at if that is where you added it from, and a book
 * about a trip has no year for a date to resolve to in the first place.
 *
 * With no explicit id, the original rule stands and is still the common case:
 * the year comes from the date (brief §3), which is what makes backfilling
 * 2020-2025 practical with no manual picker. Adding from the project LIST
 * screen goes through this branch, because there the answer really is "wherever
 * the date says".
 *
 * A supplied id that does not exist falls back to the date rather than
 * inserting a row that points at nothing — fail soft, and the content lands
 * somewhere findable instead of erroring out mid-save.
 *
 * @param array  $data    The create payload; 'year_project_id' is optional.
 * @param string $dateKey Which key holds this type's date ('entry_date' for
 *               text content, 'captured_at' for photos).
 */
function year_project_for_new(array $data, string $dateKey): int
{
    $explicit = (int) ($data['year_project_id'] ?? 0);
    if ($explicit > 0 && year_project_get($explicit) !== null) {
        return $explicit;
    }

    return year_project_get_or_create((string) $data[$dateKey]);
}

/**
 * Where a piece of content should live after its date was corrected — or NULL
 * for "leave it exactly where it is".
 *
 * THE RULE: a row whose project still agrees with its own date was filed by
 * the date, so correcting the date re-files it (commit "Correcting a photo's
 * date now re-groups it" — a photo dated 2024 by a wrong EXIF timestamp has to
 * be able to move to 2023 when that is fixed, or the correction is cosmetic).
 * A row whose project does NOT agree with its date was put there by hand, and
 * a hand placement outranks an inference. Correcting its date leaves it put.
 *
 * WHY THIS RATHER THAN A "PINNED" COLUMN on all four content tables: the fact
 * is already in the data. "Does this row sit where its date would have put it"
 * is exactly the question a pinned flag would answer, and a flag would be a
 * second copy of it that can disagree with the row it describes. It also means
 * nothing has to be backfilled — every row that exists today was filed by date
 * and reads as unpinned, which is correct.
 *
 * A yearless project can never equal the date-derived answer, so content in a
 * trip book is never re-filed out of it. That falls out of the rule rather
 * than needing a case of its own.
 */
function year_project_reassign_on_date(int $currentId, ?string $oldDate, string $newDate): ?int
{
    if ($oldDate === null || $oldDate === '') {
        return null;
    }

    $derivedFromOld = year_project_get_by_year(year_from_date($oldDate));
    if ($derivedFromOld === null || (int) $derivedFromOld['id'] !== $currentId) {
        return null; // hand-placed — not ours to move
    }

    if (year_from_date($oldDate) === year_from_date($newDate)) {
        return null; // same year, nothing to re-file
    }

    return year_project_get_or_create($newDate);
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
    $rows = q('SELECT * FROM year_projects')->fetchAll();

    /* Sorted in PHP, not in SQL, now that `year` can be NULL.
     *
     * The obvious ORDER BY year DESC puts every yearless project in one block
     * at the bottom (MySQL sorts NULLs last in DESC), so a trip book made this
     * morning files below a 2020 book that has not been touched in years. What
     * is wanted is one list in rough recency order, with a project's year
     * standing in for its date when it has one.
     *
     * Expressing that in portable SQL means COALESCE(year, YEAR(created_at)),
     * and YEAR() is MySQL-only — tools/test-harness.php would need to learn a
     * strftime rewrite to run a single query on a table that holds a handful
     * of rows. Sorting here costs nothing at this size and keeps the rule
     * readable and testable as plain PHP. */
    $sortYear = static function (array $row): int {
        if ($row['year'] !== null) {
            return (int) $row['year'];
        }
        return (int) substr((string) $row['created_at'], 0, 4);
    };

    usort($rows, static function (array $a, array $b) use ($sortYear): int {
        /* Ties broken by id descending — newest first — so two projects made
           in the same year keep a stable, meaningful order rather than
           whatever the storage engine handed back. */
        return $sortYear($b) <=> $sortYear($a) ?: (int) $b['id'] <=> (int) $a['id'];
    });

    return $rows;
}

/**
 * Make a project by hand: a year book for a year that has nothing in it yet,
 * or a book that is not about a year at all.
 *
 * The OTHER way projects come into existence is year_project_get_or_create()
 * below, which derives a year from the date on whatever is being saved. That
 * one still runs for anything captured from outside a project. This one runs
 * when Kathryn says "new project" out loud, and is the only path that can
 * produce a yearless one.
 *
 * @param int|null    $year  NULL for a project that is not a calendar year.
 * @param string|null $title REQUIRED when $year is NULL — see schema.sql's
 *                    comment on the column. A yearless, nameless project has
 *                    nothing to render on a card and no way to be told apart
 *                    from the next one.
 * @throws InvalidArgumentException when neither a year nor a title is given.
 */
function year_project_create(?int $year, ?string $title = null, ?string $subtitle = null): int
{
    $title    = ($title === null || trim($title) === '') ? null : trim($title);
    $subtitle = ($subtitle === null || trim($subtitle) === '') ? null : trim($subtitle);

    if ($year === null && $title === null) {
        throw new InvalidArgumentException('A project needs a year or a name.');
    }

    /* A year that already has a project is that project, not a second one —
       the same answer year_project_get_or_create() gives, and what stops a
       double-tap on "New project" producing a duplicate 2025 that the UNIQUE
       key would reject with a 500 instead. */
    if ($year !== null) {
        $existing = year_project_get_by_year($year);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
    }

    q(
        'INSERT INTO year_projects (year, title, subtitle) VALUES (?, ?, ?)',
        array($year, $title, $subtitle)
    );

    return (int) db()->lastInsertId();
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
 * The book's name, as it prints on the cover and the title page.
 *
 * NULL or blank clears it back to the year — brief §4.6's default, and still
 * what an untouched book shows. Kathryn asked for this after living with the
 * year-as-title for a while, so "no title set" has to keep meaning "follow the
 * year" rather than silently freezing whatever the year was on the day the row
 * was created.
 *
 * Every reader goes through year_project_title() rather than reading the
 * column, so the fallback exists in exactly one place.
 */
function year_project_update_title(int $id, ?string $title): void
{
    $title = ($title === null || trim($title) === '') ? null : trim($title);

    /* A TITLE THAT IS JUST THE YEAR IS STORED AS NULL.
     *
     * schema.sql's own comment on this column says why NULL rather than a copy
     * of the year: "a row whose title happens to read 2025 cannot then be told
     * apart from one she typed 2025 into on purpose". Writing "2025" into the
     * title of the 2025 book creates exactly that ambiguity — and it is not
     * hypothetical. A rename dialog that pre-filled itself from the DISPLAYED
     * name (the year, for an unnamed book) saved it back the first time the
     * subtitle beside it was edited, and the book then had a real title that
     * happened to equal its year. The visible symptom was a header reading
     * "2025" over "2025".
     *
     * The pre-fill is fixed, but normalizing here is what makes it unreachable
     * — including from any path added later that has not read this comment —
     * and it quietly repairs a row already in that state the next time it is
     * saved. There is no information lost: NULL and "2025" render identically
     * through year_project_title(), and NULL additionally follows the year if
     * it is ever corrected, which is the behaviour the column was designed for.
     */
    if ($title !== null) {
        $project = year_project_get($id);
        if ($project !== null && $project['year'] !== null && $title === (string) $project['year']) {
            $title = null;
        }
    }

    q('UPDATE year_projects SET title = ? WHERE id = ?', array($title, $id));
}

/**
 * What to print as this book's title: hers if she set one, else the year.
 *
 * Takes the row rather than an id because every caller already has the row in
 * hand, and a title is wanted in loops (the dashboard lists every year) where
 * a query per row would be a query too many.
 */
function year_project_title(array $project): string
{
    $title = trim((string) ($project['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    /* No title and no year should be impossible — year_project_create()
       refuses it and year_project_get_or_create() always supplies a year — but
       this function is called from inside loops on every screen in the app, and
       "Untitled project" is a better outcome for one malformed row than a blank
       card nobody can identify or click. Fail soft, per house style. */
    return $project['year'] !== null ? (string) $project['year'] : 'Untitled project';
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

/**
 * Which generated layout Kathryn is working from (schema.sql:
 * active_book_layout_id is "the one I'm working from", deliberately NOT
 * "the highest version"). Set automatically only for a year's FIRST layout;
 * after that it changes only when she picks a version by hand.
 */
function year_project_set_active_layout(int $id, ?int $layoutId): void
{
    q('UPDATE year_projects SET active_book_layout_id = ? WHERE id = ?', array($layoutId, $id));
}

/**
 * How much is about to be destroyed, for the delete confirmation to say out
 * loud. "Delete this project?" and "Delete this project — 123 photos, 4
 * groups and 2 layouts?" are different questions, and only the second one can
 * be answered correctly by someone who has two projects open in two tabs.
 *
 * Five COUNTs on a screen that runs them once, when a finger is already on a
 * destructive button. Cheap at that rate.
 */
function year_project_counts(int $id): array
{
    $count = static function (string $table) use ($id): int {
        return (int) q(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE year_project_id = ?',
            array($id)
        )->fetchColumn();
    };

    /* Table names are literals in this file, never request input — the
       concatenation above cannot carry anything a caller supplied. */
    return array(
        'photos'    => $count('photos'),
        'quotes'    => $count('quotes'),
        'anecdotes' => $count('anecdotes'),
        'snapshots' => $count('snapshots'),
        'groups'    => $count('event_groups'),
        'layouts'   => $count('book_layouts'),
    );
}

/**
 * Delete a project and everything in it.
 *
 * The ROWS are the database's job: every child table that points here does so
 * with ON DELETE CASCADE (schema.sql: photos, quotes, anecdotes, snapshots,
 * event_groups, book_layouts — and book_pages/book_page_photos cascade in turn
 * from book_layouts). One DELETE takes all of them, in one transaction,
 * without this function needing to know the table list or keep it up to date.
 *
 * The FILES are not, so they are collected BEFORE the delete and unlinked
 * after it — the same "commit the row first, clean up files after" order
 * photos-delete.php and photos-crop.php use, for the same reason: a crash
 * between the two leaves an orphaned file on disk rather than a row pointing
 * at nothing. Files are unlinked one at a time and failures ignored; a
 * photo whose file already went missing must not stop the project from
 * being deleted.
 *
 * @return int how many files were removed, so the caller can log it.
 */
function year_project_delete(int $id): int
{
    require_once __DIR__ . '/imageproc.php';

    $paths = array();
    $photos = q(
        'SELECT original_path, thumb_path FROM photos WHERE year_project_id = ?',
        array($id)
    )->fetchAll();

    foreach ($photos as $photo) {
        foreach (array('original_path', 'thumb_path') as $column) {
            if ($photo[$column] === null || $photo[$column] === '') {
                continue;
            }
            $abs = imageproc_resolve_upload((string) $photo[$column]);
            if ($abs !== null) {
                $paths[$abs] = true;
            }
        }
    }

    q('DELETE FROM year_projects WHERE id = ?', array($id));

    $removed = 0;
    foreach (array_keys($paths) as $abs) {
        if (@unlink($abs)) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Everything in one project, as plain arrays, for the JSON export.
 *
 * SELECT * on purpose, rather than naming columns. This is a backup, not an
 * API: the one failure that matters is a column added next month that quietly
 * stops being exported, and nobody discovers it until they need the export.
 * Whatever the table holds is what comes out, and a reader that does not
 * recognise a key can ignore it.
 *
 * Photo FILES are not in here — see year-projects-export.php's header. The
 * paths are, so an export can be matched back up against a copy of uploads/.
 */
function year_project_export_data(int $id): ?array
{
    $project = year_project_get($id);
    if ($project === null) {
        return null;
    }

    $for = static function (string $table) use ($id): array {
        return q(
            'SELECT * FROM ' . $table . ' WHERE year_project_id = ? ORDER BY id',
            array($id)
        )->fetchAll();
    };

    $layouts = $for('book_layouts');

    /* Pages and their slots hang off layouts rather than off the project, so
       they need the extra hop. Two queries per layout and a book has one or
       two layouts — the alternative is a three-table join that has to be
       un-joined again in PHP to rebuild the nesting. */
    foreach ($layouts as &$layout) {
        $pages = q(
            'SELECT * FROM book_pages WHERE book_layout_id = ? ORDER BY page_number',
            array((int) $layout['id'])
        )->fetchAll();

        foreach ($pages as &$page) {
            $page['slots'] = q(
                'SELECT * FROM book_page_photos WHERE book_page_id = ? ORDER BY slot_number',
                array((int) $page['id'])
            )->fetchAll();
        }
        unset($page);

        $layout['pages'] = $pages;
    }
    unset($layout);

    return array(
        'keepsake_export' => 1,
        'exported_at'     => date('c'),
        'project'         => $project,
        'display_title'   => year_project_title($project),
        'event_groups'    => $for('event_groups'),
        'photos'          => $for('photos'),
        'quotes'          => $for('quotes'),
        'anecdotes'       => $for('anecdotes'),
        'snapshots'       => array_map(
            /* Sections nest under their snapshot. Without this the export
               would carry a snapshot's title and date and none of what is
               written on it — which is precisely the failure this function's
               SELECT * is meant to prevent, one table further down. */
            static function (array $snapshot): array {
                $snapshot['sections'] = snapshot_sections((int) $snapshot['id']);
                return $snapshot;
            },
            $for('snapshots')
        ),
        'book_layouts'    => $layouts,
    );
}

/* --------------------------------------------------------------- quotes */

/**
 * @param array{quote_text:string, who_said_it:string, entry_date:string} $data
 * @return int the new quote id
 */
function quote_create(array $data): int
{
    $yearId = year_project_for_new($data, 'entry_date');

    q(
        'INSERT INTO quotes (year_project_id, quote_text, who_said_it, entry_date)
         VALUES (?, ?, ?, ?)',
        array($yearId, $data['quote_text'], $data['who_said_it'], $data['entry_date'])
    );
    return (int) db()->lastInsertId();
}

/**
 * Every name that has said something, for the quote form's picker.
 *
 * SELECT DISTINCT off the quotes themselves rather than a `people` table —
 * see schema.sql on quotes.who_said_it for why there isn't one. The list
 * grows by being used and there is nothing to maintain.
 *
 * Kathryn and Emma are always offered even before either has said anything,
 * because a brand-new install with an empty dropdown would look broken rather
 * than empty. They are merged in, not prepended blindly, so neither appears
 * twice.
 *
 * Not scoped to one project: a name is a person, and the same people turn up
 * across books.
 */
function quote_speakers(): array
{
    $rows = q(
        'SELECT DISTINCT who_said_it FROM quotes WHERE who_said_it <> \'\' ORDER BY who_said_it'
    )->fetchAll();

    $names = array_map(static fn(array $r): string => (string) $r['who_said_it'], $rows);

    foreach (array('Kathryn', 'Emma') as $seed) {
        if (!in_array($seed, $names, true)) {
            $names[] = $seed;
        }
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
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
        /* Re-file into another project ONLY if this row still sits where its
           own date put it. A row placed by hand stays placed — see
           year_project_reassign_on_date() for why that test is the data
           itself rather than a pinned flag on four tables. */
        $existing = quote_get($id);
        $moved    = $existing === null ? null : year_project_reassign_on_date(
            (int) $existing['year_project_id'],
            $existing['entry_date'] === null ? null : (string) $existing['entry_date'],
            (string) $fields['entry_date']
        );
        if ($moved !== null) {
            $sets[]   = 'year_project_id = ?';
            $values[] = $moved;
        }
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
    $yearId = year_project_for_new($data, 'entry_date');

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
        /* Re-file into another project ONLY if this row still sits where its
           own date put it. A row placed by hand stays placed — see
           year_project_reassign_on_date() for why that test is the data
           itself rather than a pinned flag on four tables. */
        $existing = anecdote_get($id);
        $moved    = $existing === null ? null : year_project_reassign_on_date(
            (int) $existing['year_project_id'],
            $existing['entry_date'] === null ? null : (string) $existing['entry_date'],
            (string) $fields['entry_date']
        );
        if ($moved !== null) {
            $sets[]   = 'year_project_id = ?';
            $values[] = $moved;
        }
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

/**
 * The section headings a new snapshot starts with, per template.
 *
 * PRE-FILL, NOT SCHEMA. These used to be real columns, one per fact, and the
 * `type` column said which set existed. Now they are just the headings typed
 * into a new entry's sections for you — every one of them renameable,
 * deletable, and joinable by others you add. See schema.sql on snapshots.
 *
 * Empty bodies: the headings are the prompt, the answers are hers.
 */
const SNAPSHOT_TEMPLATES = array(
    'birthday'    => array('Age', 'Height'),
    'school_year' => array('Grade', 'School', 'Teacher', 'Favorite color', 'Dream job', 'Favorite class'),
);

/** The section headings a fresh snapshot of this type should start with. */
function snapshot_template_headings(string $type): array
{
    return SNAPSHOT_TEMPLATES[$type] ?? array();
}

/**
 * @param array $data type, entry_date, title?, hero_photo_id?, sections?
 *              — where sections is a list of array('heading' => …, 'body' => …).
 *              Omit sections entirely to start from the type's template.
 * @return int the new snapshot id
 */
function snapshot_create(array $data): int
{
    $type = $data['type'];
    if (!in_array($type, array('birthday', 'school_year'), true)) {
        throw new InvalidArgumentException('bad snapshot type: ' . $type);
    }

    $yearId = year_project_for_new($data, 'entry_date');

    q(
        'INSERT INTO snapshots (year_project_id, type, title, entry_date, hero_photo_id)
         VALUES (?, ?, ?, ?, ?)',
        array(
            $yearId,
            $type,
            isset($data['title']) && trim((string) $data['title']) !== '' ? trim((string) $data['title']) : null,
            $data['entry_date'],
            isset($data['hero_photo_id']) && $data['hero_photo_id'] !== '' ? (int) $data['hero_photo_id'] : null,
        )
    );
    $id = (int) db()->lastInsertId();

    /* An absent `sections` key means "start me from the template"; an explicit
       empty array means "no sections", which is a different thing and has to
       stay possible. array_key_exists, not isset, so the two are told apart. */
    if (array_key_exists('sections', $data)) {
        snapshot_sections_replace($id, is_array($data['sections']) ? $data['sections'] : array());
    } else {
        $seed = array();
        foreach (snapshot_template_headings($type) as $heading) {
            $seed[] = array('heading' => $heading, 'body' => '');
        }
        snapshot_sections_replace($id, $seed, true);
    }

    return $id;
}

/**
 * Normalize whatever a request called "sections" into the shape
 * snapshot_sections_replace() stores.
 *
 * In the repo rather than in each endpoint because both of them need it and
 * the rules are about the data, not about HTTP: keep only heading and body,
 * coerce both to trimmed strings, ignore anything that is not an object.
 * A caller sending a bare string, a number, or a row with extra keys gets the
 * sensible reading rather than an error — this is a personal app and one
 * malformed section should degrade that section, per the fail-soft rule.
 *
 * @param mixed $raw
 */
function snapshot_sections_from_request($raw): array
{
    if (!is_array($raw)) {
        return array();
    }

    $out = array();
    foreach ($raw as $section) {
        if (!is_array($section)) {
            continue;
        }
        $out[] = array(
            'heading' => is_string($section['heading'] ?? null) ? trim($section['heading']) : '',
            'body'    => is_string($section['body'] ?? null) ? trim($section['body']) : '',
        );
    }
    return $out;
}

/** One snapshot's sections, in page order. */
function snapshot_sections(int $snapshotId): array
{
    return q(
        'SELECT * FROM snapshot_sections WHERE snapshot_id = ? ORDER BY sort_order, id',
        array($snapshotId)
    )->fetchAll();
}

/**
 * Replace a snapshot's sections wholesale.
 *
 * DELETE-THEN-INSERT, not a diff. The client sends the list it is showing, in
 * the order it is showing it, and that list IS the answer — there is no id to
 * match rows up by, because a section has no identity beyond its position and
 * nobody links to one. A diff would need stable ids round-tripped through the
 * form purely so the server could work out what the client already knows.
 *
 * That also makes sort_order contiguous by construction: it is the loop
 * counter, rewritten every time, so no path can leave a gap or a duplicate.
 *
 * EMPTY SECTIONS ARE DROPPED — a row with neither a heading nor a body is a
 * blank line the form left behind, not content. Passing $keepEmpty keeps them,
 * which is what seeding a new snapshot from its template needs: those rows are
 * deliberately headings with nothing under them yet.
 *
 * @param array $sections list of array('heading' => …, 'body' => …)
 * @return int how many were stored
 */
function snapshot_sections_replace(int $snapshotId, array $sections, bool $keepEmpty = false): int
{
    q('DELETE FROM snapshot_sections WHERE snapshot_id = ?', array($snapshotId));

    $order = 0;
    foreach ($sections as $section) {
        $heading = trim((string) ($section['heading'] ?? ''));
        $body    = trim((string) ($section['body'] ?? ''));

        if (!$keepEmpty && $heading === '' && $body === '') {
            continue;
        }

        $order++;
        q(
            'INSERT INTO snapshot_sections (snapshot_id, sort_order, heading, body)
             VALUES (?, ?, ?, ?)',
            array(
                $snapshotId,
                $order,
                $heading === '' ? null : $heading,
                $body === '' ? null : $body,
            )
        );
    }

    return $order;
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
        /* Re-file into another project ONLY if this row still sits where its
           own date put it. A row placed by hand stays placed — see
           year_project_reassign_on_date() for why that test is the data
           itself rather than a pinned flag on four tables. */
        $existing = snapshot_get($id);
        $moved    = $existing === null ? null : year_project_reassign_on_date(
            (int) $existing['year_project_id'],
            $existing['entry_date'] === null ? null : (string) $existing['entry_date'],
            (string) $fields['entry_date']
        );
        if ($moved !== null) {
            $sets[]   = 'year_project_id = ?';
            $values[] = $moved;
        }
    }
    if (array_key_exists('hero_photo_id', $fields)) {
        $sets[]   = 'hero_photo_id = ?';
        $values[] = ($fields['hero_photo_id'] === null || $fields['hero_photo_id'] === '')
            ? null : (int) $fields['hero_photo_id'];
    }
    if (array_key_exists('title', $fields)) {
        $title    = trim((string) ($fields['title'] ?? ''));
        $sets[]   = 'title = ?';
        $values[] = $title === '' ? null : $title;
    }

    /* Sections are their own table, so they are replaced separately and not
       through $sets. Done BEFORE the UPDATE returns early on an empty $sets:
       editing only the sections — which is most edits — changes no column on
       this row at all, and an early return would silently discard them. */
    if (array_key_exists('sections', $fields)) {
        snapshot_sections_replace($id, is_array($fields['sections']) ? $fields['sections'] : array());
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
    $yearId = year_project_for_new($data, 'captured_at');

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
        /* Re-file into another project ONLY if this row still sits where its
           own date put it. A row placed by hand stays placed — see
           year_project_reassign_on_date() for why that test is the data
           itself rather than a pinned flag on four tables. */
        $existing = photo_get($id);
        $moved    = $existing === null ? null : year_project_reassign_on_date(
            (int) $existing['year_project_id'],
            $existing['captured_at'] === null ? null : (string) $existing['captured_at'],
            (string) $fields['captured_at']
        );
        if ($moved !== null) {
            $sets[]   = 'year_project_id = ?';
            $values[] = $moved;
        }
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

/**
 * Every photo in one project, newest first, for the hero-photo picker.
 *
 * NEWEST FIRST, unlike photos_for_year() right below, which is chronological
 * because it renders a timeline. Picking a hero is a search, and the photo you
 * want is far more often one you added recently than one from the top of
 * January.
 *
 * captured_at DESC rather than id DESC: what matters is when the photo was
 * TAKEN, which after a bulk import of a year's camera roll has nothing to do
 * with the order the rows were inserted.
 */
function photos_for_picker(int $yearProjectId, int $limit = 500): array
{
    return q(
        'SELECT * FROM photos WHERE year_project_id = ? ORDER BY captured_at DESC, id DESC LIMIT ' . (int) $limit,
        array($yearProjectId)
    )->fetchAll();
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
 * Creation, manual by default. Brief §5.2 originally described this as the
 * ONLY way a group exists (Phase 4's auto-detector hadn't run yet), so
 * is_manual_name defaulted to 1 unconditionally — every field on a
 * hand-made group was a manual decision, not something a future
 * auto-naming pass should ever overwrite.
 *
 * Phase 4 (lib/grouping.php) is now the other caller, and needs the
 * opposite default: a freshly auto-detected group's name IS something its
 * own next run should be allowed to revise (e.g. once a joining photo
 * brings GPS the first pass didn't have) — so it passes
 * 'is_manual_name' => false explicitly. Every existing manual caller
 * (public/api/event-groups-create.php, event_group_split()) sends no such
 * key and keeps getting 1, unchanged.
 *
 * @param array{
 *   year_project_id:int, name:string, start_date:string, end_date:string,
 *   location_name?:?string, is_manual_name?:bool
 * } $data
 * @return int the new event_groups id
 */
function event_group_create(array $data): int
{
    $isManual = array_key_exists('is_manual_name', $data) ? (bool) $data['is_manual_name'] : true;

    q(
        'INSERT INTO event_groups
            (year_project_id, name, start_date, end_date, location_name, is_manual_name)
         VALUES (?, ?, ?, ?, ?, ?)',
        array(
            $data['year_project_id'],
            $data['name'],
            $data['start_date'],
            $data['end_date'],
            (isset($data['location_name']) && $data['location_name'] !== '') ? $data['location_name'] : null,
            $isManual ? 1 : 0,
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

/* ------------------------------------------------------- book layout tables
 *
 * Phase 5's three tables (schema.sql's BOOK LAYOUT section): book_layouts ->
 * book_pages -> book_page_photos. Plain CRUD only — every decision about WHAT
 * a page should contain lives in lib/layout.php, the same split that keeps
 * lib/grouping.php's clustering out of this file.
 *
 * VERSIONS ARE NEVER OVERWRITTEN (brief §4.5). There is deliberately no
 * "replace this layout's pages" function: generating again means
 * book_layout_create() and a new version, and reflowing means
 * book_layout_delete_pages_from() inside one version. Those are the only two
 * ways pages ever change, so there is no third path for a future caller to
 * reach for by accident.
 */

/** Hard ceiling on slots per page — book_page_photos' own CHECK (1..4). Kept
 * as its own constant here (rather than importing lib/layout.php's identical
 * LAYOUT_MAX_SLOTS) so this file has no dependency on the layer built on top
 * of it — repo.php is loaded on its own by callers that never touch
 * lib/layout.php at all (e.g. public/api/photos-update.php). */
const BOOK_PAGE_MAX_SLOTS = 4;

/**
 * A new layout for a year, at the next version number for that year.
 *
 * MAX(version)+1 read and written in two statements, exactly as schema.sql's
 * comment on book_layouts.version describes. The read-then-write race is real
 * but harmless: uniq_year_version makes the loser's INSERT fail rather than
 * letting two layouts both call themselves version 4. Single-user app, one
 * button, no transaction needed to make that safe — just an error the caller
 * sees instead of silent duplication.
 */
function book_layout_create(int $yearProjectId): int
{
    $row = q(
        'SELECT COALESCE(MAX(version), 0) + 1 AS next_version
           FROM book_layouts WHERE year_project_id = ?',
        array($yearProjectId)
    )->fetch();

    $version = $row === false ? 1 : max(1, (int) $row['next_version']);

    q(
        'INSERT INTO book_layouts (year_project_id, version) VALUES (?, ?)',
        array($yearProjectId, $version)
    );

    return (int) db()->lastInsertId();
}

function book_layout_get(int $id): ?array
{
    $row = q('SELECT * FROM book_layouts WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/**
 * A year's layouts, newest version first, each with the page counts that make
 * one version comparable to another at a glance (brief §4.5: generation "can
 * be run multiple times ... to preview/compare"). One query with correlated
 * subqueries rather than N+1 — a year has a handful of versions, and the
 * alternative is four extra round trips per row on public/layout.php.
 */
function book_layouts_for_year(int $yearProjectId): array
{
    return q(
        "SELECT bl.*,
                (SELECT COUNT(*) FROM book_pages bp
                  WHERE bp.book_layout_id = bl.id) AS page_count,
                (SELECT COUNT(*) FROM book_pages bp
                  WHERE bp.book_layout_id = bl.id AND bp.page_type = 'photos') AS photo_pages,
                (SELECT COUNT(*) FROM book_pages bp
                  WHERE bp.book_layout_id = bl.id AND bp.page_type = 'text') AS text_pages,
                (SELECT COUNT(*) FROM book_pages bp
                  WHERE bp.book_layout_id = bl.id AND bp.page_type = 'snapshot') AS snapshot_pages,
                (SELECT COUNT(*) FROM book_page_photos bpp
                   JOIN book_pages bp2 ON bp2.id = bpp.book_page_id
                  WHERE bp2.book_layout_id = bl.id) AS slot_count
           FROM book_layouts bl
          WHERE bl.year_project_id = ?
          ORDER BY bl.version DESC",
        array($yearProjectId)
    )->fetchAll();
}

function book_layout_delete(int $id): void
{
    // book_pages (and through them book_page_photos) cascade — see schema.sql.
    q('DELETE FROM book_layouts WHERE id = ?', array($id));

    // active_book_layout_id is deliberately not a foreign key (schema.sql's
    // comment: it would close a circular CREATE TABLE dependency), so nothing
    // clears it for us — same cleanup photo_delete() does for cover_photo_id.
    q('UPDATE year_projects SET active_book_layout_id = NULL WHERE active_book_layout_id = ?', array($id));
}

/**
 * One generated interior page. $snapshotId is required for and permitted only
 * on page_type='snapshot' — schema.sql has a CHECK saying so, and this
 * function passes the caller's value straight through rather than second-
 * guessing it, so a mistake surfaces as a constraint violation on the deploy
 * that supports CHECK rather than as a page that renders blank.
 */
function book_page_create(int $layoutId, int $pageNumber, string $pageType, ?int $snapshotId = null): int
{
    if (!in_array($pageType, array('photos', 'text', 'snapshot'), true)) {
        throw new InvalidArgumentException('bad page_type: ' . $pageType);
    }

    q(
        'INSERT INTO book_pages (book_layout_id, page_number, page_type, snapshot_id)
         VALUES (?, ?, ?, ?)',
        array($layoutId, $pageNumber, $pageType, $snapshotId)
    );

    return (int) db()->lastInsertId();
}

/**
 * One filled slot. $occupant is exactly one of photo_id / quote_id /
 * anecdote_id — book_page_photos' CHECK enforces it in the database; this
 * rejects it earlier, with a message that names the caller's mistake.
 *
 * @param array{photo_id?:int, quote_id?:int, anecdote_id?:int} $occupant
 */
function book_page_slot_create(int $pageId, int $slotNumber, array $occupant): int
{
    $columns = array('photo_id', 'quote_id', 'anecdote_id');
    $values  = array();
    $set     = 0;

    foreach ($columns as $column) {
        $id = isset($occupant[$column]) ? (int) $occupant[$column] : null;
        if ($id !== null && $id > 0) {
            $set++;
        } else {
            $id = null;
        }
        $values[] = $id;
    }

    if ($set !== 1) {
        throw new InvalidArgumentException(
            'a slot holds exactly one of photo_id/quote_id/anecdote_id, got ' . $set
        );
    }

    q(
        'INSERT INTO book_page_photos (book_page_id, slot_number, photo_id, quote_id, anecdote_id)
         VALUES (?, ?, ?, ?, ?)',
        array_merge(array($pageId, $slotNumber), $values)
    );

    return (int) db()->lastInsertId();
}

/** One book_pages row, or null. */
function book_page_get(int $id): ?array
{
    $row = q('SELECT * FROM book_pages WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/**
 * The line that prints at the foot of a page, as it should read right now.
 *
 * Two sources, in order: Kathryn's rewrite if she has made one, otherwise the
 * captions of the page's photos joined in slot order. Captions are authored per
 * photo — she will not know which photo lands on which page, so asking her to
 * caption a page directly would be asking about a page she cannot picture —
 * and this is where they become one line.
 *
 * Returns '' when there is nothing to print, so a caller can test one thing.
 * A page whose rewrite is the empty string counts as deliberately blank: the
 * override is set, so the derived join does not come back.
 *
 * @param array $page  a book_pages row
 * @param list<array> $slots that page's slots in slot order, each with a
 *   'caption' key where the slot holds a photo
 */
function book_page_caption(array $page, array $slots): string
{
    if ($page['caption_override'] !== null) {
        return trim((string) $page['caption_override']);
    }

    $parts = array();
    foreach ($slots as $slot) {
        $c = trim((string) ($slot['caption'] ?? ''));
        if ($c !== '') { $parts[] = $c; }
    }

    /* Middle dot rather than a full stop or a semicolon: the parts are
     * independent captions about different photos, not clauses of a sentence,
     * and punctuation that implies a sentence would read as a mistake once two
     * captions with different subjects land side by side. */
    return implode(' · ', $parts);
}

/**
 * Set or clear a page's caption rewrite. Null restores the derived line —
 * clearing is how she gets back to "whatever the photos say", which is why it
 * is not the same as saving an empty string.
 *
 * Deliberately does NOT touch photos.caption. One page's edit must never
 * rewrite a caption that also appears under that photo elsewhere; see the
 * column comment in schema.sql.
 */
function book_page_set_caption(int $pageId, ?string $text): bool
{
    if (book_page_get($pageId) === null) {
        return false;
    }

    $value = $text === null ? null : trim($text);
    q('UPDATE book_pages SET caption_override = ? WHERE id = ?', array($value, $pageId));
    return true;
}

/**
 * One page's slots in slot order, with the photo fields a caption needs.
 *
 * The single-page counterpart to book_layout_pages_with_content(), which loads
 * a whole layout in two queries and is the wrong tool when an endpoint has just
 * changed one page and wants to answer with that page's new state.
 */
function book_page_slots(int $pageId): array
{
    return q(
        'SELECT bpp.*, p.caption, p.thumb_path, p.width, p.height
           FROM book_page_photos bpp
           LEFT JOIN photos p ON p.id = bpp.photo_id
          WHERE bpp.book_page_id = ?
          ORDER BY bpp.slot_number',
        array($pageId)
    )->fetchAll();
}

/**
 * Set or clear the cover photo's crop. Null restores the centred default.
 *
 * Same normalized 0..1 convention and the same clamping as
 * book_page_slot_set_crop(), because it is the same crop tool on the other end
 * (crop.js) and a caller should not have to remember which kind of crop this
 * one is.
 */
function year_project_set_cover_crop(int $yearProjectId, ?array $rect): bool
{
    if (year_project_get($yearProjectId) === null) {
        return false;
    }

    if ($rect === null) {
        q('UPDATE year_projects SET cover_crop_x = NULL, cover_crop_y = NULL,
                                    cover_crop_w = NULL, cover_crop_h = NULL
            WHERE id = ?', array($yearProjectId));
        return true;
    }

    $clamp = static fn($v): float => max(0.0, min(1.0, (float) $v));
    $x = $clamp($rect['x'] ?? 0);
    $y = $clamp($rect['y'] ?? 0);
    $w = max(0.02, min(1.0 - $x, $clamp($rect['w'] ?? 1)));
    $h = max(0.02, min(1.0 - $y, $clamp($rect['h'] ?? 1)));

    q('UPDATE year_projects SET cover_crop_x = ?, cover_crop_y = ?,
                                cover_crop_w = ?, cover_crop_h = ?
        WHERE id = ?', array($x, $y, $w, $h, $yearProjectId));
    return true;
}

/** One book_page_photos (filled slot) row, or null. */
function book_page_slot_get(int $id): ?array
{
    $row = q('SELECT * FROM book_page_photos WHERE id = ?', array($id))->fetch();
    return $row ?: null;
}

/**
 * Set or clear a PHOTO slot's manual crop override (schema.sql's
 * crop_x/y/w/h — lib/layout_render.php's "adjust crop" feature). $rect null
 * clears it back to NULL (auto-fit resumes); non-null must be exactly the 4
 * normalized fractions crop.js's openCropper() returns.
 *
 * Refuses (returns false, changes nothing) a text-card slot — schema.sql's
 * own comment on these columns says they're meaningless there, and this is
 * where that gets enforced, since a table-level CHECK linking four nullable
 * columns to three others isn't worth the schema noise for one caller to
 * respect. Fails soft on an unknown slot id, same as every other slot
 * mutator in this file.
 */
function book_page_slot_set_crop(int $slotId, ?array $rect): bool
{
    $slot = book_page_slot_get($slotId);
    if ($slot === null || $slot['photo_id'] === null) {
        return false;
    }

    if ($rect === null) {
        q(
            'UPDATE book_page_photos SET crop_x = NULL, crop_y = NULL, crop_w = NULL, crop_h = NULL WHERE id = ?',
            array($slotId)
        );
        return true;
    }

    $clamp = static function ($v): float {
        return max(0.0, min(1.0, (float) $v));
    };
    $x = $clamp($rect['x'] ?? 0);
    $y = $clamp($rect['y'] ?? 0);
    $w = max(0.02, min(1.0 - $x, $clamp($rect['w'] ?? 1)));
    $h = max(0.02, min(1.0 - $y, $clamp($rect['h'] ?? 1)));

    q(
        'UPDATE book_page_photos SET crop_x = ?, crop_y = ?, crop_w = ?, crop_h = ? WHERE id = ?',
        array($x, $y, $w, $h, $slotId)
    );
    return true;
}

/**
 * Phase 6's drag-and-drop (brief §4.4/§5.4): trade the PHOTOS occupying two
 * existing slots — same page or two different pages of the SAME layout,
 * doesn't matter, since a slot's year is derived only through
 * book_page_id -> book_pages.book_layout_id -> book_layouts.year_project_id,
 * never touched here.
 *
 * PHOTOS ONLY, deliberately, echoing schema.sql's own note on
 * book_page_photos.slot_number ("swap these two photos"): a slot currently
 * holding a quote/anecdote card refuses the swap even if asked. Text cards
 * are not draggable in this build (see public/assets/layout.js) — letting
 * one land in a photo's place would leave a page_type='photos' page's card
 * slot rules unclear for no real benefit, since the brief's own phrase for
 * this interaction is "swap two PHOTOS between slots".
 *
 * Swaps only the CONTENT (photo_id) between the two rows, not book_page_id/
 * slot_number — the rows keep their own identity and positions; their
 * occupants trade places. The rendered result is identical to swapping
 * position instead, but this way never touches the (book_page_id,
 * slot_number) unique key, so there's no intermediate state to worry about
 * and no MySQL-specific multi-table UPDATE the SQLite test harness can't run.
 *
 * SAME-LAYOUT ONLY: refuses (returns false) if the two slots belong to
 * different book_layouts rows — different versions of the same year, or
 * different years entirely. A drag-and-drop UI scoped to one open layout
 * can't construct this case through the screen itself; this is the
 * defense-in-depth every other year-isolation check in this app also gets.
 *
 * Fails soft (returns false, changes nothing) rather than throwing: a stale
 * drag target — the other slot was deleted by a reflow that ran in another
 * tab, say — degrades this one action instead of crashing the screen.
 */
function book_page_slot_swap(int $slotIdA, int $slotIdB): bool
{
    if ($slotIdA === $slotIdB) {
        return false;
    }

    $a = book_page_slot_get($slotIdA);
    $b = book_page_slot_get($slotIdB);
    if ($a === null || $b === null || $a['photo_id'] === null || $b['photo_id'] === null) {
        return false;
    }

    $pageA = book_page_get((int) $a['book_page_id']);
    $pageB = book_page_get((int) $b['book_page_id']);
    if ($pageA === null || $pageB === null
        || (int) $pageA['book_layout_id'] !== (int) $pageB['book_layout_id']) {
        return false;
    }

    q('UPDATE book_page_photos SET photo_id = ? WHERE id = ?', array($b['photo_id'], $slotIdA));
    q('UPDATE book_page_photos SET photo_id = ? WHERE id = ?', array($a['photo_id'], $slotIdB));
    return true;
}

/**
 * Phase 6's other drag-and-drop gesture (brief §4.4/§5.4): move a photo
 * slot's occupant onto a DIFFERENT page — at the next open slot_number
 * (1..4, book_page_photos' own CHECK), or a specific one if given and free.
 *
 * PHOTOS ONLY (see book_page_slot_swap()'s header — same reasoning), and the
 * destination must be a page_type='photos' page: a photo can share one of
 * those pages with a text card (schema.sql: "may include ONE text-card slot
 * mixed in among the photos"), but never lands on a page_type='snapshot'
 * page (brief §2.3's fixed template has zero slots, always) or a
 * page_type='text' page (its one slot is a quote/anecdote card, not a
 * photo) — moving onto either would leave that page's page_type
 * disagreeing with what it actually holds.
 *
 * SAME-LAYOUT ONLY, same reasoning as the swap above.
 *
 * Fails soft (returns false, changes nothing): a full destination page, a bad
 * slot_number, a stale page id (deleted by a reflow since the drag started),
 * or a source slot that no longer holds a photo all leave the layout exactly
 * as it was rather than throwing mid-drag.
 *
 * PHASE 8: if moving the photo OUT drains the source page down to zero
 * filled slots (no photos, no text card either — see
 * book_page_delete_and_renumber()'s own header), that now-empty page is
 * deleted and every later page in the same layout is renumbered to close the
 * gap, rather than leaving the empty husk PLAN.md's Phase 6 note flagged as
 * a known, deliberately-unfixed limitation. Same-page reordering can never
 * trigger this — the slot's occupant never actually leaves that page.
 *
 * @return bool
 */
function book_page_slot_move(int $slotId, int $targetPageId, ?int $targetSlotNumber = null): bool
{
    $slot = book_page_slot_get($slotId);
    if ($slot === null || $slot['photo_id'] === null) {
        return false;
    }

    $sourcePage = book_page_get((int) $slot['book_page_id']);
    $targetPage = book_page_get($targetPageId);
    if ($sourcePage === null || $targetPage === null
        || (int) $sourcePage['book_layout_id'] !== (int) $targetPage['book_layout_id']
        || $targetPage['page_type'] !== 'photos') {
        return false;
    }

    $occupied = array();
    foreach (q(
        'SELECT slot_number FROM book_page_photos WHERE book_page_id = ?',
        array($targetPageId)
    )->fetchAll() as $row) {
        $occupied[(int) $row['slot_number']] = true;
    }
    $sourcePageId = (int) $slot['book_page_id'];
    $samePage     = $sourcePageId === $targetPageId;
    // Moving within the SAME page (reordering) doesn't count the slot's own
    // current position as "taken" — it's the one being vacated.
    if ($samePage) {
        unset($occupied[(int) $slot['slot_number']]);
    }

    if ($targetSlotNumber !== null) {
        if ($targetSlotNumber < 1 || $targetSlotNumber > BOOK_PAGE_MAX_SLOTS || isset($occupied[$targetSlotNumber])) {
            return false;
        }
        $newSlotNumber = $targetSlotNumber;
    } else {
        $newSlotNumber = null;
        for ($n = 1; $n <= BOOK_PAGE_MAX_SLOTS; $n++) {
            if (!isset($occupied[$n])) {
                $newSlotNumber = $n;
                break;
            }
        }
        if ($newSlotNumber === null) {
            return false; // the destination page already has 4 filled slots
        }
    }

    q(
        'UPDATE book_page_photos SET book_page_id = ?, slot_number = ? WHERE id = ?',
        array($targetPageId, $newSlotNumber, $slotId)
    );

    if (!$samePage) {
        book_page_delete_and_renumber($sourcePageId);
    }

    return true;
}

/**
 * Phase 8 fix for the gap PLAN.md's Phase 6 note flagged and deliberately
 * left unfixed under that session's time pressure: dragging the last photo
 * off a page used to leave behind an empty page_type='photos' row (0 filled
 * slots) rather than collapsing it out of the book.
 *
 * No-ops (returns false) unless $pageId currently has ZERO rows in
 * book_page_photos — a page still carrying a lone text card (schema.sql:
 * a photos page "may include ONE text-card slot mixed in among the
 * photos") is not empty and is left exactly alone, same as it always was.
 * Deliberately re-checks emptiness itself rather than trusting the caller,
 * so this is safe to call unconditionally.
 *
 * Renumbering walks later pages in ASCENDING page_number order and updates
 * one row at a time: each page's new number is exactly the number the row
 * before it just vacated (the deleted page's own number, then each
 * decremented page's old number), so book_pages.uniq_layout_page
 * (book_layout_id, page_number) is never hit mid-renumber — there's no need
 * for a temporary offset or a single batched UPDATE.
 *
 * Scoped to the page's own book_layout_id throughout, so this can no more
 * reach another version or another year's pages than any other write path
 * in this file (PLAN.md's year/version-isolation rule).
 */
/**
 * Put a layout's pages in a new order.
 *
 * @param int   $layoutId
 * @param int[] $orderedPageIds Every page in this layout, top to bottom. It
 *        must be exactly the layout's own set — same ids, no more, no fewer.
 *        A partial list is refused rather than applied to the pages it covers:
 *        the pages it left out would end up sharing numbers with the ones it
 *        did, and the result reads as a shuffled book rather than as a failed
 *        request.
 * @return bool false if the list does not match the layout's pages.
 *
 * TWO PASSES, because of uniq_layout_page (book_layout_id, page_number). Any
 * one-pass renumber walks into a collision the moment a page moves into a
 * number another page has not yet vacated — moving page 3 to position 1 tries
 * to write a 1 that page 1 is still holding. So every page is first parked
 * beyond the end of the book, then brought back to its final number.
 *
 * The offset is added and subtracted rather than the numbers being written
 * twice, so the second pass cannot itself collide: at that point the parked
 * numbers are already in the new order, just all shifted by the same amount.
 *
 * NO TRANSACTION, matching every other multi-statement write in this file.
 * The consequence of a crash between the passes is a layout whose pages are
 * all numbered above REORDER_PARK — visibly, uniformly wrong rather than
 * subtly wrong, and fixed by re-running this. That is the right failure shape
 * for something with a Generate button that can rebuild it from scratch.
 */
const REORDER_PARK = 30000;

function book_pages_reorder(int $layoutId, array $orderedPageIds): bool
{
    $existing = q(
        'SELECT id FROM book_pages WHERE book_layout_id = ? ORDER BY page_number',
        array($layoutId)
    )->fetchAll();

    $have = array_map('intval', array_column($existing, 'id'));
    $want = array_values(array_map('intval', $orderedPageIds));

    if ($want === array() || count($want) !== count($have)) {
        return false;
    }

    /* Compared as sets: the whole point is that the ORDER differs. array_diff
       both ways rather than sorting and comparing, so a list containing the
       same id twice — which would otherwise pass a naive count check — is
       caught by the missing id on the other side. */
    $sortedHave = $have;
    $sortedWant = $want;
    sort($sortedHave);
    sort($sortedWant);
    if ($sortedHave !== $sortedWant) {
        return false;
    }

    foreach ($want as $index => $pageId) {
        q(
            'UPDATE book_pages SET page_number = ? WHERE id = ? AND book_layout_id = ?',
            array(REORDER_PARK + $index + 1, $pageId, $layoutId)
        );
    }

    q(
        'UPDATE book_pages SET page_number = page_number - ? WHERE book_layout_id = ?',
        array(REORDER_PARK, $layoutId)
    );

    return true;
}

function book_page_delete_and_renumber(int $pageId): bool
{
    $page = book_page_get($pageId);
    if ($page === null) {
        return false;
    }

    $filled = q(
        'SELECT COUNT(*) AS n FROM book_page_photos WHERE book_page_id = ?',
        array($pageId)
    )->fetch();
    if ((int) $filled['n'] !== 0) {
        return false;
    }

    $layoutId   = (int) $page['book_layout_id'];
    $pageNumber = (int) $page['page_number'];

    q('DELETE FROM book_pages WHERE id = ?', array($pageId));

    $later = q(
        'SELECT id, page_number FROM book_pages
          WHERE book_layout_id = ? AND page_number > ?
          ORDER BY page_number',
        array($layoutId, $pageNumber)
    )->fetchAll();

    foreach ($later as $row) {
        q(
            'UPDATE book_pages SET page_number = ? WHERE id = ?',
            array((int) $row['page_number'] - 1, (int) $row['id'])
        );
    }

    return true;
}

/**
 * Every page of a layout in page order, each carrying its own 'slots' list in
 * slot order. Two queries, not one per page: a full year's book is ~100 pages
 * and every reader of this (reflow, the preview screen, Phase 6, Phase 7)
 * wants all of them.
 */
function book_pages_for_layout(int $layoutId): array
{
    $pages = q(
        'SELECT * FROM book_pages WHERE book_layout_id = ? ORDER BY page_number',
        array($layoutId)
    )->fetchAll();

    $byId = array();
    foreach ($pages as $index => $page) {
        $pages[$index]['slots'] = array();
        $byId[(int) $page['id']] = $index;
    }

    $slots = q(
        'SELECT bpp.*
           FROM book_page_photos bpp
           JOIN book_pages bp ON bp.id = bpp.book_page_id
          WHERE bp.book_layout_id = ?
          ORDER BY bpp.book_page_id, bpp.slot_number',
        array($layoutId)
    )->fetchAll();

    foreach ($slots as $slot) {
        $index = $byId[(int) $slot['book_page_id']] ?? null;
        if ($index !== null) {
            $pages[$index]['slots'][] = $slot;
        }
    }

    /* A snapshot page's sections, attached under 'snapshot_sections'.
     *
     * One query for the whole layout rather than one per page: a book has a
     * handful of snapshot pages, but the preview and the exporter both call
     * this, and the exporter already has enough per-page work to do. Keyed by
     * snapshot id on the way back out.
     *
     * These used to be nine columns on the row above and needed no query at
     * all — that is the cost of the sections table, and it is one IN() query. */
    $snapshotIds = array();
    foreach ($pages as $page) {
        if ($page['snapshot_id'] !== null) {
            $snapshotIds[(int) $page['snapshot_id']] = true;
        }
    }

    $sectionsBySnapshot = array();
    if ($snapshotIds !== array()) {
        $ids = array_keys($snapshotIds);
        /* Placeholders built from the COUNT of ids, with the ids themselves
           still bound — the string interpolated into the SQL is only ever
           "?, ?, ?". */
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $rows = q(
            'SELECT * FROM snapshot_sections WHERE snapshot_id IN (' . $in . ') ORDER BY snapshot_id, sort_order, id',
            $ids
        )->fetchAll();

        foreach ($rows as $row) {
            $sectionsBySnapshot[(int) $row['snapshot_id']][] = $row;
        }
    }

    foreach ($pages as $index => $page) {
        $pages[$index]['snapshot_sections'] = $page['snapshot_id'] === null
            ? array()
            : ($sectionsBySnapshot[(int) $page['snapshot_id']] ?? array());
    }

    return $pages;
}

/**
 * The same list, with each slot's actual content joined on — thumbnails and
 * text for a preview screen, so it doesn't fetch a row per slot. Kept separate
 * from book_pages_for_layout() because reflow and Phase 7's export want the
 * ids and nothing else; this one is for anything that has to SHOW the page.
 *
 * Phase 6 extended the snapshot join to every one of that template's fields
 * (age/height, grade/school/teacher/..., notes) plus the hero photo's own
 * paths — public/layout.php's spread view renders a snapshot page from its
 * real template fields (brief §2.3/§4.3), not just its type and date the way
 * Phase 5's plainer preview did.
 */
function book_layout_pages_with_content(int $layoutId): array
{
    $pages = q(
        "SELECT bp.*,
                s.type AS snapshot_type, s.entry_date AS snapshot_date,
                s.title AS snapshot_title, s.hero_photo_id AS snapshot_hero_photo_id,
                hero.thumb_path AS snapshot_hero_thumb, hero.original_path AS snapshot_hero_original
           FROM book_pages bp
           LEFT JOIN snapshots s ON s.id = bp.snapshot_id
           LEFT JOIN photos hero ON hero.id = s.hero_photo_id
          WHERE bp.book_layout_id = ?
          ORDER BY bp.page_number",
        array($layoutId)
    )->fetchAll();

    $byId = array();
    foreach ($pages as $index => $page) {
        $pages[$index]['slots'] = array();
        $byId[(int) $page['id']] = $index;
    }

    $slots = q(
        'SELECT bpp.*,
                p.thumb_path, p.original_path, p.width, p.height, p.caption, p.captured_at,
                qu.quote_text, qu.who_said_it, qu.entry_date AS quote_date,
                an.anecdote_text, an.entry_date AS anecdote_date
           FROM book_page_photos bpp
           JOIN book_pages bp ON bp.id = bpp.book_page_id
           LEFT JOIN photos p ON p.id = bpp.photo_id
           LEFT JOIN quotes qu ON qu.id = bpp.quote_id
           LEFT JOIN anecdotes an ON an.id = bpp.anecdote_id
          WHERE bp.book_layout_id = ?
          ORDER BY bpp.book_page_id, bpp.slot_number',
        array($layoutId)
    )->fetchAll();

    foreach ($slots as $slot) {
        $index = $byId[(int) $slot['book_page_id']] ?? null;
        if ($index !== null) {
            $pages[$index]['slots'][] = $slot;
        }
    }

    /* A snapshot page's sections, attached under 'snapshot_sections'.
     *
     * One query for the whole layout rather than one per page: a book has a
     * handful of snapshot pages, but the preview and the exporter both call
     * this, and the exporter already has enough per-page work to do. Keyed by
     * snapshot id on the way back out.
     *
     * These used to be nine columns on the row above and needed no query at
     * all — that is the cost of the sections table, and it is one IN() query. */
    $snapshotIds = array();
    foreach ($pages as $page) {
        if ($page['snapshot_id'] !== null) {
            $snapshotIds[(int) $page['snapshot_id']] = true;
        }
    }

    $sectionsBySnapshot = array();
    if ($snapshotIds !== array()) {
        $ids = array_keys($snapshotIds);
        /* Placeholders built from the COUNT of ids, with the ids themselves
           still bound — the string interpolated into the SQL is only ever
           "?, ?, ?". */
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $rows = q(
            'SELECT * FROM snapshot_sections WHERE snapshot_id IN (' . $in . ') ORDER BY snapshot_id, sort_order, id',
            $ids
        )->fetchAll();

        foreach ($rows as $row) {
            $sectionsBySnapshot[(int) $row['snapshot_id']][] = $row;
        }
    }

    foreach ($pages as $index => $page) {
        $pages[$index]['snapshot_sections'] = $page['snapshot_id'] === null
            ? array()
            : ($sectionsBySnapshot[(int) $page['snapshot_id']] ?? array());
    }

    return $pages;
}

/**
 * "Reflow from here"'s destructive half (brief §4.5): drop this layout's pages
 * from $fromPageNumber onward, cascading to their slots, and leave everything
 * before it untouched — including manual edits, which is the entire point.
 *
 * Scoped to ONE book_layout_id, so it can no more reach another version of the
 * same year than it can another year (PLAN.md's year-isolation rule).
 */
function book_layout_delete_pages_from(int $layoutId, int $fromPageNumber): void
{
    q(
        'DELETE FROM book_pages WHERE book_layout_id = ? AND page_number >= ?',
        array($layoutId, max(1, $fromPageNumber))
    );
}
