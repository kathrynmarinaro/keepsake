<?php
/* POST /api/photos-delete.php   { id }
 *
 * Phase 3's desktop review screen. Deliberately a plain confirm-then-delete
 * endpoint, not swipe-to-delete's five-second-undo dance (public/assets/
 * swipe.js) — this screen is primarily desktop (brief §5.2), and unlike a
 * grocery item's swipe gesture, a deleted photo can't be "undone" cheaply:
 * the file is gone from disk the moment this commits. review.js asks for an
 * explicit confirmation before ever calling this.
 *
 * Removes the row THEN the files, same order as photos-crop.php: a crash
 * between the two leaves an orphaned file on disk rather than a row pointing
 * at nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/imageproc.php';

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

$originalAbs = imageproc_resolve_upload($photo['original_path']);
$thumbAbs    = $photo['thumb_path'] !== null ? imageproc_resolve_upload($photo['thumb_path']) : null;

photo_delete($id);

// Fail soft: a file that's already missing (or a path that no longer
// resolves under UPLOAD_DIR) is not this request's problem to report — the
// row is gone either way, which is the part Kathryn asked for.
if ($originalAbs !== null) {
    @unlink($originalAbs);
}
if ($thumbAbs !== null) {
    @unlink($thumbAbs);
}

json_out(array('id' => $id, 'deleted' => true));
