<?php
/* Print-ready PDF export — Phase 7, brief §5.5.
 *
 * READS, NEVER RECOMPUTES. Everything this file renders was already decided
 * by Phase 5's layout_generate()/layout_reflow_from() and possibly hand-
 * adjusted by Phase 6's drag-and-drop — this is exactly the arrangement
 * lib/repo.php's book_layout_pages_with_content() already returns, turned
 * into PDF drawing calls instead of HTML for a browser. If a page looks
 * wrong here, the bug is almost never in this file — it's in what got
 * written to book_pages/book_page_photos upstream.
 *
 * WHICH LAYOUT: the year's year_projects.active_book_layout_id, always —
 * never "the newest version" and never a fresh call into lib/layout.php.
 * schema.sql's own comment on that column says why ("most recent" and "the
 * one I'm working from" are different facts once more than one version
 * exists) and public/layout.php already treats it as the thing Kathryn
 * picks with "Use this one"; export honors that same choice rather than
 * re-deciding it.
 *
 * LIBRARY: mPDF (composer require mpdf/mpdf — this app's first Composer
 * dependency, per PLAN.md's architecture note: "introduce Composer only
 * when a phase actually needs a package"). Chosen over TCPDF/FPDF because
 * every page type here is naturally an HTML/CSS layout problem (a photo
 * grid, a text card, a snapshot's two-column fact sheet) and mPDF renders
 * HTML directly rather than requiring per-element Image()/Cell() drawing
 * calls; it is pure PHP with no external binary to shell out to, matching
 * this app's "no build step" philosophy better than a wkhtmltopdf wrapper
 * would. Neither sibling repo (personal-cms, inspiration) uses a PDF
 * library or Composer at all — personal-cms's own CLAUDE.md says its vendored
 * PHPMailer is deliberately "no Composer, no autoloader" — so there was
 * nothing to match; this is the suite's first Composer usage, period.
 *
 * GEOMETRY: every page in the exported PDF — cover, title, and every
 * book_pages row — is rendered at the SAME size: the configured trim size
 * plus bleed on all four edges (config.example.php's 'export' block). That
 * matches how Lulu and Mixam both actually want an interior file: not a
 * trim-sized page with separate PDF trim-box metadata, but one PDF page
 * per book page, physically sized at trim+bleed, content kept inside a
 * safety margin measured in from the TRIM edge. See pdf_export_geometry()
 * for the exact math and the citations for both printers' numbers.
 *
 * ONE DELIBERATE SIMPLIFICATION: only the COVER page bleeds a photo to the
 * true page edge (a book cover conventionally does; it uses a single
 * position:fixed wrapper spanning the whole physical page — see
 * pdf_render_cover_html()'s own comment for an mPDF quirk that shape avoids).
 * Every interior page's content — photo grids, text cards, snapshot
 * templates — stays inside the safety-margin box using mPDF's ordinary
 * document flow. Interior photos in this build are not edge-to-edge;
 * nothing in brief §5.5 requires it, and it keeps every page renderer
 * simple, uniform, and easy to reason about against the page-count/
 * dimension checks in tools/verify-export.php. Worth reconsidering later if
 * Kathryn wants a more magazine-style bleed treatment on interior spreads —
 * flagged in the Phase 7 report, not decided unilaterally here.
 *
 * ROUND 6: a page's shape comes from lib/compose.php's solved rectangles,
 * the same ones public/layout.php draws — see pdf_render_photos_page_html().
 *
 * A photo is pre-cropped (imageproc_crop_to_temp()) ONLY when its box is not
 * its own shape, which now means only where the composer matched it to
 * same-shape neighbours. Everything else is embedded untouched. That is a
 * correctness point as well as a speed one: cropping is done here rather than
 * with `object-fit` because mPDF's <img> support does not honour it, but
 * running every photo through a decode and re-encode it did not need cost
 * about 0.4s each and re-compressed the original for nothing. On a 123-photo
 * book that was ~50 seconds of waste — enough to trip the execution limit on
 * shared hosting and fail the export outright, which is exactly what it did.
 * See pdf_resolve_crop_rect().
 *
 * FAIL SOFT, PER PLAN.md:
 *   - No cover photo picked (year_projects.cover_photo_id IS NULL): the
 *     cover page still renders — a plain background carrying just the year
 *     and subtitle, no photo — rather than being skipped or crashing the
 *     export. A book with no cover art chosen yet is still a valid, useful
 *     PDF to look at; disappearing the cover page entirely would silently
 *     shift every later page's number by one relative to what Kathryn saw
 *     on public/layout.php.
 *   - A photo row whose original_path/thumb_path no longer resolves to a
 *     real file on disk (moved, deleted out from under the database, a
 *     synthetic test row): that ONE slot renders as a labeled placeholder
 *     box instead of an <img>, and the caption (if any) still prints. This
 *     is deliberately visible rather than silently blank — a missing photo
 *     in a proof PDF is exactly the kind of thing Kathryn should notice
 *     before paying to print it, not something export should paper over.
 *   - A year whose active layout has zero book_pages (nothing eligible was
 *     ever generated): export still produces a valid, short PDF — cover +
 *     title only. This is what layout_generate() itself already does for an
 *     empty year (tools/verify-layout.php's $emptyRun case), so a downstream
 *     empty PDF is the honest continuation of that, not a new failure mode.
 *   - No year project for the given year, or a year project with no active
 *     layout at all (nothing has ever been generated): THESE throw a plain
 *     RuntimeException with a short code — 'no_year_project' /
 *     'no_active_layout' — same house pattern as lib/imageproc.php. This is
 *     the "clear early error" case, not a placeholder: there is nothing
 *     coherent to prepend page numbers to, so the right move is telling
 *     Kathryn to generate/activate a layout first, the same way
 *     public/layout.php already asks her to.
 *
 * A big oversized quote/anecdote/snapshot-notes body (this app's TEXT
 * columns have no length cap) is truncated at PDF_EXPORT_MAX_TEXT_CHARS —
 * a safety valve, not a normal-case behavior. Every realistic quote,
 * anecdote or notes field is far under it; it exists only so a pathological
 * input can't make mPDF overflow a "one page" div onto a second physical
 * page and desynchronize the page count this file promises the caller.
 */

