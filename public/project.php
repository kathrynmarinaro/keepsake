<?php
/* One project, two tabs: Content and Book.
 *
 * These were two screens — public/review.php and public/layout.php — with two
 * URLs and no way between them except going up to the list and back down. They
 * are two views of one project, and the tab strip is what says so. Both old
 * URLs still resolve: they redirect here, preserving whatever was on the query
 * string, so a bookmark or a link in a note keeps working.
 *
 * KEYED BY id, NOT BY YEAR. Every screen in the app used to take ?year=YYYY,
 * which was fine while a project WAS a year. A project can now be a trip
 * instead (schema.sql on year_projects.year), and those have no year to key on.
 * project_url() in lib/page.php builds every link into here so the shape of
 * this query string lives in one place.
 *
 * THE TABS ARE A TOP STRIP, NOT THE BOTTOM BAR. The bottom bar is gone
 * entirely (lib/page.php's header has the reasoning); these two are not
 * app-level destinations but a switch between two readings of the thing named
 * in the header directly above them, and they read that way sitting under it.
 * Both are real links with real URLs — no JavaScript is involved in switching
 * tabs, and each tab is separately bookmarkable.
 *
 * THE KEBAB, NOT A HAMBURGER. The menu here is about this project — rename it,
 * export it, delete it — which is the same menu, with the same three entries,
 * that sits on this project's card in the list. App-level actions stay on the
 * list screen's hamburger, one back-tap away.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/page.php';

require_login_page();

/* ---------------------------------------------------------------- inputs */

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

/* ?year= is the old URL shape, still honoured so that review.php's and
   layout.php's redirects — and anything Kathryn bookmarked before the merge —
   land on the right project instead of an error. */
if ($id <= 0 && isset($_GET['year'])) {
    $legacy = year_project_get_by_year((int) $_GET['year']);
    if ($legacy !== null) {
        $id = (int) $legacy['id'];
    }
}

$project = $id > 0 ? year_project_get($id) : null;
$tab     = ($_GET['tab'] ?? '') === 'book' ? 'book' : 'content';

/* ------------------------------------------------------ the project is gone */

if ($project === null) {
    page_head(array('title' => 'Project'));
    page_screen_head(array('heading' => 'Project', 'back' => 'index.php'));
    ?>
    <div class="empty">
      <p>That project doesn't exist.</p>
      <p class="hint">It may have been deleted. Everything you have is on the
      <a href="index.php">projects list</a>.</p>
    </div>
    <?php
    page_foot();
    exit;
}

/* ------------------------------------------------------------------ chrome */

$projectId   = (int) $project['id'];
$projectName = year_project_title($project);

/* The header's second line. A book that was renamed shows the year it covers,
   since its name no longer says it; otherwise the subtitle, which is the thing
   that will print under the title on the cover. */
$named = trim((string) ($project['title'] ?? '')) !== '';
$sub   = '';
if ($named && $project['year'] !== null) {
    $sub = (string) $project['year'];
} elseif ($project['subtitle']) {
    $sub = (string) $project['subtitle'];
}

page_head(array(
    'title'      => $projectName,
    /* review.js reads this on boot to scope every write to this project. It
       was on review.php's <body> and has to keep being on the body, not on the
       tab's own markup, because that is where the module looks. */
    'body_attrs' => array('data-year-project-id' => $projectId),
));

page_screen_head(array(
    'heading' => $projectName,
    'sub'     => $sub,
    'back'    => 'index.php',
    'menu'    => 'kebab',
));
?>

<nav class="tabstrip" aria-label="Project sections">
  <a href="<?= h(project_url($projectId, 'content')) ?>"<?= $tab === 'content' ? ' class="is-active" aria-current="page"' : '' ?>>Content</a>
  <a href="<?= h(project_url($projectId, 'book')) ?>"<?= $tab === 'book' ? ' class="is-active" aria-current="page"' : '' ?>>Book</a>
</nav>

<?php
/* The tab's own prologue runs here, after the chrome, because nothing in the
 * header depends on it and a slow query should not delay the header painting.
 * $project, $projectId and $projectName are already in scope; each view
 * re-derives the last two anyway so it stays readable on its own. */
require __DIR__ . '/../lib/views/' . $tab . '.php';

page_foot(array(
    /* Inside a project the + needs no menu: this is the project, so capture
       pins whatever is saved to it whatever the date says. A plain link, so it
       survives a long press and opens in a new tab. */
    'fab'     => array(
        'href'  => 'capture.php?project=' . $projectId,
        'label' => 'Add to ' . $projectName,
    ),
    'scripts' => array_merge(
        array('assets/project.js'),
        $tab === 'book' ? array('assets/layout.js') : array('assets/review.js')
    ),
));
