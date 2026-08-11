<?php
/* POST /api/snapshots.php
 *   { type: 'birthday'|'school_year', entry_date, title?, hero_photo_id?,
 *     sections? }
 *
 * A snapshot is a title, a hero photo, a date and any number of sections.
 * `type` picks the TEMPLATE — which section headings a new one starts with,
 * SNAPSHOT_TEMPLATES in lib/repo.php — and does nothing after that. It used
 * to say which of nine fixed columns existed; those columns are gone
 * (schema.sql on snapshots).
 *
 * OMITTING `sections` MEANS "seed me from the template". Sending an empty
 * array means "no sections at all", which is a different thing and stays
 * possible — snapshot_create() tells them apart with array_key_exists.
 *
 * hero_photo_id is Kathryn's manual pick (brief: "not auto-pulled"), from
 * public/assets/photo-picker.js — which can now offer every photo in the
 * project rather than the last two dozen uploaded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$type = $body['type'] ?? null;
if (!in_array($type, array('birthday', 'school_year'), true)) {
    json_error('bad_request', 400, "type must be 'birthday' or 'school_year'.");
}

$date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('bad_request', 400, 'entry_date must be Y-m-d.');
}

$data = array(
    'type'       => $type,
    'entry_date' => $date,
    'title'      => is_string($body['title'] ?? null) ? trim($body['title']) : null,
    /* An explicit project, when the + was tapped from inside one — see
       year_project_for_new() in lib/repo.php. 0 means "the date decides". */
    'year_project_id' => (int) ($body['year_project_id'] ?? 0),
);

if (array_key_exists('sections', $body)) {
    $data['sections'] = snapshot_sections_from_request($body['sections']);
}

$heroPhotoId = $body['hero_photo_id'] ?? null;
$data['hero_photo_id'] = ($heroPhotoId !== null && $heroPhotoId !== '') ? (int) $heroPhotoId : null;

$id = snapshot_create($data);

json_out(array('id' => $id, 'sections' => snapshot_sections($id)), 201);