declare(strict_types=1);

require_once __DIR__ . '/imageproc.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/layout_render.php';

/** See this file's header. Generous on purpose — never hit by real content. */
const PDF_EXPORT_MAX_TEXT_CHARS = 4000;

/* ------------------------------------------------------------- geometry */

/**
 * Every measurement export needs, derived once from config.example.php's
 * 'export' block. Inches are the config's own unit (matching how both
 * printers publish their specs); mPDF's page format wants millimeters.
 */
function pdf_export_geometry(): array
{
    $trimW  = (float) cfg('export.trim_width_in', 8.5);
    $trimH  = (float) cfg('export.trim_height_in', 8.5);
    $bleed  = (float) cfg('export.bleed_in', 0.125);
    $safety = (float) cfg('export.safety_margin_in', 0.5);

    $pageWIn = $trimW + (2 * $bleed);
    $pageHIn = $trimH + (2 * $bleed);
    $mmPerIn = 25.4;

    return array(
        'trim_width_in'    => $trimW,
        'trim_height_in'   => $trimH,
        'bleed_in'         => $bleed,
        'safety_margin_in' => $safety,
        'page_width_in'    => $pageWIn,
        'page_height_in'   => $pageHIn,
        'page_width_mm'    => $pageWIn * $mmPerIn,
        'page_height_mm'   => $pageHIn * $mmPerIn,
        // Safety margin is measured from the TRIM edge (brief/both printers'
        // own convention), so from the outer bleed edge — where mPDF's page
        // margins actually start counting from — it's bleed + safety.
        'content_margin_mm' => ($bleed + $safety) * $mmPerIn,
        // The box a "photos" page's composition tree actually gets to work
        // with — mPDF's own margin_left/right/top/bottom (pdf_export_build())
        // are already set to content_margin_mm, so content written inside
        // ordinary flow is automatically inset that far; this is that same
        // box's size, for lib/layout_render.php's geometry math.
        'content_width_mm'  => $pageWIn * $mmPerIn - (2 * ($bleed + $safety) * $mmPerIn),
        'content_height_mm' => $pageHIn * $mmPerIn - (2 * ($bleed + $safety) * $mmPerIn),
    );
}

/* ------------------------------------------------------------- helpers */

/** Local escape helper — lib/ files don't depend on public/'s h(), see
 *  lib/layout.php and friends for the same self-containment. */
function pdf_esc(?string $raw): string
{
    return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
}

/** Safety-valve truncation — see this file's header. */
function pdf_clip_text(?string $text): string
{
    $text = (string) $text;
    if (mb_strlen($text) > PDF_EXPORT_MAX_TEXT_CHARS) {
        $text = mb_substr($text, 0, PDF_EXPORT_MAX_TEXT_CHARS - 1) . '…';
    }
    return $text;
}

