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
 * GEOMETRY: every page in the exported PDF — the cover and every book_pages
 * row — is rendered at the SAME size: the configured trim size
 * plus bleed on all four edges (config.example.php's 'export' block). That
 * matches how Lulu and Mixam both actually want an interior file: not a
 * trim-sized page with separate PDF trim-box metadata, but one PDF page
 * per book page, physically sized at trim+bleed, content kept inside a
 * safety margin measured in from the TRIM edge. See pdf_export_geometry()
 * for the exact math and the citations for both printers' numbers.
 *
 * ONE DELIBERATE SIMPLIFICATION: only the COVER bleeds a photo to the true
 * page edge, which is what the bleed allowance exists for and what a book
 * cover conventionally does — see pdf_draw_cover_page(). Every interior page
 * keeps its content inside the safety margin. Interior photos in this build
 * are not edge-to-edge; nothing in brief 5.5 requires it, and it keeps the
 * page-count and dimension checks in tools/verify-export.php meaningful.
 * Worth reconsidering if Kathryn wants a magazine-style bleed on interior
 * spreads — flagged, not decided unilaterally here.
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
 *     cover page still renders — a plain background carrying just the title
 *     and subtitle, no photo — rather than being skipped or crashing the
 *     export. A book with no cover art chosen yet is still a valid, useful
 *     PDF to look at; disappearing the cover page entirely would silently
 *     shift every later page's number by one relative to what Kathryn saw
 *     on public/layout.php. Since Round 7 the cover is the ONLY front matter:
 *     the interior title page that used to follow it was removed at her
 *     request — "I actually don't want an internal title page, just a cover".
 *   - A photo row whose original_path/thumb_path no longer resolves to a
 *     real file on disk (moved, deleted out from under the database, a
 *     synthetic test row): that ONE slot renders as a labeled placeholder
 *     box instead of an <img>, and the caption (if any) still prints. This
 *     is deliberately visible rather than silently blank — a missing photo
 *     in a proof PDF is exactly the kind of thing Kathryn should notice
 *     before paying to print it, not something export should paper over.
 *   - A year whose active layout has zero book_pages (nothing eligible was
 *     ever generated): export still produces a valid, short PDF — the cover
 *     alone. This is what layout_generate() itself already does for an empty
 *     year (tools/verify-layout.php's $emptyRun case), so a downstream empty
 *     PDF is the honest continuation of that, not a new failure mode.
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

require_once __DIR__ . '/compose.php';

require_once __DIR__ . '/imageproc.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/layout_render.php';
/* Explicit since the cover started asking year_project_title() what the book is
 * called. This file has always leaned on repo.php — photo_get(), the layout
 * lookups — and got away with it because every entry point loaded repo.php
 * first. tools/verify-pdf-geometry.php does not, which is exactly the sort of
 * caller an unstated dependency catches out. */
require_once __DIR__ . '/repo.php';

/**
 * THE COMPOSER AUTOLOADER, loaded here and nowhere else.
 *
 * This is the app's only Composer dependency and this is its only consumer, so
 * the autoloader belongs with it rather than in bootstrap.php, where every page
 * that never touches mPDF would pay to load it.
 *
 * It was missing entirely until Round 7, and the consequence was that PDF export
 * could never have worked on the server: `new \Mpdf\Mpdf` threw "Class not
 * found" every time, and api/export.php turned that into its generic "Could not
 * build the PDF". The reason no test caught it is worth remembering —
 * tools/verify-export.php required the autoloader ITSELF before calling in, so
 * it was testing a world production never had. A test that arranges a
 * precondition the real caller does not is not testing the real caller.
 *
 * A FUNCTION rather than a require at the top of this file, because requiring
 * the file no longer means intending to build a PDF: api/year-projects-update.php
 * pulls it in for pdf_export_geometry() alone, so it can hand the cover preview
 * the band's measurements, and making a subtitle edit load all of mPDF would
 * undo the point of keeping it out of bootstrap.php in the first place.
 *
 * Guarded by class_exists so a caller that has already autoloaded (the test
 * harness, or a future front controller) is not made to load it twice.
 */
function pdf_require_library(): void
{
    if (class_exists(\Mpdf\Mpdf::class, false)) {
        return;
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

/** See this file's header. Generous on purpose — never hit by real content. */
const PDF_EXPORT_MAX_TEXT_CHARS = 4000;

/**
 * The tint behind a quote or anecdote sharing a page with photographs.
 *
 * This is `--teal-tint` from public/assets/styles.css, written out as a
 * literal because mPDF cannot read a CSS custom property. That makes it the
 * one colour in the app stored in two places, so tools/verify-pdf-geometry.php
 * reads the stylesheet and fails if the two drift — the failure mode otherwise
 * is a printed book that is subtly a different colour from the preview it was
 * approved in, which nobody would catch until it arrived.
 */
const PDF_TEXT_CARD_TINT = '#e8f6f4';

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

/**
 * Millimetres to points, for CSS that mPDF will parse.
 *
 * The cover's type sizes are fractions of the page (lib/layout_render.php), so
 * they arrive here in millimetres. They are handed to mPDF in POINTS rather
 * than in mm because a font-size is the one place where the unit's support is
 * worth not assuming — pt is the unit a typesetter reads by definition, and
 * every CSS parser agrees about it.
 */
function pdf_mm_to_pt(float $mm): float
{
    return $mm * 72.0 / 25.4;
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
 * How the cover photo is framed: Kathryn's crop if she has set one, otherwise
 * centred on the page's shape.
 *
 * The cover always fills its frame, so a photo that is not the page's shape
 * always loses something. NULL columns mean "you choose"; once she has framed
 * it by hand on the layout screen, that is the frame, and it is not recomputed
 * behind her.
 */
function pdf_cover_crop_rect(array $project, array $coverPhoto, float $pageAspect): array
{
    if (($project['cover_crop_x'] ?? null) !== null && ($project['cover_crop_w'] ?? null) !== null) {
        return array(
            'x' => (float) $project['cover_crop_x'], 'y' => (float) $project['cover_crop_y'],
            'w' => (float) $project['cover_crop_w'], 'h' => (float) $project['cover_crop_h'],
        );
    }

    $w = (int) ($coverPhoto['width'] ?? 0);
    $h = (int) ($coverPhoto['height'] ?? 0);

    return ($w > 0 && $h > 0)
        ? layout_auto_crop_rect($w, $h, $pageAspect)
        : array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0);
}

/**
 * The cover: one photo bleeding to the true page edge, with the year and
 * subtitle in a band across the foot.
 *
 * DRAWN, NOT WRITTEN AS HTML, for the same reason the photo pages are. The
 * previous version handed mPDF an <img> and a block with a -30mm top margin to
 * pull the title back over the photo, and Kathryn's first real cover shows what
 * that produced: the photo on its side (the raw <img> never went through
 * imageproc, so the EXIF rotation was never baked in), the colours unconverted,
 * the photo stopping short of the edge at 190x143mm inside the margins, and the
 * title landing somewhere on top of the picture where it could not be read.
 *
 * A cover conventionally bleeds — that is what the bleed allowance is for — so
 * the photo covers the whole physical page, trim plus bleed on all four edges,
 * and is centre-cropped to the page's shape. Whatever the printer trims is
 * photo, which is the point.
 *
 * The title band is opaque rather than translucent. mPDF's support for rgba
 * backgrounds is patchy, and a cover title that is sometimes readable is worse
 * than one that is plainly a band; it also survives being printed on paper that
 * absorbs ink, which a wash over a dark photo does not.
 */
function pdf_draw_cover_page(\Mpdf\Mpdf $mpdf, array $geo, array $project, ?array $coverPhoto): void
{
    $pageMm   = (float) $geo['page_width_mm'];
    $tallMm   = (float) $geo['page_height_mm'];
    $safeMm   = (float) $geo['content_margin_mm'];
    $title    = year_project_title($project);
    $subtitle = trim((string) ($project['subtitle'] ?? ''));

    $photoAbs = $coverPhoto !== null ? pdf_resolve_photo_file($coverPhoto) : null;

    if ($photoAbs !== null) {
        /* Cropped to the PAGE's shape, not the photo's — this is the one place
         * in the book where filling the frame outranks showing the whole
         * picture, because a cover with white edges is not a cover. */
        $rect = pdf_cover_crop_rect($project, $coverPhoto, $pageMm / $tallMm);

        list($maxW, $maxH) = pdf_print_pixel_budget($pageMm, $tallMm);
        $prepared = imageproc_prepare_cached($photoAbs, $rect, $maxW, $maxH);

        $mpdf->Image($prepared ?? $photoAbs, 0, 0, $pageMm, $tallMm, '', '', true, false);
    } else {
        /* No cover photo chosen yet — fail soft, as this file's header
         * promises: a plain ground carrying the title, never a skipped page. */
        $mpdf->WriteFixedPosHTML(
            '<div style="background:#eef1ec;width:100%;height:100%;"></div>',
            0, 0, $pageMm, $tallMm
        );
    }

    /* The band sits inside the safety margin, so nothing a printer trims can
     * take a letter off. Its height is set here rather than left to the text,
     * because a fixed-position box in mPDF does not grow and silently clipping
     * a subtitle would be worse than a band with room to spare. */
    $band    = cover_band_metrics($subtitle !== '', $safeMm / $tallMm);
    $bandHMm = $band['height'] * $tallMm;
    $bandYMm = $band['top'] * $tallMm;

    /* mPDF cannot centre a block vertically inside a fixed-position box, so the
     * band's own padding is spent as a top margin instead. That is the same
     * number the preview centres with — see cover_band_metrics() — so the title
     * lands in the same place on paper as it does on screen, and on a cover with
     * no subtitle that place is the middle of the white box. */
    $html = '<div style="background:#ffffff;width:100%;height:100%;text-align:center;'
        . 'font-family:sans-serif;">'
        . sprintf(
            '<div style="font-size:%.2fpt;line-height:%.2f;font-weight:bold;margin-top:%.2fmm;">%s</div>',
            pdf_mm_to_pt($band['title_size'] * $tallMm),
            COVER_TITLE_LEAD,
            $band['title_top'] * $tallMm,
            pdf_esc(pdf_clip_text($title))
        )
        . ($subtitle !== ''
            ? sprintf(
                '<div style="font-size:%.2fpt;line-height:%.2f;margin-top:%.2fmm;">%s</div>',
                pdf_mm_to_pt($band['sub_size'] * $tallMm),
                COVER_SUB_LEAD,
                $band['gap'] * $tallMm,
                pdf_esc(pdf_clip_text($subtitle))
            )
            : '')
        . '</div>';

    $mpdf->WriteFixedPosHTML($html, $safeMm, $bandYMm, $pageMm - (2 * $safeMm), $bandHMm);
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
function pdf_draw_photos_page(\Mpdf\Mpdf $mpdf, array $page, array $geo, ?array $choice, string $caption): void
{
    $slots = $page['slots'];

    if ($slots === array() || $choice === null) {
        /* Phase 6's known gap (a page emptied by a drag), or shapes no template
         * accepts. Both fail soft and visibly rather than silently. */
        $mpdf->WriteHTML('<div style="text-align:center;padding-top:45%;color:#999;'
            . 'font-family:sans-serif;">'
            . ($slots === array() ? '(empty page)' : '(no template fits this page)')
            . '</div>');
        return;
    }

    $tpl   = compose_templates()[$choice['name']];
    $occ   = compose_bind(compose_occupants($slots), $tpl, $choice['order']);
    $rects = compose_solve($tpl, $occ);

    /* THE SOLVER'S PERCENTAGES ARE OF THE TRIM, not of the safety box.
     *
     * This is what made the printed pages emptier than the preview. The margin
     * around a page is already Kathryn's — she picked it in the layout lab by
     * comparing three widths, and it is baked into the solve as COMPOSE_FILL.
     * Mapping those percentages onto the safety box then charged the safety
     * margin a second time, and 85% of the page became 73% of it on paper while
     * the screen still showed 85%.
     *
     * Measuring from the trim edge instead makes the two agree, and costs
     * nothing in safety: the solver never places anything outside its own
     * margin, so the tightest possible page still leaves 7.5% of 8.5in — about
     * 0.64in — between the photos and the trim, comfortably outside the 0.5in
     * the printers ask for. */
    $trimMm   = (float) $geo['trim_width_in'] * 25.4;
    $trimHMm  = (float) $geo['trim_height_in'] * 25.4;
    $originMm = (float) $geo['bleed_in'] * 25.4;

    $boxMm  = $trimMm;
    $tallMm = $trimHMm;

    $bottomPct = 0.0;

    foreach ($rects as $rect) {
        $slot = $slots[$choice['order'][$rect['slot']]] ?? null;
        if ($slot === null) { continue; }

        $xMm = $originMm + $rect['x'] / 100.0 * $boxMm;
        $yMm = $originMm + $rect['y'] / 100.0 * $tallMm;
        $wMm = $rect['w'] / 100.0 * $boxMm;
        $hMm = $rect['h'] / 100.0 * $tallMm;

        $bottomPct = max($bottomPct, $rect['y'] + $rect['h']);

        if ($slot['photo_id'] === null) {
            /* The slot's own height, so the card centres in it — see
               pdf_render_text_card_html(). */
            $mpdf->WriteFixedPosHTML(pdf_render_text_card_html($slot, false, $hMm), $xMm, $yMm, $wMm, $hMm);
            continue;
        }

        $srcAbs = pdf_resolve_photo_file($slot);
        if ($srcAbs === null) {
            /* Deliberately visible: a photo missing from disk should be noticed
             * in a proof, not papered over. */
            $mpdf->WriteFixedPosHTML(
                '<div style="border:0.5mm dashed #bbb;text-align:center;font-family:sans-serif;'
                . 'font-size:9pt;color:#888;">Photo not found</div>',
                $xMm, $yMm, $wMm, $hMm
            );
            continue;
        }

        $cropRect = pdf_resolve_crop_rect($slot, $wMm, $hMm)
            ?? array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0);
        list($maxW, $maxH) = pdf_print_pixel_budget($wMm, $hMm);

        /* Cached, so a run that nginx cuts off is not wasted work — see
         * imageproc_prepare_cached(). */
        $prepared = imageproc_prepare_cached($srcAbs, $cropRect, $maxW, $maxH);

        /* $paint = true, $constrain = FALSE. Constrain is what makes mPDF keep
         * the image's own aspect and resize the box to suit; the box is already
         * the photo's shape (or the shape it was matched to), so the rectangle
         * the composer solved is the one that gets drawn. */
        $mpdf->Image($prepared ?? $srcAbs, $xMm, $yMm, $wMm, $hMm, '', '', true, false);
    }

    if ($caption !== '') {
        /* Centred in the white space under the photos, on the same midpoint the
         * preview uses. */
        $footCentreMm = $originMm + ($bottomPct + 100.0) / 200.0 * $tallMm;
        $footHMm      = max(6.0, ($originMm + $tallMm) - $footCentreMm);

        $mpdf->WriteFixedPosHTML(
            '<div style="font-family:sans-serif;font-size:8.5pt;line-height:1.3;color:#444;'
            . 'text-align:center;">' . pdf_esc(pdf_clip_text($caption)) . '</div>',
            $originMm,
            max($originMm, $footCentreMm - $footHMm / 2),
            $boxMm,
            $footHMm
        );
    }
}

/**
 * The most pixels worth embedding for a box of $wMm x $hMm, at print
 * resolution.
 *
 * Print wants 300dpi at final size and gains nothing above it — the press
 * cannot resolve more, and the surplus is pure file size. A phone photo is
 * routinely three or four times that for a quarter-page slot, which is how
 * Kathryn's first successful export came out over 400 MB and took an age to
 * download.
 *
 * Rounded up a little (the +2) so a photo that lands almost exactly on the
 * budget is not scaled by a hair for nothing.
 */
function pdf_print_pixel_budget(float $wMm, float $hMm): array
{
    $dpi      = max(72.0, (float) cfg('export.print_dpi', 300));
    $perMm    = $dpi / 25.4;

    return array(
        max(1, (int) ceil($wMm * $perMm) + 2),
        max(1, (int) ceil($hMm * $perMm) + 2),
    );
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



/** A quote/anecdote text card — riding along on a photo page (brief §4.3:
 *  "occupy one of the page's slots") or standing alone on its own page. */
/**
 * The words of a quote or an anecdote, laid out.
 *
 * HANGING QUOTATION MARKS, DONE WITH A TWO-CELL TABLE rather than a negative
 * text-indent. The indent version was the first attempt and Kathryn caught what
 * was wrong with it: an indent is a guess at how wide the quote mark is, so the
 * first word sat somewhere other than where the second line started. A table
 * cell IS the mark's width, whatever the font decides that is, so the text
 * column lines up with itself exactly — verified out of a real PDF's text
 * positioning operators, not by eye.
 *
 * THE ATTRIBUTION SITS IN THE TEXT CELL, not under the whole table: "the person
 * and date should be left aligned with the words, not the quotation mark".
 *
 * An anecdote gets no marks and therefore no mark cell — just the text, which
 * is what "keep it how it was" meant.
 */
function pdf_render_text_block_html(array $slot, float $textPt, float $metaPt): string
{
    $isQuote = $slot['quote_id'] !== null;
    $text    = pdf_clip_text((string) ($isQuote ? $slot['quote_text'] : $slot['anecdote_text']));
    $date    = pdf_fmt_date((string) ($isQuote ? $slot['quote_date'] : $slot['anecdote_date']));
    $who     = $isQuote ? trim((string) $slot['who_said_it']) : '';

    $meta = $who !== '' ? pdf_esc($who) . ' &middot; ' . pdf_esc($date) : pdf_esc($date);

    /* CENTRED, EVERY LINE — "I'd like the anecdote and quote centered in their
     * boxes, vertically and horizontally."
     *
     * THIS REPLACES THE HANGING QUOTATION MARK, and that was not a decision
     * taken lightly. The mark used to sit in its own table cell, outside the
     * words, so that every line and the attribution shared one straight left
     * edge — which is the whole point of hanging it. Centred lines have no
     * straight left edge to hang off: whatever the mark did there would be
     * measured against a different starting point on every line. So the mark
     * goes back inline, where it belongs in a centred setting, and the
     * construction that held it out is gone rather than left doing something
     * arbitrary.
     *
     * The two spacing rules that made the old version work are kept for the
     * same reason they existed: mPDF drops margins inside a fixed-position
     * block, so the gap above the attribution is cell padding, not a margin. */
    $body = '<table style="width:100%;border-spacing:0;border-collapse:collapse;">'
        . '<tr><td style="padding:0 0 3mm 0;text-align:center;'
        . 'font-size:' . $textPt . 'pt;font-style:italic;line-height:1.4;">'
        . ($isQuote ? '&ldquo;' : '') . nl2br(pdf_esc($text)) . ($isQuote ? '&rdquo;' : '')
        . '</td></tr>'
        . '<tr><td style="padding:0;text-align:center;'
        . 'font-size:' . $metaPt . 'pt;color:#666;">' . $meta . '</td></tr>'
        . '</table>';

    return $body;
}

/**
 * A quote or anecdote, CENTRED IN WHATEVER IT SITS IN — a slot on a photo page
 * or a page of its own, both ways. "I'd like the anecdote and quote centered in
 * their boxes, vertically and horizontally."
 *
 * Horizontally is pdf_render_text_block_html()'s text-align above. Vertically
 * is the full-height table with a vertical-align:middle cell, which is how it
 * is done in mPDF — it has no flexbox and honours vertical-align only inside a
 * table cell whose height is stated. Checked against a real PDF's positioning
 * operators rather than assumed.
 */
function pdf_render_text_card_html(array $slot, bool $standalone = false, ?float $heightMm = null): string
{
    $textPt = $standalone ? 22.0 : 11.0;
    $metaPt = $standalone ? 10.0 : 9.0;

    $block = pdf_render_text_block_html($slot, $textPt, $metaPt);

    /* A standalone page centres a column narrower than the page; a slot card
       uses the whole slot it was given. */
    $inner = $standalone
        ? '<table style="width:78%;margin:0 auto;"><tr><td style="padding:0;">' . $block . '</td></tr></table>'
        : $block;

    /* THE TINT PRINTS. "I like the light turquoise box. Can we add that as
     * actual styling behind the quote?"
     *
     * #e8f6f4 is --teal-tint from public/assets/styles.css, restated here as a
     * literal because mPDF cannot read a CSS custom property — the two are
     * therefore kept in step by hand, and verify-pdf-geometry.php checks they
     * still match rather than trusting anyone to remember.
     *
     * A SLOT CARD ONLY, not a page of its own. On screen the tint is what marks
     * a quote out from the photographs beside it; a whole page of it is a field
     * of colour nobody asked for, and a standalone quote has nothing to be
     * distinguished from. The grey hairline goes — a border and a fill both
     * saying "this is a box" is one too many. */
    $pad = $standalone
        ? ''
        : 'padding:4mm;background-color:' . PDF_TEXT_CARD_TINT . ';border-radius:2mm;';

    if ($heightMm === null) {
        /* No height to centre within — flow from the top, which is what a
           caller that did not say how tall its box is has asked for. */
        return '<div style="' . $pad . 'font-family:sans-serif;">' . $inner . '</div>';
    }

    return '<table style="width:100%;height:' . round($heightMm, 2) . 'mm;font-family:sans-serif;">'
        . '<tr><td style="vertical-align:middle;height:' . round($heightMm, 2) . 'mm;' . $pad . '">'
        . $inner
        . '</td></tr></table>';
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
    /* Centred in the content box rather than pushed down it by a percentage
     * padding, which is what this did and which put the words at a different
     * height on every page depending on how long they were. */
    $geo      = pdf_export_geometry();
    $heightMm = $geo['page_height_mm'] - (2 * $geo['content_margin_mm']);

    return pdf_render_text_card_html($slots[0], true, $heightMm);
}

/**
 * page_type='snapshot': the hero photo down one side, the title and sections
 * down the other. Two-up portrait, as Kathryn asked for.
 *
 * DRAWN BY COORDINATE, like a photos page, and NOT as a table in document
 * flow — which is what it was, and what the page lab caught. mPDF's table
 * engine will not hold a height, so the two columns were as tall as their
 * content and no taller: on an 8.75in square page a birthday with three
 * sections occupied the top 40% and the rest was blank. It read as a page that
 * had failed to finish rather than a page with room on it.
 *
 * By coordinate, the hero fills the full height of its column and the text
 * sits beside it, so the page is a spread of two panels rather than a
 * paragraph floating at the top. pdf_draw_photos_page() above hit exactly this
 * wall for exactly this reason; this is the same answer.
 *
 * THE HERO IS CROPPED TO ITS COLUMN, using the same imageproc_prepare_cached()
 * path a photo slot uses — the column is a fixed portrait rectangle, so a
 * landscape hero has to lose its sides to fill it. That is the one place this
 * app crops a photo to fit a shape rather than shaping the box to the photo,
 * and it is deliberate: "one side will be the hero image", a side being a
 * fixed thing.
 *
 * NO TYPE LABEL — "I don't want the type of content shown on the page". The
 * pill in the preview's page toolbar is screen chrome and stays.
 */
/**
 * Every rectangle on a snapshot page, given how tall the text turned out.
 *
 * PURE, and separate from the drawing, because three things have to agree
 * about where these panels sit: the exporter below, the on-screen preview in
 * lib/views/book.php, and tools/page-lab.php. Two of them can now ask rather
 * than re-derive, and the arithmetic is testable without an mPDF.
 *
 * $textHmm is the measured height of the text column — see
 * pdf_measure_html_height(). Pass 0.0 and you get the hero-centred page, which
 * is the right answer whenever the text is shorter than the hero anyway.
 */
function pdf_snapshot_layout(array $geo, float $textHmm = 0.0): array
{
    /* Measured from the TRIM edge, like the composition solver — see
     * pdf_draw_photos_page()'s note on why the safety margin must not be
     * charged twice. */
    $originMm = (float) $geo['bleed_in'] * 25.4;
    $trimWMm  = (float) $geo['trim_width_in'] * 25.4;
    $trimHMm  = (float) $geo['trim_height_in'] * 25.4;

    /* The same 7.5% margin the photo solver leaves (COMPOSE_FILL), so a
     * snapshot page sits on the same visual margin as the page before it. */
    $insetMm = $trimWMm * 0.075;

    $boxX = $originMm + $insetMm;
    $boxY = $originMm + $insetMm;
    $boxW = $trimWMm - (2 * $insetMm);
    $boxH = $trimHMm - (2 * $insetMm);

    /* 45/55, the split this page has always had, with a gutter taken out of
     * the middle rather than off either panel. */
    $gutterMm = 8.0;
    $heroW    = ($boxW - $gutterMm) * 0.45;
    $textW    = ($boxW - $gutterMm) * 0.55;
    $textX    = $boxX + $heroW + $gutterMm;

    /* THE HERO IS THE BOOK'S PORTRAIT SHAPE, not the full height of its column.
     *
     * Filling the column was the first version and it made the hero a 1:2.3
     * slab — taller and narrower than any photograph anywhere else in the book,
     * so a birthday page did not look like it belonged to the same book as the
     * page before it. "The image should be the same ratio as the other portrait
     * images in the book."
     *
     * COMPOSE_CANON['P'] is that ratio, and it is the number lib/compose.php
     * already uses for every portrait slot the layout engine solves — imported
     * rather than restated, so there is one portrait in this app. */
    $heroH = min($boxH, $heroW / COMPOSE_CANON['P']);

    /* THE TWO PANELS ARE ONE BLOCK, AND THE BLOCK IS WHAT GETS CENTRED.
     *
     * The block is as tall as whichever panel is taller, and both panels start
     * at its top. That single rule covers both of the things asked for, and
     * the two cases meet without a seam:
     *
     *   text shorter than the hero — the block IS the hero, so the hero is
     *     centred on the page and the text centres against it (the cell below
     *     does that half). This is the page as it was signed off.
     *
     *   text longer than the hero — the block is the text, so "the text is top
     *     aligned with the image", and the pair is still centred on the page.
     *
     * The alternative was to leave the hero centred and hang the text off its
     * top edge, which is simpler and wrong: it throws away the top third of
     * the page and a nine-section snapshot then runs off the bottom. Measured
     * in the page lab before this was written — it overflowed the trim by
     * 19mm. */
    $blockH = max($heroH, min($textHmm, $boxH));
    $blockY = $boxY + max(0.0, ($boxH - $blockH) / 2.0);

    return array(
        'box_x'  => $boxX,  'box_y'  => $boxY,  'box_w' => $boxW, 'box_h' => $boxH,
        'gutter' => $gutterMm,
        'hero_x' => $boxX,  'hero_y' => $blockY, 'hero_w' => $heroW, 'hero_h' => $heroH,
        'text_x' => $textX, 'text_y' => $blockY, 'text_w' => $textW,
        /* What is left between the block's top and the foot of the content
           box. The cell inside is the HERO's height, so a short text centres
           against the photo; a long one grows the table down into this. */
        'text_h'      => $boxH - ($blockY - $boxY),
        'text_cell_h' => $heroH,
    );
}

/**
 * How tall a block of the exporter's own HTML comes out at a given width, in
 * millimetres.
 *
 * WHY THIS EXISTS. pdf_snapshot_layout() needs to know whether the text is
 * taller than the hero before it can place either of them, and mPDF will not
 * tell you how big something is until it has drawn it. So this draws it — on a
 * throwaway page 3 metres long, where nothing can paginate — and reads the
 * flow position off the end. Same engine, same fonts, same width as the real
 * panel, so the answer is the real answer rather than a character-count
 * estimate that drifts the first time a heading wraps.
 *
 * Cached on the html and the width: a book has a handful of snapshot pages,
 * and the lab draws each of its cases twice.
 *
 * Returns 0.0 when mPDF is not installed, which the caller reads as "assume it
 * fits" — the pre-measurement page, and a sane thing for a checkout with no
 * vendor/ to fall back to.
 */
function pdf_measure_html_height(string $html, float $widthMm): float
{
    static $cache = array();

    $key = md5($html) . '|' . round($widthMm, 2);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    pdf_require_library();
    if (!class_exists(\Mpdf\Mpdf::class)) {
        return $cache[$key] = 0.0;
    }

    try {
        $probe = new \Mpdf\Mpdf(array(
            'format'        => array($widthMm, 3000.0),
            'margin_left'   => 0, 'margin_right'  => 0,
            'margin_top'    => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'tempDir'       => sys_get_temp_dir() . '/keepsake-mpdf',
        ));
        $probe->AddPage();
        $probe->WriteHTML($html);
        $height = (float) $probe->y;
    } catch (\Throwable $e) {
        /* Fail soft, like everything else here: a page that cannot be measured
           is drawn the old way rather than not drawn. */
        return $cache[$key] = 0.0;
    }

    return $cache[$key] = max(0.0, $height);
}

function pdf_draw_snapshot_page(\Mpdf\Mpdf $mpdf, array $page, array $geo): void
{
    /* Measured first, placed second — the block cannot be positioned until it
       is known which panel is the tall one. What gets measured is the same
       wrapper that gets drawn, with the cell height set to nothing so the
       table collapses to its content: measuring a different construction from
       the one you draw is how you end up centring against a number that was
       never true. */
    $probe    = pdf_snapshot_layout($geo, 0.0);
    $textHtml = pdf_render_snapshot_text_html($page, 0.0);
    $box      = pdf_snapshot_layout($geo, pdf_measure_html_height($textHtml, $probe['text_w']));

    $heroW = $box['hero_w'];
    $heroH = $box['hero_h'];
    $heroY = $box['hero_y'];
    $boxX  = $box['hero_x'];

    /* ---- the hero column ---- */

    $heroAbs = null;
    if ($page['snapshot_hero_photo_id'] !== null) {
        $heroAbs = pdf_resolve_photo_file(array('original_path' => $page['snapshot_hero_original']));
    }

    if ($heroAbs !== null) {
        list($maxW, $maxH) = pdf_print_pixel_budget($heroW, $heroH);
        $prepared = imageproc_prepare_cached(
            $heroAbs,
            /* Centre-crop to the column. A hand crop for the hero would be a
               new stored rectangle and a new control to set it; the cover
               already has one and nothing has asked for a second. */
            array('x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0),
            $maxW,
            $maxH
        );
        $mpdf->Image($prepared ?? $heroAbs, $boxX, $heroY, $heroW, $heroH, '', '', true, false);
    } else {
        /* Visible rather than blank, the same call pdf_draw_photos_page()
           makes for a photo missing from disk: a proof should show you that
           there is no hero, not quietly print a narrower page. */
        $mpdf->WriteFixedPosHTML(
            '<div style="border:0.5mm dashed #bbb;height:100%;"></div>',
            $boxX, $heroY, $heroW, $heroH
        );
    }

    /* ---- the text column ----
     *
     * Starts on the block's top edge, which is the hero's top edge, and is
     * given a CELL the hero's height: shorter content centres in it, longer
     * content grows the table downward past the foot of the photo. Between
     * that and pdf_snapshot_layout()'s block, "centred when it fits,
     * top-aligned with the image when it doesn't" needs no branch anywhere. */
    $mpdf->WriteFixedPosHTML(
        pdf_render_snapshot_text_html($page, $box['text_cell_h']),
        $box['text_x'], $box['text_y'], $box['text_w'], $box['text_h']
    );
}

/**
 * The text half of a snapshot page: title, date, then the sections.
 *
 * Split out from the drawing above so the page lab can render it, and so the
 * on-screen preview in lib/views/book.php has one thing to mirror rather than
 * a sequence of mPDF calls.
 *
 * THE STACK IS A TABLE AND THE GAPS ARE CELL PADDING, which looks like a 1998
 * web page and is not a stylistic choice. mPDF's WriteFixedPosHTML — how every
 * panel on a coordinate-drawn page gets there — SILENTLY DROPS margin and
 * padding on block elements. The gaps this page is supposed to have (2mm under
 * the title, 6mm under the date, 4.5mm between sections) were all being thrown
 * away in print, so every line sat on the same 4.7mm rhythm and the date ran
 * into the first heading. It looked right in the page lab because a browser
 * honours the margins mPDF was discarding.
 *
 * Cell padding survives, measured out of a real PDF. So the gaps are cell
 * padding, and the last row has none — a trailing gap would offset the
 * centring by half of itself.
 */
function pdf_render_snapshot_text_html(array $page, ?float $heightMm = null): string
{
    $title    = trim((string) ($page['snapshot_title'] ?? ''));
    $sections = $page['snapshot_sections'] ?? array();

    /* Each entry is array(html, gap-under-it-in-mm). Collected first so the
       last one can have its gap taken off. */
    $rows = array();

    if ($title !== '') {
        $rows[] = array(
            '<div style="font-size:19pt;font-weight:bold;line-height:1.2;">' . pdf_esc($title) . '</div>',
            2.0,
        );
    }

    $rows[] = array(
        '<div style="font-size:10pt;color:#666;">' . pdf_fmt_date((string) $page['snapshot_date']) . '</div>',
        6.0,
    );

    foreach ($sections as $section) {
        $heading = trim((string) ($section['heading'] ?? ''));
        $text    = trim((string) ($section['body'] ?? ''));
        if ($heading === '' && $text === '') {
            continue;
        }

        $cell = '';
        if ($heading !== '') {
            /* Bold, as asked. The body copy underneath keeps the weight and
               size it always had — "the titles should be bold and the body
               copy is good as-is". */
            $cell .= '<div style="font-size:12pt;font-weight:bold;line-height:1.35;">'
                . pdf_esc($heading) . '</div>';
        }
        if ($text !== '') {
            $cell .= '<div style="font-size:11pt;line-height:1.5;color:#333;">'
                . nl2br(pdf_esc(pdf_clip_text($text))) . '</div>';
        }

        $rows[] = array($cell, 4.5);
    }

    $rows[count($rows) - 1][1] = 0.0;

    $html = '<table style="width:100%;border-spacing:0;border-collapse:collapse;font-family:sans-serif;">';
    foreach ($rows as $row) {
        $html .= '<tr><td style="padding:0 0 ' . round($row[1], 2) . 'mm 0;">' . $row[0] . '</td></tr>';
    }
    $html .= '</table>';

    if ($heightMm === null) {
        return $html;
    }

    /* $heightMm is the HERO's height, and the cell is a minimum rather than a
     * cap: content shorter than it centres against the hero, content taller
     * than it grows the table downward and starts on the hero's top edge. That
     * is the second half of "centred when it fits, top-aligned with the image
     * when it doesn't" — pdf_snapshot_layout() is the first. Same construction
     * pdf_render_text_card_html() uses, verified the same way, out of a real
     * PDF's text positions.
     *
     * border-spacing and border-collapse are here because mPDF's default table
     * spacing put the first line 0.5mm below the hero's top edge — invisible on
     * its own, and exactly the kind of not-quite-aligned that the eye reads as
     * sloppy when there is a photograph beside it to compare against. */
    return '<table style="width:100%;height:' . round($heightMm, 2) . 'mm;'
        . 'border-spacing:0;border-collapse:collapse;margin:0;">'
        . '<tr><td style="vertical-align:middle;height:' . round($heightMm, 2) . 'mm;padding:0;">'
        . $html
        . '</td></tr></table>';
}

/**
 * HTML for the page types that are ordinary document flow. A photos page is
 * NOT one of them any more — see pdf_draw_photos_page(), which places it at
 * absolute coordinates because mPDF's table engine will not hold a given size.
 */
function pdf_render_page_html(array $page): string
{
    /* Snapshots are no longer here: they are coordinate-drawn by
     * pdf_draw_snapshot_page(), for the same reason photos pages are. This
     * function is now only the text pages. */
    return pdf_render_text_page_html($page);
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
    /* Said plainly and early, because the alternative is a "Class not found"
     * fatal from deep inside this function that reads like an application bug
     * rather than a missing upload. vendor/ is not in git and has to be put on
     * the server by hand — see tools/build-deploy.php, which refuses to make a
     * bundle without it. */
    pdf_require_library();
    if (!class_exists(\Mpdf\Mpdf::class)) {
        throw new RuntimeException('pdf_library_missing');
    }

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
    $mpdf->SetTitle(year_project_title($project) . ($project['subtitle'] ? ' — ' . $project['subtitle'] : ''));

    /* The cover, and nothing else, in front of the book's own pages — it is not
     * a book_pages row (see schema.sql's own comment on book_pages for why).
     *
     * There WAS an interior title page here as well, the traditional second
     * piece of front matter, repeating the title and subtitle on plain white.
     * Kathryn asked for it to go: "I actually don't want an internal title
     * page, just a cover." The cover already carries both lines, and a photo
     * book of one person's year is not a volume that needs announcing twice.
     *
     * mPDF has no page until something asks for one, and Image() is not
     * something that asks: called first, it draws into nowhere and the page is
     * silently short. WriteHTML used to open the document by accident, which is
     * why this was never needed before the cover became a drawn page. */
    $mpdf->AddPage();

    pdf_draw_cover_page($mpdf, $geo, $project, $coverPhoto);

    if ($pages !== array()) {
        $mpdf->AddPage();
    }

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

    /* Explicit AddPage() between pages rather than page-break-after in markup.
     * A photos page is drawn with the coordinate API, which paints onto the
     * CURRENT page, so the break has to be something this loop does between
     * pages rather than something buried in the markup of one. */
    $lastIndex = count($pages) - 1;
    foreach ($pages as $index => $page) {
        if ($page['page_type'] === 'photos') {
            pdf_draw_photos_page(
                $mpdf,
                $page,
                $geo,
                $choices[(int) $page['id']] ?? null,
                book_page_caption($page, $page['slots'])
            );
        } elseif ($page['page_type'] === 'snapshot') {
            /* Also coordinate-drawn — see pdf_draw_snapshot_page(). It paints
               onto the CURRENT page, so it belongs on this side of the branch
               with the photos rather than in the WriteHTML path below. */
            pdf_draw_snapshot_page($mpdf, $page, $geo);
        } else {
            $mpdf->WriteHTML(pdf_wrap_page(pdf_render_page_html($page), false));
        }

        if ($index !== $lastIndex) {
            $mpdf->AddPage();
        }
    }

    $bytes = $mpdf->Output('', 'S');

    /* The old scratch directory is still swept — nothing writes to it on the
     * normal path any more, but a fallback might. The prepared images are NOT
     * swept: they are a cache that makes the next export fast and lets an
     * interrupted one resume, so they are only aged out. */
    pdf_cleanup_export_crops();
    imageproc_prune_export_cache();

    /* The download's name follows the book's name, so a renamed book does not
     * arrive in the Downloads folder still called by its year. The year is kept
     * on the front regardless: it is what makes a shelf of these files sort and
     * makes two books called "Our Big Year" tell themselves apart. Anything not
     * safe in a filename becomes a hyphen rather than being dropped, so two
     * different titles cannot collapse into one name. */
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', year_project_title($project));
    $slug = trim(substr(trim((string) $slug, '-'), 0, 60), '-');
    $year = (string) $project['year'];

    $filename = 'Keepsake-' . $year
        . ($slug !== '' && $slug !== $year ? '-' . $slug : '')
        . '.pdf';

    return array(
        'bytes'       => $bytes,
        'filename'    => $filename,
        // cover + every book_pages row. No interior title page — see the
        // AddPage() sequence above.
        'page_count'  => 1 + count($pages),
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
