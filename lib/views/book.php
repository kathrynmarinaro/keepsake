<?php
/* The Book tab of a project screen (public/project.php?tab=book).
 *
 * This was public/layout.php, whole — see lib/views/content.php's header for
 * why the two screens became two tabs. public/project.php resolves the
 * project, renders the chrome and the tab strip, and requires this file with
 * $project already in scope and guaranteed non-null, which is why the
 * null-guards the prologue used to carry are gone rather than left inert.
 */

declare(strict_types=1);

if (!isset($project) || !is_array($project)) {
    http_response_code(404);
    exit;
}

/* Named once, here. Everything below prints the book's own name rather than
   its year, because a project may not have one — year_project_title() is the
   single place that decides what a book is called. */
$projectId   = (int) $project['id'];
$projectName = year_project_title($project);

/* A function in PHP cannot see this file's top-level variables, and
   render_text_slot() needs the project to build the link to its entry. A
   constant rather than threading a parameter down through render_page() and
   render_page_canvas(), neither of which has any other use for it. Guarded
   because tools/verify-screens.php renders views repeatedly. */
if (!defined('BOOK_TAB_PROJECT_ID')) {
    define('BOOK_TAB_PROJECT_ID', $projectId);
}

require_once __DIR__ . '/../grouping.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../layout_render.php';
require_once __DIR__ . '/../pdfexport.php';

$layouts = book_layouts_for_year((int) $project['id']);

/* Which version to inspect: the one asked for, else the year's active one,
 * else the newest. A layout id that belongs to a DIFFERENT year is ignored
 * rather than rendered — the isolation rule (PLAN.md) is a property of every
 * screen, not just of the writes. */
$requested = isset($_GET['layout']) ? (int) $_GET['layout'] : 0;
$selected  = null;
foreach ($layouts as $layout) {
    if ((int) $layout['id'] === $requested) {
        $selected = $layout;
    }
}
if ($selected === null && $layouts !== array()) {
    $activeId = $project['active_book_layout_id'] === null ? 0 : (int) $project['active_book_layout_id'];
    foreach ($layouts as $layout) {
        if ((int) $layout['id'] === $activeId) {
            $selected = $layout;
        }
    }
    if ($selected === null) {
        $selected = $layouts[0];
    }
}

$pages = $selected === null ? array() : book_layout_pages_with_content((int) $selected['id']);

$coverPhoto = ($project['cover_photo_id'] !== null)
    ? photo_get((int) $project['cover_photo_id'])
    : null;

/**
 * A run of text with its whitespace collapsed, optionally cut short.
 *
 * $len = null MEANS DO NOT CUT, and that is what every text slot passes.
 * This used to cut a quote at 90 characters — "There was a cucumber that grew
 * really big and had a face and then it grew into a jack-in-…" — while the PDF
 * printed the whole thing, because the exporter's own cap is 4000 characters
 * and exists only to stop a pathological paste. So the preview was not a
 * preview: it showed a page that will never be printed, and the one thing this
 * screen is for is judging what will be.
 *
 * The slot cannot overflow from this. Anything longer than
 * config's layout.text_page_chars is given a page of its own by the layout
 * engine long before it reaches a shared slot, and .ks-slot-text scrolls.
 */
function page_snippet(string $text, ?int $len = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($len === null || mb_strlen($text) <= $len) {
        return $text;
    }
    return mb_substr($text, 0, $len - 1) . '…';
}

function page_fmt_date(string $ymd): string
{
    $ts = strtotime($ymd);
    return $ts === false ? $ymd : date('M j, Y', $ts);
}

/**
 * A snapshot page: the hero photo on one side, the title and sections on the
 * other. Two-up portrait, as Kathryn asked for.
 *
 * NO TYPE LABEL. It used to print a "Birthday" / "School year" pill at the top
 * of the text column — "I don't want the type of content shown on the page".
 * The pill in the page's TOOLBAR stays: that is
 * screen chrome for telling pages apart while reordering them, and it is
 * outside the drawn page.
 *
 * Mirrors pdf_draw_snapshot_page() in lib/pdfexport.php: 45/55 with a gutter,
 * the hero at the book's portrait ratio, and the pair treated as ONE BLOCK
 * that is centred on the page — so a short text centres against the photo and
 * a long one starts on its top edge and runs down past it. The PDF draws that
 * by coordinate because mPDF will not hold a table's height, which is what
 * left this page occupying the top 40% of the sheet until tools/page-lab.php
 * made it visible. Here the same shape comes out of a CSS table.
 */
