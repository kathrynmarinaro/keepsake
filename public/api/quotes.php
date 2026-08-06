<?php
/* POST /api/quotes.php
 *   { quote_text, who_said_it, entry_date, photo_id? }
 *
 * Quick-add (brief §2.1). year_project_id is resolved from entry_date by
 * lib/repo.php's quote_create() — see that file's header for the
 * year-auto-assignment rule this implements.
 *
 * `photo_id`, if present, bundles this quote onto an already-uploaded photo
 * as its caption instead of leaving it standalone (brief §2.5) — the
 * "optional photo+text bundling at submission time" the capture flow offers
 * from the quick-add side; public/assets/photo-picker.js is what supplies
 * this id from the client.
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

$bundled = false;
$photoId = isset($body['photo_id']) && $body['photo_id'] !== '' ? (int) $body['photo_id'] : null;
if ($photoId !== null) {
    try {
        photo_text_bundle_create($photoId, $id, null);
        $bundled = true;
    } catch (Throwable $e) {
        // The quote is already saved — a bad photo_id (already bundled,
        // doesn't exist) degrades to "saved as a standalone entry" rather
        // than losing what was typed. Fail soft, per PLAN.md's conventions.
        error_log('quotes: bundling failed: ' . $e->getMessage());
    }
}

json_out(array('id' => $id, 'bundled' => $bundled), 201);
