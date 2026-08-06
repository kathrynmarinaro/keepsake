<?php
/* POST /api/event-groups-delete.php   { id }
 *
 * "Ungroup" — deletes the event_groups row only. photos.event_group_id is
 * ON DELETE SET NULL (schema.sql), so member photos are never deleted, just
 * unassigned back to the state they were in before any grouping ran.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_request', 400, 'id is required.');
}
if (event_group_get($id) === null) {
    json_error('not_found', 404);
}

event_group_delete($id);

json_out(array('id' => $id, 'deleted' => true));
