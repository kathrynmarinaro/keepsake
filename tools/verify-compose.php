<?php
/* Invariants for lib/compose.php, the second-generation page composer.
 *
 * These are the same four checks tools/verify-lab2.mjs runs against the
 * prototype's JavaScript solver, restated against the PHP one. The point of
 * keeping both is that the lab is what Kathryn actually reviewed and signed
 * off: if the two solvers ever disagree, the book stops matching the proof she
 * approved, and this file is where that shows up.
 *
 * Run: php tools/verify-compose.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/compose.php';

$fails = 0;
$checks = 0;

function bad(string $msg): void
{
    global $fails;
    $fails++;
    fwrite(STDERR, "  FAIL $msg\n");
}

/* A spread of real ratios from Kathryn's library: 3:4 and 4:3 dominate, with
 * the handful of oddities that caused every complaint so far — the 9:16 that
 * made page 11 look wrong, and the 0.681 that knocked page 10's divider off
 * centre. Fixtures worth keeping literal; they are the actual failures. */
$RATIOS = array('P' => array(0.75, 0.75, 0.681, 0.5625, 0.752), 'L' => array(4 / 3, 4 / 3, 1.778, 1.5));

/** Every way to fill a template's slots from the fixture ratios, capped. */
function occupant_sets(array $slots, array $ratios, bool $withCard): array
{
    $sets = array(array());
    foreach ($slots as $si => $shape) {
        $next = array();
        foreach ($sets as $set) {
            foreach ($ratios[$shape] as $ar) {
                $set[] = array('shape' => $shape, 'ar' => $ar);
                $next[] = $set;
                array_pop($set);
            }
        }
        $sets = array_slice($next, 0, 400);
    }
    if ($withCard && $slots !== array()) {
        /* A card in the first slot, adopting that slot's canonical ratio — the
         * behaviour Kathryn chose when asked how cards should sit under
         * shape-required templates. */
        foreach ($sets as $i => $set) {
            $set[0] = array('shape' => '*', 'ar' => COMPOSE_CANON[$slots[0]]);
            $sets[$i] = $set;
        }
    }
    return $sets;
}

foreach (compose_templates() as $name => $tpl) {
    foreach (array(false, true) as $withCard) {
        foreach (occupant_sets($tpl['slots'], $RATIOS, $withCard) as $occ) {
            foreach (array(0.60, 0.72, 0.85) as $fill) {
                $rects = compose_solve($tpl, $occ, $fill);
                $where = sprintf('%s fill=%.2f%s', $name, $fill, $withCard ? ' +card' : '');
                $checks++;

                if (count($rects) !== count($tpl['slots'])) {
                    bad("$where: " . count($rects) . ' rects, want ' . count($tpl['slots']));
                    continue;
                }

                foreach ($rects as $r) {
                    $o    = $occ[$r['slot']];
                    $got  = $r['w'] / $r['h'];
                    $want = $o['ar'];

                    /* 1. An occupant is only ever reshaped to match same-shape
                     * neighbours, and the rect must then agree with the crop it
                     * claims. Anything reporting no crop keeps its exact ratio. */
                    if ($r['crop'] <= 0.0 && $o['shape'] !== '*' && abs($got - $want) / $want > 2e-3) {
                        bad(sprintf('%s: slot %d ratio %.4f != %.4f with crop 0', $where, $r['slot'], $got, $want));
                    }
                    if ($r['crop'] > 0.0) {
                        $claimed = 1.0 - min($got, $want) / max($got, $want);
                        if (abs($claimed - $r['crop']) > 2e-3) {
                            bad(sprintf('%s: slot %d crop %.4f != actual %.4f', $where, $r['slot'], $r['crop'], $claimed));
                        }
                    }

                    // 2. On the page, and real.
                    if ($r['x'] < -1e-4 || $r['y'] < -1e-4
                        || $r['x'] + $r['w'] > 100 + 1e-4 || $r['y'] + $r['h'] > 100 + 1e-4) {
                        bad(sprintf('%s: slot %d out of bounds %.2f,%.2f %.2fx%.2f',
                            $where, $r['slot'], $r['x'], $r['y'], $r['w'], $r['h']));
                    }
                    if ($r['w'] <= 0 || $r['h'] <= 0) {
                        bad("$where: slot {$r['slot']} non-positive size");
                    }
                }

                // 3. Nothing overlaps.
                foreach ($rects as $a => $p) {
                    foreach (array_slice($rects, $a + 1) as $q) {
                        $ox = min($p['x'] + $p['w'], $q['x'] + $q['w']) - max($p['x'], $q['x']);
                        $oy = min($p['y'] + $p['h'], $q['y'] + $q['h']) - max($p['y'], $q['y']);
                        if ($ox > 0.01 && $oy > 0.01) {
                            bad(sprintf('%s: slots %d/%d overlap by %.3fx%.3f', $where, $p['slot'], $q['slot'], $ox, $oy));
                        }
                    }
                }

                /* 4. The complaint that drove the whole rule: same-shape photos
                 * sitting together must come out the same size. */
                $groups = array();
                foreach ($rects as $r) {
                    if (isset($r['group'])) { $groups[$r['group']][] = $r; }
                }
                foreach ($groups as $g => $cells) {
                    foreach ($cells as $c) {
                        if (abs($c['w'] - $cells[0]['w']) > 1e-6 || abs($c['h'] - $cells[0]['h']) > 1e-6) {
                            bad(sprintf('%s: matched group %d has cells of different size', $where, $g));
                            break;
                        }
                    }
                }
            }
        }
    }
}