/**
 * A photo row's ORIGINAL file on disk (print quality — schema.sql's own
 * comment on photos.original_path: "Phase 7's PDF export needs full
 * resolution"), or null if it can't be resolved — moved, deleted, or (in
 * tools/verify-export.php's synthetic rows) never real to begin with.
 * imageproc_resolve_upload() already refuses anything outside uploads/, so
 * this can't be pointed outside the app's own storage even by a poisoned row.
 */
function pdf_resolve_photo_file(array $photo): ?string
{
    $path = $photo['original_path'] ?? null;
    return $path !== null ? imageproc_resolve_upload((string) $path) : null;
}

function pdf_fmt_date(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    $ts = strtotime($ymd);
    return $ts === false ? $ymd : date('F j, Y', $ts);
}

/** One <img> or, if the file can't be found, a visible placeholder box that
 *  still carries the caption — see this file's header on why a missing
 *  photo stays visible instead of silently vanishing. */
function pdf_photo_html(array $photo, string $imgStyle, string $placeholderStyle): string
{
    $abs = pdf_resolve_photo_file($photo);
    if ($abs !== null) {
        return '<img src="' . pdf_esc($abs) . '" style="' . pdf_esc($imgStyle) . '">';
    }
    return '<div style="' . pdf_esc($placeholderStyle) . '">Photo not found on disk</div>';
}

/* ------------------------------------------------------------- pages */

/**
 * Cover page. Bleeds the cover photo to the true physical edge — the one
 * place this file intentionally breaks the "stay inside the safety margin"
 * rule, because a printed book cover conventionally runs edge to edge.
 *
 * IMPLEMENTATION NOTE, hard-won empirically (see tools/verify-export.php's
 * "single-fixed-wrapper shape" checks and the Phase 7 session report): mPDF
 * 8.3.1 silently inserts a phantom extra page when a page's markup nests
 * TWO percentage-sized `position:absolute` children inside ONE
 * `position:fixed` wrapper — reproduced and isolated directly (a plain
 * background-color div plus a second absolutely-positioned text overlay was
 * enough to trigger it; a single absolute child, or two children in ordinary
 * document flow, were not). Rather than depend on that undocumented
 * threshold, this function uses `position:absolute` NOWHERE: ONE
 * `position:fixed` wrapper for the whole bled page, and everything inside it
 * — the photo (or placeholder background) and the year/subtitle caption —
 * in ordinary block flow. The caption overlaps the bottom of the photo using
 * a NEGATIVE top margin instead of absolute positioning, which reads
 * identically on the page and doesn't touch the code path that misbehaves.
 * Do not reintroduce `position:absolute` here without re-running
 * tools/verify-export.php's page-count assertions against it.
 */
function pdf_render_cover_html(array $geo, array $project, ?array $coverPhoto): string
{
    $pageMm   = $geo['page_width_mm'];
    $year     = (string) $project['year'];
    $subtitle = $project['subtitle'] ?? null;

    $captionHtml = '<div style="margin-top:-30mm;width:100%;text-align:center;'
        . 'background:rgba(255,255,255,0.85);padding:6mm 0;font-family:sans-serif;">'
        . '<div style="font-size:30pt;font-weight:bold;">' . pdf_esc($year) . '</div>'
        . ($subtitle ? '<div style="font-size:14pt;margin-top:2mm;">' . pdf_esc((string) $subtitle) . '</div>' : '')
        . '</div>';

    $photoAbs = $coverPhoto !== null ? pdf_resolve_photo_file($coverPhoto) : null;

    if ($photoAbs !== null) {
        $art = '<img src="' . pdf_esc($photoAbs) . '" style="width:100%;height:100%;display:block;">';
        $inner = $art . $captionHtml;
        $wrapperBg = '';
    } else {
        // No cover photo chosen yet — fail soft (see this file's header): a
        // plain background carrying just the title text, not a crash and
        // not a skipped page. Text sits centered in the middle of the page
        // via padding, since there's no photo bottom edge to anchor against.
        $inner = '<div style="text-align:center;padding-top:42%;font-family:sans-serif;">'
            . '<div style="font-size:30pt;font-weight:bold;">' . pdf_esc($year) . '</div>'
            . ($subtitle ? '<div style="font-size:14pt;margin-top:2mm;">' . pdf_esc((string) $subtitle) . '</div>' : '')
            . '</div>';
        $wrapperBg = 'background:#eef1ec;';
    }

    return '<div style="position:fixed;left:0mm;top:0mm;width:' . $pageMm . 'mm;height:' . $pageMm . 'mm;' . $wrapperBg . '">'
        . $inner
        . '</div>';
}

/**
 * Interior title page — the traditional second front-matter page, distinct
 * from the cover (brief §4.6: "Title: defaults to the year ... An optional
 * subtitle field"). Plain, inside the normal safety-margin flow, no photo.
 */
