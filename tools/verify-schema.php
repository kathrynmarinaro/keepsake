<?php
/**
 * Schema smoke test: loads schema.sql through tools/test-harness.php and
 * verifies year isolation with a real (if throwaway) insert — not a
 * permanent test suite, just something worth keeping now that it exists.
 *
 * WHAT THIS CHECKS:
 *   1. schema.sql applies cleanly (harness_pdo() throws otherwise).
 *   2. Two year_projects, each with its own photo/quote/anecdote/event_group/
 *      snapshot/book_layout, and a query scoped to year A's id never returns
 *      year B's rows, for every table that carries year_project_id directly.
 *   3. The tables that DERIVE their year instead of storing it (book_pages/
 *      book_page_photos, via book_layout_id) resolve to the right year
 *      through the join, so the derivation this schema leans on instead of
 *      a column actually holds.
 *
 * Usage:
 *   php tools/verify-schema.php
 *
 * Exits 0 and prints "ALL CHECKS PASSED" on success, exits 1 with the first
 * failure otherwise.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/test-harness.php';

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

echo "Loading schema.sql into an in-memory SQLite database...\n";
$pdo = harness_pdo();
echo "Schema applied cleanly.\n\n";

echo "Seeding two year_projects with one of everything each...\n";

// ---- Year A: 2024 -------------------------------------------------------
$pdo->exec("INSERT INTO year_projects (year, subtitle) VALUES (2024, 'The year of the move')");
$yearA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO event_groups (year_project_id, name, start_date, end_date)
            VALUES ($yearA, 'Move-in weekend', '2024-06-01', '2024-06-02')");
$eventA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO photos (year_project_id, event_group_id, original_path, captured_at)
            VALUES ($yearA, $eventA, 'uploads/original/a1.jpg', '2024-06-01 09:00:00')");
$photoA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO quotes (year_project_id, quote_text, who_said_it, entry_date)
            VALUES ($yearA, 'This is our new kitchen', 'Emma', '2024-06-01')");
$quoteA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO anecdotes (year_project_id, anecdote_text, entry_date)
            VALUES ($yearA, 'Emma named the new house Sunflower House', '2024-06-01')");
$anecdoteA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO snapshots (year_project_id, type, entry_date, age, height)
            VALUES ($yearA, 'birthday', '2024-04-15', 6, '3ft 9in')");
$snapshotA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_layouts (year_project_id, version) VALUES ($yearA, 1)");
$layoutA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_pages (book_layout_id, page_number, page_type) VALUES ($layoutA, 1, 'photos')");
$pageA = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_page_photos (book_page_id, slot_number, photo_id) VALUES ($pageA, 1, $photoA)");

// ---- Year B: 2025 --------------------------------------------------------
$pdo->exec("INSERT INTO year_projects (year, subtitle) VALUES (2025, 'The year of the garden')");
$yearB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO event_groups (year_project_id, name, start_date, end_date)
            VALUES ($yearB, 'First day of school', '2025-08-25', '2025-08-25')");
$eventB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO photos (year_project_id, event_group_id, original_path, captured_at)
            VALUES ($yearB, $eventB, 'uploads/original/b1.jpg', '2025-08-25 08:00:00')");
$photoB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO quotes (year_project_id, quote_text, who_said_it, entry_date)
            VALUES ($yearB, 'I am going to be a marine biologist', 'Emma', '2025-08-25')");
$quoteB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO anecdotes (year_project_id, anecdote_text, entry_date)
            VALUES ($yearB, 'Planted three tomato seedlings, ate zero tomatoes', '2025-08-25')");
$anecdoteB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO snapshots (year_project_id, type, entry_date, grade, school)
            VALUES ($yearB, 'school_year', '2025-08-25', '1st grade', 'Lincoln Elementary')");
$snapshotB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_layouts (year_project_id, version) VALUES ($yearB, 1)");
$layoutB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_pages (book_layout_id, page_number, page_type) VALUES ($layoutB, 1, 'photos')");
$pageB = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO book_page_photos (book_page_id, slot_number, photo_id) VALUES ($pageB, 1, $photoB)");

echo "Seeded. Year A (2024) id=$yearA, Year B (2025) id=$yearB.\n\n";

echo "Checking tables with a direct year_project_id column...\n";

foreach (array('event_groups', 'photos', 'quotes', 'anecdotes', 'snapshots', 'book_layouts') as $table) {
    $rowsA = $pdo->query("SELECT id FROM $table WHERE year_project_id = $yearA")->fetchAll(PDO::FETCH_COLUMN);
    $rowsB = $pdo->query("SELECT id FROM $table WHERE year_project_id = $yearB")->fetchAll(PDO::FETCH_COLUMN);

    check("$table: year A query returns exactly its own row", count($rowsA) === 1);
    check("$table: year B query returns exactly its own row", count($rowsB) === 1);
    check("$table: year A's row never appears in year B's result", array_intersect($rowsA, $rowsB) === array());
}

echo "\nChecking tables that DERIVE year_project_id instead of storing it...\n";

// book_pages / book_page_photos derive through book_layout_id.
$pagesA = $pdo->query(
    "SELECT bp.id FROM book_pages bp
       JOIN book_layouts bl ON bl.id = bp.book_layout_id
      WHERE bl.year_project_id = $yearA"
)->fetchAll(PDO::FETCH_COLUMN);
$pagesB = $pdo->query(
    "SELECT bp.id FROM book_pages bp
       JOIN book_layouts bl ON bl.id = bp.book_layout_id
      WHERE bl.year_project_id = $yearB"
)->fetchAll(PDO::FETCH_COLUMN);
check('book_pages: year A has exactly its own page via the layout join', count($pagesA) === 1);
check('book_pages: year B has exactly its own page via the layout join', count($pagesB) === 1);
check('book_pages: no overlap between A and B', array_intersect($pagesA, $pagesB) === array());

$slotsA = $pdo->query(
    "SELECT bpp.id FROM book_page_photos bpp
       JOIN book_pages bp ON bp.id = bpp.book_page_id
       JOIN book_layouts bl ON bl.id = bp.book_layout_id
      WHERE bl.year_project_id = $yearA"
)->fetchAll(PDO::FETCH_COLUMN);
$slotsB = $pdo->query(
    "SELECT bpp.id FROM book_page_photos bpp
       JOIN book_pages bp ON bp.id = bpp.book_page_id
       JOIN book_layouts bl ON bl.id = bp.book_layout_id
      WHERE bl.year_project_id = $yearB"
)->fetchAll(PDO::FETCH_COLUMN);
check('book_page_photos: year A has exactly its own slot via the two-level join', count($slotsA) === 1);
check('book_page_photos: year B has exactly its own slot via the two-level join', count($slotsB) === 1);
check('book_page_photos: no overlap between A and B', array_intersect($slotsA, $slotsB) === array());

echo "\nChecking that deleting a year_project cascades within its own year and never touches the other...\n";

$pdo->exec("DELETE FROM year_projects WHERE id = $yearA");

check('year A photo is gone (cascade)', (int) $pdo->query("SELECT COUNT(*) FROM photos WHERE id = $photoA")->fetchColumn() === 0);
check('year A quote is gone (cascade)', (int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE id = $quoteA")->fetchColumn() === 0);
check('year A book_layout is gone (cascade)', (int) $pdo->query("SELECT COUNT(*) FROM book_layouts WHERE id = $layoutA")->fetchColumn() === 0);
check('year A book_page is gone (cascade, two levels deep)', (int) $pdo->query("SELECT COUNT(*) FROM book_pages WHERE id = $pageA")->fetchColumn() === 0);
check('year A book_page_photos slot is gone (cascade, three levels deep)', (int) $pdo->query("SELECT COUNT(*) FROM book_page_photos WHERE book_page_id = $pageA")->fetchColumn() === 0);

check('year B photo is UNTOUCHED', (int) $pdo->query("SELECT COUNT(*) FROM photos WHERE id = $photoB")->fetchColumn() === 1);
check('year B quote is UNTOUCHED', (int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE id = $quoteB")->fetchColumn() === 1);
check('year B book_layout is UNTOUCHED', (int) $pdo->query("SELECT COUNT(*) FROM book_layouts WHERE id = $layoutB")->fetchColumn() === 1);
check('year B book_page is UNTOUCHED', (int) $pdo->query("SELECT COUNT(*) FROM book_pages WHERE id = $pageB")->fetchColumn() === 1);
check('year B book_page_photos slot is UNTOUCHED', (int) $pdo->query("SELECT COUNT(*) FROM book_page_photos WHERE book_page_id = $pageB")->fetchColumn() === 1);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "$failures CHECK(S) FAILED\n");
    exit(1);
}

echo "ALL CHECKS PASSED\n";
