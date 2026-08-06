<?php
/* POST /api/year-projects-update.php   { id, subtitle }
 *
 * Brief §4.6: "An optional subtitle field is available for editing during
 * the pre-layout content review step" — the book title page's subtitle.
 * Wired to public/assets/inline-edit.js on public/review.php: tapping the
 * subtitle text edits it in place, same as every other tap-to-edit field in
 * the suite. An empty string clears it back to no subtitle (title page then
 * falls back to just the year, brief §4.6's default).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_request', 400, 'id is required.');
}

if (year_project_get($id) === null) {
    json_error('not_found', 404);
}

$subtitle = array_key_exists('subtitle', $body) && is_string($body['subtitle']) ? $body['subtitle'] : null;

year_project_update_subtitle($id, $subtitle);

$project = year_project_get($id);
json_out(array('id' => $id, 'subtitle' => $project['subtitle']));