function render_snapshot_page(array $page): string
{
    ob_start();
    $hero     = $page['snapshot_hero_thumb'] ?: $page['snapshot_hero_original'];
    $title    = trim((string) ($page['snapshot_title'] ?? ''));
    $sections = $page['snapshot_sections'] ?? array();
    ?>
    <div class="ks-snapshot">
      <?php /* The two columns are a CSS table on purpose — see .ks-snapshot-grid
               in styles.css. It is the only construction that centres a short
               text against the hero AND top-aligns a long one with it, which is
               what pdf_draw_snapshot_page() does. */ ?>
      <div class="ks-snapshot-grid">
      <div class="ks-snapshot-hero-cell">
        <?php if ($hero): ?>
          <img class="ks-snapshot-hero" src="<?= h((string) $hero) ?>" alt="">
        <?php else: ?>
          <div class="ks-snapshot-hero is-empty"><span class="hint">No hero photo</span></div>
        <?php endif; ?>
      </div>
      <div class="ks-snapshot-body">
        <?php if ($title !== ''): ?>
          <h3 class="ks-snapshot-title"><?= h($title) ?></h3>
        <?php endif; ?>
        <div class="ks-snapshot-date"><?= h(page_fmt_date((string) $page['snapshot_date'])) ?></div>
        <?php foreach ($sections as $section):
            $heading = trim((string) ($section['heading'] ?? ''));
            $body    = trim((string) ($section['body'] ?? ''));
            if ($heading === '' && $body === '') { continue; }
        ?>
          <div class="ks-section">
            <?php if ($heading !== ''): ?>
              <div class="ks-section-heading"><?= h($heading) ?></div>
            <?php endif; ?>
            <?php if ($body !== ''): ?>
              <div class="ks-section-body"><?= nl2br(h($body)) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * One photo slot — draggable, since photos are the only thing Phase 6's
 * swap/move gestures act on (lib/repo.php's book_page_slot_swap()/_move()).
 *
 * $flexStyle is this leaf's `flex: <weight> 0 0` from
 * a rectangle solved by lib/compose.php — see render_page_canvas() — so this
 * slot is positioned and sized by the same numbers the PDF uses, rather than
 * by anything the browser works out for itself.
 *
 * A slot with a manual crop override (book_page_photos.crop_x/y/w/h, set via
 * the "Adjust crop" control) renders as a sized `background-image` instead
 * of a plain `<img>` — lib/layout_render.php's layout_crop_css() header
 * explains why `object-position` alone can't reproduce an arbitrary
 * zoom+pan. Every OTHER slot (no override, the common case) stays a plain
 * `<img>` with `object-fit: cover` — cheaper, and real `<img>` semantics.
 */
function render_photo_slot(array $slot, string $flexStyle = ''): string
{
    ob_start();
    $src     = (string) ($slot['thumb_path'] ?: $slot['original_path']);
    $hasCrop = $slot['crop_x'] !== null && $slot['crop_w'] !== null;
    ?>
    <figure class="ks-slot ks-slot-photo" draggable="true" style="<?= h($flexStyle) ?>"
            data-slot-id="<?= (int) $slot['id'] ?>" data-photo-id="<?= (int) $slot['photo_id'] ?>"
            data-original="<?= h((string) $slot['original_path']) ?>"
            data-src="<?= h($src) ?>"
            data-crop="<?= $hasCrop ? h((string) json_encode(array(
                'x' => (float) $slot['crop_x'], 'y' => (float) $slot['crop_y'],
                'w' => (float) $slot['crop_w'], 'h' => (float) $slot['crop_h'],
            ))) : '' ?>">
      <div class="ks-slot-photo-frame">
        <?php if ($hasCrop):
            $css = layout_crop_css(array(
                'x' => (float) $slot['crop_x'], 'y' => (float) $slot['crop_y'],
                'w' => (float) $slot['crop_w'], 'h' => (float) $slot['crop_h'],
            ));
        ?>
          <div class="ks-slot-photo-bg"
               style="background-image:url('<?= h($src) ?>');background-size:<?= h($css['size']) ?>;background-position:<?= h($css['position']) ?>;"></div>
        <?php else: ?>
          <img src="<?= h($src) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <button type="button" class="ks-slot-crop-btn" data-act="adjust-crop"
                data-slot-id="<?= (int) $slot['id'] ?>" aria-label="Adjust crop">⤢</button>
        <?php /* Takes this photo OUT OF THE BOOK — it marks the photo itself as
                 skipped, which is why it is not called "delete": the photo stays
                 in the library and on the Content tab, where the same flag can be
                 turned off again. The page it was on re-arranges around what is
                 left. */ ?>
        <button type="button" class="ks-slot-drop-btn" data-act="drop-photo"
                data-slot-id="<?= (int) $slot['id'] ?>"
                aria-label="Take this photo out of the book"
                title="Take this photo out of the book">✕</button>
      </div>
      <?php /* No per-photo caption here any more. Kathryn chose the page-foot
               treatment, so a photo's caption prints as part of one line at the
               bottom of the page (book_page_caption()) — showing it under the
               photo as well would put something on this screen that the book
               will not have. Captions are still authored per photo, on the
               review screen. */ ?>
    </figure>
    <?php
    return ob_get_clean();
}

