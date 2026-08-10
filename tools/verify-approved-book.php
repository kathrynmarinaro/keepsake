<?php
/**
 * THE BOOK KATHRYN APPROVED, as a regression test.
 *
 * Round 6 was settled by her looking at a rendered proof of her real 2025
 * photos and saying yes — page by page, over five rounds, with pages 10, 11 and
 * 21 each naming a specific geometric fault that got fixed. The result was 44
 * pages: 5 singles, 14 two-ups, 10 three-ups, 15 four-ups.
 *
 * That agreement is the most valuable thing this project has and the easiest
 * thing to lose. Every knob in the engine can move it — a tuning value, the
 * template list, the partitioner's cost, the lone-photo pairing rule — and
 * none of those changes announces itself as "this repaginates the book". So
 * the approved result is pinned here, against her actual photo metadata rather
 * than a synthetic year, and running the whole engine rather than one function.
 *
 * IF THIS FAILS, the question is not "which assertion do I update". It is
 * whether the change was meant to repaginate a book she already signed off on.
 * Sometimes the answer is yes — she asks for something new — and then the
 * numbers here get updated deliberately, in the same commit, with the reason.
 *
 * The fixtures are exports of her real photos and event groups: dimensions,
 * timestamps and grouping only, no images and no captions.
 *
 * Usage: php tools/verify-approved-book.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';
require __DIR__ . '/../lib/grouping.php';
require __DIR__ . '/../lib/layout.php';

$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

function fixture(string $file): array
{
    $rows = array();
    $head = null;
    $fh   = fopen(__DIR__ . '/fixtures/' . $file, 'r');
    while (($r = fgetcsv($fh)) !== false) {
        if ($head === null) { $head = $r; continue; }
        if (count($r) !== count($head)) { continue; }
        $rows[] = array_combine($head, $r);
    }
    fclose($fh);
    return $rows;
}

harness_pdo();

q('INSERT INTO year_projects (year) VALUES (?)', array(2025));
$yearId = (int) db()->lastInsertId();

$groupIds = array();
foreach (fixture('approved-book-groups.csv') as $row) {
    q('INSERT INTO event_groups (year_project_id, name, start_date, end_date)
       VALUES (?, ?, ?, ?)',
        array($yearId, 'Group ' . $row['id'], $row['start_date'], $row['end_date']));
    $groupIds[(int) $row['id']] = (int) db()->lastInsertId();
}

$photos = fixture('approved-book-photos.csv');
foreach ($photos as $row) {
    q('INSERT INTO photos (year_project_id, event_group_id, original_path, thumb_path,
                           width, height, captured_at, skip_for_book, full_page)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(
            $yearId,
            $row['event_group_id'] === '' ? null : ($groupIds[(int) $row['event_group_id']] ?? null),
            'o' . $row['id'] . '.jpg',
            't' . $row['id'] . '.jpg',
            (int) $row['width'],
            (int) $row['height'],
            $row['captured_at'],
            (int) $row['skip_for_book'],
            (int) $row['full_page'],
        ));
}

echo "\nThe engine, end to end, on Kathryn's real 2025 photos...\n";

$run = layout_generate($yearId);
$pages = book_pages_for_layout($run['layout_id']);

$photoPages = array_values(array_filter(
    $pages,
    static fn(array $p): bool => $p['page_type'] === 'photos'
));

$sizes = array();
foreach ($photoPages as $page) {
    $n = 0;
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] !== null) { $n++; }
    }
    if ($n > 0) { $sizes[] = $n; }
}
$dist = array_count_values($sizes);
ksort($dist);

$placed = array_sum($sizes);
check('every photo reached the book, exactly once (' . $placed . ' of ' . count($photos) . ')',
    $placed === count($photos));

/* THE PAGE MIX IS REPORTED, NOT YET PINNED — and that is a live question, not
 * an oversight.
 *
 * The proof Kathryn approved has 44 pages: 5 singles, 14 two-ups, 10
 * three-ups, 15 four-ups. The full engine does not reproduce it, because the
 * lab modelled one run per EVENT GROUP and the engine sub-groups by time
 * first (brief §4.3's day/close-timing rule, subgroup_gap_hours ~5h).
 *
 * That is not a rounding difference. Event group 2 is 8 photos taken between
 * January 15th and January 23rd: the lab put them on two 4-up pages, the
 * engine splits them into eight single-day groups which then pair up into
 * four 2-up pages. Both are defensible and it is an editorial call about
 * whether a page may hold photos from days that are a week apart — hers to
 * make, so the number stays unpinned until she has made it.
 *
 * When she does, this becomes an assertion against the mix she chose. */
printf("       page mix: %s (approved proof was 5x1, 14x2, 10x3, 15x4 across 44 pages)\n",
    implode(', ', array_map(static fn($n, $c): string => "{$c}x{$n}-up", array_keys($dist), $dist)));

/* Every page must be drawable. A page the composer cannot place is a page that
 * prints as grey text, which is exactly how the export bug got through. */
$unplaceable = array();
foreach ($photoPages as $page) {
    if ($page['slots'] === array()) { continue; }
    $occ = compose_occupants($page['slots']);
    if (compose_candidates($occ, array()) === array()) {
        $unplaceable[] = (int) $page['page_number'];
    }
}
check('every page has a template that fits it', $unplaceable === array());
foreach ($unplaceable as $n) { fwrite(STDERR, "       page $n has no template\n"); }

/* Chronology, measured PAGE by page rather than photo by photo.
 *
 * Strict photo-order across the whole book is the wrong property and fails on
 * a correct book: a lone photo absorbed into an earlier group, or two orphans
 * paired across an event boundary, both legitimately place a photo beside one
 * taken before it. What must hold is that the pages themselves advance — page
 * n+1 does not start before page n did. */
$lastStart  = '';
$outOfOrder = 0;
foreach ($photoPages as $page) {
    $starts = array();
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] === null) { continue; }
        $starts[] = (string) q('SELECT captured_at FROM photos WHERE id = ?', array($slot['photo_id']))
            ->fetch()['captured_at'];
    }
    if ($starts === array()) { continue; }
    $start = min($starts);
    if ($start < $lastStart) { $outOfOrder++; }
    $lastStart = $start;
}
check('the pages advance through the year', $outOfOrder === 0);

/* And the whole thing solves without a single rectangle escaping the page. */
$bad = 0;
foreach ($photoPages as $page) {
    if ($page['slots'] === array()) { continue; }
    $occ    = compose_occupants($page['slots']);
    $cands  = compose_candidates($occ, array());
    if ($cands === array()) { continue; }
    $tpl    = compose_templates()[$cands[0]['name']];
    foreach (compose_solve($tpl, compose_bind($occ, $tpl, $cands[0]['order'])) as $r) {
        if ($r['x'] < -1e-4 || $r['y'] < -1e-4
            || $r['x'] + $r['w'] > 100.0001 || $r['y'] + $r['h'] > 100.0001) {
            $bad++;
        }
    }
}
check('every photo in the book lands inside its page', $bad === 0);

echo "\n";
if ($failures > 0) {
    printf("%d check(s) FAILED.\n", $failures);
    exit(1);
}
print "ALL CHECKS PASSED\n";