/* A mixed row must NOT be normalised — the page-21 rejection. A landscape and a
 * portrait side by side share one height and keep their own widths. */
$tpl  = compose_templates()['pair-LP'];
$occ  = array(array('shape' => 'L', 'ar' => 4 / 3), array('shape' => 'P', 'ar' => 0.75));
$r    = compose_solve($tpl, $occ);
$checks++;
if (abs($r[0]['h'] - $r[1]['h']) > 1e-6) { bad('pair-LP: mixed row should share one height'); }
if ($r[0]['w'] <= $r[1]['w'])            { bad('pair-LP: landscape should be wider than the portrait'); }
if ($r[0]['crop'] > 0 || $r[1]['crop'] > 0) { bad('pair-LP: mixed row must never crop'); }

/* And the bottom band of a 2x2 whose row is mixed is allowed to be shorter than
 * the top band, which is what Kathryn asked for in as many words. */
$tpl = compose_templates()['quad-PPLP'];
$occ = array(
    array('shape' => 'P', 'ar' => 0.75), array('shape' => 'P', 'ar' => 0.75),
    array('shape' => 'L', 'ar' => 4 / 3), array('shape' => 'P', 'ar' => 0.75),
);
$r = compose_solve($tpl, $occ);
$checks++;
if (abs($r[0]['h'] - $r[1]['h']) > 1e-6)  { bad('quad-PPLP: top row cells should match'); }
if (abs($r[2]['h'] - $r[3]['h']) > 1e-6)  { bad('quad-PPLP: bottom row should share one height'); }
if ($r[3]['h'] >= $r[0]['h'])             { bad('quad-PPLP: lower portrait should be shorter than the upper ones'); }
foreach ($r as $x) { if ($x['crop'] > 0) { bad('quad-PPLP: all-canonical page should not crop'); } }

/* compose_fill(): a card takes any slot, but only after the photos have had
 * their pick, so a card cannot strand a photo that had one place to go. */
$order = compose_fill(
    array(array('shape' => '*', 'ar' => 0.75), array('shape' => 'L', 'ar' => 4 / 3)),
    compose_templates()['pair-LP']
);
$checks++;
if ($order !== array(1, 0)) { bad('compose_fill: card should yield the L slot to the real landscape'); }

if (compose_fill(array(array('shape' => 'P', 'ar' => 0.75)), compose_templates()['single-L']) !== null) {
    bad('compose_fill: a portrait must not satisfy a landscape slot');
}
$checks++;

/* --------------------------------------------- occupants, binding, assignment */

/* A photo with no stored dimensions must still reach the page. It becomes a
 * wildcard rather than a guess, which is the fail-soft branch this app asks for
 * everywhere: one bad row degrades itself, never the screen. */
$occ = compose_occupants(array(
    array('photo_id' => 1, 'width' => 3024, 'height' => 4032),
    array('photo_id' => 2, 'width' => 4032, 'height' => 3024),
    array('photo_id' => 3, 'width' => 0, 'height' => 0),
    array('photo_id' => null, 'quote_id' => 9),
));
$checks++;
if (array_column($occ, 'shape') !== array('P', 'L', '*', '*')) {
    bad('compose_occupants: got ' . implode(',', array_column($occ, 'shape')));
}

/* A card between two portraits must not break the matched group. It takes the
 * SHAPE of its slot, not just a ratio — without that the row falls back to
 * ratio widths and the cells stop agreeing, which is the page-10 fault. */
$tpl   = compose_templates()['row-PPP'];
$occ   = array(
    array('shape' => 'P', 'ar' => 0.75),
    array('shape' => '*', 'ar' => 0.75),
    array('shape' => 'P', 'ar' => 0.681),
);
$order = compose_fill($occ, $tpl);
$checks++;
if ($order === null) {
    bad('compose_fill: a card should satisfy a portrait slot');
} else {
    $bound = compose_bind($occ, $tpl, $order);
    if (array_column($bound, 'shape') !== array('P', 'P', 'P')) {
        bad('compose_bind: wildcard did not take its slot shape');
    }
    $rects = compose_solve($tpl, $bound);
    $w = $rects[0]['w'];
    foreach ($rects as $r) {
        if (abs($r['w'] - $w) > 1e-6) {
            bad('compose_bind: a card broke the matched group');
            break;
        }
    }
}

/* Interchangeable templates must rotate. row-PPP and heroP-PP both take three
 * portraits; picking whichever enumerated first is what left the lab using 11
 * of 19 templates, and a book of portraits looking like one page repeated. */
$three   = array_fill(0, 3, array('shape' => 'P', 'ar' => 0.75));
$assign  = compose_assign(array($three, $three, $three, $three));
$names   = array_column($assign, 'name');
$checks++;
if (count(array_unique(array_slice($names, 0, 2))) !== 2) {
    bad('compose_assign: two identical pages in a row used the same template (' . implode(',', $names) . ')');
}
$checks++;
if (compose_assign(array($three, $three, $three, $three)) !== $assign) {
    bad('compose_assign: not deterministic — the preview and the PDF would disagree');
}

/* A page nothing can draw reports null rather than a wrong template. */
$checks++;
if (compose_assign(array(array_fill(0, 4, array('shape' => 'L', 'ar' => 4 / 3)),
                         array(array('shape' => 'P', 'ar' => 0.75)),
                   ))[0] === null) {
    bad('compose_assign: four landscapes do have a template');
}

printf("%d checks\n", $checks);
if ($fails > 0) {
    printf("FAILED (%d)\n", $fails);
    exit(1);
}
print "OK — ratios honoured, matched groups identical, mixed rows untouched, nothing overlaps\n";
