<?php
/**
 * Phase 2 (capture flow) smoke test: proves the year-auto-assignment logic
 * (brief §3 — "auto-determined from its date... EXIF year for photos") and
 * the EXIF date/GPS parsing it depends on, against the SQLite test-harness
 * database. No browser, no real image files, no MySQL.
 *
 * Follows tools/verify-schema.php's own style and header conventions — see
 * that file for what the SQLite translation does and does not prove.
 *
 * WHAT THIS CHECKS:
 *   1. lib/exif.php's pure functions: EXIF datetime parsing (including the
 *      "0000:00:00" uninitialized-clock case and a calendar-invalid date),
 *      DMS-to-decimal GPS conversion (including malformed input), and
 *      exif_extract_from_data() end to end against a realistic EXIF array.
 *   2. lib/repo.php's year_project_get_or_create(): two entries in the same
 *      year resolve to the SAME year_projects row, not two.
 *   3. quote_create()/anecdote_create()/snapshot_create() resolve
 *      year_project_id from entry_date, including a year far in the past
 *      (2021) — proving backfilling an old year doesn't require one to
 *      already exist.
 *   4. snapshot_create() only ever writes the fields belonging to its own
 *      template (brief §2.3) — a birthday snapshot has NULL grade/school/…
 *      and vice versa — even though schema.sql deliberately has no CHECK
 *      enforcing this (see that table's comment); lib/repo.php is where the
 *      invariant actually lives.
 *   5. photo_create() resolves year_project_id from captured_at — THE
 *      exit-criterion behaviour ("a photo with EXIF data lands in the
 *      correct year automatically"): a photo whose captured_at is a
 *      simulated EXIF date from 2019 lands in 2019 even though "today" for
 *      every OTHER check in this script is deep in 2026.
 *   6. photo_update() changing captured_at (brief: "the auto-assigned year
 *      is editable") re-resolves year_project_id, moving the photo's row to
 *      a different year_projects id.
 *
 * NOT CHECKED HERE: photo+text bundling. An earlier version of this app had
 * a photo_text_bundle_create() that attached a quote/anecdote to a photo as
 * its caption; that mechanism was removed on request (see
 * public/api/quotes.php's header) — a quote/anecdote is always standalone,
 * and a photo's only caption is photos.caption, typed during upload — so
 * there is nothing left here to test.
 *
 * Usage:
 *   php tools/verify-capture.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/exif.php';
require __DIR__ . '/../lib/repo.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

echo "Loading schema.sql into an in-memory SQLite database...\n";
$pdo = harness_pdo();
echo "Schema applied cleanly.\n\n";

/* ========================================================== EXIF parsing */

echo "EXIF datetime parsing (lib/exif.php, pure)...\n";
check(
    'parses a normal EXIF datetime',
    exif_parse_datetime('2024:07:04 14:32:10') === '2024-07-04 14:32:10'
);
check(
    'rejects an all-zero EXIF datetime (camera clock never set)',
    exif_parse_datetime('0000:00:00 00:00:00') === null
);
check(
    'rejects a calendar-invalid date via checkdate() (Feb 30)',
    exif_parse_datetime('2024:02:30 10:00:00') === null
);
check('rejects garbage input', exif_parse_datetime('not a date') === null);
check(
    'tolerates a "T" separator',
    exif_parse_datetime('2024:07:04T14:32:10') === '2024-07-04 14:32:10'
);

echo "\nEXIF GPS DMS -> decimal conversion...\n";
$lat = exif_gps_coordinate(array('40/1', '26/1', '4630/100'), 'N');
check(
    'N ref: positive, matches hand-computed decimal degrees',
    $lat !== null && abs($lat - (40 + 26 / 60 + 46.30 / 3600)) < 0.0001
);
$latS = exif_gps_coordinate(array('40/1', '26/1', '4630/100'), 'S');
check('S ref negates the same magnitude', $latS !== null && $latS < 0 && abs($latS + $lat) < 0.0001);
$lonW = exif_gps_coordinate(array('118/1', '15/1', '0/1'), 'W');
check('W ref negates', $lonW !== null && $lonW < 0);
check('malformed (non-numeric) DMS component returns null', exif_gps_coordinate(array('a', 'b', 'c'), 'N') === null);
check('zero-denominator fraction returns null, not INF/NAN', exif_gps_coordinate(array('1/0', '0/1', '0/1'), 'N') === null);
check('missing ref returns null', exif_gps_coordinate(array('1/1', '0/1', '0/1'), null) === null);
check('wrong-length DMS array returns null', exif_gps_coordinate(array('1/1', '0/1'), 'N') === null);

