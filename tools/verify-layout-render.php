<?php
/**
 * Photo windowing: the two pure functions in lib/layout_render.php that
 * survived Round 6.
 *
 * This file used to also test the composition tree — nominal role aspects, the
 * row/col aspect algebra, the geometry resolver. lib/compose.php replaced all
 * of it by solving the page from the photos' real ratios, so those tests went
 * with the code they described; tools/verify-compose.php owns page geometry
 * now. What is left is the crop maths, which is still load-bearing for two
 * narrow jobs: cutting pixels for mPDF, which has no object-fit, and drawing
 * Kathryn's manual crop override in the browser.
 *
 * Usage: php tools/verify-layout-render.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/layout_render.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if (!$condition) { $failures++; }
    printf("  %-4s %s\n", $condition ? 'ok' : 'FAIL', $label);
}

/* ====================================================== layout_auto_crop_rect */

echo "\nlayout_auto_crop_rect(): centered cover-fit crop...\n";

$wide = layout_auto_crop_rect(3000, 2000, 1.0);
check('a wider-than-target source keeps full height', abs($wide['h'] - 1.0) < 1e-9);
check('...and crops the sides symmetrically', $wide['w'] < 1.0 && abs($wide['x'] - (1.0 - $wide['w']) / 2.0) < 1e-9);

$tall = layout_auto_crop_rect(2000, 3000, 1.0);
check('a taller-than-target source keeps full width', abs($tall['w'] - 1.0) < 1e-9);
check('...and crops top/bottom symmetrically', $tall['h'] < 1.0 && abs($tall['y'] - (1.0 - $tall['h']) / 2.0) < 1e-9);

$exact = layout_auto_crop_rect(3000, 2000, 1.5);
check('an exact aspect match crops nothing', abs($exact['w'] - 1.0) < 1e-9 && abs($exact['h'] - 1.0) < 1e-9);

/* ========================================================== layout_crop_css */

echo "\nlayout_crop_css(): reproduces a manual crop rect as background CSS...\n";

$full = layout_crop_css(array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0));
check('a full-frame rect is centered, no zoom', $full['size'] === '100% 100%' && $full['position'] === '50% 50%');

$tight = layout_crop_css(array('x' => 0.25, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0));
check('a half-width rect zooms in 2x horizontally', $tight['size'] === '200% 100%');
check('...and centers horizontally (x is centered in the overflow)', $tight['position'] === '50% 50%');

/* A photo already the shape of its box must come back untouched. This is the
 * ordinary case now, not an edge one: under the Round 6 rules a photo's
 * rectangle IS its own shape unless the composer matched it to same-shape
 * neighbours, so an auto-crop that quietly trimmed a pixel here would be
 * cropping almost every photo in the book. */
foreach (array(array(3024, 4032), array(4032, 3024), array(1080, 1920)) as $dim) {
    $rect = layout_auto_crop_rect($dim[0], $dim[1], $dim[0] / $dim[1]);
    check("a {$dim[0]}x{$dim[1]} photo in its own shape is not cropped at all",
        abs($rect['x']) < 1e-9 && abs($rect['y']) < 1e-9
        && abs($rect['w'] - 1.0) < 1e-9 && abs($rect['h'] - 1.0) < 1e-9);
}

/* Fail soft: a photo with no stored dimensions must not produce a nonsense
 * rect that later divides by zero somewhere in mPDF. */
$junk = layout_auto_crop_rect(0, 0, 1.0);
check('a photo with no dimensions falls back to the whole frame',
    $junk['w'] === 1.0 && $junk['h'] === 1.0);

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) FAILED.\n";
    exit(1);
}
echo "All checks passed.\n";
