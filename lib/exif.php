<?php
/* EXIF date + GPS extraction for photo uploads (Phase 2, brief §2.4/§3/§7).
 *
 * NEW WORK, NOT A PORT — Inspiration Board's lib/imageproc.php only reads the
 * `Orientation` tag (to bake rotation into a derivative); it has no date or
 * GPS handling at all, because Inspiration never needed either. This file is
 * Keepsake's own.
 *
 * SPLIT ON PURPOSE: exif_extract_from_data() is a pure function over the
 * array shape exif_read_data() returns — no file I/O, no PHP extension
 * dependency at call time — so tools/verify-capture.php can feed it synthetic
 * EXIF blocks (including malformed ones) and prove the date/GPS math without
 * a real JPEG on disk. exif_read_file() is the thin, fail-soft wrapper that
 * touches the filesystem and the `exif` extension.
 *
 * FAIL SOFT EVERYWHERE (CLAUDE-equivalent conventions, PLAN.md): a photo
 * with no EXIF block, a corrupt one, or a file format exif_read_data() can't
 * parse (HEIC, PNG, WebP — it only understands JPEG/TIFF) must still upload
 * successfully. Every function here returns nulls rather than throwing, and
 * the one function that touches disk (exif_read_file) swallows every warning
 * PHP's own extension is prone to emitting on truncated/odd files.
 */

declare(strict_types=1);

/**
 * Read EXIF date + GPS off a file on disk, fail-soft.
 *
 * Returns the same shape as exif_extract_from_data() — never throws, never
 * emits a warning to the page. A HEIC file, a PNG, a JPEG with no EXIF block
 * at all, or a corrupt one all come back as
 * ['captured_at' => null, 'gps_lat' => null, 'gps_lon' => null] rather than
 * stopping the upload — the caller falls back to submission time.
 *
 * @return array{captured_at: ?string, gps_lat: ?float, gps_lon: ?float}
 */
function exif_read_file(string $absPath): array
{
    $none = array('captured_at' => null, 'gps_lat' => null, 'gps_lon' => null);

    if (!function_exists('exif_read_data')) {
        // The exif extension itself isn't compiled in on this host — same
        // fallback as any other unreadable block, not a fatal error.
        return $none;
    }

    // @: exif_read_data() warns loudly on a file it can't parse (wrong
    // format, truncated download, a HEIC container) rather than just
    // returning false in every PHP version. The return value is what's
    // trusted, not the absence of a warning.
    $raw = @exif_read_data($absPath, 'EXIF', true, false);
    if (!is_array($raw)) {
        return $none;
    }

    try {
        return exif_extract_from_data($raw);
    } catch (Throwable $e) {
        // A malformed GPS block (wrong array shape, a "0/0" fraction) is a
        // degraded ROW, not a fatal upload — see lib/exif.php's header.
        error_log('exif: could not extract from ' . $absPath . ': ' . $e->getMessage());
        return $none;
    }
}

/**
 * Pure: pull captured_at + GPS out of an exif_read_data(..., true) array
 * (sections keyed by IFD name — 'EXIF', 'GPS', etc., which is what passing
 * `true` as the third argument produces). No file I/O.
 *
 * @return array{captured_at: ?string, gps_lat: ?float, gps_lon: ?float}
 */
function exif_extract_from_data(array $exif): array
{
    // exif_read_data(..., true) nests tags under their IFD section, but
    // different tags land in different sections depending on the camera/
    // phone (DateTimeOriginal is usually under 'EXIF', GPS tags under 'GPS',
    // but some encoders flatten everything to the top level). Search both
    // shapes rather than assuming one.
    $flat = $exif;
    foreach ($exif as $key => $value) {
        if (is_array($value) && $key !== 'GPS') {
            $flat = $flat + $value;
        }
    }
    $gps = is_array($exif['GPS'] ?? null) ? $exif['GPS'] : $flat;

    $dateRaw = $flat['DateTimeOriginal'] ?? $flat['DateTimeDigitized'] ?? $flat['DateTime'] ?? null;
    $capturedAt = is_string($dateRaw) ? exif_parse_datetime($dateRaw) : null;

    $lat = exif_gps_coordinate(
        $gps['GPSLatitude'] ?? null,
        is_string($gps['GPSLatitudeRef'] ?? null) ? $gps['GPSLatitudeRef'] : null
    );
    $lon = exif_gps_coordinate(
        $gps['GPSLongitude'] ?? null,
        is_string($gps['GPSLongitudeRef'] ?? null) ? $gps['GPSLongitudeRef'] : null
    );

    return array('captured_at' => $capturedAt, 'gps_lat' => $lat, 'gps_lon' => $lon);
}

/**
 * "2024:07:04 14:32:10" (EXIF's own date format, colons in the date part
 * too) -> "2024-07-04 14:32:10" for a MySQL DATETIME column. Null for
 * anything that doesn't match — a camera with its clock never set writes
 * "0000:00:00 00:00:00", which must not become a fabricated timestamp.
 */
function exif_parse_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match(
        '/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/',
        $raw,
        $m
    )) {
        return null;
    }
    [, $y, $mo, $d, $h, $mi, $s] = $m;

    if ((int) $y < 1970 || (int) $mo < 1 || (int) $mo > 12 || (int) $d < 1 || (int) $d > 31) {
        return null;
    }

    // checkdate() catches "2024:02:30" — a corrupt or hand-edited EXIF block
    // — which the regex above accepts but no calendar does.
    if (!checkdate((int) $mo, (int) $d, (int) $y)) {
        return null;
    }

    return sprintf('%s-%s-%s %s:%s:%s', $y, $mo, $d, $h, $mi, $s);
}

/**
 * One coordinate (lat or lon) from EXIF's [deg, min, sec] DMS array + N/S/E/W
 * ref, to signed decimal degrees. Null for anything malformed rather than a
 * wrong number silently placed on a map.
 *
 * @param mixed $dms Expected: array of 3 values, each a "num/den" fraction
 *                    string (what exif_read_data() actually returns) or a
 *                    plain numeric string/float — both are tolerated since
 *                    the exact string shape has drifted across PHP versions.
 * @param ?string $ref 'N'/'S'/'E'/'W'
 */
function exif_gps_coordinate($dms, ?string $ref): ?float
{
    if (!is_array($dms) || count($dms) !== 3 || $ref === null || $ref === '') {
        return null;
    }

    $deg = exif_gps_fraction($dms[0] ?? null);
    $min = exif_gps_fraction($dms[1] ?? null);
    $sec = exif_gps_fraction($dms[2] ?? null);
    if ($deg === null || $min === null || $sec === null) {
        return null;
    }

    $decimal = $deg + ($min / 60) + ($sec / 3600);

    // A degree value outside the physically possible range means the block
    // was misread, not that the photo was taken off the surface of Earth.
    if ($decimal < 0 || $decimal > 180) {
        return null;
    }

    $sign = in_array(strtoupper(substr($ref, 0, 1)), array('S', 'W'), true) ? -1 : 1;
    return round($decimal * $sign, 6);
}

/** "46/1" -> 46.0, "302/100" -> 3.02, already-numeric input passed through. */
function exif_gps_fraction($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    if (!is_string($value) || $value === '') {
        return null;
    }
    if (str_contains($value, '/')) {
        [$num, $den] = array_pad(explode('/', $value, 2), 2, '0');
        $den = (float) $den;
        // A zero denominator is a malformed fraction, not a divide-by-zero
        // to let PHP turn into INF and round() into garbage.
        return $den == 0.0 ? null : ((float) $num / $den);
    }
    return is_numeric($value) ? (float) $value : null;
}
