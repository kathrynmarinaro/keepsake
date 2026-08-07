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
 * Grid shape is chosen from slot count and orientation using
 * lib/layout.php's own layout_orientation() — reused, not re-derived — so
 * this doesn't invent a second opinion about what "portrait" means.
 */
function pdf_render_photos_page_html(array $page): string
{
    $slots = $page['slots'];
    $n     = count($slots);

    if ($n === 0) {
        // Phase 6's known gap (PLAN.md's Phase 6 note): moving the only
        // photo off a page can leave an empty book_pages row behind until a
        // reflow. Fail soft here too — a quiet blank page, not a crash.
        return '<div style="text-align:center;padding-top:45%;color:#999;font-family:sans-serif;">'
            . '(empty page)</div>';
    }

    $cellHtml = array_map('pdf_render_slot_cell', $slots);

    if ($n === 1) {
        $rows = array(array($cellHtml[0]));
    } elseif ($n === 2) {
        $landscapeCount = 0;
        foreach ($slots as $slot) {
            if ($slot['photo_id'] !== null && layout_orientation($slot) === 'landscape') {
                $landscapeCount++;
            }
        }
        // Two landscapes read better stacked; anything else (portraits,
        // mixed, a text card) reads better side by side.
        $rows = $landscapeCount === 2
            ? array(array($cellHtml[0]), array($cellHtml[1]))
            : array($cellHtml);
    } elseif ($n === 3) {
        $landscapeCount = 0;
        foreach ($slots as $slot) {
            if ($slot['photo_id'] !== null && layout_orientation($slot) === 'landscape') {
                $landscapeCount++;
            }
        }
        $rows = $landscapeCount >= 2
            ? array(array($cellHtml[0]), array($cellHtml[1]), array($cellHtml[2])) // stacked
            : array($cellHtml); // 3 columns
    } else { // 4
        $rows = array(
            array($cellHtml[0], $cellHtml[1]),
            array($cellHtml[2], $cellHtml[3]),
        );
    }

    $html = '<table style="width:100%;border-collapse:collapse;">';
    foreach ($rows as $row) {
        $colWidth = 100 / count($row);
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td style="width:' . $colWidth . '%;padding:3mm;vertical-align:middle;">' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    return $html;
}

/** One slot's cell content — a photo (with inline caption) or a text card. */
function pdf_render_slot_cell(array $slot): string
{
    if ($slot['photo_id'] !== null) {
        $img = pdf_photo_html(
            $slot,
            'width:100%;',
            'width:100%;height:40mm;border:0.5mm dashed #bbb;display:flex;'
                . 'align-items:center;justify-content:center;color:#888;font-family:sans-serif;font-size:9pt;'
        );
        $caption = $slot['caption']
            ? '<div style="font-size:9pt;font-family:sans-serif;margin-top:1.5mm;color:#333;">'
                . pdf_esc(pdf_clip_text((string) $slot['caption'])) . '</div>'
            : '';
        return $img . $caption;
    }

    return pdf_render_text_card_html($slot);
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

/** One book_pages row, dispatched by page_type. */
function pdf_render_page_html(array $page): string
{
    switch ($page['page_type']) {
        case 'snapshot':
            return pdf_render_snapshot_page_html($page);
        case 'text':
            return pdf_render_text_page_html($page);
        default:
            return pdf_render_photos_page_html($page);
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

    $lastIndex = count($pages) - 1;
    foreach ($pages as $index => $page) {
        $mpdf->WriteHTML(pdf_wrap_page(pdf_render_page_html($page), $index !== $lastIndex));
    }

    $bytes = $mpdf->Output('', 'S');

    $filename = 'Keepsake-' . $project['year'] . '.pdf';

    return array(
        'bytes'       => $bytes,
        'filename'    => $filename,
        // cover + title + every book_pages row.
        'page_count'  => 2 + count($pages),
    );
}

/** page-break-after wrapper — $more is false only for the very last page in
 *  the document, so mPDF doesn't emit a trailing blank page. */
function pdf_wrap_page(string $innerHtml, bool $more): string
{
    $style = $more ? 'page-break-after: always;' : '';
    return '<div style="' . $style . '">' . $innerHtml . '</div>';
}
