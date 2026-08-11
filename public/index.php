<?php
/* The year-project dashboard — the landing page after login.
 *
 * Phase 3 (see PLAN.md) wires this to a real query, replacing Phase 0's
 * hardcoded 2020-current-year loop with fake "in progress"/"not started"
 * labels. Each row is now a live link into public/review.php for that year.
 *
 * WHY THIS SHOWS ONLY YEARS THAT ACTUALLY HAVE A year_projects ROW, RATHER
 * THAN SYNTHESIZING A PLACEHOLDER FOR EVERY YEAR 2020-current: the brief's
 * whole year-auto-assignment model (§3) is that a year "starts existing" the
 * moment something is dated into it — year_project_get_or_create() is the
 * ONLY thing that ever inserts a row here, and nothing else does. A
 * dashboard that shows "2022 — not started" for a year with zero captured
 * content would be inventing a fact the schema doesn't have (there is no
 * status column either — see schema.sql's comment on year_projects for why:
 * "in progress"/"not started" is derivable, not stored). An empty list, or a
 * list of only the years Kathryn has actually put something into, is the
 * more honest reading of that model. If this turns out to read as bare
 * (nothing to click into on a brand new deploy before the first capture),
 * the fix is a single "jump to a year" input on this page that resolves
 * through year_project_get_or_create() when submitted — not fake rows here.
 *
 * "Status" (in-progress vs. has-a-book vs. exported) is likewise not stored
 * — it's derived per row below from EXISTS checks against the tables that
 * would actually contain that evidence (any content, any event groups, any
 * book_layouts row), same reasoning as the removed status column.
 *
 * No separate views/partials layer — this file IS the template, matching
 * every sibling app in the suite (see public/index.php in personal-cms). */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';

require_login_page();

$yearProjects = year_project_list();

/**
 * A one-word-ish status derived from what actually exists for this year,
 * never stored (see this file's header). Cheap: four EXISTS() lookups per
 * row, and a dashboard is read far less often than content changes.
 */
function dashboard_status(int $yearProjectId): string
{
    $hasLayout = (bool) q(
        'SELECT 1 FROM book_layouts WHERE year_project_id = ? LIMIT 1',
        array($yearProjectId)
    )->fetchColumn();
    if ($hasLayout) {
        return 'layout generated';
    }

    $hasContent = (bool) q('SELECT 1 FROM photos WHERE year_project_id = ? LIMIT 1', array($yearProjectId))->fetchColumn()
        || (bool) q('SELECT 1 FROM quotes WHERE year_project_id = ? LIMIT 1', array($yearProjectId))->fetchColumn()
        || (bool) q('SELECT 1 FROM anecdotes WHERE year_project_id = ? LIMIT 1', array($yearProjectId))->fetchColumn()
        || (bool) q('SELECT 1 FROM snapshots WHERE year_project_id = ? LIMIT 1', array($yearProjectId))->fetchColumn();

    return $hasContent ? 'in progress' : 'empty';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body>
<main class="wrap">
  <header class="screen-head">
    <h1>Year Projects</h1>
    <?php if (auth_is_logged_in()): ?>
    <div class="head-actions">
      <form method="post" action="logout.php">
        <button class="link-btn" type="submit">Log out</button>
      </form>
    </div>
    <?php endif; ?>
  </header>

  <p class="hint">
    Each year is its own capture pool and its own book. Jump into a year to
    review and edit everything captured for it, or add new quotes,
    anecdotes, snapshots and photos from the Add tab.
  </p>

  <?php if ($yearProjects === array()): ?>
    <div class="empty">
      <p>No years yet.</p>
      <p class="hint">A year starts existing the moment something is dated
      into it — add a quote, anecdote, snapshot or photo from the Add tab and
      it'll show up here.</p>
    </div>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($yearProjects as $project):
          $isCurrent = (int) $project['year'] === (int) date('Y');
          $status    = dashboard_status((int) $project['id']);
      ?>
        <div class="card row-between">
          <div>
            <?php /* The book's own name if she gave it one, else the year —
                     year_project_title(). A renamed book has to be findable by
                     the name she renamed it to. */ ?>
            <strong><?= h(year_project_title($project)) ?></strong>
            <?php if ($project['title'] && (string) $project['title'] !== (string) $project['year']): ?>
              <div class="hint"><?= h((string) $project['year']) ?></div>
            <?php endif; ?>
            <?php if ($project['subtitle']): ?>
              <div class="hint"><?= h((string) $project['subtitle']) ?></div>
            <?php elseif ($isCurrent): ?>
              <div class="hint">Current year</div>
            <?php endif; ?>
          </div>
          <div class="row">
            <span class="pill<?= $status === 'empty' ? ' is-plain' : '' ?>">
              <?= h($status) ?>
            </span>
            <a class="link-btn" href="review.php?year=<?= h((string) $project['year']) ?>">Open</a>
            <?php if ($status === 'layout generated'): ?>
              <a class="link-btn" href="layout.php?year=<?= h((string) $project['year']) ?>">Book</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<?php if (auth_is_logged_in()): ?>
<!-- Still two stops: Phase 3's review/browse screen (public/review.php) is
     reached per-year from an "Open" link above, not from a third global tab
     — there's no single review screen without a year to look at, the same
     reason this dashboard itself has no "Review" tab of its own. -->
<nav class="tabbar">
  <a href="index.php" class="is-active">Years</a>
  <a href="capture.php">Add</a>
</nav>
<?php endif; ?>
</body>
</html>