function pdf_render_title_html(array $project): string
{
    $year     = (string) $project['year'];
    $subtitle = $project['subtitle'] ?? null;

    return '<div style="text-align:center;padding-top:45%;font-family:sans-serif;">'
        . '<div style="font-size:30pt;font-weight:bold;">' . pdf_esc($year) . '</div>'
        . ($subtitle ? '<div style="font-size:15pt;margin-top:4mm;color:#444;">' . pdf_esc((string) $subtitle) . '</div>' : '')
        . '</div>';
}

/**
 * A page_type='photos' page: 1-4 slots, each either a photo (its own typed
 * caption shown inline — the ONLY captioning mechanism a photo has, per
 * schema.sql) or, per brief §4.3, one text-card slot mixed in among them.
 *
 * ROUND 6 (PLAN.md): both renderers now draw ONE SOLVED LAYOUT.
 *
 * lib/compose.php sizes the page and hands back rectangles in percent of the
 * square trim. The browser positions them absolutely. mPDF cannot: it ignores
 * CSS `left` and `top` outright — a probe put three absolutely-positioned
 * boxes all at x=0, stacked — which is the same limitation the cover's
 * negative-margin hack works around further up this file.
 *
 * So this renderer takes the solve as a TREE (compose_solve_tree()) and
 * rebuilds it as nested <table>s with explicit millimetre sizes. Every size
 * comes from a rectangle the solver already computed; the gaps are read as the
 * distance BETWEEN sibling rectangles rather than being a constant of this
 * file's own. Nothing here decides how big anything is, which is the point —
 * the previous version resolved the same row/col weight maths independently,
 * and two implementations of one layout is what this file's history is a
 * record of going wrong.
 *
 * Photos are still pre-cropped (imageproc_crop_to_temp()) to the exact box they
 * land in rather than leaning on CSS object-fit, which mPDF's <img> support
 * does not reliably honour. Under the new rules most boxes are the photo's own
 * shape and that crop is a no-op; it does real work only where the solver
 * matched a photo to its neighbours' ratio.
 */
function pdf_render_photos_page_html(array $page, array $geo, ?array $choice, string $caption): string
{
    $slots = $page['slots'];

    if ($slots === array()) {
        // Phase 6's known gap (PLAN.md's Phase 6 note): moving the only
        // photo off a page can leave an empty book_pages row behind until a
        // reflow. Fail soft here too — a quiet blank page, not a crash.
        return '<div style="text-align:center;padding-top:45%;color:#999;font-family:sans-serif;">'
            . '(empty page)</div>';
    }

    if ($choice === null) {
        /* No template accepts these shapes. Same call as the preview: a page
         * that is visibly wrong beats one that looks plausible and isn't. */
        return '<div style="text-align:center;padding-top:45%;color:#999;font-family:sans-serif;">'
            . '(no template fits this page)</div>';
    }

    $tpl  = compose_templates()[$choice['name']];
    $occ  = compose_bind(compose_occupants($slots), $tpl, $choice['order']);
    $tree = compose_solve_tree($tpl, $occ);

    $pageMm = (float) $geo['content_width_mm'];
    $tallMm = (float) $geo['content_height_mm'];

    /* The solved block sits somewhere inside the trim, and mPDF cannot be told
     * to put it there. A spacer row above and a spacer cell to the left place
     * it — crude, but it is the one construct mPDF sizes reliably. */
    $blockX = $tree['x'] / 100.0 * $pageMm;
    $blockY = $tree['y'] / 100.0 * $tallMm;
    $blockW = $tree['w'] / 100.0 * $pageMm;
    $blockH = $tree['h'] / 100.0 * $tallMm;

    $inner = pdf_render_solved_node($tree, $slots, $choice['order'], $pageMm, $tallMm);

    $html = '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">'
        . '<tr><td style="height:' . round($blockY, 2) . 'mm;"></td></tr>'
        . '<tr><td style="height:' . round($blockH, 2) . 'mm;vertical-align:top;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
        . '<td style="width:' . round($blockX, 2) . 'mm;"></td>'
        . '<td style="width:' . round($blockW, 2) . 'mm;vertical-align:top;">' . $inner . '</td>'
        . '</tr></table></td></tr>';

    /* The foot caption, centred in the white space under the photos — the same
     * midpoint the preview uses, so the printed line sits where the screen said
     * it would. Given as a fixed-height row so a long caption cannot push the
     * page taller than the trim. */
    if ($caption !== '') {
        $footMm = max(0.0, $tallMm - ($blockY + $blockH));
        $html .= '<tr><td style="height:' . round($footMm, 2) . 'mm;vertical-align:middle;'
            . 'text-align:center;font-family:sans-serif;font-size:8.5pt;color:#444;'
            . 'line-height:1.3;overflow:hidden;">' . pdf_esc(pdf_clip_text($caption)) . '</td></tr>';
    }

    return $html . '</table>';
}

