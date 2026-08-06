<?php
/**
 * Phase 4 (event grouping + geocoding) smoke test: proves the date-gap
 * clustering / join-existing-vs-new heuristic, the manual-override
 * invariants (an already-grouped photo is never touched; a manually
 * ungrouped photo becomes eligible again), the geocode_cache lookup/store
 * cycle, and the date-range/no-GPS naming fallback — against the SQLite
 * test-harness database, with a stubbed geocode_http_fetch() so no real
 * network call happens. No browser, no MySQL, no Nominatim.
 *
 * Follows tools/verify-review.php's own style and header conventions.
 *
 * WHAT THIS CHECKS:
 *   1. event_grouping_cluster_ungrouped(): a run of photos within the gap
 *      threshold of each other forms ONE cluster; two runs separated by
 *      more than the threshold form TWO.
 *   2. event_grouping_run() end to end on a fresh year: one backfilled trip
 *      (photos within the gap threshold) produces a single event group —
 *      THE exit-criterion behaviour. Two clearly separate trips produce
 *      two groups.
 *   3. The join-existing-vs-new heuristic: an ungrouped photo whose date is
 *      within the gap threshold of an EXISTING group's range joins that
 *      group (its date range widens) rather than spawning a duplicate; a
 *      photo far outside every existing group's range starts a new one.
 *   4. Already-grouped photos are NEVER touched by a re-run: a second call
 *      to event_grouping_run() with nothing newly ungrouped changes
 *      nothing (all summary counts are 0), and a photo's event_group_id
 *      from the first run is unchanged.
 *   5. A manually-ungrouped photo (photo_update(event_group_id => null))
 *      becomes eligible again on the next run.
 *   6. geocode_cache is consulted BEFORE any "network" call (a second
 *      geocode_reverse() for the same rounded coordinates does not call the
 *      stub again) and written AFTER a "network" fetch (the row exists in
 *      geocode_cache afterward).
 *   7. Naming: a cluster with a GPS-bearing photo gets "date range ·
 *      Location" (via the stub); a cluster with no GPS at all gets the
 *      date-range-only fallback, no location suffix.
 *   8. is_manual_name is respected: once a group's name is set by hand,
 *      extending it with more ungrouped photos changes location_name/dates
 *      but leaves the name untouched.
 *
 * Usage:
 *   php tools/verify-grouping.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

/* lib/bootstrap.php is NOT loaded here — same reason tools/verify-capture.php
 * and tools/verify-review.php don't load it: it requires a real config.php
 * file this build environment doesn't have (see lib/bootstrap.php's own
 * guard). lib/geocode.php/grouping.php both call cfg(), so this test-only
 * shim reads config.example.php directly instead — the exact tunables and
 * defaults Kathryn's real config.php would carry, without pulling in the
 * session/DSN machinery the rest of bootstrap.php does that this script has
 * no use for. */
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

require __DIR__ . '/../lib/geocode.php';
require __DIR__ . '/../lib/grouping.php';

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

/** Insert a bare-bones photo row directly (bypasses upload/EXIF plumbing —
 *  this test is about grouping, not capture) and return its id. */
function make_photo(int $yearProjectId, string $capturedAt, ?float $lat = null, ?float $lon = null): int
{
    return photo_create(array(
        'original_path' => 'uploads/original/test.jpg',
        'thumb_path'    => null,
        'width'         => 100,
        'height'        => 100,
        'captured_at'   => $capturedAt,
        'gps_lat'       => $lat,
        'gps_lon'       => $lon,
    ));
}

function event_group_id_of(int $photoId): ?int
{
    $row = photo_get($photoId);
    return $row['event_group_id'] !== null ? (int) $row['event_group_id'] : null;
}

/* A fake Nominatim response, in the shape geocode_parse_response() expects. */
$geocodeCallCount = 0;
$GLOBALS['keepsake_geocode_fetch_override'] = function (string $url) use (&$geocodeCallCount): ?string {
    $geocodeCallCount++;
    return json_encode(array(
        'display_name' => 'Myrtle Beach, Horry County, South Carolina, United States',
        'address'      => array('city' => 'Myrtle Beach', 'county' => 'Horry County', 'state' => 'South Carolina'),
    ));
};

/* ============================================== pure clustering heuristic */

echo "event_grouping_cluster_ungrouped(): pure date-gap clustering...\n";

$onePhoto = fn(string $id, string $date) => array('id' => $id, 'captured_at' => $date . ' 12:00:00');

