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
 *   3. layout_variety_penalty() / layout_partition_subgroup(): page sizes stay
 *      inside the configured bounds, which Round 6 reopened to 1..4 after
 *      Kathryn approved a book built from her own sketched templates. What
 *      makes a short page rare is no longer the bound but shape feasibility
 *      plus the partitioner's cost — see tools/verify-partition.php, which
 *      owns the drawable-page invariant. Historic note: this used to assert
 *      2-3 and nothing else, including n=7
 *      where no partition into 3s alone exists; a lone photo still gets its
 *      1-up page because there is nothing to pair it with; the same input
 *      twice gives the same partition; the large-group greedy fallback
 *      obeys the same bounds; and within all that, density still VARIES
 *      across a run of identically-shaped photos rather than sticking on
 *      one number.
 *   3c. layout_pair_lone_subgroups(): two leftover orphans, from different
 *      occasions, sharing a page — the one place an event boundary is
 *      crossed, and only ever between two photos that both ended up alone.
 *   3b. layout_merge_lone_subgroups(): the structural half of "fewer 1-up
 *      pages" — a lone photo inside an event group joins its neighbours
 *      however wide the gap, an ungrouped one only within
 *      lone_merge_gap_hours, and neither ever crosses the
 *      grouped/ungrouped boundary or a boundary between two events.
 *   4. layout_subgroup_photos(): brief §4.3's day/close-timing pass — a
 *      beach morning and a dinner that evening (same day) land in different
 *      sub-groups, while photos minutes apart stay together, using the
 *      SAME clustering primitive Phase 4's coarser pass uses.
 *   5. layout_text_is_long(): the tunable ~180-char threshold, either side
 *      of the boundary, counted in characters and not bytes.
 *   6. layout_assign_texts(): text inside an event's date range lands on
 *      that event; a leftover within the attach window rides along with the
 *      nearest page-group; a leftover outside it stands alone.
 *   7. layout_generate() end to end on a synthetic 2024: every photos page
 *      carries 2 or 3 photos except the two legitimate singles (the
 *      full_page-flagged shot and the March photo with no neighbour inside
 *      lone_merge_gap_hours), and those two are the ONLY ones — checked in
 *      photo slots rather than total slots, since a page carrying a text
 *      card holds one more slot than it holds photos. Also: every eligible
 *      photo appears exactly once, page numbering is
 *      dense, full-page photos land alone, snapshots land alone as
 *      page_type='snapshot' with snapshot_id set and no slots, a long
 *      anecdote gets a page_type='text' page, short quotes ride in a slot
 *      on a photo page, skip_for_book photos appear NOWHERE, and a
 *      snapshot's hero photo is not also loose in the flow.
 *   8. Versioning (brief §4.5): regenerating INSERTs version 2 and leaves
 *      version 1's pages byte-for-byte as they were.
 *   9. THE ARRANGEMENT IS FROZEN: generation writes down which template draws
 *      each photos page, and swapping two differently-shaped photos across
 *      pages afterwards changes none of them. A stored choice that no longer
 *      describes its page — wrong photo count, unknown template, an order that
 *      would place one photo twice — is ignored rather than drawn.
 *      This replaced a "reflow from here" block; reflow was removed in Round 10
 *      because a layout is something Kathryn reworks by hand and nothing may
 *      rebuild it under her.
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

echo "\nlayout_partition_feasible(): what the 2-3 rule can and cannot end on...\n";
check('nothing left is a valid end', layout_partition_feasible(0, 2, 3) === true);
check('exactly one photo left is NOT (that is the orphan page)', layout_partition_feasible(1, 2, 3) === false);
check('every count from 2 up is reachable with 2s and 3s', (static function (): bool {
    for ($n = 2; $n <= 60; $n++) {
        if (!layout_partition_feasible($n, 2, 3)) {
            return false;
        }
    }
    return true;
})());
check('a min==max config can only end on multiples of it', layout_partition_feasible(4, 3, 3) === false);

echo "\nlayout_card_schedule(): text cards spread, not bunched...\n";
check('no pages means no schedule', layout_card_schedule(0, 3) === array());
check('one card across five pages lands in the middle', layout_card_schedule(5, 1) === array(2));
check('two cards across four pages are spread apart', layout_card_schedule(4, 2) === array(1, 3));
check('more cards than pages caps at one per page', count(layout_card_schedule(2, 5)) === 2);

echo "\nlayout_pair_lone_subgroups(): two orphans from different occasions share a page...\n";

/* The one place an event boundary is crossed, added in Round 6 after Kathryn
 * reviewed these exact pages in the layout lab and asked for them. It never
 * puts a photo INTO an event — it seats two photos that each ended up alone. */
