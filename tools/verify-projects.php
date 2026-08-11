<?php
/**
 * Projects are not stuck to the year.
 *
 * The original model was that a project IS a calendar year: every *_create()
 * derived one from the date on the row being saved, and there was no other way
 * to make a project. Kathryn asked to be able to make a book for a trip — and
 * for content added from inside a project to land in THAT project whatever its
 * date says — so this file pins down the three rules that replaced it:
 *
 *   1. A project can have no year, and then it must have a name.
 *   2. Creating content with an explicit year_project_id honours it; without
 *      one, the date still decides, exactly as before.
 *   3. Correcting a date re-files content that was filed BY date, and leaves
 *      content that was placed BY HAND where it is. That is the rule that lets
 *      a trip book keep a photo taken in a year it does not cover, without
 *      a "pinned" column on four tables to say so.
 *
 * Also covers the two destructive/bulk operations added alongside them,
 * because both are unusually expensive to get wrong: year_project_delete()
 * (cascades to every child table, and unlinks files) and
 * year_project_export_data() (the backup — a missing table here is not
 * noticed until the day it is needed).
 *
 * Usage: php tools/verify-projects.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* year_project_delete() unlinks photo files, so it reaches
 * imageproc_resolve_upload(), which resolves paths against UPLOAD_DIR. Defined
 * here the same way tools/verify-export.php does it — bootstrap.php is not
 * loaded because it needs a real config.php this build environment has not
 * got. No file ever exists at these paths during the test; the unlink is
 * expected to fail softly, which is itself part of what is being checked. */
define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_DIR', APP_ROOT . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

harness_pdo();

/* ------------------------------------------------ 1. yearless projects */

echo "\nyear_project_create()...\n";

$y2025 = year_project_create(2025);
$trip  = year_project_create(null, 'Iceland');

check('a year project is created', year_project_get($y2025) !== null);
check('a yearless project is created', year_project_get($trip) !== null);
check(
    'the yearless project really has no year',
    year_project_get($trip)['year'] === null
);
check(
    'a yearless project titles itself by its name',
    year_project_title(year_project_get($trip)) === 'Iceland'
);
check(
    'a year project with no title titles itself by its year',
    year_project_title(year_project_get($y2025)) === '2025'
);

/* The unique key still does its job for years, and does NOT get in the way of
   a second yearless project — MySQL and SQLite both allow repeated NULLs in a
   UNIQUE index, which is the whole reason the key could be left alone. */
check(
    'creating an existing year resolves to that same project',
    year_project_create(2025) === $y2025
);

$trip2 = year_project_create(null, 'Maine');
check('a second yearless project is allowed', $trip2 !== $trip && $trip2 > 0);

