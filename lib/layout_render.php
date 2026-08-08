<?php
/* Page COMPOSITION — how a page_type='photos' page's already-decided slots
 * (lib/layout.php's job) turn into an actual on-screen or on-paper shape.
 * Shared by public/layout.php (the browser preview, CSS flexbox) and
 * lib/pdfexport.php (nested mPDF <table>s), so the two can't drift apart —
 * see this app's own history of exactly that drift (PLAN.md's Phase 8 note
 * on layout.css) for why "two renderers, one tree" beats "two renderers,
 * two opinions".
 *
 * ============================================================ THE PROBLEM
 *
 * Kathryn's complaint (see PLAN.md): every photo was cropped into a square,
 * and pages leaned heavily 1-up because a mismatched portrait+landscape pair
 * had nowhere good to go. Both traced to the same cause — every slot was an
 * interchangeable equal-size cell (an HTML <table> with equal <td>s, a CSS
 * grid of equal boxes), so a photo's own shape never got to matter.
 *
 * THE FIX: a page is a TREE of binary splits, not a grid of equal cells.
 * Two photos side by side don't get equal columns — a landscape gets a wide
 * column, a portrait a narrow one, sized to their own aspect ratio, and the
 * gap between them reads as page margin rather than an empty box. This is
 * the "asymmetric" option from the two mockups Kathryn reviewed before this
 * was built (chose over "letterboxed" — see PLAN.md).
 *
 * ============================================================== THE TREE
 *
 * A leaf is one occupant (photo or text card) at its own effective aspect
 * ratio. A split has two children and a direction:
 *
 *   'row'  — children share the split's HEIGHT, side by side. A child's
 *            share of the WIDTH is proportional to its own aspect ratio
 *            (wider photo, wider column).
 *   'col'  — children share the split's WIDTH, stacked. A child's share of
 *            the HEIGHT is proportional to 1/aspect (taller photo, taller
 *            row).
 *
 * Every node — leaf or split — has an EFFECTIVE ASPECT RATIO, computed
 * bottom-up (layout_tree_aspect()): a leaf's is its own; a row split's is
 * the SUM of its children's (same height, so widths add); a col split's is
 * 1/(sum of 1/child aspect) (same width, so heights add). That's what lets
 * a composite subtree (say, two stacked landscapes) act as one weighted
 * participant in whatever split contains it, with no separate case for
 * "leaf vs. subtree" anywhere in the renderer.
 *
 * NOT A GENERAL BIN-PACKER. Exactly the shapes layout.php's own
 * layout_orientation_table() already names as good pages (brief §4.3's
 * table, whose comments this file's layout_build_tree() branches follow
 * directly) — 1..4 occupants, built by a fixed set of rules, not a search.
 * A future retune changes a branch here the same way retuning the table
 * means changing a number there.
 */

declare(strict_types=1);

require_once __DIR__ . '/layout.php';

/**
 * Nominal aspect ratio (width/height) per resolved role — the same shapes
 * layout.php's own comments use ("3:2 landscape", "2:3 portrait"). Not read
 * from any one photo's real dimensions: these are WEIGHTS for how much
 * space a role wants relative to its page-mates, not a promise about any
 * single photo's exact ratio — see layout_auto_crop_rect() for where a real
 * photo's real dimensions actually get used.
 */
function layout_role_aspect(string $role): float
{
    return $role === 'portrait' ? (2.0 / 3.0) : (3.0 / 2.0);
}

/**
 * Bottom-up effective aspect ratio of a tree node — see this file's header.
 */
function layout_tree_aspect(array $node): float
{
    if (isset($node['leaf'])) {
        return (float) $node['aspect'];
    }

    $sum = 0.0;
    foreach ($node['children'] as $child) {
        $a = layout_tree_aspect($child);
        $sum += $node['split'] === 'row' ? $a : (1.0 / max(0.0001, $a));
    }

    return $node['split'] === 'row' ? $sum : (1.0 / max(0.0001, $sum));
}

/** One leaf: the original index into the page's slot list, plus its own
 *  aspect (a role's nominal aspect — see layout_role_aspect()). */
