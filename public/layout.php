<?php
/* Book layout screen — Phase 5's version list/generate/activate PLUS Phase
 * 6's real page-by-page review (brief §5.4, PLAN.md).
 *
 * public/layout.php?year=YYYY[&layout=ID]
 *
 * WHAT'S KEPT FROM PHASE 5, VERBATIM IN SPIRIT: the version list ("Versions"
 * — brief §4.5's "can be run multiple times ... to preview/compare"), the
 * "Create book layout" bar, and "Use this one" to switch the active version.
 * PLAN.md is explicit that these are meant to survive this phase untouched,
 * and they do — same markup, same public/api/book-layouts-create.php and
 * -activate.php endpoints, same layout.js delegation.
 *
 * WHAT'S REPLACED: Phase 5's flat, one-column listing of "page N — N slots"
 * cards (deliberately plain, its own header said so) is gone. In its place:
 *
 *   - A "Book title & cover" card — brief §4.6, the final step before
 *     export: title (the year, not editable — brief's own default), subtitle
 *     (tap-to-edit, SAME endpoint/gesture Phase 3 wired on review.php — see
 *     public/api/year-projects-update.php's header), and a manual cover-photo
 *     picker using the exact same openPhotoPicker() pattern review.js already
 *     uses for a snapshot's hero photo (brief: "same pattern ... not
 *     auto-selected").
 *   - A real spread-by-spread visual rendering of the selected version: pages
 *     paired two-up (a "spread"), a snapshot page rendered from its real
 *     template fields (not just its type/date the way Phase 5's preview
 *     showed it), and a text card visually distinct from a photo. Every
 *     "photos" page carries a "Reflow from here" button, wired to
 *     layout_reflow_from() (built and tested in Phase 5 — see that
 *     function's own doc comment for the exact rule, not re-derived here).
 *   - Drag-and-drop, in public/assets/layout.js: drop one photo directly onto
 *     another to SWAP them (book_page_slot_swap()); drop a photo onto a
 *     page's open background to MOVE it there (book_page_slot_move()). Both
 *     PHOTOS ONLY — see those functions' own comments in lib/repo.php for why
 *     text cards aren't drag targets in this build.
 *
 * POST-LAUNCH REWORK (see PLAN.md): a "photos" page's slots no longer render
 * in a flat row of equal-size boxes cropped to squares. Round 6 replaced the
 * nested-flex composition that succeeded it: render_page_canvas()
 * builds lib/layout_render.php's composition tree from the page's CURRENT
 * photos and walks it into nested flex divs sized to each photo's own
 * orientation — a landscape gets a wide cell, a portrait a narrow one — so
 * mismatched pairs stop fighting the grid instead of getting force-cropped
 * to fit it. lib/pdfexport.php builds the SAME tree for the PDF, so the two
 * can't drift apart. render_photo_slot() also carries the "Adjust crop"
 * control for a slot's optional manual crop override.
 *
 * NONE OF THIS IS BROWSER-TESTABLE IN THIS ENVIRONMENT (same constraint every
 * earlier phase has had — no browser, no MySQL). The repo-layer swap/move
 * functions, and the composition tree itself, are proven against the
 * SQLite harness in tools/verify-page-review.php and
 * tools/verify-layout-render.php; the drag gesture, the crop UI, and the
 * visual appearance were traced by hand, not clicked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/grouping.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/layout_render.php';
/* For pdf_export_geometry() — the cover preview is sized from the same trim,
 * bleed and safety numbers the PDF uses, so the two cannot disagree. */
require_once __DIR__ . '/../lib/pdfexport.php';

require_login_page();

$year    = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$project = $year > 0 ? year_project_get_by_year($year) : null;

$layouts = $project === null ? array() : book_layouts_for_year((int) $project['id']);

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

$coverPhoto = ($project !== null && $project['cover_photo_id'] !== null)
    ? photo_get((int) $project['cover_photo_id'])
    : null;

