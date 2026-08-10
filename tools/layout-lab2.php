<?php
/**
 * LAYOUT LAB 2 — shape-driven prototyping harness. NOT the shipping engine.
 *
 * WHY A SECOND LAB. Lab 1 asked "what shape should this slot be?" and then
 * cropped whatever photo landed there to fill it. Kathryn's verdict on the
 * output was that everything looked squeezed, and she was right: 79% of her
 * library is portrait 3:4, so inventing shape variety meant mutilating photos
 * that were already a perfectly good shape. She then drew the layouts she
 * actually wants on sticky notes, and every frame in them is a natural photo
 * shape with white space taking up the slack.
 *
 * So this lab inverts the model:
 *
 *   - A photo's aspect ratio is an input to the layout, not an output of it.
 *     The engine never reshapes a photo to fill a slot it was assigned.
 *   - Templates declare the SHAPE of each slot ('P'/'L') and only ever receive
 *     a photo of that shape. Kathryn chose "shapes are required" over "shapes
 *     are illustrative" explicitly. The cost is that some templates fire rarely;
 *     the benefit is that a page always looks like the sketch it came from.
 *   - Geometry is solved, not tabulated. See the solver in the emitted JS.
 *   - The one exception, added at Kathryn's request in review: photos of the
 *     SAME orientation sitting side by side are drawn at one identical size,
 *     centre-cropping whichever misses the target ratio. Padding the odd one
 *     out with white space was tried first and rejected — a 9:16 floating
 *     beside three 3:4s reads as a mistake. A row of MIXED orientations is
 *     never normalised; it just makes a rectangle, which is why the lower band
 *     of a 2x2 is allowed to be shorter than the upper one.
 *
 * WHY THE GEOMETRY LIVES IN JAVASCRIPT. Kathryn asked to compare alignment
 * policies and margin levels rather than pick blind, which is nine renderings
 * of every page. Emitting a copy of the markup per combination would inline the
 * base64 thumbnails once per copy and blow past the artifact size limit, so the
 * thumbnails and the PAGE PLAN are emitted once and the solver runs in the
 * browser. Toggling a policy is then instant, which is the whole point — you
 * cannot judge white space by reloading.
 *
 * The page plan itself (which photos share a page, under which template) is
 * computed HERE, once, and does not change with the toggles. That is
 * deliberate: the toggles are meant to isolate the look of a page, not to
 * reshuffle the book underneath it.
 *
 * Shares layout_orientation() with the production engine so "portrait" means
 * the same thing here as in the real book. Shares nothing else.
 *
 * Usage:
 *   php tools/layout-lab2.php --photos=P.csv --groups=G.csv \
 *       --thumbs=DIR --map=thumb-map.json --out=lab2.html
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/layout.php';

/* ------------------------------------------------------------------ input */

$opt = getopt('', array('photos:', 'groups:', 'thumbs:', 'map:', 'out:'));
foreach (array('photos', 'thumbs', 'map', 'out') as $need) {
    if (!isset($opt[$need])) {
        fwrite(STDERR, "missing --$need\n");
        exit(1);
    }
}

function lab2_csv(string $file): array
{
    $rows = array();
    $head = null;
    $fh   = fopen($file, 'r');
    while (($r = fgetcsv($fh)) !== false) {
        if ($head === null) { $head = $r; continue; }
        if (count($r) !== count($head)) { continue; }
        $rows[] = array_combine($head, $r);
    }
    fclose($fh);
    return $rows;
}

$thumbMap = json_decode((string) file_get_contents($opt['map']), true);

$photos = array();
foreach (lab2_csv($opt['photos']) as $row) {
    if ((int) $row['skip_for_book'] === 1) { continue; }
    $id   = (int) $row['id'];
    $file = $thumbMap[$id] ?? null;
    if ($file === null) { continue; }

    $abs = rtrim($opt['thumbs'], '/') . '/' . $file;
    $dim = @getimagesize($abs);
    if ($dim === false) { continue; }

    /* The THUMBNAIL's dimensions are the source of truth, not the CSV's — same
     * call as lab 1, and it matters more here. Under a shapes-are-required
     * model a slot that receives the wrong shape does not just look slightly
     * off, it breaks the arrangement the template exists to produce. Measuring
     * the file that will actually be drawn keeps the page self-consistent even
     * where the id->file mapping is wrong. */
    $orient = layout_orientation(array('width' => $dim[0], 'height' => $dim[1]));

    /* 'flex' means near-square. The production engine lets those float to
     * whichever role a page needs; here there is nothing to float to, because
     * every slot names a concrete shape. Kathryn's library contains no photo
     * within 5% of square, so this branch is currently unreachable — it exists
     * so that importing one later degrades to "treated as portrait" instead of
     * silently dropping the photo out of the book. */
    if ($orient === 'flex') {
        $orient = ($dim[0] > $dim[1]) ? 'landscape' : 'portrait';
    }

    $photos[] = array(
        'id'          => $id,
        'captured_at' => $row['captured_at'],
        'group'       => ($row['event_group_id'] === '' ? null : (int) $row['event_group_id']),
        'w'           => $dim[0],
        'h'           => $dim[1],
        'shape'       => ($orient === 'landscape' ? 'L' : 'P'),
        'file'        => $abs,
    );
}
usort($photos, fn(array $a, array $b): int => array($a['captured_at'], $a['id']) <=> array($b['captured_at'], $b['id']));

