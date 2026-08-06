<?php
/* POST /api/event-groups-create.php
 *   { year_project_id, name, start_date, end_date, location_name? }
 *
 * Manual event-group creation. Phase 4's date-gap/geocoding engine (brief
 * §4.1) hasn't run yet — see PLAN.md's Phase 3 section — so this is the ONLY
 * way a group exists to review, rename, merge or split against before then.
 * Photos are assigned to it afterward via photos-update.php's
 * event_group_id field, one photo at a time (Phase 3's review UI), not as
 * part of this call.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$yearProjectId = (int) ($body['year_project_id'] ?? 0);
if ($yearProjectId <= 0 || year_project_get($yearProjectId) === null) {
    json_error('bad_request', 400, 'year_project_id must reference an existing year.');
}

$name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
if ($name === '') {
    json_error('bad_request', 400, 'name is required.');
}

$start = is_string($body['start_date'] ?? null) ? $body['start_date'] : '';
$end   = is_string($body['end_date'] ?? null) ? $body['end_date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    json_error('bad_request', 400, 'start_date and end_date must be Y-m-d.');
}
if ($end < $start) {
    json_error('bad_request', 400, 'end_date cannot be before start_date.');
}

$location = is_string($body['location_name'] ?? null) ? trim($body['location_name']) : null;

$id = event_group_create(array(
    'year_project_id' => $yearProjectId,
    'name'            => $name,
    'start_date'      => $start,
    'end_date'        => $end,
    'location_name'   => $location,
));

json_out(array('id' => $id), 201);