$oneTrip = array_map($onePhoto, array('1', '2', '3'), array('2024-07-04', '2024-07-05', '2024-07-06'));
$clusters = event_grouping_cluster_ungrouped($oneTrip, 3);
check('three photos 1 day apart (within gap 3) form ONE cluster', count($clusters) === 1);
check('that cluster has all three photos', count($clusters[0]) === 3);

$twoTrips = array_map(
    $onePhoto,
    array('1', '2', '3', '4'),
    array('2024-07-04', '2024-07-05', '2024-07-20', '2024-07-21')
);
$clusters2 = event_grouping_cluster_ungrouped($twoTrips, 3);
check('two runs separated by 14 days (beyond gap 3) form TWO clusters', count($clusters2) === 2);
check('first cluster has 2 photos', count($clusters2[0]) === 2);
check('second cluster has 2 photos', count($clusters2[1]) === 2);

echo "\nevent_grouping_range_gap_days(): overlap vs. distance...\n";
check('overlapping ranges are 0 apart', event_grouping_range_gap_days('2024-07-01', '2024-07-05', '2024-07-03', '2024-07-10') === 0);
check('adjacent ranges (touching) are 0 apart', event_grouping_range_gap_days('2024-07-01', '2024-07-05', '2024-07-05', '2024-07-10') === 0);
check('ranges 3 days apart measure 3, order-independent (A before B)', event_grouping_range_gap_days('2024-07-01', '2024-07-05', '2024-07-08', '2024-07-10') === 3);
check('ranges 3 days apart measure 3, order-independent (B before A)', event_grouping_range_gap_days('2024-07-08', '2024-07-10', '2024-07-01', '2024-07-05') === 3);

echo "\nevent_grouping_format_date_range(): naming's pure formatting...\n";
check("single day formats as 'Jul 4'", event_grouping_format_date_range('2024-07-04', '2024-07-04') === 'Jul 4');
check("same month formats as 'Jul 4–6'", event_grouping_format_date_range('2024-07-04', '2024-07-06') === 'Jul 4–6');
check("cross-month formats as 'Jul 30–Aug 2'", event_grouping_format_date_range('2024-07-30', '2024-08-02') === 'Jul 30–Aug 2');
check(
    "cross-year formats as 'Dec 30, 2024–Jan 2, 2025'",
    event_grouping_format_date_range('2024-12-30', '2025-01-02') === 'Dec 30, 2024–Jan 2, 2025'
);

/* ==================================================== geocode cache cycle */

echo "\ngeocode_reverse(): cache-first, network only on a miss...\n";

check('geocode_cache starts empty for this coordinate', geocode_cache_lookup(33.689, -78.886) === null);

$name1 = geocode_reverse(33.6891, -78.8867);
check('the stub was called exactly once (a genuine cache miss)', $geocodeCallCount === 1);
check('geocode_reverse() returned the stub\'s parsed name', $name1 === 'Myrtle Beach, South Carolina');
check('the result was written to geocode_cache, rounded to 3 decimals', geocode_cache_lookup(33.689, -78.887) === 'Myrtle Beach, South Carolina');

$name2 = geocode_reverse(33.6893, -78.8869); // rounds to the SAME 33.689/-78.887 cell
check('a second call for a coordinate in the SAME rounded cell does NOT hit the network again', $geocodeCallCount === 1);
check('it still returns the cached name', $name2 === 'Myrtle Beach, South Carolina');

/* ============================================ run 1: one trip -> one group */

echo "\nevent_grouping_run(): a backfilled single trip produces ONE group...\n";

$yp2024 = year_project_get_or_create('2024-01-01');

$p1 = make_photo($yp2024, '2024-07-04 09:00:00', 33.6891, -78.8867);
$p2 = make_photo($yp2024, '2024-07-05 10:00:00');
$p3 = make_photo($yp2024, '2024-07-06 18:30:00');

$summary1 = event_grouping_run($yp2024);
check('one group was created', $summary1['groups_created'] === 1);
check('no group was extended (nothing existed yet)', $summary1['groups_extended'] === 0);
check('all 3 photos were grouped', $summary1['photos_grouped'] === 3);

$g1 = event_group_id_of($p1);
check('all three photos share the same event_group_id', $g1 !== null && event_group_id_of($p2) === $g1 && event_group_id_of($p3) === $g1);

$groupRow = event_group_get($g1);
check('the group\'s date range spans Jul 4–6', $groupRow['start_date'] === '2024-07-04' && $groupRow['end_date'] === '2024-07-06');
check('the group\'s name includes the geocoded location', $groupRow['name'] === 'Jul 4–6 · Myrtle Beach, South Carolina');
check('the group is NOT flagged manual (auto-naming may still revise it)', (int) $groupRow['is_manual_name'] === 0);

