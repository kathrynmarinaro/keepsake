<?php
/* POST /api/anecdotes-update.php   { id, anecdote_text?, entry_date? }
 *
 * Same shape as quotes-update.php minus who_said_it — Phase 3's full edit
 * on an anecdote (brief §5.2/§2.2), with the same re-resolve-year-on-date-
 * change behaviour.
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

$anecdote = anecdote_get($id);
if ($anecdote === null) {
    json_error('not_found', 404);
}

$fields = array();

if (array_key_exists('anecdote_text', $body)) {
    $text = is_string($body['anecdote_text']) ? trim($body['anecdote_text']) : '';
    if ($text === '') {
        json_error('bad_request', 400, 'anecdote_text cannot be empty.');
    }
    $fields['anecdote_text'] = $text;
}
if (array_key_exists('entry_date', $body)) {
    if (!is_string($body['entry_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['entry_date'])) {
        json_error('bad_request', 400, 'entry_date must be Y-m-d.');
    }
    $fields['entry_date'] = $body['entry_date'];
}

anecdote_update($id, $fields);

$anecdote = anecdote_get($id);
json_out(array(
    'id'               => $id,
    'anecdote_text'    => $anecdote['anecdote_text'],
    'entry_date'       => $anecdote['entry_date'],
    'year_project_id'  => (int) $anecdote['year_project_id'],
));