$threw = false;
try {
    year_project_create(null, null);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a project with neither a year nor a name is refused', $threw);

/* ------------------------------------- 2. explicit project id beats the date */

echo "\nyear_project_for_new()...\n";

/* A quote dated 2023, added from INSIDE the Iceland book. It belongs to
   Iceland — that is the whole request. */
$pinned = quote_create(array(
    'quote_text'      => 'It rained the entire week.',
    'who_said_it'     => 'Kathryn',
    'entry_date'      => '2023-06-01',
    'year_project_id' => $trip,
));
check(
    'an explicit project id wins over the date',
    (int) quote_get($pinned)['year_project_id'] === $trip
);

/* The same quote with no project id still files itself by its date, which is
   what adding from the project LIST screen does. */
$byDate = quote_create(array(
    'quote_text'  => 'Filed by its date.',
    'who_said_it' => 'Emma',
    'entry_date'  => '2023-06-01',
));
$p2023 = year_project_get_by_year(2023);
check('a 2023 project was created on demand', $p2023 !== null);
check(
    'with no explicit id the date still decides',
    (int) quote_get($byDate)['year_project_id'] === (int) $p2023['id']
);

/* A nonexistent id must not be written — the row would point at nothing. */
$bogus = quote_create(array(
    'quote_text'      => 'Bad id falls back to the date.',
    'who_said_it'     => 'Emma',
    'entry_date'      => '2021-03-03',
    'year_project_id' => 999999,
));
check(
    'an id that does not exist falls back to the date',
    (int) quote_get($bogus)['year_project_id'] === (int) year_project_get_by_year(2021)['id']
);

/* ------------------------------------- 3. re-filing on a date correction */

echo "\nyear_project_reassign_on_date()...\n";

/* Filed by date, then corrected: it moves. This is the existing behaviour
   ("Correcting a photo's date now re-groups it") and it has to survive. */
quote_update($byDate, array('entry_date' => '2024-08-08'));
check(
    'a date-filed row moves when its date is corrected',
    (int) quote_get($byDate)['year_project_id'] === (int) year_project_get_by_year(2024)['id']
);

/* Placed by hand into a yearless book, then corrected: it stays. Without this
   rule, fixing a typo in a date would silently empty a trip book. */
quote_update($pinned, array('entry_date' => '2024-08-08'));
check(
    'a hand-placed row stays put when its date is corrected',
    (int) quote_get($pinned)['year_project_id'] === $trip
);

/* Placed by hand into the WRONG year project — a photo taken in December
   added from inside the 2025 book — also stays. The rule is "does it sit
   where its date would have put it", not "does it have a year". */
$crossYear = quote_create(array(
    'quote_text'      => 'Taken in December, belongs in the 2025 book.',
    'who_said_it'     => 'Kathryn',
    'entry_date'      => '2024-12-30',
    'year_project_id' => $y2025,
));
quote_update($crossYear, array('entry_date' => '2024-12-31'));
check(
    'a row hand-placed in another year stays there',
    (int) quote_get($crossYear)['year_project_id'] === $y2025
);

/* Same year, different day: nothing to re-file, and nothing should churn. */
$before = (int) quote_get($byDate)['year_project_id'];
quote_update($byDate, array('entry_date' => '2024-08-09'));
check(
    'a date change within the same year leaves the project alone',
    (int) quote_get($byDate)['year_project_id'] === $before
);

/* ------------------------------------------------------- 4. counts + delete */

echo "\nyear_project_counts() / year_project_delete()...\n";

$doomed = year_project_create(null, 'Doomed');

quote_create(array('quote_text' => 'q', 'who_said_it' => 'Kathryn', 'entry_date' => '2025-01-01', 'year_project_id' => $doomed));
anecdote_create(array('anecdote_text' => 'a', 'entry_date' => '2025-01-02', 'year_project_id' => $doomed));
$groupId = event_group_create(array(
    'year_project_id' => $doomed,
    'name'            => 'A day out',
    'start_date'      => '2025-01-01',
    'end_date'        => '2025-01-01',
));
$photoId = photo_create(array(
    'year_project_id' => $doomed,
    'original_path'   => 'original/none.jpg',
    'thumb_path'      => 'thumb/none.jpg',
    'captured_at'     => '2025-01-01 09:00:00',
    'width'           => 100,
    'height'          => 100,
));
$layoutId = book_layout_create($doomed);

$counts = year_project_counts($doomed);
check('counts sees the quote', $counts['quotes'] === 1);
check('counts sees the anecdote', $counts['anecdotes'] === 1);
check('counts sees the photo', $counts['photos'] === 1);
check('counts sees the group', $counts['groups'] === 1);
check('counts sees the layout', $counts['layouts'] === 1);

year_project_delete($doomed);

check('the project is gone', year_project_get($doomed) === null);
check('its photo cascaded', photo_get($photoId) === null);
check('its group cascaded', event_group_get($groupId) === null);
check('its layout cascaded', book_layout_get($layoutId) === null);
check(
    'nothing else was touched',
    year_project_get($trip) !== null && year_project_get($y2025) !== null
);

/* ------------------------------------------------------------- 5. export */

echo "\nyear_project_export_data()...\n";

$exported = year_project_export_data($trip);

check('an export is produced', is_array($exported));
check('it names the book', ($exported['display_title'] ?? null) === 'Iceland');
check('it carries the project row', isset($exported['project']['id']));

/* Every content table has to be in there. This list is the point of the test:
   the failure that matters is a table quietly missing from the backup, and
   nobody finds out until they need it. */
foreach (array('event_groups', 'photos', 'quotes', 'anecdotes', 'snapshots', 'book_layouts') as $key) {
    check('it carries ' . $key, array_key_exists($key, $exported));
}

/* Exactly the one quote that is in Iceland — $crossYear went into the 2025
   book and must not leak into this project's export. */
check(
    'it carries only the quotes that are in this project',
    count($exported['quotes']) === 1
        && (int) $exported['quotes'][0]['id'] === $pinned
);

check('a missing project exports as null', year_project_export_data(999999) === null);

/* Layout pages nest under their layout rather than arriving as a flat list. */
$withLayout = year_project_create(null, 'Has a layout');
$lid = book_layout_create($withLayout);
book_page_create($lid, 1, 'photos');
$exported2 = year_project_export_data($withLayout);
check(
    'layout pages nest under their layout',
    isset($exported2['book_layouts'][0]['pages'][0]['page_number'])
);
check(
    'pages carry their slots array',
    isset($exported2['book_layouts'][0]['pages'][0]['slots'])
        && is_array($exported2['book_layouts'][0]['pages'][0]['slots'])
);

/* ------------------------------------------------------------ 6. list order */

echo "\nyear_project_list()...\n";

$list = year_project_list();
check('every surviving project is listed', count($list) >= 5);

$years = array();
foreach ($list as $row) {
    $years[] = $row['year'] !== null
        ? (int) $row['year']
        : (int) substr((string) $row['created_at'], 0, 4);
}
$sorted = $years;
rsort($sorted);
check('the list is in descending year order', $years === $sorted);
check(
    'yearless projects are interleaved, not dumped at the end',
    in_array(null, array_column($list, 'year'), true)
);

/* -------------------------------------------------------- 7. page reorder */

echo "\nbook_pages_reorder()...\n";

$reorderProject = year_project_create(null, 'Reorderable');
$reorderLayout  = book_layout_create($reorderProject);

$pageIds = array();
foreach (range(1, 5) as $n) {
    $pageIds[] = book_page_create($reorderLayout, $n, 'photos');
}

/** The layout's page ids in stored page_number order. */
function page_order(int $layoutId): array
{
    return array_map(
        'intval',
        array_column(
            q(
                'SELECT id FROM book_pages WHERE book_layout_id = ? ORDER BY page_number',
                array($layoutId)
            )->fetchAll(),
            'id'
        )
    );
}

check('the pages start in creation order', page_order($reorderLayout) === $pageIds);

/* Move the LAST page to the front. This is the case a one-pass renumber
   cannot do: page 5 has to become page 1 while page 1 still holds a 1, and
   uniq_layout_page (book_layout_id, page_number) refuses that. */
$moved = array($pageIds[4], $pageIds[0], $pageIds[1], $pageIds[2], $pageIds[3]);
check('a reorder is accepted', book_pages_reorder($reorderLayout, $moved) === true);
check('the last page is now the first', page_order($reorderLayout) === $moved);

/* And the numbers themselves have to come out 1..5 with no gaps and nothing
   left parked above REORDER_PARK — a half-applied reorder would still pass an
   ORDER BY check while printing "Page 30001" on the screen. */
$numbers = array_map(
    'intval',
    array_column(
        q(
            'SELECT page_number FROM book_pages WHERE book_layout_id = ? ORDER BY page_number',
            array($reorderLayout)
        )->fetchAll(),
        'page_number'
    )
);
check('the numbers are 1..5 with no gaps', $numbers === array(1, 2, 3, 4, 5));

/* Reversing is the other end of the same problem. */
$reversed = array_reverse($moved);
check('a full reversal is accepted', book_pages_reorder($reorderLayout, $reversed) === true);
check('the reversal took', page_order($reorderLayout) === $reversed);

/* Everything below must be refused and must leave the order alone — a request
   that is not exactly this layout's pages is a bug or a stale tab, and
   applying the part of it that makes sense produces a shuffled book. */
$before = page_order($reorderLayout);

check(
    'a short list is refused',
    book_pages_reorder($reorderLayout, array_slice($reversed, 0, 3)) === false
);
check(
    'a list with a duplicate is refused',
    book_pages_reorder(
        $reorderLayout,
        array($reversed[0], $reversed[0], $reversed[2], $reversed[3], $reversed[4])
    ) === false
);
check(
    'a list with a foreign page id is refused',
    book_pages_reorder(
        $reorderLayout,
        array($reversed[0], $reversed[1], $reversed[2], $reversed[3], 999999)
    ) === false
);
check('an empty list is refused', book_pages_reorder($reorderLayout, array()) === false);
check('none of the refusals moved anything', page_order($reorderLayout) === $before);

/* ------------------------------------------- 8. the header's second line */

echo "\nproject_sub_line() / a title that is just the year...\n";

require_once __DIR__ . '/../lib/page.php';

/* THE BUG THIS PINS DOWN. The header showed "2025" over "2025" and the real
 * subtitle was nowhere. Two causes, both fixed here:
 *
 *   1. The rule put the YEAR on the second line whenever a book had a name,
 *      which is nonsense when the name IS the year.
 *   2. The book had a stored title of "2025" at all — saved by a rename dialog
 *      that pre-filled itself from the DISPLAYED name.
 */
$dupe = year_project_create(2019);
q(
    'UPDATE year_projects SET title = ?, subtitle = ? WHERE id = ?',
    array('2019', 'Emma & Kathryn', $dupe)
);
$row = year_project_get($dupe);

check('the heading is still the year', year_project_title($row) === '2019');
check(
    'the second line is the subtitle, not the year again',
    project_sub_line($row) === 'Emma & Kathryn'
);
check('the second line never repeats the heading', project_sub_line($row) !== year_project_title($row));

/* And saving through the normal path repairs the stored value, so a row
   already in this state heals itself rather than needing a hand-written
   UPDATE. */
year_project_update_title($dupe, '2019');
check(
    'a title equal to the year is stored as NULL',
    year_project_get($dupe)['title'] === null
);
check(
    'and the book still shows the same name afterwards',
    year_project_title(year_project_get($dupe)) === '2019'
);

/* A real name is untouched by that normalization. */
$named = year_project_create(2018, 'The Long Summer');
check('a real title is stored as typed', year_project_get($named)['title'] === 'The Long Summer');
check(
    'a named book with no subtitle falls back to its year',
    project_sub_line(year_project_get($named)) === '2018'
);

/* A yearless book with neither has nothing to put there, and must not invent
   something — an empty string is a line that does not render. */
$bare = year_project_create(null, 'Just a name');
check('a yearless book with no subtitle has no second line', project_sub_line(year_project_get($bare)) === '');

echo "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " CHECK(S) FAILED.\n");
exit($failures === 0 ? 0 : 1);
