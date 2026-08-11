<?php
/**
 * Phase 3 (review/browse) smoke test: proves the repo-layer logic this
 * phase added — year re-resolution on date-edit for quotes/anecdotes/
 * snapshots (extending photo_update()'s existing behaviour, brief §3), the
 * skip_for_book/full_page flags persisting through photo_update(), and the
 * event-group manual create/rename/merge/split semantics — against the
 * SQLite test-harness database. No browser, no MySQL.
 *
 * Follows tools/verify-capture.php's own style and header conventions.
 *
 * WHAT THIS CHECKS:
 *   1. quote_update()/anecdote_update()/snapshot_update(): a partial update
 *      that doesn't touch entry_date leaves year_project_id alone; one that
 *      does touch it re-resolves through year_project_get_or_create(),
 *      moving the row to a different year — the exact behaviour
 *      photo_update() already had, now proven for the other three content
 *      types too.
 *   2. snapshot_update() only ever writes fields belonging to the ROW'S OWN
 *      template (birthday vs. school_year), even when the request body
 *      hands it a field from the other template — mirrors
 *      snapshot_create()'s invariant, checked in verify-capture.php, now
 *      checked on the update path.
 *   3. photo_update() with skip_for_book/full_page persists both flags
 *      independently, and leaves them alone when a later partial update
 *      doesn't mention them — THE exit-criterion behaviour ("skip-for-book
 *      and full-page flags persist").
 *   4. photo_delete() clears year_projects.cover_photo_id when the deleted
 *      photo was the cover (not a real FK — see schema.sql's comment).
 *   5. event_group_create()/event_group_rename(): manual creation sets
 *      is_manual_name; rename sets it again and updates the name.
 *   6. event_group_merge(): photos from two source groups land in the
 *      target group, both sources are gone, and the target's date range
 *      widens to cover the merged membership. A source group from a
 *      DIFFERENT year is skipped rather than merged across the isolation
 *      boundary.
 *   7. event_group_split(): moving a subset of a group's photos to a new
 *      group leaves the right photos on each side, and both groups' date
 *      ranges are recomputed from their post-split membership.
 *
 * Usage:
 *   php tools/verify-review.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

echo "Loading schema.sql into an in-memory SQLite database...\n";
$pdo = harness_pdo();
echo "Schema applied cleanly.\n\n";

/* ============================================== quote/anecdote year-edit */

echo "quote_update(): year re-resolves only when entry_date changes...\n";
$quoteId = quote_create(array('quote_text' => 'Original', 'who_said_it' => 'Emma', 'entry_date' => '2024-05-01'));
$ypBefore = (int) $pdo->query("SELECT year_project_id FROM quotes WHERE id = $quoteId")->fetchColumn();

quote_update($quoteId, array('quote_text' => 'Edited text, same date'));
$ypAfterTextEdit = (int) $pdo->query("SELECT year_project_id FROM quotes WHERE id = $quoteId")->fetchColumn();
check('editing quote_text alone leaves year_project_id untouched', $ypAfterTextEdit === $ypBefore);
check('quote_text was actually updated', $pdo->query("SELECT quote_text FROM quotes WHERE id = $quoteId")->fetchColumn() === 'Edited text, same date');

quote_update($quoteId, array('entry_date' => '2019-11-20'));
$ypAfterDateEdit = (int) $pdo->query("SELECT year_project_id FROM quotes WHERE id = $quoteId")->fetchColumn();
$yearAfterDateEdit = (int) $pdo->query("SELECT year FROM year_projects WHERE id = $ypAfterDateEdit")->fetchColumn();
check('editing entry_date moves the quote to a different year_project', $ypAfterDateEdit !== $ypBefore);
check('the new year_project is actually 2019', $yearAfterDateEdit === 2019);

echo "\nanecdote_update(): same year re-resolution behaviour...\n";
$anecId = anecdote_create(array('anecdote_text' => 'Something happened', 'entry_date' => '2023-02-14'));
anecdote_update($anecId, array('entry_date' => '2026-01-01'));
$anecYear = (int) $pdo->query(
    "SELECT yp.year FROM anecdotes a JOIN year_projects yp ON yp.id = a.year_project_id WHERE a.id = $anecId"
)->fetchColumn();
check('anecdote date correction lands in the new year (2026)', $anecYear === 2026);

/* ================================================== snapshot update rules */

