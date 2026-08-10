<?php
/* TEMPORARY DIAGNOSTIC — delete this file once the PDF export is working.
 *
 * public/api/export.php catches every failure and answers the browser with a
 * deliberately vague "Could not build the PDF", sending the real message to the
 * PHP error log. That is the right behaviour for an endpoint the browser calls,
 * and useless when the host has no error log to read — which is where Kathryn
 * ended up.
 *
 * So this runs the exact same build, behind the same login, and prints what
 * actually happened as plain text: the real exception with its file and line,
 * or — if it succeeds — what the export cost against the limits the server
 * imposes, which is the other half of the diagnosis. A build that works while
 * sitting at 95% of the memory limit is a build that will fail on a bigger year.
 *
 * SAFE TO UPLOAD, BUT NOT TO LEAVE. It is login-gated exactly like every other
 * page, and it writes nothing and changes nothing. But it does print internal
 * paths and error detail, which is not something to leave reachable once the
 * question is answered. Delete it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/pdfexport.php';

require_login_page();

header('Content-Type: text/plain; charset=utf-8');

/* If the build dies in a way try/catch cannot see — a fatal, a memory ceiling,
 * the process being killed — the page would otherwise just stop mid-sentence
 * and tell us nothing. This makes the last words useful. */
register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last !== null && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        printf("\n\nFATAL — the script was stopped, not an exception it could catch:\n\n");
        printf("  %s\n  at %s line %d\n", $last['message'], $last['file'], $last['line']);
        printf("\n  peak memory %.0f MB\n", memory_get_peak_usage(true) / 1048576);
        print "\nSend me these lines.\n";
    }
});

/* The ceilings first, so they are on the page whatever happens below —
 * including if the build dies hard enough to take the script with it. */
$memLimit = ini_get('memory_limit');
$timeCap  = ini_get('max_execution_time');

printf("PHP %s\n", PHP_VERSION);
printf("memory_limit          %s\n", $memLimit === false ? '(unknown)' : $memLimit);
printf("max_execution_time    %s\n", $timeCap === false ? '(unknown)' : $timeCap . 's');
printf("Imagick available     %s\n", class_exists('Imagick') ? 'yes' : 'no (GD is used instead)');
printf("GD available          %s\n", function_exists('imagecreatetruecolor') ? 'yes' : 'NO — this would break cropping');
print str_repeat('-', 60) . "\n";

/* Pick the year to test. An explicit ?year= wins; otherwise take the newest
 * year that actually HAS an active layout, rather than assuming the current
 * calendar year — the book being worked on is usually last year's, and
 * defaulting to today's just reports "nothing to export" about a year nobody
 * asked about. */
$all = year_project_list();

print "Years in the app:\n";
foreach ($all as $row) {
    printf("  %d  project #%-3d %s\n", (int) $row['year'], (int) $row['id'],
        $row['active_book_layout_id'] !== null
            ? 'active layout #' . (int) $row['active_book_layout_id']
            : '(no active layout)');
}
print "\n";

$project = null;
if (isset($_GET['year'])) {
    $project = year_project_get_by_year((int) $_GET['year']);
    if ($project === null) {
        printf("No year project for %d.\n", (int) $_GET['year']);
        exit;
    }
} else {
    foreach ($all as $row) {
        if ($row['active_book_layout_id'] !== null) { $project = $row; break; }
    }
}

if ($project === null) {
    print "No year has an active book layout yet — generate one on the Layout screen.\n";
    exit;
}

$year = (int) $project['year'];
printf("Testing year %d, project #%d\n", $year, (int) $project['id']);

$layoutId = $project['active_book_layout_id'];
if ($layoutId === null) {
    printf("Year %d has no active book layout. Add ?year=YYYY to test another.\n", $year);
    exit;
}

/* book_layout_pages_with_content(), NOT book_pages_for_layout().
 *
 * The difference caused a false alarm worth not repeating: the latter selects
 * the slot rows only, with no join to photos, so original_path reads as empty
 * for every slot and this page cheerfully reported all 123 photos missing from
 * disk. The export uses this loader, so the diagnostic has to as well —
 * checking a different query than the one under investigation is how you
 * diagnose a problem that does not exist. */
$pages = book_layout_pages_with_content((int) $layoutId);
printf("Active layout #%d, %d pages\n", (int) $layoutId, count($pages));

/* How many photos, and how big the originals are. This is what actually drives
 * both the time and the memory, so it is worth seeing even on success. */
