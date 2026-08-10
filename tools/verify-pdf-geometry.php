<?php
/**
 * Does the PDF put each photo exactly where the composer said, in millimetres?
 *
 * This file exists because its predecessor did not ask that question. It
 * checked the millimetre sizes in the HTML the exporter emitted, which were
 * correct — and then mPDF ignored them. Its table engine stretches a
 * `width:100%` table to fill its container and treats explicit column widths as
 * ratios, so a photo asked to be 251pt wide came out 250pt (a coincidence) and
 * one asked to be 335pt tall came out 121pt. Kathryn's first successful export
 * had every page squashed into the top-left corner and I had a green test suite
 * saying the geometry was right.
 *
 * So the exporter now places photos with mPDF's coordinate API, which is exact,
 * and this checks THAT — by handing pdf_draw_photos_page() an Mpdf that records
 * what it was asked to draw instead of drawing it. No PDF is produced and no
 * image is read; the question is only whether the numbers reaching the drawing
 * API are the numbers the composer solved.
 *
 * Usage: php tools/verify-pdf-geometry.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

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
$GLOBALS['config'] = require $root . '/config.example.php';

define('APP_ROOT', $root);
define('PUBLIC_DIR', $root . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');

require $root . '/lib/imageproc.php';
require $root . '/lib/layout.php';
require $root . '/lib/layout_render.php';
require $root . '/vendor/autoload.php';
require $root . '/lib/pdfexport.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

/**
 * An Mpdf that writes nothing down and remembers everything it was asked to do.
 *
 * Subclassed rather than faked with a plain object because
 * pdf_draw_photos_page() type-hints the real class — and that hint is worth
 * keeping, so the test bends instead. Built without the constructor, since
 * nothing here needs a real document, fonts or a temp directory.
 */
final class RecordingMpdf extends \Mpdf\Mpdf
{
    /* Named 'drawn'/'placed' rather than the obvious 'images': Mpdf already
     * declares a typed $images property, and redeclaring it is a fatal. */
    /** @var list<array{x:float,y:float,w:float,h:float,file:string}> */
    public array $drawn = array();
    /** @var list<array{x:float,y:float,w:float,h:float,html:string}> */
    public array $placed = array();

    public function __construct() {}   // deliberately does not call parent

    public function Image(
        $file, $x = 0, $y = 0, $w = 0, $h = 0, $type = '', $link = '',
        $paint = true, $constrain = true, $watermark = false,
        $shownoimg = true, $allowvector = true
    ) {
        $this->drawn[] = array('x' => (float) $x, 'y' => (float) $y,
            'w' => (float) $w, 'h' => (float) $h, 'file' => (string) $file);
        return array();
    }

    public function WriteFixedPosHTML($html, $x, $y, $w, $h, $overflow = 'visible', $bounding = array())
    {
        $this->placed[] = array('x' => (float) $x, 'y' => (float) $y,
            'w' => (float) $w, 'h' => (float) $h, 'html' => (string) $html);
    }

    public function WriteHTML($html, $mode = 0, $init = true, $close = true) {}
}

$geo      = pdf_export_geometry();
$originMm = (float) $geo['content_margin_mm'];
$boxMm    = (float) $geo['content_width_mm'];

/* Slots that resolve to no file on disk, so nothing is decoded or cropped —
 * the placement maths is what is under test, not imageproc. */
function slot_for(int $id, int $w, int $h): array
{
    return array(
        'photo_id' => $id, 'quote_id' => null, 'anecdote_id' => null,
        'width' => $w, 'height' => $h, 'caption' => null,
        'original_path' => 'uploads/original/does-not-exist-' . $id . '.jpg',
        'thumb_path'    => 'uploads/thumb/does-not-exist-' . $id . '.jpg',
        'crop_x' => null, 'crop_y' => null, 'crop_w' => null, 'crop_h' => null,
    );
}

$RATIOS = array('P' => array(array(3024, 4032), array(1080, 1920)),
                'L' => array(array(4032, 3024), array(1920, 1080)));

