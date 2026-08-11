<?php
/* POST /api/year-projects-cover-crop.php   { year_project_id, rect: {x,y,w,h} | null }
 *
 * How the cover photo is framed. Normalized 0..1 fractions of the photo's
 * ORIGINAL file, the same shape crop.js's openCropper() returns everywhere else
 * in this app.
 *
 * rect: null clears it, and the cover goes back to being centred on the page's
 * shape.
 *
 * WHY THE COVER HAS ITS OWN ENDPOINT rather than reusing the slot one: the
 * cover is not a page. It has no book_pages row and no slot to hang a crop on,
 * it is not part of any layout version, and it survives regenerating the book —
 * so it is stored on the year (schema.sql's year_projects.cover_crop_*) and
 * written here.
 *
 * The cover always FILLS its frame, unlike every interior photo, so something
 * is always cropped off a photo that is not square. This is how Kathryn says
 * what.
 *
 * -> { "year_project_id": 1, "cropped": true, "css": {"size":"...","position":"..."} }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/layout_render.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();
$id   = (int) ($body['year_project_id'] ?? 0);
$rect = array_key_exists('rect', $body) ? $body['rect'] : false;

if ($id <= 0 || $rect === false) {
    json_error('bad_request', 400, 'year_project_id and rect ({x,y,w,h} or null) are required.');
}
if ($rect !== null && (!is_array($rect) || !isset($rect['x'], $rect['y'], $rect['w'], $rect['h']))) {
    json_error('bad_request', 400, 'rect must be {x,y,w,h} or null.');
}

if (!year_project_set_cover_crop($id, $rect)) {
    json_error('not_found', 404, 'No such year.');
}

$project = year_project_get($id);
$css     = $project['cover_crop_x'] !== null
    ? layout_crop_css(array(
        'x' => (float) $project['cover_crop_x'], 'y' => (float) $project['cover_crop_y'],
        'w' => (float) $project['cover_crop_w'], 'h' => (float) $project['cover_crop_h'],
    ))
    : null;

json_out(array(
    'year_project_id' => $id,
    'cropped'         => $css !== null,
    /* Handed back ready to apply, so the preview can be updated from the
     * response rather than the browser recomputing the same maths. */
    'css'             => $css,
));