/* -------------------------------------------------------- template library */

/**
 * Every template is one of Kathryn's sticky notes, transcribed.
 *
 * 'slots' lists the required shape of each slot in READING ORDER (left-to-right,
 * top-to-bottom) and is what the partitioner matches against. 'tree' is the
 * composition: a row/col nesting whose leaves are indices into 'slots'.
 *
 * There is no geometry here — no weights, no percentages, no sizes. The photos'
 * own aspect ratios plus the nesting fully determine the page, so a template
 * that tried to also specify proportions would either be redundant or be a lie.
 * This is why the two asymmetric 4-up sketches collapse into one entry (see
 * notes below).
 */
function lab2_templates(): array
{
    $P = 'P';
    $L = 'L';

    return array(
        /* --- 1 photo. The sketches also show a square single; the library has
         * no square photo, so transcribing it would add a template that can
         * never fire. Left out on purpose, not overlooked. */
        'single-P' => array(
            'slots' => array($P),
            'tree'  => array('t' => 'leaf', 'i' => 0),
            'note'  => 'one portrait, centred',
        ),
        'single-L' => array(
            'slots' => array($L),
            'tree'  => array('t' => 'leaf', 'i' => 0),
            'note'  => 'one landscape, centred',
        ),

        /* --- 2 photos */
        'pair-PP' => array(
            'slots' => array($P, $P),
            'tree'  => array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
            'note'  => 'two portraits side by side',
        ),
        'pair-LL' => array(
            'slots' => array($L, $L),
            'tree'  => array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
            'note'  => 'two landscapes stacked',
        ),
        'pair-LP' => array(
            'slots' => array($L, $P),
            'tree'  => array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
            'note'  => 'landscape left, portrait right',
        ),
        'pair-PL' => array(
            'slots' => array($P, $L),
            'tree'  => array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
            'note'  => 'portrait left, landscape right',
        ),

        /* --- 3 photos */
        'row-PPP' => array(
            'slots' => array($P, $P, $P),
            'tree'  => array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            'note'  => 'three portraits in a row',
        ),
        'col-LLL' => array(
            'slots' => array($L, $L, $L),
            'tree'  => array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            'note'  => 'three landscapes in a column',
        ),
        'heroP-PP' => array(
            'slots' => array($P, $P, $P),
            'tree'  => array('t' => 'row', 'k' => array(
                array('t' => 'leaf', 'i' => 0),
                array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            )),
            'note'  => 'big portrait left, two portraits stacked right',
        ),
        'heroP-PL' => array(
            'slots' => array($P, $P, $L),
            'tree'  => array('t' => 'row', 'k' => array(
                array('t' => 'leaf', 'i' => 0),
                array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            )),
            'note'  => 'big portrait left, portrait over landscape right',
        ),
        'bandL-LL' => array(
            'slots' => array($L, $L, $L),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'leaf', 'i' => 0),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            )),
            'note'  => 'wide landscape on top, two beneath',
        ),

        /* --- 4 photos */
        'quad-PPPP' => array(
            'slots' => array($P, $P, $P, $P),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            /* Not on a sticky note. Added on Kathryn's instruction after she saw
             * that all four drawn 4-ups need a landscape, which would have
             * capped 20 of her 36 event groups at three photos a page. Four
             * portraits in a 2x2 make one large rectangle, which is her phrase
             * for it and an accurate description of the block it produces. */
            'note'  => 'four portraits, 2x2',
        ),
        'quad-PPLL' => array(
            'slots' => array($P, $P, $L, $L),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            'note'  => 'two portraits over two landscapes',
        ),
        'pinwheel' => array(
            'slots' => array($P, $L, $L, $P),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            /* Two of the sticky notes show an asymmetric 2x2 with a portrait and
             * a landscape in each row. They differ only in which photo is drawn
             * biggest — and with cropping off the table, relative size is a
             * consequence of the photos' own ratios, not something a template
             * can dial. So they transcribe to the same entry. Flagged for
             * Kathryn rather than faked into two. */
            'note'  => 'portrait/landscape, landscape/portrait',
        ),
        'col3L-heroP' => array(
            'slots' => array($L, $L, $L, $P),
            'tree'  => array('t' => 'row', 'k' => array(
                array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
                array('t' => 'leaf', 'i' => 3),
            )),
            'note'  => 'three landscapes stacked left, big portrait right',
        ),

        /* --- DERIVED. Not transcribed from a sticky note.
         *
         * Kathryn said she was open to other groupings that follow the
         * guidelines the sketches set up, and the sketches leave three shape
         * combinations with nowhere to go: P+L+L, P+P+P+L, and four landscapes.
         * A gap is not neutral — the partitioner's only escape from an
         * unmatchable run is to cut it into smaller pages, so a missing 4-up
         * quietly becomes a 2-up and a 2-up wherever that shape run occurs.
         *
         * Each of these reuses an arrangement already in the set with different
         * shapes in the slots; none invents a new idea. They are tagged so the
         * lab can show which pages depend on a template Kathryn never drew, and
         * removing any of them is a one-line delete. */
        'heroP-LL' => array(
            'slots' => array($P, $L, $L),
            'tree'  => array('t' => 'row', 'k' => array(
                array('t' => 'leaf', 'i' => 0),
                array('t' => 'col', 'k' => array(array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            )),
            'note'    => 'big portrait left, two landscapes stacked right',
            'derived' => true,
        ),
        'bandL-PP' => array(
            'slots' => array($L, $P, $P),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'leaf', 'i' => 0),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 1), array('t' => 'leaf', 'i' => 2))),
            )),
            'note'    => 'wide landscape on top, two portraits beneath',
            'derived' => true,
        ),
        'quad-PPPL' => array(
            'slots' => array($P, $P, $P, $L),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            'note'    => 'three portraits and a landscape, 2x2',
            'derived' => true,
        ),
        'quad-PPLP' => array(
            'slots' => array($P, $P, $L, $P),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            /* quad-PPPL with the bottom row flipped. Asked for so the most-used
             * 4-up has two versions rather than one, which matters because it
             * carries a fifth of the book: the template-rotation pass can now
             * alternate them and the same arrangement stops reappearing on
             * consecutive pages. */
            'note'    => 'three portraits and a landscape, landscape at bottom left',
            'derived' => true,
        ),
        'quad-LLLL' => array(
            'slots' => array($L, $L, $L, $L),
            'tree'  => array('t' => 'col', 'k' => array(
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 0), array('t' => 'leaf', 'i' => 1))),
                array('t' => 'row', 'k' => array(array('t' => 'leaf', 'i' => 2), array('t' => 'leaf', 'i' => 3))),
            )),
            'note'    => 'four landscapes, 2x2',
            'derived' => true,
        ),
    );
}

