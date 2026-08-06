<?php
/* POST /api/photos-update.php   { id, caption?, location_text?, entry_date? }
 *
 * Used by the batch step-through (public/assets/photo-batch.js) to set a
 * photo's caption/location and correct its date after upload — brief §2.4's
 * "editable" fields, minus crop (see photos-crop.php) and skip_for_book/
 * full_page, which are Phase 3's desktop review screen, not this phase's.
 *
 * `entry_date` is a bare Y-m-d, matching the <input type=date> it comes
 * from. photos.captured_at is a DATETIME (brief: time-of-day matters for
 * Phase 5's day/close-timing sub-grouping) — this endpoint keeps whatever
 * time-of-day the photo already had (from EXIF, or the upload moment) and
 * only replaces the date part, rather than asking Kathryn to also retype a
 * time nobody is trying to correct.
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

$photo = photo_get($id);
if ($photo === null) {
    json_error('not_found', 404);
}

$fields = array();

if (array_key_exists('caption', $body)) {
    $fields['caption'] = is_string($body['caption']) ? trim($body['caption']) : null;
}
if (array_key_exists('location_text', $body)) {
    $fields['location_text'] = is_string($body['location_text']) ? trim($body['location_text']) : null;
}
if (array_key_exists('entry_date', $body) && is_string($body['entry_date']) && $body['entry_date'] !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['entry_date'])) {
        json_error('bad_request', 400, 'entry_date must be Y-m-d.');
    }
    // Preserve the existing time-of-day — see this file's header.
    $time = substr((string) $photo['captured_at'], 10) ?: ' 00:00:00';
    $fields['captured_at'] = $body['entry_date'] . $time;
}

photo_update($id, $fields);

$photo = photo_get($id);
json_out(array(
    'id'            => $id,
    'caption'       => $photo['caption'],
    'location_text' => $photo['location_text'],
    'entry_date'    => substr((string) $photo['captured_at'], 0, 10),
));
