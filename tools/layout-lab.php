<?php
/**
 * LAYOUT LAB — a prototyping harness, NOT the shipping engine.
 *
 * Renders the same real photo set through several candidate layout "voices"
 * and writes ONE self-contained HTML file to compare them side by side. Runs
 * entirely offline against exported CSV metadata plus a folder of thumbnails —
 * no database, no deploy, no browser needed to produce it.
 *
 * WHY THIS EXISTS. Five rounds of layout tuning happened by description: Kathryn
 * looked at a generated book on her phone, said what was wrong, and I changed a
 * number. That loop is slow and lossy — nobody could see the alternatives, only
 * the one thing that shipped. This file makes the loop "run it, look at three
 * options, point at one", which is the only way taste questions ever get
 * settled.
 *
 * DELIBERATELY SEPARATE FROM lib/layout.php. The production engine keeps
 * working while variants are explored here. Only the winning voice gets ported
 * back, and porting it is a real code review, not a copy-paste — this file
 * optimises for being easy to change, not for being correct at the edges.
 *
 * The one thing it DOES share is layout_orientation(), so "portrait" means the
 * same thing here as in the real book.
 *
 * Usage:
 *   php tools/layout-lab.php --photos=P.csv --groups=G.csv \
 *       --thumbs=DIR --map=thumb-map.json --out=lab.html
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/grouping.php';
require __DIR__ . '/../lib/layout.php';

/* ------------------------------------------------------------------ input */

$opt = getopt('', array('photos:', 'groups:', 'thumbs:', 'map:', 'out:', 'maxedge::'));
foreach (array('photos', 'groups', 'thumbs', 'map', 'out') as $need) {
    if (!isset($opt[$need])) {
        fwrite(STDERR, "missing --$need\n");
        exit(1);
    }
}
$MAX_EDGE = (int) ($opt['maxedge'] ?? 320);

function lab_csv(string $file): array
{
    $rows = array();
    $head = null;
    $fh = fopen($file, 'r');
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
foreach (lab_csv($opt['photos']) as $row) {
    if ((int) $row['skip_for_book'] === 1) { continue; }
    $id   = (int) $row['id'];
    $file = $thumbMap[$id] ?? null;
    if ($file === null) { continue; }

    $abs = rtrim($opt['thumbs'], '/') . '/' . $file;
    $dim = @getimagesize($abs);
    if ($dim === false) { continue; }

    /* The THUMBNAIL's own dimensions are the source of truth for orientation
     * here, not the CSV's width/height. The id->file mapping is recovered
     * heuristically (see the session notes), so a few photos may be paired with
     * the wrong file; deriving orientation from the file that will actually be
     * DRAWN keeps every page internally consistent — a slot never receives a
     * photo of a shape the template didn't plan for, which would look like a
     * layout bug while being a data bug. */
    $photos[] = array(
        'id'             => $id,
        'captured_at'    => $row['captured_at'],
        'width'          => $dim[0],
        'height'         => $dim[1],
        'event_group_id' => ($row['event_group_id'] === '' ? null : (int) $row['event_group_id']),
        'full_page'      => (int) $row['full_page'],
        'file'           => $abs,
        'orient'         => layout_orientation(array('width' => $dim[0], 'height' => $dim[1])),
    );
}
usort($photos, fn(array $a, array $b): int => array($a['captured_at'], $a['id']) <=> array($b['captured_at'], $b['id']));

/* ------------------------------------------------------- template library */

/**
 * A template is a named page shape: a list of slots in NORMALISED page space,
 * resolved from a simple rows/columns spec so one definition works at any
 * margin and gutter. This is the industry approach (see the research artifact):
 * a person decides what a good three-photo page looks like, once, and the
 * generator's job shrinks to CHOOSING a template rather than inventing geometry.
 *
 * 'rows' is a list of [rowWeight, [slotWeight, ...]].
 * 'fit'  is 'cover' (fill the slot, small crop) or 'contain' (whole photo,
 *        white space around it) — the breather look.
 * 'bleed' drops the page margin entirely for the FIRST slot, running it to the
 *        page edge. Single-page only; nothing here ever crosses the gutter.
 */
function lab_templates(): array
{
    return array(
        // --- 1 photo
        'bleed-single'   => array('n' => 1, 'fit' => 'cover',   'bleed' => true,
                                  'rows' => array(array(1, array(1)))),
        'frame-single'   => array('n' => 1, 'fit' => 'contain', 'bleed' => false,
                                  'rows' => array(array(1, array(1)))),

        // --- 2 photos
        'pair-side'      => array('n' => 2, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1, 1)))),
        'pair-stack'     => array('n' => 2, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1)), array(1, array(1)))),
        'pair-mixed'     => array('n' => 2, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1.5, 1)))),
        'companion'      => array('n' => 2, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(2.2, 1)))),

        // --- 3 photos
        'band-three'     => array('n' => 3, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1, 1, 1)))),
        'stack-three'    => array('n' => 3, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1)), array(1, array(1)), array(1, array(1)))),
        'hero-two'       => array('n' => 3, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1.7, array(1)), array(1, array(1, 1)))),
        'two-hero'       => array('n' => 3, 'fit' => 'cover', 'bleed' => false,
                                  'rows' => array(array(1, array(1, 1)), array(1.7, array(1)))),
        'bleed-inset'    => array('n' => 3, 'fit' => 'cover', 'bleed' => true,
                                  'rows' => array(array(1.6, array(1)), array(1, array(1, 1)))),
    );
}