/* ------------------------------------------------------------ partitioner */

/**
 * Can this run of photos fill this template, and if so in what order?
 *
 * Returns the photo indices in SLOT order, or null. Slots are filled in reading
 * order, each taking the earliest still-unused photo of the shape it demands,
 * so a page stays as close to chronological as its template allows. Exact
 * chronology is impossible in general — 'pair-LP' shows the landscape first
 * whichever photo was taken first — and page-internal order matters far less
 * than the order of the pages themselves.
 */
function lab2_fill(array $run, array $tpl): ?array
{
    if (count($run) !== count($tpl['slots'])) { return null; }

    $used = array();
    $out  = array();

    foreach ($tpl['slots'] as $want) {
        $pick = null;
        foreach ($run as $i => $p) {
            if (isset($used[$i])) { continue; }
            if ($p['shape'] === $want) { $pick = $i; break; }
        }
        if ($pick === null) { return null; }
        $used[$pick] = true;
        $out[] = $pick;
    }

    return $out;
}

/**
 * Break one event group into pages. Exact DP over the chronological sequence —
 * the group is at most 13 photos and pages are at most 4, so the state space is
 * trivial and there is no reason to approximate.
 *
 * Pages must take CONSECUTIVE photos: the book is a chronological record, and a
 * partition that reordered across pages to get prettier shape matches would
 * silently rewrite the day. The cost function only chooses between partitions
 * that all preserve the order.
 *
 * Cost is per page, so the baseline behaviour is "use as few pages as possible".
 * Two adjustments on top:
 *   - repeating a template on consecutive pages is penalised, because a book
 *     that solves every page optimally in isolation reads as a contact sheet;
 *   - a single-photo page inside a multi-photo group is penalised lightly, so
 *     singles land where the shapes genuinely force them rather than as the
 *     partitioner's easy way out.
 */
