<?php
/* PAGE COMPOSITION, second generation — the template library and the geometry
 * solver behind it. Pure: content in, rectangles out. No database, no I/O, no
 * global state, so every rule in here is testable without a photo on disk.
 *
 * ====================================================== WHY THIS REPLACES THE
 *
 * lib/layout_render.php builds a page from NOMINAL role aspects: a portrait is
 * 2:3 and a landscape is 3:2 no matter what the photo actually is, and the real
 * photo is then cropped to fit the cell it landed in (layout_auto_crop_rect()).
 * That was the right call when the goal was shape variety. Kathryn's verdict on
 * a full book built that way was that everything looked squeezed, and the
 * numbers agreed: 79% of her library is portrait 3:4, so the engine was
 * mutilating photos that were already a good shape in order to manufacture
 * variety it did not need.
 *
 * This file inverts it. A photo's real aspect ratio is an INPUT to the layout.
 * The page is still a tree of rows and columns, but the sizes come from the
 * photos rather than from a table of nominal shapes.
 *
 * ================================================================ THE RULES
 *
 * All four were settled by Kathryn against rendered proofs (tools/layout-lab2.php
 * and its published artifact), not chosen here:
 *
 *   1. A slot declares the SHAPE it takes ('P' or 'L') and only ever receives
 *      that shape. Templates are transcriptions of layouts she drew, so a page
 *      should look like the drawing it came from.
 *
 *   2. Photos keep their own ratio. The engine does not reshape a photo to fill
 *      a hole.
 *
 *   3. EXCEPT where photos of the same orientation sit side by side: those are
 *      drawn at one identical size, centre-cropping whichever misses the
 *      group's target ratio. Padding the odd one out with white space was built
 *      first and rejected on sight — a 9:16 floating beside three 3:4s reads as
 *      a mistake, and she would rather spend the crop. Across her 123 photos
 *      this costs 4 crops.
 *
 *   4. A row of MIXED orientations is never normalised. It shares one height
 *      and makes a rectangle, which is why the lower band of a 2x2 is allowed
 *      to be shorter than the upper one. Forcing equal widths there was the
 *      one change she rejected outright: a 4:3 and a 3:4 at the same width have
 *      wildly different heights and the landscape ends up in a pool of white.
 *
 * A TEXT CARD is typeset rather than photographed, so it has no ratio of its
 * own and can take a slot of either shape — it simply adopts the canonical
 * ratio for that shape. That keeps every template available to a page carrying
 * a card, which is how the previous engine behaved and what Kathryn chose to
 * keep.
 */

declare(strict_types=1);

/* Canonical ratio per shape. Not an average of the library — these are the
 * shapes a phone actually produces, and 90 of Kathryn's 97 portraits are
 * exactly 3:4. Used for a text card's ratio, and to break ties when a matched
 * group has to pick a target, so that matched pages agree with each other
 * across the book rather than only within themselves. */
const COMPOSE_CANON = array('P' => 0.75, 'L' => 4.0 / 3.0);

/* Gap between photos, as a percentage of the page's side. The page is square,
 * so one number covers both axes. */
const COMPOSE_GAP = 2.0;

/* Fraction of the page the content box may fill. Kathryn picked the tight end
 * after comparing three levels side by side. */
const COMPOSE_FILL = 0.85;

/**
 * The template library: every layout Kathryn drew, plus five derived ones.
 *
 * 'slots' is the required shape of each slot in READING ORDER and is what the
 * partitioner matches a run of photos against. 'tree' is the composition, a
 * row/col nesting whose leaves index into 'slots'.
 *
 * There is deliberately NO geometry here — no weights, no percentages, no
 * sizes. The occupants' own ratios plus the nesting fully determine the page,
 * so a template that also specified proportions would either be redundant or
 * be a lie about what the renderer will do.
 *
 * 'derived' marks a template that is not a transcription of one of her
 * sketches. Those exist because the sketches leave three shape combinations
 * with nowhere to go, and a gap is not neutral: the partitioner's only escape
 * from a run it cannot match is to cut it into smaller pages, so a missing
 * 4-up quietly becomes two 2-ups wherever that shape run occurs.
 */