$paired = layout_pair_lone_subgroups(array(
    sub(1, array('2024-03-01 10:00:00')),
    sub(2, array('2024-06-01 10:00:00')),
));
check('two adjacent lone groups become one group of two', count($paired) === 1 && count($paired[0]['photos']) === 2);
check('the paired group belongs to neither event', $paired[0]['event_group_id'] === null);
check('its photos are in chronological order',
    $paired[0]['photos'][0]['captured_at'] === '2024-03-01 10:00:00');
check('its date range spans both', $paired[0]['start_date'] === '2024-03-01' && $paired[0]['end_date'] === '2024-06-01');

/* An event between two orphans is a wall. Reaching over it would put two photos
 * together whose only relationship is that something else happened in between. */
$walled = layout_pair_lone_subgroups(array(
    sub(1, array('2024-03-01 10:00:00')),
    sub(2, array('2024-04-01 10:00:00', '2024-04-01 11:00:00')),
    sub(3, array('2024-06-01 10:00:00')),
));
check('a real event between two orphans keeps them apart', count($walled) === 3);
check('...and neither orphan was altered',
    $walled[0]['event_group_id'] === 1 && $walled[2]['event_group_id'] === 3);

/* Pairs only. Five orphans are two pages of two and one of one — not a 4-up and
 * a single, because these are not one occasion and a 4-up reads as an event. */
$five = layout_pair_lone_subgroups(array(
    sub(1, array('2024-01-01 10:00:00')),
    sub(2, array('2024-02-01 10:00:00')),
    sub(3, array('2024-03-01 10:00:00')),
    sub(4, array('2024-04-01 10:00:00')),
    sub(5, array('2024-05-01 10:00:00')),
));
check('five orphans pair off two at a time, leaving one alone',
    array_map(static fn(array $g): int => count($g['photos']), $five) === array(2, 2, 1));

check('a group that was never lone is untouched',
    layout_pair_lone_subgroups(array(sub(1, array('2024-01-01 10:00:00', '2024-01-01 11:00:00'))))[0]['event_group_id'] === 1);
check('an empty book pairs to nothing', layout_pair_lone_subgroups(array()) === array());

echo "\nlayout_partition_subgroup(): page sizes stay inside the configured bounds...\n";
foreach (range(2, 10) as $n) {
    $mixed = array();
    for ($i = 0; $i < $n; $i++) {
        $mixed[] = array('portrait', 'landscape', 'square')[$i % 3] === 'square' ? 'flex' : array('portrait', 'landscape')[$i % 2];
    }
    $part  = layout_partition_subgroup($mixed, 0, array(), $TUNING);
    $sizes = array_map(static fn(array $p): int => $p['count'], $part);
    [$boundMin, $boundMax] = layout_page_size_bounds($TUNING);
    check(
        "n=$n partitions inside the bounds, nothing else (" . implode(',', $sizes) . ')',
        array_sum($sizes) === $n && min($sizes) >= $boundMin && max($sizes) <= $boundMax
    );
}

echo "\nlayout_partition_subgroup(): density varies across a long run...\n";
$partition = layout_partition_subgroup(array_fill(0, 14, 'landscape'), 0, array(), $TUNING);
$sizes = array_map(static fn(array $p): int => $p['count'], $partition);
check('every photo is placed exactly once', array_sum($sizes) === 14);
// The bounds are the point of the function, so the check is the real bounds
// rather than the literals Round 5 happened to configure.
[$bMin, $bMax] = layout_page_size_bounds($TUNING);
check('no page falls below the configured minimum', min($sizes) >= $bMin);
check('no page exceeds the configured maximum', max($sizes) <= $bMax);
check(
    'density is NOT stuck on one number across 14 identical photos (brief §4.3)',
    count(array_unique($sizes)) > 1
);
check('the book is not one-photo-per-page', count($partition) < 14);
echo '       (14 landscapes partitioned as: ' . implode(', ', $sizes) . ")\n";

$seven = layout_partition_subgroup(array_fill(0, 7, 'portrait'), 0, array(), $TUNING);
$sevenSizes = array_map(static fn(array $p): int => $p['count'], $seven);
check(
    'n=7 still yields a valid in-bounds composition: ' . implode('+', $sevenSizes),
    array_sum($sevenSizes) === 7 && min($sevenSizes) >= $bMin && max($sevenSizes) <= $bMax
);

$onePhoto = layout_partition_subgroup(array('portrait'), 0, array(), $TUNING);
check(
    'a genuinely isolated photo still gets its own 1-up page (nothing to pair with)',
    count($onePhoto) === 1 && $onePhoto[0]['count'] === 1
);

$mixedRun = array('portrait', 'landscape', 'landscape', 'flex', 'portrait', 'portrait', 'landscape', 'flex', 'portrait');
check(
    'the same input twice gives the same partition (deterministic)',
    layout_partition_subgroup($mixedRun, 1, array(2, 3), $TUNING)
        === layout_partition_subgroup($mixedRun, 1, array(2, 3), $TUNING)
);