/** First ~90 characters of a run of text, for a slot's card. */
function page_snippet(string $text, int $len = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}

function page_fmt_date(string $ymd): string
{
    $ts = strtotime($ymd);
    return $ts === false ? $ymd : date('M j, Y', $ts);
}

/** A snapshot page's fixed fields, birthday or school-year — brief §2.3. */
function render_snapshot_page(array $page): string
{
    ob_start();
    $isBirthday = $page['snapshot_type'] === 'birthday';
    $hero = $page['snapshot_hero_thumb'] ?: $page['snapshot_hero_original'];

    $facts = array();
    if ($isBirthday) {
        if ($page['snapshot_age'] !== null) { $facts[] = 'Age ' . $page['snapshot_age']; }
        if ($page['snapshot_height']) { $facts[] = (string) $page['snapshot_height']; }
    } else {
        foreach (array('grade' => 'Grade', 'school' => 'School', 'teacher' => 'Teacher',
                        'favorite_color' => 'Favorite color', 'dream_job' => 'Dream job',
                        'favorite_class' => 'Favorite class') as $field => $label) {
            $value = $page['snapshot_' . $field];
            if ($value !== null && $value !== '') {
                $facts[] = $label . ': ' . $value;
            }
        }
    }
    ?>
    <div class="ks-snapshot">
      <?php if ($hero): ?>
        <img class="ks-snapshot-hero" src="<?= h((string) $hero) ?>" alt="">
      <?php endif; ?>
      <div class="ks-snapshot-body">
        <span class="pill"><?= $isBirthday ? 'Birthday' : 'School year' ?></span>
        <div class="hint"><?= h(page_fmt_date((string) $page['snapshot_date'])) ?></div>
        <?php if ($facts !== array()): ?>
          <ul class="ks-snapshot-facts">
            <?php foreach ($facts as $fact): ?><li><?= h($fact) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($page['snapshot_notes']): ?>
          <p class="ks-snapshot-notes"><?= h((string) $page['snapshot_notes']) ?></p>
        <?php endif; ?>
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
    <div class="ks-slot ks-slot-text<?= $standalone ? ' is-standalone' : '' ?>" style="<?= h($flexStyle) ?>">
      <span class="pill is-plain"><?= $isQuote ? 'quote' : 'anecdote' ?></span>
      <?php if ($isQuote): ?>
        <p>“<?= h(page_snippet((string) $slot['quote_text'], $standalone ? 400 : 90)) ?>”</p>
        <span class="hint"><?= h((string) $slot['who_said_it']) ?> · <?= h(page_fmt_date((string) $slot['quote_date'])) ?></span>
      <?php else: ?>
        <p><?= h(page_snippet((string) $slot['anecdote_text'], $standalone ? 400 : 90)) ?></p>
        <span class="hint"><?= h(page_fmt_date((string) $slot['anecdote_date'])) ?></span>
      <?php endif; ?>
    </div>
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

/** One page card: header, reflow button, and whichever body its page_type calls for. */
function render_page(array $page, ?array $choice): string
{
    ob_start();
    $type = (string) $page['page_type'];
    ?>
    <section class="card ks-page" data-page-id="<?= (int) $page['id'] ?>"
              data-page-number="<?= (int) $page['page_number'] ?>" data-page-type="<?= h($type) ?>">
      <div class="row-between ks-page-head">
        <strong>Page <?= (int) $page['page_number'] ?></strong>
        <div class="row ks-page-actions">
          <span class="pill<?= $type === 'photos' ? '' : ' is-plain' ?>"><?= h($type) ?></span>
          <button type="button" class="btn-ghost" data-act="reflow" data-page="<?= (int) $page['page_number'] ?>">
            Reflow from here
          </button>
        </div>
      </div>

      <?php if ($type === 'snapshot'): ?>
        <?= render_snapshot_page($page) ?>
      <?php elseif ($type === 'text'): ?>
        <?php foreach ($page['slots'] as $slot) { echo render_text_slot($slot, true); } ?>
      <?php elseif ($page['slots'] === array()): ?>
        <?php // Phase 6's known gap: moving the only photo off a page can leave
              // an empty book_pages row until a reflow — see lib/pdfexport.php's
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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake — Book layouts</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body>
<main class="wrap">
  <header class="screen-head">
    <h1>Book layouts<?= $project !== null ? ' — ' . h((string) $project['year']) : '' ?></h1>
    <div class="head-actions">
      <a class="link-btn" href="index.php">Years</a>
    </div>
  </header>

<?php if ($project === null): ?>
  <div class="empty">
    <p>No year project for <?= h((string) $year) ?>.</p>
    <p class="hint">A year starts existing the moment something is dated into
    it. Pick one from the <a href="index.php">Years</a> list.</p>
  </div>
<?php else: ?>

  <p class="hint">
    Generating a layout always adds a new version — nothing is ever
    overwritten, so re-running to see a different arrangement costs nothing.
  </p>

  <!-- Brief §4.6, final step before export: title/subtitle/cover. A
       property of the YEAR, not of any one layout version, so it's shown
       regardless of which version is open below. -->
  <div class="card" data-role="title-card" data-year-project="<?= (int) $project['id'] ?>">
    <strong>Book title &amp; cover</strong>
    <p class="hint">Title defaults to the year; an optional subtitle and a cover photo are picked here, same as everywhere else in this app.</p>
    <ul class="list" id="subtitle-list">
      <li class="list-row" data-id="<?= (int) $project['id'] ?>">
        <div class="row-slide">
          <div class="row-body">
            <span class="row-sub">Title</span>
            <span class="row-text ks-static-text"><?= h((string) $project['year']) ?></span>
          </div>
        </div>
      </li>
      <li class="list-row" data-id="<?= (int) $project['id'] ?>">
        <div class="row-slide">
          <div class="row-body">
            <span class="row-sub">Subtitle</span>
            <span class="row-text<?= $project['subtitle'] ? '' : ' muted' ?>" data-role="subtitle"><?= h($project['subtitle'] ?: 'Tap to add a subtitle…') ?></span>
          </div>
        </div>
      </li>
    </ul>

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

        <div class="ks-cover-band" style="left:<?= round($band['inset'] * 100, 3) ?>%;
             right:<?= round($band['inset'] * 100, 3) ?>%;
             top:<?= round($band['top'] * 100, 3) ?>%;
             height:<?= round($band['height'] * 100, 3) ?>%;">
          <span class="ks-cover-year"><?= h((string) $project['year']) ?></span>
          <?php if ($subtitle !== ''): ?>
            <span class="ks-cover-sub" data-role="cover-sub"><?= h($subtitle) ?></span>
          <?php endif; ?>
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

  <!-- Phase 7 (brief §5.5): the one action the exit criterion asks for.
       Always reads year_projects.active_book_layout_id — see
       lib/pdfexport.php's header for why that's never "the newest version".
       A plain GET link, not a JS-driven fetch(): the browser's own download
       handling is simpler and more robust than reimplementing it, and GET
       requests don't need api.js's CSRF header (require_same_origin() is a
       no-op for GET). No cover photo picked yet doesn't block this — the
       export itself fails soft on that (see lib/pdfexport.php). -->
  <div class="card row-between" data-role="export-bar">
    <div>
      <strong>Export PDF</strong>
      <div class="hint">
        <?php if ($project['active_book_layout_id'] !== null): ?>
          Print-ready PDF of the active layout — cover, title page, and every generated page.
        <?php else: ?>
          Generate a layout and mark one active ("Use this one") before exporting.
        <?php endif; ?>
      </div>
    </div>
    <?php if ($project['active_book_layout_id'] !== null): ?>
      <a class="btn-primary" href="api/export.php?year=<?= h((string) $project['year']) ?>">Export PDF</a>
    <?php else: ?>
      <button class="btn-primary" type="button" disabled>Export PDF</button>
    <?php endif; ?>
  </div>

  <div class="card row-between" data-role="generate-bar" data-year-project="<?= (int) $project['id'] ?>">
    <div>
      <strong>Create book layout</strong>
      <div class="hint">Arranges everything reviewed for <?= h((string) $project['year']) ?> into pages.</div>
    </div>
    <button class="btn-primary" type="button" data-act="generate">Create</button>
  </div>

  <?php if ($layouts === array()): ?>
    <div class="empty">
      <p>No layouts generated yet.</p>
      <p class="hint">Review this year's content on the
      <a href="review.php?year=<?= h((string) $project['year']) ?>">review screen</a> first —
      skip-for-book, full-page flags and event groups all change what the
      engine does.</p>
    </div>
  <?php else: ?>

    <h2 class="cat-head">Versions <span class="cat-count"><?= count($layouts) ?></span></h2>
    <div class="stack">
      <?php foreach ($layouts as $layout):
          $id       = (int) $layout['id'];
          $isActive = $project['active_book_layout_id'] !== null && (int) $project['active_book_layout_id'] === $id;
          $isOpen   = $selected !== null && (int) $selected['id'] === $id;
      ?>
        <div class="card version-row<?= $isOpen ? ' is-open' : '' ?>">
          <div class="row-between">
            <div>
              <strong>Version <?= (int) $layout['version'] ?></strong>
              <?php if ($isActive): ?><span class="pill">active</span><?php endif; ?>
              <div class="hint">
                <?= (int) $layout['page_count'] ?> pages ·
                <?= (int) $layout['photo_pages'] ?> photo,
                <?= (int) $layout['text_pages'] ?> text,
                <?= (int) $layout['snapshot_pages'] ?> snapshot ·
                <?= (int) $layout['slot_count'] ?> filled slots ·
                <?= h(page_fmt_date((string) $layout['created_at'])) ?>
              </div>
            </div>
            <div class="row version-actions">
              <?php if (!$isActive): ?>
                <button class="btn-secondary" type="button" data-act="activate" data-layout="<?= $id ?>">Use this one</button>
              <?php endif; ?>
              <a class="link-btn" href="layout.php?year=<?= h((string) $project['year']) ?>&amp;layout=<?= $id ?>">
                <?= $isOpen ? 'Viewing' : 'View pages' ?>
              </a>
              <?php if (!$isActive): ?>
                <?php /* Only ever offered for a version that is NOT active — the
                         endpoint refuses the active one too, but a button you
                         cannot use is worse than one that is not there. */ ?>
                <button class="btn-danger" type="button" data-act="delete-layout"
                        data-layout="<?= $id ?>" data-version="<?= (int) $layout['version'] ?>">Delete</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
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
          Drag a photo onto another photo to swap them. Drag a photo onto a
          different page's background to move it there. Text cards aren't
          drag targets. Tap <span aria-hidden="true">⤢</span> on a photo to
          adjust how it's cropped on this page. “Reflow from here”
          regenerates every page from that one to the end of the book — the
          pages before it are never touched.
        </p>
        <?php
          /* Template choice is a property of the SEQUENCE, not of one page —
             interchangeable layouts rotate by least-recently-used so a book of
             portraits is not the same arrangement forty times over. So it is
             computed once for the whole layout here and handed down, which is
             also what lets lib/pdfexport.php arrive at the same answer. */
          $choices = array();
          $photoPages = array();
          foreach ($pages as $page) {
              if ($page['page_type'] === 'photos' && $page['slots'] !== array()) {
                  $photoPages[] = (int) $page['id'];
                  $occupants[]  = compose_occupants($page['slots']);
              }
          }
          foreach (compose_assign($occupants ?? array()) as $i => $choice) {
              $choices[$photoPages[$i]] = $choice;
          }
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

<?php endif; ?>
</main>

<nav class="tabbar">
  <a href="index.php">Years</a>
  <a href="capture.php">Add</a>
</nav>

<script type="module" src="<?= asset('assets/layout.js') ?>"></script>
</body>
</html>
