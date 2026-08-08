<?php
/**
 * Phase 5 (book layout engine) smoke test: proves the pure arrangement
 * heuristics on synthetic input, then runs the whole engine end to end
 * against the SQLite test-harness database on a synthetic year's worth of
 * content. No browser, no MySQL, no network.
 *
 * Follows tools/verify-grouping.php's own style and header conventions.
 *
 * WHAT THIS CHECKS:
 *   1. layout_orientation(): portrait/landscape, and the two things that
 *      resolve to the 'flex' wildcard (a square photo, a photo with no
 *      stored dimensions).
 *   2. layout_orientation_score(): a matched pair beats a mixed one; a
 *      wildcard heals an otherwise lopsided 3+1 page; a lone photo scores
 *      below a matched pair (the value that keeps this engine out of the
 *      one-photo-per-page look).
 *   3. layout_variety_penalty() / layout_choose_page_size(): repeating a
 *      page size costs more each time, so density VARIES across a run of
 *      identically-shaped photos instead of sticking on one number; a size
 *      that would strand a single photo is avoided; a text card lowers the
 *      photo ceiling from 4 to 3.
 *   4. layout_subgroup_photos(): brief §4.3's day/close-timing pass — a
 *      beach morning and a dinner that evening (same day) land in different
 *      sub-groups, while photos minutes apart stay together, using the
 *      SAME clustering primitive Phase 4's coarser pass uses.
 *   5. layout_text_is_long(): the tunable ~180-char threshold, either side
 *      of the boundary, counted in characters and not bytes.
 *   6. layout_assign_texts(): text inside an event's date range lands on
 *      that event; a leftover within the attach window rides along with the
 *      nearest page-group; a leftover outside it stands alone.
 *   7. layout_generate() end to end on a synthetic 2024: pages are NOT all
 *      1-up, every eligible photo appears exactly once, page numbering is
 *      dense, full-page photos land alone, snapshots land alone as
 *      page_type='snapshot' with snapshot_id set and no slots, a long
 *      anecdote gets a page_type='text' page, short quotes ride in a slot
 *      on a photo page, skip_for_book photos appear NOWHERE, and a
 *      snapshot's hero photo is not also loose in the flow.
 *   8. Versioning (brief §4.5): regenerating INSERTs version 2 and leaves
 *      version 1's pages byte-for-byte as they were.
 *   9. "Reflow from here" (brief §4.5): after a simulated manual edit
 *      (a photo swapped directly in book_page_photos on an early page),
 *      reflowing from page N leaves every page before N byte-for-byte
 *      untouched — manual edit included — regenerates from N onward, does
 *      not duplicate content already used on the retained pages, and puts
 *      the evicted photo back into the flow.
 *  10. Fail-soft: a year with no content at all, and an event group holding
 *      a single photo, both generate rather than throwing.
 *
 * Usage:
 *   php tools/verify-layout.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

/* lib/bootstrap.php is NOT loaded here — same reason every other tools/verify-*
 * script skips it: it requires a real config.php this build environment
 * doesn't have. lib/layout.php and lib/grouping.php both call cfg(), so this
 * test-only shim reads config.example.php directly, which is exactly the set
 * of tunables and defaults Kathryn's real config.php would carry. */
