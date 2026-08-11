<?php
/* GET /api/year-projects-export.php?id=N   — one project
 * GET /api/year-projects-export.php?all=1  — every project
 *
 * The data export, as distinct from /api/export.php, which builds the printed
 * PDF. This one is the backup: every row of everything, as JSON, so the
 * contents of a book survive a hosting account going away.
 *
 * WHY JSON AND NOT A ZIP OF THE PHOTOS. DEPLOY.txt already treats public/
 * uploads/ as a separate backup with its own instructions, because it is the
 * one directory that is never overwritten by a deploy. Zipping 123 originals
 * on demand means holding a few hundred megabytes in memory on shared hosting
 * to hand back a file that duplicates a backup that already exists — and it
 * is the same shape as the timeout that PDF export hit and had to be taught to
 * resume from. The photo PATHS are in the export, so a reader can match this
 * file up against a copy of uploads/ taken any time after it.
 *
 * A plain GET, like /api/export.php and for the same reasons: it produces a
 * file, an <a href> is enough to fetch it, and require_same_origin() is
 * already a no-op for GET. Still behind require_login_api() — this is the
 * single most disclosive URL in the app, since it is the whole database.
 *
 * NOT json_out() on success, again like export.php: the browser is meant to
 * SAVE this rather than render it, so it needs Content-Disposition and a
 * filename. Errors still go through json_error(), and they all fire before a
 * byte of the body is written.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';

require_login_api();
require_same_origin();
require_method('GET');

$all = isset($_GET['all']) && $_GET['all'] !== '' && $_GET['all'] !== '0';
$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$all && $id <= 0) {
    json_error('bad_request', 400, 'Pass id=N for one project or all=1 for everything.');
}

if ($all) {
    $projects = array();
    foreach (year_project_list() as $row) {
        $data = year_project_export_data((int) $row['id']);
        if ($data !== null) {
            $projects[] = $data;
        }
    }

    $payload  = array(
        'keepsake_export' => 1,
        'exported_at'     => date('c'),
        'projects'        => $projects,
    );
    $filename = 'keepsake-all-' . date('Y-m-d') . '.json';
} else {
    $payload = year_project_export_data($id);
    if ($payload === null) {
        json_error('not_found', 404, 'No project with id ' . $id . '.');
    }

    /* Named after the book, not the id: a folder of these is meant to be
       readable six months later, and "keepsake-3.json" is not. Reduced to the
       characters that are safe in a filename on every platform rather than
       trusting a title Kathryn typed — a book called "Summer '24 / Maine"
       would otherwise propose a path separator as part of its own name. */
    $slug = strtolower(year_project_title($payload['project']));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'project-' . $id;
    }

    $filename = 'keepsake-' . $slug . '.json';
}

$body = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

if ($body === false) {
    error_log('year-projects-export.php: json_encode failed: ' . json_last_error_msg());
    json_error('export_failed', 500, 'Could not encode the export.');
}

http_response_code(200);
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $body;
