<?php
/* POST /api/book-layouts-create.php   { year_project_id }
 *
 * Brief §5.3's "Create Book Layout", triggered manually per year-project.
 * Runs lib/layout.php's engine over everything reviewed for that year and
 * writes a NEW book_layouts version — never an overwrite (brief §4.5), so
 * re-running to compare a different result can't cost Kathryn the one she
 * already liked.
 *
 * -> { "layout_id": 4, "version": 2, "pages": 41 }
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

$yearProjectId = (int) ($body['year_project_id'] ?? 0);
if ($yearProjectId <= 0 || year_project_get($yearProjectId) === null) {
    json_error('bad_request', 400, 'year_project_id must reference an existing year.');
}

json_out(layout_generate($yearProjectId));
