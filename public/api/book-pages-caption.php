<?php
/* POST /api/book-pages-caption.php   { page_id, caption: string | null }
 *
 * Saves Kathryn's rewrite of the line that prints at the foot of a page.
 *
 * The line is normally DERIVED — the captions of the page's photos, joined in
 * slot order (lib/repo.php's book_page_caption()). Captions stay authored per
 * photo, because she will not know which photo lands on which page and a
 * page-level caption box would ask her to describe a page she cannot picture.
 * This endpoint exists for the other half of that: the derived join is a first
 * draft, and a line reads differently under the photos than it does in a
 * caption field, so she asked to be able to rewrite it in place.
 *
 * caption: null CLEARS the rewrite and the derived line comes back. That is a
 * different thing from saving "" — an empty string is a deliberate "print
 * nothing on this page", and it sticks. Both are reachable from the UI, and
 * conflating them would make "I want this page bare" impossible to express.
 *
 * Does NOT touch photos.caption. A rewrite here is a decision about this page
 * only; the same photo may carry its caption on another page or in the year
 * timeline, and one page's edit must not silently rewrite those. See
 * schema.sql's comment on book_pages.caption_override.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('POST');

$body   = json_body();
$pageId = (int) ($body['page_id'] ?? 0);

if ($pageId <= 0 || !array_key_exists('caption', $body)) {
    json_error('bad_request', 400, 'page_id and caption (string or null) are required.');
}

$caption = $body['caption'];
if ($caption !== null && !is_string($caption)) {
    json_error('bad_request', 400, 'caption must be a string or null.');
}

/* A hard ceiling so a paste accident cannot put a novel on a page. Generous
 * next to anything that fits in the white space under the photos — the page
 * itself is the real limit, and it is visible while typing. */
if (is_string($caption) && mb_strlen($caption) > 2000) {
    json_error('too_long', 422, 'That caption is longer than a page can show.');
}

if (!book_page_set_caption($pageId, $caption)) {
    json_error('not_found', 404, 'No such page.');
}

$page  = book_page_get($pageId);
$slots = book_page_slots($pageId);

json_out(array(
    'page_id'    => $pageId,
    /* What the page will actually print now, whether that came from her
     * rewrite or fell back to the photos' own captions — so the caller can
     * redraw from the response rather than guessing which case it hit. */
    'caption'    => book_page_caption($page, $slots),
    'overridden' => $page['caption_override'] !== null,
));
