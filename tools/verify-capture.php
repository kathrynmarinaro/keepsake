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
 *   7. photo_text_bundle_create() enforces "exactly one of quote_id/
 *      anecdote_id" (lib/repo.php's own guard) AND the database's UNIQUE
 *      constraints (schema.sql) — a second bundle attempt on an
 *      already-bundled photo is rejected by SQLite with foreign_keys/UNIQUE
 *      enforcement ON, the same as MySQL would.
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

echo "\nsnapshot_create() writes only its own template's fields...\n";
$birthdayId = snapshot_create(array(
    'type' => 'birthday', 'entry_date' => '2026-04-15',
    'age' => 6, 'height' => '3ft 9in', 'notes' => 'Cake was chocolate.',
));
$birthdayRow = $pdo->query("SELECT * FROM snapshots WHERE id = $birthdayId")->fetch();
check('birthday snapshot: age is set', (int) $birthdayRow['age'] === 6);
check('birthday snapshot: school-year-only fields are all NULL', $birthdayRow['grade'] === null && $birthdayRow['school'] === null && $birthdayRow['teacher'] === null);

$schoolId = snapshot_create(array(
    'type' => 'school_year', 'entry_date' => '2025-08-25',
    'grade' => '1st grade', 'school' => 'Lincoln Elementary', 'dream_job' => 'marine biologist',
));
$schoolRow = $pdo->query("SELECT * FROM snapshots WHERE id = $schoolId")->fetch();
check('school-year snapshot: grade/school/dream_job are set', $schoolRow['grade'] === '1st grade' && $schoolRow['dream_job'] === 'marine biologist');
check('school-year snapshot: birthday-only fields (age/height) are NULL', $schoolRow['age'] === null && $schoolRow['height'] === null);

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

/* ========================================================== text bundles */

echo "\nphoto_text_bundle_create(): exactly-one-of, application-level...\n";
try {
    photo_text_bundle_create($fallbackPhotoId, null, null);
    check('rejects neither quote_id nor anecdote_id set', false);
} catch (InvalidArgumentException $e) {
    check('rejects neither quote_id nor anecdote_id set', true);
}
try {
    photo_text_bundle_create($fallbackPhotoId, $q1, $a1);
    check('rejects BOTH quote_id and anecdote_id set', false);
} catch (InvalidArgumentException $e) {
    check('rejects BOTH quote_id and anecdote_id set', true);
}

echo "\nphoto_text_bundle_create(): database-enforced uniqueness (schema.sql, foreign_keys ON)...\n";
photo_text_bundle_create($fallbackPhotoId, null, $a1);
check(
    'first bundle on this photo succeeded',
    (int) $pdo->query("SELECT COUNT(*) FROM photo_text_bundles WHERE photo_id = $fallbackPhotoId")->fetchColumn() === 1
);
try {
    photo_text_bundle_create($fallbackPhotoId, $q2, null);
    check('a second bundle on an already-bundled photo is rejected (uniq_photo)', false);
} catch (Throwable $e) {
    check('a second bundle on an already-bundled photo is rejected (uniq_photo)', true);
}

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}

echo "ALL CHECKS PASSED\n";
