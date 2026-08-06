<?php

declare(strict_types=1);

/**
 * Year-project dashboard — the landing page after login.
 *
 * Phase 1 hasn't built the year_projects table yet, so this renders a
 * placeholder/preview state rather than a real query. The structure below
 * (loop over $yearProjects) is written the way the real version will work,
 * so wiring in Phase 1's data model later should mean replacing the
 * `$yearProjects = [...]` block with a DB query — not rewriting this view.
 */

// TODO(Phase 1): replace with a real query against year_projects, e.g.
//   SELECT * FROM year_projects ORDER BY year DESC
$yearProjects = [
    ['year' => 2026, 'status' => 'in progress', 'is_current' => true],
    ['year' => 2025, 'status' => 'not started', 'is_current' => false],
    ['year' => 2024, 'status' => 'not started', 'is_current' => false],
    ['year' => 2023, 'status' => 'not started', 'is_current' => false],
    ['year' => 2022, 'status' => 'not started', 'is_current' => false],
    ['year' => 2021, 'status' => 'not started', 'is_current' => false],
    ['year' => 2020, 'status' => 'not started', 'is_current' => false],
];
?>
<div class="page-header">
    <h1>Year Projects</h1>
    <p class="page-header__lede">
        Each year is its own capture pool and its own book. Jump into a year
        to add quotes, anecdotes, snapshots, and photos, or to review and
        lay out its book.
    </p>
</div>

<div class="notice notice--info">
    <strong>Preview only.</strong> This dashboard is scaffolding from Phase 0.
    The cards below show the intended layout for 2020&ndash;2026 (Kathryn's
    backfill range plus the current year) but aren't wired to real data yet
    &mdash; that's Phase 1 (data model) and Phase 2 (capture flow). Nothing
    here is clickable yet.
</div>

<div class="year-grid">
    <?php foreach ($yearProjects as $project): ?>
        <div class="year-card <?= $project['is_current'] ? 'year-card--current' : '' ?>">
            <div class="year-card__year"><?= e((string) $project['year']) ?></div>
            <div class="year-card__status">
                <span class="badge badge--<?= $project['status'] === 'in progress' ? 'active' : 'muted' ?>">
                    <?= e($project['status']) ?>
                </span>
            </div>
            <?php if ($project['is_current']): ?>
                <p class="year-card__hint">Current year</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="year-card year-card--add" aria-hidden="true">
        <div class="year-card__add-icon">+</div>
        <div class="year-card__hint">New year project</div>
    </div>
</div>