/** A quote/anecdote card — riding along on a photo page, or standing alone
 *  on a page_type='text' page. Never draggable — see this file's header. */
function render_text_slot(array $slot, bool $standalone, string $flexStyle = ''): string
{
    ob_start();
    $isQuote = $slot['quote_id'] !== null;
    ?>
    <?php /* NO "quote" / "anecdote" PILL. It used to open this card and it
             printed — "I don't want the type of content shown on the page".
             The page's own toolbar pill, outside the drawn page, stays: that
             is how you tell pages apart while reordering them.

             A quote already announces itself with quotation marks, and an
             anecdote with a date and no attribution; neither needed a label to
             say what it was. */ ?>
    <?php
      /* CLICKING THE TEXT OPENS ITS DETAIL PAGE — "click and it opens the detail
         page for the quote/anecdote/etc".
 
         A LINK to the Content tab rather than a modal opened in place. The
         detail form is a <details> element that only the Content tab renders;
         entry-modal.js works by MOVING that element into an overlay, so there
         is nothing here for it to move. The alternatives were rendering every
         entry's form into this screen as well — a second copy of markup kept in
         sync by hand — or fetching it over the wire, which is a new endpoint and
         a new rendering path for a link's worth of benefit.
 
         An <a> also means it behaves like a link: middle-click, long-press,
         open in a new tab. And a text slot is not a drag target, so there is no
         click-versus-drag to disentangle here — only photos are draggable. */
      $entryKind = $isQuote ? 'quote' : 'anecdote';
      $entryId   = (int) ($isQuote ? $slot['quote_id'] : $slot['anecdote_id']);
      $entryHref = project_url(BOOK_TAB_PROJECT_ID, 'content', array('view' => 'grid', 'type' => $entryKind))
          . '#entry-' . $entryKind . '-' . $entryId;
    ?>
    <a class="ks-slot ks-slot-text<?= $standalone ? ' is-standalone' : '' ?><?= $isQuote ? ' is-quote' : '' ?>"
       href="<?= h($entryHref) ?>" style="<?= h($flexStyle) ?>"
       title="Open this <?= h($entryKind) ?> to edit it">
      <?php if ($isQuote): ?>
        <?php /* Both marks inline, and the whole thing centred — the mirror of
                 pdf_render_text_block_html(). The opening mark used to hang in
                 its own grid cell so the lines kept one straight left edge;
                 centred lines have no straight edge for it to hang off, so it
                 came back inline when the centring was asked for. */ ?>
        <div class="ks-quote-body">
          <p class="ks-quote-lines">&ldquo;<?= h(page_snippet((string) $slot['quote_text'], null)) ?>&rdquo;</p>
          <span class="hint ks-quote-meta"><?= h((string) $slot['who_said_it']) ?> · <?= h(page_fmt_date((string) $slot['quote_date'])) ?></span>
        </div>
      <?php else: ?>
        <p><?= h(page_snippet((string) $slot['anecdote_text'], null)) ?></p>
        <span class="hint ks-quote-meta"><?= h(page_fmt_date((string) $slot['anecdote_date'])) ?></span>
      <?php endif; ?>
    </a>
    <?php
    return ob_get_clean();
}

