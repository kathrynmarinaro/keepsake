<?php
/* Event-grouping engine — Phase 4, brief §4.1/§4.2/§7 (see PLAN.md).
 *
 * Clusters PHOTOS ONLY into event_groups by a gap-in-days threshold between
 * consecutive photos' captured_at — not quotes, anecdotes or snapshots.
 * Those get placed into a group's date range later, by Phase 5's layout
 * engine (brief §4.3), which doesn't exist yet; nothing here writes to
 * anything but photos.event_group_id and event_groups.
 *
 * MANUAL OVERRIDE STAYS AUTHORITATIVE (brief §4.1). Every function below
 * that touches photo rows filters on `event_group_id IS NULL` — a photo
 * already in a group, whether it got there from a PRIOR run of this same
 * engine, a manual assignment via photo_update(), or a merge/split (Phase
 * 3, lib/repo.php), is never read, moved or reassigned here. The only way a
 * grouped photo becomes eligible again is Phase 3's existing UI clearing
 * event_group_id back to NULL by hand.
 *
 * SKIP_FOR_BOOK PHOTOS DO PARTICIPATE. Brief §4.2 only says skip_for_book
 * affects what's *included in the book* — it says nothing about whether a
 * photo gets organizationally grouped, and the two questions are genuinely
 * separate (schema.sql keeps them as independent columns). Excluding
 * skipped photos here would mean toggling skip_for_book on a photo could
 * silently pull a real GPS reading out of a group's location derivation,
 * or leave a skipped photo permanently ungrouped and invisible to "Group
 * photos" — worse than the alternative. Grouping is about "what happened
 * together"; book inclusion is a separate, later decision Phase 5 reads
 * independently. Simple call, not worth relitigating per PLAN.md's brief.
 *
 * TWO TRIGGER POINTS, ONE FUNCTION (PLAN.md: "there is no queue/cron in
 * this app"): public/api/photos-upload.php calls event_grouping_run() once
 * per year_project_id its batch actually touched, right after the batch's
 * inserts commit; public/api/event-groups-auto.php calls the exact same
 * function on demand from review.php's Groups view "Group photos" button,
 * for re-running over whatever's currently ungrouped (after a manual
 * ungroup, or a date correction that moved a photo into this year). Neither
 * caller re-implements any clustering/naming logic of its own.
 *
 * THE JOIN-EXISTING-VS-NEW HEURISTIC (isolated below, brief §7: "write this
 * so ... easy to re-tune once Kathryn has real data"):
 *
 *   1. Cluster ungrouped photos among THEMSELVES first, by pure date-gap
 *      distance (event_grouping_cluster_ungrouped()) — two ungrouped photos
 *      within the gap threshold of each other always end up in the same
 *      cluster, independent of any existing group.
 *   2. For each resulting cluster, measure the day-gap between the
 *      cluster's own [start, end] date range and EVERY existing group's
 *      [start_date, end_date] in the same year (event_grouping_range_gap_days()
 *      — 0 if the ranges overlap). If the closest existing group is within
 *      the gap threshold, the whole cluster JOINS that group (extending its
 *      range via event_group_recompute_dates()) instead of spawning a
 *      redundant duplicate for the same trip. Otherwise the cluster becomes
 *      a brand new group.
 *
 * Clustering ungrouped photos as a group FIRST, then testing the cluster's
 * range (not each photo individually) against existing groups, is what
 * keeps a single trip from being split across "join old group" and "form a
 * new group" decisions photo-by-photo — every photo in one gap-connected
 * run of ungrouped photos gets the same fate. Ties (a cluster equidistant
 * between two existing groups) break toward the earlier-starting group,
 * then the lower id — deterministic, but arbitrary the way the brief itself
 * expects this whole area to need iteration once real backfilled data shows
 * up (§7, §8).
 */

declare(strict_types=1);

/** Date-gap threshold in days — config over hardcoding, see config.example.php. */
function event_grouping_gap_days(): int
{
    return max(0, (int) cfg('grouping.gap_days', 3));
}

