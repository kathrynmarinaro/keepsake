<?php
/* Generated-layout list and page inspector — Phase 5 (see PLAN.md).
 *
 * public/layout.php?year=YYYY[&layout=ID]
 *
 * DELIBERATELY PLAIN, AND DELIBERATELY NOT PHASE 6. Brief §5.4's page review
 * — spread-by-spread visual rendering, drag-and-drop, cover/title selection —
 * is Phase 6's whole job and is not started here. This screen exists so that
 * Phase 5's output can be SANITY-CHECKED by a human (or by a future session)
 * without waiting for that: which versions exist for a year, how each one is
 * composed, and what actually landed on each page.
 *
 * It covers exactly the two things brief §4.5 asks of this phase's UI surface:
 * layout generation "can be run multiple times ... to preview/compare", and
 * picking which of those versions is the one being worked from. Everything
 * else about a page — how it looks, moving a photo, reflowing from here — is
 * left alone on purpose.
 *
 * Reading it: a page is a card; each card lists its slots in slot order. A
 * photo slot shows its thumbnail and (if it has one) the caption that renders
 * inline with it; a text slot shows the quote/anecdote that occupies that
 * slot as a card; a snapshot page shows which snapshot's template it is and
 * has no slots at all, which is exactly what schema.sql says a snapshot page
 * is.
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
    Page-by-page review, moving photos around and “reflow from here” come in
    the next build phase; this screen is here to check what the arrangement
    engine produced.
  </p>

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
        <div class="book-pages">
          <?php foreach ($pages as $page): ?>
            <section class="card book-page">
              <div class="row-between book-page-head">
                <strong>Page <?= (int) $page['page_number'] ?></strong>
                <span class="pill<?= $page['page_type'] === 'photos' ? '' : ' is-plain' ?>">
                  <?= h((string) $page['page_type']) ?><?php
                    if ($page['page_type'] !== 'snapshot') {
                        echo ' · ' . count($page['slots']) . (count($page['slots']) === 1 ? ' slot' : ' slots');
                    }
                  ?>
                </span>
              </div>

              <?php if ($page['page_type'] === 'snapshot'): ?>
                <div class="slot slot-snapshot">
                  <strong><?= h($page['snapshot_type'] === 'birthday' ? 'Birthday snapshot' : 'School-year snapshot') ?></strong>
                  <div class="hint"><?= h(page_fmt_date((string) $page['snapshot_date'])) ?> · full-page template</div>
                </div>
              <?php else: ?>
                <div class="page-slots">
                  <?php foreach ($page['slots'] as $slot): ?>
                    <?php if ($slot['photo_id'] !== null): ?>
                      <figure class="slot slot-photo">
                        <img class="thumb" src="<?= h((string) ($slot['thumb_path'] ?: $slot['original_path'])) ?>" alt="">
                        <figcaption>
                          <span class="hint">
                            <?= h(layout_orientation($slot)) ?>
                            <?= h(substr((string) $slot['captured_at'], 0, 16)) ?>
                          </span>
                          <?php if ($slot['caption']): ?>
                            <span class="slot-caption"><?= h(page_snippet((string) $slot['caption'])) ?></span>
                          <?php endif; ?>
                        </figcaption>
                      </figure>
                    <?php elseif ($slot['quote_id'] !== null): ?>
                      <div class="slot slot-text">
                        <span class="pill is-plain">quote</span>
                        <p>“<?= h(page_snippet((string) $slot['quote_text'])) ?>”</p>
                        <span class="hint"><?= h((string) $slot['who_said_it']) ?> · <?= h(page_fmt_date((string) $slot['quote_date'])) ?></span>
                      </div>
                    <?php else: ?>
                      <div class="slot slot-text">
                        <span class="pill is-plain">anecdote</span>
                        <p><?= h(page_snippet((string) $slot['anecdote_text'])) ?></p>
                        <span class="hint"><?= h(page_fmt_date((string) $slot['anecdote_date'])) ?></span>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
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