/**
 * One node of a solved composition tree, as nested <table>s.
 *
 * Sizes come from the rectangles, never from a weight recomputed here. Sibling
 * spacing is read as the distance between one child's far edge and the next
 * child's near edge, so the gap on paper is the gap the solver actually left
 * and there is no constant in this file that could drift from it.
 */
function pdf_render_solved_node(array $node, array $slots, array $order, float $pageMm, float $tallMm): string
{
    if ($node['t'] === 'leaf') {
        $slot = $slots[$order[$node['slot']]] ?? null;
        if ($slot === null) {
            return '';
        }
        $wMm = $node['w'] / 100.0 * $pageMm;
        $hMm = $node['h'] / 100.0 * $tallMm;

        return $slot['photo_id'] !== null
            ? pdf_render_photo_cell_sized($slot, $wMm, $hMm)
            : pdf_render_text_cell_sized($slot, $wMm, $hMm);
    }

    $kids  = $node['k'];
    $count = count($kids);

    if ($node['t'] === 'row') {
        $html = '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>';
        foreach ($kids as $i => $kid) {
            $wMm = $kid['w'] / 100.0 * $pageMm;
            $gap = $i < $count - 1
                ? max(0.0, ($kids[$i + 1]['x'] - ($kid['x'] + $kid['w'])) / 100.0 * $pageMm)
                : 0.0;
            $html .= '<td style="width:' . round($wMm, 2) . 'mm;padding:0 ' . round($gap, 2)
                . 'mm 0 0;vertical-align:top;">'
                . pdf_render_solved_node($kid, $slots, $order, $pageMm, $tallMm) . '</td>';
        }
        return $html . '</tr></table>';
    }

    $html = '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">';
    foreach ($kids as $i => $kid) {
        $hMm = $kid['h'] / 100.0 * $tallMm;
        $gap = $i < $count - 1
            ? max(0.0, ($kids[$i + 1]['y'] - ($kid['y'] + $kid['h'])) / 100.0 * $tallMm)
            : 0.0;
        $html .= '<tr><td style="height:' . round($hMm, 2) . 'mm;padding:0 0 ' . round($gap, 2)
            . 'mm 0;vertical-align:top;">'
            . pdf_render_solved_node($kid, $slots, $order, $pageMm, $tallMm) . '</td></tr>';
    }
    return $html . '</table>';
}

/**
 * Which crop rect to cut a photo slot's ORIGINAL to before embedding it:
 * Kathryn's manual override (book_page_photos.crop_x/y/w/h) if she's set one,
 * else a centred crop to the box the solver gave this photo.
 *
 * That second case does almost nothing now and that is deliberate. Under the
 * Round 6 rules a photo's box IS its own shape unless the solver matched it to
 * same-shape neighbours, so this resolves to the whole image for all but a
 * handful of photos — 4 of Kathryn's 123. It stays because the arithmetic is
 * the same either way, and because mPDF will not honour object-fit for the
 * cases where a crop really is needed.
 */
function pdf_resolve_crop_rect(array $slot, float $wMm, float $hMm): ?array
{
    if ($slot['crop_x'] !== null && $slot['crop_w'] !== null) {
        return array(
            'x' => (float) $slot['crop_x'], 'y' => (float) $slot['crop_y'],
            'w' => (float) $slot['crop_w'], 'h' => (float) $slot['crop_h'],
        );
    }

    /* NULL WHEN THERE IS NOTHING TO CUT, which since Round 6 is nearly always.
     *
     * The caller reads null as "embed the original untouched". That matters far
     * more than it sounds: a rect covering the whole frame still sent every
     * photo through a decode and re-encode of a 12-megapixel JPEG, which cost
     * about 0.4s each and re-compressed an image that did not need touching. On
     * a 123-photo book that is roughly 50 seconds of pure waste, which is
     * enough to trip the execution limit on shared hosting and fail the export
     * outright.
     *
     * Under the old engine the rect was always a genuine crop, so the work was
     * always real and this branch would have been dead code. Under the new one
     * a photo's box IS its own shape unless the composer matched it to
     * same-shape neighbours, so the crop is real for a handful of photos and a
     * no-op for the rest. */
    $w = (int) ($slot['width'] ?? 0);
    $h = (int) ($slot['height'] ?? 0);
    if ($w <= 0 || $h <= 0 || $hMm <= 0) {
        // Nothing to compute a crop from. Embed the original, same as before.
        return null;
    }

    $auto  = layout_auto_crop_rect($w, $h, $wMm / $hMm);
    $whole = $auto['x'] <= 1e-4 && $auto['y'] <= 1e-4
        && $auto['w'] >= 1.0 - 1e-4 && $auto['h'] >= 1.0 - 1e-4;

    return $whole ? null : $auto;
}