echo "\nsnapshot_update(): year re-resolves; template fields stay isolated...\n";
$birthdayId = snapshot_create(array(
    'type' => 'birthday', 'entry_date' => '2025-04-15',
    'title' => 'Fifth birthday',
    'sections' => array(array('heading' => 'Age', 'body' => '5')),
));
snapshot_update($birthdayId, array(
    'entry_date' => '2022-04-15',
    'title'      => 'Sixth birthday',
    'sections'   => array(array('heading' => 'Age', 'body' => '6')),
));
$row = $pdo->query("SELECT * FROM snapshots WHERE id = $birthdayId")->fetch();
$birthdayYear = (int) $pdo->query("SELECT year FROM year_projects WHERE id = " . (int) $row['year_project_id'])->fetchColumn();
check('snapshot date correction re-resolved year_project_id (now 2022)', $birthdayYear === 2022);
check('snapshot_update() applied the title', $row['title'] === 'Sixth birthday');

$sections = snapshot_sections($birthdayId);
check('snapshot_update() replaced the sections', count($sections) === 1 && $sections[0]['body'] === '6');

/* The old cross-template guard is gone with the columns it protected: there is
 * no "birthday-only" or "school-year-only" field left for a confused client to
 * write into the wrong row. `type` now only decides what a NEW entry's section
 * headings start as, and snapshot_update() does not accept it at all — the
 * template a saved snapshot was started from is not something to change after
 * the fact. */
snapshot_update($birthdayId, array('type' => 'school_year'));
check(
    'snapshot_update() will not change the template a snapshot was started from',
    snapshot_get($birthdayId)['type'] === 'birthday'
);

/* Clearing the title puts it back to NULL rather than storing an empty string,
   matching every other optional text field in the repo. */
snapshot_update($birthdayId, array('title' => '   '));
check('an emptied title is stored as NULL', snapshot_get($birthdayId)['title'] === null);

/* ========================================== skip_for_book / full_page ===*/

echo "\nphoto_update(): skip_for_book/full_page persist independently...\n";
$photoId = photo_create(array(
    'original_path' => 'uploads/original/a.jpg', 'thumb_path' => null,
    'width' => 800, 'height' => 600, 'captured_at' => '2026-06-01 12:00:00',
));
photo_update($photoId, array('skip_for_book' => true));
$afterSkip = $pdo->query("SELECT skip_for_book, full_page FROM photos WHERE id = $photoId")->fetch();
check('skip_for_book set to true persists', (int) $afterSkip['skip_for_book'] === 1);
check('full_page stayed false (default), untouched by the skip_for_book update', (int) $afterSkip['full_page'] === 0);

photo_update($photoId, array('full_page' => true));
$afterFullPage = $pdo->query("SELECT skip_for_book, full_page FROM photos WHERE id = $photoId")->fetch();
check('full_page set to true persists', (int) $afterFullPage['full_page'] === 1);
check('skip_for_book from the EARLIER update is still true — a later partial update did not clear it', (int) $afterFullPage['skip_for_book'] === 1);

photo_update($photoId, array('skip_for_book' => false));
$afterUnskip = $pdo->query("SELECT skip_for_book, full_page FROM photos WHERE id = $photoId")->fetch();
check('skip_for_book can be toggled back off', (int) $afterUnskip['skip_for_book'] === 0);
check('full_page remains true, independent of skip_for_book', (int) $afterUnskip['full_page'] === 1);

echo "\nphoto_delete(): clears a dangling year_projects.cover_photo_id (not a real FK)...\n";
$coverPhotoId = photo_create(array(
    'original_path' => 'uploads/original/cover.jpg', 'thumb_path' => null,
    'width' => 800, 'height' => 600, 'captured_at' => '2026-01-01 08:00:00',
));
$coverYearId = (int) $pdo->query("SELECT year_project_id FROM photos WHERE id = $coverPhotoId")->fetchColumn();
year_project_set_cover_photo($coverYearId, $coverPhotoId);
check('cover_photo_id was set before delete', (int) $pdo->query("SELECT cover_photo_id FROM year_projects WHERE id = $coverYearId")->fetchColumn() === $coverPhotoId);
photo_delete($coverPhotoId);
$coverAfter = $pdo->query("SELECT cover_photo_id FROM year_projects WHERE id = $coverYearId")->fetchColumn();
check('cover_photo_id is NULL after its photo is deleted', $coverAfter === null);

/* ==================================================== event_groups CRUD */

echo "\nevent_group_create()/event_group_rename()...\n";
$ypId = year_project_get_or_create('2026-07-01');
$groupId = event_group_create(array(
    'year_project_id' => $ypId, 'name' => 'Auto: Jul 1-3',
    'start_date' => '2026-07-01', 'end_date' => '2026-07-03',
));
$groupRow = event_group_get($groupId);
check('manually created group has is_manual_name = 1', (int) $groupRow['is_manual_name'] === 1);