function lab2_partition(array $run, array $templates): array
{
    $n = count($run);
    if ($n === 0) { return array(); }

    $byCount = array();
    foreach ($templates as $name => $tpl) {
        $byCount[count($tpl['slots'])][$name] = $tpl;
    }

    /* best[i] = list of candidate (cost, pages) reaching position i, keyed by
     * the template used for the page that ends at i, so the repeat penalty can
     * look one page back. */
    $best = array(0 => array('' => array('cost' => 0.0, 'pages' => array())));

    for ($i = 0; $i < $n; $i++) {
        if (!isset($best[$i])) { continue; }
        for ($len = 1; $len <= 4 && $i + $len <= $n; $len++) {
            $slice = array_slice($run, $i, $len);
            foreach (($byCount[$len] ?? array()) as $name => $tpl) {
                $order = lab2_fill($slice, $tpl);
                if ($order === null) { continue; }

                foreach ($best[$i] as $prevName => $state) {
                    $cost = $state['cost'] + 1.0;
                    if ($prevName === $name)       { $cost += 0.5; }
                    if ($len === 1 && $n > 1)      { $cost += 0.25; }

                    $ids = array();
                    foreach ($order as $k) { $ids[] = $slice[$k]['id']; }

                    $page  = array('tpl' => $name, 'ids' => $ids);
                    $pages = $state['pages'];
                    $pages[] = $page;

                    $j = $i + $len;
                    if (!isset($best[$j][$name]) || $best[$j][$name]['cost'] > $cost) {
                        $best[$j][$name] = array('cost' => $cost, 'pages' => $pages);
                    }
                }
            }
        }
    }

    if (!isset($best[$n])) {
        /* Unreachable: single-P and single-L between them accept any photo, so
         * an all-singles partition always exists. Kept as a loud failure rather
         * than a silent empty group in case a future template edit removes one
         * of the singles. */
        fwrite(STDERR, "PARTITION FAILED for run of $n\n");
        return array();
    }

    $win = null;
    foreach ($best[$n] as $state) {
        if ($win === null || $state['cost'] < $win['cost']) { $win = $state; }
    }
    return $win['pages'];
}

/* --------------------------------------------------------- build the plan */

$templates = lab2_templates();

$groups = array();
foreach ($photos as $p) {
    $key = $p['group'] ?? ('solo-' . $p['id']);
    $groups[$key][] = $p;
}

/* Lone photos from adjacent event groups share a page. Carried over unchanged
 * from lab 1, where Kathryn reviewed the behaviour and said the groupings were
 * good. Still marked on the page so it stays reviewable rather than becoming
 * invisible policy. */
$ordered = array_values($groups);
$pages   = array();
$pending = null;

foreach ($ordered as $run) {
    if (count($run) === 1) {
        if ($pending === null) {
            $pending = $run[0];
            continue;
        }
        $twin = array($pending, $run[0]);
        $done = false;
        foreach ($templates as $name => $tpl) {
            if (count($tpl['slots']) !== 2) { continue; }
            $order = lab2_fill($twin, $tpl);
            if ($order === null) { continue; }
            $ids = array();
            foreach ($order as $k) { $ids[] = $twin[$k]['id']; }
            $pages[] = array('tpl' => $name, 'ids' => $ids, 'cross' => true);
            $done    = true;
            break;
        }
        if (!$done) {
            $pages[] = array('tpl' => 'single-' . $pending['shape'], 'ids' => array($pending['id']), 'cross' => false);
            $pages[] = array('tpl' => 'single-' . $run[0]['shape'], 'ids' => array($run[0]['id']), 'cross' => false);
        }
        $pending = null;
        continue;
    }

    if ($pending !== null) {
        $pages[] = array('tpl' => 'single-' . $pending['shape'], 'ids' => array($pending['id']), 'cross' => false);
        $pending = null;
    }

    foreach (lab2_partition($run, $templates) as $page) {
        $page['cross'] = false;
        $pages[] = $page;
    }
}
if ($pending !== null) {
    $pages[] = array('tpl' => 'single-' . $pending['shape'], 'ids' => array($pending['id']), 'cross' => false);
}

