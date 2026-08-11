<?php
/**
 * The screens render.
 *
 * WHY THIS EXISTS. Merging public/review.php and public/layout.php into the two
 * tabs of public/project.php moved about 1,200 lines of template between files.
 * Every verify-*.php beside this one tests logic through repo functions with no
 * template involved, so a view that references a variable the merge left
 * behind — $year, say, which project.php no longer defines — passes every one
 * of them and fails the first time Kathryn opens the page.
 *
 * php -l cannot catch it either: an undefined variable is a runtime warning,
 * not a parse error. So this file actually RENDERS each view against the
 * SQLite harness database with a real project in it, with the error handler
 * turned up so that a warning is a failure rather than a line of noise inside
 * otherwise-valid HTML.
 *
 * It is a smoke test and says so: it proves the templates run and close their
 * tags, not that they look right. The screens' behaviour is covered by
 * verify-review.php, verify-layout.php and verify-projects.php.
 *
 * EACH RENDER RUNS IN ITS OWN PROCESS, which is why this file re-invokes
 * itself with --render. The views declare functions at the top level, exactly
 * as the screens they came from always did, and PHP cannot redeclare a
 * function — so rendering two views, or the same view twice, in one process
 * dies on the second. That is a property of running templates in a loop, not
 * a defect in the templates: a web request renders exactly one. Wrapping every
 * helper in the views in function_exists() to please a test would be the test
 * dictating the shape of the product.
 *
 * Usage: php tools/verify-screens.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_DIR', APP_ROOT . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads');

require __DIR__ . '/test-harness.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/repo.php';
require __DIR__ . '/../lib/page.php';

/* bootstrap.php is not loaded — it needs a real config.php and would try to
 * open a MySQL connection. The three helpers the templates actually reach for
 * are stood up here instead, matching bootstrap.php's own definitions. */
if (!function_exists('cfg')) {
    function cfg(string $path, $default = null)
    {
        $node = $GLOBALS['config'] ?? array();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
$GLOBALS['config'] = require __DIR__ . '/../config.example.php';

if (!function_exists('h')) {
    function h(?string $raw): string
    {
        return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('asset')) {
    function asset(string $relative): string
    {
        return h(ltrim($relative, '/'));
    }
}

/* DELIBERATELY NOT REQUIRING lib/grouping.php OR lib/geocode.php HERE.
 *
 * A view has to pull in whatever it calls, because public/project.php requires
 * it and nothing else does. Requiring grouping.php in this harness — which is
 * what this file did at first — satisfied lib/views/content.php's calls to
 * event_grouping_internal_gaps() and event_grouping_gap_days() from the OUTSIDE,
 * so the Groups view rendered green here and fatally errored in a browser with
 * "call to undefined function". The test was standing in for the require the
 * view was missing.
 *
 * Whatever the views need, the views must ask for. */

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
    if (!$ok && $detail !== '') {
        foreach (explode("\n", rtrim($detail)) as $line) {
            echo '         ' . $line . "\n";
        }
    }
}

/* Any notice, warning or deprecation inside a template is a failure. An
 * undefined variable in a view is exactly the class of bug this file exists to
 * catch, and PHP's default is to print it into the middle of the page and
 * carry on. */
$problems = array();
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$problems): bool {
    $problems[] = basename($file) . ':' . $line . ' — ' . $msg;
    return true;
});

/**
 * Render one view the way public/project.php does and report what went wrong.
 *
 * @return array{html:string, problems:string[], fatal:?string}
 */
