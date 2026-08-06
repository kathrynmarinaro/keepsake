<?php
/* POST /api/photos-crop.php   { id, rect: {x, y, w, h} }
 *
 * `rect` is normalized (0..1 fractions of the source), exactly what
 * public/assets/crop.js's openCropper() resolves to. Crops the photo's
 * ORIGINAL in place — a new file, new slug, old files removed once the row
 * points at the new ones — and regenerates its thumbnail. See
 * lib/imageproc.php's imageproc_crop_photo() for why this crops the kept
 * original directly rather than a separate "detail" copy: Keepsake doesn't
 * generate one.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/imageproc.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$id   = (int) ($body['id'] ?? 0);
$rect = $body['rect'] ?? null;

if ($id <= 0 || !is_array($rect) || !isset($rect['x'], $rect['y'], $rect['w'], $rect['h'])) {
    json_error('bad_request', 400, 'id and rect {x,y,w,h} are required.');
}

$photo = photo_get($id);
if ($photo === null) {
    json_error('not_found', 404);
}

$srcAbs = imageproc_resolve_upload($photo['original_path']);
if ($srcAbs === null) {
    json_error('source_missing', 500, 'The original file for this photo could not be found.');
}

$sniff = imageproc_sniff($srcAbs);
if ($sniff === null) {
    json_error('unsupported_type', 422);
}

try {
    $derived = imageproc_crop_photo($srcAbs, $rect, $sniff);
} catch (Throwable $e) {
    error_log('photos-crop: ' . $e->getMessage());
    $code = $e->getMessage() === 'crop_too_small' ? 'crop_too_small' : 'crop_failed';
    json_error($code, 422);
}

// Only removed once photo_apply_crop() has committed the new paths, so a
// crash between the crop and the UPDATE leaves the old files as an orphan
// rather than a photo row pointing at nothing.
$oldOriginalAbs = PUBLIC_DIR . '/' . $photo['original_path'];
$oldThumbAbs    = $photo['thumb_path'] !== null ? PUBLIC_DIR . '/' . $photo['thumb_path'] : null;

photo_apply_crop($id, $derived);

@unlink($oldOriginalAbs);
if ($oldThumbAbs !== null) {
    @unlink($oldThumbAbs);
}

json_out(array(
    'id'            => $id,
    'original_url'  => $derived['original_path'],
    'thumb_url'     => $derived['thumb_path'],
    'width'         => $derived['width'],
    'height'        => $derived['height'],
));