/**
 * One occupant, positioned absolutely from a solved rectangle.
 *
 * The rectangle is a percentage of the square page, straight out of
 * compose_solve(), so this function does no geometry of its own — that is the
 * point. The same numbers drive lib/pdfexport.php, which is the only way the
 * preview and the printed book can be guaranteed to agree.
 */
function render_placed_slot(array $slot, array $rect): string
{
    $style = sprintf(
        'position:absolute;left:%.4f%%;top:%.4f%%;width:%.4f%%;height:%.4f%%;',
        $rect['x'], $rect['y'], $rect['w'], $rect['h']
    );

    return $slot['photo_id'] !== null
        ? render_photo_slot($slot, $style)
        : render_text_slot($slot, false, $style);
}

/**
 * The whole page canvas: a fixed-aspect box (styles.css: square, matching
 * config's default trim) containing the composition tree built from this
 * page's CURRENT slots. Roles (portrait/landscape) and the tree shape are
 * recomputed here, every render — see lib/layout_render.php's header on
 * why that's deliberate rather than reading back a decision made when the
 * page was generated.
 *
 * $mirror alternates by page NUMBER, not stored anywhere — plain visual
 * variety between two same-shaped pages a reader will see close together.
 */
function render_page_canvas(array $slots, ?array $choice, string $caption, int $pageId): string
{
    if ($choice === null) {
        /* No template accepts these shapes. Draw the page empty and say so,
         * rather than inventing an arrangement: a page that looks plausible and
         * is wrong is worse than one that is visibly broken. */
        return '<div class="ks-page-canvas is-unplaceable">'
            . '<p class="hint">No template fits this page\'s photo shapes.</p></div>';
    }

    $tpl   = compose_templates()[$choice['name']];
    $occ   = compose_bind(compose_occupants($slots), $tpl, $choice['order']);
    $rects = compose_solve($tpl, $occ);

    $inner = '';
    foreach ($rects as $rect) {
        $slot = $slots[$choice['order'][$rect['slot']]] ?? null;
        if ($slot !== null) {
            $inner .= render_placed_slot($slot, $rect);
        }
    }

    /* The foot caption sits centred in the white space between the photos and
     * the bottom of the page — Kathryn's correction to a fixed offset, which
     * put the line in a different relationship to the photos on every page
     * because the block's height changes. Computed from the solved rectangles
     * rather than guessed. */
    $bottom = 0.0;
    foreach ($rects as $rect) {
        $bottom = max($bottom, $rect['y'] + $rect['h']);
    }
    $footTop = ($bottom + 100.0) / 2.0;

    $inner .= sprintf(
        '<div class="ks-page-caption" style="top:%.4f%%" data-page-id="%d" '
            . 'contenteditable="true" spellcheck="false" role="textbox" '
            . 'aria-label="Page caption" data-original="%s">%s</div>',
        $footTop,
        $pageId,
        h($caption),
        h($caption)
    );

    return '<div class="ks-page-canvas">' . $inner . '</div>';
}

