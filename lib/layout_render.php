<?php
/* PHOTO WINDOWING — how a photo is fitted into the rectangle the composer gave
 * it. Two pure functions, nothing else.
 *
 * WHAT THIS FILE USED TO BE. Until Round 6 it also built a page: a tree of
 * binary splits whose nodes carried nominal role aspects (a portrait is 2:3, a
 * landscape 3:2, whatever the photo actually was), which public/layout.php
 * resolved with flexbox and lib/pdfexport.php resolved again with nested
 * tables. That model is gone. lib/compose.php now solves the page from the
 * photos' REAL ratios and hands both renderers the same rectangles, so the
 * tree, its aspect algebra and its geometry resolver were deleted rather than
 * left sitting there looking authoritative. Their history is in git; the
 * reasoning that outlived them is in compose.php's header.
 *
 * WHAT SURVIVED, AND WHY BOTH STILL EARN THEIR KEEP:
 *
 *   layout_auto_crop_rect() — a centred crop to a target shape. Under the new
 *     rules a photo's rectangle IS its own shape almost always, so this is a
 *     no-op for all but a handful of photos: it does real work only where the
 *     composer matched a photo to same-shape neighbours (4 of Kathryn's 123).
 *     It stays because mPDF has no object-fit and someone has to cut the pixels.
 *
 *   layout_crop_css() — reproduces an arbitrary crop rect in the browser. This
 *     is now exclusively for Kathryn's MANUAL "adjust crop" override, which is
 *     the only cropping the app does that she asked for by hand. The engine no
 *     longer drives it.
 */

declare(strict_types=1);

/**
 * The tightest centered crop of a $srcW x $srcH photo that fills a box of
 * $targetAspect (width/height) — the same result `object-fit: cover` gives
 * a browser for free, computed here because lib/pdfexport.php has to
 * actually cut the pixels (mPDF has no object-fit equivalent). Normalized
 * (0..1 fractions), same convention as crop.js/imageproc_crop_photo()'s
 * manual crop rect, so a manually-adjusted rect and an auto one are
 * interchangeable to every caller.
 *
 * @return array{x:float,y:float,w:float,h:float}
 */
function layout_auto_crop_rect(int $srcW, int $srcH, float $targetAspect): array
{
    if ($srcW <= 0 || $srcH <= 0 || $targetAspect <= 0) {
        return array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0);
    }

    $srcAspect = $srcW / $srcH;

    if ($srcAspect > $targetAspect) {
        // Source is relatively wider than the target box: keep full height,
        // crop the sides.
        $w = $targetAspect / $srcAspect;
        return array('x' => (1.0 - $w) / 2.0, 'y' => 0.0, 'w' => $w, 'h' => 1.0);
    }

    // Source is relatively taller (or equal): keep full width, crop top/bottom.
    $h = $srcAspect / $targetAspect;
    return array('x' => 0.0, 'y' => (1.0 - $h) / 2.0, 'w' => 1.0, 'h' => $h);
}

/**
 * CSS for reproducing an arbitrary crop rect via `background-image`, used
 * ONLY when a slot carries a Kathryn-adjusted crop (book_page_photos'
 * crop_x/y/w/h) — see public/layout.php's render_photo_slot(). An ordinary
 * <img> with `object-fit: cover; object-position: 50% 50%` already
 * reproduces the DEFAULT centered auto-crop for free and is used for every
 * slot that has no manual override; object-position alone can't reproduce a
 * manual crop because it can only slide the image, never zoom it in past
 * what `cover` already picks — the standard fix is a background-image sized
 * so the rect exactly fills the box.
 *
 * @param array{x:float,y:float,w:float,h:float} $rect
 * @return array{size:string,position:string}
 */
function layout_crop_css(array $rect): array
{
    $w = max(0.02, min(1.0, (float) $rect['w']));
    $h = max(0.02, min(1.0, (float) $rect['h']));
    $x = max(0.0, min(1.0 - $w, (float) $rect['x']));
    $y = max(0.0, min(1.0 - $h, (float) $rect['y']));

    $sizeX = 100.0 / $w;
    $sizeY = 100.0 / $h;
    // Standard background-position-as-fraction-of-overflow formula: 0% pins
    // the image's left/top edge to the box's, 100% pins its right/bottom.
    $posX = $w >= 1.0 ? 50.0 : ($x / (1.0 - $w)) * 100.0;
    $posY = $h >= 1.0 ? 50.0 : ($y / (1.0 - $h)) * 100.0;

    return array(
        'size'     => round($sizeX, 2) . '% ' . round($sizeY, 2) . '%',
        'position' => round($posX, 2) . '% ' . round($posY, 2) . '%',
    );
}

/**
 * Where the cover's title band sits, as FRACTIONS of the page.
 *
 * Lives here rather than in lib/pdfexport.php because two renderers need it:
 * the PDF converts these to millimetres, and public/layout.php's on-screen
 * cover preview turns the same numbers into percentages. Kathryn asked for that
 * preview precisely so she can see how the title falls on the photo before
 * exporting, which is only worth anything if the two agree — and they can only
 * be relied on to agree if neither is allowed its own copy of the numbers.
 *
 * The band is taller when there is a subtitle because there are two lines to
 * hold, and it is inset by the safety margin on three sides so a printer's trim
 * can never take a letter off.
 *
 * @param float $safeFrac page margin (bleed + safety) as a fraction of the page
 * @return array{inset:float,height:float,top:float}
 */
function cover_band_metrics(bool $hasSubtitle, float $safeFrac): array
{
    $height = $hasSubtitle ? 0.153 : 0.117;

    return array(
        'inset'  => $safeFrac,
        'height' => $height,
        'top'    => 1.0 - $safeFrac - $height,
    );
}