/** A short run of text, matching public/layout.php's page_snippet() — this
 *  file stays self-contained rather than requiring public/'s copy (see this
 *  file's header on pdf_esc() for the same reasoning). */
function pdf_snippet(string $text, int $len = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}

/**
 * One photo slot, sized to EXACTLY $wMm x $hMm — a caption (if any) takes a
 * small reserved band off the bottom rather than growing the cell, so a long
 * caption can't push the row below it out of place; the image is pre-cropped
 * to fill the remaining band exactly (pdf_resolve_crop_rect()), rather than
 * leaning on `object-fit` — see this file's header on why.
 */
function pdf_render_photo_cell_sized(array $slot, float $wMm, float $hMm): string
{
    /* NO PER-SLOT CAPTION BAND ANY MORE. A photo's caption prints as part of the
     * page's foot line (book_page_caption()), which is what the preview shows
     * and what Kathryn chose. Reserving a band here would have printed captions
     * twice AND — worse — shortened the box the composer sized, so the box
     * would no longer match the photo's shape and every captioned photo would
     * have been silently cropped to fit it. The photo fills its rectangle. */
    $imgHmm = $hMm;

    $srcAbs = pdf_resolve_photo_file($slot);
    if ($srcAbs === null) {
        $img = '<div style="width:100%;height:' . round($imgHmm, 2) . 'mm;border:0.5mm dashed #bbb;'
            . 'display:table-cell;text-align:center;vertical-align:middle;color:#888;'
            . 'font-family:sans-serif;font-size:9pt;">Photo not found on disk</div>';
    } else {
        $rect      = pdf_resolve_crop_rect($slot, $wMm, $imgHmm);
        $croppedAbs = $rect !== null ? imageproc_crop_to_temp($srcAbs, $rect) : null;
        $imgSrc     = $croppedAbs ?? $srcAbs;
        $img = '<img src="' . pdf_esc($imgSrc) . '" style="width:100%;height:' . round($imgHmm, 2) . 'mm;display:block;">';
    }

    return $img;
}

/** A text-card slot sized to at most $wMm x $hMm — text reflows to fit
 *  rather than needing an exact fill, so this just caps the box and lets
 *  pdf_render_text_card_html() render as it always has. */
function pdf_render_text_cell_sized(array $slot, float $wMm, float $hMm): string
{
    return '<div style="width:100%;max-height:' . round($hMm, 2) . 'mm;overflow:hidden;">'
        . pdf_render_text_card_html($slot) . '</div>';
}

/** A quote/anecdote text card — riding along on a photo page (brief §4.3:
 *  "occupy one of the page's slots") or standing alone on its own page. */
function pdf_render_text_card_html(array $slot, bool $standalone = false): string
{
    $isQuote = $slot['quote_id'] !== null;
    $text    = pdf_clip_text((string) ($isQuote ? $slot['quote_text'] : $slot['anecdote_text']));
    $date    = pdf_fmt_date((string) ($isQuote ? $slot['quote_date'] : $slot['anecdote_date']));
    $who     = $isQuote ? (string) $slot['who_said_it'] : null;

    $fontSize = $standalone ? '20pt' : '11pt';
    $pad      = $standalone ? 'padding:0 8mm;' : 'padding:3mm;border:0.3mm solid #ddd;';

    $meta = $who !== null ? pdf_esc($who) . ' · ' . pdf_esc($date) : pdf_esc($date);

    return '<div style="' . $pad . 'font-family:sans-serif;text-align:center;">'
        . '<div style="font-size:' . $fontSize . ';font-style:italic;line-height:1.4;">'
        . ($isQuote ? '&ldquo;' . nl2br(pdf_esc($text)) . '&rdquo;' : nl2br(pdf_esc($text)))
        . '</div>'
        . '<div style="font-size:9pt;color:#666;margin-top:3mm;">' . $meta . '</div>'
        . '</div>';
}

/** page_type='text': a quote/anecdote over the ~180-char threshold
 *  (config's layout.text_page_chars) got a full page instead of a shared
 *  slot (brief §4.3). Exactly one slot on this page, by schema.sql's CHECK. */
