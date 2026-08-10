<?php
/* GET /api/export.php?year=YYYY
 *
 * The one action brief §5.5/PLAN.md's exit criterion asks for: "a full
 * year's book exports without manual intervention." Reads the year's
 * ACTIVE book layout (year_projects.active_book_layout_id) — never the
 * newest version, never a fresh recompute — see lib/pdfexport.php's header
 * for why that's the deliberate reading of schema.sql's own comment on that
 * column.
 *
 * A plain GET, not a POST: this produces a file, the browser navigates (or
 * a plain <a href> triggers) the download directly, and require_same_origin()
 * is already a no-op for GET/HEAD/OPTIONS — same as every other read-only
 * endpoint in public/api/. Still gated the same three ways as every
 * mutating endpoint in this app, per PLAN.md, since generating a multi-
 * megabyte PDF on demand is not "free" the way a plain SELECT is.
 *
 * NOT json_out() on success: a PDF download needs Content-Type:
 * application/pdf and a Content-Disposition header, not a JSON envelope.
 * Errors still go through json_error() — this only ever fails BEFORE any
 * bytes are written, so there's no half-sent-PDF-then-JSON problem.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/pdfexport.php';

require_login_api();
require_same_origin();
require_method('GET');

$year    = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$project = $year > 0 ? year_project_get_by_year($year) : null;

if ($project === null) {
    json_error('no_year_project', 404, 'No year project for ' . $year . '.');
}

try {
    $export = pdf_export_build((int) $project['id']);
} catch (Throwable $e) {
    if ($e->getMessage() === 'pdf_library_missing') {
        json_error(
            'pdf_library_missing',
            500,
            'The PDF library is not installed on the server. The vendor folder is missing or incomplete.'
        );
    }
    if ($e->getMessage() === 'no_active_layout') {
        json_error(
            'no_active_layout',
            409,
            'This year has no active book layout yet. Generate one on the layout screen first.'
        );
    }
    error_log('export.php: ' . $e->getMessage());
    json_error('export_failed', 500, 'Could not build the PDF.');
}

http_response_code(200);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
header('Content-Length: ' . strlen($export['bytes']));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $export['bytes'];
