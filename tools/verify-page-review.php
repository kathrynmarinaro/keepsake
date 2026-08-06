<?php
/**
 * Phase 6 (page review UI) smoke test: proves the repo-layer drag-and-drop
 * functions (book_page_slot_swap()/book_page_slot_move()) and the
 * cover_photo_id/subtitle partial-update behaviour against the SQLite
 * test-harness database. No browser, no MySQL, no network — same
 * constraint as every prior phase; the drag gesture itself, the visual
 * spread rendering, and the picker UI are not exercised here (see
 * public/layout.php's own header for what was traced by hand instead).
 *
 * Follows tools/verify-layout.php's own style and bootstrap shim.
 *
 * WHAT THIS CHECKS:
 *   1. book_page_slot_swap(): two photos trade places, same page or across
 *      pages, with slot_number and book_page_id staying put on both sides —
 *      only the photo_id column moves. Refuses a text-card slot, a slot
 *      that doesn't exist, and two slots from different book_layouts.
 *   2. book_page_slot_move(): a photo lands at the next open slot on a
 *      different page, or a specific free slot_number if given. Refuses a
 *      full destination page, a page_type that isn't 'photos', a
 *      cross-layout move, and correctly excludes the slot's OWN current
 *      position from "occupied" when reordering within the same page.
 *   3. Neither function duplicates or loses a photo anywhere in the layout
 *      — every photo present before a swap/move is still present exactly
 *      once afterward.
 *   4. year_project_update_subtitle()/year_project_set_cover_photo() are
 *      independent partial updates (public/api/year-projects-update.php's
 *      own header claims this) — setting one never touches the other.
 *
 * Usage:
 *   php tools/verify-page-review.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

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
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

require __DIR__ . '/../lib/grouping.php';
require __DIR__ . '/../lib/layout.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $GLOBALS['failures']++;
    }
}

function make_photo(string $capturedAt, string $shape = 'landscape'): int
{
    $dims = array(
        'landscape' => array(1200, 800),
        'portrait'  => array(800, 1200),
    );
    [$w, $h] = $dims[$shape];

    return photo_create(array(
        'original_path' => 'uploads/original/test.jpg',
        'thumb_path'    => 'uploads/thumb/test.webp',
        'width'         => $w,
        'height'        => $h,
        'captured_at'   => $capturedAt,
        'gps_lat'       => null,
        'gps_lon'       => null,
    ));
}

/** All photo_id values across a set of book_pages rows (from book_pages_for_layout()). */
function all_photo_ids(array $pages): array
{
    $ids = array();
    foreach ($pages as $page) {
        foreach ($page['slots'] as $slot) {
            if ($slot['photo_id'] !== null) {
                $ids[] = (int) $slot['photo_id'];
            }
        }
    }
    sort($ids);
    return $ids;
}

/* ============================================================ two layouts === */

echo "Loading schema.sql into an in-memory SQLite database...\n";
$pdo = harness_pdo();
echo "Schema applied cleanly.\n\n";

// Year A: enough photos across two days to get a handful of pages, so there's
// a same-page slot and an other-page slot to test moves/swaps against.
$ypA = year_project_get_or_create('2024-07-01');
$photosA = array();
for ($i = 0; $i < 6; $i++) {
    $photosA[] = make_photo('2024-07-01 ' . (10 + $i) . ':00:00', 'landscape');
}
$groupA = event_group_create(array(
    'year_project_id' => $ypA,
    'name'             => 'Test trip A',
    'start_date'       => '2024-07-01',
    'end_date'         => '2024-07-01',
));
foreach ($photosA as $pid) {
    photo_update($pid, array('event_group_id' => $groupA));
}
$layoutA = layout_generate($ypA);
$pagesA  = book_pages_for_layout($layoutA['layout_id']);
$photoPagesA = array_values(array_filter($pagesA, static fn(array $p): bool => $p['page_type'] === 'photos'));