if (!function_exists('cfg')) {
    function cfg(string $path, $default = null)
    {
        $node = $GLOBALS['config'] ?? array();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

require __DIR__ . '/../lib/grouping.php';
require __DIR__ . '/../lib/layout.php';

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

$TUNING = layout_tuning();

/* ===================================================== pure: orientation == */

echo "layout_orientation(): shape from stored dimensions...\n";
check('a tall photo is portrait', layout_orientation(array('width' => 800, 'height' => 1200)) === 'portrait');
check('a wide photo is landscape', layout_orientation(array('width' => 1200, 'height' => 800)) === 'landscape');
check('a square photo is the flex wildcard', layout_orientation(array('width' => 1000, 'height' => 1000)) === 'flex');
check(
    'a photo with no stored dimensions is flex, not an error (fail soft)',
    layout_orientation(array('width' => null, 'height' => null)) === 'flex'
);

echo "\nlayout_orientation_score(): what reads well on a square page...\n";
$pairMatched = layout_orientation_score(array('portrait', 'portrait'));
$pairMixed   = layout_orientation_score(array('portrait', 'landscape'));
$single      = layout_orientation_score(array('portrait'));
check('two portraits side by side score the maximum', $pairMatched === 1.0);
check('two landscapes stacked score the same as two portraits', layout_orientation_score(array('landscape', 'landscape')) === $pairMatched);
check('a portrait + landscape pair scores materially worse', $pairMixed < $pairMatched - 0.3);
check('a single photo scores BELOW a matched pair (no drift to one-per-page)', $single < $pairMatched);
check('a single photo still scores above the awkward mixed pair', $single > $pairMixed);
check(
    'a flex element heals a lopsided 3+1 page',
    layout_orientation_score(array('portrait', 'portrait', 'portrait', 'flex'))
        > layout_orientation_score(array('portrait', 'portrait', 'portrait', 'landscape'))
);
check(
    'a 2+2 mix (uniform rows) beats 4 of a kind',
    layout_orientation_score(array('portrait', 'portrait', 'landscape', 'landscape'))
        > layout_orientation_score(array('portrait', 'portrait', 'portrait', 'portrait'))
);
check('an over-full page scores 0 rather than blowing up', layout_orientation_score(array('portrait', 'portrait', 'portrait', 'portrait', 'portrait')) === 0.0);

/* ======================================================== pure: variety === */

echo "\nlayout_variety_penalty(): repeating a page size costs more each time...\n";
$p0 = layout_variety_penalty(2, array(), $TUNING);
$p1 = layout_variety_penalty(2, array(2), $TUNING);
$p2 = layout_variety_penalty(2, array(2, 2), $TUNING);
check('an unseen size costs nothing', $p0 === 0.0);
check('one repeat costs something', $p1 > 0.0);
check('two in a row cost strictly more than one', $p2 > $p1);
check(
    'a size seen earlier but broken up costs less than the same size just used',
    layout_variety_penalty(2, array(2, 3), $TUNING) < $p1
);
check('a different size is unaffected by the run', layout_variety_penalty(3, array(2, 2), $TUNING) === 0.0);
check(
    'setting repeat_penalty to 0 turns the variety heuristic off entirely',
    layout_variety_penalty(2, array(2, 2, 2), array_merge($TUNING, array('variety_repeat_penalty' => 0.0))) === 0.0
);

echo "\nlayout_choose_page_size(): the next page's photo count...\n";
$sixLandscapes = array_fill(0, 6, 'landscape');
check(
    'a fresh run of landscapes starts with a matched pair, not a single',
    layout_choose_page_size($sixLandscapes, array(), false, $TUNING) === 2
);
check(
    'after two 2-ups in a row the engine picks something else',
    layout_choose_page_size($sixLandscapes, array(2, 2), false, $TUNING) !== 2
);
check(
    // PLAN.md Round 4: variety credit used to be able to make a FRESH 1-up
    // out-score a REPEATED multi-photo size, even with plenty of photos
    // left to pair — "avoid monotony" quietly fighting "avoid singles".
    // singles_penalty exists specifically so this never happens: as long as
    // more than one photo remains, a long run of the SAME size still isn't
    // a reason to drop to one.
    'a long run of repeated 2-ups still does not drop to a single photo',
    layout_choose_page_size($sixLandscapes, array(2, 2, 2, 2), false, $TUNING) !== 1
);
check(
    'a size that would strand exactly one photo is avoided (3 left -> 3, not 2)',
    layout_choose_page_size(array('landscape', 'landscape', 'landscape'), array(), false, $TUNING) === 3
);
check(
    'a text card lowers the photo ceiling to 3 (4 slots total)',
    layout_choose_page_size(array_fill(0, 8, 'portrait'), array(2, 2, 2), true, $TUNING) <= 3
);
check('nothing left means no page', layout_choose_page_size(array(), array(), false, $TUNING) === 0);

echo "\nlayout_card_schedule(): text cards spread, not bunched...\n";
check('no pages means no schedule', layout_card_schedule(0, 3) === array());
check('one card across five pages lands in the middle', layout_card_schedule(5, 1) === array(2));
check('two cards across four pages are spread apart', layout_card_schedule(4, 2) === array(1, 3));
check('more cards than pages caps at one per page', count(layout_card_schedule(2, 5)) === 2);

echo "\nlayout_partition_subgroup(): density varies across a long run...\n";
$partition = layout_partition_subgroup(array_fill(0, 14, 'landscape'), 0, array(), $TUNING);
$sizes = array_map(static fn(array $p): int => $p['count'], $partition);
check('every photo is placed exactly once', array_sum($sizes) === 14);
check('no page is empty', min($sizes) >= 1);
check('no page exceeds four photos', max($sizes) <= 4);
check(
    'density is NOT stuck on one number across 14 identical photos (brief §4.3)',
    count(array_unique($sizes)) > 1
);
check('the book is not one-photo-per-page', count($partition) < 14);
echo '       (14 landscapes partitioned as: ' . implode(', ', $sizes) . ")\n";

$withCards = layout_partition_subgroup(array_fill(0, 9, 'portrait'), 2, array(), $TUNING);
$cardPages = array_values(array_filter($withCards, static fn(array $p): bool => $p['card']));
check('both text cards were given a page to ride on', count($cardPages) === 2);
check(
    'no page carrying a card exceeds 4 slots',
    max(array_map(static fn(array $p): int => $p['count'] + ($p['card'] ? 1 : 0), $withCards)) <= 4
);
$cardsOnly = layout_partition_subgroup(array(), 2, array(), $TUNING);
check('cards with no photos at all become pages of their own', count($cardsOnly) === 2 && $cardsOnly[0]['count'] === 0);

/* ============================================== pure: day / close timing == */

echo "\nlayout_subgroup_photos(): day / close-timing sub-grouping (brief §4.3)...\n";
$shot = static fn(int $id, string $at): array => array('id' => $id, 'captured_at' => $at);

$oneDay = array(
    $shot(1, '2024-07-04 09:00:00'),
    $shot(2, '2024-07-04 09:20:00'),
    $shot(3, '2024-07-04 10:05:00'),
    $shot(4, '2024-07-04 19:00:00'),  // dinner that evening
    $shot(5, '2024-07-04 19:30:00'),
);
$clusters = layout_subgroup_photos($oneDay, (float) $TUNING['subgroup_gap_hours']);
check('a beach morning and a dinner that evening split, SAME calendar day', count($clusters) === 2);
check('the morning keeps its three photos together', count($clusters[0]) === 3);
check('the evening keeps its two together', count($clusters[1]) === 2);

$overnight = array($shot(1, '2024-07-04 21:00:00'), $shot(2, '2024-07-05 09:00:00'));
check('a night apart splits', count(layout_subgroup_photos($overnight, (float) $TUNING['subgroup_gap_hours'])) === 2);

$midnight = array($shot(1, '2024-12-31 23:30:00'), $shot(2, '2025-01-01 00:20:00'));
check(
    'a party across midnight stays on one page (no hard calendar-day rule)',
    count(layout_subgroup_photos($midnight, (float) $TUNING['subgroup_gap_hours'])) === 1
);
check('a single photo is a sub-group of one, not an error', count(layout_subgroup_photos(array($shot(1, '2024-07-04 09:00:00')), 5.0)) === 1);
check('no photos at all yields no sub-groups', layout_subgroup_photos(array(), 5.0) === array());

/* Phase 4's own coarse pass must still mean what it meant — same primitive,
   different metric (see event_grouping_cluster_by()'s comment). */
check(
    "Phase 4's calendar-day clustering is unchanged by sharing the primitive",
    count(event_grouping_cluster_ungrouped(array(
        $shot(1, '2024-07-01 00:01:00'),
        $shot(2, '2024-07-04 23:59:00'),
    ), 3)) === 1
);

/* ==================================================== pure: text handling = */

echo "\nlayout_text_is_long(): the tunable ~180-character threshold...\n";
$threshold = (int) $TUNING['text_page_chars'];
check('the configured default is the brief\'s 180', $threshold === 180);
check('exactly at the threshold shares a page', layout_text_is_long(str_repeat('a', 180), 180) === false);
check('one character over gets its own page', layout_text_is_long(str_repeat('a', 181), 180) === true);
check('surrounding whitespace does not push it over', layout_text_is_long('  ' . str_repeat('a', 180) . '  ', 180) === false);
check('length is counted in characters, not bytes', layout_text_is_long(str_repeat('é', 100), 180) === false);

echo "\nlayout_assign_texts(): placing a quote by date (brief §4.3)...\n";
$subgroupsFixture = array(
    array('event_group_id' => 7, 'start_date' => '2024-07-04', 'end_date' => '2024-07-04'),
    array('event_group_id' => 7, 'start_date' => '2024-07-06', 'end_date' => '2024-07-06'),
    array('event_group_id' => null, 'start_date' => '2024-09-10', 'end_date' => '2024-09-10'),
);
$groupsFixture = array(array('id' => 7, 'start_date' => '2024-07-04', 'end_date' => '2024-07-06'));

$assigned = layout_assign_texts(
    array(
        array('kind' => 'quote', 'id' => 1, 'text' => 'in range, nearest the 6th', 'date' => '2024-07-06'),
        array('kind' => 'quote', 'id' => 2, 'text' => 'leftover, one day from the September photos', 'date' => '2024-09-11'),
        array('kind' => 'quote', 'id' => 3, 'text' => 'leftover, nowhere near anything', 'date' => '2024-12-25'),
    ),
    $subgroupsFixture,
    $groupsFixture,
    (int) $TUNING['text_attach_days']
);
check('a quote inside an event\'s range lands on that event', isset($assigned['assigned'][1]));
check('...on the page-group closest to its own date', count($assigned['assigned'][1] ?? array()) === 1);
check('a leftover within the attach window rides along with nearby photos', isset($assigned['assigned'][2]));
check('a leftover with nothing near it stands alone', count($assigned['standalone']) === 1);
check('...and it is the December one', $assigned['standalone'][0]['id'] === 3);

$noSubgroups = layout_assign_texts(
    array(array('kind' => 'anecdote', 'id' => 9, 'text' => 'a year with no photos at all', 'date' => '2024-05-05')),
    array(),
    array(),
    2
);
check('with no page-groups anywhere, text stands alone rather than erroring', count($noSubgroups['standalone']) === 1);

/* ==================================================== a synthetic year ==== */

echo "\nBuilding a synthetic 2024 (event group, full-page flag, skip flag, snapshot, loose text)...\n";

/** Insert a photo directly (this test is about layout, not capture). */
function make_photo(string $capturedAt, string $shape = 'landscape', array $flags = array()): int
{
    $dims = array(
        'landscape' => array(1200, 800),
        'portrait'  => array(800, 1200),
        'square'    => array(1000, 1000),
    );
    [$w, $h] = $dims[$shape];

    $id = photo_create(array(
        'original_path' => 'uploads/original/test.jpg',
        'thumb_path'    => 'uploads/thumb/test.webp',
        'width'         => $w,
        'height'        => $h,
        'captured_at'   => $capturedAt,
        'gps_lat'       => null,
        'gps_lon'       => null,
    ));

    if ($flags !== array()) {
        photo_update($id, $flags);
    }
    return $id;
}

$yp = year_project_get_or_create('2024-01-01');

/* --- March: a birthday snapshot with a hero photo, plus one loose photo. */
$heroPhoto  = make_photo('2024-03-15 10:00:00', 'portrait');
$marchLoose = make_photo('2024-03-15 10:05:00', 'landscape');
$snapshotId = snapshot_create(array(
    'type'          => 'birthday',
    'entry_date'    => '2024-03-15',
    'hero_photo_id' => $heroPhoto,
    'age'           => 7,
    'height'        => '4\'0"',
    'notes'         => 'Cake in the backyard.',
));

/* --- July: one event group, four day/close-timing sub-groups inside it. */
$beach = event_group_create(array(
    'year_project_id' => $yp,
    'name'            => 'Jul 4–6 · Myrtle Beach',
    'start_date'      => '2024-07-04',
    'end_date'        => '2024-07-06',
    'is_manual_name'  => false,
));

$julyMorning = array(
    make_photo('2024-07-04 09:00:00', 'landscape'),
    make_photo('2024-07-04 09:15:00', 'landscape'),
    make_photo('2024-07-04 09:30:00', 'portrait'),
    make_photo('2024-07-04 10:00:00', 'portrait'),
);
$julyEvening = array(
    make_photo('2024-07-04 19:00:00', 'portrait'),
    make_photo('2024-07-04 19:20:00', 'portrait'),
    make_photo('2024-07-04 19:40:00', 'landscape'),
);
$julyMidday = array(
    make_photo('2024-07-05 12:00:00', 'landscape'),
    make_photo('2024-07-05 12:10:00', 'landscape'),
    make_photo('2024-07-05 12:20:00', 'landscape'),
    make_photo('2024-07-05 12:30:00', 'portrait'),
    make_photo('2024-07-05 12:40:00', 'portrait'),
);
$julyLast = array(
    make_photo('2024-07-06 08:00:00', 'portrait'),
    make_photo('2024-07-06 08:30:00', 'portrait'),
);

$fullPagePhoto = make_photo('2024-07-05 15:00:00', 'portrait', array('full_page' => true));
$skippedPhoto  = make_photo('2024-07-05 12:50:00', 'landscape', array('skip_for_book' => true));

foreach (array_merge($julyMorning, $julyEvening, $julyMidday, $julyLast, array($fullPagePhoto, $skippedPhoto)) as $photoId) {
    photo_update($photoId, array('event_group_id' => $beach));
}

/* --- Text: two short quotes inside the beach range, one long anecdote. */
$shortQuoteA = quote_create(array('quote_text' => 'The ocean is so loud!', 'who_said_it' => 'Emma', 'entry_date' => '2024-07-04'));
$shortQuoteB = quote_create(array('quote_text' => 'Can we live here forever?', 'who_said_it' => 'Emma', 'entry_date' => '2024-07-05'));
$longText    = str_repeat('We drove home the long way and she narrated every single field. ', 4);
$longAnecdote = anecdote_create(array('anecdote_text' => $longText, 'entry_date' => '2024-07-06'));

/* --- September: ungrouped photos (Phase 4 simply hasn't run on them). */
$september = array(
    make_photo('2024-09-10 10:00:00', 'portrait'),
    make_photo('2024-09-10 10:06:00', 'portrait'),
    make_photo('2024-09-10 10:12:00', 'landscape'),
    make_photo('2024-09-10 10:18:00', 'landscape'),
    make_photo('2024-09-10 10:24:00', 'square'),
    make_photo('2024-09-10 10:30:00', 'portrait'),
);
$nearQuote  = quote_create(array('quote_text' => 'One day after the park.', 'who_said_it' => 'Kathryn', 'entry_date' => '2024-09-11'));
$orphanQuote = quote_create(array('quote_text' => 'Nowhere near a photo.', 'who_said_it' => 'Kathryn', 'entry_date' => '2024-10-20'));

/* ======================================================== generation ====== */

echo "\nlayout_generate(): the first version of 2024's book...\n";

$v1 = layout_generate($yp);
check('the first layout is version 1', $v1['version'] === 1);
check('it wrote pages', $v1['pages'] > 0);
check('it became the year\'s active layout (there was none before)', (int) year_project_get($yp)['active_book_layout_id'] === $v1['layout_id']);

$pages = book_pages_for_layout($v1['layout_id']);
check('every written page came back', count($pages) === $v1['pages']);

$numbers = array_map(static fn(array $p): int => (int) $p['page_number'], $pages);
check('page numbers are dense, 1..N with no gaps', $numbers === range(1, count($pages)));

/** photo_id -> page_number, over a whole layout. */
function photo_page_map(array $pages): array
{
    $map = array();
    foreach ($pages as $page) {
        foreach ($page['slots'] as $slot) {
            if ($slot['photo_id'] !== null) {
                $map[(int) $slot['photo_id']] = (int) $page['page_number'];
            }
        }
    }
    return $map;
}

/** Every occupant id of one kind, in page order. */
function slot_ids(array $pages, string $column): array
{
    $ids = array();
    foreach ($pages as $page) {
        foreach ($page['slots'] as $slot) {
            if ($slot[$column] !== null) {
                $ids[] = (int) $slot[$column];
            }
        }
    }
    return $ids;
}

$photoPage  = photo_page_map($pages);
$photoIds   = slot_ids($pages, 'photo_id');
$quoteIds   = slot_ids($pages, 'quote_id');
$anecdoteIds = slot_ids($pages, 'anecdote_id');

$expectedPhotos = array_merge(
    $julyMorning, $julyEvening, $julyMidday, $julyLast,
    array($fullPagePhoto, $marchLoose), $september
);
sort($expectedPhotos);
$placed = $photoIds;
sort($placed);
check('every eligible photo is placed exactly once', $placed === $expectedPhotos);
check('a skip_for_book photo appears NOWHERE in the layout', !in_array($skippedPhoto, $photoIds, true));
check('a snapshot\'s hero photo is not also loose in the flow', !in_array($heroPhoto, $photoIds, true));

echo "\nPage composition...\n";
$photoPages = array_values(array_filter($pages, static fn(array $p): bool => $p['page_type'] === 'photos'));
$densities  = array_map(static fn(array $p): int => count($p['slots']), $photoPages);
check('there is more than one page density in the book (brief §4.3)', count(array_unique($densities)) > 1);
check('the book is not one-photo-per-page', array_sum($densities) / max(1, count($densities)) > 1.5);
check('no page holds more than four slots', max($densities) <= 4);
echo '       (photo-page densities in order: ' . implode(', ', $densities) . ")\n";

$fullPageNumber = $photoPage[$fullPagePhoto] ?? null;
$fullPageRow = null;
foreach ($pages as $page) {
    if ((int) $page['page_number'] === $fullPageNumber) {
        $fullPageRow = $page;
    }
}
check('the full-page photo got a page', $fullPageRow !== null);
check('...with exactly one slot on it, nothing else competing', $fullPageRow !== null && count($fullPageRow['slots']) === 1);
check('...typed as a photos page (schema.sql: text/snapshot mean other things)', $fullPageRow !== null && $fullPageRow['page_type'] === 'photos');

$snapshotPages = array_values(array_filter($pages, static fn(array $p): bool => $p['page_type'] === 'snapshot'));
check('the snapshot got exactly one page of its own', count($snapshotPages) === 1);
check('...carrying its snapshot_id', (int) $snapshotPages[0]['snapshot_id'] === $snapshotId);
check('...with no slots at all (the template renders from snapshot_id)', $snapshotPages[0]['slots'] === array());

$textPages = array_values(array_filter($pages, static fn(array $p): bool => $p['page_type'] === 'text'));
check('every text page holds exactly one slot', $textPages !== array() && array_reduce(
    $textPages,
    static fn(bool $ok, array $p): bool => $ok && count($p['slots']) === 1,
    true
));
check('the >180-char anecdote is on a page of its own', in_array($longAnecdote, slot_ids($textPages, 'anecdote_id'), true));
check('the far-away orphan quote is on a page of its own', in_array($orphanQuote, slot_ids($textPages, 'quote_id'), true));

$cardPages = array_values(array_filter($photoPages, static fn(array $p): bool => slot_ids(array($p), 'quote_id') !== array()));
check('short quotes ride in a slot on a photo page, not a page of their own', $cardPages !== array());
check('the in-range short quotes are both placed as cards', count(array_intersect(array($shortQuoteA, $shortQuoteB), slot_ids($photoPages, 'quote_id'))) === 2);
check('the leftover quote one day from the September photos rides along too', in_array($nearQuote, slot_ids($photoPages, 'quote_id'), true));
check(
    'no photo page carries more than one text card',
    array_reduce(
        $photoPages,
        static fn(bool $ok, array $p): bool => $ok && (count(slot_ids(array($p), 'quote_id')) + count(slot_ids(array($p), 'anecdote_id'))) <= 1,
        true
    )
);
/* Quote ids and anecdote ids are separate id spaces — uniqueness has to be
   checked per kind, not over a merged list. */
check(
    'every quote and anecdote in the year is placed exactly once',
    count($quoteIds) === 4 && count(array_unique($quoteIds)) === 4
        && count($anecdoteIds) === 1
);

echo "\nDay / close-timing sub-grouping, end to end...\n";
$morningPages = array_unique(array_map(static fn(int $id): int => $photoPage[$id], $julyMorning));
$eveningPages = array_unique(array_map(static fn(int $id): int => $photoPage[$id], $julyEvening));
check(
    'the July 4 morning and the July 4 evening never share a page',
    array_intersect($morningPages, $eveningPages) === array()
);
$middayPages = array_unique(array_map(static fn(int $id): int => $photoPage[$id], $julyMidday));
check('the July 5 midday run is on different pages again', array_intersect($middayPages, $morningPages) === array());
check(
    'the book runs chronologically: March pages come before July pages',
    $photoPage[$marchLoose] < min($morningPages)
);
check(
    '...and July before September',
    max($middayPages) < min(array_map(static fn(int $id): int => $photoPage[$id], $september))
);

/* ========================================================= versioning ===== */

echo "\nRegenerating: a NEW version, with the old one left exactly as it was (brief §4.5)...\n";

$v1Before = book_pages_for_layout($v1['layout_id']);

$v2 = layout_generate($yp);
check('the second run is version 2', $v2['version'] === 2);
check('it is a different book_layouts row', $v2['layout_id'] !== $v1['layout_id']);
check('version 1 still exists', book_layout_get($v1['layout_id']) !== null);
check('version 1\'s pages are byte-for-byte what they were', book_pages_for_layout($v1['layout_id']) == $v1Before);
check('the active layout was NOT yanked over to the new version', (int) year_project_get($yp)['active_book_layout_id'] === $v1['layout_id']);
check('both versions are listed for the year', count(book_layouts_for_year($yp)) === 2);

$summary = book_layouts_for_year($yp)[0];
check('the listing carries a page count', (int) $summary['page_count'] === $v2['pages']);
check('...and a per-type breakdown that adds up', (int) $summary['photo_pages'] + (int) $summary['text_pages'] + (int) $summary['snapshot_pages'] === (int) $summary['page_count']);

check('the two versions arranged the same content the same way (deterministic)',
    array_map(static fn(array $p): string => $p['page_type'] . ':' . count($p['slots']), book_pages_for_layout($v2['layout_id']))
    === array_map(static fn(array $p): string => $p['page_type'] . ':' . count($p['slots']), $v1Before));

/* ============================================================ reflow ====== */

echo "\nlayout_reflow_from(): a manual edit before page N survives it...\n";

$layoutId = $v2['layout_id'];
$before   = book_pages_for_layout($layoutId);

/* Simulate exactly what Phase 6's drag-and-drop will do: swap a photo on an
   early page for one that currently sits much later in the book. The reflow
   must (a) leave that page alone, (b) not place the moved-in photo a second
   time downstream, (c) put the evicted photo back into the flow. */
$reflowFrom = 6;
$earlySlot  = null;
foreach ($before as $page) {
    if ((int) $page['page_number'] < $reflowFrom && $page['page_type'] === 'photos' && $page['slots'] !== array()) {
        foreach ($page['slots'] as $slot) {
            if ($slot['photo_id'] !== null) {
                $earlySlot = $slot;
                break 2;
            }
        }
    }
}
$evicted = (int) $earlySlot['photo_id'];

$movedIn = null;
foreach ($before as $page) {
    if ((int) $page['page_number'] >= $reflowFrom) {
        foreach ($page['slots'] as $slot) {
            if ($slot['photo_id'] !== null) {
                $movedIn = (int) $slot['photo_id'];
                break 2;
            }
        }
    }
}
check('the test found an early slot and a later photo to swap into it', $earlySlot !== null && $movedIn !== null);

q('UPDATE book_page_photos SET photo_id = ? WHERE id = ?', array($movedIn, (int) $earlySlot['id']));

$keptBefore = array_values(array_filter($before, static fn(array $p): bool => (int) $p['page_number'] < $reflowFrom));
// Re-read so the manual edit is part of the "before" we compare against.
$keptBefore = array_values(array_filter(book_pages_for_layout($layoutId), static fn(array $p): bool => (int) $p['page_number'] < $reflowFrom));

$reflow = layout_reflow_from($layoutId, $reflowFrom);
check('reflow reported the pages it kept', $reflow['kept'] === count($keptBefore));
check('reflow wrote pages from page ' . $reflowFrom . ' onward', $reflow['pages'] > 0);

$after     = book_pages_for_layout($layoutId);
$keptAfter = array_values(array_filter($after, static fn(array $p): bool => (int) $p['page_number'] < $reflowFrom));
check('every page before the reflow point is byte-for-byte untouched', $keptAfter == $keptBefore);
check('...including the hand-swapped photo', in_array($movedIn, slot_ids($keptAfter, 'photo_id'), true));

$tail = array_values(array_filter($after, static fn(array $p): bool => (int) $p['page_number'] >= $reflowFrom));
check('the tail regenerated', $tail !== array());
check('page numbering is still dense across the seam', array_map(static fn(array $p): int => (int) $p['page_number'], $after) === range(1, count($after)));
check('the hand-swapped photo does NOT appear a second time downstream', !in_array($movedIn, slot_ids($tail, 'photo_id'), true));
check('the photo it displaced is back in the flow rather than lost', in_array($evicted, slot_ids($tail, 'photo_id'), true));

$allAfter = slot_ids($after, 'photo_id');
check('no photo is duplicated anywhere in the reflowed book', count($allAfter) === count(array_unique($allAfter)));
$quotesAfter    = slot_ids($after, 'quote_id');
$anecdotesAfter = slot_ids($after, 'anecdote_id');
check(
    'no quote or anecdote is duplicated either',
    count($quotesAfter) === count(array_unique($quotesAfter))
        && count($anecdotesAfter) === count(array_unique($anecdotesAfter))
);
$snapAfter = array();
foreach ($after as $page) {
    if ($page['snapshot_id'] !== null) {
        $snapAfter[] = (int) $page['snapshot_id'];
    }
}
check('the snapshot still appears exactly once', count($snapAfter) === 1);

check('version 1 is STILL untouched after a reflow of version 2', book_pages_for_layout($v1['layout_id']) == $v1Before);

echo "\nReflow edge cases...\n";
$fromOne = layout_reflow_from($layoutId, 1);
check('reflowing from page 1 keeps nothing and rebuilds the whole book', $fromOne['kept'] === 0 && $fromOne['pages'] > 0);
$beyondEnd = layout_reflow_from($layoutId, 9999);
check('reflowing from beyond the last page adds nothing and keeps everything', $beyondEnd['pages'] === 0 && $beyondEnd['kept'] === count(book_pages_for_layout($layoutId)));

$threw = false;
try {
    layout_reflow_from(999999, 1);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('reflowing an unknown layout is refused loudly, not silently', $threw);

/* ========================================================== fail soft ===== */

echo "\nFail-soft edges...\n";

$empty = year_project_get_or_create('2019-01-01');
$emptyRun = layout_generate($empty);
check('a year with no content at all generates without throwing', $emptyRun['layout_id'] > 0);
check('...and produces zero pages rather than a fake one', $emptyRun['pages'] === 0);
check('...and its version numbering starts at 1 independently of 2024', $emptyRun['version'] === 1);

$lonely = year_project_get_or_create('2021-01-01');
$lonelyPhoto = make_photo('2021-05-05 12:00:00', 'portrait');
$lonelyGroup = event_group_create(array(
    'year_project_id' => $lonely,
    'name'            => 'May 5',
    'start_date'      => '2021-05-05',
    'end_date'        => '2021-05-05',
));
photo_update($lonelyPhoto, array('event_group_id' => $lonelyGroup));
$lonelyRun = layout_generate($lonely);
check('an event group with a single photo produces a single page', $lonelyRun['pages'] === 1);

check(
    'generating 2021 did not touch 2024 (year isolation)',
    count(book_layouts_for_year($yp)) === 2 && book_pages_for_layout($v1['layout_id']) == $v1Before
);

/* ================================================================= result */

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) FAILED.\n";
    exit(1);
}
echo "All checks passed.\n";