function layout_tree_leaf(int $index, string $role): array
{
    return array('leaf' => $index, 'role' => $role, 'aspect' => layout_role_aspect($role));
}

/** One split node. Computes and caches its own effective aspect so callers
 *  never have to walk back down. */
function layout_tree_split(string $direction, array $children): array
{
    $node = array('split' => $direction, 'children' => $children);
    $node['aspect'] = layout_tree_aspect($node);
    return $node;
}

/**
 * Build the composition tree for one page's already-resolved roles.
 *
 * @param list<string> $roles 'portrait' | 'landscape', one per slot, IN SLOT
 *   ORDER — see layout_resolve_orientations() for how a page's raw
 *   orientations (including a text card's 'flex') become this list.
 * @param bool $mirror flips left/right and top/bottom within splits, for
 *   visual variety between pages of the same shape. Derived by the caller
 *   from something stable and free — public/layout.php and pdfexport.php
 *   both use page_number parity — rather than stored: a page's shape is
 *   fully determined by its CURRENT content plus this one bit, and a bit
 *   that's just "even or odd" can't disagree with itself the way a stored
 *   decision could after a manual edit changes what's on the page.
 * @return array a tree node whose leaves are 0..count($roles)-1, each
 *   appearing exactly once, in original order left-to-right / top-to-bottom
 *   in the shape that node's split direction implies.
 */
function layout_build_tree(array $roles, bool $mirror = false): array
{
    $n = count($roles);

    if ($n <= 0) {
        // Nothing to lay out — see render_photo_slot()'s empty-page case;
        // callers check count() before reaching this, but a leaf-of-nothing
        // would be a worse failure than a 1x1 aspect placeholder.
        return layout_tree_leaf(0, 'landscape');
    }

    if ($n === 1) {
        return layout_tree_leaf(0, $roles[0]);
    }

    $order = static function (array $pair) use ($mirror): array {
        return $mirror ? array_reverse($pair) : $pair;
    };

    if ($n === 2) {
        $leaves = array(layout_tree_leaf(0, $roles[0]), layout_tree_leaf(1, $roles[1]));
        // Two landscapes read better stacked (each is wide/short; stacked
        // they form one tall column) — everything else, side by side.
        // Matches layout_orientation_table()'s own comment on the 2-photo
        // row, and pdfexport.php's prior landscapeCount===2 special case.
        $bothLandscape = $roles[0] === 'landscape' && $roles[1] === 'landscape';
        return layout_tree_split($bothLandscape ? 'col' : 'row', $order($leaves));
    }

    $portraits  = array();
    $landscapes = array();
    foreach ($roles as $i => $role) {
        if ($role === 'portrait') {
            $portraits[] = $i;
        } else {
            $landscapes[] = $i;
        }
    }

    if ($n === 3) {
        // A clean 1-and-2 split: one photo spans the full height/width
        // beside a stacked pair of the other orientation. Matches the
        // table's 0.90/0.88 rows ("one full-height portrait beside two
        // stacked landscapes", and its mirror).
        if (count($portraits) === 1 || count($landscapes) === 1) {
            $lone = count($portraits) === 1 ? $portraits[0] : $landscapes[0];
            $rest = count($portraits) === 1 ? $landscapes : $portraits;

            $span = layout_tree_leaf($lone, $roles[$lone]);
            $col  = layout_tree_split('col', array(
                layout_tree_leaf($rest[0], $roles[$rest[0]]),
                layout_tree_leaf($rest[1], $roles[$rest[1]]),
            ));
            return layout_tree_split('row', $order(array($span, $col)));
        }

        // All three the same orientation: portraits tolerate a narrow
        // column (table's own comment) so they go in a row; landscapes
        // don't, so they stack instead of running as three thin strips.
        $leaves = array_map(
            static fn(int $i): array => layout_tree_leaf($i, $roles[$i]),
            $portraits !== array() ? $portraits : $landscapes
        );
        return layout_tree_split($portraits !== array() ? 'row' : 'col', $leaves);
    }

    // $n === 4
    if (count($portraits) === 2 && count($landscapes) === 2) {
        // "Each ROW of the grid is uniform" (table's 0.92 comment) — group
        // by role rather than raw slot order, so the two landscapes share
        // one row and the two portraits share the other instead of
        // alternating into a messier grid.
        $rowA = layout_tree_split('row', array(
            layout_tree_leaf($landscapes[0], $roles[$landscapes[0]]),
            layout_tree_leaf($landscapes[1], $roles[$landscapes[1]]),
        ));
        $rowB = layout_tree_split('row', array(
            layout_tree_leaf($portraits[0], $roles[$portraits[0]]),
            layout_tree_leaf($portraits[1], $roles[$portraits[1]]),
        ));
        return layout_tree_split('col', $order(array($rowA, $rowB)));
    }

    if (count($portraits) === 4 - count($landscapes) && (count($portraits) === 0 || count($landscapes) === 0)) {
        // Four of a kind: a plain 2x2 in original chronological order —
        // nothing to weight toward, table's own 0.85 already marks this
        // "fine, just busy" rather than a favorite.
        $top = layout_tree_split('row', array(
            layout_tree_leaf(0, $roles[0]), layout_tree_leaf(1, $roles[1]),
        ));
        $bottom = layout_tree_split('row', array(
            layout_tree_leaf(2, $roles[2]), layout_tree_leaf(3, $roles[3]),
        ));
        return layout_tree_split('col', array($top, $bottom));
    }

    // 3-and-1: one photo spans beside a stacked column of the other three —
    // same shape as the 3-photo lone case, one longer.
    $lone = count($portraits) === 1 ? $portraits[0] : $landscapes[0];
    $rest = count($portraits) === 1 ? $landscapes : $portraits;

    $span = layout_tree_leaf($lone, $roles[$lone]);
    $col  = layout_tree_split('col', array_map(
        static fn(int $i): array => layout_tree_leaf($i, $roles[$i]),
        $rest
    ));
    return layout_tree_split('row', $order(array($span, $col)));
}

