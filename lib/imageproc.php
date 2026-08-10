<?php
/* Image validation, thumbnail generation and crop — Phase 2's upload
 * pipeline.
 *
 * PORTED PATTERN, ADAPTED SHAPE, FROM INSPIRATION BOARD's lib/imageproc.php:
 * the byte-sniffing (never trust the client's name or MIME), the
 * generated-filename-only path scheme, and the crop math (normalized 0..1
 * rect, orientation baked in before the cut) all come from there. What's
 * DELIBERATELY NOT carried over, per PLAN.md's brief:
 *
 *   - No queue, no 'pending' status, no worker.php. This runs INLINE, during
 *     the upload request: sniff, read EXIF, write one thumbnail, done. There
 *     is no vision/color pipeline here to defer work for, so there's nothing
 *     for a queue to buy.
 *   - One derivative (thumb), not two. Inspiration keeps a 1600px "detail"
 *     copy so the annotator can crop without holding the full original in
 *     the browser. Keepsake's original stays on disk permanently (Phase 7
 *     needs full resolution for print) and crop.js crops directly against
 *     THAT — see photo-batch.js — so there is no separate detail copy to
 *     generate or to keep in sync.
 *   - No color/vision analysis, no tags. Not this app's job.
 */

declare(strict_types=1);

/** Hard ceiling per uploaded file. Config over hardcoding — see config.example.php. */
function imageproc_max_bytes(): int
{
    return max(1, (int) cfg('uploads.max_bytes', 25 * 1024 * 1024));
}

/** Sub-directories of public/uploads/ we are allowed to write into. */
const IMAGEPROC_DIRS = array('original', 'thumb');

/** Smallest crop accepted, as a fraction of the source edge — matches crop.js's MIN_FRAC. */
const IMAGEPROC_MIN_CROP = 0.02;

/* ------------------------------------------------------------ detection */

/** Content types we accept => the canonical extension we store them under. */
function imageproc_accepted_types(): array
{
    return array(
        'image/jpeg'          => 'jpg',
        'image/png'           => 'png',
        'image/webp'          => 'webp',
        'image/heic'          => 'heic',
        'image/heif'          => 'heic',
        'image/heic-sequence' => 'heic',
        'image/heif-sequence' => 'heic',
        'image/avif'          => 'heic',
    );
}

/**
 * ISO-BMFF brands that mean "this is a HEIF-family still image". Read from
 * the `ftyp` box rather than trusting finfo, which is missing or out of date
 * on plenty of shared hosts — same reasoning and same brand list as
 * Inspiration Board's imageproc_heif_brand().
 */
function imageproc_heif_brand(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = fread($fh, 32);
    fclose($fh);

    if ($head === false || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
        return null;
    }

    $major  = strtolower(substr($head, 8, 4));
    $brands = array(
        'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs',
        'mif1', 'msf1', 'avif', 'avis',
    );

    return in_array($major, $brands, true) ? $major : null;
}

/**
 * Identify a file by its bytes, never by the client-supplied name or MIME.
 *
 * @return array{mime:string, ext:string, family:'raster'|'heif'}|null
 */
function imageproc_sniff(string $path): ?array
{
    if (!is_file($path) || filesize($path) === 0) {
        return null;
    }

    $accepted = imageproc_accepted_types();

    $brand = imageproc_heif_brand($path);
    if ($brand !== null) {
        return array(
            'mime'   => ($brand === 'avif' || $brand === 'avis') ? 'image/avif' : 'image/heic',
            'ext'    => 'heic',
            'family' => 'heif',
        );
    }

    $info = @getimagesize($path);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return null;
    }

    $byGd = array(
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    );
    $type = $info[2] ?? 0;
    if (!isset($byGd[$type])) {
        return null;
    }

    $mime = null;
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);
        if (is_string($detected) && $detected !== '') {
            $mime = strtolower($detected);
        }
    }
    $sniffed = $byGd[$type];
    if ($mime !== null && isset($accepted[$mime]) && $mime !== $sniffed) {
        return null;
    }

    return array('mime' => $sniffed, 'ext' => $accepted[$sniffed], 'family' => 'raster');
}

/** True when this build of Imagick can actually decode HEIF, not just list it. */
function imageproc_heif_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!class_exists('Imagick')) {
        return $ok = false;
    }
    $formats = @Imagick::queryFormats('HEI*');
    return $ok = (is_array($formats) && $formats !== array());
}