/** Whole days between two 'Y-m-d' dates, order-independent. Pure. */
function event_grouping_day_gap(string $dateA, string $dateB): int
{
    $a = DateTime::createFromFormat('Y-m-d', $dateA) ?: new DateTime($dateA);
    $b = DateTime::createFromFormat('Y-m-d', $dateB) ?: new DateTime($dateB);
    return (int) abs($a->diff($b)->days);
}

/**
 * Day-gap between two DATE ranges: 0 if they overlap (or touch), otherwise
 * the number of days strictly between the earlier range's end and the later
 * range's start. Pure, order-independent — the same measure used both to
 * cluster ungrouped photos together and to test a cluster against an
 * existing group, so "close enough to join" means the same thing on both
 * sides of that decision.
 */
function event_grouping_range_gap_days(string $startA, string $endA, string $startB, string $endB): int
{
    if ($startA > $endB) {
        return event_grouping_day_gap($endB, $startA);
    }
    if ($startB > $endA) {
        return event_grouping_day_gap($endA, $startB);
    }
    return 0;
}

/**
 * Step 1 of the heuristic: group ungrouped photos among themselves by pure
 * date-gap distance. Pure function over photo rows (only 'captured_at' and
 * 'id' are read) — no DB access, so tools/verify-grouping.php can feed it
 * synthetic rows directly.
 *
 * @param array $photos rows with at least 'id' and 'captured_at'
 * @return list<array> list of clusters, each a list of the same photo rows,
 *   sorted chronologically both within and across clusters
 */
function event_grouping_cluster_ungrouped(array $photos, int $gapDays): array
{
    usort($photos, static function (array $a, array $b): int {
        return strcmp((string) $a['captured_at'], (string) $b['captured_at']);
    });

    $clusters = array();
    $current  = array();
    $prevDate = null;

    foreach ($photos as $photo) {
        $date = substr((string) $photo['captured_at'], 0, 10);
        if ($prevDate !== null && event_grouping_day_gap($prevDate, $date) > $gapDays) {
            $clusters[] = $current;
            $current    = array();
        }
        $current[] = $photo;
        $prevDate  = $date;
    }
    if ($current !== array()) {
        $clusters[] = $current;
    }

    return $clusters;
}

/**
 * Step 2 of the heuristic: the closest existing group within the gap
 * threshold of a cluster's date range, or null if none qualifies. Pure.
 *
 * @param array $existingGroups rows with 'id', 'start_date', 'end_date'
 */
function event_grouping_find_join_target(
    string $clusterStart,
    string $clusterEnd,
    array $existingGroups,
    int $gapDays
): ?array {
    $best     = null;
    $bestDist = null;

    foreach ($existingGroups as $group) {
        $dist = event_grouping_range_gap_days(
            $clusterStart,
            $clusterEnd,
            (string) $group['start_date'],
            (string) $group['end_date']
        );
        if ($dist > $gapDays) {
            continue;
        }

        $better = $bestDist === null
            || $dist < $bestDist
            || ($dist === $bestDist && event_grouping_group_is_earlier($group, $best));

        if ($better) {
            $best     = $group;
            $bestDist = $dist;
        }
    }

    return $best;
}

/** Deterministic tie-break for equidistant candidates: earlier start_date, then lower id. */
function event_grouping_group_is_earlier(array $candidate, array $current): bool
{
    $cStart = (string) $candidate['start_date'];
    $bStart = (string) $current['start_date'];
    if ($cStart !== $bStart) {
        return $cStart < $bStart;
    }
    return (int) $candidate['id'] < (int) $current['id'];
}

/**
 * The full plan for one year's ungrouped photos: cluster them, then decide
 * join-existing vs. new-group per cluster. Pure — no DB access — so this is
 * where tools/verify-grouping.php proves the heuristic itself, independent
 * of the DB-writing orchestration in event_grouping_run() below.
 *
 * @param array $ungroupedPhotos rows with 'id', 'captured_at' (event_group_id IS NULL)
 * @param array $existingGroups  rows with 'id', 'start_date', 'end_date'
 * @return list<array{
 *   action: 'join'|'create', photo_ids: list<int>,
 *   group_id?: int, start_date?: string, end_date?: string
 * }>
 */