/**
 * The one entry point both renderers call: raw per-slot orientations in —
 * layout_orientation() per photo slot, 'flex' for a text-card slot — a
 * composition tree out. Resolves flex roles the same way layout.php's own
 * scoring does (layout_resolve_orientations()), so a page's shape always
 * agrees with the page it was scored as.
 *
 * @param list<string> $orientations 'portrait' | 'landscape' | 'flex', one
 *   per slot, in slot order
 * @param bool $mirror see layout_build_tree()
 */
function layout_page_tree(array $orientations, bool $mirror = false): array
{
    $roles = layout_resolve_orientations($orientations);
    return layout_build_tree($roles, $mirror);
}

/**
 * Walk a tree in leaf order (0, 1, 2, ...), returning each leaf's own node.
 * Both renderers use this to pull each slot's resolved role/aspect back out
 * without re-walking the tree themselves.
 *
 * @return array<int,array> keyed by leaf index
 */
function layout_tree_leaves(array $node, array &$out = array()): array
{
    if (isset($node['leaf'])) {
        $out[(int) $node['leaf']] = $node;
    } else {
        foreach ($node['children'] as $child) {
            layout_tree_leaves($child, $out);
        }
    }
    return $out;
}

/**
 * Resolve every leaf's ABSOLUTE box within a $width x $height canvas (any
 * consistent unit — px for CSS-free contexts, mm for PDF), by recursively
 * dividing space per node the same way CSS flexbox would with
 * `flex: <weight> 0 0` on every child (weight = the child's own effective
 * aspect for a row split, 1/aspect for a col split — see this file's
 * header). The browser preview lets flexbox do this itself; lib/pdfexport.php
 * has no flexbox, so it calls this to get real millimeters for a nested
 * <table>'s cell sizes and for the auto-crop math (layout_auto_crop_rect()).
 *
 * @return array<int,array{x:float,y:float,w:float,h:float}> keyed by leaf index
 */