/* 60 photos with no gap between them. Round 5 used this to exercise a greedy
   fallback past LAYOUT_PARTITION_MAX_CANDIDATES; Round 6 removed both the
   enumeration and the fallback in favour of one exact DP, so what this now
   proves is that the DP stays correct — and fast — at a size the old path
   refused to even enumerate. */
$huge = layout_partition_subgroup(array_fill(0, 60, 'portrait'), 0, array(), $TUNING);
$hugeSizes = array_map(static fn(array $p): int => $p['count'], $huge);
check(
    'a 60-photo group stays inside the bounds',
    array_sum($hugeSizes) === 60 && min($hugeSizes) >= $bMin && max($hugeSizes) <= $bMax
);

$withCards = layout_partition_subgroup(array_fill(0, 9, 'portrait'), 2, array(), $TUNING);
$cardPages = array_values(array_filter($withCards, static fn(array $p): bool => $p['card']));
check('both text cards were given a page to ride on', count($cardPages) === 2);
check(
    'no page carrying a card exceeds 4 slots',
    max(array_map(static fn(array $p): int => $p['count'] + ($p['card'] ? 1 : 0), $withCards)) <= 4
);
check(
    'a card never buys itself room by shrinking a page below two photos',
    min(array_map(static fn(array $p): int => $p['count'], $withCards)) >= 2
);
$cardsOnly = layout_partition_subgroup(array(), 2, array(), $TUNING);
check('cards with no photos at all become pages of their own', count($cardsOnly) === 2 && $cardsOnly[0]['count'] === 0);

$lonePlusCard = layout_partition_subgroup(array('portrait'), 1, array(), $TUNING);
check(
    'a card rides along with an isolated photo rather than taking a page of its own',
    count($lonePlusCard) === 1 && $lonePlusCard[0]['count'] === 1 && $lonePlusCard[0]['card'] === true
);

echo "\nlayout_estimate_page_count(): under-estimates, which is the safe direction...\n";
check('it is the FEWEST pages the bounds allow, never more', (static function (): bool {
    for ($n = 2; $n <= 40; $n++) {
        $pages = count(layout_partition_subgroup(array_fill(0, $n, 'portrait'), 0, array(), $GLOBALS['TUNING']));
        if (layout_estimate_page_count($n, layout_page_size_bounds($GLOBALS['TUNING'])[1]) > $pages) {
            return false;
        }
    }
    return true;
})());
check('raising the ceiling lowers the estimate rather than stranding cards', layout_estimate_page_count(8, 4) === 2);

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

/* ================================================ pure: lone-photo merging = */

echo "\nlayout_merge_lone_subgroups(): a photo with nobody to share a page with...\n";

/** A page-group fixture, in the shape layout_plan() builds. */
function sub(?int $groupId, array $capturedAt): array
{
    $photos = array();
    foreach ($capturedAt as $i => $at) {
        // Ids ascend with time so 'first_id' is unambiguous in these fixtures.
        $photos[] = array('id' => (int) (crc32($at) % 100000) + $i, 'captured_at' => $at);
    }
    return array(
        'event_group_id' => $groupId,
        'photos'         => $photos,
        'start'          => $capturedAt[0],
        'start_date'     => substr($capturedAt[0], 0, 10),
        'end_date'       => substr($capturedAt[count($capturedAt) - 1], 0, 10),
        'first_id'       => $photos[0]['id'],
    );
}

/** Photo counts per page-group, in order. */
function sub_counts(array $subgroups): array
{
    return array_map(static fn(array $s): int => count($s['photos']), $subgroups);
}

$insideEvent = layout_merge_lone_subgroups(array(
    sub(7, array('2024-07-04 09:00:00', '2024-07-04 09:30:00')),
    sub(7, array('2024-07-06 18:00:00')),                          // 2+ days later, same trip
), 24.0);
check(
    'a lone photo inside an event group merges however wide the gap',
    sub_counts($insideEvent) === array(3)
);
check(
    '...and the merged group re-reads its own start and end from its photos',
    $insideEvent[0]['start'] === '2024-07-04 09:00:00' && $insideEvent[0]['end_date'] === '2024-07-06'
);
check(
    '...with its photos still in chronological order',
    array_map(static fn(array $p): string => $p['captured_at'], $insideEvent[0]['photos'])
        === array('2024-07-04 09:00:00', '2024-07-04 09:30:00', '2024-07-06 18:00:00')
);

$nearUngrouped = layout_merge_lone_subgroups(array(
    sub(null, array('2024-09-10 10:00:00', '2024-09-10 10:10:00')),
    sub(null, array('2024-09-10 20:00:00')),                       // ~10h later
), 24.0);
check('an ungrouped lone photo within the window rides along', sub_counts($nearUngrouped) === array(3));

