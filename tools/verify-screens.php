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
    /* LONG ENOUGH TO HAVE BEEN TRUNCATED. The Book tab used to cut a slot's
       quote at 90 characters while the PDF printed all of it, so the preview
       showed a page that would never be printed. 120 characters, and still
       under config's layout.text_page_chars, so it stays in a shared slot
       rather than being promoted to a page of its own. */
    quote_create(array(
        'quote_text'      => 'Look at the size of that puffin, it is the biggest puffin anyone has ever seen and I would like to take it home now.',
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
        'title'           => "Emma's 8th Birthday",
        'year_project_id' => $pid,
        'sections'        => array(
            array('heading' => 'Age', 'body' => '8'),
            array('heading' => 'Height', 'body' => '4 feet'),
            array('heading' => '', 'body' => 'Cake was chocolate.'),
        ),
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

/* GIVE ONE SNAPSHOT A HERO PHOTO, so the detail form has a preview to draw.
   Without this every snapshot in the fixture is heroless and the preview markup
   is never exercised — which is how a form that only ever printed an id got
   through a green suite in the first place. */
$heroPhotoId = (int) q(
    'SELECT id FROM photos WHERE year_project_id = ? ORDER BY id LIMIT 1',
    array($yearId)
)->fetchColumn();
$heroSnapId = (int) q(
    'SELECT id FROM snapshots WHERE year_project_id = ? ORDER BY id LIMIT 1',
    array($yearId)
)->fetchColumn();
snapshot_update($heroSnapId, array('hero_photo_id' => $heroPhotoId));

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

/* A snapshot PAGE, so the Book tab renders the two-up snapshot layout rather
   than only photo pages — that page reads snapshot_sections through the join
   in book_layout_pages_with_content(), which nothing else here exercises. */
$snapForPage = q(
    'SELECT id FROM snapshots WHERE year_project_id = ? ORDER BY id LIMIT 1',
    array($yearId)
)->fetchColumn();
if ($snapForPage) {
    book_page_create($layoutId, 2, 'snapshot', (int) $snapForPage);
}

/* A TEXT PAGE CARRYING THE QUOTE, so the Book tab renders a text slot at all.
   Without one, everything below about how a quote is drawn was checking HTML
   that the fixture never produced — which is how a preview that cut its quotes
   at 90 characters stayed green. */
$quoteForPage = q(
    'SELECT id FROM quotes WHERE year_project_id = ? ORDER BY id LIMIT 1',
    array($yearId)
)->fetchColumn();
if ($quoteForPage) {
    $textPageId = book_page_create($layoutId, 3, 'text');
    book_page_slot_create($textPageId, 1, array('quote_id' => (int) $quoteForPage));
}

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

/* ------------------------------------------------------- grid really is a grid */

echo "\nGrid vs Timeline...\n";

/* THE REGRESSION THIS PINS DOWN. "Grid" used to mean a grid only for photos;
 * every other type fell through to a stack of full-width rows, which is a
 * timeline with the month headings taken off. It was survivable while the
 * default type was Photos, and became the first thing on the screen the moment
 * the default became All — reported, accurately, as "the Grid view is showing
 * a timeline view".
 *
 * Checking the containers rather than the styling: a grid is .photo-grid, a
 * timeline is #entry-list with month headings, and the two must not swap. */
foreach (array('all', 'photo', 'snapshot', 'quote', 'anecdote') as $t) {
    $out = render_in_child('content', 'year', array('view' => 'grid', 'type' => $t));

    check(
        'grid type=' . $t . ' renders a grid',
        str_contains($out['html'], 'class="photo-grid"'),
        (string) $out['fatal']
    );
    check(
        'grid type=' . $t . ' is not a stacked list',
        !str_contains($out['html'], 'id="entry-list"')
    );
}

/* Every type produces a real cell, not an empty grid. The fixture has one of
   each, so a type that silently rendered nothing would pass the container
   check above and fail here. */
$out = render_in_child('content', 'year', array('view' => 'grid', 'type' => 'all'));
check('the grid holds a photo cell', str_contains($out['html'], 'photo-cell-details'));
check('the grid holds text cells', str_contains($out['html'], 'text-cell-details'));

/* THE HERO PREVIEW. The form used to print `Hero photo: #37` — an id, which
   says a photo is attached and nothing about which one. It must now show the
   picture ON LOAD, which is the half that needed a join: the client already had
   the photo row after a fresh pick, but snapshots_for_year() was a plain
   SELECT * and had only the id. */
$out = render_in_child('content', 'year', array('view' => 'timeline', 'type' => 'snapshot'));
check('the snapshot form shows its hero photo, not its id',
    str_contains($out['html'], 'hero-preview-img') && str_contains($out['html'], 'thumb/fixture.jpg'));
check('...and no longer prints the id as text',
    !str_contains($out['html'], 'Hero photo: #'));
check('...with a way to take it off again',
    str_contains($out['html'], 'data-act="clear-hero"'));

/* And the timeline is still the timeline — the fix must not have turned every
   view into a grid. */
$out = render_in_child('content', 'year', array('view' => 'timeline', 'type' => 'all'));
check('timeline stacks its entries', str_contains($out['html'], 'id="entry-list"'));
check('timeline groups by month', str_contains($out['html'], 'cat-head'));
check('timeline is not a grid', !str_contains($out['html'], 'class="photo-grid"'));

/* --------------------------------------------- the Content tab stays content */

echo "\nContent tab carries no book controls...\n";

/* The subtitle field and the "Create Book" link were duplicates once the two
 * screens became two tabs: the subtitle is edited on the Book tab, on the card
 * showing the cover it prints on, and "Create Book" pointed at a screen that is
 * one tap away in the tab strip. Removing them took #subtitle-list with them —
 * which two unrelated features were reading the project id out of, so that is
 * what the last two checks are really about. */
$out = render_in_child('content', 'year', array('view' => 'grid', 'type' => 'all'));
check('no subtitle field', !str_contains($out['html'], 'id="subtitle-list"'));
check('no Create Book link', !str_contains($out['html'], 'create-book-btn'));
check('no clear-subtitle button', !str_contains($out['html'], 'clear-subtitle'));

$groups = render_in_child('content', 'year', array('view' => 'grid', 'type' => 'groups'));
check('the Groups type still offers "Group photos"', str_contains($groups['html'], 'id="run-grouping-btn"'));
check('the Groups type still offers "New group"', str_contains($groups['html'], 'id="new-group-form"'));

/* Both of those post a year_project_id that review.js now reads off the body.
   public/project.php is what puts it there, so if that attribute ever stops
   being rendered, creating a group silently posts project 0. */
$projectPhp = file_get_contents(APP_ROOT . '/public/project.php');
check(
    'project.php still puts the project id on the body',
    str_contains($projectPhp, "'data-year-project-id' => $projectId")
);

/* ------------------------------------------------- one form, not five copies */

echo "\nOne edit form per type...\n";

/* render_entry_photo() and render_photo_cell() used to carry their own
   transcription of the same four fields. Adding grid cells for the other three
   types would have made that four duplications, so the form was factored out
   first — and this is what stops it drifting back apart: the row shape and the
   cell shape of one entry must contain the same form. */
$grid     = render_in_child('content', 'year', array('view' => 'grid', 'type' => 'quote'));
$timeline = render_in_child('content', 'year', array('view' => 'timeline', 'type' => 'quote'));

foreach (array('name="quote_text"', 'name="who_said_it"', 'name="entry_date"', 'data-act="delete"') as $field) {
    check(
        'both shapes of a quote carry ' . $field,
        str_contains($grid['html'], $field) && str_contains($timeline['html'], $field)
    );
}

/* ------------------------------------------------- the printed pages */

echo "\nPrinted pages...\n";

$book = render_in_child('book', 'year', array('tab' => 'book'));

check('the book tab renders', $book['fatal'] === null, (string) $book['fatal']);
/* THE PREVIEW SHOWS THE WHOLE QUOTE. It used to cut a slot's quote at 90
   characters and add an ellipsis, while the PDF printed all of it — so the one
   screen whose job is judging what will print was showing a page that never
   would. Reported as "the quote is getting cut off". */
/* THE ✕ THAT TAKES A PHOTO OUT OF THE BOOK, and the text card that links to
   its own detail page. Both are Book-tab controls added in Round 10; both are
   checked here because a control that does not render is a feature that does
   not exist. */
check('every photo on a page offers a way out of the book',
    substr_count($book['html'], 'data-act="drop-photo"') === substr_count($book['html'], 'data-act="adjust-crop"'),
    substr_count($book['html'], 'data-act="drop-photo"') . ' drop vs '
    . substr_count($book['html'], 'data-act="adjust-crop"') . ' crop controls');

check('a text card links to its own entry',
    (bool) preg_match('/<a class="ks-slot ks-slot-text[^"]*"\s+href="[^"]*#entry-(quote|anecdote)-\d+"/', $book['html']),
    'no entry link found on a text slot');
check('...to the Content tab, where the detail form actually lives',
    (bool) preg_match('/href="[^"]*tab=content[^"]*#entry-/', $book['html'])
    || (bool) preg_match('/href="project\.php\?id=\d+[^"]*#entry-/', $book['html']));

check('a slot quote is not truncated in the preview',
    str_contains($book['html'], 'I would like to take it home now'));
check('...and carries no ellipsis of its own',
    !str_contains($book['html'], 'home now&hellip;') && !str_contains($book['html'], 'puffin, it is the biggest puffin anyone has ever seen and I would like…'));

check('a snapshot page draws its title', str_contains($book['html'], "Emma&#039;s 8th Birthday"));
check('and its section headings', str_contains($book['html'], 'ks-section-heading'));
check('and its section bodies', str_contains($book['html'], 'Cake was chocolate.'));
check('the hero and the text are two cells', str_contains($book['html'], 'ks-snapshot-hero-cell'));

/* THE TYPE LABELS MUST NOT PRINT. Kathryn: "I don't want the type of content
 * shown on the page". The words still appear in the page's TOOLBAR pill, which
 * is screen chrome outside the drawn page — so the check is on the drawn page
 * markup, not on the whole document. */
$drawn = '';
if (preg_match_all('/<div class="ks-snapshot".*?<\/div>\s*<\/div>\s*<\/div>/s', $book['html'], $m)) {
    $drawn = implode("\n", $m[0]);
}
check('no "Birthday" label on the drawn snapshot page', !str_contains($drawn, '>Birthday<'));
check('no "School year" label either', !str_contains($drawn, '>School year<'));

/* The toolbar pill is the one that stays — it is how pages are told apart
   while being dragged into a new order. */
check('the page toolbar still says what kind of page it is',
    str_contains($book['html'], '<span class="pill is-plain">snapshot</span>')
    || str_contains($book['html'], '>snapshot<'));

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
