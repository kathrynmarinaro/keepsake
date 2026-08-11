<?php
/* POST /api/quotes-update.php   { id, quote_text?, who_said_it?, entry_date? }
 *
 * Phase 3's desktop review screen — full edit on a quote (brief §5.2/§2.1).
 * Only the keys present are touched (lib/repo.php's quote_update()); changing
 * entry_date re-resolves year_project_id, brief §3's "auto-assigned year is
 * editable" behaviour extended from photos to every content type.
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

$quote = quote_get($id);
if ($quote === null) {
    json_error('not_found', 404);
}

$fields = array();

if (array_key_exists('quote_text', $body)) {
    $text = is_string($body['quote_text']) ? trim($body['quote_text']) : '';
    if ($text === '') {
        json_error('bad_request', 400, 'quote_text cannot be empty.');
    }
    $fields['quote_text'] = $text;
}
if (array_key_exists('who_said_it', $body)) {
    /* Any name — see public/api/quotes.php and schema.sql on the column. */
    $who = is_string($body['who_said_it']) ? trim($body['who_said_it']) : '';
    if ($who === '') {
        json_error('bad_request', 400, 'who_said_it cannot be empty.');
    }
    if (mb_strlen($who) > 190) {
        json_error('bad_request', 400, 'who_said_it is too long (190 characters max).');
    }
    $fields['who_said_it'] = $who;
}
if (array_key_exists('entry_date', $body)) {
    if (!is_string($body['entry_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['entry_date'])) {
        json_error('bad_request', 400, 'entry_date must be Y-m-d.');
    }
    $fields['entry_date'] = $body['entry_date'];
}

quote_update($id, $fields);

$quote = quote_get($id);
json_out(array(
    'id'          => $id,
    'quote_text'  => $quote['quote_text'],
    'who_said_it' => $quote['who_said_it'],
    'entry_date'  => $quote['entry_date'],
    'year_project_id' => (int) $quote['year_project_id'],
));
