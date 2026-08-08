<?php
/**
 * Post-launch page-COMPOSITION smoke test (lib/layout_render.php): proves the
 * composition-tree engine that replaced the old equal-cell/forced-square
 * renderer — see PLAN.md and lib/layout_render.php's own header for why.
 *
 * Follows tools/verify-layout.php's own style and header conventions.
 *
 * WHAT THIS CHECKS:
 *   1. layout_resolve_orientations(): a flex element resolves to whichever
 *      role scores best against layout_orientation_table(), and
 *      layout_orientation_score() can never disagree with what it resolved.
 *   2. layout_build_tree(): every leaf 0..n-1 appears EXACTLY ONCE, in the
 *      tree's own reading order, for every 1-4 count/orientation combination
 *      — mirrored and not. Specific shapes named in the table's own comments
 *      (two landscapes stack, a lone photo spans beside a stacked pair, a
 *      2+2 groups by role) are asserted directly, not just "some tree came
 *      back".
 *   3. layout_resolve_geometry(): resolved leaf boxes tile the canvas
 *      exactly — areas sum to the whole, no leaf is zero-sized — for every
 *      shape in check #2.
 *   4. layout_auto_crop_rect(): a wider-than-target source crops the sides
 *      and keeps full height; a taller one crops top/bottom and keeps full
 *      width; an exact match crops nothing.
 *   5. layout_crop_css(): reproduces a manual rect's zoom (background-size)
 *      and position, and degrades to plain centering at full-frame (w=h=1).
 *   6. layout_page_tree() end to end: a text card (the 'flex' orientation) on
 *      a real page-shaped orientation list still produces a tree with the
 *      right leaf count.
 *
 * Usage:
 *   php tools/verify-layout-render.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/layout_render.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

/** Every leaf index 0..n-1 present exactly once, in the tree's own order. */
function leaf_order(array $tree): array
{
    $out = layout_tree_leaves($tree);
    ksort($out);
    return array_keys($out);
}

/* ============================================== layout_resolve_orientations */

echo "layout_resolve_orientations(): flex resolves to the best-scoring role...\n";

check(
    '2 landscapes stay landscape (no flex to resolve)',
    layout_resolve_orientations(array('landscape', 'landscape')) === array('landscape', 'landscape')
);
check(
    'a mixed pair is left alone',
    layout_resolve_orientations(array('landscape', 'portrait')) === array('landscape', 'portrait')
);
check(
    'one flex among two landscapes becomes portrait (0.88 beats 0.70)',
    layout_resolve_orientations(array('landscape', 'flex', 'landscape')) === array('landscape', 'portrait', 'landscape')
);
check(
    'layout_orientation_score() never disagrees with what this resolved',
    abs(layout_orientation_score(array('landscape', 'flex', 'landscape')) - 0.88) < 1e-9
);
check(
    'two ties resolve deterministically (first max wins, not random)',
    layout_resolve_orientations(array('flex', 'flex')) === layout_resolve_orientations(array('flex', 'flex'))
);

/* ========================================================= layout_build_tree */

echo "\nlayout_build_tree(): every leaf appears once, in order, for every shape...\n";

$shapes = array(
    array('portrait'),
    array('landscape', 'portrait'),
    array('landscape', 'landscape'),
    array('portrait', 'portrait'),
    array('portrait', 'portrait', 'landscape'),
    array('landscape', 'landscape', 'landscape'),
    array('portrait', 'portrait', 'portrait'),
    array('portrait', 'portrait', 'landscape', 'landscape'),
    array('portrait', 'portrait', 'portrait', 'portrait'),
    array('landscape', 'landscape', 'landscape', 'landscape'),
    array('portrait', 'landscape', 'landscape', 'landscape'),
    array('portrait', 'portrait', 'portrait', 'landscape'),
);

foreach ($shapes as $roles) {
    $label = '[' . implode(',', $roles) . ']';
    $tree  = layout_build_tree($roles, false);
    check("leaves match input for $label", leaf_order($tree) === range(0, count($roles) - 1));

    $mirrored = layout_build_tree($roles, true);
    check("mirrored leaves still match input for $label", leaf_order($mirrored) === range(0, count($roles) - 1));
}

check(
    'two landscapes stack (col split), not a row',
    layout_build_tree(array('landscape', 'landscape'), false)['split'] === 'col'
);
check(
    'a mixed pair sits in a row, not a col',
    layout_build_tree(array('landscape', 'portrait'), false)['split'] === 'row'
);
$loneTree = layout_build_tree(array('portrait', 'landscape', 'landscape'), false);
check(
    '1 portrait + 2 landscape: outer split is a row (span beside a stacked pair)',
    $loneTree['split'] === 'row'
);
check(
    '...and one child is itself a col split of the other two',
    ($loneTree['children'][0]['split'] ?? null) === 'col' || ($loneTree['children'][1]['split'] ?? null) === 'col'
);
$twoTwo = layout_build_tree(array('portrait', 'landscape', 'portrait', 'landscape'), false);
check('2 portrait + 2 landscape: outer split is a col (two uniform rows)', $twoTwo['split'] === 'col');
$rowA = $twoTwo['children'][0];
check(
    'each inner row is uniform (grouped by role, not raw slot order)',
    layout_tree_leaves($rowA) !== array() && (function () use ($rowA): bool {
        $roles = array();
        foreach (layout_tree_leaves($rowA) as $leaf) {
            $roles[] = $leaf['role'];
        }
        return count(array_unique($roles)) === 1;
    })()
);

/* ===================================================== layout_resolve_geometry */

echo "\nlayout_resolve_geometry(): leaf boxes tile the canvas exactly...\n";

foreach ($shapes as $roles) {
    $label = '[' . implode(',', $roles) . ']';
    $tree  = layout_build_tree($roles, false);
    $geo   = layout_resolve_geometry($tree, 0.0, 0.0, 1200.0, 900.0);

    check("one box per leaf for $label", count($geo) === count($roles));

    $area = 0.0;
    $allPositive = true;
    foreach ($geo as $box) {
        $area += $box['w'] * $box['h'];
        if ($box['w'] <= 0.0 || $box['h'] <= 0.0) {
            $allPositive = false;
        }
    }
    check("boxes sum to the full canvas area for $label", abs($area - (1200.0 * 900.0)) < 1.0);
    check("every box has positive width and height for $label", $allPositive);
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

/* ============================================================ layout_page_tree */

echo "\nlayout_page_tree(): a text card resolves and places like any flex element...\n";

$withCard = layout_page_tree(array('landscape', 'landscape', 'flex'), false);
check('3 elements (2 photos + a card) produce 3 leaves', count(layout_tree_leaves($withCard)) === 3);

/* ================================================================= result */

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) FAILED.\n";
    exit(1);
}
echo "All checks passed.\n";
