<?php
/* Gone — this screen is now the Book tab of public/project.php.
 *
 * Kept as a redirect, for the same reasons as public/review.php beside it: two
 * screens became two tabs, and this URL has been in use for months. See
 * public/project.php's header for the merge itself.
 *
 * NOTE FOR ANYONE GREPPING: lib/layout.php is a completely different file —
 * the book-layout ENGINE — and is not going anywhere. Only this thin public
 * entry point moved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/page.php';

require_login_page();

$params = $_GET;
unset($params['year'], $params['id'], $params['tab']);

$year    = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$project = $year > 0 ? year_project_get_by_year($year) : null;

if ($project === null) {
    header('Location: index.php', true, 302);
    exit;
}

/* ?layout=N — which version to open — survives the move, so a link to a
   specific version still lands on that version. */
header('Location: ' . project_url((int) $project['id'], 'book', $params), true, 302);
exit;