function pdf_render_text_page_html(array $page): string
{
    $slots = $page['slots'];
    if ($slots === array()) {
        return '<div style="text-align:center;padding-top:45%;color:#999;font-family:sans-serif;">(empty page)</div>';
    }
    return '<div style="padding-top:35%;">' . pdf_render_text_card_html($slots[0], true) . '</div>';
}

/**
 * page_type='snapshot': the brief §2.3 fixed templates, rendered from their
 * REAL fields (age/height for birthday; grade/school/teacher/
 * favorite_color/dream_job/favorite_class for school_year) — not just type
 * and date. Field list/labels mirror public/layout.php's
 * render_snapshot_page() exactly, so the printed page matches what Kathryn
 * already reviewed on screen.
 */
function pdf_render_snapshot_page_html(array $page): string
{
    $isBirthday = $page['snapshot_type'] === 'birthday';

    $facts = array();
    if ($isBirthday) {
        if ($page['snapshot_age'] !== null) {
            $facts[] = 'Age ' . $page['snapshot_age'];
        }
        if ($page['snapshot_height']) {
            $facts[] = (string) $page['snapshot_height'];
        }
    } else {
        foreach (array(
            'grade' => 'Grade', 'school' => 'School', 'teacher' => 'Teacher',
            'favorite_color' => 'Favorite color', 'dream_job' => 'Dream job',
            'favorite_class' => 'Favorite class',
        ) as $field => $label) {
            $value = $page['snapshot_' . $field];
            if ($value !== null && $value !== '') {
                $facts[] = $label . ': ' . $value;
            }
        }
    }

    $heroAbs = null;
    if ($page['snapshot_hero_photo_id'] !== null) {
        $heroAbs = pdf_resolve_photo_file(array('original_path' => $page['snapshot_hero_original']));
    }
    $heroCell = $heroAbs !== null
        ? '<img src="' . pdf_esc($heroAbs) . '" style="width:100%;">'
        : '<div style="width:100%;height:80mm;border:0.5mm dashed #bbb;display:flex;'
            . 'align-items:center;justify-content:center;color:#888;font-family:sans-serif;font-size:9pt;">'
            . 'No hero photo</div>';

    $factsHtml = '';
    if ($facts !== array()) {
        $factsHtml = '<ul style="font-size:12pt;line-height:1.8;padding-left:5mm;">';
        foreach ($facts as $fact) {
            $factsHtml .= '<li>' . pdf_esc($fact) . '</li>';
        }
        $factsHtml .= '</ul>';
    }

    $notesHtml = $page['snapshot_notes']
        ? '<p style="font-size:11pt;line-height:1.5;color:#333;">'
            . nl2br(pdf_esc(pdf_clip_text((string) $page['snapshot_notes']))) . '</p>'
        : '';

    return '<table style="width:100%;">'
        . '<tr>'
        . '<td style="width:45%;vertical-align:top;">' . $heroCell . '</td>'
        . '<td style="width:55%;vertical-align:top;padding-left:6mm;font-family:sans-serif;">'
        . '<span style="display:inline-block;background:#eef1ec;padding:1mm 4mm;font-size:10pt;">'
        . ($isBirthday ? 'Birthday' : 'School year') . '</span>'
        . '<div style="font-size:10pt;color:#666;margin-top:2mm;">' . pdf_fmt_date((string) $page['snapshot_date']) . '</div>'
        . $factsHtml . $notesHtml
        . '</td>'
        . '</tr></table>';
}

/** One book_pages row, dispatched by page_type. $geo only matters for
 *  page_type='photos' — see pdf_render_photos_page_html(). */
function pdf_render_page_html(array $page, array $geo, ?array $choice = null): string
{
    switch ($page['page_type']) {
        case 'snapshot':
            return pdf_render_snapshot_page_html($page);
        case 'text':
            return pdf_render_text_page_html($page);
        default:
            return pdf_render_photos_page_html(
                $page,
                $geo,
                $choice,
                book_page_caption($page, $page['slots'])
            );
    }
}

/* ------------------------------------------------------------- orchestration */

/**
 * Resolve which book_layouts.id export reads: the year's
 * active_book_layout_id, always (see this file's header). Throws
 * 'no_year_project' / 'no_active_layout' — the two "nothing coherent to
 * export" cases — rather than silently falling back to "newest version" or
 * recomputing anything.
 */
function pdf_export_resolve_layout(int $yearProjectId): array
{
    $project = year_project_get($yearProjectId);
    if ($project === null) {
        throw new RuntimeException('no_year_project');
    }
    $layoutId = $project['active_book_layout_id'] !== null ? (int) $project['active_book_layout_id'] : 0;
    if ($layoutId <= 0) {
        throw new RuntimeException('no_active_layout');
    }
    $layout = book_layout_get($layoutId);
    if ($layout === null) {
        // active_book_layout_id is deliberately not a foreign key
        // (schema.sql) — a dangling pointer is possible in principle, so
        // this is treated the same as "no active layout" rather than assumed
        // impossible.
        throw new RuntimeException('no_active_layout');
    }
    return array($project, $layout);
}

