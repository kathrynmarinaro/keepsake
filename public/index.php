<?php
/* The year-project dashboard — the landing page after login.
 *
 * Phase 1 (see PLAN.md) hasn't built out the review/browse screens yet, so
 * this still renders a placeholder/preview state rather than a real query
 * against year_projects, even though that table exists as of this pass's
 * schema.sql. The loop below is written the way the real version will work,
 * so wiring in a live query is Phase 2/3's job, not a rewrite of this file.
 *
 * No separate views/partials layer — this file IS the template, matching
 * every sibling app in the suite (see public/index.php in personal-cms). */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

require_login_page();

// TODO(Phase 2/3): replace with a real query, e.g.
//   SELECT * FROM year_projects ORDER BY year DESC
$currentYear = (int) date('Y');
$yearProjects = array();
for ($year = $currentYear; $year >= 2020; $year--) {
    $yearProjects[] = array(
        'year'       => $year,
        'status'     => $year === $currentYear ? 'in progress' : 'not started',
        'is_current' => $year === $currentYear,
    );
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
    add quotes, anecdotes, snapshots and photos, or to review and lay out its
    book.
  </p>

  <div class="card">
    <p><strong>Preview only.</strong> This dashboard is scaffolding — the
    cards below show the intended layout for 2020&ndash;<?= h((string) $currentYear) ?>
    (Kathryn's backfill range plus the current year) but aren't wired to real
    data or clickable yet. That's Phase 2/3, once the capture and review
    screens exist on top of Phase 1's schema.</p>
  </div>

  <div class="stack">
    <?php foreach ($yearProjects as $project): ?>
      <div class="card row-between">
        <div>
          <strong><?= h((string) $project['year']) ?></strong>
          <?php if ($project['is_current']): ?>
            <span class="hint">Current year</span>
          <?php endif; ?>
        </div>
        <span class="pill<?= $project['is_current'] ? '' : ' is-plain' ?>">
          <?= h($project['status']) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
