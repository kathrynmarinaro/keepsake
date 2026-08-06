<?php
/* POST /api/photos-upload.php   multipart/form-data, files[]
 *
 * The validation SHAPE is worth reusing from Inspiration Board's
 * public/api/upload.php (see PLAN.md): normalize $_FILES into one row per
 * file, reject with a reason code per file, detect payload_too_large via
 * CONTENT_LENGTH before assuming an empty body means no files, generated
 * filenames never derived from client input.
 *
 * WHAT'S DIFFERENT, DELIBERATELY: this is SYNCHRONOUS. Reads EXIF, generates
 * one thumbnail, resolves year_project_id, done — inline, in this request.
 * There is no lib/queue.php, no 'pending' status column, no worker.php to
 * poll. Inspiration Board defers processing because it also runs a
 * vision/color pipeline per image that's too slow to hold a phone's upload
 * request open for; Keepsake has no such pipeline, so there's nothing to
 * defer work for — see lib/imageproc.php's own header.
 *
 * → { "created":  [{ "id":91, "name":"IMG_4021.jpg", "thumb_url":"...",
 *                     "original_url":"...", "caption":null,
 *                     "location_text":null, "entry_date":"2024-07-04",
 *                     "width":3024, "height":4032 }],
 *     "rejected": [{ "name":"notes.pdf", "reason":"unsupported_type" }] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/imageproc.php';
require_once __DIR__ . '/../../lib/exif.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/geocode.php';
require_once __DIR__ . '/../../lib/grouping.php';

require_login_api();
require_same_origin();
require_method('POST');

/* PHP discards the whole body when it exceeds post_max_size, leaving $_FILES
 * empty with no error code to inspect — detect it before concluding the
 * client sent nothing. */
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > 0 && empty($_FILES) && empty($_POST)) {
    json_error('payload_too_large', 413, 'The upload exceeded the server post_max_size limit.');
}

if (empty($_FILES['files'])) {
    json_error('no_files', 400);
}

$created  = array();
$rejected = array();

// One clock for the whole batch: every photo in this request that has no
// usable EXIF date falls back to the SAME submission moment, rather than
// each one reading a slightly different `now()` a few milliseconds apart.
$submittedAt = date('Y-m-d H:i:s');

foreach (photos_upload_normalize_files($_FILES['files']) as $file) {
    $label = photos_upload_display_name($file['name']);

    $reason = photos_upload_reject_reason($file);
    if ($reason !== null) {
        $rejected[] = array('name' => $label, 'reason' => $reason);
        continue;
    }

    /* Identify by bytes only — the client-supplied name and MIME type are
     * echoed back for display and otherwise never trusted. */
    $sniff = imageproc_sniff($file['tmp_name']);
    if ($sniff === null) {
        $rejected[] = array('name' => $label, 'reason' => 'unsupported_type');
        continue;
    }
    if ($sniff['family'] === 'heif' && !imageproc_heif_supported()) {
        $rejected[] = array('name' => $label, 'reason' => 'heic_unsupported_on_server');
        continue;
    }

    try {
        $row = photos_upload_store($file['tmp_name'], $sniff, $submittedAt);
    } catch (Throwable $e) {
        error_log('photos-upload: ' . $e->getMessage());
        $rejected[] = array('name' => $label, 'reason' => 'save_failed');
        continue;
    }

    $row['name'] = $label;
    $created[]   = $row;
}

/* Phase 4 trigger point 1/2 (PLAN.md: "there is no queue/cron in this
 * app", brief §4.1) — auto-group runs synchronously at the end of THIS
 * batch, scoped only to the year_project_id(s) it actually touched, so an
 * upload landing in 2019 never re-clusters 2026's photos in the same
 * request. The other trigger point is public/api/event-groups-auto.php,
 * called from review.php's "Group photos" button — both call the exact
 * same lib/grouping.php::event_grouping_run(), so there is one place the
 * clustering/naming logic lives, not two.
 *
 * A grouping failure (a Nominatim outage, an unexpected exception) must not
 * cost Kathryn a successful upload she's already watched complete — fail
 * soft here the same way a single photo's thumbnail failure does above. */
$touchedYears = array();
foreach ($created as $row) {
    $touchedYears[(int) substr((string) $row['entry_date'], 0, 4)] = true;
}
foreach (array_keys($touchedYears) as $year) {
    $yearProject = year_project_get_by_year($year);
    if ($yearProject === null) {
        continue;
    }
    try {
        event_grouping_run((int) $yearProject['id']);
    } catch (Throwable $e) {
        error_log('photos-upload: auto-grouping failed for year ' . $year . ': ' . $e->getMessage());
    }
}

