<?php
/**
 * PAGE LAB — the NON-PHOTO pages, at true page proportions, in a browser.
 *
 * Writes one self-contained HTML file showing snapshot, quote and anecdote
 * pages side by side, in variants, so a taste question can be settled by
 * looking rather than by exporting a PDF and scrolling to page 19.
 *
 * WHY A THIRD LAB. layout-lab.php and layout-lab2.php prototype PHOTO pages:
 * how many to a page, what shape, how much white space. They need a CSV export
 * of a real library and a folder of thumbnails, because the whole question is
 * about photographs. The pages this file draws are text with at most one hero
 * photo, so it needs no export, no database and no arguments — which is the
 * point: it runs anywhere, including before an upload.
 *
 * WHAT MAKES IT MORE THAN A MOCKUP: every page below is built from the
 * exporter's own functions in lib/pdfexport.php, dropped into a box the size
 * of a real page. Text pages go through pdf_render_page_html() whole. Snapshot
 * pages are coordinate-drawn in the PDF (pdf_draw_snapshot_page), so this file
 * positions the same two rectangles from the same geometry and fills the text
 * half with the exporter's own pdf_render_snapshot_text_html(). Change a size
 * in the exporter, re-run this, and the difference is on screen.
 *
 * THAT MIRRORING IS THE ONE PLACE THIS FILE CAN LIE, and it is why the
 * rectangle maths lives in lab_snapshot_page() below rather than being typed
 * twice: it reads the same $geo and the same 45/55 split. If the exporter's
 * split ever changes, this changes with it or the lab is wrong.
 *
 * WHAT IT IS NOT. mPDF is not a browser. Font metrics, table column widths and
 * line breaking differ, so this shows COMPOSITION AND PROPORTION — where things
 * sit, how much room they take, whether a page feels crowded — not the printed
 * typography to the millimetre. The one thing that IS exact is the page shape
 * and the margins, because those come from pdf_export_geometry().
 *
 * Usage:
 *   php tools/page-lab.php                    -> tools/page-lab.html
 *   php tools/page-lab.php --out=/tmp/lab.html
 *   php tools/page-lab.php --hero=path/to/a/photo.jpg
 *
 * With no --hero it draws the dashed empty-hero box the exporter draws when a
 * snapshot has no photo picked. Pass a real one to see a real page; it is
 * embedded as a data URI so the output file stays self-contained.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
define('APP_ROOT', $root);
define('PUBLIC_DIR', $root . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');

/* config.example.php rather than config.php: this must run in a checkout that
 * has never been configured, and the only values it reads are the page's trim
 * and bleed, which are the same in both. */