/** One page card: header, and whichever body its page_type calls for. */
function render_page(array $page, ?array $choice): string
{
    ob_start();
    $type = (string) $page['page_type'];
    ?>
    <section class="card ks-page" data-page-id="<?= (int) $page['id'] ?>"
              data-page-number="<?= (int) $page['page_number'] ?>" data-page-type="<?= h($type) ?>">
      <div class="row-between ks-page-head">
        <?php /* The grip is what is draggable, not the page — the page's
                 PHOTOS are already drag sources for the swap/move gesture, and
                 a page that is itself draggable swallows those drags before
                 they start. Scoping it to the grip keeps the two gestures from
                 contending for the same pointer.

                 HTML5 drag-and-drop, matching the photo drag beside it. That
                 makes page reordering desktop-only, as the photo drag already
                 is — iOS fires no drag events for touch. This screen is
                 primarily a desktop one (brief §5.2), and the alternative is a
                 second, pointer-based drag implementation running alongside
                 the first. */ ?>
        <span class="ks-page-grip" draggable="true" title="Drag to move this page"
              aria-hidden="true" data-page-drag="<?= (int) $page['id'] ?>">⠿</span>
        <strong class="ks-page-label">Page <?= (int) $page['page_number'] ?></strong>
        <div class="row ks-page-actions">
          <span class="pill<?= $type === 'photos' ? '' : ' is-plain' ?>"><?= h($type) ?></span>
          <?php if ($type === 'photos' && count($page['slots']) > 1): ?>
            <?php /* Cycles this page through the arrangements its photos allow,
                     and saves the one it lands on. Only offered where there is
                     something to cycle THROUGH: a single-photo page has one
                     arrangement and a button that does nothing is worse than no
                     button. */ ?>
            <button type="button" class="btn-ghost" data-act="cycle-arrangement"
                    data-page-id="<?= (int) $page['id'] ?>" title="Try this page a different way">
              Try another arrangement
            </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($type === 'blank'): ?>
        <?php /* A REAL PAGE WITH NOTHING ON IT, and the one page type that is
                 drawn to be noticed rather than to look like the book. It is
                 here because a printer binds in fours; it is drawn because a
                 space you can see is a space you can decide to fill. */ ?>
        <div class="ks-blank">
          <span class="ks-blank-mark" aria-hidden="true">+</span>
          <p class="hint">Blank page — room for a photo, a quote or a snapshot</p>
        </div>
      <?php elseif ($type === 'snapshot'): ?>
        <?= render_snapshot_page($page) ?>
      <?php elseif ($type === 'text'): ?>
        <?php foreach ($page['slots'] as $slot) { echo render_text_slot($slot, true); } ?>
      <?php elseif ($page['slots'] === array()): ?>
        <?php // Phase 6's known gap: moving the only photo off a page can leave
              // an empty book_pages row — see lib/pdfexport.php's
              // identical fail-soft case. ?>
        <p class="hint">(empty page)</p>
      <?php else: ?>
        <?= render_page_canvas(
              $page['slots'],
              $choice,
              book_page_caption($page, $page['slots']),
              (int) $page['id']
            ) ?>
      <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}