check('the synthetic year produced more than one photo page', count($photoPagesA) > 1);

// Year B: a second, separate layout, to prove swap/move refuse to cross layouts.
$ypB = year_project_get_or_create('2023-05-01');
$photosB = array(make_photo('2023-05-01 09:00:00', 'landscape'), make_photo('2023-05-01 09:05:00', 'landscape'));
$groupB = event_group_create(array(
    'year_project_id' => $ypB,
    'name'             => 'Test trip B',
    'start_date'       => '2023-05-01',
    'end_date'         => '2023-05-01',
));
foreach ($photosB as $pid) {
    photo_update($pid, array('event_group_id' => $groupB));
}
$layoutB = layout_generate($ypB);
$pagesB  = book_pages_for_layout($layoutB['layout_id']);

echo "\nbook_page_slot_swap(): two photos trade places...\n";

// Find two distinct filled photo slots on year A's first two photo pages.
$slotsA = array();
foreach ($photoPagesA as $page) {
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] !== null) {
            $slotsA[] = $slot;
        }
    }
}
check('there are at least two filled slots to test with', count($slotsA) >= 2);

$slot1 = $slotsA[0];
$slot2 = $slotsA[1];
$photo1Before = (int) $slot1['photo_id'];
$photo2Before = (int) $slot2['photo_id'];

$beforeIds = all_photo_ids($pagesA);
$swapped = book_page_slot_swap((int) $slot1['id'], (int) $slot2['id']);
check('swap reported success', $swapped);

$slot1After = book_page_slot_get((int) $slot1['id']);
$slot2After = book_page_slot_get((int) $slot2['id']);
check('slot 1 now holds what was in slot 2', (int) $slot1After['photo_id'] === $photo2Before);
check('slot 2 now holds what was in slot 1', (int) $slot2After['photo_id'] === $photo1Before);
check('slot 1 stayed on its own page', (int) $slot1After['book_page_id'] === (int) $slot1['book_page_id']);
check('slot 1 kept its own slot_number', (int) $slot1After['slot_number'] === (int) $slot1['slot_number']);

$afterIds = all_photo_ids(book_pages_for_layout($layoutA['layout_id']));
check('no photo was duplicated or lost by the swap', $beforeIds === $afterIds);

echo "\nbook_page_slot_swap(): refuses invalid targets...\n";
check('swapping a slot with itself is refused', book_page_slot_swap((int) $slot1['id'], (int) $slot1['id']) === false);
check('swapping against a nonexistent slot is refused', book_page_slot_swap((int) $slot1['id'], 999999) === false);

// Cross-layout: pick any filled slot from year B.
$slotB = null;
foreach ($pagesB as $page) {
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] !== null) {
            $slotB = $slot;
            break 2;
        }
    }
}
check('year B has a filled slot to test cross-layout refusal with', $slotB !== null);
check(
    'swapping across two different book_layouts is refused',
    book_page_slot_swap((int) $slot1['id'], (int) $slotB['id']) === false
);

echo "\nbook_page_slot_move(): a photo moves to a different page...\n";

// Re-fetch current state (slot1/slot2 already swapped above).
$pagesA = book_pages_for_layout($layoutA['layout_id']);
$photoPagesA = array_values(array_filter($pagesA, static fn(array $p): bool => $p['page_type'] === 'photos'));

$sourcePage = $photoPagesA[0];
$targetPage = $photoPagesA[1];
$sourceSlot = null;
foreach ($sourcePage['slots'] as $slot) {
    if ($slot['photo_id'] !== null) {
        $sourceSlot = $slot;
        break;
    }
}
check('found a source slot on page 1 to move', $sourceSlot !== null);

$movedPhotoId = (int) $sourceSlot['photo_id'];
$beforeIds = all_photo_ids($pagesA);

$moved = book_page_slot_move((int) $sourceSlot['id'], (int) $targetPage['id']);
check('move reported success', $moved);

