<?php
/**
 * ONE-TIME MIGRATION: snapshots' nine fixed columns become sections.
 *
 * Run this ONCE against the live database, after uploading this round's code
 * and before using the app. It is safe to run again — see "RE-RUNNABLE" below.
 *
 *   php tools/migrate-snapshot-sections.php --dry-run   (show, change nothing)
 *   php tools/migrate-snapshot-sections.php             (do it)
 *
 * On Hostinger there is no shell, so the SQL this generates can also be run by
 * hand — --dry-run prints exactly what it would do.
 *
 * WHAT IT MOVES. Every snapshot's populated columns become snapshot_sections
 * rows, in the order the old page showed them:
 *
 *   birthday     age -> "Age", height -> "Height"
 *   school_year  grade -> "Grade", school -> "School", teacher -> "Teacher",
 *                favorite_color -> "Favorite color", dream_job -> "Dream job",
 *                favorite_class -> "Favorite class"
 *   both         notes -> a section with a BODY AND NO HEADING, which is
 *                exactly what freeform notes were and how the new page prints
 *                them.
 *
 * Empty and NULL columns are skipped rather than becoming empty sections.
 *
 * WHY A SCRIPT AND NOT SQL IN DEPLOY.txt. The column-to-heading mapping is a
 * per-type list with an ordering, and a snapshot's sections have to be
 * numbered 1..n contiguously. That is a loop, and a loop written as SQL in a
 * text file is a loop nobody can test. This one runs against the SQLite test
 * harness in tools/verify-snapshot-migration.php.
 *
 * RE-RUNNABLE. It skips any snapshot that already has sections, so a run that
 * died halfway can simply be run again — the snapshots it got to are left
 * alone and the rest are picked up. It never deletes a section.
 *
 * IT DOES NOT DROP THE OLD COLUMNS. Dropping them is the last step and it is
 * in DEPLOY.txt as a separate statement, to be run once this has reported what
 * it moved and the pages look right. A migration that destroys its own source
 * data in the same breath cannot be checked afterwards.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* SPLIT INTO A LIBRARY HALF AND A CLI HALF, with the run guarded below on
 * being the script that was actually invoked.
 *
 * tools/verify-snapshot-migration.php includes this file to rehearse the
 * migration against the harness database. Without the guard, including it
 * would run the real thing on import — and a test that has to strip the
 * runnable part out of the file it is testing (which is what the first
 * attempt at this did, with a regex and an eval) is testing a rewrite of the
 * script rather than the script. */

/**
 * Old column -> section heading, per template, in the order the old page
 * listed them. `notes` is handled separately: it had no heading and gets none.
 *
 * Public so tools/verify-snapshot-migration.php exercises the same map the
 * real run uses rather than a copy of it.
 */
const MIGRATION_COLUMNS = array(
    'birthday' => array(
        'age'    => 'Age',
        'height' => 'Height',
    ),
    'school_year' => array(
        'grade'          => 'Grade',
        'school'         => 'School',
        'teacher'        => 'Teacher',
        'favorite_color' => 'Favorite color',
        'dream_job'      => 'Dream job',
        'favorite_class' => 'Favorite class',
    ),
);

/**
 * The sections one old snapshot row becomes.
 *
 * Pure — takes a row, returns a list — so the test can check the mapping
 * without a database in the middle.
 *
 * @param array $row A snapshots row as it was BEFORE this round: type, the
 *              nine optional columns, notes.
 * @return list<array{heading:?string, body:string}>
 */
function migration_sections_for(array $row): array
{
    $out  = array();
    $type = (string) ($row['type'] ?? 'birthday');

    foreach (MIGRATION_COLUMNS[$type] ?? array() as $column => $heading) {
        $value = $row[$column] ?? null;
        if ($value === null || trim((string) $value) === '') {
            continue;
        }
        $out[] = array('heading' => $heading, 'body' => trim((string) $value));
    }

    /* Notes last, and headingless. It was the freeform paragraph under the
       facts, and a section with a body and no heading prints exactly that. */
    $notes = $row['notes'] ?? null;
    if ($notes !== null && trim((string) $notes) !== '') {
        $out[] = array('heading' => null, 'body' => trim((string) $notes));
    }

    return $out;
}

/* ------------------------------------------------------------------- run */

/**
 * Do the migration (or describe it).
 *
 * @return array{moved:int, skipped:int, sections:int}
 */
function migration_run(bool $dryRun = false): array
{
/* SELECT * rather than naming the old columns: this file has to keep running
   after they are dropped, and asking for a column that no longer exists is a
   fatal error rather than a no-op. Whatever is there is what gets read. */
$snapshots = q('SELECT * FROM snapshots ORDER BY id')->fetchAll();

if ($snapshots === array()) {
    echo "No snapshots to migrate.\n";
    return array('moved' => 0, 'skipped' => 0, 'sections' => 0);
}

$moved = 0;
$skipped = 0;
$sectionsWritten = 0;

foreach ($snapshots as $row) {
    $id = (int) $row['id'];

    $existing = (int) q(
        'SELECT COUNT(*) FROM snapshot_sections WHERE snapshot_id = ?',
        array($id)
    )->fetchColumn();

    if ($existing > 0) {
        $skipped++;
        printf("  #%-4d skipped — already has %d section(s)\n", $id, $existing);
        continue;
    }

    $sections = migration_sections_for($row);
    if ($sections === array()) {
        $skipped++;
        printf("  #%-4d skipped — nothing to move\n", $id);
        continue;
    }

    printf("  #%-4d %s -> %d section(s): %s\n",
        $id,
        (string) $row['type'],
        count($sections),
        implode(', ', array_map(
            static fn(array $s): string => $s['heading'] ?? '(no heading)',
            $sections
        ))
    );

    if ($dryRun) {
        $moved++;
        $sectionsWritten += count($sections);
        continue;
    }

    $order = 0;
    foreach ($sections as $section) {
        $order++;
        q(
            'INSERT INTO snapshot_sections (snapshot_id, sort_order, heading, body)
             VALUES (?, ?, ?, ?)',
            array($id, $order, $section['heading'], $section['body'])
        );
        $sectionsWritten++;
    }
    $moved++;
}

echo "\n";
printf(
    "%s%d snapshot(s) migrated, %d skipped, %d section(s) %s.\n",
    $dryRun ? 'DRY RUN — nothing was written. ' : '',
    $moved,
    $skipped,
    $sectionsWritten,
    $dryRun ? 'would be created' : 'created'
);

if (!$dryRun && $moved > 0) {
    echo "\nCheck a snapshot page in the app before running the DROP COLUMN\n"
       . "statement in DEPLOY.txt — the old columns are still there until you do.\n";
}

    return array('moved' => $moved, 'skipped' => $skipped, 'sections' => $sectionsWritten);
}

/* ------------------------------------------------------------------- CLI */

/* Only when this file IS the script being run — see the note at the top. */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    require_once __DIR__ . '/../lib/bootstrap.php';
    require_once __DIR__ . '/../lib/repo.php';

    migration_run(in_array('--dry-run', $argv ?? array(), true));
}