if (!function_exists('cfg')) {
    function cfg(string $path, $default = null)
    {
        $node = $GLOBALS['config'] ?? array();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
$GLOBALS['config'] = require $root . '/config.example.php';

require_once $root . '/lib/pdfexport.php';

$opt  = getopt('', array('out:', 'hero:'));
$out  = $opt['out'] ?? __DIR__ . '/page-lab.html';
$hero = $opt['hero'] ?? null;

/* ------------------------------------------------------------------ hero */

/**
 * A data URI for the hero photo, so the output file is self-contained and can
 * be opened from anywhere — including sent to a phone.
 *
 * The exporter takes an absolute path and emits <img src="/abs/path">, which
 * is exactly right for mPDF and useless in a browser opened from somewhere
 * else. So the path is swapped for a data URI AFTER rendering, which keeps
 * this file from having its own opinion about how the exporter builds an
 * image tag.
 */
function lab_hero_data_uri(?string $path): ?string
{
    if ($path === null) {
        return null;
    }
    if (!is_file($path)) {
        fwrite(STDERR, "hero not found, drawing the empty box instead: {$path}\n");
        return null;
    }

    $bytes = file_get_contents($path);
    if ($bytes === false) {
        return null;
    }

    $info = @getimagesize($path);
    $mime = $info['mime'] ?? 'image/jpeg';

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

$heroUri = lab_hero_data_uri($hero);

/* ------------------------------------------------------------- fixtures */

/**
 * One snapshot page's worth of data, in the shape
 * book_layout_pages_with_content() hands the renderer.
 */
function lab_snapshot(string $title, array $sections, bool $withHero = true): array
{
    return array(
        'page_type'               => 'snapshot',
        'snapshot_title'          => $title,
        'snapshot_date'           => '2025-04-15',
        'snapshot_hero_photo_id'  => $withHero ? 1 : null,
        'snapshot_hero_original'  => $withHero ? 'lab-hero' : null,
        'snapshot_sections'       => array_map(
            static fn(array $s): array => array('heading' => $s[0], 'body' => $s[1]),
            $sections
        ),
    );
}

/* The cases worth looking at are the ones where a page can go wrong: a lot of
 * sections, one long one, no title, no photo. A page that looks right with two
 * tidy lines tells you nothing. */
$snapshotCases = array(
    'Birthday, as it comes out of the template' => lab_snapshot(
        "Emma's 8th Birthday",
        array(
            array('Age', '8'),
            array('Height', "3'9\""),
            array('', 'Cake was chocolate, and she asked for exactly one candle to be relit so she could blow it out again.'),
        )
    ),
    'School year, six sections' => lab_snapshot(
        'First day of 2nd grade',
        array(
            array('Grade', '2nd'),
            array('School', 'Forest North Elementary'),
            array('Teacher', 'Ms. Devore'),
            array('Favorite color', 'Turquoise'),
            array('Dream job', 'Marine biologist'),
            array('Favorite class', 'Art, but only the painting part'),
        )
    ),
    'A grown-up birthday — different headings entirely' => lab_snapshot(
        "Kathryn's 40th Birthday",
        array(
            array('Where', 'The escape room, then dinner'),
            array('Who came', 'Everybody, somehow'),
            array('What I said about turning 40', 'Nothing printable.'),
        )
    ),
    'One long section — does the column hold?' => lab_snapshot(
        'The year in one paragraph',
        array(
            array('How it went', 'She learned to ride a bike in April and immediately rode it into the neighbour\'s hedge. In June we drove to the coast and she refused to go in the water for two days, then would not come out of it for the rest of the week. School started and she came home on the first day announcing that she now had a best friend, a nemesis, and a favourite bench.'),
        )
    ),
    'No title' => lab_snapshot('', array(
        array('Age', '8'),
        array('Height', "3'9\""),
    )),
    'No hero photo picked' => lab_snapshot(
        "Emma's 8th Birthday",
        array(array('Age', '8'), array('Height', "3'9\"")),
        false
    ),
);

/**
 * One text page's worth of data, in the shape the renderer is handed.
 *
 * THROUGH pdf_render_page_html(), like the snapshots above, and NOT through
 * the standalone renderers directly — which is what the first version of this
 * file did, and it drew a page that does not exist: a text page is wrapped by
 * pdf_render_text_page_html() in a padding-top that pushes it down the sheet,
 * and skipping the wrapper put every quote at the top of a page it will never
 * sit at the top of. A lab that renders a different page from the exporter is
 * worse than no lab.
 */
function lab_text_page(bool $isQuote, string $text, string $who, string $date): array
{
    return array(
        'page_type' => 'text',
        'slots'     => array(array(
            'quote_id'       => $isQuote ? 1 : null,
            'anecdote_id'    => $isQuote ? null : 1,
            'quote_text'     => $isQuote ? $text : null,
            'anecdote_text'  => $isQuote ? null : $text,
            'quote_date'     => $isQuote ? $date : null,
            'anecdote_date'  => $isQuote ? null : $date,
            'who_said_it'    => $isQuote ? $who : null,
        )),
    );
}


$quoteCases = array(
    'Short — the case hanging marks are most visible on' => lab_text_page(
        true, 'Poop poop is for dinner!', 'Emma', '2025-11-11'
    ),
    'Two lines' => lab_text_page(
        true,
        'I don\'t want to be four anymore, I want to be five, but only on the weekends.',
        'Emma', '2025-03-02'
    ),
    'Long enough to wrap several times' => lab_text_page(
        true,
        'When I grow up I want to be a marine biologist and also a person who drives the little truck at the airport, and I want to live next door to you but in my own house with a slide instead of stairs.',
        'Emma', '2025-09-14'
    ),
    'A name that is not Kathryn or Emma' => lab_text_page(
        true, 'She gets that from your side of the family.', 'Grandma', '2025-12-25'
    ),
);

$anecdoteCases = array(
    'Unchanged, for comparison' => lab_text_page(
        false,
        'We ate dinner and Emma was laughing so hard that milk sprayed out of her nose.',
        '', '2025-07-11'
    ),
    'Longer' => lab_text_page(
        false,
        'She spent the entire drive home explaining, in order, every single thing that had happened at the party, including who cried and why, and then fell asleep mid-sentence about the cake.',
        '', '2025-08-02'
    ),
);

/* --------------------------------------------------------------- render */

$geo = pdf_export_geometry();

/**
 * A snapshot page, positioned the way pdf_draw_snapshot_page() positions it.
 *
 * The PDF draws this one by coordinate rather than as flowing HTML, because
 * mPDF's tables will not hold a height and the page ended up occupying its top
 * 40%. So the lab cannot simply render "the exporter's HTML" for this page —
 * there isn't any; there are two rectangles and a call to
 * pdf_render_snapshot_text_html() for one of them.
 *
 * These are the same numbers, read from the same $geo: 7.5% inset (the margin
 * the photo solver leaves), an 8mm gutter, 45/55.
 */
function lab_snapshot_page(array $page, array $geo, ?string $heroUri): string
{
    $trimWMm = (float) $geo['trim_width_in'] * 25.4;
    $trimHMm = (float) $geo['trim_height_in'] * 25.4;
    $bleedMm = (float) $geo['bleed_in'] * 25.4;
    $insetMm = $trimWMm * 0.075;

    $boxX = $bleedMm + $insetMm;
    $boxY = $bleedMm + $insetMm;
    $boxW = $trimWMm - (2 * $insetMm);
    $boxH = $trimHMm - (2 * $insetMm);

    $gutterMm = 8.0;
    $heroW    = ($boxW - $gutterMm) * 0.45;
    $textW    = ($boxW - $gutterMm) * 0.55;
    $textX    = $boxX + $heroW + $gutterMm;

    /* The book's portrait ratio, from the same constant the exporter reads,
       and centred on the page the way pdf_draw_snapshot_page() centres it. */
    $heroH = min($boxH, $heroW / COMPOSE_CANON['P']);
    $heroY = $boxY + max(0.0, ($boxH - $heroH) / 2.0);

    $hero = $page['snapshot_hero_photo_id'] !== null && $heroUri !== null
        ? '<img src="' . $heroUri . '" style="width:100%;height:100%;object-fit:cover;">'
        : '<div style="border:0.5mm dashed #bbb;width:100%;height:100%;"></div>';

    return sprintf(
        '<div style="position:absolute;left:%.2fmm;top:%.2fmm;width:%.2fmm;height:%.2fmm;">%s</div>'
        . '<div style="position:absolute;left:%.2fmm;top:%.2fmm;width:%.2fmm;height:%.2fmm;'
        . 'display:flex;align-items:center;overflow:hidden;"><div style="width:100%%;">%s</div></div>',
        $boxX, $heroY, $heroW, $heroH, $hero,
        $textX, $boxY, $textW, $boxH, pdf_render_snapshot_text_html($page)
    );
}

/** One page box, at the real trim size, holding the exporter's own HTML. */
function lab_page(string $label, string $innerHtml, array $geo, bool $absolute = false): string
{
    return '<figure class="page-case">'
        . '<figcaption>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</figcaption>'
        /* A coordinate-drawn page positions everything from the page's own
           top-left, so it gets NO padding — its inset is already in the
           rectangles. A flowing page keeps the content margin mPDF would give
           it. */
        . '<div class="page" style="width:' . round($geo['page_width_mm'], 2) . 'mm;'
        . 'height:' . round($geo['page_height_mm'], 2) . 'mm;'
        . 'padding:' . ($absolute ? '0' : round($geo['content_margin_mm'], 2) . 'mm') . ';">'
        . '<div class="page-trim" style="inset:' . round($geo['bleed_in'] * 25.4, 2) . 'mm;"></div>'
        . $innerHtml
        . '</div>'
        . '</figure>';
}

$sections = array();

$html = '';
foreach ($snapshotCases as $label => $page) {
    $html .= lab_page($label, lab_snapshot_page($page, $geo, $heroUri), $geo, true);
}
$sections[] = array('Snapshot pages', 'Two-up: hero one side, title and sections the other. Section headings bold, body copy as it was.', $html);

$html = '';
foreach ($quoteCases as $label => $page) {
    $html .= lab_page($label, pdf_render_page_html($page), $geo);
}
$sections[] = array(
    'Quote pages',
    'Hanging quotation marks: the opening mark sits outside the text block so the words line up. Look at the left edge of the first line against the ones under it.',
    $html
);

$html = '';
foreach ($anecdoteCases as $label => $page) {
    $html .= lab_page($label, pdf_render_page_html($page), $geo);
}
$sections[] = array(
    'Anecdote pages',
    'Deliberately unchanged this round — centred, 20pt, no quotation marks. Here so the quote treatment can be compared against something.',
    $html
);

/* ----------------------------------------------------------------- write */

$body = '';
foreach ($sections as [$heading, $note, $cases]) {
    $body .= '<section><h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p class="note">' . $note . '</p>'
        . '<div class="cases">' . $cases . '</div></section>';
}

$shell = file_get_contents(__DIR__ . '/page-lab-shell.html');
if ($shell === false) {
    fwrite(STDERR, "tools/page-lab-shell.html is missing.\n");
    exit(1);
}

$meta = sprintf(
    'Page %.2f&times;%.2f in (%.2f in trim + %.3f in bleed), %.2f in content margin. Rendered from lib/pdfexport.php.',
    $geo['page_width_in'],
    $geo['page_height_in'],
    $geo['trim_width_in'],
    $geo['bleed_in'],
    $geo['bleed_in'] + $geo['safety_margin_in']
);

file_put_contents($out, str_replace(array('<!--BODY-->', '<!--META-->'), array($body, $meta), $shell));

fwrite(STDERR, sprintf(
    "%s\n%d snapshot, %d quote, %d anecdote pages%s\n",
    $out,
    count($snapshotCases),
    count($quoteCases),
    count($anecdoteCases),
    $heroUri === null ? ' (no --hero given: snapshot pages draw the empty box)' : ''
));