foreach (compose_templates() as $name => $tpl) {
    $slots = array();
    foreach ($tpl['slots'] as $i => $shape) {
        list($w, $h) = $RATIOS[$shape][$i % count($RATIOS[$shape])];
        $slots[] = slot_for($i + 1, $w, $h);
    }

    $occ    = compose_occupants($slots);
    $cands  = compose_candidates($occ, array());
    if ($cands === array()) { check("$name: has a template", false); continue; }

    /* Force THIS template rather than whichever the rotation would pick, so the
     * check is about placement and not about selection. */
    $choice = null;
    foreach ($cands as $c) { if ($c['name'] === $name) { $choice = $c; break; } }
    if ($choice === null) { check("$name: accepts its own slots", false); continue; }

    $expected = compose_solve($tpl, compose_bind($occ, $tpl, $choice['order']));

    $mpdf = new RecordingMpdf();
    pdf_draw_photos_page($mpdf, array('slots' => $slots, 'page_type' => 'photos'), $geo, $choice, '');

    $drawn = array_merge($mpdf->drawn, $mpdf->placed);
    $checks++;

    if (count($drawn) !== count($expected)) {
        check("$name: draws one box per photo (got " . count($drawn) . ' of ' . count($expected) . ')', false);
        continue;
    }

    /* Placement is in absolute page millimetres; the solver works in percent of
     * the content box. This conversion is the thing that used to be wrong. */
    $wrong = array();
    foreach ($expected as $k => $rect) {
        $wantX = $originMm + $rect['x'] / 100.0 * $boxMm;
        $wantY = $originMm + $rect['y'] / 100.0 * $boxMm;
        $wantW = $rect['w'] / 100.0 * $boxMm;
        $wantH = $rect['h'] / 100.0 * $boxMm;
        $got   = $drawn[$k];

        foreach (array('x' => $wantX, 'y' => $wantY, 'w' => $wantW, 'h' => $wantH) as $axis => $want) {
            if (abs($got[$axis] - $want) > 0.01) {
                $wrong[] = sprintf('slot %d %s: drew %.2fmm, solved %.2fmm', $k, $axis, $got[$axis], $want);
            }
        }
    }
    check("$name: every photo drawn at its solved millimetres", $wrong === array());
    foreach (array_slice($wrong, 0, 4) as $w) { fwrite(STDERR, "       $w\n"); }

    /* Nothing may sit outside the safety margin — that is content the printer
     * may trim off. */
    $checks++;
    $escaped = 0;
    foreach ($drawn as $d) {
        if ($d['x'] < $originMm - 0.01 || $d['y'] < $originMm - 0.01
            || $d['x'] + $d['w'] > $originMm + $boxMm + 0.01
            || $d['y'] + $d['h'] > $originMm + $boxMm + 0.01) {
            $escaped++;
        }
    }
    check("$name: nothing crosses the safety margin", $escaped === 0);
}

/* The caption sits below the photos, inside the page, and does not overlap
 * them — the property the preview and the print are supposed to share. */
$tpl    = compose_templates()['quad-PPPP'];
$slots  = array(slot_for(1, 3024, 4032), slot_for(2, 3024, 4032),
                slot_for(3, 3024, 4032), slot_for(4, 3024, 4032));
$occ    = compose_occupants($slots);
$choice = compose_candidates($occ, array())[0];

$mpdf = new RecordingMpdf();
pdf_draw_photos_page($mpdf, array('slots' => $slots, 'page_type' => 'photos'), $geo, $choice,
    'A caption that runs long enough to be worth placing carefully');

/* The placeholders for the missing files also go through WriteFixedPosHTML, so
 * the caption is found by its text rather than by being the only one. */
$captions = array_values(array_filter(
    $mpdf->placed,
    static fn(array $p): bool => strpos($p['html'], 'worth placing carefully') !== false
));

$checks++;
check('a captioned page draws exactly one caption', count($captions) === 1);
if ($captions !== array()) {
    $cap    = $captions[0];
    $lowest = 0.0;
    foreach (array_merge($mpdf->drawn, $mpdf->placed) as $img) {
        if (strpos($img['html'] ?? '', 'worth placing carefully') !== false) { continue; }
        $lowest = max($lowest, $img['y'] + $img['h']);
    }
    check('the caption sits below every photo', $cap['y'] >= $lowest - 0.01);
    check('...and stays on the page', $cap['y'] + $cap['h'] <= $originMm + $boxMm + 0.01);
}

/* A page with nothing on it must not throw, and must not draw. */
$mpdf = new RecordingMpdf();
pdf_draw_photos_page($mpdf, array('slots' => array(), 'page_type' => 'photos'), $geo, null, '');
$checks++;
check('an empty page draws no photos', $mpdf->drawn === array());

printf("\n%d page drawings checked\n", $checks);
if ($failures > 0) {
    printf("FAILED (%d)\n", $failures);
    exit(1);
}
print "OK — the PDF draws the solved layout, to the hundredth of a millimetre\n";
