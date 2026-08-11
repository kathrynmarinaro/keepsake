<?php
/* POST /api/snapshots-update.php
 *   { id, title?, entry_date?, hero_photo_id?, sections? }
 *
 * A snapshot's full edit. `sections` is the whole list, in page order —
 * [{heading, body}, …] — because that is what the form is showing and a
 * section has no identity to diff against; see snapshot_sections_replace().
 *
 * THE TYPE-SPECIFIC FIELDS ARE GONE. This used to accept age/height or
 * grade/school/teacher/… depending on the row's own type, and only ever wrote
 * that template's columns. Those columns no longer exist: a snapshot is a
 * title, a hero photo, a date and any number of sections (schema.sql). `type`
 * survives as the template a new entry is seeded from and is still not
 * editable here.
 *
 * hero_photo_id comes from public/assets/photo-picker.js, which can now offer
 * every photo in the project rather than the last two dozen uploaded;
 * null/empty clears it.
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
if (array_key_exists('title', $body)) {
    $fields['title'] = is_string($body['title']) ? trim($body['title']) : null;
}
if (array_key_exists('sections', $body)) {
    /* Normalized here rather than trusted: the repo stores whatever shape it
       is handed, and a section arriving as a bare string or with extra keys
       would otherwise reach the INSERT. */
    $fields['sections'] = snapshot_sections_from_request($body['sections']);
}

snapshot_update($id, $fields);

$snapshot = snapshot_get($id);
// Cast the two id-shaped columns explicitly rather than json_out()-ing the
// raw PDO row: a MySQL/SQLite driver can hand back an int column as a
// numeric STRING, and review.js compares year_project_id against a JS
// Number to detect "this edit moved the entry to a different year" — a
// stray "5" !== 5 there would misfire on every single save.
$snapshot['id'] = (int) $snapshot['id'];
$snapshot['year_project_id'] = (int) $snapshot['year_project_id'];

/* The sections travel back with the row so the screen can repaint its summary
   from what was actually stored — empty rows are dropped on the way in, so
   what came back is not necessarily what was sent. */
$snapshot['sections'] = snapshot_sections($id);

json_out($snapshot);
