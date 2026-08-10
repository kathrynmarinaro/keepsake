<?php
/**
 * Phase 7 (PDF export) smoke test: proves lib/pdfexport.php's pure/near-pure
 * pieces (geometry math, missing-file fail-soft, the cover's single-fixed-
 * wrapper HTML shape, text truncation) directly, then generates a real
 * synthetic year through layout_generate(), exports it end to end, and
 * inspects the ACTUAL PDF bytes: starts with %PDF-, has the expected page
 * count (cover + title + every book_pages row), and every page's MediaBox
 * matches the configured trim+bleed size in points — per PLAN.md's own
 * suggested approach ("grep-parsing the PDF's /MediaBox entries directly").
 *
 * Follows tools/verify-layout.php's own style/header conventions, including
 * the same "no bootstrap.php, define what's needed by hand" shape every
 * verify-*.php script uses (no MySQL, no browser in this build environment).
 * NEW HERE: this is the first verify-*.php script that needs UPLOAD_DIR/
 * PUBLIC_DIR (lib/imageproc.php's imageproc_resolve_upload() needs them) and
 * the first that writes a real file to disk — one small fixture JPEG under
 * public/uploads/original/, deleted again at the end, to exercise the
 * "photo actually embeds" path instead of only the "file missing" one every
 * synthetic photo in every earlier phase's tests already covers by never
 * pointing at a real file.
 *
 * WHAT THIS CHECKS:
 *   1. pdf_export_geometry(): trim + 2×bleed math, mm conversion, and the
 *      safety margin measured from the bleed edge.
 *   2. pdf_resolve_photo_file() / pdf_photo_html(): a real fixture file
 *      resolves and embeds as an <img>; a photo row pointing nowhere real
 *      resolves to null and renders a visible placeholder instead — the
 *      fail-soft path PLAN.md's brief asks for.
 *   3. pdf_render_cover_html(): exactly ONE top-level position:fixed wrapper
 *      AND zero nested position:absolute elements, regardless of whether a
 *      cover photo is set — the shape that avoids a confirmed mPDF
 *      phantom-extra-page quirk documented in that function's own header;
 *      an <img> only appears when a cover photo is actually chosen.
 *   4. pdf_clip_text(): the safety-valve truncation, either side of the cap.
 *   5. pdf_export_resolve_layout(): 'no_year_project' for a bogus id,
 *      'no_active_layout' for a real year with no layout ever generated —
 *      never a silent fallback to "newest version".
 *   6. pdf_export_build() end to end on a synthetic year (a birthday
 *      snapshot with a real hero photo, a school_year snapshot with no hero
 *      photo, an event group with a full-page photo, a skip_for_book photo,
 *      a short quote riding a photo page as a text card, a long anecdote on
 *      its own text page, one photo with a real embeddable file, several
 *      with none): the raw bytes start with %PDF-, the page count is
 *      exactly 2 + count(book_pages), and every /MediaBox in the file
 *      matches the configured page size in points.
 *   7. The same, on a year whose active layout has zero book_pages (brief/
 *      PLAN.md's "a year with zero pages" fail-soft case) — cover + title
 *      only, still a valid two-page PDF.
 *   8. A year with a cover photo chosen vs. one with none: both export
 *      cleanly; only the first page's rendering path takes the "real photo"
 *      branch (checked structurally, not by decompressing PDF content
 *      streams — see the header comment above pdf_render_cover_html() check
 *      for why the pure-HTML checks above are what actually prove this,
 *      not this end-to-end pass).
 *
 * Usage:
 *   php tools/verify-export.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This app's first verify-*.php script that needs these — see this file's
// header. Same values lib/bootstrap.php would define from a real deploy;
// UPLOAD_DIR/PUBLIC_DIR point at the REAL repo public/uploads directory
// (gitignored contents) so the one fixture file this script writes is a
// real file mPDF can actually open, not a path inside a temp sandbox.
define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_DIR', APP_ROOT . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

/* Same test-only cfg() shim every verify-*.php script carries — real
 * config.php doesn't exist in this build environment, config.example.php is
 * exactly the tunables (including Phase 7's new 'export' block) a real
 * config.php would carry. */