$farUngrouped = layout_merge_lone_subgroups(array(
    sub(null, array('2024-09-10 10:00:00', '2024-09-10 10:10:00')),
    sub(null, array('2024-09-17 20:00:00')),                       // a week later
), 24.0);
check(
    'an ungrouped lone photo beyond the window keeps its own page (it IS its own moment)',
    sub_counts($farUngrouped) === array(2, 1)
);

$acrossBuckets = layout_merge_lone_subgroups(array(
    sub(7, array('2024-07-04 09:00:00', '2024-07-04 09:30:00')),
    sub(null, array('2024-07-04 10:00:00')),                       // ungrouped, minutes away
    sub(8, array('2024-07-04 11:00:00', '2024-07-04 11:30:00')),
), 24.0);
check(
    'a lone photo never merges across the grouped/ungrouped boundary, however close',
    sub_counts($acrossBuckets) === array(2, 1, 2)
);

$acrossEvents = layout_merge_lone_subgroups(array(
    sub(7, array('2024-07-04 09:00:00', '2024-07-04 09:30:00')),
    sub(8, array('2024-07-04 10:00:00')),
), 24.0);
check(
    'nor between two different event groups (they are two different occasions)',
    sub_counts($acrossEvents) === array(2, 1)
);

$tie = layout_merge_lone_subgroups(array(
    sub(7, array('2024-07-04 08:00:00', '2024-07-04 08:30:00')),
    sub(7, array('2024-07-04 09:30:00')),
    sub(7, array('2024-07-04 10:30:00', '2024-07-04 11:00:00')),   // exactly as far away
), 24.0);
check('an exact tie between neighbours goes to the EARLIER one', sub_counts($tie) === array(3, 2));

$nearer = layout_merge_lone_subgroups(array(
    sub(null, array('2024-09-10 08:00:00', '2024-09-10 08:30:00')),
    sub(null, array('2024-09-10 20:00:00')),                       // 11.5h back, 1h forward
    sub(null, array('2024-09-10 21:00:00', '2024-09-10 21:30:00')),
), 24.0);
check('otherwise the nearer neighbour wins', sub_counts($nearer) === array(2, 3));

$twoLonelies = layout_merge_lone_subgroups(array(
    sub(null, array('2024-09-10 08:00:00')),
    sub(null, array('2024-09-10 14:00:00')),
), 24.0);
check('two adjacent lone photos become one 2-up page, which is the whole point', sub_counts($twoLonelies) === array(2));

$noNeighbour = layout_merge_lone_subgroups(array(sub(4, array('2021-05-05 12:00:00'))), 24.0);
check('a lone photo with no neighbour at all is left alone rather than erroring', sub_counts($noNeighbour) === array(1));
check('no page-groups at all is not an error either', layout_merge_lone_subgroups(array(), 24.0) === array());

$stillSorted = layout_merge_lone_subgroups(array(
    sub(null, array('2024-09-10 08:00:00')),                       // merges FORWARD, moving its host's start back
    sub(null, array('2024-09-10 09:00:00', '2024-09-10 09:30:00')),
    sub(null, array('2024-09-12 09:00:00', '2024-09-12 09:30:00')),
), 24.0);
$starts = array_map(static fn(array $s): string => $s['start'], $stillSorted);
$sorted = $starts;
sort($sorted);
check('the returned page-groups are still in chronological order', $starts === $sorted);

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

/* A SHORT TEXT NO LONGER STANDS ALONE JUST BECAUSE NOTHING IS NEAR IT.
 *
 * It used to: a text more than text_attach_days from any page-group got a page
 * of its own, so a quote from a quiet December week was printed at 22pt across
 * a whole sheet. Kathryn's rule is that length alone decides — "quotes and
 * anecdotes should fit within an existing slot in the layouts unless it's too
 * long, then it gets its own page" — and this one is short.
 *
 * The date still decides WHICH page it rides on; it just no longer refuses to
 * travel. */
check('a short leftover with nothing near it still rides along', $assigned['standalone'] === array());
check(
    'and it goes to the nearest page-group, not just any',
    isset($assigned['assigned'][2]) && count($assigned['assigned'][2]) === 2
);
check(
    '...and the December quote is one of the two riding on it',
    in_array(3, array_column($assigned['assigned'][2] ?? array(), 'id'), true)
);

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
echo '       (photo-page slot counts in order: ' . implode(', ', $densities) . ")\n";

/* The Round 5 rule, end to end and against the real interplay: this synthetic
   year has a full-page photo, text cards riding on photo pages, and one March
   photo with no neighbour inside lone_merge_gap_hours. Everything else must
   be a 2- or 3-photo page — counted in PHOTO slots, since a card page holds
   one more slot than it holds photos.

   The two legitimate singles are named, not merely tolerated: a check that
   allowed any 1-photo page would pass on a book that had gone back to being
   full of them. */
$expectedSingles = array($fullPagePhoto, $marchLoose);
sort($expectedSingles);

