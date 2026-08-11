<?php
/* POST /api/book-pages-reorder.php   { layout_id, page_ids: [...] }
 *
 * Put a version's pages in a new order, by dragging one page onto another on
 * the Book tab.
 *
 * THE WHOLE ORDER, not "move page 7 to position 3". The client already knows
 * the order it is showing — it just did the move in the DOM — and sending it
 * whole means the server's answer cannot disagree with what Kathryn is looking
 * at. A move-one-page endpoint has to re-derive the other pages' numbers, and
 * two implementations of the same renumber is one too many.
 *
 * PAGES MOVE, SLOTS DO NOT. This changes book_pages.page_number and nothing
 * else: the photos on a page travel with it, its captions travel with it, its
 * crops travel with it. Moving a photo BETWEEN pages is a different gesture
 * with a different endpoint (book-page-slots-move.php).
 *
 * REGENERATING DISCARDS THIS, and so does "Reflow from here" for every page
 * from its own page onward — both rebuild page rows from scratch. That is not
 * a bug to fix here: a layout version is a generated artifact that can be
 * hand-adjusted, and the hand adjustments live only as long as the version
 * does. It is worth saying out loud in the UI, which is why layout.js's reflow
 * confirmation says it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$layoutId = (int) ($body['layout_id'] ?? 0);
$pageIds  = $body['page_ids'] ?? null;

if ($layoutId <= 0) {
    json_error('bad_request', 400, 'layout_id is required.');
}
if (!is_array($pageIds) || $pageIds === array()) {
    json_error('bad_request', 400, 'page_ids must be a non-empty array.');
}

$layout = book_layout_get($layoutId);
if ($layout === null) {
    json_error('not_found', 404);
}

/* Cast here rather than trusting the JSON's types: a page id arriving as the
 * string "12" would fail the strict set comparison in book_pages_reorder() and
 * look like a mismatched list rather than like a type problem. */
$pageIds = array_map(static fn($id): int => (int) $id, $pageIds);

if (!book_pages_reorder($layoutId, $pageIds)) {
    json_error(
        'bad_order',
        409,
        'That list is not this version\'s pages. Reload and try again.'
    );
}

json_out(array(
    'layout_id' => $layoutId,
    'page_ids'  => $pageIds,
    'pages'     => count($pageIds),
));
