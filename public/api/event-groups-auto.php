<?php
/* POST /api/event-groups-auto.php   { year_project_id }
 *
 * Phase 4 trigger point 2/2 (PLAN.md, brief §4.1/§5.2) — review.php's
 * Groups view "Group photos" button. Re-clusters whatever's CURRENTLY
 * ungrouped for this year (event_group_id IS NULL): after a manual ungroup,
 * a date correction that moved a photo into this year, or simply because
 * nothing has auto-grouped it yet. Calls the exact same
 * lib/grouping.php::event_grouping_run() that photos-upload.php's batch
 * end calls, so there is exactly one place the clustering/naming/geocoding
 * logic lives — see that function's own header for what it does and does
 * not touch (in particular: never re-groups a photo that's already in a
 * group, by any means).
 *
 * -> { "groups_created": 1, "groups_extended": 0, "photos_grouped": 5 }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/geocode.php';
require_once __DIR__ . '/../../lib/grouping.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$yearProjectId = (int) ($body['year_project_id'] ?? 0);
if ($yearProjectId <= 0 || year_project_get($yearProjectId) === null) {
    json_error('bad_request', 400, 'year_project_id must reference an existing year.');
}

$summary = event_grouping_run($yearProjectId);

json_out($summary);
