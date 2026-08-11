<?php
/**
 * The snapshot migration moves the right things to the right places.
 *
 * WHY THIS EXISTS. tools/migrate-snapshot-sections.php runs ONCE, against
 * Kathryn's real database, over rows this build environment has never seen —
 * and it is the only thing standing between nine columns of typed-in facts and
 * an empty page. A migration nobody can rehearse is a migration you find out
 * about afterwards.
 *
 * So this builds the OLD table shape in the harness database, fills it with
 * rows of the kind the live database actually holds, and checks both halves:
 * the pure column-to-heading mapping, and the run itself — ordering, the
 * skip-if-already-migrated rule that makes a re-run safe, and the fact that it
 * never touches the source columns.
 *
 * THE OLD SHAPE IS BUILT HERE, not read from schema.sql, because schema.sql is
 * the NEW shape — those columns are gone from it. This file is the only
 * remaining description of what is being migrated FROM, which is the honest
 * place for it: when the live database has been migrated and the columns
 * dropped, this file and the script it tests are both dead and can go together.
 *
 * Usage: php tools/verify-snapshot-migration.php
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

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures++; }
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
    if (!$ok && $detail !== '') { echo '         ' . $detail . "\n"; }
}

harness_pdo();

/* ------------------------------------------------- the mapping, on its own */

echo "\nmigration_sections_for()...\n";

/* Loaded for its pure function and its constant. The script's own run block
 * executes on include, so it is given a database with no snapshots in it yet
 * and prints "No snapshots to migrate" — which is why this happens BEFORE
 * anything is seeded below. */
require __DIR__ . '/migrate-snapshot-sections.php';

$birthday = migration_sections_for(array(
    'type' => 'birthday', 'age' => 8, 'height' => "3'9\"",
    'grade' => 'IGNORED', 'notes' => 'Cake was chocolate.',
));
check('a birthday maps age then height', count($birthday) === 3);
check('in the order the old page showed them',
    $birthday[0]['heading'] === 'Age' && $birthday[1]['heading'] === 'Height');
check('the values come across', $birthday[0]['body'] === '8' && $birthday[1]['body'] === "3'9\"");
check('notes become a section with no heading',
    $birthday[2]['heading'] === null && $birthday[2]['body'] === 'Cake was chocolate.');
check('the OTHER template\'s columns are ignored',
    !in_array('Grade', array_column($birthday, 'heading'), true));

$school = migration_sections_for(array(
    'type' => 'school_year', 'grade' => '2nd', 'school' => 'Forest North Elementary',
    'teacher' => 'Ms. Devore', 'favorite_color' => 'Turquoise',
    'dream_job' => null, 'favorite_class' => '', 'notes' => null,
));
check('a school year maps its six columns', count($school) === 4);
check('in order', array_column($school, 'heading')
    === array('Grade', 'School', 'Teacher', 'Favorite color'));
check('NULL and empty columns are skipped, not made blank sections',
    !in_array('Dream job', array_column($school, 'heading'), true)
    && !in_array('Favorite class', array_column($school, 'heading'), true));

check('a row with nothing in it produces nothing',
    migration_sections_for(array('type' => 'birthday')) === array());

/* ------------------------------------------------------ the run, end to end */

echo "\nthe migration run...\n";

/* The OLD snapshots table, as the live database still has it. The harness
   built the NEW one from schema.sql, so it is dropped and rebuilt here. */
db()->exec('DROP TABLE IF EXISTS snapshots');
db()->exec(
    'CREATE TABLE snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year_project_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        entry_date TEXT NOT NULL,
        hero_photo_id INTEGER NULL,
        age INTEGER NULL,
        height TEXT NULL,
        grade TEXT NULL,
        school TEXT NULL,
        teacher TEXT NULL,
        favorite_color TEXT NULL,
        dream_job TEXT NULL,
        favorite_class TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

$projectId = year_project_create(2025);

q("INSERT INTO snapshots (year_project_id, type, entry_date, age, height, notes)
   VALUES (?, 'birthday', '2025-04-15', 8, '3''9\"', 'Cake was chocolate.')", array($projectId));
$emmaId = (int) db()->lastInsertId();

q("INSERT INTO snapshots (year_project_id, type, entry_date, grade, school, teacher, favorite_color)
   VALUES (?, 'school_year', '2025-05-22', '2nd', 'Forest North Elementary', 'Ms. Devore', 'Turquoise')", array($projectId));
$schoolId = (int) db()->lastInsertId();

/* A row with nothing to move — it must be left alone rather than given an
   empty section. */
q("INSERT INTO snapshots (year_project_id, type, entry_date)
   VALUES (?, 'birthday', '2025-01-01')", array($projectId));
$emptyId = (int) db()->lastInsertId();

/** The script's own migration_run(), with its output swallowed. */
function run_migration(bool $dryRun = false): array
{
    ob_start();
    $result = migration_run($dryRun);
    $GLOBALS['migration_output'] = (string) ob_get_clean();
    return $result;
}

$dry = run_migration(true);
check('a dry run writes nothing',
    (int) q('SELECT COUNT(*) FROM snapshot_sections')->fetchColumn() === 0);
check('and says so', str_contains($GLOBALS['migration_output'], 'DRY RUN'));
check('but still reports what it would move', $dry['moved'] === 2 && $dry['sections'] === 7);

run_migration();

$emmaSections = snapshot_sections($emmaId);
check('the birthday got its sections', count($emmaSections) === 3);
check('headings in order',
    array_map(static fn($r): ?string => $r['heading'], $emmaSections)
        === array('Age', 'Height', null));
check('sort_order is 1..3', array_map(static fn($r): int => (int) $r['sort_order'], $emmaSections) === array(1, 2, 3));

$schoolSections = snapshot_sections($schoolId);
check('the school year got its four populated columns', count($schoolSections) === 4);
check('and not its empty ones',
    !in_array('Dream job', array_map(static fn($r): ?string => $r['heading'], $schoolSections), true));

check('a snapshot with nothing to move gets no sections', snapshot_sections($emptyId) === array());

/* The source columns are untouched, which is what makes the DROP a separate,
   deliberate second step. */
$emmaRow = q('SELECT * FROM snapshots WHERE id = ?', array($emmaId))->fetch();
check('the old columns still hold their values', (int) $emmaRow['age'] === 8);

/* ------------------------------------------------------------- re-running */

echo "\nrunning it twice...\n";

$before = q('SELECT * FROM snapshot_sections ORDER BY id')->fetchAll();
run_migration();
$after = q('SELECT * FROM snapshot_sections ORDER BY id')->fetchAll();

check('a second run changes nothing', $before === $after);
check('and does not duplicate sections', count($after) === count($before));

/* A snapshot edited between runs keeps the edit — the skip rule is "has any
   sections", so a re-run must not overwrite work done since. */
snapshot_sections_replace($emmaId, array(array('heading' => 'Age', 'body' => '9')));
run_migration();
$edited = snapshot_sections($emmaId);
check('an edit made after migrating survives a re-run',
    count($edited) === 1 && $edited[0]['body'] === '9');

echo "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " CHECK(S) FAILED.\n");
exit($failures === 0 ? 0 : 1);