/* ======================================= run 2: a second, separate trip */

echo "\nevent_grouping_run(): a clearly separate second trip produces a SECOND group...\n";

$p4 = make_photo($yp2024, '2024-08-20 09:00:00'); // no GPS
$p5 = make_photo($yp2024, '2024-08-21 09:00:00');

$summary2 = event_grouping_run($yp2024);
check('exactly one new group was created for the second trip', $summary2['groups_created'] === 1);
check('the first trip\'s group was not touched (0 extended)', $summary2['groups_extended'] === 0);
check('2 photos were grouped this run', $summary2['photos_grouped'] === 2);

$g2 = event_group_id_of($p4);
check('the second trip is a DIFFERENT group from the first', $g2 !== null && $g2 !== $g1);
check('the second group has no location (no member had GPS)', event_group_get($g2)['location_name'] === null);
check('the second group\'s name is date-range-only, no " · " suffix', event_group_get($g2)['name'] === 'Aug 20–21');

/* =========================== already-grouped photos are never re-touched */

echo "\nRe-running with nothing newly ungrouped changes nothing...\n";

$summary3 = event_grouping_run($yp2024);
check('groups_created is 0 on a no-op re-run', $summary3['groups_created'] === 0);
check('groups_extended is 0 on a no-op re-run', $summary3['groups_extended'] === 0);
check('photos_grouped is 0 on a no-op re-run', $summary3['photos_grouped'] === 0);
check('the first trip\'s photo is still in its original group (untouched)', event_group_id_of($p1) === $g1);

/* ==================================== join-existing-vs-new: within threshold */

echo "\nAn ungrouped photo close to an EXISTING group's range JOINS it...\n";

// Jul 9 is 3 days after the first trip's Jul 6 end — exactly at the gap
// threshold (3), so this should EXTEND group $g1, not spawn a duplicate.
$p6 = make_photo($yp2024, '2024-07-09 08:00:00');

$summary4 = event_grouping_run($yp2024);
check('no new group was created (it joined the existing one)', $summary4['groups_created'] === 0);
check('exactly one existing group was extended', $summary4['groups_extended'] === 1);
check('the photo landed in the FIRST trip\'s group, not a new one', event_group_id_of($p6) === $g1);

$g1After = event_group_get($g1);
check('the joined group\'s date range widened to include Jul 9', $g1After['end_date'] === '2024-07-09');

/* ========================================== manual ungroup makes eligible again */

echo "\nA manually-ungrouped photo becomes eligible again...\n";

photo_update($p2, array('event_group_id' => null));
check('the photo is ungrouped after the manual clear', event_group_id_of($p2) === null);

$summary5 = event_grouping_run($yp2024);
check('the re-run actually did something (photos_grouped > 0)', $summary5['photos_grouped'] > 0);
check('the manually-ungrouped photo is grouped again', event_group_id_of($p2) !== null);

/* ========================================================= is_manual_name */

echo "\nis_manual_name freezes the name but not location_name/dates...\n";

$ypManual = year_project_get_or_create('2025-01-01');
$m1 = make_photo($ypManual, '2025-03-01 09:00:00');
$m2 = make_photo($ypManual, '2025-03-02 09:00:00');
event_grouping_run($ypManual);
$manualGroupId = event_group_id_of($m1);

event_group_rename($manualGroupId, 'Emma\'s birthday weekend');
check('the group is now flagged manual', (int) event_group_get($manualGroupId)['is_manual_name'] === 1);

// A third photo, close enough to join, WITH gps this time.
$m3 = make_photo($ypManual, '2025-03-04 09:00:00', 33.6891, -78.8867);
$summaryManual = event_grouping_run($ypManual);
check('the third photo joined the manually-named group (no new group)', $summaryManual['groups_created'] === 0 && $summaryManual['groups_extended'] === 1);

$manualAfter = event_group_get($manualGroupId);
check('the manually-set NAME is unchanged', $manualAfter['name'] === 'Emma\'s birthday weekend');
check('location_name WAS refreshed despite is_manual_name', $manualAfter['location_name'] === 'Myrtle Beach, South Carolina');
check('the date range WAS refreshed to include the new member', $manualAfter['end_date'] === '2025-03-04');

/* ================================================================= result */

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) FAILED.\n";
    exit(1);
}
echo "All checks passed.\n";