json_out(array('created' => $created, 'rejected' => $rejected));

/* ------------------------------------------------------------------ helpers */

/**
 * Flatten PHP's transposed $_FILES['files'] into one row per file. Also
 * tolerates a single (non-array) upload under the same key.
 *
 * @return list<array{name:string,tmp_name:string,size:int,error:int}>
 */
function photos_upload_normalize_files(array $field): array
{
    if (!is_array($field['name'] ?? null)) {
        $field = array_map(static fn($v): array => array($v), $field);
    }

    $out   = array();
    $count = count($field['name']);
    for ($i = 0; $i < $count; $i++) {
        $out[] = array(
            'name'     => (string) ($field['name'][$i] ?? ''),
            'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
            'size'     => (int) ($field['size'][$i] ?? 0),
            'error'    => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        );
    }
    return $out;
}

/** Why this upload can't be accepted, or null if it can. */
function photos_upload_reject_reason(array $file): ?string
{
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'too_large';
        case UPLOAD_ERR_NO_FILE:
            return 'empty_file';
        case UPLOAD_ERR_PARTIAL:
            return 'incomplete_upload';
        default:
            return 'upload_failed';
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return 'upload_failed';
    }
    if ($file['size'] <= 0) {
        return 'empty_file';
    }
    if ($file['size'] > imageproc_max_bytes()) {
        return 'too_large';
    }
    return null;
}

/**
 * A safe echo of the client's filename. Display text only — never used to
 * build a path.
 */
function photos_upload_display_name(string $raw): string
{
    $name = str_replace(array("\0", "\r", "\n"), '', $raw);
    $name = basename($name);
    $name = preg_replace('/[[:cntrl:]]/u', '', $name) ?? '';
    $name = trim($name);
    return $name === '' ? 'upload' : mb_substr($name, 0, 120, 'UTF-8');
}

/**
 * Move an accepted upload into place, read its EXIF, generate its thumbnail,
 * and insert its photos row — resolving year_project_id along the way (see
 * lib/repo.php's photo_create()). Returns the row the client needs to run
 * the batch step-through.
 */
function photos_upload_store(string $tmpName, array $sniff, string $submittedAt): array
{
    imageproc_ensure_dir('original');

    $slug    = imageproc_new_slug();               // generated, never client-derived
    $destAbs = imageproc_upload_path('original', $slug, $sniff['ext']);

    if (!move_uploaded_file($tmpName, $destAbs)) {
        throw new RuntimeException('move_uploaded_file failed');
    }
    @chmod($destAbs, 0644);

    $originalRel = imageproc_relative_path('original', $slug, $sniff['ext']);

    /* EXIF date + GPS, fail-soft (brief §7): no EXIF block, a corrupt one, or
     * a format exif_read_data() can't parse (HEIC) all fall back to
     * submission time / null GPS rather than rejecting the upload. */
    $exif       = exif_read_file($destAbs);
    $capturedAt = $exif['captured_at'] ?? $submittedAt;

    $dims = imageproc_probe_dimensions($destAbs);

    $thumbRel = null;
    try {
        $thumbRel = imageproc_make_thumbnail($destAbs, $slug, $sniff);
    } catch (Throwable $e) {
        // Fail soft: a thumbnail failure degrades THIS photo (no thumb_path)
        // rather than the whole batch — the original is still safely stored.
        error_log('photos-upload: thumbnail failed for ' . $originalRel . ': ' . $e->getMessage());
    }

    $id = photo_create(array(
        'original_path' => $originalRel,
        'thumb_path'    => $thumbRel,
        'width'         => $dims[0] ?? null,
        'height'        => $dims[1] ?? null,
        'captured_at'   => $capturedAt,
        'gps_lat'       => $exif['gps_lat'],
        'gps_lon'       => $exif['gps_lon'],
    ));

    return array(
        'id'            => $id,
        'thumb_url'     => $thumbRel,
        'original_url'  => $originalRel,
        'caption'       => null,
        'location_text' => null,
        'entry_date'    => substr($capturedAt, 0, 10),
        'width'         => $dims[0] ?? null,
        'height'        => $dims[1] ?? null,
    );
}