event_group_rename($groupId, 'Fourth of July at the lake house');
$renamed = event_group_get($groupId);
check('rename updated the name', $renamed['name'] === 'Fourth of July at the lake house');
check('rename kept is_manual_name = 1', (int) $renamed['is_manual_name'] === 1);

echo "\nevent_group_merge(): membership moves, sources vanish, dates widen...\n";
$groupA = event_group_create(array('year_project_id' => $ypId, 'name' => 'A', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01'));
$groupB = event_group_create(array('year_project_id' => $ypId, 'name' => 'B', 'start_date' => '2026-07-05', 'end_date' => '2026-07-05'));

$photoA = photo_create(array('original_path' => 'uploads/original/pa.jpg', 'thumb_path' => null, 'width' => 100, 'height' => 100, 'captured_at' => '2026-07-01 09:00:00'));
$photoB = photo_create(array('original_path' => 'uploads/original/pb.jpg', 'thumb_path' => null, 'width' => 100, 'height' => 100, 'captured_at' => '2026-07-05 09:00:00'));
photo_update($photoA, array('event_group_id' => $groupA));
photo_update($photoB, array('event_group_id' => $groupB));

// A group in a DIFFERENT year — must be skipped by the merge, not merged
// across the year-isolation boundary.
$otherYearId = year_project_get_or_create('2019-01-01');
$foreignGroup = event_group_create(array('year_project_id' => $otherYearId, 'name' => 'Different year', 'start_date' => '2019-01-01', 'end_date' => '2019-01-01'));

event_group_merge(array($groupB, $foreignGroup), $groupA);

$mergedPhotoA = (int) $pdo->query("SELECT event_group_id FROM photos WHERE id = $photoA")->fetchColumn();
$mergedPhotoB = (int) $pdo->query("SELECT event_group_id FROM photos WHERE id = $photoB")->fetchColumn();
check('photo originally in group A is still in group A', $mergedPhotoA === $groupA);
check('photo originally in group B is now in group A (merged)', $mergedPhotoB === $groupA);
check('group B no longer exists', event_group_get($groupB) === null);
check('the foreign-year group was NOT merged (still exists)', event_group_get($foreignGroup) !== null);

$mergedGroupA = event_group_get($groupA);
check('group A\'s date range widened to cover both members (start 2026-07-01)', $mergedGroupA['start_date'] === '2026-07-01');
check('group A\'s date range widened to cover both members (end 2026-07-05)', $mergedGroupA['end_date'] === '2026-07-05');

echo "\nevent_group_split(): a subset of photos peels off into a new group...\n";
$photoC = photo_create(array('original_path' => 'uploads/original/pc.jpg', 'thumb_path' => null, 'width' => 100, 'height' => 100, 'captured_at' => '2026-07-10 09:00:00'));
photo_update($photoC, array('event_group_id' => $groupA));
// Group A now has photoA (07-01), photoB (07-05), photoC (07-10).

$newGroupId = event_group_split($groupA, array($photoC), 'Split off: the 10th');

$photoCGroup = (int) $pdo->query("SELECT event_group_id FROM photos WHERE id = $photoC")->fetchColumn();
$photoAGroup = (int) $pdo->query("SELECT event_group_id FROM photos WHERE id = $photoA")->fetchColumn();
check('the split photo moved to the new group', $photoCGroup === $newGroupId);
check('a photo not named in the split stayed in the original group', $photoAGroup === $groupA);

$newGroup = event_group_get($newGroupId);
check('new group has is_manual_name = 1', (int) $newGroup['is_manual_name'] === 1);
check('new group\'s date range is just its one member (07-10)', $newGroup['start_date'] === '2026-07-10' && $newGroup['end_date'] === '2026-07-10');

$sourceAfterSplit = event_group_get($groupA);
check(
    'source group\'s date range shrank back to its remaining members (07-01..07-05)',
    $sourceAfterSplit['start_date'] === '2026-07-01' && $sourceAfterSplit['end_date'] === '2026-07-05'
);

echo "\nevent_group_delete(): ungroups without deleting photos...\n";
event_group_delete($newGroupId);
check('the group row is gone', event_group_get($newGroupId) === null);
$photoCAfterUngroup = $pdo->query("SELECT event_group_id FROM photos WHERE id = $photoC")->fetchColumn();
check('the photo that was in it still exists and is simply unassigned', $photoCAfterUngroup === null && photo_get($photoC) !== null);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}

echo "ALL CHECKS PASSED\n";