/* ------------------------------------------------------------ safe paths */

/** A generated, name-independent basename — never derived from client input. */
function imageproc_new_slug(): string
{
    return bin2hex(random_bytes(8));
}

function imageproc_is_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $slug);
}

function imageproc_upload_path(string $dir, string $slug, string $ext): string
{
    if (!in_array($dir, IMAGEPROC_DIRS, true)) {
        throw new RuntimeException('bad upload directory: ' . $dir);
    }
    if (!imageproc_is_slug($slug)) {
        throw new RuntimeException('bad slug');
    }
    if (!preg_match('/^[a-z0-9]{2,5}$/', $ext)) {
        throw new RuntimeException('bad extension');
    }
    return UPLOAD_DIR . '/' . $dir . '/' . $slug . '.' . $ext;
}

/** The public-relative form of an upload path, e.g. "uploads/thumb/ab.webp". */
function imageproc_relative_path(string $dir, string $slug, string $ext): string
{
    imageproc_upload_path($dir, $slug, $ext);      // reuses the validation
    return 'uploads/' . $dir . '/' . $slug . '.' . $ext;
}

/**
 * Resolve a stored relative path to a real file inside uploads/, or null.
 * Anything that escapes the uploads directory is refused, so a poisoned
 * original_path column can't make anything read outside it.
 */
function imageproc_resolve_upload(?string $rel): ?string
{
    if ($rel === null || $rel === '' || str_contains($rel, "\0")) {
        return null;
    }
    $full = realpath(PUBLIC_DIR . '/' . $rel);
    $base = realpath(UPLOAD_DIR);
    if ($full === false || $base === false) {
        return null;
    }
    if (!str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
        error_log('imageproc: refusing path outside uploads: ' . $rel);
        return null;
    }
    return is_file($full) ? $full : null;
}

/** mkdir -p for one of our upload sub-directories. */
function imageproc_ensure_dir(string $dir): string
{
    if (!in_array($dir, IMAGEPROC_DIRS, true)) {
        throw new RuntimeException('bad upload directory: ' . $dir);
    }
    $path = UPLOAD_DIR . '/' . $dir;
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('cannot create ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('not writable: ' . $path);
    }
    return $path;
}

/* -------------------------------------------------------- probing/orient */

/**
 * Display-oriented width/height of a source file: getimagesize()'s raw
 * dimensions, swapped if EXIF says the image is rotated 90/270 for display.
 *
 * Stored in photos.width/height so "width < height = portrait" (schema.sql's
 * own comment on those columns) is true of what a viewer SEES, without
 * every later reader having to re-check an orientation tag. The original
 * file on disk is left exactly as uploaded — see the header of this file.
 *
 * @return array{0:int,1:int}|null
 */
function imageproc_probe_dimensions(string $absPath): ?array
{
    $info = @getimagesize($absPath);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return null;
    }
    [$w, $h] = array($info[0], $info[1]);

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($absPath);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($o, array(5, 6, 7, 8), true)) {
            [$w, $h] = array($h, $w);
        }
    }

    return array($w, $h);
}

/* ------------------------------------------------------------ thumbnail */

/**
 * Produce the one thumbnail copy of an original. Returns the public-relative
 * thumb path. Throws on failure (caller decides whether that fails the whole
 * upload row or just leaves thumb_path null — see photos-upload.php).
 */
function imageproc_make_thumbnail(string $srcAbs, string $slug, array $sniff): string
{
    $thumbMax = max(32, (int) cfg('uploads.thumb_max', 480));
    $quality  = min(100, max(1, (int) cfg('uploads.webp_quality', 82)));

    imageproc_ensure_dir('thumb');
    $thumbAbs = imageproc_upload_path('thumb', $slug, 'webp');

    $written = array();
    try {
        if (class_exists('Imagick')) {
            imageproc_thumbnail_imagick($srcAbs, $thumbAbs, $thumbMax, $quality, $written);
        } elseif ($sniff['family'] === 'raster') {
            imageproc_thumbnail_gd($srcAbs, $thumbAbs, $thumbMax, $quality, $written);
        } else {
            throw new RuntimeException('heif_decode_unavailable');
        }
    } catch (Throwable $e) {
        foreach ($written as $path) {
            @unlink($path);
        }
        if ($sniff['family'] === 'heif' && !imageproc_heif_supported()) {
            throw new RuntimeException('heif_decode_unavailable', 0, $e);
        }
        throw $e;
    }

    return imageproc_relative_path('thumb', $slug, 'webp');
}