?>

  <p class="hint">
    Generating a layout always adds a new version — nothing is ever
    overwritten, so re-running to see a different arrangement costs nothing.
  </p>

  <!-- Brief §4.6, final step before export: title/subtitle/cover. A
       property of the YEAR, not of any one layout version, so it's shown
       regardless of which version is open below. -->
  <?php /* An accordion, closed by default. The title, the subtitle, the cover
           photo and its crop are four controls you touch once per book and
           then never again — and they sat above the version list and the page
           grid, which are what you actually came to this tab for. Collapsed,
           they are one row; the summary carries the book's name so it still
           says what is inside without being opened.

           <details>, not a JS toggle: it remembers nothing between loads,
           which is right (it should always come back closed), and it works
           before any module has run. */ ?>
  <details class="accordion" data-role="title-accordion">
    <summary class="accordion-head">Title &amp; cover</summary>
    <div class="accordion-body">
  <div class="card is-flush" data-role="title-card" data-year-project="<?= (int) $project['id'] ?>">

    <?php /* ONE BUTTON, opening the same dialog the kebab's "Rename" opens.
             It used to be two tap-to-edit rows with two "Reset"/"Remove"
             buttons beside them — a different gesture, in a different place,
             for the same two fields the project menu already edits. Kathryn
             asked for the interactions to match, and the way to make two
             things match is for there to be one of them: this calls
             renameProject() in project-menu.js, exactly as the kebab does.

             Both lines are shown, not just the title, because they print
             together and the dialog edits them together. The title is muted
             when she has not named the book — the year showing there is a
             default the app is filling in, not a value she chose. */ ?>
    <div class="row-between cover-name-row">
      <div class="cover-name">
        <span class="row-sub">Title</span>
        <span class="row-text<?= $project['title'] ? '' : ' muted' ?>" data-role="title"><?= h(year_project_title($project)) ?></span>
        <span class="row-sub">Subtitle</span>
        <span class="row-text<?= $project['subtitle'] ? '' : ' muted' ?>" data-role="subtitle"><?= h($project['subtitle'] ?: 'None') ?></span>
      </div>
      <button type="button" class="btn-secondary" data-act="rename-project">Edit</button>
    </div>

    <?php
      /* THE COVER AS IT WILL PRINT, not a thumbnail of the photo.
       *
       * Kathryn asked for this after an export whose cover was wrong in four
       * ways at once — it was the only page in the book she could not look at
       * before paying to print it. It is a square, the photo fills it and
       * bleeds off every edge, and the title band sits where the PDF puts it,
       * using cover_band_metrics() so the two cannot drift.
       *
       * The bleed is drawn as a dashed edge rather than hidden: the photo
       * genuinely does run past the trim, and what is outside that line is
       * what the printer cuts off. Better to see it than to be surprised by
       * it on paper. */
      $coverCrop = ($project !== null && $project['cover_crop_x'] !== null)
          ? layout_crop_css(array(
              'x' => (float) $project['cover_crop_x'], 'y' => (float) $project['cover_crop_y'],
              'w' => (float) $project['cover_crop_w'], 'h' => (float) $project['cover_crop_h'],
            ))
          : null;

      $coverGeo  = pdf_export_geometry();
      $safeFrac  = (float) $coverGeo['content_margin_mm'] / (float) $coverGeo['page_height_mm'];
      $subtitle  = trim((string) ($project['subtitle'] ?? ''));
      $band      = cover_band_metrics($subtitle !== '', $safeFrac);
      $bleedFrac = (float) $coverGeo['bleed_in'] / (float) $coverGeo['page_width_in'];

      /* The type sizes come from cover_band_metrics() too, in fractions of the
       * page, and become container units here — 1cqw is 1% of the preview's
       * width, and the preview is the page. They used to be constants in
       * styles.css, tuned by eye, and were nearly double what the exporter
       * printed; a preview whose whole job is showing where the title falls
       * cannot have its own opinion about how big the title is. */
      $bandCss = static fn(float $frac): string => round($frac * 100, 3) . 'cqw';
    ?>
    <div class="ks-cover-row">
      <div class="ks-cover-preview<?= $coverPhoto === null ? ' is-empty' : '' ?>"
           data-role="cover-preview"
           data-year-project="<?= (int) $project['id'] ?>"
           data-photo-id="<?= $coverPhoto !== null ? (int) $coverPhoto['id'] : '' ?>"
           data-original="<?= $coverPhoto !== null ? h((string) $coverPhoto['original_path']) : '' ?>"
           data-crop="<?= $coverCrop !== null ? h((string) json_encode(array(
               'x' => (float) $project['cover_crop_x'], 'y' => (float) $project['cover_crop_y'],
               'w' => (float) $project['cover_crop_w'], 'h' => (float) $project['cover_crop_h'],
           ))) : '' ?>">
        <?php if ($coverPhoto !== null): ?>
          <?php $coverSrc = (string) ($coverPhoto['original_path'] ?: $coverPhoto['thumb_path']); ?>
          <div class="ks-cover-art" data-role="cover-art"
               style="background-image:url('<?= h($coverSrc) ?>');<?= $coverCrop !== null
                   ? 'background-size:' . h($coverCrop['size']) . ';background-position:' . h($coverCrop['position']) . ';'
                   : '' ?>"></div>
        <?php endif; ?>

        <?php /* The band is flex-centred, which puts one line dead centre and a
                 pair centred together — the same result the PDF gets from the
                 explicit top margin cover_band_metrics() hands it. */ ?>
        <div class="ks-cover-band" data-role="cover-band"
             style="left:<?= round($band['inset'] * 100, 3) ?>%;
             right:<?= round($band['inset'] * 100, 3) ?>%;
             top:<?= round($band['top'] * 100, 3) ?>%;
             height:<?= round($band['height'] * 100, 3) ?>%;
             gap:<?= $bandCss($band['gap']) ?>;">
          <span class="ks-cover-title" data-role="cover-title"
                style="font-size:<?= $bandCss($band['title_size']) ?>;"><?= h(year_project_title($project)) ?></span>
          <span class="ks-cover-sub" data-role="cover-sub"
                style="font-size:<?= $bandCss($band['sub_size']) ?>;<?= $subtitle === '' ? 'display:none;' : '' ?>"><?= h($subtitle) ?></span>
        </div>

        <div class="ks-cover-trim" aria-hidden="true"
             style="inset:<?= round($bleedFrac * 100, 3) ?>%;"></div>
      </div>

      <div>
        <p class="hint" data-role="cover-status"><?= $coverPhoto !== null ? 'Cover photo set.' : 'No cover photo chosen yet.' ?></p>
        <p class="hint">The dashed line is where the printer trims. Anything outside it is cut off.</p>
        <div class="row">
          <button type="button" class="btn-ghost" data-act="pick-cover"><?= $coverPhoto !== null ? 'Change cover photo' : 'Choose cover photo' ?></button>
          <?php if ($coverPhoto !== null): ?>
            <button type="button" class="btn-ghost" data-act="crop-cover">Adjust framing</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
    </div>
  </details>

  <?php /* The two things this tab does, side by side, in the order you do
           them. They were two full-width cards stacked one above the other,
           each with a paragraph of explanation, which is a lot of screen for
           two buttons.

           Export ALWAYS reads year_projects.active_book_layout_id — see
           lib/pdfexport.php's header for why that is never "the newest
           version" — and so it is disabled until there is one. A disabled
           button rather than a hidden one: the point is that exporting is the
           step AFTER generating, and a button that appears out of nowhere
           does not teach that.

           A plain GET link, not a fetch(): the browser's own download
           handling is more robust than reimplementing it, and GET needs no
           CSRF header (require_same_origin() is a no-op for GET). No cover
           photo picked does not block it — the export fails soft on that. */ ?>
  <div class="card" data-role="generate-bar" data-year-project="<?= (int) $project['id'] ?>">
    <span class="box-label">Actions</span>
    <div class="book-actions">
    <button class="btn-primary" type="button" data-act="generate">
      <?= $layouts === array() ? 'Create layout' : 'Create new layout' ?>
    </button>
    <?php if ($project['active_book_layout_id'] !== null): ?>
      <?php /* FOUR FILES: three that a hardcover printer wants as separate
               uploads — cover, spine, book block — and the whole book, which is
               the one for reading a proof before paying for anything. Hand a
               printer a single file with the cover as page 1 and the cover gets
               bound in as the first interior page.

               THE SPINE'S WIDTH IS A CONFIG VALUE, not something this app can
               work out: it depends on the page count AND the paper, so the
               printer tells you — it is in the filename of the template they
               send. config.example.php, export.spine_width_in. */ ?>
      <a class="btn-secondary" href="api/export.php?id=<?= $projectId ?>">Export PDF (whole book)</a>
      <a class="btn-secondary" href="api/export.php?id=<?= $projectId ?>&amp;part=cover">Export cover only</a>
      <a class="btn-secondary" href="api/export.php?id=<?= $projectId ?>&amp;part=spine">Export spine only</a>
      <a class="btn-secondary" href="api/export.php?id=<?= $projectId ?>&amp;part=interior">Export pages only</a>
    <?php else: ?>
      <button class="btn-secondary" type="button" disabled
              title="Generate a layout first">Export PDF</button>
    <?php endif; ?>
    </div>
  </div>

  <?php if ($layouts === array()): ?>
    <div class="empty">
      <p>No layouts generated yet.</p>
      <p class="hint">Review this year's content on the
      <a href="<?= h(project_url($projectId, 'content')) ?>">Content tab</a> first —
      skip-for-book, full-page flags and event groups all change what the
      engine does.</p>
    </div>
  <?php else: ?>

    <?php /* A dropdown, not a list of cards.
             Every generated version stays forever — nothing is overwritten,
             which is the point — so after a few tries at getting the book
             right the list was the tallest thing on the screen, and all but
             one row of it was history. The <select> shows the one you are
             looking at; the rest are one tap away.

             A real <form method="get">, so switching version is a navigation
             with its own URL and no JavaScript is required. layout.js
             upgrades it to submit on change; without the module you get a
             "Show" button, which still works. */ ?>
    <div class="card version-bar">
      <form method="get" action="project.php" class="version-picker">
        <input type="hidden" name="id" value="<?= $projectId ?>">
        <input type="hidden" name="tab" value="book">
        <label class="field version-select">
          <span>Layout Versions</span>
          <select name="layout" data-role="version-select">
            <?php foreach ($layouts as $layout):
                $id       = (int) $layout['id'];
                $isActive = $project['active_book_layout_id'] !== null && (int) $project['active_book_layout_id'] === $id;
            ?>
              <option value="<?= $id ?>"<?= ($selected !== null && (int) $selected['id'] === $id) ? ' selected' : '' ?>>
                Version <?= (int) $layout['version'] ?><?= $isActive ? ' (active)' : '' ?>
                — <?= (int) $layout['page_count'] ?> pages, <?= h(page_fmt_date((string) $layout['created_at'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn-secondary version-go" type="submit">Show</button>
      </form>

      <?php if ($selected !== null):
          $selectedId     = (int) $selected['id'];
          $selectedActive = $project['active_book_layout_id'] !== null
              && (int) $project['active_book_layout_id'] === $selectedId;
      ?>
        <div class="hint version-summary">
          <?= (int) $selected['photo_pages'] ?> photo,
          <?= (int) $selected['text_pages'] ?> text,
          <?= (int) $selected['snapshot_pages'] ?> snapshot<?php
            /* The blanks are the spaces still free in the book, so they are
               worth counting out loud rather than hiding inside the total. */
            if ((int) $selected['blank_pages'] > 0): ?>,
            <strong><?= (int) $selected['blank_pages'] ?> blank</strong><?php
            endif; ?> ·
          <?= (int) $selected['slot_count'] ?> filled slots
        </div>

        <?php /* The two actions that belong to the version you are looking at.
                 Delete is never offered for the active one — the endpoint
                 refuses it too, but a button you cannot use is worse than one
                 that is not there. */ ?>
        <div class="row version-actions">
          <?php if (!$selectedActive): ?>
            <button class="btn-secondary" type="button" data-act="activate" data-layout="<?= $selectedId ?>">Use this one</button>
            <button class="btn-danger" type="button" data-act="delete-layout"
                    data-layout="<?= $selectedId ?>" data-version="<?= (int) $selected['version'] ?>">Delete</button>
          <?php else: ?>
            <span class="pill">active — this is what exports</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($selected !== null): ?>
      <h2 class="cat-head">
        Version <?= (int) $selected['version'] ?> — pages
        <span class="cat-count"><?= count($pages) ?></span>
      </h2>

      <?php if ($pages === array()): ?>
        <div class="empty">
          <p>This version has no pages.</p>
          <p class="hint">Nothing eligible was found for this year — every
          photo skipped, or nothing captured yet.</p>
        </div>
      <?php else: ?>
        <p class="hint">
          Drag <span aria-hidden="true">⠿</span> to move a whole page, with
          everything on it, somewhere else in the book. Drag a photo onto
          another photo to swap them, or onto a different page's background to
          move it there. Text cards aren't drag targets. Tap
          <span aria-hidden="true">⤢</span> on a photo to adjust how it's
          cropped on this page. Nothing in this version rearranges itself: it was
          settled when you created it, and only you change it from here.
        </p>
        <?php
          /* Frozen at generation and read back here — see
             layout_page_arrangements(). Template choice is a property of the
             SEQUENCE, not of one page —
             interchangeable layouts rotate by least-recently-used so a book of
             portraits is not the same arrangement forty times over. So it is
             computed once for the whole layout here and handed down, which is
             also what lets lib/pdfexport.php arrive at the same answer. */
          /* One reader, shared with the exporter — see
             layout_page_arrangements(). This used to run the compose_assign()
             loop here and again in lib/pdfexport.php, agreeing only because
             both ran the same function on the same input. */
          $choices = layout_page_arrangements($pages);
        ?>
        <div class="ks-book" data-layout-id="<?= (int) $selected['id'] ?>">
          <?php foreach (array_chunk($pages, 2) as $spread): ?>
            <div class="ks-spread">
              <?php foreach ($spread as $page) { echo render_page($page, $choices[(int) $page['id']] ?? null); } ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  <?php endif; ?>