$photoCounts      = array();
$singlePhotoPages = array();
$illegalPages     = array();
foreach ($photoPages as $row) {
    $ids   = slot_ids(array($row), 'photo_id');
    $count = count($ids);
    $photoCounts[] = $count;

    if ($count === 1) {
        $singlePhotoPages[] = $ids[0];
        if (!in_array($ids[0], $expectedSingles, true)) {
            $illegalPages[] = (int) $row['page_number'];
        }
    } elseif ($count < 1 || $count > LAYOUT_MAX_SLOTS) {
        $illegalPages[] = (int) $row['page_number'];
    }
}
sort($singlePhotoPages);

check(
    'every photos page carries a drawable number of photos',
    $illegalPages === array()
);
check(
    '...and BOTH of those singles are accounted for: the full_page flag, and the March photo with no neighbour',
    $singlePhotoPages === $expectedSingles
);
echo '       (photos per photo-page: ' . implode(', ', $photoCounts) . ")\n";

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
/* The far-away orphan is SHORT, so it now rides in a slot rather than taking a
 * page — see layout_assign_texts(). It used to be here because distance could
 * promote a short quote to a whole page; only length can now. */
check(
    'a far-away SHORT quote rides in a slot, not a page of its own',
    !in_array($orphanQuote, slot_ids($textPages, 'quote_id'), true)
);
check(
    '...and it is somewhere in the book',
    in_array($orphanQuote, slot_ids($pages, 'quote_id'), true)
);

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
/* EVERY page type, blanks included. Leaving blanks out is how a breakdown
   silently stops adding up the moment a book is padded to a signature. */
check('...and a per-type breakdown that adds up',
    (int) $summary['photo_pages'] + (int) $summary['text_pages']
    + (int) $summary['snapshot_pages'] + (int) $summary['blank_pages']
    === (int) $summary['page_count'],
    sprintf('%d photo + %d text + %d snapshot + %d blank vs %d total',
        $summary['photo_pages'], $summary['text_pages'], $summary['snapshot_pages'],
        $summary['blank_pages'], $summary['page_count']));

/* A page's whole content, minus the row ids that necessarily differ between
   two versions: type, snapshot, and every occupant in slot order. Comparing
   only "type:slot count" would call two books identical while they held
   different photos on every page, which is not what determinism means. */
function page_fingerprint(array $page): string
{
    $slots = array();
    foreach ($page['slots'] as $slot) {
        $slots[] = ($slot['photo_id'] ?? 'n') . '/' . ($slot['quote_id'] ?? 'n') . '/' . ($slot['anecdote_id'] ?? 'n');
    }
    return $page['page_type'] . ':' . ($page['snapshot_id'] ?? 'n') . ':' . implode(',', $slots);
}

check('the two versions arranged the same content the same way (deterministic)',
    array_map('page_fingerprint', book_pages_for_layout($v2['layout_id']))
    === array_map('page_fingerprint', $v1Before));

/* ============================================== the arrangement is frozen == */

echo "\nArrangements: the dragged page adapts, the rest hold still...\n";

/* WHAT THIS REPLACED. There was a "reflow from here" block here, checking that
   regenerating a layout from page N left the pages before it untouched. Round 10
   removed reflow: a layout is something Kathryn reworks by hand, and nothing may
   rebuild it under her.
 
   What matters instead is the property reflow's removal made possible. The
   template each page uses was recomputed at render time from the slot shapes
   plus a rotation over the whole book, so swapping two photos could change how
   that page — and, through the rotation, the pages after it — were drawn. It is
   written down at generation now, and this is the check that it stays put. */

$frozenLayout = $v2['layout_id'];
/* The joined rows, so slots carry width/height — the same view the preview
   and the exporter read, and the only one where shapes exist. */
$frozenPages  = book_layout_pages_with_content($frozenLayout);

$storedBefore = array();
foreach ($frozenPages as $page) {
    $storedBefore[(int) $page['id']] = $page['template_order'];
}
check('generation writes an arrangement for every photos page',
    count(array_filter($storedBefore)) > 0);
check('...recorded against the shapes it was chosen for',
    count(array_filter($storedBefore, static fn($v): bool => is_string($v) && strpos($v, ':') !== false)) > 0);

$arrangedBefore = layout_page_arrangements($frozenPages);

/* THE TWO PHOTOS MUST BE DIFFERENT SHAPES. That is the whole case: dragging a
   landscape onto a page of portraits is supposed to re-arrange THAT page around
   it — "I liked that the layout would update based on the photo I dragged into
   it" — while leaving every other page alone, which is what the stored
   arrangement is for. Swapping two photos of the same shape would change
   nothing either way and the test would have no teeth. */
$shapeOf = static function (array $slot): string {
    $w = (int) ($slot['width'] ?? 0);
    $h = (int) ($slot['height'] ?? 0);
    return $w > 0 && $h > 0 && $w >= $h ? 'L' : 'P';
};

