<?php
/* POST /api/event-groups-merge.php   { source_ids: [int, ...], target_id }
 *
 * Brief §4.1's manual override: "merge ... groups — critical for avoiding
 * the 'stray unrelated photo on the Myrtle Beach page' problem" (well, the
 * opposite of that problem: two groups that are really one event). Every
 * photo in each source group is reassigned to target_id and the source
 * groups are deleted; lib/repo.php's event_group_merge() silently skips a
 * source id that doesn't exist or belongs to a different year rather than
 * aborting the whole merge — see that function's header.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$targetId = (int) ($body['target_id'] ?? 0);
if ($targetId <= 0 || event_group_get($targetId) === null) {
    json_error('bad_request', 400, 'target_id must reference an existing event group.');
}

$sourceIds = is_array($body['source_ids'] ?? null) ? $body['source_ids'] : array();
$sourceIds = array_values(array_filter(array_map('intval', $sourceIds), static fn($v) => $v > 0));

if ($sourceIds === array()) {
    json_error('bad_request', 400, 'source_ids must contain at least one event group id.');
}

event_group_merge($sourceIds, $targetId);

json_out(array('target_id' => $targetId, 'merged' => $sourceIds));