/**
 * Resolve a template into absolute percentage rects. Pure.
 *
 * A bleed template's first slot is expanded back out to the full page: it keeps
 * its row's share of the height but ignores the margin on the left, right and
 * top, which is what makes it read as "running off the edge" rather than "a
 * slightly bigger photo".
 */
function lab_resolve(array $tpl, float $margin, float $gutter): array
{
    $rows      = $tpl['rows'];
    $rowWeight = array_sum(array_column($rows, 0));
    $inner     = 100.0 - (2 * $margin);
    $vGaps     = $gutter * (count($rows) - 1);

    $out = array();
    $y   = $margin;

    foreach ($rows as $r => $row) {
        list($rw, $slots) = $row;
        $h = ($inner - $vGaps) * ($rw / $rowWeight);

        $slotWeight = array_sum($slots);
        $hGaps      = $gutter * (count($slots) - 1);
        $x          = $margin;

        foreach ($slots as $s => $sw) {
            $w    = ($inner - $hGaps) * ($sw / $slotWeight);
            $rect = array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);

            if (!empty($tpl['bleed']) && $r === 0 && $s === 0) {
                /* Extend back out past the margin on every edge this slot
                 * actually touches. A bleed slot in a MULTI-row template still
                 * stops at its own row's bottom edge, so the framed photos
                 * below it keep their margin; a bleed slot that is the whole
                 * template covers the page outright, which is the difference
                 * between "a big photo" and "no border". */
                $bottom = (count($rows) === 1) ? 100.0 : ($h + $margin);
                $rect   = array('x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => $bottom);
            }

            $out[] = $rect;
            $x += $w + $gutter;
        }
        $y += $h + $gutter;
    }

    return $out;
}

/* --------------------------------------------------------------- variants */

/**
 * Each variant is a complete design voice, not a parameter tweak — margins,
 * bleed policy, which templates it may use, and (the part Kathryn asked to
 * judge by eye rather than in the abstract) its own rule for when a photo earns
 * a page to itself.
 */
function lab_variants(): array
{
    return array(
        'A' => array(
            'name'    => 'Quiet',
            'blurb'   => 'Generous margins, no bleed, photos floating in white space. Every event opens on a breather.',
            'margin'  => 9.0,
            'gutter'  => 4.0,
            'breather' => 'opener',      // first photo of any group of 3+
            'bleed'   => false,
            'prefer3' => false,
        ),
        'B' => array(
            'name'    => 'Editorial',
            'blurb'   => 'Full-bleed openers on big events, hero-and-support hierarchy, tighter margins. Breathers placed for pacing.',
            'margin'  => 6.0,
            'gutter'  => 3.0,
            'breather' => 'pacing',      // after a run of dense pages
            'bleed'   => true,
            'prefer3' => false,
        ),
        'C' => array(
            'name'    => 'Dense',
            'blurb'   => 'Minimal margins, mostly three-ups, photos nearly touching. Breathers only where you flag a photo.',
            'margin'  => 3.5,
            'gutter'  => 1.5,
            'breather' => 'flagged',     // full_page only
            'bleed'   => false,
            'prefer3' => true,
        ),
    );
}

/* ------------------------------------------------------------- the layout */