/* --------------------------------------------------- spread the templates

 * The partitioner chooses how many photos share a page. It should NOT also be
 * choosing which of several interchangeable templates draws them: several
 * templates accept the same shapes ('row-PPP' and 'heroP-PP' both want three
 * portraits), so a cost function that rates them equally picks whichever it
 * enumerated first and the other never appears. The first run of this lab used
 * 11 of 19 templates for exactly that reason.
 *
 * So template choice is a separate pass, made after the page sizes are fixed:
 * among the templates that accept this page's shapes, take the one used least
 * recently, breaking ties on least-used overall. Packing stays optimal and
 * interchangeable layouts rotate instead of one of them winning permanently.
 */
$lastUsed  = array();
$totalUsed = array();
$seq       = 0;

foreach ($pages as $idx => $page) {
    $ids   = $page['ids'];
    $byId  = array();
    foreach ($photos as $p) { $byId[$p['id']] = $p; }

    $run = array();
    foreach ($ids as $id) { $run[] = $byId[$id]; }

    $best = null;
    foreach ($templates as $name => $tpl) {
        if (count($tpl['slots']) !== count($run)) { continue; }
        $order = lab2_fill($run, $tpl);
        if ($order === null) { continue; }

        $rank = array($lastUsed[$name] ?? -1, $totalUsed[$name] ?? 0);
        if ($best === null || $rank < $best['rank']) {
            $best = array('rank' => $rank, 'name' => $name, 'order' => $order);
        }
    }
    if ($best === null) { continue; }

    $newIds = array();
    foreach ($best['order'] as $k) { $newIds[] = $run[$k]['id']; }

    $pages[$idx]['tpl'] = $best['name'];
    $pages[$idx]['ids'] = $newIds;

    $lastUsed[$best['name']]  = $seq++;
    $totalUsed[$best['name']] = ($totalUsed[$best['name']] ?? 0) + 1;
}

/* ------------------------------------------------------------- catalogue

 * One rendering of every template, on real photos, whether or not the book
 * happens to use it. Kathryn is reviewing the template set as much as the book,
 * and a template the partitioner never reaches is still a decision she needs to
 * see — either to keep it for a future year's photos or to strike it. Slots are
 * filled from the front of the library, so this is a shape demo, not a
 * suggestion about which photos belong together.
 */
$catalogue = array();
foreach ($templates as $name => $tpl) {
    $take = array('P' => 0, 'L' => 0);
    $pool = array('P' => array(), 'L' => array());
    foreach ($photos as $p) { $pool[$p['shape']][] = $p['id']; }

    $ids = array();
    $ok  = true;
    foreach ($tpl['slots'] as $want) {
        if (!isset($pool[$want][$take[$want]])) { $ok = false; break; }
        $ids[] = $pool[$want][$take[$want]++];
    }
    if ($ok) {
        $catalogue[] = array('tpl' => $name, 'ids' => $ids, 'cross' => false);
    }
}

/* --------------------------------------------------------------- emit */

$photoJs = array();
foreach ($photos as $p) {
    $bin = @file_get_contents($p['file']);
    if ($bin === false) { continue; }
    $photoJs[$p['id']] = array(
        'ar'  => round($p['w'] / $p['h'], 6),
        's'   => $p['shape'],
        'src' => 'data:image/webp;base64,' . base64_encode($bin),
    );
}

$tplJs = array();
foreach ($templates as $name => $tpl) {
    $tplJs[$name] = array(
        'tree'    => $tpl['tree'],
        'note'    => $tpl['note'],
        'n'       => count($tpl['slots']),
        'derived' => !empty($tpl['derived']),
    );
}

$used = array();
foreach ($pages as $pg) { $used[$pg['tpl']] = ($used[$pg['tpl']] ?? 0) + 1; }
ksort($used);

$stats = array(
    'photos' => count($photoJs),
    'pages'  => count($pages),
    'usage'  => $used,
);

$shell = file_get_contents(__DIR__ . '/layout-lab2-shell.html');
$html  = str_replace(
    array('/*PHOTOS*/', '/*TEMPLATES*/', '/*PAGES*/', '/*STATS*/', '/*CATALOGUE*/'),
    array(
        json_encode($photoJs),
        json_encode($tplJs),
        json_encode($pages),
        json_encode($stats),
        json_encode($catalogue),
    ),
    $shell
);

file_put_contents($opt['out'], $html);

fwrite(STDERR, sprintf("%d photos, %d pages\n", count($photoJs), count($pages)));
foreach ($used as $name => $n) {
    fwrite(STDERR, sprintf("  %-14s %d\n", $name, $n));
}
