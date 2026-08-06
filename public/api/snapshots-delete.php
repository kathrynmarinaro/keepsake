<?php
/* POST /api/snapshots-delete.php   { id }
 *
 * Plain delete, no undo snackbar — see photos-delete.php's header. Deleting
 * a snapshot never deletes its hero photo (schema.sql: hero_photo_id points
 * OUT at photos, not the other way round).
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

if (snapshot_get($id) === null) {
    json_error('not_found', 404);
}

snapshot_delete($id);

json_out(array('id' => $id, 'deleted' => true));
