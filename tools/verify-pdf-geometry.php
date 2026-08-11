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

/** Every decompressed content stream in a PDF, concatenated. */
function gzuncompress_all(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m) === false) {
        return $out;
    }
    foreach ($m[1] as $chunk) {
        $plain = @gzuncompress($chunk);
        if ($plain !== false) { $out .= $plain . "\n"; }
    }
    return $out;
}

$geo = pdf_export_geometry();

/* The solver's percentages are of the TRIM — the page as it will be after the
 * guillotine — not of the safety box inside it. The margin is already in the
 * solve (COMPOSE_FILL); measuring from the safety box charged it twice and made
 * the print emptier than the preview. So the expected millimetres here are
 * derived the same way the exporter derives them, and the safety margin is
 * checked separately below as a CONSEQUENCE rather than as the origin. */
$originMm = (float) $geo['bleed_in'] * 25.4;
$boxMm    = (float) $geo['trim_width_in'] * 25.4;
$tallMm   = (float) $geo['trim_height_in'] * 25.4;
$safeMm   = (float) $geo['content_margin_mm'];

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
     * the trim. This conversion is the thing that used to be wrong — twice. */
    $wrong = array();
    foreach ($expected as $k => $rect) {
        $wantX = $originMm + $rect['x'] / 100.0 * $boxMm;
        $wantY = $originMm + $rect['y'] / 100.0 * $tallMm;
        $wantW = $rect['w'] / 100.0 * $boxMm;
        $wantH = $rect['h'] / 100.0 * $tallMm;
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
     * may trim off. Now that placement measures from the trim, this is no
     * longer true by construction: it holds because the solver's own margin
     * (7.5% of the trim, about 0.64in) is wider than the 0.5in safety inset.
     * Which makes it worth checking against the PAGE, in absolute millimetres —
     * if COMPOSE_FILL is ever loosened past the point where photos would print
     * into the trim zone, this is the test that says so. */
    $checks++;
    $pageW   = (float) $geo['page_width_mm'];
    $pageH   = (float) $geo['page_height_mm'];
    $escaped = 0;
    foreach ($drawn as $d) {
        if ($d['x'] < $safeMm - 0.01 || $d['y'] < $safeMm - 0.01
            || $d['x'] + $d['w'] > $pageW - $safeMm + 0.01
            || $d['y'] + $d['h'] > $pageH - $safeMm + 0.01) {
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

    /* Inside the TRIM, not inside the safety box. The caption is deliberately
     * the closest thing on the page to the edge — it is centred in the white
     * space the photos leave, which is where Kathryn put it — and that white
     * space runs to the trim. It lands about 12mm clear of the cut, so it
     * cannot be guillotined; it is simply nearer the edge than the 0.5in a
     * printer would ask for body text, which is the price of matching the
     * preview exactly. */
    check('...and stays inside the trim', $cap['y'] + $cap['h'] <= $originMm + $tallMm + 0.01);

    $clearMm = ($originMm + $tallMm) - ($cap['y'] + $cap['h']);
    check(sprintf('...clear of the cut line by %.1fmm', $clearMm), $clearMm > 3.0);
}

/* A page with nothing on it must not throw, and must not draw. */
$mpdf = new RecordingMpdf();
pdf_draw_photos_page($mpdf, array('slots' => array(), 'page_type' => 'photos'), $geo, null, '');
$checks++;
check('an empty page draws no photos', $mpdf->drawn === array());

/* ------------------------------------------------------------------ cover */

echo "\nThe cover: full bleed, upright, and a title you can read...\n";

$geoC   = $geo;
$pageMm = (float) $geoC['page_width_mm'];   // $safeMm is set above, alongside the trim

/* A portrait cover photo — the case that printed on its side, because the old
 * cover handed mPDF a raw <img> and never went near imageproc. */
$coverPhoto = array(
    'id' => 1, 'width' => 3024, 'height' => 4032,
    'original_path' => 'uploads/original/nope.jpg', 'thumb_path' => 'uploads/thumb/nope.jpg',
);

$mpdf = new RecordingMpdf();
pdf_draw_cover_page($mpdf, $geoC,
    array('year' => 2025, 'title' => null, 'subtitle' => 'The best year ever!'), $coverPhoto);

$checks++;
/* No file on disk here, so nothing is drawn — but the BAND must still be
 * placed, which is the fail-soft promise. */
check('a cover with an unreadable photo still gets its title band', count($mpdf->placed) >= 1);

$band = end($mpdf->placed);
check('the title band sits inside the safety margin',
    $band['x'] >= $safeMm - 0.01 && $band['y'] >= $safeMm - 0.01
    && $band['x'] + $band['w'] <= $pageMm - $safeMm + 0.01
    && $band['y'] + $band['h'] <= $pageMm - $safeMm + 0.01);
check('the band is at the foot, not the middle', $band['y'] > $pageMm / 2);
check('the band carries the year and the subtitle',
    strpos($band['html'], '2025') !== false && strpos($band['html'], 'best year ever') !== false);

/* --------------------------------------------------- the title she chose */

/* A named book prints its name, and only its name. The year is the DEFAULT, so
 * finding it still on the cover of a renamed book would mean the fallback had
 * been applied on top of her answer rather than instead of it. */
$mpdf = new RecordingMpdf();
pdf_draw_cover_page($mpdf, $geoC,
    array('year' => 2025, 'title' => 'Our Big Year', 'subtitle' => ''), $coverPhoto);
$named = end($mpdf->placed);

$checks++;
check('a renamed book prints its own name on the cover',
    strpos($named['html'], 'Our Big Year') !== false);
check('...and not the year as well', strpos($named['html'], '2025') === false);

/* WITH NO SUBTITLE THE TITLE IS CENTRED IN THE WHITE BOX, which is what Kathryn
 * asked for. mPDF cannot centre vertically inside a fixed-position box, so the
 * exporter spends the band's padding as an explicit top margin — and the check
 * that this is really centring is that the margin plus one line of type plus the
 * margin again is the whole band, with nothing left over. */
$geoH   = (float) $geoC['page_height_mm'];
$solo   = cover_band_metrics(false, $safeMm / $geoH);
$titleH = $solo['title_size'] * COVER_TITLE_LEAD;

$checks++;
check('a title with no subtitle is vertically centred in its band',
    abs(($solo['title_top'] * 2 + $titleH) - $solo['height']) < 1e-9);

/* The margin the exporter actually wrote, not the one it should have. */
if (preg_match('/margin-top:([\d.]+)mm/', $named['html'], $m)) {
    check('...and the PDF is drawn with exactly that margin',
        abs((float) $m[1] - $solo['title_top'] * $geoH) < 0.01);
} else {
    check('...and the PDF is drawn with exactly that margin', false);
}

/* The type size is the shared one, in points. This is the number the preview
 * used to disagree with by nearly double. */
if (preg_match('/font-size:([\d.]+)pt/', $named['html'], $m)) {
    check('the cover title is typeset at the shared size',
        abs((float) $m[1] - pdf_mm_to_pt($solo['title_size'] * $geoH)) < 0.01);
} else {
    check('the cover title is typeset at the shared size', false);
}

/* A subtitle-less cover must not leave a hole where the subtitle would be. */
check('an empty subtitle draws no second line',
    substr_count($named['html'], '<div') === 2);   // the white ground, and the title

/* ------------------------------------- the preview cannot disagree in CSS */

/* The browser gets its cover type sizes inline from cover_band_metrics(), and
 * only its LINE HEIGHTS from the stylesheet — because those are ratios rather
 * than sizes, and mm-per-line is what the band's height is built out of. So the
 * two numbers in styles.css have to be the two constants in PHP, and there must
 * be no font-size beside them: a tuned constant in the stylesheet is exactly how
 * the preview came to be drawing the title at nearly twice what printed. */
$css = (string) file_get_contents($root . '/public/assets/styles.css');
$checks++;

foreach (array(
    '.ks-cover-title' => COVER_TITLE_LEAD,
    '.ks-cover-sub'   => COVER_SUB_LEAD,
) as $selector => $lead) {
    $rule = null;
    if (preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $m)) {
        $rule = $m[1];
    }

    if ($rule === null) {
        check("styles.css still has a $selector rule", false);
        continue;
    }
    check("$selector's line-height matches the PHP constant",
        preg_match('/line-height:\s*([\d.]+)/', $rule, $lh) === 1
        && abs((float) $lh[1] - $lead) < 1e-9);
    check("...and $selector sets no font-size of its own",
        strpos($rule, 'font-size') === false);
}

/* With a real file, the photo must cover the WHOLE physical page — trim plus
 * bleed, all four edges. A cover that stops at the safety margin is what
 * Kathryn's first export did, and it reads as a mistake. */
$realCover = imageproc_export_cache_dir() . '/cover-fixture.jpg';
@mkdir(dirname($realCover), 0700, true);
$im = imagecreatetruecolor(400, 600);
imagefill($im, 0, 0, imagecolorallocate($im, 120, 140, 160));
imagejpeg($im, $realCover, 80);
imagedestroy($im);

$rel = 'uploads/original/cover-fixture.jpg';
@mkdir(UPLOAD_DIR . '/original', 0775, true);
copy($realCover, UPLOAD_DIR . '/original/cover-fixture.jpg');

$mpdf = new RecordingMpdf();
pdf_draw_cover_page(
    $mpdf,
    $geoC,
    array('year' => 2025, 'subtitle' => ''),
    array('id' => 1, 'width' => 400, 'height' => 600,
          'original_path' => $rel, 'thumb_path' => $rel)
);

$checks++;
check('the cover photo is drawn', count($mpdf->drawn) === 1);
if ($mpdf->drawn !== array()) {
    $art = $mpdf->drawn[0];
    check('...bleeding to every edge of the physical page',
        abs($art['x']) < 0.01 && abs($art['y']) < 0.01
        && abs($art['w'] - $pageMm) < 0.01 && abs($art['h'] - (float) $geoC['page_height_mm']) < 0.01);

    /* The file handed to mPDF must be a PREPARED copy, never the original.
     * That is what bakes in the rotation and converts the colour, and drawing
     * the original is exactly the bug being fenced here. */
    check('...from a prepared copy, not the original file',
        $art['file'] !== UPLOAD_DIR . '/original/cover-fixture.jpg');
}

/* A stored crop must win over the centred default, and clearing it must give
 * the default back. This is the whole point of the framing control: once she
 * has decided what the cover shows, nothing recomputes it behind her. */
$centred = pdf_cover_crop_rect(
    array('cover_crop_x' => null, 'cover_crop_w' => null),
    array('width' => 3024, 'height' => 4032),
    1.0
);
$checks++;
check('with no crop set, a tall photo is centred on the square',
    abs($centred['w'] - 1.0) < 1e-9 && $centred['h'] < 1.0
    && abs($centred['y'] - (1.0 - $centred['h']) / 2.0) < 1e-9);

$hers = pdf_cover_crop_rect(
    array('cover_crop_x' => 0.05, 'cover_crop_y' => 0.0, 'cover_crop_w' => 0.6, 'cover_crop_h' => 0.6),
    array('width' => 3024, 'height' => 4032),
    1.0
);
check('her framing is used exactly as stored',
    abs($hers['x'] - 0.05) < 1e-9 && abs($hers['w'] - 0.6) < 1e-9);

/* The band's geometry is shared with the preview, so it is worth pinning that
 * a subtitle makes it taller and that it always clears the safety margin. */
$withSub = cover_band_metrics(true, 0.0714);
$noSub   = cover_band_metrics(false, 0.0714);
$checks++;
check('a subtitle makes the title band taller', $withSub['height'] > $noSub['height']);
check('the band stops short of the page edge by the safety margin',
    abs(($withSub['top'] + $withSub['height']) - (1.0 - 0.0714)) < 1e-9);
check('...and is inset by the same margin on the sides', abs($withSub['inset'] - 0.0714) < 1e-9);

@unlink(UPLOAD_DIR . '/original/cover-fixture.jpg');
@unlink($realCover);
imageproc_prune_export_cache(0);

/* ================================================== the snapshot page ===== */

echo "\nSnapshot page — two-up, one block, centred...\n";

/* WHY THIS IS HERE. The snapshot page used to be an HTML table in document
 * flow, and mPDF will not hold a height for one: on an 8.75in square page a
 * birthday with three sections filled the top 40% and left the rest blank.
 * tools/page-lab.php is what made that visible; this is what stops it coming
 * back. It is now coordinate-drawn like a photos page, so its rectangles can
 * be measured the same way. */

$snapPage = array(
    'page_type'              => 'snapshot',
    'snapshot_title'         => "Emma's 8th Birthday",
    'snapshot_date'          => '2025-04-15',
    'snapshot_hero_photo_id' => null,   // no file on disk: placement, not imageproc
    'snapshot_hero_original' => null,
    'snapshot_sections'      => array(
        array('heading' => 'Age', 'body' => '8'),
        array('heading' => 'Height', 'body' => "3'9\""),
    ),
);

$snapMpdf = new RecordingMpdf();
pdf_draw_snapshot_page($snapMpdf, $snapPage, $geo);

/* With no hero file the empty box and the text column are both WriteFixedPosHTML,
   so there are two placements and no images. */
check('the page draws two panels', count($snapMpdf->placed) === 2, count($snapMpdf->placed) . ' placed');

if (count($snapMpdf->placed) === 2) {
    list($heroBox, $textBox) = $snapMpdf->placed;

    $insetMm = $boxMm * 0.075;
    $expectX = $originMm + $insetMm;
    $expectY = $originMm + $insetMm;
    $expectH = $tallMm - (2 * $insetMm);

    check('the hero starts at the page inset horizontally',
        abs($heroBox['x'] - $expectX) < 0.01,
        sprintf('hero x %.2f, expected %.2f', $heroBox['x'], $expectX));

    /* THE HERO IS THE BOOK'S PORTRAIT SHAPE — COMPOSE_CANON['P'], the same
       ratio every portrait slot the layout engine solves uses. It filled the
       column at first, which made it a 1:2.3 slab taller and narrower than any
       photograph anywhere else in the book: "the image should be the same
       ratio as the other portrait images in the book". */
    $expectHeroH = min($expectH, $heroBox['w'] / COMPOSE_CANON['P']);
    check('the hero is the book\'s portrait ratio',
        abs($heroBox['h'] - $expectHeroH) < 0.01,
        sprintf('%.2fmm, expected %.2fmm', $heroBox['h'], $expectHeroH));
    check('...which is 3:4, like every other portrait slot',
        abs(($heroBox['w'] / $heroBox['h']) - COMPOSE_CANON['P']) < 0.001,
        sprintf('ratio %.4f', $heroBox['w'] / $heroBox['h']));

    /* AND BECAUSE IT IS SHORTER THAN THE COLUMN, IT IS CENTRED IN IT. Holding
       the hero at the top inset left all the slack in one lump at the foot of
       the page, which reads as a page that ran out rather than a composed one.
       Same slack, split in two. */
    $expectHeroY = $expectY + max(0.0, ($expectH - $heroBox['h']) / 2.0);
    check('the hero is centred down the column',
        abs($heroBox['y'] - $expectHeroY) < 0.01,
        sprintf('hero y %.2f, expected %.2f', $heroBox['y'], $expectHeroY));
    check('...with equal slack above and below it',
        abs(($heroBox['y'] - $expectY) - (($expectY + $expectH) - ($heroBox['y'] + $heroBox['h']))) < 0.01);

    /* THE TWO PANELS START ON ONE LINE, always — they are one block, and the
       block is what gets centred. With this short a snapshot the block is the
       hero, so this is also the centred case above. */
    check('the text panel starts on the hero\'s top edge',
        abs($textBox['y'] - $heroBox['y']) < 0.01,
        sprintf('text y %.2f, hero y %.2f', $textBox['y'], $heroBox['y']));

    /* And it keeps everything between there and the foot of the content box,
       which is what a longer snapshot grows down into. */
    check('the text panel runs to the foot of the content box',
        abs(($textBox['y'] + $textBox['h']) - ($expectY + $expectH)) < 0.01,
        sprintf('ends at %.2f, box ends at %.2f', $textBox['y'] + $textBox['h'], $expectY + $expectH));

    /* The CELL inside it is the hero's height, and that is what centres a short
       text against the photo rather than against the panel. */
    check('the text is centred in a cell the hero\'s height',
        str_contains($textBox['html'], 'height:' . round($heroBox['h'], 2) . 'mm')
        && str_contains($textBox['html'], 'vertical-align:middle'));

    check('the hero does not run past the page',
        $heroBox['y'] + $heroBox['h'] <= $originMm + $tallMm + 0.01);

    check('the two panels are side by side, not stacked',
        $textBox['x'] > $heroBox['x'] + $heroBox['w'] - 0.01);

    /* 45/55 of the space left after the gutter. */
    $contentW = $boxMm - (2 * $insetMm);
    check('the split is 45/55 with a gutter between',
        abs($heroBox['w'] - ($contentW - 8.0) * 0.45) < 0.01
        && abs($textBox['w'] - ($contentW - 8.0) * 0.55) < 0.01);

    /* Nothing may cross the trim. */
    $rightEdge = $textBox['x'] + $textBox['w'];
    $lowEdge   = $textBox['y'] + $textBox['h'];
    check('nothing reaches the trim edge',
        $heroBox['x'] >= $originMm - 0.01
        && $rightEdge <= $originMm + $boxMm + 0.01
        && $lowEdge <= $originMm + $tallMm + 0.01);

    /* And it clears the printer's safety margin, as a consequence of the
       inset rather than by being measured from it. */
    check('and clears the safety margin',
        $heroBox['x'] >= $safeMm - 0.01 && $rightEdge <= $originMm + $boxMm - ($safeMm - $originMm) + 0.01);

    /* The text half carries the title and the headings, and NOT a type label. */
    check('the text panel carries the title', str_contains($textBox['html'], '8th Birthday'));
    check('with bold section headings', str_contains($textBox['html'], 'font-weight:bold'));
    check('and no type label on the page',
        !str_contains($textBox['html'], '>Birthday<') && !str_contains($textBox['html'], 'School year'));
}

/* A hero that DOES resolve is drawn as an image filling the same rectangle. */
$withHero = $snapPage;
$withHero['snapshot_hero_photo_id'] = 1;
$withHero['snapshot_hero_original'] = 'uploads/original/does-not-exist.jpg';

$heroMpdf = new RecordingMpdf();
pdf_draw_snapshot_page($heroMpdf, $withHero, $geo);
check('a hero that cannot be resolved still leaves the page whole',
    count($heroMpdf->placed) + count($heroMpdf->drawn) === 2);

/* ---------------------------------------------------------------------------
 * The block rule, on pdf_snapshot_layout() directly — no mPDF, no drawing,
 * just the arithmetic that decides where the pair sits. This is the half of
 * "centred when it fits, top-aligned with the image when it doesn't" that can
 * be checked exactly; the cell above is the other half.
 * ------------------------------------------------------------------------ */

echo "\n...and the block rule that positions the pair\n";

$flat   = pdf_snapshot_layout($geo, 0.0);
$heroHt = $flat['hero_h'];
$boxTop = $flat['box_y'];
$boxBot = $flat['box_y'] + $flat['box_h'];

/* SHORT: the block is the photo, so the photo is centred on the page. */
$short = pdf_snapshot_layout($geo, $heroHt / 2);
check('a short text leaves the hero centred on the page',
    abs(($short['hero_y'] - $boxTop) - ($boxBot - ($short['hero_y'] + $short['hero_h']))) < 0.01,
    sprintf('%.2f above, %.2f below', $short['hero_y'] - $boxTop, $boxBot - ($short['hero_y'] + $short['hero_h'])));
check('...and does not move it as it grows, right up to the hero\'s height',
    abs(pdf_snapshot_layout($geo, $heroHt)['hero_y'] - $short['hero_y']) < 0.01);

/* LONG: the block is the text. Tops align — the thing that was asked for —
   and it is the BLOCK that is centred, so the text still fits. */
$longH = $heroHt * 1.5;
$long  = pdf_snapshot_layout($geo, $longH);

check('a long text is top-aligned with the hero',
    abs($long['text_y'] - $long['hero_y']) < 0.01,
    sprintf('text y %.2f, hero y %.2f', $long['text_y'], $long['hero_y']));
check('...and the pair, not the photo, is what is centred',
    abs(($long['hero_y'] - $boxTop) - ($boxBot - ($long['text_y'] + $longH))) < 0.01,
    sprintf('%.2f above, %.2f below', $long['hero_y'] - $boxTop, $boxBot - ($long['text_y'] + $longH)));
check('...so the text still ends inside the content box',
    $long['text_y'] + $longH <= $boxBot + 0.01,
    sprintf('ends at %.2f, box ends at %.2f', $long['text_y'] + $longH, $boxBot));

/* THE CASE THAT MADE THIS NECESSARY. Leaving the hero centred and hanging the
   text off its top edge is the obvious implementation and it overflows: the
   nine-section snapshot in the page lab ran 19mm past the trim. Same numbers,
   the other way round. */
$naiveTop = $flat['hero_y'];
check('the naive version — hero centred, text hung off it — would have overflowed',
    $naiveTop + $longH > $boxBot,
    sprintf('would end at %.2f, box ends at %.2f', $naiveTop + $longH, $boxBot));

/* And the two rules meet without a step at the crossover. */
$justUnder = pdf_snapshot_layout($geo, $heroHt - 0.01);
$justOver  = pdf_snapshot_layout($geo, $heroHt + 0.01);
check('the two cases meet without a jump',
    abs($justUnder['hero_y'] - $justOver['hero_y']) < 0.02,
    sprintf('%.3f vs %.3f', $justUnder['hero_y'], $justOver['hero_y']));

/* A text taller than the whole page is clamped rather than pushed off the top. */
check('a text taller than the page starts at the top of it, not above it',
    abs(pdf_snapshot_layout($geo, $flat['box_h'] * 3)['text_y'] - $boxTop) < 0.01);

/* ---------------------------------------------------------------------------
 * The printed spacing, read back out of a real PDF.
 *
 * mPDF's WriteFixedPosHTML DROPS margin and padding on block elements, which
 * is not documented anywhere obvious and cost this page its whole vertical
 * rhythm: the 6mm under the date and the 4.5mm between sections were being
 * thrown away in print while the page lab — a browser, which honours them —
 * showed them. Table cell padding survives. This is the guard.
 * ------------------------------------------------------------------------ */

echo "\n...and the spacing that survives into the PDF\n";

$sm = new \Mpdf\Mpdf(array(
    'format'       => array($geo['page_width_mm'], $geo['page_height_mm']),
    'margin_left'  => $geo['content_margin_mm'], 'margin_right'  => $geo['content_margin_mm'],
    'margin_top'   => $geo['content_margin_mm'], 'margin_bottom' => $geo['content_margin_mm'],
    'tempDir'      => '/tmp/keepsake-geometry-mpdf',
));
$sm->AddPage();
pdf_draw_snapshot_page($sm, $snapPage, $geo);

$lines = array();
foreach (explode("\n", (string) gzuncompress_all($sm->Output('', 'S'))) as $line) {
    if (preg_match('/BT\s+([\d.]+)\s+([\d.]+)\s+Td\s+\((.*)\)\s*Tj/', $line, $m) === 1) {
        $lines[] = (float) $geo['page_height_mm'] - ((float) $m[2] * 25.4 / 72);
    }
}
sort($lines);

/* title, date, Age, 8, Height, 3'9" */
check('the snapshot page printed all six lines', count($lines) === 6, count($lines) . ' lines');

if (count($lines) === 6) {
    $headingToBody = $lines[3] - $lines[2];   // no gap between a heading and its body
    $dateToSection = $lines[2] - $lines[1];   // the 6mm gap under the date
    $betweenTwo    = $lines[4] - $lines[3];   // the 4.5mm between sections

    check('the gap under the date survived into print',
        $dateToSection > $headingToBody + 5.0,
        sprintf('%.2fmm vs a plain %.2fmm line', $dateToSection, $headingToBody));
    check('and so did the gap between two sections',
        $betweenTwo > $headingToBody + 4.0,
        sprintf('%.2fmm vs a plain %.2fmm line', $betweenTwo, $headingToBody));
    check('a heading still sits tight against its own body copy',
        $headingToBody < 6.0, sprintf('%.2fmm', $headingToBody));
}

/* ============================================== hanging quotation marks === */

echo "\nQuote pages — the mark hangs, the words line up...\n";

/* WHY THIS IS MEASURED AND NOT EYEBALLED. The first version hung the mark with
 * a negative text-indent, which is a GUESS at how wide a quote mark is: the
 * first word landed somewhere other than where the second line started, and
 * Kathryn spotted it in the page lab. A table cell IS the mark's width, and the
 * only way to know it worked is to read the text positions back out of a real
 * PDF — which is what this does. */

$quoteSlot = array(
    'quote_id' => 1, 'anecdote_id' => null,
    'quote_text' => "When I grow up I want to be a marine biologist and also a person who drives the little truck at the airport, and I want to live next door to you.",
    'anecdote_text' => null,
    'quote_date' => '2025-09-14', 'anecdote_date' => null,
    'who_said_it' => 'Emma',
);

@mkdir('/tmp/keepsake-geometry-mpdf', 0777, true);
$qm = new \Mpdf\Mpdf(array(
    'format'       => array($geo['page_width_mm'], $geo['page_height_mm']),
    'margin_left'  => $geo['content_margin_mm'], 'margin_right'  => $geo['content_margin_mm'],
    'margin_top'   => $geo['content_margin_mm'], 'margin_bottom' => $geo['content_margin_mm'],
    'tempDir'      => '/tmp/keepsake-geometry-mpdf',
));
$qm->AddPage();
$qm->WriteHTML(pdf_wrap_page(pdf_render_page_html(array(
    'page_type' => 'text',
    'slots'     => array($quoteSlot),
)), false));
$quotePdf = $qm->Output('', 'S');

/* Every text-showing operation, as (x, y, text). */
$runs = array();
foreach (explode("\n", (string) gzuncompress_all($quotePdf)) as $line) {
    if (preg_match('/BT\s+([\d.]+)\s+([\d.]+)\s+Td\s+\((.*)\)\s*Tj/', $line, $m) === 1) {
        $runs[] = array('x' => (float) $m[1], 'y' => (float) $m[2], 'raw' => $m[3]);
    }
}

$checks++;
check('the quote page produced text', count($runs) >= 3, count($runs) . ' runs');

if (count($runs) >= 3) {
    /* The mark is its own run, at the smallest x. Everything else — every
       wrapped line AND the attribution — must share one larger x. */
    $xs = array_map(static fn(array $r): float => $r['x'], $runs);
    sort($xs);

    $markX = $xs[0];
    $rest  = array_slice($xs, 1);

    check('the mark hangs left of everything else', $markX < $rest[0] - 0.5,
        sprintf('mark at %.2f, next at %.2f', $markX, $rest[0]));

    check(
        'every other line shares one left edge',
        abs(max($rest) - min($rest)) < 0.5,
        sprintf('spread %.3fpt between %.2f and %.2f', max($rest) - min($rest), min($rest), max($rest))
    );

    /* The attribution is the LAST run down the page, and it has to be on that
       same edge — "the person and date should be left aligned with the words,
       not the quotation mark". */
    usort($runs, static fn(array $a, array $b): int => $b['y'] <=> $a['y']);
    $meta = end($runs);
    check('the attribution sits on the words\' edge, not the mark\'s',
        abs($meta['x'] - min($rest)) < 0.5,
        sprintf('meta at %.2f, words at %.2f', $meta['x'], min($rest)));

    /* Centred in the page rather than pushed down it by a percentage: the
       block's midpoint should be near the middle of the content box. */
    $ys       = array_map(static fn(array $r): float => $r['y'], $runs);
    $midPt    = (max($ys) + min($ys)) / 2.0;
    $pageHPt  = $geo['page_height_mm'] * 72.0 / 25.4;
    check('the block is centred down the page, not top-anchored',
        abs($midPt - $pageHPt / 2.0) < $pageHPt * 0.12,
        sprintf('mid %.1fpt of %.1fpt', $midPt, $pageHPt));
}

printf("\n%d page drawings checked\n", $checks);
if ($failures > 0) {
    printf("FAILED (%d)\n", $failures);
    exit(1);
}
print "OK — the PDF draws the solved layout, to the hundredth of a millimetre\n";
