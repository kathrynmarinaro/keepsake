<?php
/* POST /api/book-layouts-delete.php   { layout_id }
 *
 * Deletes one generated version of a book layout, with its pages and slots
 * (book_pages cascades — see schema.sql). Kathryn generates a version every
 * time she wants to see a change, so a year accumulates them; this is how the
 * ones she has decided against stop cluttering the list.
 *
 * REFUSES TO DELETE THE ACTIVE VERSION. book_layout_delete() would happily do
 * it and then null out year_projects.active_book_layout_id, which leaves the
 * year with no book: export stops working and the layout screen asks her to
 * generate one, with no hint that a click did it. Making her activate another
 * version first means the year always has a book, and it turns an irreversible
 * mistake into an ordinary two-step.
 *
 * NOT REVERSIBLE. There is no undo and no soft delete, because a layout is
 * regenerable — the photos, groups, captions and crops it was built from all
 * live elsewhere and are untouched. What is genuinely lost is any manual page
 * editing done on THAT version: a swap, a move, an adjusted crop, a rewritten
 * page caption. That is why the button confirms and says so.
 *
 * -> { "deleted": 12, "remaining": 6 }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body     = json_body();
$layoutId = (int) ($body['layout_id'] ?? 0);

if ($layoutId <= 0) {
    json_error('bad_request', 400, 'layout_id is required.');
}

$layout = book_layout_get($layoutId);
if ($layout === null) {
    json_error('not_found', 404, 'No such layout version.');
}

$project = year_project_get((int) $layout['year_project_id']);
if ($project === null) {
    json_error('not_found', 404, 'That layout belongs to no year.');
}

if ((int) ($project['active_book_layout_id'] ?? 0) === $layoutId) {
    json_error(
        'layout_active',
        409,
        'That is the version you are using. Switch to another one first, then delete this.'
    );
}

book_layout_delete($layoutId);

json_out(array(
    'deleted'   => $layoutId,
    'remaining' => count(book_layouts_for_year((int) $project['id'])),
));
