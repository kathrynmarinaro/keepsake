<?php
/* POST /api/book-page-slots-swap.php   { slot_id_a, slot_id_b }
 *
 * Phase 6's drag-and-drop, half one (brief §4.4/§5.4): "swap two photos
 * between slots" — dropping one photo directly onto another. Both slots keep
 * their own position (same page, same slot_number); only which photo sits in
 * each trades places. See lib/repo.php's book_page_slot_swap() for exactly
 * what does and doesn't move, and why photos are the only thing this touches
 * (a quote/anecdote card is left alone even if the request names its slot).
 *
 * -> { "swapped": true }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
/* For layout_resettle_arrangements() — a drag can change a page's shapes, and
   the page it changed has to be re-arranged around them. */
require_once __DIR__ . '/../../lib/layout.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$slotIdA = (int) ($body['slot_id_a'] ?? 0);
$slotIdB = (int) ($body['slot_id_b'] ?? 0);
if ($slotIdA <= 0 || $slotIdB <= 0) {
    json_error('bad_request', 400, 'slot_id_a and slot_id_b are required.');
}

$ok = book_page_slot_swap($slotIdA, $slotIdB);
if (!$ok) {
    // Fail soft, per house style: a stale drag target (a slot deleted since
    // the page was rendered, a slot holding text rather than a photo, the two
    // slots belonging to different layouts) is a 409, not a 500 — the UI
    // reports it and leaves the layout exactly as it was.
    json_error('swap_failed', 409, 'Could not swap those two slots.');
}

/* The two pages may now hold different SHAPES than they were arranged for, and
   a page that has changed shape is supposed to re-arrange itself around what
   landed on it — see layout_resettle_arrangements(). Pages that did not change
   keep exactly what they had. */
$layoutId = book_layout_id_for_slot($slotIdA);
if ($layoutId > 0) {
    layout_resettle_arrangements($layoutId);
}

json_out(array('swapped' => true));