function layout_resolve_geometry(array $node, float $x, float $y, float $width, float $height, array &$out = array()): array
{
    if (isset($node['leaf'])) {
        $out[(int) $node['leaf']] = array('x' => $x, 'y' => $y, 'w' => $width, 'h' => $height);
        return $out;
    }

    $weights = array();
    foreach ($node['children'] as $child) {
        $a = layout_tree_aspect($child);
        $weights[] = $node['split'] === 'row' ? $a : (1.0 / max(0.0001, $a));
    }
    $total = array_sum($weights) ?: count($weights);

    $cursor = $node['split'] === 'row' ? $x : $y;
    foreach ($node['children'] as $i => $child) {
        $share = $weights[$i] / $total;
        if ($node['split'] === 'row') {
            $cw = $width * $share;
            layout_resolve_geometry($child, $cursor, $y, $cw, $height, $out);
            $cursor += $cw;
        } else {
            $ch = $height * $share;
            layout_resolve_geometry($child, $x, $cursor, $width, $ch, $out);
            $cursor += $ch;
        }
    }

    return $out;
}

/**
 * The tightest centered crop of a $srcW x $srcH photo that fills a box of
 * $targetAspect (width/height) — the same result `object-fit: cover` gives
 * a browser for free, computed here because lib/pdfexport.php has to
 * actually cut the pixels (mPDF has no object-fit equivalent). Normalized
 * (0..1 fractions), same convention as crop.js/imageproc_crop_photo()'s
 * manual crop rect, so a manually-adjusted rect and an auto one are
 * interchangeable to every caller.
 *
 * @return array{x:float,y:float,w:float,h:float}
 */
function layout_auto_crop_rect(int $srcW, int $srcH, float $targetAspect): array
{
    if ($srcW <= 0 || $srcH <= 0 || $targetAspect <= 0) {
        return array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0);
    }

    $srcAspect = $srcW / $srcH;

    if ($srcAspect > $targetAspect) {
        // Source is relatively wider than the target box: keep full height,
        // crop the sides.
        $w = $targetAspect / $srcAspect;
        return array('x' => (1.0 - $w) / 2.0, 'y' => 0.0, 'w' => $w, 'h' => 1.0);
    }

    // Source is relatively taller (or equal): keep full width, crop top/bottom.
    $h = $srcAspect / $targetAspect;
    return array('x' => 0.0, 'y' => (1.0 - $h) / 2.0, 'w' => 1.0, 'h' => $h);
}

/**
 * CSS for reproducing an arbitrary crop rect via `background-image`, used
 * ONLY when a slot carries a Kathryn-adjusted crop (book_page_photos'
 * crop_x/y/w/h) — see public/layout.php's render_photo_slot(). An ordinary
 * <img> with `object-fit: cover; object-position: 50% 50%` already
 * reproduces the DEFAULT centered auto-crop for free and is used for every
 * slot that has no manual override; object-position alone can't reproduce a
 * manual crop because it can only slide the image, never zoom it in past
 * what `cover` already picks — the standard fix is a background-image sized
 * so the rect exactly fills the box.
 *
 * @param array{x:float,y:float,w:float,h:float} $rect
 * @return array{size:string,position:string}
 */
function layout_crop_css(array $rect): array
{
    $w = max(0.02, min(1.0, (float) $rect['w']));
    $h = max(0.02, min(1.0, (float) $rect['h']));
    $x = max(0.0, min(1.0 - $w, (float) $rect['x']));
    $y = max(0.0, min(1.0 - $h, (float) $rect['y']));

    $sizeX = 100.0 / $w;
    $sizeY = 100.0 / $h;
    // Standard background-position-as-fraction-of-overflow formula: 0% pins
    // the image's left/top edge to the box's, 100% pins its right/bottom.
    $posX = $w >= 1.0 ? 50.0 : ($x / (1.0 - $w)) * 100.0;
    $posY = $h >= 1.0 ? 50.0 : ($y / (1.0 - $h)) * 100.0;

    return array(
        'size'     => round($sizeX, 2) . '% ' . round($sizeY, 2) . '%',
        'position' => round($posX, 2) . '% ' . round($posY, 2) . '%',
    );
}