/** Longest-edge box fit that never upscales. @return array{0:int,1:int} */
function imageproc_fit(int $w, int $h, int $max): array
{
    if ($w <= 0 || $h <= 0) {
        throw new RuntimeException('zero-sized image');
    }
    $longest = max($w, $h);
    if ($longest <= $max) {
        return array($w, $h);
    }
    $scale = $max / $longest;
    return array(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
}

function imageproc_thumbnail_imagick(
    string $srcAbs,
    string $thumbAbs,
    int $max,
    int $quality,
    array &$written
): void {
    $im = new Imagick();
    try {
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        $im->readImage($srcAbs);

        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
            $frame = $im->getImage();
            $im->clear();
            $im = $frame;
        }

        imageproc_imagick_orient($im);

        if ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        if ($im->getImageAlphaChannel()) {
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->clear();
            $im = $flat;
        }

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w < 1 || $h < 1) {
            throw new RuntimeException('zero-sized image');
        }

        $im->stripImage();
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality($quality);
        $im->setOption('webp:method', '4');

        [$tw, $th] = imageproc_fit($w, $h, $max);
        $im->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
        $im->setImagePage(0, 0, 0, 0);
        if (!$im->writeImage($thumbAbs)) {
            throw new RuntimeException('thumb write failed');
        }
        $written[] = $thumbAbs;
    } finally {
        $im->clear();
    }
}

/** Bake EXIF orientation into the pixels (Imagick path). */
function imageproc_imagick_orient(Imagick $im): void
{
    try {
        $orientation = $im->getImageOrientation();
    } catch (Throwable $e) {
        return;
    }
    if ($orientation === Imagick::ORIENTATION_TOPLEFT || $orientation === Imagick::ORIENTATION_UNDEFINED) {
        return;
    }

    $white = new ImagickPixel('white');
    switch ($orientation) {
        case Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
        case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage($white, 180); break;
        case Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
        case Imagick::ORIENTATION_LEFTTOP:     $im->flopImage(); $im->rotateImage($white, -90); break;
        case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage($white, 90); break;
        case Imagick::ORIENTATION_RIGHTBOTTOM: $im->flopImage(); $im->rotateImage($white, 90); break;
        case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage($white, -90); break;
    }
    $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

/** GD fallback. Cannot read HEIF; callers must not route HEIF here. */
function imageproc_thumbnail_gd(string $srcAbs, string $thumbAbs, int $max, int $quality, array &$written): void
{
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('gd_webp_unavailable');
    }

    $data = @file_get_contents($srcAbs);
    if ($data === false) {
        throw new RuntimeException('unreadable original');
    }
    $src = @imagecreatefromstring($data);
    unset($data);
    if ($src === false) {
        throw new RuntimeException('gd decode failed');
    }

    try {
        $src = imageproc_gd_orient($src, $srcAbs);

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $flat = imagecreatetruecolor($srcW, $srcH);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $src, 0, 0, 0, 0, $srcW, $srcH);
        imagedestroy($src);
        $src = $flat;

        [$w, $h] = imageproc_fit($srcW, $srcH, $max);
        $out = imagescale($src, $w, $h, IMG_BICUBIC_FIXED);
        if ($out === false) {
            throw new RuntimeException('gd resize failed');
        }
        $ok = imagewebp($out, $thumbAbs, $quality);
        imagedestroy($out);
        if (!$ok) {
            throw new RuntimeException('gd webp write failed');
        }
        $written[] = $thumbAbs;
    } finally {
        if ($src instanceof GdImage) {
            imagedestroy($src);
        }
    }
}

function imageproc_gd_orient(GdImage $img, string $srcAbs): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($srcAbs);
    $o    = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    if ($o <= 1 || $o > 8) {
        return $img;
    }

    $rotate = array(3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90);
    $flip   = array(2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_HORIZONTAL,
                    5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL);

    if (isset($rotate[$o])) {
        $rotated = imagerotate($img, $rotate[$o], 0);
        if ($rotated !== false) {
            imagedestroy($img);
            $img = $rotated;
        }
    }
    if (isset($flip[$o])) {
        imageflip($img, $flip[$o]);
    }
    return $img;
}

/* -------------------------------------------------------------------- crop */