/**
 * Build the whole PDF and return it as raw bytes — never writes to disk
 * itself, so both public/api/export.php (streams it straight to the
 * browser) and tools/verify-export.php (writes it to a scratch file to
 * inspect) call the exact same code path.
 *
 * @return array{bytes:string, filename:string, page_count:int}
 */
function pdf_export_build(int $yearProjectId): array
{
    list($project, $layout) = pdf_export_resolve_layout($yearProjectId);

    $pages = book_layout_pages_with_content((int) $layout['id']);
    $geo   = pdf_export_geometry();

    $coverPhoto = $project['cover_photo_id'] !== null ? photo_get((int) $project['cover_photo_id']) : null;

    $mpdf = new \Mpdf\Mpdf(array(
        'format'         => array($geo['page_width_mm'], $geo['page_height_mm']),
        'margin_left'    => $geo['content_margin_mm'],
        'margin_right'   => $geo['content_margin_mm'],
        'margin_top'     => $geo['content_margin_mm'],
        'margin_bottom'  => $geo['content_margin_mm'],
        'margin_header'  => 0,
        'margin_footer'  => 0,
        'tempDir'        => sys_get_temp_dir() . '/keepsake-mpdf',
    ));
    $mpdf->SetTitle('Keepsake ' . $project['year'] . ($project['subtitle'] ? ' — ' . $project['subtitle'] : ''));

    // Cover, then title — both prepended, neither a book_pages row (see
    // schema.sql's own comment on book_pages for why). Every page but the
    // very last one carries page-break-after so mPDF starts a fresh
    // physical page for what follows.
    $mpdf->WriteHTML(pdf_wrap_page(pdf_render_cover_html($geo, $project, $coverPhoto), true));
    $mpdf->WriteHTML(pdf_wrap_page(pdf_render_title_html($project), $pages !== array()));

    /* Template choice for the WHOLE book at once, exactly as public/layout.php
     * does it — see compose_assign(). Doing it per page would pick the same
     * template every time, and doing it differently here from the preview would
     * mean the printed book quietly disagreed with the screen Kathryn approved
     * it on. Same function, same input, same answer. */
    $choices    = array();
    $photoPages = array();
    $occupants  = array();
    foreach ($pages as $page) {
        if ($page['page_type'] === 'photos' && $page['slots'] !== array()) {
            $photoPages[] = (int) $page['id'];
            $occupants[]  = compose_occupants($page['slots']);
        }
    }
    foreach (compose_assign($occupants) as $i => $choice) {
        $choices[$photoPages[$i]] = $choice;
    }

    $lastIndex = count($pages) - 1;
    foreach ($pages as $index => $page) {
        $html = pdf_render_page_html($page, $geo, $choices[(int) $page['id']] ?? null);
        $mpdf->WriteHTML(pdf_wrap_page($html, $index !== $lastIndex));
    }

    $bytes = $mpdf->Output('', 'S');

    // pdf_render_photo_cell_sized() -> imageproc_crop_to_temp() wrote one
    // scratch JPEG per photo slot into this directory; mPDF has already read
    // every one of them into $bytes by the time Output() returns, so nothing
    // downstream needs them to survive this request.
    pdf_cleanup_export_crops();

    $filename = 'Keepsake-' . $project['year'] . '.pdf';

    return array(
        'bytes'       => $bytes,
        'filename'    => $filename,
        // cover + title + every book_pages row.
        'page_count'  => 2 + count($pages),
    );
}

/** Sweep imageproc_crop_to_temp()'s scratch directory after an export. Best
 *  effort — a leftover file here is disk clutter, never a correctness
 *  problem (each is randomly named, so nothing collides with the next
 *  export), so a failed unlink is silently skipped rather than raised. */
function pdf_cleanup_export_crops(): void
{
    $dir = sys_get_temp_dir() . '/keepsake-export-crops';
    foreach (glob($dir . '/*.jpg') ?: array() as $file) {
        @unlink($file);
    }
}

/** page-break-after wrapper — $more is false only for the very last page in
 *  the document, so mPDF doesn't emit a trailing blank page. */
function pdf_wrap_page(string $innerHtml, bool $more): string
{
    $style = $more ? 'page-break-after: always;' : '';
    return '<div style="' . $style . '">' . $innerHtml . '</div>';
}
