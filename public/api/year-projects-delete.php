<?php
/* POST /api/year-projects-delete.php   { id }
 * GET  /api/year-projects-delete.php?id=N   — what would be destroyed
 *
 * Deleting a whole project: every photo, quote, anecdote, snapshot, event
 * group and book layout in it, and the photo files on disk.
 *
 * TWO METHODS ON PURPOSE. The GET returns the counts and nothing else, so the
 * confirmation dialog can name what is about to go ("123 photos, 4 groups, 2
 * layouts") instead of asking an abstract question. The POST does the delete.
 * Kathryn asked for a warning modal with a Confirm button rather than typing
 * the project name to unlock it — so the dialog's whole job is to be specific
 * enough that a mis-tap is obvious before the tap that matters, and that
 * specificity has to come from the server, since the list screen does not
 * carry the counts.
 *
 * THERE IS NO UNDO, and unlike a swipe-deleted grocery row there cannot
 * usefully be one: the photo files are unlinked, and a five-second snackbar
 * over a few hundred megabytes of restored originals is not a thing this app
 * can promise. That is exactly why the confirm is a modal and not a snackbar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
$method = require_method('GET', 'POST');

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
} else {
    $body = json_body();
    $id   = (int) ($body['id'] ?? 0);
}

if ($id <= 0) {
    json_error('bad_request', 400, 'id is required.');
}

$project = year_project_get($id);
if ($project === null) {
    json_error('not_found', 404);
}

if ($method === 'GET') {
    json_out(array(
        'id'     => $id,
        'title'  => year_project_title($project),
        'counts' => year_project_counts($id),
    ));
}

$title  = year_project_title($project);
$counts = year_project_counts($id);

$removed = year_project_delete($id);

/* Logged because it is the one action in this app that destroys something
 * that cannot be reconstructed from anywhere else. If it ever happens by
 * accident, the server log is the only record that it happened at all. */
error_log(sprintf(
    'year-projects-delete.php: deleted project %d (%s) — %d photos, %d quotes, %d anecdotes, %d snapshots, %d groups, %d layouts, %d files removed',
    $id,
    $title,
    $counts['photos'],
    $counts['quotes'],
    $counts['anecdotes'],
    $counts['snapshots'],
    $counts['groups'],
    $counts['layouts'],
    $removed
));

json_out(array(
    'id'            => $id,
    'deleted'       => true,
    'title'         => $title,
    'counts'        => $counts,
    'files_removed' => $removed,
));
