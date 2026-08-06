<?php
/* POST /api/book-layouts-reflow.php   { layout_id, from_page_number }
 *
 * Brief §4.5's "reflow from here" — the one piece of Phase 5's engine that
 * had no UI until this phase. Wires straight to lib/layout.php's
 * layout_reflow_from(), already built and tested in Phase 5: read ITS doc
 * comment for the exact rule ("everything on the retained pages is spoken
 * for") rather than re-deriving the semantics here. This endpoint is
 * request-parsing only — no arrangement logic of its own.
 *
 * -> { "from": 6, "kept": 5, "pages": 41 }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/grouping.php';
require_once __DIR__ . '/../../lib/layout.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$layoutId = (int) ($body['layout_id'] ?? 0);
$layout   = $layoutId > 0 ? book_layout_get($layoutId) : null;
if ($layout === null) {
    json_error('not_found', 404, 'No such book layout.');
}

$fromPageNumber = (int) ($body['from_page_number'] ?? 0);
if ($fromPageNumber <= 0) {
    json_error('bad_request', 400, 'from_page_number must be a positive page number.');
}

json_out(layout_reflow_from($layoutId, $fromPageNumber));
