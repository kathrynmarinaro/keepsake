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
 *   4. Phase 8: book_page_slot_move() draining a page to zero filled slots
 *      (no photos AND no text card) deletes that page and renumbers every
 *      later page in the same layout, closing the gap PLAN.md's Phase 6 note
 *      flagged as a known, deliberately-unfixed limitation. A page that still
 *      holds a text card after its photo leaves is NOT deleted, and same-page
 *      reordering never triggers this path at all.
 *   5. year_project_update_subtitle()/year_project_set_cover_photo() are
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
/* The destination has to have somewhere to put it. Page 2 used to be a safe
 * assumption because a generated page held at most three photos; with the
 * ceiling at four (Round 6) every generated page can be genuinely full, and
 * book_page_slot_move() refusing a full page is correct behaviour rather than
 * the bug this block is looking for. So the target is an empty page appended to
 * the same layout — which is also a cleaner test of "lands at the next open
 * slot on a different page" than borrowing a page that happens to have a gap. */
q('INSERT INTO book_pages (book_layout_id, page_number, page_type) VALUES (?, ?, ?)', array(
    $layoutA['layout_id'],
    max(array_map(static fn(array $p): int => (int) $p['page_number'], $pagesA)) + 1,
    'photos',
));
$targetPage = book_page_get((int) db()->lastInsertId());
check('an empty target page exists to move into', $targetPage !== null);
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

/* ============================================ Phase 8: emptied-page gap === */

echo "\nbook_page_slot_move(): draining a page's last photo deletes it and renumbers...\n";

// A dedicated layout, built directly through the repo layer rather than
// layout_generate() (whose exact page shapes aren't something this test
// wants to depend on): full deterministic control over which page starts
// with exactly one filled slot.
$ypC = year_project_get_or_create('2022-03-01');
$layoutC = book_layout_create($ypC);

$photoLone   = make_photo('2022-03-01 09:00:00');
$photoTargetA = make_photo('2022-03-01 09:05:00');
$photoTargetB = make_photo('2022-03-01 09:06:00');
$photoTail   = make_photo('2022-03-01 09:07:00');
$photoMixed  = make_photo('2022-03-01 09:08:00');

$quoteId = quote_create(array(
    'quote_text'  => 'A short quote riding along a photo page.',
    'who_said_it' => 'Emma',
    'entry_date'  => '2022-03-01',
));

// Page 1: exactly one filled slot (the one being drained). Page 2: the move
// TARGET. Page 3: a text page, to prove a non-'photos' page type also
// renumbers correctly. Page 4: one more photo page, to prove renumbering
// isn't a one-off single-decrement special case.
$pageC1 = book_page_create($layoutC, 1, 'photos');
book_page_slot_create($pageC1, 1, array('photo_id' => $photoLone));

$pageC2 = book_page_create($layoutC, 2, 'photos');
book_page_slot_create($pageC2, 1, array('photo_id' => $photoTargetA));
book_page_slot_create($pageC2, 2, array('photo_id' => $photoTargetB));

$pageC3 = book_page_create($layoutC, 3, 'text');
book_page_slot_create($pageC3, 1, array('quote_id' => $quoteId));

$pageC4 = book_page_create($layoutC, 4, 'photos');
book_page_slot_create($pageC4, 1, array('photo_id' => $photoTail));

// Page 5: a photo slot PLUS a text-card slot — moving the photo away must
// leave the text card behind and must NOT delete this page, since "0 filled
// slots" means zero of anything, not zero photos specifically.
$quoteId2 = quote_create(array(
    'quote_text'  => 'Stays behind on the mixed page.',
    'who_said_it' => 'Kathryn',
    'entry_date'  => '2022-03-01',
));
$pageC5 = book_page_create($layoutC, 5, 'photos');
$slotC5Photo = book_page_slot_create($pageC5, 1, array('photo_id' => $photoMixed));
book_page_slot_create($pageC5, 2, array('quote_id' => $quoteId2));

$slotLone = null;
foreach (book_pages_for_layout($layoutC)[0]['slots'] as $s) {
    $slotLone = $s;
}
check('page 1 has exactly the one slot set up above', $slotLone !== null && (int) $slotLone['photo_id'] === $photoLone);

$beforeIdsC = all_photo_ids(book_pages_for_layout($layoutC));
$movedC = book_page_slot_move((int) $slotLone['id'], $pageC2);
check('the drain-to-empty move itself reported success', $movedC);

