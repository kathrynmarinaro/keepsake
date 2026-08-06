<?php
/* POST /api/event-groups-rename.php   { id, name }
 *
 * Brief §4.1's manual override: "Kathryn can rename any group." Sets
 * is_manual_name so Phase 4's auto-naming pass, once it exists, skips this
 * group's name on a later re-run (schema.sql's comment on the column).
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

$name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
if ($name === '') {
    json_error('bad_request', 400, 'name cannot be empty.');
}

event_group_rename($id, $name);

json_out(array('id' => $id, 'name' => $name));
