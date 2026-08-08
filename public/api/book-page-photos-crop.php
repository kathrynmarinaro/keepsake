<?php
/* POST /api/book-page-photos-crop.php   { slot_id, rect: {x,y,w,h} | null }
 *
 * `rect` is normalized (0..1 fractions of the photo's ORIGINAL file), the
 * same shape public/assets/crop.js's openCropper() already returns — but
 * unlike public/api/photos-crop.php, this does NOT touch the photo's own
 * files. It only sets book_page_photos.crop_x/y/w/h (schema.sql), which is
 * how THIS ONE PLACEMENT of the photo is windowed on THIS page — see
 * lib/repo.php's book_page_slot_set_crop() for why that has to be
 * non-destructive (a "Reflow from here" or a drag can move this same photo
 * into a differently-shaped slot without warning).
 *
 * rect: null clears the override back to auto-fit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/layout_render.php';

require_login_api();
require_same_origin();
require_method('POST');

$body   = json_body();
$slotId = (int) ($body['slot_id'] ?? 0);
$rect   = array_key_exists('rect', $body) ? $body['rect'] : false;

if ($slotId <= 0 || $rect === false) {
    json_error('bad_request', 400, 'slot_id and rect ({x,y,w,h} or null) are required.');
}
if ($rect !== null && (!is_array($rect) || !isset($rect['x'], $rect['y'], $rect['w'], $rect['h']))) {
    json_error('bad_request', 400, 'rect must be {x,y,w,h} or null.');
}

$ok = book_page_slot_set_crop($slotId, $rect);
if (!$ok) {
    json_error('not_found', 404, 'That slot does not hold a photo, or does not exist.');
}

$slot = book_page_slot_get($slotId);
$css  = $slot !== null && $slot['crop_x'] !== null
    ? layout_crop_css(array(
        'x' => (float) $slot['crop_x'], 'y' => (float) $slot['crop_y'],
        'w' => (float) $slot['crop_w'], 'h' => (float) $slot['crop_h'],
    ))
    : null;

json_out(array(
    'slot_id' => $slotId,
    'cropped' => $css !== null,
    'css'     => $css,
));