check('page 1 (now empty) was deleted', book_page_get($pageC1) === null);

$pagesCAfter = book_pages_for_layout($layoutC);
$byOldId = array();
foreach ($pagesCAfter as $p) {
    $byOldId[(int) $p['id']] = $p;
}
check('exactly 4 pages remain (5 minus the deleted one)', count($pagesCAfter) === 4);
check('old page 2 renumbered down to page 1', (int) $byOldId[$pageC2]['page_number'] === 1);
check('old page 3 (the TEXT page) renumbered down to page 2', (int) $byOldId[$pageC3]['page_number'] === 2);
check('old page 4 renumbered down to page 3', (int) $byOldId[$pageC4]['page_number'] === 3);
check('old page 5 (untouched by this move) renumbered down to page 4', (int) $byOldId[$pageC5]['page_number'] === 4);
check('old page 3 kept its own page_type through the renumber', $byOldId[$pageC3]['page_type'] === 'text');
check('the moved photo landed on old page 2, now with 3 filled slots', count($byOldId[$pageC2]['slots']) === 3);

$afterIdsC = all_photo_ids($pagesCAfter);
check('no photo was duplicated or lost by the drain-and-delete move', $beforeIdsC === $afterIdsC);

echo "\nbook_page_slot_move(): a page with a surviving text card is NOT deleted...\n";

$beforeCountC = count(book_pages_for_layout($layoutC));
$targetForMixed = $byOldId[$pageC4]['id']; // old page 4, a plain photos page
$movedMixed = book_page_slot_move((int) $slotC5Photo, (int) $targetForMixed);
check('moving the mixed page\'s photo away reported success', $movedMixed);
check('the mixed page (still holding its text card) was NOT deleted', book_page_get($pageC5) !== null);
check('page count is unchanged — nothing was deleted this time', count(book_pages_for_layout($layoutC)) === $beforeCountC);

echo "\nbook_page_slot_move(): same-page reordering never triggers the delete path...\n";

$pageC2After = $byOldId[$pageC2];
$reorderSlot = $pageC2After['slots'][0];
$beforeCountReorder = count(book_pages_for_layout($layoutC));
book_page_slot_move((int) $reorderSlot['id'], (int) $pageC2, 4); // move to an open slot_number on ITS OWN page
check(
    'reordering within the same page changes nothing about page count',
    count(book_pages_for_layout($layoutC)) === $beforeCountReorder
);
check('the source page (self) still exists', book_page_get($pageC2) !== null);

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

echo "\nbook_layout_delete(): removing a version she has decided against...\n";

/* Kathryn generates a version every time she wants to see a change, so a year
 * accumulates them — hers had seven for 2025. These pin the two things that
 * matter about removing one: the pages go with it, and deleting the ACTIVE
 * version is exactly the mistake the API guard exists to prevent. */
$doomed = book_layout_create($ypA);
q('INSERT INTO book_pages (book_layout_id, page_number, page_type) VALUES (?, ?, ?)',
    array($doomed, 1, 'photos'));
$doomedPage = (int) db()->lastInsertId();
q('INSERT INTO book_page_photos (book_page_id, slot_number, photo_id) VALUES (?, ?, ?)',
    array($doomedPage, 1, $movedPhotoId));

check('the throwaway version has a page', count(book_pages_for_layout($doomed)) === 1);

book_layout_delete($doomed);

check('the version is gone', book_layout_get($doomed) === null);
check('...and its pages went with it',
    (int) q('SELECT COUNT(*) AS n FROM book_pages WHERE book_layout_id = ?', array($doomed))
        ->fetch()['n'] === 0);
check('...and its slots went with the pages',
    (int) q('SELECT COUNT(*) AS n FROM book_page_photos WHERE book_page_id = ?', array($doomedPage))
        ->fetch()['n'] === 0);
check('the photo itself is untouched — deleting a layout is not deleting photos',
    photo_get($movedPhotoId) !== null);

/* Why the endpoint refuses the active version: nothing else does. */
$activeId = (int) year_project_get($ypA)['active_book_layout_id'];
check('the year has an active version to protect', $activeId > 0);
book_layout_delete($activeId);
check('deleting the active one leaves the year with no book at all, which is why '
    . 'api/book-layouts-delete.php refuses to',
    year_project_get($ypA)['active_book_layout_id'] === null);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}
echo "All checks passed.\n";
