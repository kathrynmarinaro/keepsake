<?php
/* The project dashboard — the landing page after login.
 *
 * WHY THIS SHOWS ONLY PROJECTS THAT ACTUALLY HAVE A ROW, RATHER THAN
 * SYNTHESIZING A PLACEHOLDER FOR EVERY YEAR 2020-current: the brief's whole
 * year-auto-assignment model (§3) is that a year "starts existing" the moment
 * something is dated into it — year_project_get_or_create() is the ONLY thing
 * that ever inserts a row here. A dashboard that shows "2022 — not started"
 * for a year with zero captured content would be inventing a fact the schema
 * doesn't have (there is no status column either — see schema.sql's comment on
 * year_projects for why: "in progress"/"not started" is derivable, not
 * stored). An empty list, or a list of only the projects Kathryn has actually
 * put something into, is the more honest reading of that model.
 *
 * "Status" is likewise not stored — it's derived per row below from EXISTS
 * checks against the tables that would actually contain that evidence, same
 * reasoning as the removed status column.
 *
 * WHY THE HEADING SAYS "PHOTO BOOK PROJECTS" AND NOT "YEARS": a project is no
 * longer necessarily a year. Kathryn asked to be able to make a book for a
 * trip, so a project is whatever she decided to put in it and the year is one
 * common way to decide — see lib/repo.php's year_project_title() and the
 * nullable `year` column. The table is still called year_projects because
 * renaming it means rewriting nine foreign keys and every query in the app to
 * fix a word; the UI is where the word matters.
 *
 * No separate views/partials layer — this file IS the template, matching every
 * sibling app in the suite. The chrome (head, header, menu button, floating +)
 * comes from lib/page.php, which is shared with the project screen so the two
 * cannot drift apart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/page.php';

require_login_page();

$projects = year_project_list();

/**
 * A one-word-ish status derived from what actually exists for this project,
 * never stored (see this file's header). Cheap: a handful of EXISTS() lookups
 * per row, and a dashboard is read far less often than content changes.
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

page_head(array('body_class' => 'projects-body'));
page_screen_head(array(
    'heading' => 'Photo Book Projects',
    'menu'    => 'hamburger',
));
?>

  <?php if ($projects === array()): ?>
    <div class="empty">
      <p>No projects yet.</p>
      <p class="hint">Tap + to start one — a book for a year, a trip, or
      anything else worth keeping.</p>
    </div>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($projects as $project):
          $id      = (int) $project['id'];
          $year    = $project['year'] !== null ? (int) $project['year'] : null;
          $title   = year_project_title($project);
          $status  = dashboard_status($id);

          /* The quiet second line. A book that was renamed shows the year it
             covers, since the name no longer says it; a book that still goes
             by its year shows the subtitle instead, if there is one. Never
             both — the card is a glance, not a record. */
          $named = trim((string) ($project['title'] ?? '')) !== '';
          $sub   = '';
          if ($named && $year !== null) {
              $sub = (string) $year;
          } elseif ($project['subtitle']) {
              $sub = (string) $project['subtitle'];
          } elseif ($year !== null && $year === (int) date('Y')) {
              $sub = 'Current year';
          }
      ?>
        <div class="card project-card" data-project="<?= $id ?>" data-title="<?= h($title) ?>">
          <?php /* The whole card is the link, not a separate "Open" button.
                   There was nothing else on the card to tap, and a 48px target
                   in the corner of a 90px card wastes the other 80% of it. The
                   kebab sits OUTSIDE the anchor so tapping it does not also
                   navigate — nesting interactive elements is invalid HTML and
                   behaves differently in every browser that tolerates it. */ ?>
          <a class="project-card-main" href="project.php?id=<?= $id ?>">
            <span class="project-card-name"><?= h($title) ?></span>
            <?php if ($sub !== ''): ?>
              <span class="project-card-sub"><?= h($sub) ?></span>
            <?php endif; ?>
            <span class="pill<?= $status === 'empty' ? ' is-plain' : '' ?>"><?= h($status) ?></span>
          </a>
          <?php page_menu_button('kebab', array('data-project-menu' => $id)) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php
/* POST-only sign-out, so no <img src> can trigger it. The form is empty and
 * hidden; menu.js submits it by selector from the hamburger. */
?>
<form id="logout-form" method="post" action="logout.php" hidden></form>

<?php page_foot(array(
    'fab'     => array('id' => 'add-fab', 'label' => 'Add to a project'),
    'scripts' => array('assets/projects.js'),
)) ?>