function render_view(string $tab, array $project, array $get): array
{
    global $problems;
    $problems = array();

    $_GET = $get;

    ob_start();
    $fatal = null;
    try {
        require APP_ROOT . '/lib/views/' . $tab . '.php';
    } catch (Throwable $e) {
        $fatal = get_class($e) . ': ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    $html = (string) ob_get_clean();

    return array('html' => $html, 'problems' => $problems, 'fatal' => $fatal);
}

/** Every <?php if/foreach ?> in a template has to close, or the page is cut off. */
function tags_balance(string $html, string $tag): bool
{
    return substr_count($html, '<' . $tag) === substr_count($html, '</' . $tag . '>');
}

/* ------------------------------------------------------------- the fixture */

/**
 * A database with something in it. Rebuilt from scratch in every child
 * process — the harness database is in-memory, so it cannot be shared, and
 * rebuilding it is a few dozen inserts.
 *
 * @return array{year:int, trip:int, layout:int}
 */
function build_fixture(): array
{
    harness_pdo();

/* Two projects, because the interesting difference is between a book that has
 * a year and one that does not — the second is the case the merge introduced
 * and the one where a leftover $year reference would blow up. */
$yearId  = year_project_create(2025, null, 'A year of small things');
$tripId  = year_project_create(null, 'Iceland');
/* A third, left deliberately empty: the empty states are the only place a
   view prints the book's own name, so they are where a leftover $year would
   still be visible. */
$emptyId = year_project_create(null, 'Nothing in here');

foreach (array($yearId, $tripId) as $pid) {
    quote_create(array(
        'quote_text'      => 'Look at the size of that puffin.',
        'who_said_it'     => 'Emma',
        'entry_date'      => '2025-06-01',
        'year_project_id' => $pid,
    ));
    anecdote_create(array(
        'anecdote_text'   => 'She would not stop talking about the puffin.',
        'entry_date'      => '2025-06-02',
        'year_project_id' => $pid,
    ));
    snapshot_create(array(
        'type'            => 'birthday',
        'entry_date'      => '2025-04-15',
        'age'             => 8,
        'height'          => '4 feet',
        'year_project_id' => $pid,
    ));
    photo_create(array(
        'year_project_id' => $pid,
        'original_path'   => 'original/fixture.jpg',
        'thumb_path'      => 'thumb/fixture.jpg',
        'captured_at'     => '2025-06-01 10:00:00',
        'width'           => 1200,
        'height'          => 800,
    ));
}

/* A group, so the Groups view has something to draw. */
event_group_create(array(
    'year_project_id' => $yearId,
    'name'            => 'Iceland, June',
    'start_date'      => '2025-06-01',
    'end_date'        => '2025-06-02',
));

/* A layout with a page, so the Book tab renders its version list AND its page
 * grid rather than only the empty state. */
$layoutId = book_layout_create($yearId);
book_page_create($layoutId, 1, 'photos');
year_project_set_active_layout($yearId, $layoutId);

    return array(
        'year'  => $yearId,
        'trip'  => $tripId,
        'empty' => $emptyId,
        'layout' => $layoutId,
    );
}

/* ------------------------------------------------------------- child mode */

/* `php tools/verify-screens.php --render <tab> <year|trip> [k=v ...]` renders
 * exactly one view and prints the result as JSON for the parent to check. */
if (($argv[1] ?? '') === '--render') {
    $ids = build_fixture();

    $tab   = (string) ($argv[2] ?? 'content');
    $which = (string) ($argv[3] ?? 'year');
    $get   = array('id' => $ids[$which]);
    foreach (array_slice($argv, 4) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $get[$k] = $v === 'LAYOUT_ID' ? (string) $ids['layout'] : $v;
    }

    $project = year_project_get($ids[$which]);
    $out     = render_view($tab, $project, $get);

    restore_error_handler();
    echo json_encode(array(
        'fatal'    => $out['fatal'],
        'problems' => $out['problems'],
        'balanced' => tags_balance($out['html'], 'div'),
        'html'     => $out['html'],
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

/* ------------------------------------------------------------ parent mode */

/**
 * Render one view in a fresh process. Returns the child's decoded report, or a
 * synthetic fatal if the child died so hard it printed nothing parseable —
 * which is itself the most important failure this file can report.
 */
function render_in_child(string $tab, string $which, array $get = array()): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --render ' . escapeshellarg($tab) . ' ' . escapeshellarg($which);
    foreach ($get as $k => $v) {
        $cmd .= ' ' . escapeshellarg($k . '=' . $v);
    }

    $raw     = shell_exec($cmd . ' 2>&1');
    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded)) {
        return array(
            'fatal'    => 'the render process died: ' . trim((string) $raw),
            'problems' => array(),
            'balanced' => false,
            'html'     => '',
        );
    }
    return $decoded;
}

/* ------------------------------------------------------------ content tab */

echo "\nContent tab...\n";

/* Every combination of the two filter axes, because they became independent:
   view and type used to be tangled (groups was a third VIEW and the type
   filter only existed inside the grid), and the whole point of separating them
   is that all twelve pairs are now reachable URLs. */
foreach (array('grid', 'timeline') as $view) {
foreach (array('all', 'photo', 'snapshot', 'quote', 'anecdote', 'groups') as $type) {
    $out   = render_in_child('content', 'year', array('view' => $view, 'type' => $type));
    $label = 'view=' . $view . ' type=' . $type;

    check($label . ' renders', $out['fatal'] === null, (string) $out['fatal']);
    check($label . ' is clean', $out['problems'] === array(), implode("\n", $out['problems']));
    check($label . ' closes its divs', $out['balanced'] === true);
}}

/* The case the merge created: a project with no year at all. Anything still
   printing $project['year'] as an identity shows up here as an empty string in
   the page or a warning above. */
$out = render_in_child('content', 'trip', array('view' => 'grid', 'type' => 'photo'));
check('a yearless project renders', $out['fatal'] === null, (string) $out['fatal']);
check('a yearless project is clean', $out['problems'] === array(), implode("\n", $out['problems']));

/* The empty states are the only place a view names the book — everywhere else
   the name is in the header, which project.php renders. An empty YEARLESS
   project is therefore the one render that would show a leftover $year as a
   blank in the middle of a sentence. */
$out = render_in_child('content', 'empty', array('view' => 'grid', 'type' => 'photo'));
check('an empty yearless project renders', $out['fatal'] === null, (string) $out['fatal']);
check('an empty yearless project is clean', $out['problems'] === array(), implode("\n", $out['problems']));
check(
    'its empty state names the book, not a blank year',
    str_contains($out['html'], 'Nothing in here')
);

/* --------------------------------------------------------------- book tab */

echo "\nBook tab...\n";

$out = render_in_child('book', 'year', array('tab' => 'book'));
check('with a layout it renders', $out['fatal'] === null, (string) $out['fatal']);
check('with a layout it is clean', $out['problems'] === array(), implode("\n", $out['problems']));
check('with a layout it closes its divs', $out['balanced'] === true);

$out = render_in_child('book', 'year', array('tab' => 'book', 'layout' => 'LAYOUT_ID'));
check('a specific version renders', $out['fatal'] === null, (string) $out['fatal']);
check('a specific version is clean', $out['problems'] === array(), implode("\n", $out['problems']));

/* No layout at all — the state every project starts in. */
$out = render_in_child('book', 'trip', array('tab' => 'book'));
check('with no layout it renders', $out['fatal'] === null, (string) $out['fatal']);
check('with no layout it is clean', $out['problems'] === array(), implode("\n", $out['problems']));

/* ------------------------------------------------------------ project_url */

echo "\nproject_url()...\n";

check(
    'content is the short URL',
    project_url(7) === 'project.php?id=7'
);
check(
    'book names its tab',
    project_url(7, 'book') === 'project.php?tab=book&id=7'
        || project_url(7, 'book') === 'project.php?id=7&tab=book'
);
check(
    'filters ride along',
    str_contains(project_url(7, 'content', array('view' => 'grid')), 'view=grid')
);
check(
    'a fragment is appended',
    str_ends_with(project_url(7, 'content', array(), 'entry-photo-3'), '#entry-photo-3')
);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " CHECK(S) FAILED.\n");
exit($failures === 0 ? 0 : 1);
