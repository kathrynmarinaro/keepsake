<?php
/* POST /api/anecdotes.php
 *   { anecdote_text, entry_date }
 *
 * Quick-add (brief §2.2). Same shape as quotes.php minus who_said_it — see
 * that file's header for the year-assignment rule and the "never a photo's
 * caption" note, which apply here identically.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$text = is_string($body['anecdote_text'] ?? null) ? trim($body['anecdote_text']) : '';
$date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';

/* An explicit project, when the + was tapped from inside one. Passed straight
 * through to the repo, where year_project_for_new() decides between it and the
 * date — see lib/repo.php. Absent (0) means "the date decides", which is what
 * adding from the project list does and what this endpoint always did. */
$projectId = (int) ($body['year_project_id'] ?? 0);

if ($text === '') {
    json_error('bad_request', 400, 'anecdote_text is required.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('bad_request', 400, 'entry_date must be Y-m-d.');
}

$id = anecdote_create(array(
    'anecdote_text'   => $text,
    'entry_date'      => $date,
    'year_project_id' => $projectId,
));

json_out(array('id' => $id), 201);