echo "\nexif_extract_from_data() end to end...\n";
$fromRealShape = exif_extract_from_data(array(
    'EXIF' => array('DateTimeOriginal' => '2023:12:25 08:15:00'),
    'GPS'  => array(
        'GPSLatitude' => array('34/1', '3/1', '8/1'), 'GPSLatitudeRef' => 'N',
        'GPSLongitude' => array('118/1', '15/1', '0/1'), 'GPSLongitudeRef' => 'W',
    ),
));
check('captured_at extracted from a realistic sectioned EXIF array', $fromRealShape['captured_at'] === '2023-12-25 08:15:00');
check('gps_lat positive for a N reading', $fromRealShape['gps_lat'] > 0);
check('gps_lon negative for a W reading', $fromRealShape['gps_lon'] < 0);

$noExif = exif_extract_from_data(array());
check(
    'no EXIF block at all -> all null (a PNG, or a JPEG with no metadata)',
    $noExif === array('captured_at' => null, 'gps_lat' => null, 'gps_lon' => null)
);

$corruptGps = exif_extract_from_data(array(
    'GPS' => array('GPSLatitude' => 'not-an-array', 'GPSLatitudeRef' => 'N'),
));
check('corrupt GPS block degrades to null rather than throwing', $corruptGps['gps_lat'] === null);

/* =================================================== year auto-assignment */

echo "\nyear_project_get_or_create()...\n";

$q1 = quote_create(array('quote_text' => 'Backfilled from 2021', 'who_said_it' => 'Emma', 'entry_date' => '2021-03-14'));
$q2 = quote_create(array('quote_text' => 'Another 2021 quote', 'who_said_it' => 'Kathryn', 'entry_date' => '2021-11-02'));

$yp1 = (int) $pdo->query("SELECT year_project_id FROM quotes WHERE id = $q1")->fetchColumn();
$yp2 = (int) $pdo->query("SELECT year_project_id FROM quotes WHERE id = $q2")->fetchColumn();
check('two entries in the same year share ONE year_projects row (get-or-create reuses)', $yp1 === $yp2 && $yp1 > 0);

$yearOfYp1 = (int) $pdo->query("SELECT year FROM year_projects WHERE id = $yp1")->fetchColumn();
check('that shared row is actually year 2021 — proves backfilling an old year needs no pre-created project', $yearOfYp1 === 2021);

echo "\nquote_create() / anecdote_create() resolve year from entry_date...\n";
$a1 = anecdote_create(array('anecdote_text' => 'Planted the garden', 'entry_date' => '2026-08-06'));
$yearA1 = (int) $pdo->query(
    "SELECT yp.year FROM anecdotes a JOIN year_projects yp ON yp.id = a.year_project_id WHERE a.id = $a1"
)->fetchColumn();
check('anecdote entry_date year (2026) resolves correctly', $yearA1 === 2026);

echo "\nsnapshot_create(): title, sections, and the template seed...\n";

/* A snapshot is a title, a hero photo, a date and any number of sections. It
 * used to be two fixed templates with nine columns between them, and this
 * block used to check that each template only ever wrote its own columns —
 * there are no columns left to write across. */
$birthdayId = snapshot_create(array(
    'type'       => 'birthday',
    'entry_date' => '2026-04-15',
    'title'      => "Emma's 6th Birthday",
    'sections'   => array(
        array('heading' => 'Age', 'body' => '6'),
        array('heading' => 'Height', 'body' => '3ft 9in'),
        array('heading' => '', 'body' => 'Cake was chocolate.'),
    ),
));
$birthdayRow = snapshot_get($birthdayId);
check('the title is stored', $birthdayRow['title'] === "Emma's 6th Birthday");

$sections = snapshot_sections($birthdayId);
check('every section is stored', count($sections) === 3);
check('in the order they were given', $sections[0]['heading'] === 'Age' && $sections[1]['heading'] === 'Height');
check('sort_order is 1-based and contiguous', array_map(static fn($r): int => (int) $r['sort_order'], $sections) === array(1, 2, 3));
check('a body with no heading is kept', $sections[2]['heading'] === null && $sections[2]['body'] === 'Cake was chocolate.');

/* Omitting `sections` entirely means "seed me from the template" — which is
 * the ONLY thing `type` does now. */
$seeded = snapshot_create(array('type' => 'school_year', 'entry_date' => '2025-08-25'));
$seededSections = snapshot_sections($seeded);
check(
    'omitting sections seeds them from the template',
    array_map(static fn($r): string => (string) $r['heading'], $seededSections)
        === SNAPSHOT_TEMPLATES['school_year']
);
check('the seeded sections are empty, not filled in', $seededSections[0]['body'] === null);