$photoCount = 0;
$missing    = 0;
$biggest    = 0;
foreach ($pages as $page) {
    foreach ($page['slots'] as $slot) {
        if ($slot['photo_id'] === null) { continue; }
        $photoCount++;
        $abs = pdf_resolve_photo_file($slot);
        if ($abs === null) { $missing++; continue; }
        $biggest = max($biggest, (int) @filesize($abs));
    }
}
printf("Photos on pages       %d\n", $photoCount);
printf("Missing from disk     %d%s\n", $missing, $missing > 0 ? '  <-- these print as placeholders' : '');
printf("Largest original      %.1f MB\n", $biggest / 1048576);

/* When photos cannot be found, WHERE the app looked matters more than the fact
 * that it failed. The preview finds them fine because the browser fetches them
 * over the web; the PDF has to open them on disk, which is a different question
 * with a different answer. */
if ($missing > 0) {
    print "\n";
    print "Photos are missing on disk — where the app looked:\n\n";
    printf("  PUBLIC_DIR   %s   %s\n", PUBLIC_DIR, is_dir(PUBLIC_DIR) ? 'exists' : 'MISSING');
    printf("  UPLOAD_DIR   %s   %s\n", UPLOAD_DIR, is_dir(UPLOAD_DIR) ? 'exists' : 'MISSING');

    if (is_dir(UPLOAD_DIR)) {
        $entries = @scandir(UPLOAD_DIR) ?: array();
        $entries = array_values(array_diff($entries, array('.', '..')));
        printf("  uploads holds %d entries%s\n", count($entries),
            $entries === array() ? '  <-- empty' : ': ' . implode(', ', array_slice($entries, 0, 6))
                . (count($entries) > 6 ? ', ...' : ''));
    }

    print "\n  What the database says, for the first few photos:\n";
    $shown = 0;
    foreach ($pages as $page) {
        foreach ($page['slots'] as $slot) {
            if ($slot['photo_id'] === null || $shown >= 3) { continue; }
            $shown++;
            $orig  = (string) ($slot['original_path'] ?? '');
            $thumb = (string) ($slot['thumb_path'] ?? '');
            printf("\n    photo #%d\n", (int) $slot['photo_id']);
            printf("      original_path  %s\n", $orig === '' ? '(empty)' : $orig);
            printf("      thumb_path     %s\n", $thumb === '' ? '(empty)' : $thumb);
            printf("      looked for     %s\n", PUBLIC_DIR . '/' . $orig);
            printf("      is it there?   %s\n", is_file(PUBLIC_DIR . '/' . $orig) ? 'YES' : 'no');
            printf("      thumb there?   %s\n", is_file(PUBLIC_DIR . '/' . $thumb) ? 'YES' : 'no');
        }
    }
    print "\n";
}
print str_repeat('-', 60) . "\n";
print "Building the PDF...\n\n";

@ob_flush();
@flush();

$started = microtime(true);

try {
    $export  = pdf_export_build((int) $project['id']);
    $seconds = microtime(true) - $started;
    $peakMb  = memory_get_peak_usage(true) / 1048576;

    print "SUCCESS — the export itself works.\n\n";
    printf("  pages        %d\n", $export['page_count']);
    printf("  size         %.1f MB\n", strlen($export['bytes']) / 1048576);
    printf("  time taken   %.1f s%s\n", $seconds,
        (is_numeric($timeCap) && (int) $timeCap > 0 && $seconds > (int) $timeCap * 0.5)
            ? '   <-- over half the time limit, too close' : '');
    printf("  peak memory  %.0f MB%s\n", $peakMb,
        (preg_match('/^(\d+)M$/i', (string) $memLimit, $m) && $peakMb > (int) $m[1] * 0.8)
            ? '   <-- over 80% of the memory limit, too close' : '');
    print "\nIf this says SUCCESS but the Export button still fails, the difference\n";
    print "is that the button also sends the file to your browser. Say so and I\n";
    print "will look at the download path rather than the build.\n";
} catch (Throwable $e) {
    printf("FAILED after %.1f s, peak memory %.0f MB\n\n",
        microtime(true) - $started, memory_get_peak_usage(true) / 1048576);
    printf("  %s: %s\n", get_class($e), $e->getMessage());
    printf("  at %s line %d\n\n", $e->getFile(), $e->getLine());
    print "Where it came from:\n";
    print $e->getTraceAsString() . "\n";
    print "\nSend me the lines above.\n";
}

print "\n";
print "When you are done: DELETE this file (public/export-check.php).\n";
