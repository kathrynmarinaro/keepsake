<?php
/* GET /api/photos-recent.php?year_project_id=N&limit=500
 *
 * Photos for public/assets/photo-picker.js — today, a snapshot's hero-photo
 * selector (brief §2.3).
 *
 * IT USED TO BE "THE LAST 24 UPLOADED", full stop, on the reasoning that this
 * is a quick picker and not the year-browsing gallery. That was wrong for the
 * one job it has: a hero photo for a birthday page is a photo OF that
 * birthday, which for a book being assembled months later is nowhere near the
 * most recent 24. Kathryn asked to "be able to select any photo for the hero,
 * not just the most recent".
 *
 * So: scoped to a project when one is given, newest first, with a limit high
 * enough to mean "all of them" for a book (a year is a few hundred photos, and
 * the response is ids and paths, not images). Without a project id it keeps
 * the old global behaviour, because nothing should break if a future caller
 * has no project in hand.
 *
 * NOT PAGINATED. The sheet it fills scrolls, the payload for 500 photos is a
 * few tens of kilobytes, and the thumbnails load lazily as they scroll into
 * view — so paging would add a scroll-to-load state machine to save bytes
 * nobody is waiting on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('GET');

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
$limit = max(1, min($limit, 2000));

$projectId = isset($_GET['year_project_id']) ? (int) $_GET['year_project_id'] : 0;

$rows = $projectId > 0
    ? photos_for_picker($projectId, $limit)
    : photos_recent($limit);

$photos = array_map(
    static function (array $row): array {
        return array(
            'id'            => (int) $row['id'],
            'thumb_url'     => $row['thumb_path'],
            'original_url'  => $row['original_path'],
            'caption'       => $row['caption'],
            'location_text' => $row['location_text'],
            'entry_date'    => substr((string) $row['captured_at'], 0, 10),
        );
    },
    $rows
);

json_out($photos);