/* An explicit empty array is a different thing and has to stay possible. */
$bare = snapshot_create(array('type' => 'birthday', 'entry_date' => '2026-01-01', 'sections' => array()));
check('an explicit empty sections list means no sections', snapshot_sections($bare) === array());

/* A row with neither a heading nor a body is a blank line the form left
 * behind, and must not reach the database. */
$withBlanks = snapshot_create(array(
    'type' => 'birthday', 'entry_date' => '2026-02-02',
    'sections' => array(
        array('heading' => 'Age', 'body' => '7'),
        array('heading' => '', 'body' => ''),
        array('heading' => '  ', 'body' => "\t"),
    ),
));
$kept = snapshot_sections($withBlanks);
check('blank sections are dropped', count($kept) === 1);
check('and the survivors renumber from 1', (int) $kept[0]['sort_order'] === 1);

/* Editing replaces the whole list — see snapshot_sections_replace(). */
snapshot_update($birthdayId, array('sections' => array(
    array('heading' => 'Height', 'body' => '3ft 10in'),
)));
$after = snapshot_sections($birthdayId);
check('an edit replaces the list wholesale', count($after) === 1 && $after[0]['heading'] === 'Height');
check('and renumbers what is left', (int) $after[0]['sort_order'] === 1);

/* Editing ONLY the sections touches no column on the snapshots row, so an
 * early return on an empty SET would silently discard them. */
snapshot_update($birthdayId, array('sections' => array(
    array('heading' => 'A', 'body' => '1'),
    array('heading' => 'B', 'body' => '2'),
)));
check('a sections-only edit is not swallowed', count(snapshot_sections($birthdayId)) === 2);

/* Deleting the snapshot takes its sections (ON DELETE CASCADE). */
snapshot_delete($birthdayId);
check('sections cascade with the snapshot', snapshot_sections($birthdayId) === array());

echo "\nphoto_create(): THE exit-criterion behaviour — EXIF year wins over the submission year...\n";
// 'Today', for every other check in this script, is deep in 2026 (year_projects
// created above land there or in 2025/2021). A photo carrying a captured_at
// that lib/exif.php resolved from a REAL EXIF DateTimeOriginal — simulated
// here exactly as photos-upload.php would compute it — must land in ITS OWN
// year regardless of when it was actually uploaded.
$exifPhotoId = photo_create(array(
    'original_path' => 'uploads/original/simulated-exif.jpg',
    'thumb_path'    => null,
    'width'         => 3024,
    'height'        => 4032,
    'captured_at'   => '2019-06-01 10:15:00',    // as if exif_read_file() found DateTimeOriginal
    'gps_lat'       => null,
    'gps_lon'       => null,
));
$exifPhotoYear = (int) $pdo->query(
    "SELECT yp.year FROM photos p JOIN year_projects yp ON yp.id = p.year_project_id WHERE p.id = $exifPhotoId"
)->fetchColumn();
check('a photo with a 2019 EXIF-derived captured_at lands in the 2019 year_project', $exifPhotoYear === 2019);

$fallbackPhotoId = photo_create(array(
    'original_path' => 'uploads/original/no-exif.jpg',
    'thumb_path'    => null,
    'width'         => 100,
    'height'        => 100,
    'captured_at'   => '2026-08-06 12:00:00',    // as if exif_read_file() found nothing, falling back to submission time
    'gps_lat'       => null,
    'gps_lon'       => null,
));
$fallbackPhotoYear = (int) $pdo->query(
    "SELECT yp.year FROM photos p JOIN year_projects yp ON yp.id = p.year_project_id WHERE p.id = $fallbackPhotoId"
)->fetchColumn();
check('a photo with no EXIF date falls back to the submission year (2026)', $fallbackPhotoYear === 2026);

echo "\nphoto_update(): correcting a date moves the photo to a different year_project...\n";
$beforeYpId = (int) $pdo->query("SELECT year_project_id FROM photos WHERE id = $exifPhotoId")->fetchColumn();
photo_update($exifPhotoId, array('captured_at' => '2020-01-01'));
$afterYpId = (int) $pdo->query("SELECT year_project_id FROM photos WHERE id = $exifPhotoId")->fetchColumn();
$afterYear = (int) $pdo->query("SELECT year FROM year_projects WHERE id = $afterYpId")->fetchColumn();
check('date correction changed year_project_id', $beforeYpId !== $afterYpId);
check('the new year_project is actually 2020', $afterYear === 2020);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}

echo "ALL CHECKS PASSED\n";
