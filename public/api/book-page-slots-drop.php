<?php
/* Take one photo out of the book — the ✕ on a photo in the Book tab.
 *
 * NOT A DELETE, and the naming is deliberate. It sets photos.skip_for_book on
 * the photo itself, which is the same flag the Content tab already toggles, so
 * the photo stays in the library, keeps its caption and its group, and can be
 * put back. What it loses is its place in the book: the slot goes, and the
 * layout engine will not offer it again.
 *
 * The page it was on then RE-ARRANGES around what is left, because its shapes
 * have changed — see layout_resettle_arrangements(). Nothing else in the book
 * moves. A page left holding nothing at all is removed and the pages after it
 * renumber, the same thing book_page_slot_move() does when it empties a page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/layout.php';

require_login_api();
require_same_origin();
require_method('POST');

$body   = json_body();
$slotId = (int) ($body['slot_id'] ?? 0);
if ($slotId <= 0) {
    json_error('bad_request', 400, 'slot_id is required.');
}

$slot = book_page_slot_get($slotId);
if ($slot === null || $slot['photo_id'] === null) {
    /* Fail soft: a stale target — the slot deleted since the page was drawn,
       or a text card, which is not something this control is offered on. */
    json_error('drop_failed', 409, 'That slot is not a photo in this book.');
}

$pageId   = (int) $slot['book_page_id'];
$layoutId = book_layout_id_for_page($pageId);

/* The flag first, then the slot. In that order a failure between the two
   leaves a photo marked skipped but still placed — visible and correctable —
   rather than a photo silently gone from the book and still eligible. */
photo_update((int) $slot['photo_id'], array('skip_for_book' => true));
book_page_slot_delete($slotId);

$pageDeleted = book_page_delete_and_renumber($pageId);

if ($layoutId > 0) {
    layout_resettle_arrangements($layoutId);
}

json_out(array(
    'dropped'      => true,
    'page_deleted' => $pageDeleted,
));