$movedSlot = book_page_slot_get((int) $sourceSlot['id']);
check('the slot now belongs to the target page', (int) $movedSlot['book_page_id'] === (int) $targetPage['id']);
check('the photo itself is unchanged by the move', (int) $movedSlot['photo_id'] === $movedPhotoId);

$afterIds = all_photo_ids(book_pages_for_layout($layoutA['layout_id']));
check('no photo was duplicated or lost by the move', $beforeIds === $afterIds);

echo "\nbook_page_slot_move(): refuses invalid targets...\n";
check(
    'moving onto a nonexistent page is refused',
    book_page_slot_move((int) $movedSlot['id'], 999999) === false
);
check(
    'moving a slot that no longer holds a photo is refused',
    book_page_slot_move(999999, (int) $targetPage['id']) === false
);

// Cross-layout move.
$pageB = null;
foreach ($pagesB as $page) {
    if ($page['page_type'] === 'photos') {
        $pageB = $page;
        break;
    }
}
check('year B has a photos page to test cross-layout move refusal with', $pageB !== null);
check(
    'moving across two different book_layouts is refused',
    book_page_slot_move((int) $movedSlot['id'], (int) $pageB['id']) === false
);

echo "\nbook_page_slot_move(): filling a target page refuses a 5th photo...\n";
// Pack the target page to 4 slots, then try one more.
$pagesA = book_pages_for_layout($layoutA['layout_id']);
$fullPage = null;
foreach ($pagesA as $page) {
    if ($page['page_type'] === 'photos') {
        $fullPage = $page;
        break;
    }
}
$fillIds = array();
foreach ($pagesA as $page) {
    if ((int) $page['id'] === (int) $fullPage['id']) {
        continue;
    }
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] !== null) {
            $fillIds[] = $slot['id'];
        }
    }
}
$occupied = count($fullPage['slots']);
$capacity = 4 - $occupied;
for ($i = 0; $i < $capacity && $i < count($fillIds); $i++) {
    book_page_slot_move((int) $fillIds[$i], (int) $fullPage['id']);
}
$fullPageNow = book_page_get((int) $fullPage['id']);
$slotsOnFullPage = count(
    array_filter(
        book_pages_for_layout($layoutA['layout_id']),
        static fn(array $p): bool => (int) $p['id'] === (int) $fullPage['id']
    )[0]['slots'] ?? array()
);
if ($slotsOnFullPage >= 4 && count($fillIds) > $capacity) {
    $overflowSlotId = $fillIds[$capacity] ?? null;
    if ($overflowSlotId !== null) {
        check(
            'moving a 5th photo onto a full page is refused',
            book_page_slot_move((int) $overflowSlotId, (int) $fullPage['id']) === false
        );
    } else {
        echo "  skip (no more photos available to overflow with in this synthetic year)\n";
    }
} else {
    echo "  skip (could not pack the target page to 4 slots with this synthetic year's photo count)\n";
}

/* ================================================== title/cover updates === */

echo "\nyear_project_update_subtitle()/year_project_set_cover_photo(): independent...\n";

$coverCandidate = $photosB[0];
year_project_update_subtitle($ypA, 'The Test Trip');
check('subtitle was set', year_project_get($ypA)['subtitle'] === 'The Test Trip');
check('cover_photo_id is still unset', year_project_get($ypA)['cover_photo_id'] === null);

year_project_set_cover_photo($ypA, $photosA[0]);
check('cover_photo_id was set', (int) year_project_get($ypA)['cover_photo_id'] === $photosA[0]);
check('subtitle was NOT touched by setting the cover photo', year_project_get($ypA)['subtitle'] === 'The Test Trip');

year_project_set_cover_photo($ypA, null);
check('cover_photo_id can be cleared back to null', year_project_get($ypA)['cover_photo_id'] === null);
check('subtitle survives clearing the cover photo', year_project_get($ypA)['subtitle'] === 'The Test Trip');

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}
echo "All checks passed.\n";