/** Sub-groups: event group first, then the same close-timing split production uses. */
function lab_subgroups(array $photos): array
{
    $buckets = array();
    foreach ($photos as $p) {
        $key = $p['event_group_id'] !== null ? 'g' . $p['event_group_id'] : 'u';
        $buckets[$key][] = $p;
    }

    $subgroups = array();
    foreach ($buckets as $rows) {
        foreach (layout_subgroup_photos($rows, 5.0) as $cluster) {
            $subgroups[] = array(
                'photos' => $cluster,
                'start'  => $cluster[0]['captured_at'],
                'event'  => $cluster[0]['event_group_id'],
            );
        }
    }
    usort($subgroups, fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
    return $subgroups;
}

/**
 * Kathryn's call (see the research round): a lone photo may now pair ACROSS
 * event boundaries, with the nearest group in time. Her real 2025 data has 13
 * single-photo event groups out of 35 — every one of them was becoming a
 * one-photo page, which is what "too many pages with only one photo" actually
 * meant. Merging only within an event (what production does today) cannot touch
 * them, because a group of one has no siblings.
 *
 * The visiting photo is TAGGED, so the page it lands on can render it as a
 * companion rather than an equal partner — the page keeps one subject and the
 * stray reads as "and also this", not as a second unrelated headline.
 */
function lab_merge_lone(array $subgroups): array
{
    $list = array_values($subgroups);

    for ($i = 0; $i < count($list); ) {
        if (count($list[$i]['photos']) !== 1) { $i++; continue; }

        $lone = $list[$i];
        $prev = $i > 0 ? $i - 1 : null;
        $next = $i + 1 < count($list) ? $i + 1 : null;

        $gap = static function (?int $j) use ($list, $lone): float {
            if ($j === null) { return INF; }
            $other = $list[$j]['photos'];
            return $j < 0 ? INF : min(
                layout_photo_hours_apart($other[count($other) - 1], $lone['photos'][0]),
                layout_photo_hours_apart($lone['photos'][0], $other[0])
            );
        };

        $into = null;
        if ($prev !== null && ($next === null || $gap($prev) <= $gap($next))) { $into = $prev; }
        elseif ($next !== null) { $into = $next; }

        if ($into === null) { $i++; continue; }

        $visitor = $lone['photos'][0];
        $visitor['visiting'] = true;

        $merged = array_merge($list[$into]['photos'], array($visitor));
        usort($merged, fn(array $a, array $b): int => strcmp($a['captured_at'], $b['captured_at']));
        $list[$into]['photos'] = $merged;

        array_splice($list, $i, 1);
        if ($into < $i) { $i = $into + 1; }
    }

    return $list;
}

/** Which template suits this page's photos, under this variant. */
function lab_pick_template(array $page, array $variant, int $spin): string
{
    $n       = count($page);
    $orients = array_column($page, 'orient');
    $ports   = count(array_filter($orients, fn(string $o): bool => $o !== 'landscape'));
    $lands   = $n - $ports;
    $visitor = count(array_filter($page, fn(array $p): bool => !empty($p['visiting']))) > 0;

    if ($n === 1) {
        return (!empty($page[0]['opener']) && $variant['bleed']) ? 'bleed-single' : 'frame-single';
    }

    if ($n === 2) {
        if ($visitor)        { return 'companion'; }
        if ($lands === 2)    { return 'pair-stack'; }
        if ($ports === 2)    { return 'pair-side'; }
        return 'pair-mixed';
    }

    // three
    if ($variant['bleed'] && $spin % 5 === 0) { return 'bleed-inset'; }
    if ($lands === 3)  { return 'stack-three'; }
    if ($ports === 3)  { return $spin % 3 === 2 ? 'hero-two' : 'band-three'; }
    return $spin % 2 === 0 ? 'hero-two' : 'two-hero';
}

/** One variant's whole book. */
function lab_build(array $subgroups, array $variant): array
{
    $pages = array();
    $dense = 0;   // consecutive multi-photo pages, for the 'pacing' breather rule
    $spin  = 0;

    foreach ($subgroups as $sg) {
        $photos = $sg['photos'];

        /* Breather selection — the axis Kathryn asked to judge by eye. Each
         * rule pulls AT MOST one photo out of the group to stand alone; the
         * rest partition normally underneath it. */
        $breather = null;
        $rule     = $variant['breather'];

        foreach ($photos as $k => $p) {
            if (!empty($p['full_page'])) { $breather = $k; break; }
        }
        if ($breather === null && $rule === 'opener' && count($photos) >= 3) {
            $breather = 0;
        }
        if ($breather === null && $rule === 'pacing' && $dense >= 4 && count($photos) >= 3) {
            $breather = 0;
        }
        if ($breather === null && $rule === 'pacing' && count($photos) >= 8) {
            $breather = 0;   // a big event earns an opener regardless of pacing
        }

        if ($breather !== null) {
            $solo = $photos[$breather];
            $solo['opener'] = true;
            array_splice($photos, $breather, 1);
            $pages[] = array('photos' => array($solo), 'event' => $sg['event']);
            $dense = 0;
        }

        // Partition the remainder into 2s and 3s (1 only if that is all there is).
        $n = count($photos);
        $sizes = array();
        if ($n === 1) {
            $sizes = array(1);
        } elseif ($n > 0) {
            $threes = 0;
            if ($variant['prefer3']) {
                $threes = intdiv($n, 3);
                if (($n - 3 * $threes) === 1 && $threes > 0) { $threes--; }
            } else {
                $r = $n % 2;
                $threes = ($r === 1) ? 1 : 0;
                if ($n === 3) { $threes = 1; }
            }
            $rest = $n - 3 * $threes;
            for ($i = 0; $i < $threes; $i++) { $sizes[] = 3; }
            for ($i = 0; $i < intdiv($rest, 2); $i++) { $sizes[] = 2; }
            if ($rest % 2 === 1) { $sizes[] = 1; }
            shuffle_stable($sizes);
        }

        $at = 0;
        foreach ($sizes as $size) {
            $pages[] = array('photos' => array_slice($photos, $at, $size), 'event' => $sg['event']);
            $at += $size;
            $dense = $size > 1 ? $dense + 1 : 0;
        }
    }

    foreach ($pages as $i => $page) {
        $pages[$i]['template'] = lab_pick_template($page['photos'], $variant, $spin++);
    }

    return $pages;
}

/** Deterministic reordering so page sizes alternate a little instead of all 3s then all 2s. */
function shuffle_stable(array &$sizes): void
{
    sort($sizes);
    $out = array();
    $lo  = 0;
    $hi  = count($sizes) - 1;
    while ($lo <= $hi) {
        $out[] = $sizes[$hi--];
        if ($lo <= $hi) { $out[] = $sizes[$lo++]; }
    }
    $sizes = $out;
}

/* ------------------------------------------------------------- rendering */

/** Each thumbnail encoded ONCE as a CSS class, however many variants use it. */
function lab_image_css(array $photos, int $maxEdge): string
{
    $css = '';
    foreach ($photos as $p) {
        $src = @imagecreatefromwebp($p['file']);
        if ($src === false) { continue; }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagewebp($dst, null, 72);
        $bytes = (string) ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        $css .= '.i' . $p['id'] . '{background-image:url(data:image/webp;base64,'
             . base64_encode($bytes) . ')}';
    }
    return $css;
}

function lab_render_pages(array $pages, array $variant): string
{
    $templates = lab_templates();
    $html = '';

    foreach ($pages as $n => $page) {
        $tpl   = $templates[$page['template']];
        $rects = lab_resolve($tpl, (float) $variant['margin'], (float) $variant['gutter']);
        $fit   = $tpl['fit'];

        $html .= '<figure class="pg"><div class="paper">';
        foreach ($page['photos'] as $i => $p) {
            $r = $rects[$i] ?? $rects[count($rects) - 1];
            $cls = 'sl i' . $p['id'] . ($fit === 'contain' ? ' contain' : '');
            if (!empty($p['visiting'])) { $cls .= ' visiting'; }
            $html .= '<div class="' . $cls . '" style="left:' . round($r['x'], 2) . '%;top:' . round($r['y'], 2)
                  . '%;width:' . round($r['w'], 2) . '%;height:' . round($r['h'], 2) . '%"></div>';
        }
        $html .= '</div><figcaption><span>' . ($n + 1) . '</span> ' . htmlspecialchars($page['template']) . '</figcaption></figure>';
    }

    return $html;
}

/* ---------------------------------------------------------------- output */

$subgroups = lab_merge_lone(lab_subgroups($photos));
$variants  = lab_variants();

$sections = '';
$summary  = array();

foreach ($variants as $key => $variant) {
    $pages = lab_build($subgroups, $variant);

    $counts = array(1 => 0, 2 => 0, 3 => 0);
    foreach ($pages as $p) { $counts[count($p['photos'])] = ($counts[count($p['photos'])] ?? 0) + 1; }
    $summary[$key] = array('pages' => count($pages), 'counts' => $counts);

    $sections .= '<section class="variant" id="v' . $key . '">'
        . '<div class="vhead"><h2><b>' . $key . '</b> ' . htmlspecialchars($variant['name']) . '</h2>'
        . '<p>' . htmlspecialchars($variant['blurb']) . '</p>'
        . '<p class="stat mono">' . count($pages) . ' pages &middot; '
        . $counts[1] . ' single &middot; ' . $counts[2] . ' double &middot; ' . $counts[3] . ' triple'
        . ' &middot; margin ' . $variant['margin'] . '% &middot; breathers: ' . $variant['breather'] . '</p></div>'
        . '<div class="grid">' . lab_render_pages($pages, $variant) . '</div></section>';
}

$imageCss = lab_image_css($photos, $MAX_EDGE);

$doc = file_get_contents(__DIR__ . '/layout-lab-shell.html');
$doc = str_replace(
    array('/*IMAGES*/', '<!--SECTIONS-->', '<!--PHOTOCOUNT-->'),
    array($imageCss, $sections, (string) count($photos)),
    $doc
);

file_put_contents($opt['out'], $doc);

printf("wrote %s (%.1f MB)\n", $opt['out'], filesize($opt['out']) / 1048576);
foreach ($summary as $k => $s) {
    printf("  %s: %d pages — %d single, %d double, %d triple\n",
        $k, $s['pages'], $s['counts'][1], $s['counts'][2], $s['counts'][3]);
}
