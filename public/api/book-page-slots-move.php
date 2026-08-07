<?php
/* POST /api/book-page-slots-move.php   { slot_id, target_page_id, slot_number? }
 *
 * Phase 6's drag-and-drop, half two (brief §4.4/§5.4): "move a photo to a
 * different page" — dropping a photo onto a page rather than onto another
 * photo. Lands at the next open slot on that page unless slot_number is
 * given and free. See lib/repo.php's book_page_slot_move() for the full
 * rules (photos only, destination must be a page_type='photos' page, same
 * layout only).
 *
 * -> { "moved": true, "slot_number": 3, "page_deleted": false }
 *
 * page_deleted (Phase 8): true when this move drained the SOURCE page to
 * zero filled slots, which book_page_slot_move() deletes and renumbers for
 * automatically (see that function's own header). Determined here, not by
 * changing that function's boolean return contract — book-page-slots-move.php
 * is the only caller that needs to know, and every other caller (including
 * tools/verify-page-review.php's === true/=== false checks) keeps working
 * unmodified. public/assets/layout.js reloads instead of DOM-patching when
 * this is true, since a page vanishing and everything after it renumbering is
 * a structural change, not "these two nodes traded parents" — the same
 * distinction this screen already draws for generate/activate/reflow.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$slotId       = (int) ($body['slot_id'] ?? 0);
$targetPageId = (int) ($body['target_page_id'] ?? 0);
if ($slotId <= 0 || $targetPageId <= 0) {
    json_error('bad_request', 400, 'slot_id and target_page_id are required.');
}

$targetSlotNumber = null;
if (array_key_exists('slot_number', $body) && $body['slot_number'] !== null && $body['slot_number'] !== '') {
    $targetSlotNumber = (int) $body['slot_number'];
}

$slotBefore   = book_page_slot_get($slotId);
$sourcePageId = $slotBefore !== null ? (int) $slotBefore['book_page_id'] : null;

$ok = book_page_slot_move($slotId, $targetPageId, $targetSlotNumber);
if (!$ok) {
    // Fail soft: a full destination page, a stale page id, a slot that isn't
    // a photo, or a cross-layout attempt all land here rather than a 500 —
    // the drag just doesn't take, and the UI leaves the photo where it was.
    json_error('move_failed', 409, 'Could not move that photo there.');
}

$pageDeleted = $sourcePageId !== null
    && $sourcePageId !== $targetPageId
    && book_page_get($sourcePageId) === null;

$slot = book_page_slot_get($slotId);
json_out(array(
    'moved'        => true,
    'slot_number'  => $slot !== null ? (int) $slot['slot_number'] : null,
    'page_deleted' => $pageDeleted,
));
