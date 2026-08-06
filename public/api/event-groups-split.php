<?php
/* POST /api/event-groups-split.php
 *   { source_id, photo_ids: [int, ...], new_name }
 *
 * Brief §4.1's manual override: "split groups". The given photos (which
 * must already belong to source_id) move to a brand-new group named
 * new_name; both groups' date ranges are recomputed from their post-split
 * membership. lib/repo.php's event_group_split() skips a photo id that
 * doesn't actually belong to source_id rather than failing the whole split.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$sourceId = (int) ($body['source_id'] ?? 0);
if ($sourceId <= 0 || event_group_get($sourceId) === null) {
    json_error('bad_request', 400, 'source_id must reference an existing event group.');
}

$photoIds = is_array($body['photo_ids'] ?? null) ? $body['photo_ids'] : array();
$photoIds = array_values(array_filter(array_map('intval', $photoIds), static fn($v) => $v > 0));
if ($photoIds === array()) {
    json_error('bad_request', 400, 'photo_ids must contain at least one photo id.');
}

$newName = is_string($body['new_name'] ?? null) ? trim($body['new_name']) : '';
if ($newName === '') {
    json_error('bad_request', 400, 'new_name is required.');
}

$newId = event_group_split($sourceId, $photoIds, $newName);

json_out(array('new_group_id' => $newId, 'moved' => $photoIds), 201);
