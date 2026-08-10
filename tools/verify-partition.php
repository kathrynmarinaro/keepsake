<?php
/**
 * The partitioner's new obligation: every page it emits must be a page some
 * template can actually draw.
 *
 * Under the old engine a page was just "n photos" and any n from 1 to 4 was
 * renderable by construction. Under shape-required templates that is no longer
 * true — a page is n photos OF PARTICULAR SHAPES, and some combinations have no
 * template. So "did the partitioner produce something drawable" became a real
 * question, and an undrawable page is not a cosmetic bug: it is a page the
 * renderer cannot put photos on.
 *
 * Also checks the two properties that were free before and are not now: every
 * photo lands on exactly one page (a shape constraint must never be satisfied
 * by dropping a photo), and order is preserved (the book is a chronological
 * record).
 *
 * Usage: php tools/verify-partition.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/layout.php';

$failures = 0;
$checks   = 0;

function bad(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL $msg\n");
}

$tuning = layout_tuning();

/* Kathryn's real mix is 79% portrait, and the odd 'flex' turns up when a photo
 * has no stored dimensions. Deterministic sequence rather than rand(), so a
 * failure here is reproducible by running the file again. */
$mix = array('portrait', 'portrait', 'portrait', 'landscape', 'portrait', 'flex', 'landscape', 'portrait');

for ($n = 1; $n <= 14; $n++) {
    for ($offset = 0; $offset < count($mix); $offset++) {
        $orientations = array();
        for ($i = 0; $i < $n; $i++) {
            $orientations[] = $mix[($offset + $i) % count($mix)];
        }

        foreach (array(0, 1, 2) as $cards) {
            $pages = layout_partition_subgroup($orientations, $cards, array(), $tuning);
            $where = "n=$n offset=$offset cards=$cards";
            $checks++;

            $placed    = 0;
            $cardsSeen = 0;
            foreach ($pages as $page) {
                $size     = (int) $page['count'];
                $withCard = !empty($page['card']);
                if ($withCard) { $cardsSeen++; }

                if ($size + ($withCard ? 1 : 0) > LAYOUT_MAX_SLOTS) {
                    bad("$where: page holds " . ($size + 1) . ' occupants');
                }

                if ($size > 0) {
                    $shapes = layout_page_shapes($orientations, $placed, $size, $withCard);
                    if (!compose_accepts($shapes)) {
                        bad("$where: page of [" . implode(',', $shapes) . '] has no template');
                    }
                }
                $placed += $size;
            }

            if ($placed !== $n) {
                bad("$where: placed $placed photos of $n");
            }
            if ($cardsSeen !== $cards) {
                bad("$where: placed $cardsSeen cards of $cards");
            }
        }
    }
}

/* An all-landscape run is the awkward case: four landscapes have a template,
 * but three landscapes and a portrait need a specific one, and a partitioner
 * that guessed would strand them. */
foreach (array(3, 5, 7, 9) as $n) {
    $pages = layout_partition_subgroup(array_fill(0, $n, 'landscape'), 0, array(), $tuning);
    $placed = 0;
    $checks++;
    foreach ($pages as $page) {
        $shapes = layout_page_shapes(array_fill(0, $n, 'landscape'), $placed, (int) $page['count'], false);
        if ($page['count'] > 0 && !compose_accepts($shapes)) {
            bad("all-landscape n=$n: [" . implode(',', $shapes) . '] has no template');
        }
        $placed += (int) $page['count'];
    }
    if ($placed !== $n) { bad("all-landscape n=$n: placed $placed of $n"); }
}

/* A group with more photos than the ceiling must still use big pages when it
 * can — the regression that started this: the old scorer rated pages rather
 * than books, so with the ceiling opened to four it chose 2,1,2,1,2 over 4,4. */
$eight = layout_partition_subgroup(array_fill(0, 8, 'portrait'), 0, array(), $tuning);
$sizes = array_column($eight, 'count');
$checks++;
if ($sizes !== array(4, 4)) {
    bad('eight portraits should be two 4-up pages, got ' . json_encode($sizes));
}

/* Cards are wildcards, so a card plus three portraits is a legal four-slot page
 * and must not be broken up. */
$withCard = layout_partition_subgroup(array_fill(0, 3, 'portrait'), 1, array(), $tuning);
$checks++;
if (count($withCard) !== 1 || (int) $withCard[0]['count'] !== 3 || empty($withCard[0]['card'])) {
    bad('three portraits and a card should be one page, got ' . json_encode($withCard));
}

printf("%d partitions checked\n", $checks);
if ($failures > 0) {
    printf("FAILED (%d)\n", $failures);
    exit(1);
}
print "OK — every page drawable, every photo placed, order kept\n";
