<?php
/* POST /api/snapshots.php
 *   { type: 'birthday'|'school_year', entry_date, hero_photo_id?, notes?,
 *     ...template fields }
 *
 * Two templates (brief §2.3), both with every field optional except type and
 * entry_date. Which extra fields are read depends on `type` — see
 * lib/repo.php's SNAPSHOT_BIRTHDAY_FIELDS / SNAPSHOT_SCHOOL_YEAR_FIELDS,
 * which is also where the "only this template's columns get written" rule
 * actually lives, not here.
 *
 * hero_photo_id is Kathryn's manual pick (brief: "not auto-pulled") — the
 * client gets it from public/assets/photo-picker.js, the same component the
 * quick-add quote/anecdote forms use to bundle a photo.
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
    'notes'      => is_string($body['notes'] ?? null) ? trim($body['notes']) : null,
    /* An explicit project, when the + was tapped from inside one — see
       year_project_for_new() in lib/repo.php. 0 means "the date decides". */
    'year_project_id' => (int) ($body['year_project_id'] ?? 0),
);

$heroPhotoId = $body['hero_photo_id'] ?? null;
$data['hero_photo_id'] = ($heroPhotoId !== null && $heroPhotoId !== '') ? (int) $heroPhotoId : null;

if ($type === 'birthday') {
    // Numeric age only — a stray non-numeric value is dropped (fail soft)
    // rather than failing the whole submission over one optional field.
    $age = $body['age'] ?? null;
    $data['age']    = (is_numeric($age) && (int) $age >= 0) ? (int) $age : null;
    $data['height'] = is_string($body['height'] ?? null) ? trim($body['height']) : null;
} else {
    foreach (array('grade', 'school', 'teacher', 'favorite_color', 'dream_job', 'favorite_class') as $field) {
        $data[$field] = is_string($body[$field] ?? null) ? trim($body[$field]) : null;
    }
}

$id = snapshot_create($data);

json_out(array('id' => $id), 201);
