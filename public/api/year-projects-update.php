<?php
/* POST /api/year-projects-update.php   { id, title?, subtitle?, cover_photo_id? }
 *
 * Brief §4.6: "An optional subtitle field is available for editing during
 * the pre-layout content review step" — the book's subtitle.
 * Wired to public/assets/inline-edit.js on public/review.php (where it was
 * first used, Phase 3) AND on public/layout.php (Phase 6's final-step-
 * before-export screen, brief §5.4) — same field, same endpoint, same
 * tap-to-edit gesture in both places; nothing about subtitle-editing is
 * duplicated for the second screen. An empty string clears it back to no
 * subtitle (the cover then carries the title alone).
 *
 * `title` is the same field one line up: the book's name, tap-to-edit in the
 * same two places, empty string clearing it back to the year (brief §4.6's
 * "Title: defaults to the year", which is still what an untouched book shows).
 * Kathryn asked to be able to rename a book after living with the year for a
 * while.
 *
 * cover_photo_id is Phase 6's own addition: "manually selected by Kathryn
 * (same pattern as snapshot hero photos) — not auto-selected" (brief §4.6).
 * Only public/layout.php has UI for it; the endpoint is the same partial-
 * update shape as every *_update() in lib/repo.php — only keys actually
 * present in the request body are touched, so a subtitle-only save (from
 * review.php) can't accidentally clear the cover photo, and vice versa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
/* For cover_band_metrics() and the trim/bleed numbers it needs — the response
 * carries the cover band's geometry so the layout screen's preview can redraw
 * without owning a copy of the maths. */
require_once __DIR__ . '/../../lib/layout_render.php';
require_once __DIR__ . '/../../lib/pdfexport.php';

require_login_api();
require_same_origin();
require_method('POST');

$body = json_body();

$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_request', 400, 'id is required.');
}

if (year_project_get($id) === null) {
    json_error('not_found', 404);
}

if (array_key_exists('title', $body)) {
    $title = is_string($body['title']) ? $body['title'] : null;
    year_project_update_title($id, $title);
}

if (array_key_exists('subtitle', $body)) {
    $subtitle = is_string($body['subtitle']) ? $body['subtitle'] : null;
    year_project_update_subtitle($id, $subtitle);
}

if (array_key_exists('cover_photo_id', $body)) {
    $raw = $body['cover_photo_id'];
    $photoId = ($raw === null || $raw === '') ? null : (int) $raw;

    // A photo id that doesn't exist (deleted since the picker last loaded,
    // or a tampered request) is silently ignored rather than stored — fail
    // soft, per house style, and the cover simply stays whatever it was.
    if ($photoId !== null && photo_get($photoId) === null) {
        $photoId = false; // sentinel: "don't touch it" below
    }
    if ($photoId !== false) {
        year_project_set_cover_photo($id, $photoId);
    }
}

$project  = year_project_get($id);
$subtitle = trim((string) ($project['subtitle'] ?? ''));

/* The band's geometry travels back with the text, because the cover preview on
 * public/layout.php has to redraw itself the moment either line changes and
 * adding or clearing a subtitle changes the band's HEIGHT, not just its
 * contents. Recomputing that in JavaScript would be a second copy of
 * cover_band_metrics() — the exact duplication that function exists to prevent
 * — so the server sends the answer instead. review.php ignores it. */
$geo  = pdf_export_geometry();
$band = cover_band_metrics($subtitle !== '', (float) $geo['content_margin_mm'] / (float) $geo['page_height_mm']);

json_out(array(
    'id'             => $id,
    'title'          => $project['title'],
    'display_title'  => year_project_title($project),
    'subtitle'       => $project['subtitle'],
    'cover_photo_id' => $project['cover_photo_id'] !== null ? (int) $project['cover_photo_id'] : null,
    'cover_band'     => $band,
));