$swapA     = null;
$swapAPage = 0;
$swapB     = null;
$swapBPage = 0;
foreach ($frozenPages as $page) {
    if ($page['page_type'] !== 'photos') { continue; }
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] === null) { continue; }
        if ($swapA === null) {
            $swapA     = $slot;
            $swapAPage = (int) $page['id'];
            continue;
        }
        if ((int) $page['id'] !== $swapAPage && $shapeOf($slot) !== $shapeOf($swapA)) {
            $swapB     = $slot;
            $swapBPage = (int) $page['id'];
            break 2;
        }
    }
}

if ($swapA !== null && $swapB !== null) {
    book_page_slot_swap((int) $swapA['id'], (int) $swapB['id']);
    /* What api/book-page-slots-swap.php does next. */
    layout_resettle_arrangements($frozenLayout);

    $after         = book_layout_pages_with_content($frozenLayout);
    $arrangedAfter = layout_page_arrangements($after);

    $touched   = array($swapAPage, $swapBPage);
    $movedElse = array();
    foreach ($arrangedAfter as $pageId => $choice) {
        if (in_array((int) $pageId, $touched, true)) { continue; }
        if (($arrangedBefore[$pageId] ?? null) !== $choice) { $movedElse[] = $pageId; }
    }

    check('a swap leaves every page it did not touch exactly as it was',
        $movedElse === array(),
        count($movedElse) . ' other page(s) changed: ' . implode(',', $movedElse));

    /* And the two pages it DID touch are re-arranged around their new shapes,
       then saved — not left describing the photos they used to hold. */
    $storedAfter = array();
    $sigAfter    = array();
    foreach ($after as $page) {
        $storedAfter[(int) $page['id']] = (string) $page['template_order'];
        if ($page['page_type'] === 'photos' && $page['slots'] !== array()) {
            $sigAfter[(int) $page['id']] = layout_shape_signature(compose_occupants($page['slots']));
        }
    }

    $stale = array();
    foreach ($touched as $pageId) {
        if (!isset($sigAfter[$pageId]) || $storedAfter[$pageId] === '') { continue; }
        $recorded = explode(':', $storedAfter[$pageId], 2)[0];
        if ($recorded !== $sigAfter[$pageId]) { $stale[] = $pageId; }
    }
    check('...and the pages it did touch are saved against their NEW shapes',
        $stale === array(), 'stale on page(s): ' . implode(',', $stale));

    /* Saved means saved: rendering again changes nothing further. */
    check('...and re-reading gives the same answer again',
        layout_page_arrangements(book_layout_pages_with_content($frozenLayout)) === $arrangedAfter);
} else {
    check('the fixture has two differently-shaped photos on different pages', false);
}

/* The guards. A stored choice that no longer describes its page is ignored
   rather than drawn — otherwise a page prints one photo twice, or keeps a
   shape it no longer has. */
$guardPage = null;
foreach (book_layout_pages_with_content($frozenLayout) as $page) {
    if ($page['page_type'] === 'photos' && count($page['slots']) > 1 && $page['template_order']) {
        $guardPage = $page;
        break;
    }
}
if ($guardPage !== null) {
    $occ = compose_occupants($guardPage['slots']);
    check('a stored arrangement is used when it still fits',
        layout_stored_arrangement($guardPage, $occ) !== null);

    $swappedShapes = $occ;
    $swappedShapes[0]['shape'] = $swappedShapes[0]['shape'] === 'P' ? 'L' : 'P';
    check('...ignored once the page holds a different SHAPE of photo',
        layout_stored_arrangement($guardPage, $swappedShapes) === null);

    $bogus = $guardPage;
    $bogus['template_name'] = 'no-such-template';
    check('...and ignored when the template no longer exists',
        layout_stored_arrangement($bogus, $occ) === null);

    $dupe = $guardPage;
    $sig  = explode(':', (string) $guardPage['template_order'], 2)[0];
    $dupe['template_order'] = $sig . ':' . implode(',', array_fill(0, count($occ), 0));
    check('...and ignored when it would place one photo twice',
        layout_stored_arrangement($dupe, $occ) === null);

    $legacy = $guardPage;
    $legacy['template_order'] = '0,1';
    check('...and ignored when it predates the shape signature',
        layout_stored_arrangement($legacy, $occ) === null);
} else {
    check('the fixture has a multi-photo page to check the guards against', false);
}

/* ------------------------------------------------- signatures and blanks -- */

echo "\nlayout_generate(): the book comes out a whole number of signatures...\n";

/* WHY. A printer binds interior pages in folded signatures, so the count has to
   be a multiple of four. The printer will pad the book to suit whatever you
   send; the question is only whether you find out before or after paying. So
   the layout pads itself, with real pages the Book tab draws — "so I can see if
   I need to add more images or quotes to fill in the spots". */

