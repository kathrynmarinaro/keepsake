<?php
/* POST /api/book-layouts-activate.php   { layout_id }
 *
 * Pick which generated version Kathryn is working from (schema.sql's
 * year_projects.active_book_layout_id: "the one I'm working from",
 * deliberately not "the newest"). Generating a layout only claims that
 * pointer when the year has none yet — after that, switching is this
 * deliberate act and nothing else.
 *
 * -> { "layout_id": 3, "year_project_id": 1 }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$layoutId = (int) ($body['layout_id'] ?? 0);
$layout   = $layoutId > 0 ? book_layout_get($layoutId) : null;
if ($layout === null) {
    json_error('not_found', 404, 'No such book layout.');
}

// The layout names its own year, so this cannot be pointed at another year's
// row even if the request tried to (PLAN.md's year-isolation rule).
$yearProjectId = (int) $layout['year_project_id'];
year_project_set_active_layout($yearProjectId, $layoutId);

json_out(array('layout_id' => $layoutId, 'year_project_id' => $yearProjectId));
