<?php
/* Try this page a different way — the "refresh" control on a page's toolbar.
 *
 * Steps ONE page on to the next arrangement its photos allow and saves it.
 * Nothing else in the book moves: every other page keeps the arrangement it
 * already had, which is the whole point of storing them.
 *
 * "I want to be able to cycle through the different versions of the layouts for
 * those types of photos if I don't like the one it landed on." It cycles rather
 * than randomising, so pressing it again always walks you back round to the one
 * you preferred — see layout_cycle_arrangement().
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/layout.php';

require_login_api();
require_same_origin();
require_method('POST');

$body   = json_body();
$pageId = (int) ($body['page_id'] ?? 0);
if ($pageId <= 0) {
    json_error('bad_request', 400, 'page_id is required.');
}

$name = layout_cycle_arrangement($pageId);
/* Fail soft, per house style: a page with one photo, or one no second template
   will draw, is not an error — there is simply nowhere to cycle to, and the UI
   should say so rather than show a failure. */
json_out(array('cycled' => $name !== null, 'template' => $name));
