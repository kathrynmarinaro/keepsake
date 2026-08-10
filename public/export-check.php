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

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$project = year_project_get_by_year($year);

if ($project === null) {
    printf("No year project for %d. Add ?year=YYYY to the URL for a different year.\n", $year);
    exit;
}

printf("Year %d, project #%d\n", $year, (int) $project['id']);

$layoutId = $project['active_book_layout_id'];
if ($layoutId === null) {
    print "No active book layout — generate one on the Layout screen first.\n";
    exit;
}

$pages = book_pages_for_layout((int) $layoutId);
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
