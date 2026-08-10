<?php
/* POST /api/photos-update.php
 *   { id, caption?, location_text?, entry_date?,
 *     skip_for_book?, full_page?, event_group_id? }
 *
 * Used by Phase 2's batch step-through (public/assets/photo-batch.js) to set
 * a photo's caption/location and correct its date after upload, AND by
 * Phase 3's desktop review screen (public/assets/review.js) for the same
 * fields plus the two book-inclusion flags and manual event-group
 * assignment — brief §2.4's "editable" fields, minus crop (see
 * photos-crop.php, a separate endpoint because a crop rewrites image files,
 * not just columns).
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
/* A date edit re-clusters this photo — see the note further down. geocode.php
 * comes with the grouper because naming a new group looks up its location. */
require_once __DIR__ . '/../../lib/geocode.php';
require_once __DIR__ . '/../../lib/grouping.php';

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
if (array_key_exists('skip_for_book', $body)) {
    $fields['skip_for_book'] = (bool) $body['skip_for_book'];
}
if (array_key_exists('full_page', $body)) {
    $fields['full_page'] = (bool) $body['full_page'];
}
if (array_key_exists('event_group_id', $body)) {
    $fields['event_group_id'] = ($body['event_group_id'] === null || $body['event_group_id'] === '')
        ? null : (int) $body['event_group_id'];
}

/* A DATE CHANGE RE-GROUPS THE PHOTO.
 *
 * Event groups are clustered from capture dates, and correcting a date used to
 * leave the old grouping behind — silently, because a group only looks wrong
 * once its members drift far enough apart to notice. Kathryn's 2025 book had
 * exactly that: eight photos uploaded with the wrong date, grouped on it,
 * corrected afterwards, and still sitting in one "event" spanning March to
 * November. The give-away was that they all still shared a time of day.
 *
 * So the photo is dropped from its group and the year's grouper is re-run,
 * which places it by its new date. This is a deliberate exception to the rule
 * stated everywhere else — that auto-grouping never touches a photo already in
 * a group — and the exception is narrow on purpose:
 *
 *   - Only when the date actually moved. Saving a caption regroups nothing.
 *   - Never when she set event_group_id in the same request. An explicit
 *     assignment is a decision, and a decision outranks the clusterer.
 *   - Only this photo is released. Everything else stays where it is; the
 *     grouper's own rule then applies to it as normal.
 *
 * The vacated group is left alone even if it is now empty. Deleting it would
 * make an edit to one photo destroy a group she may have named by hand, and an
 * empty group is visible in the Groups view where she can remove it herself.
 */
$dateMoved = isset($fields['captured_at'])
    && $fields['captured_at'] !== (string) $photo['captured_at'];
$regroup = $dateMoved && !array_key_exists('event_group_id', $body);

if ($regroup) {
    $fields['event_group_id'] = null;
}

photo_update($id, $fields);

$regrouped = null;
if ($regroup) {
    event_grouping_run((int) $photo['year_project_id']);
    $after = photo_get($id);
    $regrouped = $after['event_group_id'] !== null ? (int) $after['event_group_id'] : null;
}

$photo = photo_get($id);
json_out(array(
    'id'               => $id,
    'caption'          => $photo['caption'],
    'location_text'    => $photo['location_text'],
    'entry_date'       => substr((string) $photo['captured_at'], 0, 10),
    'skip_for_book'    => (bool) $photo['skip_for_book'],
    'full_page'        => (bool) $photo['full_page'],
    'event_group_id'   => $photo['event_group_id'] !== null ? (int) $photo['event_group_id'] : null,
    /* True when the date change moved this photo out of its old group and the
     * grouper placed it again, so the caller can say so rather than leaving a
     * regrouping to be discovered later. */
    'regrouped'        => $regroup,
    // Lets a caller (review.js) notice a date edit moved this photo to a
    // different year_project than the page it's currently displayed on —
    // see photo_update()'s header for the re-resolve-on-date-change rule.
    'year_project_id'  => (int) $photo['year_project_id'],
));