$multiple = (int) cfg('layout.page_multiple', 4);
$padCases = array();

foreach (array(1, 2, 3, 5, 9) as $n) {
    $padPid = year_project_create(2100 + $n, null, 'signature ' . $n);
    for ($i = 0; $i < $n; $i++) {
        photo_create(array(
            'year_project_id' => $padPid,
            'original_path'   => "sig{$n}-{$i}.jpg",
            'thumb_path'      => "sig{$n}-{$i}-t.jpg",
            /* Days apart, so the grouper does not pack them onto one page and
               every case really does produce a different page count. */
            'captured_at'     => sprintf('2025-03-%02d 10:00:00', $i + 1),
            'width'           => 1200, 'height' => 800,
        ));
    }

    $gen   = layout_generate($padPid);
    $rows  = book_pages_for_layout($gen['layout_id']);
    $blank = 0;
    foreach ($rows as $row) { if ($row['page_type'] === 'blank') { $blank++; } }

    $padCases[$n] = array('total' => count($rows), 'blank' => $blank, 'reported' => $gen);

    check("{$n} photo(s): the book is a whole number of signatures",
        count($rows) % $multiple === 0,
        count($rows) . ' pages, ' . $blank . ' of them blank');
}

/* The blanks go on the END, and they are the only pages with nothing on them. */
$lastCase = $padCases[5];
$rows     = book_pages_for_layout($lastCase['reported']['layout_id']);
$seenBlank = false;
$contentAfterBlank = false;
foreach ($rows as $row) {
    if ($row['page_type'] === 'blank') { $seenBlank = true; continue; }
    if ($seenBlank) { $contentAfterBlank = true; }
}
check('the blanks are at the end of the book, not scattered through it',
    !$contentAfterBlank);

check('a blank page carries nothing at all',
    (static function (array $rows): bool {
        foreach ($rows as $row) {
            if ($row['page_type'] === 'blank'
                && ($row['slots'] !== array() || $row['snapshot_id'] !== null)) {
                return false;
            }
        }
        return true;
    })($rows));

/* generate() reports the book's REAL length. $written alone would say 26 for a
   28-page book, which is the number you would then quote to a printer. */
check('the reported page count includes the blanks',
    $lastCase['reported']['pages'] === $lastCase['total'],
    $lastCase['reported']['pages'] . ' reported, ' . $lastCase['total'] . ' rows');
check('...and the blanks are reported separately, so they can be counted',
    $lastCase['reported']['blanks'] === $lastCase['blank']);

/* AN EMPTY BOOK IS NOT PADDED. Four blank pages is not a more useful answer
   than none for a project with nothing in it yet. */
$emptyPid = year_project_create(2199, null, 'nothing at all');
$emptyGen = layout_generate($emptyPid);
check('a book with no content is left empty rather than padded to four blanks',
    $emptyGen['pages'] === 0 && $emptyGen['blanks'] === 0,
    $emptyGen['pages'] . ' pages, ' . $emptyGen['blanks'] . ' blank');

/* ------------------------------------------ taking a photo out of the book */

echo "\nDropping a photo: skipped, not deleted, and only its page re-arranges...\n";

$dropPage = null;
foreach (book_layout_pages_with_content($frozenLayout) as $page) {
    if ($page['page_type'] === 'photos' && count($page['slots']) > 1) { $dropPage = $page; break; }
}

if ($dropPage === null) {
    check('the fixture has a multi-photo page to drop from', false);
} else {
    $dropPageId = (int) $dropPage['id'];
    $dropSlot   = null;
    foreach ($dropPage['slots'] as $slot) {
        if ($slot['photo_id'] !== null) { $dropSlot = $slot; break; }
    }

    $before      = layout_page_arrangements(book_layout_pages_with_content($frozenLayout));
    $slotsBefore = count($dropPage['slots']);
    $photoId     = (int) $dropSlot['photo_id'];

    /* Exactly what api/book-page-slots-drop.php does. */
    photo_update($photoId, array('skip_for_book' => true));
    book_page_slot_delete((int) $dropSlot['id']);
    book_page_delete_and_renumber($dropPageId);
    layout_resettle_arrangements($frozenLayout);

    $photo = photo_get($photoId);
    check('the photo is still in the library', $photo !== null);
    check('...marked skipped rather than deleted', $photo !== null && (int) $photo['skip_for_book'] === 1);

    $after     = book_layout_pages_with_content($frozenLayout);
    $afterPage = null;
    foreach ($after as $page) {
        if ((int) $page['id'] === $dropPageId) { $afterPage = $page; break; }
    }

    check('...and gone from the page it was on',
        $afterPage !== null && count($afterPage['slots']) === $slotsBefore - 1);

    /* Slot numbers close up behind it: a page left holding slots 1 and 3 would
       hand the composer an occupant list with a hole in it. */
    if ($afterPage !== null) {
        $numbers = array_map(static fn(array $s): int => (int) $s['slot_number'], $afterPage['slots']);
        check('...with the slot numbering closed up behind it',
            $numbers === range(1, count($numbers)), implode(',', $numbers));
    }

    $movedElse = array();
    foreach (layout_page_arrangements($after) as $pageId => $choice) {
        if ((int) $pageId === $dropPageId) { continue; }
        if (($before[$pageId] ?? null) !== $choice) { $movedElse[] = $pageId; }
    }
    check('...and no other page in the book moved',
        $movedElse === array(), 'moved: ' . implode(',', $movedElse));

    /* And it is out of the running for the NEXT book, which is the point of a
       flag rather than a deletion. */
    /* $yp — the project this layout belongs to. An id that does not exist
       returns nothing, and "not in nothing" passes for the wrong reason. */
    $eligible = layout_load_year_content($yp);
    $stillIn  = false;
    foreach (($eligible['photos'] ?? array()) as $p) {
        if ((int) $p['id'] === $photoId) { $stillIn = true; break; }
    }
    check('...and the layout engine will not offer it again', !$stillIn);
}

