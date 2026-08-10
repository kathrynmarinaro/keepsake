<?php
/**
 * Does the PDF put a photo where the solver said, in millimetres?
 *
 * The browser and mPDF now draw the same solved layout, but they draw it very
 * differently — the browser positions rectangles absolutely, mPDF cannot do
 * that at all and rebuilds the arrangement as nested tables. Two renderings of
 * one layout is exactly the arrangement that drifted before, so the agreement
 * needs a test rather than a comment.
 *
 * This renders a page's HTML and reads the millimetre sizes back out of it,
 * comparing them against the rectangles compose_solve_tree() produced. It does
 * not run mPDF: mPDF's job is to honour explicit table sizes, and if it stops
 * doing that no arithmetic here would catch it anyway. What can silently go
 * wrong is this file computing a size of its own, and that is what is checked.
 *
 * Usage: php tools/verify-pdf-geometry.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/compose.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
}

/* pdfexport.php pulls in the world — bootstrap, the database, mPDF. Only two
 * pure functions are under test, so they are loaded on their own out of the
 * file's source rather than by requiring it. Brittle if either is renamed,
 * which is why the extraction fails loudly instead of skipping. */
$src = (string) file_get_contents(__DIR__ . '/../lib/pdfexport.php');
foreach (array('pdf_render_solved_node', 'pdf_esc', 'pdf_clip_text', 'pdf_render_photo_cell_sized',
               'pdf_render_text_cell_sized', 'pdf_resolve_crop_rect', 'pdf_snippet',
               'pdf_resolve_photo_file') as $fn) {
    if (strpos($src, 'function ' . $fn . '(') === false) {
        fwrite(STDERR, "pdfexport.php no longer defines $fn — update this test\n");
        exit(2);
    }
}

/* Stubs for everything the cell renderers reach for. A photo that resolves to
 * no file on disk takes pdfexport's own placeholder branch, which still emits
 * the sized cell — the geometry, which is all this file is about. */
function pdf_resolve_photo_file(array $photo): ?string { return null; }
function pdf_esc(?string $raw): string { return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8'); }
function pdf_clip_text(?string $text): string { return (string) $text; }
function pdf_snippet(string $text, int $len = 90): string { return $text; }
function pdf_resolve_crop_rect(array $slot, float $w, float $h): ?array { return null; }
function pdf_render_text_cell_sized(array $slot, float $wMm, float $hMm): string
{
    return '<div style="height:' . round($hMm, 2) . 'mm;">card</div>';
}
function pdf_render_photo_cell_sized(array $slot, float $wMm, float $hMm): string
{
    return '<div class="leaf" data-w="' . round($wMm, 3) . '" data-h="' . round($hMm, 3) . '"></div>';
}

/* The one function actually under test, lifted out of the file. */
$body = null;
if (preg_match('/function pdf_render_solved_node\(.*?\n\}\n/s', $src, $m)) {
    $body = $m[0];
}
if ($body === null) {
    fwrite(STDERR, "could not extract pdf_render_solved_node()\n");
    exit(2);
}
eval($body);

const TRIM_MM = 215.9;   // 8.5in square, the book's trim

$templates = compose_templates();
$RATIOS    = array('P' => array(0.75, 0.5625, 0.681), 'L' => array(4 / 3, 1.778));

foreach ($templates as $name => $tpl) {
    /* One occupant set per template, cycling the awkward ratios so matched
     * groups and mixed rows both get exercised. */
    $occ = array();
    foreach ($tpl['slots'] as $i => $shape) {
        $occ[] = array('shape' => $shape, 'ar' => $RATIOS[$shape][$i % count($RATIOS[$shape])]);
    }

    $tree  = compose_solve_tree($tpl, $occ);
    $leaves = compose_flatten($tree);

    $slots = array();
    $order = array();
    foreach ($tpl['slots'] as $i => $shape) {
        $slots[$i] = array('photo_id' => $i + 1, 'caption' => null);
        $order[$i] = $i;
    }

    $html = pdf_render_solved_node($tree, $slots, $order, TRIM_MM, TRIM_MM);
    $checks++;

    preg_match_all('/data-w="([0-9.]+)" data-h="([0-9.]+)"/', $html, $m, PREG_SET_ORDER);
    if (count($m) !== count($leaves)) {
        check("$name: emits one cell per photo", false);
        continue;
    }

    /* Every leaf's drawn size must be its solved rectangle, converted once. */
    $wrong = array();
    foreach ($leaves as $k => $leaf) {
        $wantW = $leaf['w'] / 100.0 * TRIM_MM;
        $wantH = $leaf['h'] / 100.0 * TRIM_MM;
        if (abs((float) $m[$k][1] - $wantW) > 0.01 || abs((float) $m[$k][2] - $wantH) > 0.01) {
            $wrong[] = sprintf('slot %d drew %smm x %smm, solved %.2f x %.2f',
                $leaf['slot'], $m[$k][1], $m[$k][2], $wantW, $wantH);
        }
    }
    check("$name: every photo drawn at its solved size", $wrong === array());
    foreach ($wrong as $w) { fwrite(STDERR, "       $w\n"); }

    /* Nothing may exceed the trim — a table that overflows silently pushes the
     * page onto a second sheet in mPDF, which is a broken book, not a broken
     * pixel. */
    $checks++;
    $over = false;
    foreach ($leaves as $leaf) {
        if ($leaf['x'] + $leaf['w'] > 100.0001 || $leaf['y'] + $leaf['h'] > 100.0001) { $over = true; }
    }
    check("$name: stays inside the trim", !$over);
}

/* A matched group must come out of the PDF renderer identical too, not merely
 * identical in the solver. */
$tpl  = $templates['quad-PPPP'];
$occ  = array(
    array('shape' => 'P', 'ar' => 0.5625), array('shape' => 'P', 'ar' => 0.75),
    array('shape' => 'P', 'ar' => 0.75),   array('shape' => 'P', 'ar' => 0.752),
);
$tree  = compose_solve_tree($tpl, $occ);
$slots = array();
foreach (array(0, 1, 2, 3) as $i) { $slots[$i] = array('photo_id' => $i + 1, 'caption' => null); }
$html = pdf_render_solved_node($tree, $slots, array(0, 1, 2, 3), TRIM_MM, TRIM_MM);
preg_match_all('/data-w="([0-9.]+)" data-h="([0-9.]+)"/', $html, $m, PREG_SET_ORDER);
$checks++;
$same = true;
foreach ($m as $cell) {
    if ($cell[1] !== $m[0][1] || $cell[2] !== $m[0][2]) { $same = false; }
}
check('a matched 2x2 prints four identical cells', $same);

printf("\n%d checks\n", $checks);
if ($failures > 0) {
    printf("FAILED (%d)\n", $failures);
    exit(1);
}
print "OK — the PDF draws the solved layout, to the millimetre\n";