if (!function_exists('cfg')) {
    function cfg(string $path, $default = null)
    {
        $node = $GLOBALS['config'] ?? array();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

/* NOT requiring vendor/autoload.php here, deliberately. It used to, and that
 * hid a real failure for the entire life of the feature: nothing in the app
 * loaded the autoloader, so PDF export threw "Class not found" on the server
 * while this file passed. lib/pdfexport.php now loads it, and this test proves
 * that by not doing it first. */
require __DIR__ . '/../lib/imageproc.php';
require __DIR__ . '/../lib/grouping.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/pdfexport.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

echo "Loading schema.sql into an in-memory SQLite database...\n";
$pdo = harness_pdo();
echo "Schema applied cleanly.\n\n";

/* ============================================================= fixture === */

/* One real, tiny JPEG on disk — the "a photo actually embeds" case. Every
 * OTHER synthetic photo in this script (like every earlier phase's tests)
 * points at a path that was never written, which is exactly what exercises
 * the missing-file fail-soft path without any extra setup. */
$fixtureDir  = imageproc_ensure_dir('original');
$fixtureName = 'verify-export-fixture.jpg';
$fixtureAbs  = $fixtureDir . '/' . $fixtureName;
$fixtureRel  = 'uploads/original/' . $fixtureName;

$im = imagecreatetruecolor(800, 600);
imagefill($im, 0, 0, imagecolorallocate($im, 120, 150, 200));
imagejpeg($im, $fixtureAbs, 85);
imagedestroy($im);

register_shutdown_function(static function () use ($fixtureAbs): void {
    if (is_file($fixtureAbs)) {
        unlink($fixtureAbs);
    }
});

check('fixture JPEG was actually written to disk', is_file($fixtureAbs));

/* ======================================================== pure: geometry == */

echo "pdf_export_geometry(): trim + bleed math...\n";
$geo = pdf_export_geometry();
check('trim size comes straight from config', $geo['trim_width_in'] === 8.5 && $geo['trim_height_in'] === 8.5);
check('bleed comes straight from config', $geo['bleed_in'] === 0.125);
check('safety margin comes straight from config', $geo['safety_margin_in'] === 0.5);
check('page size is trim + 2×bleed on each dimension', abs($geo['page_width_in'] - 8.75) < 0.0001 && abs($geo['page_height_in'] - 8.75) < 0.0001);
check('page size in mm is the inch value × 25.4', abs($geo['page_width_mm'] - (8.75 * 25.4)) < 0.001);
check('content margin is (bleed + safety) in mm, measured from the bleed edge', abs($geo['content_margin_mm'] - ((0.125 + 0.5) * 25.4)) < 0.001);

/* Expected PDF page size in POINTS — what tools/verify-export.php actually
 * greps for below, since that's what ends up in the file's own /MediaBox
 * entries (72pt/inch, mPDF's PDF output unit). */
$expectedPt = $geo['page_width_in'] * 72.0;
check('8.5in trim + 0.125in bleed on each edge is 630pt at the defaults', abs($expectedPt - 630.0) < 0.01);

/* ===================================================== pure: text clip === */

echo "\npdf_clip_text(): the safety valve...\n";
check('short text is untouched', pdf_clip_text('hello') === 'hello');
$long = str_repeat('x', PDF_EXPORT_MAX_TEXT_CHARS + 500);
$clipped = pdf_clip_text($long);
check('oversized text is clipped to the cap plus an ellipsis char', mb_strlen($clipped) === PDF_EXPORT_MAX_TEXT_CHARS);
check('...and ends with the ellipsis marker', mb_substr($clipped, -1) === '…');
check('text exactly at the cap is left alone', mb_strlen(pdf_clip_text(str_repeat('y', PDF_EXPORT_MAX_TEXT_CHARS))) === PDF_EXPORT_MAX_TEXT_CHARS);

/* ================================================ pure: photo resolution == */

echo "\npdf_resolve_photo_file() / pdf_photo_html(): fail-soft on a missing file...\n";

$realPhoto = array('original_path' => $fixtureRel);
$fakePhoto = array('original_path' => 'uploads/original/does-not-exist-anywhere.jpg');

$resolvedReal = pdf_resolve_photo_file($realPhoto);
check('a real on-disk file resolves to an absolute path', $resolvedReal !== null && is_file((string) $resolvedReal));
check('...and that path IS the fixture file', $resolvedReal === realpath($fixtureAbs));

$resolvedFake = pdf_resolve_photo_file($fakePhoto);
check('a photo row pointing nowhere real resolves to null, not an error', $resolvedFake === null);

$htmlReal = pdf_photo_html($realPhoto, 'width:100%;', 'placeholder-style');
check('a resolvable photo renders as a real <img> tag', str_contains($htmlReal, '<img src='));
check('...pointing at the actual fixture path', str_contains($htmlReal, pdf_esc((string) $resolvedReal)));

$htmlFake = pdf_photo_html($fakePhoto, 'width:100%;', 'placeholder-style');
check('an unresolvable photo renders a visible placeholder, not an <img>', !str_contains($htmlFake, '<img') && str_contains($htmlFake, 'Photo not found on disk'));

/* ==================================================== pure: cover shape == */

echo "\npdf_render_cover_html(): the single-fixed-wrapper, zero-nested-absolute shape...\n";

$fakeProject = array('year' => 2024, 'subtitle' => 'A year of firsts');

$coverWithPhoto = pdf_render_cover_html($geo, $fakeProject, $realPhoto);
check(
    'a cover with a photo has EXACTLY ONE top-level position:fixed wrapper (part of avoiding the mPDF phantom-page quirk)',
    substr_count($coverWithPhoto, 'position:fixed') === 1
);
check(
    '...and NO nested position:absolute anywhere inside it (the actual trigger — see pdf_render_cover_html()\'s header)',
    !str_contains($coverWithPhoto, 'position:absolute')
);
check('...and embeds the cover photo as a real <img>', str_contains($coverWithPhoto, '<img src='));
check('...carrying the year', str_contains($coverWithPhoto, '2024'));
check('...and the subtitle', str_contains($coverWithPhoto, 'A year of firsts'));

$coverNoPhoto = pdf_render_cover_html($geo, $fakeProject, null);
check(
    'a cover with NO photo chosen still has exactly one fixed wrapper — fail soft, not a crash',
    substr_count($coverNoPhoto, 'position:fixed') === 1
);
check('...and still no nested position:absolute', !str_contains($coverNoPhoto, 'position:absolute'));
check('...but no <img> at all (placeholder background instead)', !str_contains($coverNoPhoto, '<img'));
check('...and still carries the year, so the page is not blank', str_contains($coverNoPhoto, '2024'));

$title = pdf_render_title_html($fakeProject);
check('the title page carries the year', str_contains($title, '2024'));
check('...and the subtitle', str_contains($title, 'A year of firsts'));

/* ============================================== pure: layout resolution == */

echo "\npdf_export_resolve_layout(): which book_layouts.id gets read...\n";

$threwNoProject = false;
try {
    pdf_export_resolve_layout(999999);
} catch (RuntimeException $e) {
    $threwNoProject = $e->getMessage() === 'no_year_project';
}
check('a bogus year_project_id throws no_year_project', $threwNoProject);

$bare = year_project_get_or_create('2022-01-01');
$threwNoLayout = false;
try {
    pdf_export_resolve_layout($bare);
} catch (RuntimeException $e) {
    $threwNoLayout = $e->getMessage() === 'no_active_layout';
}
check('a real year with no layout ever generated throws no_active_layout, not a silent newest-version fallback', $threwNoLayout);

/* ==================================================== a synthetic year === */

echo "\nBuilding a synthetic 2024 (both snapshot templates, event group, full-page/skip flags, text-card and full-text quotes, real + missing photo files)...\n";

/** Same shape as tools/verify-layout.php's make_photo(), plus an optional
 *  real file when $useFixture is true. */
function make_photo(string $capturedAt, string $shape = 'landscape', array $flags = array(), bool $useFixture = false): int
{
    global $fixtureRel;
    $dims = array(
        'landscape' => array(1200, 800),
        'portrait'  => array(800, 1200),
        'square'    => array(1000, 1000),
    );
    [$w, $h] = $dims[$shape];

    $id = photo_create(array(
        'original_path' => $useFixture ? $fixtureRel : 'uploads/original/test.jpg',
        'thumb_path'    => 'uploads/thumb/test.webp',
        'width'         => $w,
        'height'        => $h,
        'captured_at'   => $capturedAt,
        'gps_lat'       => null,
        'gps_lon'       => null,
    ));

    if ($flags !== array()) {
        photo_update($id, $flags);
    }
    return $id;
}

$yp = year_project_get_or_create('2024-01-01');

$heroPhoto = make_photo('2024-03-15 10:00:00', 'portrait', array(), true); // real file
snapshot_create(array(
    'type' => 'birthday', 'entry_date' => '2024-03-15', 'hero_photo_id' => $heroPhoto,
    'age' => 7, 'height' => '4\'0"', 'notes' => 'Cake in the backyard.',
));
snapshot_create(array(
    'type' => 'school_year', 'entry_date' => '2024-09-02',
    'grade' => '2nd', 'school' => 'Maple Street Elementary', 'teacher' => 'Ms. Rivera',
    'favorite_color' => 'green', 'dream_job' => 'veterinarian', 'favorite_class' => 'art',
    // deliberately NO hero_photo_id — the "No hero photo" placeholder path.
));

$beach = event_group_create(array(
    'year_project_id' => $yp, 'name' => 'Jul 4-6 . Myrtle Beach',
    'start_date' => '2024-07-04', 'end_date' => '2024-07-06', 'is_manual_name' => false,
));
$beachPhotos = array(
    make_photo('2024-07-04 09:00:00', 'landscape'),
    make_photo('2024-07-04 09:15:00', 'landscape', array(), true), // real file, with a caption below
    make_photo('2024-07-04 09:30:00', 'portrait'),
    make_photo('2024-07-04 09:45:00', 'portrait'),
);
$fullPagePhoto = make_photo('2024-07-05 15:00:00', 'portrait', array('full_page' => true));
$skippedPhoto  = make_photo('2024-07-05 12:50:00', 'landscape', array('skip_for_book' => true));
foreach (array_merge($beachPhotos, array($fullPagePhoto, $skippedPhoto)) as $photoId) {
    photo_update($photoId, array('event_group_id' => $beach));
}
photo_update($beachPhotos[1], array('caption' => 'The ocean was so loud that morning.'));

quote_create(array('quote_text' => 'The ocean is so loud!', 'who_said_it' => 'Emma', 'entry_date' => '2024-07-04'));
$longText = str_repeat('We drove home the long way and she narrated every single field. ', 4);
anecdote_create(array('anecdote_text' => $longText, 'entry_date' => '2024-07-06'));

echo "\nlayout_generate(): version 1 of 2024's book...\n";
$run = layout_generate($yp);
check('the synthetic year generated at least one page', $run['pages'] > 0);
check('it became the year\'s active layout automatically', (int) year_project_get($yp)['active_book_layout_id'] === $run['layout_id']);

year_project_update_subtitle($yp, 'The year we went to the beach');
year_project_set_cover_photo($yp, $heroPhoto);

$expectedPages = 2 + count(book_pages_for_layout($run['layout_id']));
$photoPageCount = count(array_filter(
    book_pages_for_layout($run['layout_id']),
    static fn(array $p): bool => $p['page_type'] === 'photos' && $p['slots'] !== array()
));

/** Extract every distinct /MediaBox entry and every /Type /Page (not
 *  /Pages) object from raw PDF bytes — the crude-but-works technique
 *  PLAN.md's own Phase 7 section suggests. */
function pdf_media_boxes(string $bytes): array
{
    preg_match_all('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $bytes, $m, PREG_SET_ORDER);
    $boxes = array();
    foreach ($m as $match) {
        $boxes[] = array((float) $match[3], (float) $match[4]);
    }
    return $boxes;
}

function pdf_page_object_count(string $bytes): int
{
    return preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $bytes);
}

echo "\npdf_resolve_crop_rect(): only cut a photo that actually needs cutting...\n";

/* The regression this pins cost ~50 seconds on a 123-photo book and failed the
 * export outright on shared hosting. Every photo was being sent through a
 * decode and re-encode of a 12-megapixel JPEG to apply a crop rect covering the
 * whole frame — real work, zero effect, plus a needless re-compression of the
 * original. Nothing noticed, because every existing assertion here asked
 * whether a PDF came out, not what it cost to make one. */
$portrait = array('width' => 3024, 'height' => 4032, 'crop_x' => null, 'crop_w' => null,
                  'crop_y' => null, 'crop_h' => null);

check('a photo in a box of its own shape is not cropped at all',
    pdf_resolve_crop_rect($portrait, 75.0, 100.0) === null);

check('...and the same holds for a landscape',
    pdf_resolve_crop_rect(array('width' => 4032, 'height' => 3024, 'crop_x' => null,
        'crop_w' => null, 'crop_y' => null, 'crop_h' => null), 100.0, 75.0) === null);

/* The one case where a crop IS real now: the composer matched an odd-ratio
 * photo to its same-shape neighbours, so its box is the group's shape. */
$odd = pdf_resolve_crop_rect(array('width' => 1080, 'height' => 1920, 'crop_x' => null,
    'crop_w' => null, 'crop_y' => null, 'crop_h' => null), 75.0, 100.0);
check('a 9:16 photo matched into a 3:4 box does get a real crop',
    $odd !== null && $odd['h'] < 0.99);
check('...centred, so the crop takes equal bites top and bottom',
    $odd !== null && abs($odd['y'] - (1.0 - $odd['h']) / 2.0) < 1e-9);

/* A manual override is always honoured, whatever the box. */
$manual = pdf_resolve_crop_rect(array('width' => 3024, 'height' => 4032, 'crop_x' => 0.1,
    'crop_y' => 0.2, 'crop_w' => 0.5, 'crop_h' => 0.5), 75.0, 100.0);
check('a manual crop is never discarded as a no-op',
    $manual !== null && abs($manual['x'] - 0.1) < 1e-9 && abs($manual['w'] - 0.5) < 1e-9);

/* Fail soft, unchanged by any of this: with no stored dimensions there is
 * nothing to compute a crop from, so the original is embedded untouched. */
check('a photo with no dimensions is embedded untouched',
    pdf_resolve_crop_rect(array('width' => 0, 'height' => 0, 'crop_x' => null,
        'crop_w' => null, 'crop_y' => null, 'crop_h' => null), 75.0, 100.0) === null);

echo "\npdf_export_build(): the real PDF, end to end...\n";
$export = pdf_export_build($yp);

check('the bytes are actually a PDF', str_starts_with($export['bytes'], '%PDF-'));
check('the filename carries the year', $export['filename'] === 'Keepsake-2024.pdf');
check('reported page_count is cover + title + every book_pages row', $export['page_count'] === $expectedPages);

$pageObjCount = pdf_page_object_count($export['bytes']);
check('the actual PDF has exactly that many page objects', $pageObjCount === $expectedPages);

$boxes = pdf_media_boxes($export['bytes']);
check('at least one MediaBox was found', count($boxes) > 0);
$allMatch = true;
foreach ($boxes as $box) {
    if (abs($box[0] - $expectedPt) > 0.01 || abs($box[1] - $expectedPt) > 0.01) {
        $allMatch = false;
    }
}
check('every MediaBox in the file matches the configured trim+bleed size in points', $allMatch);

/* The photos actually made it in.
 *
 * Added after a real miss: pdf_render_page_html() was handed a template choice
 * that was never declared as a parameter, so every photo page silently took the
 * "no template fits" branch and exported as a line of grey text. Every check
 * above still passed — the file was a PDF, the page count was right, the trim
 * was right — because none of them looked at whether a page had a PHOTO on it.
 * A book of empty pages is the most expensive way for this exporter to fail and
 * was, until now, the one thing it could do unnoticed. */
$imageCount = preg_match_all('/\/Subtype\s*\/Image/', $export['bytes']);
check('the PDF embeds image data at all (got ' . $imageCount . ' image objects for '
    . $photoPageCount . ' photo pages)', $imageCount >= 1);
/* Not one image PER page: every photo in this fixture is the same JPEG, and
 * mPDF stores identical images once and places them repeatedly, so a per-page
 * count would be testing the deduplicator rather than the exporter. What
 * actually catches the failure is the two placeholders below — both of them
 * mean "a page came out with no photograph on it", which is the shape the bug
 * took and the shape any repeat of it would take. */
check('no page fell back to the no-template placeholder',
    strpos($export['bytes'], 'no template fits') === false);
check('no page fell back to the missing-file placeholder',
    strpos($export['bytes'], 'Photo not found') === false);

/* ================================================ fail soft: zero pages == */

echo "\nFail-soft: a year with zero book_pages still exports (cover + title only)...\n";

$emptyYp = year_project_get_or_create('2019-01-01');
$emptyRun = layout_generate($emptyYp);
check('the empty year generated a layout with zero pages', $emptyRun['pages'] === 0);
check('...and became active automatically', (int) year_project_get($emptyYp)['active_book_layout_id'] === $emptyRun['layout_id']);

$emptyExport = pdf_export_build($emptyYp);
check('an empty year still produces a real PDF', str_starts_with($emptyExport['bytes'], '%PDF-'));
check('...with exactly 2 pages: cover + title, nothing else', $emptyExport['page_count'] === 2);
check('...and the actual byte count agrees', pdf_page_object_count($emptyExport['bytes']) === 2);

$emptyBoxes = pdf_media_boxes($emptyExport['bytes']);
$emptyAllMatch = true;
foreach ($emptyBoxes as $box) {
    if (abs($box[0] - $expectedPt) > 0.01 || abs($box[1] - $expectedPt) > 0.01) {
        $emptyAllMatch = false;
    }
}
check('...and both pages are the correct trim+bleed size', $emptyAllMatch);

/* No cover photo chosen for the empty year — fail soft, not a crash. */
check('the empty year has no cover photo set (default state)', year_project_get($emptyYp)['cover_photo_id'] === null);

/* =============================================================== result === */

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) FAILED.\n";
    exit(1);
}
echo "All checks passed.\n";
