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
 *     paired two-up (a "spread"), photos shown as actual images at their own
 *     aspect ratio, a snapshot page rendered from its real template fields
 *     (not just its type/date the way Phase 5's preview showed it), and a
 *     text card visually distinct from a photo. Every "photos" page carries
 *     a "Reflow from here" button, wired to layout_reflow_from() (built and
 *     tested in Phase 5 — see that function's own doc comment for the exact
 *     rule, not re-derived here).
 *   - Drag-and-drop, in public/assets/layout.js: drop one photo directly onto
 *     another to SWAP them (book_page_slot_swap()); drop a photo onto a
 *     page's open background to MOVE it there (book_page_slot_move()). Both
 *     PHOTOS ONLY — see those functions' own comments in lib/repo.php for why
 *     text cards aren't drag targets in this build.
 *
 * NONE OF THIS IS BROWSER-TESTABLE IN THIS ENVIRONMENT (same constraint every
 * earlier phase has had — no browser, no MySQL). The repo-layer swap/move
 * functions are proven against the SQLite harness in
 * tools/verify-page-review.php; the drag gesture itself, the visual
 * appearance, and the picker UI were traced by hand, not clicked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/grouping.php';
require_once __DIR__ . '/../lib/layout.php';

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

/** One photo slot — draggable, since photos are the only thing Phase 6's
 *  swap/move gestures act on (lib/repo.php's book_page_slot_swap()/_move()). */
function render_photo_slot(array $slot): string
{
    ob_start();
    ?>
    <figure class="ks-slot ks-slot-photo" draggable="true"
            data-slot-id="<?= (int) $slot['id'] ?>" data-photo-id="<?= (int) $slot['photo_id'] ?>">
      <img src="<?= h((string) ($slot['thumb_path'] ?: $slot['original_path'])) ?>"
           <?php if ($slot['width'] && $slot['height']): ?>
             width="<?= (int) $slot['width'] ?>" height="<?= (int) $slot['height'] ?>"
           <?php endif; ?>
           alt="" loading="lazy">
      <?php if ($slot['caption']): ?>
        <figcaption><?= h(page_snippet((string) $slot['caption'], 70)) ?></figcaption>
      <?php endif; ?>
    </figure>
    <?php
    return ob_get_clean();
}

/** A quote/anecdote card — riding along on a photo page, or standing alone
 *  on a page_type='text' page. Never draggable — see this file's header. */
function render_text_slot(array $slot, bool $standalone): string
{
    ob_start();
    $isQuote = $slot['quote_id'] !== null;
    ?>
    <div class="ks-slot ks-slot-text<?= $standalone ? ' is-standalone' : '' ?>">
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

/** One page card: header, reflow button, and whichever body its page_type calls for. */
function render_page(array $page): string
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
      <?php else: ?>
        <div class="ks-slots" data-count="<?= count($page['slots']) ?>">
          <?php foreach ($page['slots'] as $slot): ?>
            <?php if ($slot['photo_id'] !== null) {
                echo render_photo_slot($slot);
            } else {
                echo render_text_slot($slot, false);
            } ?>
          <?php endforeach; ?>
        </div>
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
<link rel="stylesheet" href="<?= asset('assets/capture.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/layout.css') ?>">
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

    <div class="ks-cover-row">
      <?php if ($coverPhoto !== null): ?>
        <img class="ks-cover-thumb" src="<?= h((string) ($coverPhoto['thumb_path'] ?: $coverPhoto['original_path'])) ?>" alt="">
      <?php else: ?>
        <div class="ks-cover-thumb ks-cover-empty" aria-hidden="true"></div>
      <?php endif; ?>
      <div>
        <p class="hint" data-role="cover-status"><?= $coverPhoto !== null ? 'Cover photo set.' : 'No cover photo chosen yet.' ?></p>
        <button type="button" class="btn-ghost" data-act="pick-cover"><?= $coverPhoto !== null ? 'Change cover photo' : 'Choose cover photo' ?></button>
      </div>
    </div>
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
          drag targets. “Reflow from here” regenerates every page from that
          one to the end of the book — the pages before it are never touched.
        </p>
        <div class="ks-book" data-layout-id="<?= (int) $selected['id'] ?>">
          <?php foreach (array_chunk($pages, 2) as $spread): ?>
            <div class="ks-spread">
              <?php foreach ($spread as $page) { echo render_page($page); } ?>
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