function event_grouping_plan(array $ungroupedPhotos, array $existingGroups, int $gapDays): array
{
    $actions = array();

    foreach (event_grouping_cluster_ungrouped($ungroupedPhotos, $gapDays) as $cluster) {
        $dates = array();
        foreach ($cluster as $photo) {
            $dates[] = substr((string) $photo['captured_at'], 0, 10);
        }
        sort($dates);
        $start = $dates[0];
        $end   = $dates[count($dates) - 1];

        $photoIds = array_map(static fn(array $p): int => (int) $p['id'], $cluster);

        $target = event_grouping_find_join_target($start, $end, $existingGroups, $gapDays);
        if ($target !== null) {
            $actions[] = array(
                'action'    => 'join',
                'group_id'  => (int) $target['id'],
                'photo_ids' => $photoIds,
            );
        } else {
            $actions[] = array(
                'action'     => 'create',
                'photo_ids'  => $photoIds,
                'start_date' => $start,
                'end_date'   => $end,
            );
        }
    }

    return $actions;
}

/**
 * Location for a cluster/group's auto-name: the first (chronologically
 * earliest) member photo that carries GPS, reverse-geocoded via
 * lib/geocode.php. NOT an average of every member's coordinates — a
 * multi-stop trip's centroid can land somewhere none of the photos were
 * actually taken (or in the ocean between two coastal stops) — and NOT
 * every GPS-bearing photo tried in turn, which would multiply Nominatim
 * calls (rate-limited to ~1/sec, brief §7) across one group's naming for
 * little benefit once the first candidate resolves. A network failure on
 * that one candidate degrades to null (date-range-only fallback) rather
 * than trying the next photo — simplest defensible choice; flagged here,
 * per the brief's own expectation that this area gets revisited with real
 * data (§7/§8), as the first thing to reconsider if Kathryn finds a group's
 * location keeps missing when a later photo in the same group did have GPS.
 *
 * @param array $memberPhotos rows with 'captured_at', 'gps_lat', 'gps_lon'
 */
function event_grouping_resolve_location(array $memberPhotos): ?string
{
    usort($memberPhotos, static function (array $a, array $b): int {
        return strcmp((string) $a['captured_at'], (string) $b['captured_at']);
    });

    foreach ($memberPhotos as $photo) {
        if ($photo['gps_lat'] !== null && $photo['gps_lon'] !== null) {
            return geocode_reverse((float) $photo['gps_lat'], (float) $photo['gps_lon']);
        }
    }

    return null;
}

/** "Jul 4" / "Jul 4–6" / "Jul 30–Aug 2" / "Dec 30, 2024–Jan 2, 2025". Pure. */
function event_grouping_format_date_range(string $startDate, string $endDate): string
{
    static $months = array(
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    );

    [$sy, $sm, $sd] = explode('-', $startDate);
    [$ey, $em, $ed] = explode('-', $endDate);

    $sLabel = $months[(int) $sm] . ' ' . (int) $sd;
    $eLabel = $months[(int) $em] . ' ' . (int) $ed;

    if ($startDate === $endDate) {
        return $sLabel;
    }
    if ($sy === $ey && $sm === $em) {
        return $sLabel . '–' . (int) $ed;
    }
    if ($sy === $ey) {
        return $sLabel . '–' . $eLabel;
    }
    // A cluster straddling New Year's Eve is rare but not impossible for a
    // backfilled trip — spell out both years so the range isn't ambiguous.
    return $sLabel . ', ' . $sy . '–' . $eLabel . ', ' . $ey;
}