function compose_templates(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $leaf = static fn(int $i): array => array('t' => 'leaf', 'i' => $i);
    $row  = static fn(array ...$k): array => array('t' => 'row', 'k' => $k);
    $col  = static fn(array ...$k): array => array('t' => 'col', 'k' => $k);

    $cache = array(
        /* --- 1 photo. Her sketches also show a square single; no photo in the
         * library is within 5% of square, so transcribing it would add a
         * template that can never fire. Left out on purpose. */
        'single-P' => array('slots' => array('P'), 'tree' => $leaf(0),
            'note' => 'one portrait, centred'),
        'single-L' => array('slots' => array('L'), 'tree' => $leaf(0),
            'note' => 'one landscape, centred'),

        /* --- 2 photos */
        'pair-PP' => array('slots' => array('P', 'P'), 'tree' => $row($leaf(0), $leaf(1)),
            'note' => 'two portraits side by side'),
        'pair-LL' => array('slots' => array('L', 'L'), 'tree' => $col($leaf(0), $leaf(1)),
            'note' => 'two landscapes stacked'),
        'pair-LP' => array('slots' => array('L', 'P'), 'tree' => $row($leaf(0), $leaf(1)),
            'note' => 'landscape left, portrait right'),
        'pair-PL' => array('slots' => array('P', 'L'), 'tree' => $row($leaf(0), $leaf(1)),
            'note' => 'portrait left, landscape right'),

        /* --- 3 photos */
        'row-PPP' => array('slots' => array('P', 'P', 'P'),
            'tree' => $row($leaf(0), $leaf(1), $leaf(2)),
            'note' => 'three portraits in a row'),
        'col-LLL' => array('slots' => array('L', 'L', 'L'),
            'tree' => $col($leaf(0), $leaf(1), $leaf(2)),
            'note' => 'three landscapes in a column'),
        'heroP-PP' => array('slots' => array('P', 'P', 'P'),
            'tree' => $row($leaf(0), $col($leaf(1), $leaf(2))),
            'note' => 'big portrait left, two portraits stacked right'),
        'heroP-PL' => array('slots' => array('P', 'P', 'L'),
            'tree' => $row($leaf(0), $col($leaf(1), $leaf(2))),
            'note' => 'big portrait left, portrait over landscape right'),
        'bandL-LL' => array('slots' => array('L', 'L', 'L'),
            'tree' => $col($leaf(0), $row($leaf(1), $leaf(2))),
            'note' => 'wide landscape on top, two beneath'),

        /* --- 4 photos */
        'quad-PPPP' => array('slots' => array('P', 'P', 'P', 'P'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            /* Not on a sticky note. Added on Kathryn's instruction once she saw
             * that all four drawn 4-ups need a landscape, which capped 20 of her
             * 36 event groups at three photos a page. "It just makes a large
             * rectangle" is her description and an accurate one. */
            'note' => 'four portraits, 2x2'),
        'quad-PPLL' => array('slots' => array('P', 'P', 'L', 'L'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            'note' => 'two portraits over two landscapes'),
        'pinwheel' => array('slots' => array('P', 'L', 'L', 'P'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            /* Two sketches show an asymmetric 2x2 with a portrait and a
             * landscape in each row, differing only in which photo is drawn
             * biggest. Relative size is a consequence of the occupants' ratios
             * here, not something a template can dial, so they transcribe to one
             * entry. Raised with Kathryn rather than faked into two. */
            'note' => 'portrait/landscape, landscape/portrait'),
        'col3L-heroP' => array('slots' => array('L', 'L', 'L', 'P'),
            'tree' => $row($col($leaf(0), $leaf(1), $leaf(2)), $leaf(3)),
            'note' => 'three landscapes stacked left, big portrait right'),

        /* --- derived; see the function docblock */
        'heroP-LL' => array('slots' => array('P', 'L', 'L'),
            'tree' => $row($leaf(0), $col($leaf(1), $leaf(2))),
            'note' => 'big portrait left, two landscapes stacked right', 'derived' => true),
        'bandL-PP' => array('slots' => array('L', 'P', 'P'),
            'tree' => $col($leaf(0), $row($leaf(1), $leaf(2))),
            'note' => 'wide landscape on top, two portraits beneath', 'derived' => true),
        'quad-PPPL' => array('slots' => array('P', 'P', 'P', 'L'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            'note' => 'three portraits and a landscape, 2x2', 'derived' => true),
        'quad-PPLP' => array('slots' => array('P', 'P', 'L', 'P'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            /* quad-PPPL with the bottom row flipped, asked for so the most-used
             * 4-up has two versions to alternate between: on its own it was
             * carrying a fifth of the book and repeating visibly. */
            'note' => 'three portraits and a landscape, landscape at bottom left',
            'derived' => true),
        'quad-LLLL' => array('slots' => array('L', 'L', 'L', 'L'),
            'tree' => $col($row($leaf(0), $leaf(1)), $row($leaf(2), $leaf(3))),
            'note' => 'four landscapes, 2x2', 'derived' => true),
    );

    return $cache;
}

/**
 * Can this run of occupants fill this template, and if so in what slot order?
 *
 * An occupant is array('shape' => 'P'|'L'|'*', 'ar' => float). A text card uses
 * shape '*' and matches any slot.
 *
 * Slots are filled in reading order, each taking the earliest still-unused
 * occupant that can satisfy it, so a page stays as close to chronological as
 * its template allows. Exact chronology is impossible in general — 'pair-LP'
 * shows the landscape first whichever photo was taken first — and page-internal
 * order matters far less than the order of the pages themselves.
 *
 * Cards are considered only after real photos for a given slot: a card can go
 * anywhere, so letting it grab the first slot it fits would strand a photo that
 * had only that one slot available.
 *
 * @return list<int>|null indices into $run, in slot order
 */
function compose_fill(array $run, array $tpl): ?array
{
    if (count($run) !== count($tpl['slots'])) { return null; }

    $used = array();
    $out  = array();

    foreach ($tpl['slots'] as $want) {
        $pick = null;
        foreach (array(false, true) as $allowCard) {
            foreach ($run as $i => $occ) {
                if (isset($used[$i])) { continue; }
                $isCard = ($occ['shape'] === '*');
                if ($isCard !== $allowCard) { continue; }
                if (!$isCard && $occ['shape'] !== $want) { continue; }
                $pick = $i;
                break 2;
            }
        }
        if ($pick === null) { return null; }
        $used[$pick] = true;
        $out[] = $pick;
    }

    return $out;
}

/**
 * Is there any template at all for a page holding these shapes?
 *
 * The partitioner's feasibility test, and the reason it has to exist: under
 * shape-required templates a page is not simply "n photos", it is n photos of
 * particular shapes, and some combinations have no home. Three landscapes and a
 * portrait is a page; two landscapes and two portraits in the wrong order may
 * not be. A partitioner that only counted photos would happily produce pages
 * that cannot be drawn.
 *
 * Cheap enough to call inside a candidate loop: at most 20 templates, and the
 * count check rejects nearly all of them before compose_fill() runs.
 *
 * @param list<string> $shapes 'P', 'L', or '*' for an occupant that fits either
 */
function compose_accepts(array $shapes): bool
{
    $occ = array();
    foreach ($shapes as $s) {
        $occ[] = array('shape' => $s, 'ar' => COMPOSE_CANON[$s === 'L' ? 'L' : 'P']);
    }

    foreach (compose_templates() as $tpl) {
        if (count($tpl['slots']) !== count($occ)) { continue; }
        if (compose_fill($occ, $tpl) !== null) { return true; }
    }
    return false;
}

/**
 * Every template that accepts this run, cheapest choice first.
 *
 * Template choice is deliberately NOT part of the partitioner's cost function.
 * Several templates accept the same shapes — 'row-PPP' and 'heroP-PP' both want
 * three portraits — so a partitioner that rated them equally picked whichever
 * it enumerated first and the other never appeared; the first full run of the
 * lab used 11 of 19 templates for exactly that reason. Ordering here by least
 * recently used lets interchangeable layouts rotate without letting template
 * choice distort how many photos go on a page.
 *
 * @param array<string,int> $lastUsed template name => page index it last drew
 */
function compose_candidates(array $run, array $lastUsed): array
{
    $out = array();
    foreach (compose_templates() as $name => $tpl) {
        $order = compose_fill($run, $tpl);
        if ($order === null) { continue; }
        $out[] = array('name' => $name, 'order' => $order, 'last' => $lastUsed[$name] ?? -1);
    }
    usort($out, static fn(array $a, array $b): int => array($a['last'], $a['name']) <=> array($b['last'], $b['name']));
    return $out;
}

/**
 * Turn a page's stored slots into occupants the solver understands.
 *
 * The bridge between what the database holds and what this file reasons about,
 * and the one place the two renderers agree on what a slot IS — so the preview
 * and the PDF cannot form different opinions about a photo's shape.
 *
 * A photo with no stored dimensions becomes a wildcard rather than being
 * guessed at, and takes the canonical ratio of whatever slot it lands in. That
 * is the fail-soft branch: a photo whose EXIF never parsed still appears in the
 * book, at a sensible shape, instead of taking the page down with it.
 *
 * A text card is a wildcard by nature — it is typeset into whatever rectangle
 * it is given, which is why it can sit in a slot of either shape.
 *
 * @param list<array> $slots book_page_photos rows, in slot order
 */
function compose_occupants(array $slots): array
{
    $out = array();
    foreach ($slots as $slot) {
        if (($slot['photo_id'] ?? null) === null) {
            $out[] = array('shape' => '*', 'ar' => COMPOSE_CANON['P'], 'card' => true);
            continue;
        }

        $w = (int) ($slot['width'] ?? 0);
        $h = (int) ($slot['height'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            $out[] = array('shape' => '*', 'ar' => COMPOSE_CANON['P']);
            continue;
        }

        $ar = $w / $h;
        $out[] = array('shape' => $ar > 1.05 ? 'L' : ($ar < 0.95 ? 'P' : '*'), 'ar' => $ar);
    }
    return $out;
}

/**
 * Choose a template for every page of a layout, in one pass.
 *
 * Done for the WHOLE layout rather than per page because the choice depends on
 * what the pages before it used: several templates accept the same shapes, and
 * picking the least recently used one is what stops a book of portraits being
 * the same arrangement forty times. That is a property of the sequence, so a
 * per-page function could not compute it.
 *
 * It also has to be identical in the browser preview and the PDF. Deriving it
 * from the page's own content plus its position — rather than storing a choice
 * when the layout is generated — follows the same reasoning layout_render.php
 * used for its mirror bit: a decision recomputed from current content cannot
 * disagree with itself after a manual swap or a reflow moves photos around,
 * where a stored one silently would.
 *
 * A page whose shapes no template accepts gets null rather than a wrong
 * template. Callers draw nothing for it and the page is visibly empty, which is
 * a bug someone can see; guessing would produce a page that looks plausible and
 * is wrong.
 *
 * @param list<list<array{shape:string,ar:float}>> $pages occupants per page, in
 *   page order
 * @return list<array{name:string,order:list<int>}|null>
 */
function compose_assign(array $pages): array
{
    $lastUsed = array();
    $out      = array();

    foreach ($pages as $index => $occ) {
        $candidates = compose_candidates($occ, $lastUsed);
        if ($candidates === array()) {
            $out[] = null;
            continue;
        }

        $pick = $candidates[0];
        $lastUsed[$pick['name']] = $index;
        $out[] = array('name' => $pick['name'], 'order' => $pick['order']);
    }

    return $out;
}

/**
 * Put a page's occupants into TEMPLATE SLOT ORDER, resolving wildcards.
 *
 * compose_fill() answers "which occupant goes in which slot"; the solver wants
 * them in slot order, so this is the step between. Two things happen here that
 * matter more than the reordering:
 *
 * A wildcard — a text card, or a photo whose dimensions never parsed — takes on
 * the SHAPE of the slot it landed in, not merely its ratio. Without that it
 * would break a matched group: two portraits either side of a card would stop
 * counting as "all the same orientation" and the row would fall back to ratio
 * widths, which is the fault Kathryn rejected on page 10. A card is typeset
 * into whatever rectangle it is given, so calling it a portrait when it sits in
 * a portrait slot is simply true.
 *
 * The caller keeps $order to map a solved rectangle back to its database row:
 * rect['slot'] indexes the TEMPLATE, and $order[rect['slot']] indexes the
 * page's slots.
 *
 * @return list<array{shape:string,ar:float}> in slot order
 */
function compose_bind(array $occ, array $tpl, array $order): array
{
    $bound = array();
    foreach ($tpl['slots'] as $i => $want) {
        $o = $occ[$order[$i]];
        if ($o['shape'] === '*') {
            $o['shape'] = $want;
            $o['ar']    = COMPOSE_CANON[$want];
        }
        $bound[] = $o;
    }
    return $bound;
}

/* ------------------------------------------------------------------ solver */

/**
 * Is this node a MATCHED group — a row or column whose occupants are all the
 * same orientation, and which therefore gets identical cells?
 *
 * Uniformity is a property of the OCCUPANTS, never of the template. An earlier
 * build keyed it off "this row belongs to a 2x2" and produced the one page
 * Kathryn rejected outright, where a landscape forced to a portrait's width
 * floated in white space. See rule 4 in this file's header.
 *
 * A text card counts as whatever its slot asked for, so a card among portraits
 * does not break the group.
 */
function compose_uniform(array $node, array $occ): bool
{
    if ($node['t'] === 'leaf') { return false; }
    $shape = null;
    foreach ($node['k'] as $kid) {
        if ($kid['t'] !== 'leaf') { return false; }
        $s = $occ[$kid['i']]['shape'];
        if ($shape === null) { $shape = $s; }
        if ($s !== $shape) { return false; }
    }
    return true;
}

/**
 * The single ratio every occupant of a matched group is drawn at.
 *
 * The most common ratio in the group wins, because cropping the minority loses
 * the least. Ties — which is every two-photo group whose photos differ — go to
 * whichever is closest to the canonical shape, so that matched pages agree with
 * each other across the book and not merely within one page.
 */
function compose_target(array $node, array $occ): float
{
    $shape = $occ[$node['k'][0]['i']]['shape'];
    $canon = COMPOSE_CANON[$shape === 'L' ? 'L' : 'P'];

    $tally = array();
    foreach ($node['k'] as $kid) {
        $key = (string) round($occ[$kid['i']]['ar'], 6);
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    $best = null;
    foreach ($tally as $key => $n) {
        $ar   = (float) $key;
        $rank = array(-$n, abs(log($ar / $canon)));
        if ($best === null || $rank < $best['rank']) {
            $best = array('rank' => $rank, 'ar' => $ar);
        }
    }
    return $best['ar'];
}

/** Fraction of an occupant's area lost when centre-cropped to $target. */
function compose_crop_loss(float $ar, float $target): float
{
    return 1.0 - min($ar, $target) / max($ar, $target);
}

/** Cell size along the divided axis, for n equal cells spanning $s. */
function compose_cell(array $node, float $s, float $gap): float
{
    return ($s - $gap * (count($node['k']) - 1)) / count($node['k']);
}

/* A row's children share one height and a column's share one width. For a plain
 * row of photos the height follows in closed form, but a row containing a
 * nested column has none once gaps are in play, so the shared dimension is
 * found by bisection. Both measures are strictly monotonic in the dimension
 * being solved for, so bisection is exact to tolerance and cannot pick a wrong
 * branch. 40 halvings takes the error below a millionth of a page. */
const COMPOSE_ITERS = 40;

function compose_height(array $node, float $w, float $gap, array $occ): float
{
    if ($node['t'] === 'leaf') { return $w / $occ[$node['i']]['ar']; }

    if (compose_uniform($node, $occ)) {
        $t = compose_target($node, $occ);
        return $node['t'] === 'row'
            ? compose_cell($node, $w, $gap) / $t
            : count($node['k']) * ($w / $t) + $gap * (count($node['k']) - 1);
    }

    if ($node['t'] === 'col') {
        $h = $gap * (count($node['k']) - 1);
        foreach ($node['k'] as $kid) { $h += compose_height($kid, $w, $gap, $occ); }
        return $h;
    }

    $lo = 0.0;
    $hi = $w * 8.0;
    for ($i = 0; $i < COMPOSE_ITERS; $i++) {
        $mid = ($lo + $hi) / 2.0;
        if (compose_width($node, $mid, $gap, $occ) < $w) { $lo = $mid; } else { $hi = $mid; }
    }
    return ($lo + $hi) / 2.0;
}

function compose_width(array $node, float $h, float $gap, array $occ): float
{
    if ($node['t'] === 'leaf') { return $h * $occ[$node['i']]['ar']; }

    if (compose_uniform($node, $occ)) {
        $t = compose_target($node, $occ);
        return $node['t'] === 'row'
            ? $h * $t * count($node['k']) + $gap * (count($node['k']) - 1)
            : compose_cell($node, $h, $gap) * $t;
    }

    if ($node['t'] === 'row') {
        $w = $gap * (count($node['k']) - 1);
        foreach ($node['k'] as $kid) { $w += compose_width($kid, $h, $gap, $occ); }
        return $w;
    }

    $lo = 0.0;
    $hi = $h * 8.0;
    for ($i = 0; $i < COMPOSE_ITERS; $i++) {
        $mid = ($lo + $hi) / 2.0;
        if (compose_height($node, $mid, $gap, $occ) < $h) { $lo = $mid; } else { $hi = $mid; }
    }
    return ($lo + $hi) / 2.0;
}

function compose_place(array $node, float $x, float $y, float $w, float $h, float $gap, array $occ, array &$out): void
{
    if ($node['t'] === 'leaf') {
        $out[] = array('slot' => $node['i'], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'crop' => 0.0);
        return;
    }

    if (compose_uniform($node, $occ)) {
        $t   = compose_target($node, $occ);
        $row = $node['t'] === 'row';
        $c   = compose_cell($node, $row ? $w : $h, $gap);
        $cw  = $row ? $c : $c * $t;
        $ch  = $row ? $c / $t : $c;

        $grp = count($out);   // cells of one group share the index of the first
        $cx  = $x;
        $cy  = $y;
        foreach ($node['k'] as $kid) {
            $o = $occ[$kid['i']];
            $out[] = array(
                'slot' => $kid['i'], 'x' => $cx, 'y' => $cy, 'w' => $cw, 'h' => $ch,
                /* A text card is typeset into whatever rectangle it is given, so
                 * it is never "cropped" however far its slot is from canonical. */
                'crop' => $o['shape'] === '*' ? 0.0 : compose_crop_loss($o['ar'], $t),
                'group' => $grp,
            );
            if ($row) { $cx += $cw + $gap; } else { $cy += $ch + $gap; }
        }
        return;
    }

    if ($node['t'] === 'row') {
        $cx = $x;
        foreach ($node['k'] as $kid) {
            $kw = compose_width($kid, $h, $gap, $occ);
            compose_place($kid, $cx, $y, $kw, $h, $gap, $occ, $out);
            $cx += $kw + $gap;
        }
        return;
    }

    $cy = $y;
    foreach ($node['k'] as $kid) {
        $kh = compose_height($kid, $w, $gap, $occ);
        compose_place($kid, $x, $cy, $w, $kh, $gap, $occ, $out);
        $cy += $kh + $gap;
    }
}

/**
 * Solve one page.
 *
 * @param array $tpl one entry from compose_templates()
 * @param list<array{shape:string,ar:float}> $occ occupants IN SLOT ORDER
 * @return list<array{slot:int,x:float,y:float,w:float,h:float,crop:float}>
 *   rectangles in PERCENT of the square page, so the same numbers drive the
 *   browser preview and the PDF at any DPI without a second opinion about size.
 *   'crop' is the fraction of that occupant's area a centre-crop will remove;
 *   0.0 means it is drawn whole.
 */
function compose_solve(array $tpl, array $occ, float $fill = COMPOSE_FILL, float $gap = COMPOSE_GAP): array
{
    $box = $fill * 100.0;
    $off = (100.0 - $box) / 2.0;

    $w = $box;
    $h = compose_height($tpl['tree'], $box, $gap, $occ);
    if ($h > $box) {
        $h = $box;
        $w = compose_width($tpl['tree'], $box, $gap, $occ);
    }

    $out = array();
    compose_place($tpl['tree'], $off + ($box - $w) / 2.0, $off + ($box - $h) / 2.0, $w, $h, $gap, $occ, $out);

    usort($out, static fn(array $a, array $b): int => $a['slot'] <=> $b['slot']);
    return $out;
}
