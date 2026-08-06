<?php
/* POST /api/snapshots-update.php
 *   { id, entry_date?, hero_photo_id?, notes?, ...type-appropriate fields }
 *
 * Phase 3's full edit on a snapshot (brief §5.2/§2.3). Which extra fields
 * are accepted depends on the ROW'S OWN type (birthday or school_year) —
 * lib/repo.php's snapshot_update() reads that off the existing row and only
 * writes that template's columns, same invariant snapshot_create() keeps at
 * creation time. There is no `type` field here — see snapshot_update()'s
 * own header for why changing a saved snapshot's template isn't supported.
 *
 * hero_photo_id comes from the same public/assets/photo-picker.js used at
 * creation time; null/empty clears it.
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

$snapshot = snapshot_get($id);
if ($snapshot === null) {
    json_error('not_found', 404);
}

$fields = array();

if (array_key_exists('entry_date', $body)) {
    if (!is_string($body['entry_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['entry_date'])) {
        json_error('bad_request', 400, 'entry_date must be Y-m-d.');
    }
    $fields['entry_date'] = $body['entry_date'];
}
if (array_key_exists('hero_photo_id', $body)) {
    $fields['hero_photo_id'] = ($body['hero_photo_id'] === null || $body['hero_photo_id'] === '')
        ? null : (int) $body['hero_photo_id'];
}
if (array_key_exists('notes', $body)) {
    $fields['notes'] = is_string($body['notes']) ? trim($body['notes']) : null;
}

$templateFields = $snapshot['type'] === 'birthday' ? SNAPSHOT_BIRTHDAY_FIELDS : SNAPSHOT_SCHOOL_YEAR_FIELDS;
foreach ($templateFields as $field) {
    if (array_key_exists($field, $body)) {
        $raw = $body[$field];
        if ($field === 'age') {
            $fields[$field] = (is_numeric($raw) && (int) $raw >= 0) ? (int) $raw : null;
        } else {
            $fields[$field] = is_string($raw) ? trim($raw) : null;
        }
    }
}

snapshot_update($id, $fields);

$snapshot = snapshot_get($id);
json_out($snapshot);