/**
 * "Jul 4–6 · Myrtle Beach" (schema.sql's own example on event_groups.name),
 * falling back to the date range alone when $locationName is null (brief
 * §4.1: "Falls back to date range only if no GPS data is available"). Pure.
 */
function event_grouping_format_name(string $startDate, string $endDate, ?string $locationName): string
{
    $range = event_grouping_format_date_range($startDate, $endDate);
    return ($locationName !== null && $locationName !== '') ? ($range . ' · ' . $locationName) : $range;
}

/**
 * Refresh location_name (and name, unless frozen) for a group whose
 * membership just changed, reading its CURRENT full membership rather than
 * just the photos that triggered this call — a group's location should
 * reflect everything in it, not only whatever joined most recently.
 *
 * Respects is_manual_name (schema.sql's comment on the column, brief §4.1's
 * manual-override rule): once Kathryn renames a group, this never touches
 * `name` again, but location_name and start_date/end_date may still be
 * refreshed — the column comment's own carve-out. Callers run
 * event_group_recompute_dates() themselves beforehand; this function only
 * owns name/location_name.
 */
function event_grouping_refresh_naming(int $groupId): void
{
    $group = event_group_get($groupId);
    if ($group === null) {
        return;
    }

    $members  = q('SELECT * FROM photos WHERE event_group_id = ?', array($groupId))->fetchAll();
    $location = event_grouping_resolve_location($members);

    if ((bool) $group['is_manual_name']) {
        q('UPDATE event_groups SET location_name = ? WHERE id = ?', array($location, $groupId));
        return;
    }

    $name = event_grouping_format_name((string) $group['start_date'], (string) $group['end_date'], $location);
    q('UPDATE event_groups SET name = ?, location_name = ? WHERE id = ?', array($name, $location, $groupId));
}

/**
 * The single entry point both trigger points call (see this file's header):
 * cluster this year's currently-ungrouped photos, join or create groups per
 * event_grouping_plan()'s decisions, and (re)derive naming/location for
 * every group touched.
 *
 * @return array{groups_created: int, groups_extended: int, photos_grouped: int}
 */
function event_grouping_run(int $yearProjectId): array
{
    $summary = array('groups_created' => 0, 'groups_extended' => 0, 'photos_grouped' => 0);

    $ungrouped = q(
        'SELECT * FROM photos WHERE year_project_id = ? AND event_group_id IS NULL ORDER BY captured_at, id',
        array($yearProjectId)
    )->fetchAll();

    if ($ungrouped === array()) {
        return $summary;
    }

    $existingGroups = q(
        'SELECT id, start_date, end_date FROM event_groups WHERE year_project_id = ?',
        array($yearProjectId)
    )->fetchAll();

    $byId = array();
    foreach ($ungrouped as $photo) {
        $byId[(int) $photo['id']] = $photo;
    }

    $actions = event_grouping_plan($ungrouped, $existingGroups, event_grouping_gap_days());

    foreach ($actions as $action) {
        $photoIds = $action['photo_ids'];
        $members  = array_map(static fn(int $id): array => $byId[$id], $photoIds);

        if ($action['action'] === 'join') {
            $groupId = $action['group_id'];
            foreach ($photoIds as $photoId) {
                photo_update($photoId, array('event_group_id' => $groupId));
            }
            event_group_recompute_dates($groupId);
            event_grouping_refresh_naming($groupId);
            $summary['groups_extended']++;
        } else {
            $location = event_grouping_resolve_location($members);
            $name     = event_grouping_format_name($action['start_date'], $action['end_date'], $location);

            $groupId = event_group_create(array(
                'year_project_id' => $yearProjectId,
                'name'            => $name,
                'start_date'      => $action['start_date'],
                'end_date'        => $action['end_date'],
                'location_name'   => $location,
                'is_manual_name'  => false,
            ));
            foreach ($photoIds as $photoId) {
                photo_update($photoId, array('event_group_id' => $groupId));
            }
            $summary['groups_created']++;
        }

        $summary['photos_grouped'] += count($photoIds);
    }

    return $summary;
}
