<?php
/**
 * Page-caption behaviour: the derived line, the rewrite, and the difference
 * between clearing a rewrite and saving an empty one.
 *
 * That last distinction is the whole reason this file exists. "Clear the
 * rewrite and go back to what the photos say" and "print nothing on this page"
 * look identical in a text box and are opposite intentions, so they are easy to
 * collapse into one by accident in six months. A test is cheaper than
 * rediscovering it.
 *
 * Runs against the harness's in-memory SQLite copy of schema.sql — no MySQL in
 * the build environment, same caveat as every other verify-* script here.
 *
 * Usage: php tools/verify-caption.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

/* db() reads its settings from the global config even when the harness has
 * already handed it a PDO, so the example config stands in — same as every
 * other verify-* script that touches the repo layer. */
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

harness_pdo();   // loads schema.sql and points db() at it

q('INSERT INTO year_projects (year, subtitle) VALUES (?, ?)', array(2025, 'Test Year'));
$yearId = (int) db()->lastInsertId();

$layoutId = book_layout_create($yearId);

q('INSERT INTO book_pages (book_layout_id, page_number, page_type) VALUES (?, ?, ?)',
    array($layoutId, 1, 'photos'));
$pageId = (int) db()->lastInsertId();

$photoIds = array();
foreach (array('First caption', '', 'Third caption') as $i => $caption) {
    q('INSERT INTO photos (year_project_id, original_path, thumb_path, caption, captured_at)
       VALUES (?, ?, ?, ?, ?)',
        array($yearId, "o$i.jpg", "t$i.jpg", $caption, '2025-06-01 12:00:00'));
    $photoIds[] = (int) db()->lastInsertId();
}
foreach ($photoIds as $i => $photoId) {
    q('INSERT INTO book_page_photos (book_page_id, slot_number, photo_id) VALUES (?, ?, ?)',
        array($pageId, $i + 1, $photoId));
}

$page  = book_page_get($pageId);
$slots = book_page_slots($pageId);

/* Derived: the photos' own captions, in slot order, with the empty one skipped
 * rather than leaving a stray separator behind. */
check('derived line joins the photos captions in slot order',
    book_page_caption($page, $slots) === 'First caption · Third caption');

check('a page starts with no rewrite', $page['caption_override'] === null);

/* A rewrite wins over the derived line. */
check('set_caption succeeds', book_page_set_caption($pageId, '  A rewritten line  '));
$page = book_page_get($pageId);
check('rewrite is trimmed on the way in', $page['caption_override'] === 'A rewritten line');
check('rewrite replaces the derived line',
    book_page_caption($page, book_page_slots($pageId)) === 'A rewritten line');

/* Editing a photo's caption must NOT leak through a page that has been
 * rewritten — the rewrite is frozen text, that is what it is for. */
q('UPDATE photos SET caption = ? WHERE id = ?', array('Changed later', $photoIds[0]));
check('a later photo-caption edit does not disturb a rewritten page',
    book_page_caption(book_page_get($pageId), book_page_slots($pageId)) === 'A rewritten line');

/* Empty string is a deliberate blank page and STICKS. */
check('set_caption accepts an empty rewrite', book_page_set_caption($pageId, ''));
$page = book_page_get($pageId);
check('an empty rewrite is stored, not treated as null', $page['caption_override'] === '');
check('an empty rewrite prints nothing',
    book_page_caption($page, book_page_slots($pageId)) === '');

/* Null CLEARS, and the derived line comes back — including the edit made above,
 * which proves the derivation is live rather than cached anywhere. */
check('set_caption accepts null', book_page_set_caption($pageId, null));
$page = book_page_get($pageId);
check('null clears the rewrite', $page['caption_override'] === null);
check('clearing restores the derived line, including later photo edits',
    book_page_caption($page, book_page_slots($pageId)) === 'Changed later · Third caption');

/* A page with no captioned photos and no rewrite prints nothing, so a caller
 * only ever has to test one thing. */
q('UPDATE photos SET caption = ? WHERE year_project_id = ?', array('', $yearId));
check('a page with nothing to say returns an empty string',
    book_page_caption(book_page_get($pageId), book_page_slots($pageId)) === '');

check('setting a caption on a missing page fails cleanly',
    book_page_set_caption(999999, 'nope') === false);

print "\n";
if ($failures > 0) {
    printf("FAILED (%d)\n", $failures);
    exit(1);
}
print "ALL CHECKS PASSED\n";
