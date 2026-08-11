<?php
/* Gone — this screen is now the Content tab of public/project.php.
 *
 * Kept as a redirect rather than deleted. Two screens became two tabs (see
 * public/project.php's header), and this URL is the one Kathryn has been
 * using and bookmarking for months; a 404 on it would look like the app had
 * lost the year rather than moved it.
 *
 * 302, not 301: a permanent redirect is cached by the browser forever, and
 * "forever" is a long time to be unable to take this back if the merge turns
 * out to be wrong.
 *
 * The query string is carried across intact — project.php still understands
 * ?year= (it resolves it to an id), and the view/type filters mean the same
 * thing on the other side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/page.php';

require_login_page();

$params = $_GET;
unset($params['year'], $params['id']);

$year    = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$project = $year > 0 ? year_project_get_by_year($year) : null;

/* A year with no project of its own has nowhere to land, so the list is the
   honest destination — it is also where every project actually is. */
if ($project === null) {
    header('Location: index.php', true, 302);
    exit;
}

header('Location: ' . project_url((int) $project['id'], 'content', $params), true, 302);
exit;