/* ------------------------------------------------- try another arrangement */

echo "\nlayout_cycle_arrangement(): steps on, saves, and comes back round...\n";

$cyclePage = null;
foreach (book_layout_pages_with_content($frozenLayout) as $page) {
    if ($page['page_type'] !== 'photos' || count($page['slots']) < 2) { continue; }
    if (count(compose_candidates(compose_occupants($page['slots']), array())) > 1) {
        $cyclePage = $page;
        break;
    }
}

if ($cyclePage === null) {
    check('the fixture has a page with more than one possible arrangement', false);
} else {
    $cycleId    = (int) $cyclePage['id'];
    $occ        = compose_occupants($cyclePage['slots']);
    $candidates = array_map(static fn(array $c): string => $c['name'], compose_candidates($occ, array()));

    $start = layout_stored_arrangement($cyclePage, $occ);
    $first = layout_cycle_arrangement($cycleId);
    check('cycling returns a template name', $first !== null && $first !== '');
    check('...and it is one the photos actually fit', in_array((string) $first, $candidates, true));
    if ($start !== null) {
        check('...and it is not the one it was already on', $first !== $start['name']);
    }

    /* Saved, not just returned. */
    $reread = book_page_with_content($cycleId);
    $stored = layout_stored_arrangement($reread, compose_occupants($reread['slots']));
    check('...the new arrangement is what the page now renders as',
        $stored !== null && $stored['name'] === $first);

    /* CYCLES. Going round the whole list must arrive back where it started —
       that is the property that makes the button usable, because it means you
       can always get back to the one you liked. A random pick could not
       promise it. */
    $seen = array((string) $first);
    for ($i = 1; $i < count($candidates); $i++) {
        $seen[] = (string) layout_cycle_arrangement($cycleId);
    }
    sort($candidates);
    $unique = array_values(array_unique($seen));
    sort($unique);
    check('...and one full turn visits every arrangement exactly once',
        $unique === $candidates,
        'visited ' . implode(',', $seen) . ' of ' . implode(',', $candidates));

    /* A full turn is N presses from where it began, so it ends where it began —
       not on $first, which is one press in. Getting this backwards is the easy
       mistake, and the check is only worth having if it says the true thing. */
    $backAgain  = book_page_with_content($cycleId);
    $backStored = layout_stored_arrangement($backAgain, compose_occupants($backAgain['slots']));
    if ($start !== null) {
        check('...and lands back exactly where it started',
            $backStored !== null && $backStored['name'] === $start['name'],
            'ended on ' . ($backStored['name'] ?? 'nothing') . ', started on ' . $start['name']);
    } else {
        check('...and ends on a template the photos fit',
            $backStored !== null && in_array($backStored['name'], $candidates, true));
    }

    /* A page with one photo has nowhere to go, and says so rather than
       pretending. */
    $singleId = 0;
    foreach (book_layout_pages_with_content($frozenLayout) as $page) {
        if ($page['page_type'] === 'photos' && count($page['slots']) === 1) {
            $singleId = (int) $page['id'];
            break;
        }
    }
    if ($singleId > 0) {
        check('a page with one photo reports that there is nowhere to cycle to',
            layout_cycle_arrangement($singleId) === null);
    }
}

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
// Still the right answer after Round 5, and worth keeping for exactly that
// reason: layout_merge_lone_subgroups() looks for a neighbour to pair this
// photo with, finds a year that contains nothing else, and leaves it alone.
/* ONE CONTENT PAGE, plus whatever blanks the signature needs — the padding is
   counted separately because it is not a layout decision. */
check('an event group with a single photo produces a single page',
    $lonelyRun['pages'] - $lonelyRun['blanks'] === 1,
    $lonelyRun['pages'] . ' pages of which ' . $lonelyRun['blanks'] . ' blank');

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
