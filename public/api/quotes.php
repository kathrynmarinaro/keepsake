<?php
/* POST /api/quotes.php
 *   { quote_text, who_said_it, entry_date }
 *
 * Quick-add (brief §2.1). year_project_id is resolved from entry_date by
 * lib/repo.php's quote_create() — see that file's header for the
 * year-auto-assignment rule this implements.
 *
 * NO photo_id. A quote is never attached to a photo as its caption — it's
 * always a standalone, dated entry; entry_date is what lets it land near
 * related photos in the book layout (Phase 5), not a link to one specific
 * photo. If a photo needs a caption, that's typed directly during upload
 * (photos.caption, see public/assets/photo-batch.js) — a separate mechanism
 * this endpoint has nothing to do with.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$text = is_string($body['quote_text'] ?? null) ? trim($body['quote_text']) : '';
$who  = $body['who_said_it'] ?? null;
$date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';

if ($text === '') {
    json_error('bad_request', 400, 'quote_text is required.');
}
if (!in_array($who, array('Kathryn', 'Emma'), true)) {
    json_error('bad_request', 400, "who_said_it must be 'Kathryn' or 'Emma'.");
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('bad_request', 400, 'entry_date must be Y-m-d.');
}

$id = quote_create(array('quote_text' => $text, 'who_said_it' => $who, 'entry_date' => $date));

json_out(array('id' => $id), 201);