/**
 * Crop a photo's ORIGINAL in place (new file, new slug — the row's
 * original_path is updated by the caller) and regenerate its thumbnail.
 *
 * The rect is normalized (0..1 fractions), matching crop.js's openCropper()
 * return value — see that file's own header for why fractions and not
 * pixels. Orientation is baked in BEFORE the crop: the rect was drawn on an
 * already-upright <img>, so cropping a still-rotated source would cut the
 * wrong side of the photo — same reasoning, same order of operations, as
 * Inspiration Board's imageproc_crop().
 *
 * @return array{original_path:string, thumb_path:string, width:int, height:int}
 */
function imageproc_crop_photo(string $srcAbs, array $rect, array $sniff): array
{
    $x = max(0.0, min(1.0, (float) $rect['x']));
    $y = max(0.0, min(1.0, (float) $rect['y']));
    $w = max(0.0, min(1.0 - $x, (float) $rect['w']));
    $h = max(0.0, min(1.0 - $y, (float) $rect['h']));

    if ($w < IMAGEPROC_MIN_CROP || $h < IMAGEPROC_MIN_CROP) {
        throw new RuntimeException('crop_too_small');
    }

    imageproc_ensure_dir('original');
    $slug   = imageproc_new_slug();
    // The crop bakes orientation into the pixels, so the output is always a
    // plain, already-upright JPEG regardless of what the source was —
    // simpler than trying to preserve every input format's own encoder.
    $outAbs = imageproc_upload_path('original', $slug, 'jpg');

    $dims = class_exists('Imagick')
        ? imageproc_crop_imagick($srcAbs, $outAbs, $x, $y, $w, $h)
        : imageproc_crop_gd($srcAbs, $outAbs, $x, $y, $w, $h, $srcAbs);

    $thumbPath = imageproc_make_thumbnail($outAbs, $slug, array('family' => 'raster') + $sniff);

    return array(
        'original_path' => imageproc_relative_path('original', $slug, 'jpg'),
        'thumb_path'    => $thumbPath,
        'width'         => $dims[0],
        'height'        => $dims[1],
    );
}

/**
 * A cropped copy of $srcAbs written to a TEMP file — never touches
 * public/uploads/, the photos table, or a thumbnail. For lib/pdfexport.php:
 * a page's composition (lib/layout_render.php) always needs a real raster
 * cropped to an exact target box before it can go in a PDF table cell (mPDF
 * has no `object-fit` equivalent), whether that box comes from Kathryn's own
 * manual crop_x/y/w/h or from layout_auto_crop_rect()'s auto-fit — this is
 * the one place both paths end up.
 *
 * Reuses the exact same primitives imageproc_crop_photo() uses
 * (imageproc_crop_imagick()/imageproc_crop_gd()) — same EXIF-orientation
 * handling, same quality — just pointed at sys_get_temp_dir() instead of
 * imageproc_upload_path('original', ...), so nothing about export leaves a
 * trace in the app's own storage or database.
 *
 * @param array{x:float,y:float,w:float,h:float} $rect
 * @return string|null absolute path to the temp JPEG, or null on failure
 *   (caller falls back to the uncropped original — see pdf_photo_html()).
 */
function imageproc_crop_to_temp(string $srcAbs, array $rect, ?int $maxW = null, ?int $maxH = null): ?string
{
    $x = max(0.0, min(1.0, (float) $rect['x']));
    $y = max(0.0, min(1.0, (float) $rect['y']));
    $w = max(IMAGEPROC_MIN_CROP, min(1.0 - $x, (float) $rect['w']));
    $h = max(IMAGEPROC_MIN_CROP, min(1.0 - $y, (float) $rect['h']));

    $dir = sys_get_temp_dir() . '/keepsake-export-crops';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }

    $outAbs = $dir . '/' . bin2hex(random_bytes(8)) . '.jpg';

    try {
        // Same choice imageproc_crop_photo() makes, for the same reason.
        class_exists('Imagick')
            ? imageproc_crop_imagick($srcAbs, $outAbs, $x, $y, $w, $h, $maxW, $maxH)
            : imageproc_crop_gd($srcAbs, $outAbs, $x, $y, $w, $h, $srcAbs, $maxW, $maxH);
    } catch (Throwable $e) {
        error_log('imageproc_crop_to_temp: ' . $e->getMessage());
        return null;
    }

    return is_file($outAbs) ? $outAbs : null;
}

/** @return array{0:int,1:int} */
function imageproc_crop_imagick(string $srcAbs, string $outAbs, float $x, float $y, float $w, float $h, ?int $maxW = null, ?int $maxH = null): array
{
    $im = new Imagick();
    try {
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);
        $im->readImage($srcAbs);

        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
            $frame = $im->getImage();
            $im->clear();
            $im = $frame;
        }

        imageproc_imagick_orient($im);

        $sw = $im->getImageWidth();
        $sh = $im->getImageHeight();
        $cw = max(1, (int) round($sw * $w));
        $ch = max(1, (int) round($sh * $h));
        $cx = max(0, min($sw - $cw, (int) round($sw * $x)));
        $cy = max(0, min($sh - $ch, (int) round($sh * $y)));

        $im->cropImage($cw, $ch, $cx, $cy);
        $im->setImagePage(0, 0, 0, 0);

        /* Down to what the page can actually show, if the caller said what that
         * is. A phone photo is routinely 3-4x the pixels a 300dpi print of a
         * quarter-page slot can use, and embedding the surplus is what turned
         * Kathryn's book into a 400 MB download. Scaled AFTER the crop, so the
         * limit describes what lands on paper rather than what was on the
         * card. */
        if ($maxW !== null && $maxH !== null) {
            $cur = array($im->getImageWidth(), $im->getImageHeight());
            if ($cur[0] > $maxW || $cur[1] > $maxH) {
                $im->resizeImage($maxW, $maxH, Imagick::FILTER_LANCZOS, 1, true);
                $cw = $im->getImageWidth();
                $ch = $im->getImageHeight();
            }
        }

        /* To sRGB before the profile is stripped. iPhones shoot Display P3;
         * dropping that profile without converting leaves P3 numbers to be read
         * as sRGB, which is what made the reds in her book look scorched. */
        try {
            if ($im->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (Throwable $e) {
            // An unreadable colorspace is not worth failing an export over.
        }

        $im->stripImage();
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(88);
        if (!$im->writeImage($outAbs)) {
            throw new RuntimeException('crop_write_failed');
        }
        return array($cw, $ch);
    } finally {
        $im->clear();
    }
}

/** @return array{0:int,1:int} */
function imageproc_crop_gd(string $srcAbs, string $outAbs, float $x, float $y, float $w, float $h, string $orientSrc, ?int $maxW = null, ?int $maxH = null): array
{
    $data = @file_get_contents($srcAbs);
    if ($data === false) {
        throw new RuntimeException('crop_source_unreadable');
    }
    $img = @imagecreatefromstring($data);
    unset($data);
    if ($img === false) {
        throw new RuntimeException('crop_decode_failed');
    }

    try {
        $img = imageproc_gd_orient($img, $orientSrc);

        $sw = imagesx($img);
        $sh = imagesy($img);
        $cw = max(1, (int) round($sw * $w));
        $ch = max(1, (int) round($sh * $h));
        $cx = max(0, min($sw - $cw, (int) round($sw * $x)));
        $cy = max(0, min($sh - $ch, (int) round($sh * $y)));

        $cropped = imagecrop($img, array('x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch));
        if ($cropped === false) {
            throw new RuntimeException('crop_failed');
        }

        /* Same ceiling the Imagick path applies, for hosts without it. GD has no
         * colour management, so this scales but cannot convert — a wide-gamut
         * original will still print a little hot here. Imagick is what Kathryn's
         * host has, and it does convert. */
        if ($maxW !== null && $maxH !== null && ($cw > $maxW || $ch > $maxH)) {
            $ratio = min($maxW / $cw, $maxH / $ch);
            $nw    = max(1, (int) round($cw * $ratio));
            $nh    = max(1, (int) round($ch * $ratio));
            $small = imagescale($cropped, $nw, $nh, IMG_BICUBIC_FIXED);
            if ($small !== false) {
                imagedestroy($cropped);
                $cropped = $small;
                $cw = $nw;
                $ch = $nh;
            }
        }

        try {
            if (!imagejpeg($cropped, $outAbs, 92)) {
                throw new RuntimeException('crop_write_failed');
            }
        } finally {
            imagedestroy($cropped);
        }

        return array($cw, $ch);
    } finally {
        imagedestroy($img);
    }
}

/** Read back a produced image's dimensions — used by tests and sanity checks. */
function imageproc_dimensions(string $abs): ?array
{
    $info = @getimagesize($abs);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return null;
    }
    return array((int) $info[0], (int) $info[1]);
}
