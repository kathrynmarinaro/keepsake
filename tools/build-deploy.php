<?php
/* Assemble the FTP/file-manager bundle: the app, laid out the way it has to
 * sit on Hostinger, as a directory and a zip of that directory.
 *
 * Ported from Personal CRM's tools/build-deploy.php — same job, same
 * reasoning, adapted for what Keepsake actually ships:
 *
 *   1. The tree has to sit on the server the way the root .htaccess shim
 *      expects it to. Getting the public/ folder's name wrong doesn't error
 *      — asset() silently stops cache-busting and a deploy just appears not
 *      to have taken effect.
 *   2. Two .htaccess files (root, lib/, tools/) are the only thing keeping
 *      config.php, schema.sql and lib/ off the public internet if the
 *      document root can't be pointed at public/ directly, and every file
 *      manager hides dotfiles by default. This script REFUSES to produce a
 *      bundle missing one.
 *   3. config.php holds a live database password. It is gitignored, but a
 *      build that copies a directory tree would happily sweep it into a
 *      zip that then gets emailed or messaged around. It is excluded, and
 *      the exclusion is asserted.
 *   4. vendor/ (mPDF and its dependencies) is INCLUDED, unlike a typical
 *      Composer-based deploy — most shared-hosting plans have no SSH and
 *      no way to run `composer install` on the server, so the only way PDF
 *      export works there is if the vendored code is already in the zip.
 *      This is the one place Keepsake's bundle genuinely differs in kind
 *      from Personal CRM's (which vendors PHPMailer as plain files for the
 *      same underlying reason — no Composer on the host).
 *
 * Test files (tools/verify-*.php, tools/test-harness.php), docs/, PLAN.md,
 * keepsake-brief.md and README.md are left out — not to hide them, but
 * because every file uploaded is a file someone has to look at and decide
 * about later, and none of these run on the server. tools/make-hash.php is
 * the one tools/ script that DOES ship, because config setup depends on it.
 *
 * Usage:
 *   composer install --no-dev --optimize-autoloader
 *   php tools/build-deploy.php
 */

declare(strict_types=1);

/* Deliberately does NOT require lib/bootstrap.php, unlike every other
 * script in tools/. Bootstrap exits when config.php is absent — correct for
 * anything that touches the database, and exactly wrong here: packaging the
 * app for its first upload is the one job you do BEFORE there is a config
 * to read. */
define('APP_ROOT', dirname(__DIR__));

const BUNDLE_NAME = 'keepsake-deploy';

/* Copied as-is, relative to the app root. Order is only for the manifest. */
const INCLUDE_ROOT_FILES = array(
    '.htaccess',
    'config.example.php',
    'schema.sql',
    'composer.json',
    'composer.lock',
    'DEPLOY.txt',
);

/* Whole directories, copied recursively, minus SKIP_FILES/SKIP_PREFIXES
 * below. No uploads/ entry here, unlike Personal CRM's build script —
 * Keepsake's uploads/ lives INSIDE public/ (photos are served as plain
 * static files, not gated behind the login the way a CRM contact export
 * would be), so public/'s own copy below already carries it. */
const INCLUDE_DIRS = array('lib', 'tools', 'vendor');

/* Never leaves this machine. config.php is the dangerous one: real
 * credentials, gitignored, so it is exactly the file a naive tree copy
 * picks up and a git-based check misses. */
const SKIP_FILES = array(
    'config.php',
    'build-deploy.php',
    'test-harness.php',
    '.DS_Store',
);

/* tools/verify-*.php are dev-only SQLite smoke tests; none of them touch a
 * real database and none of them belong on a live server. */
const SKIP_PREFIXES = array('verify-');

/* Directory NAMES skipped at every depth, not just the top level — this is
 * what keeps vendor/ small. Composer's dist (zip) downloads skip these by
 * construction, but this build environment's proxy blocks the dist-zip
 * download path for GitHub-hosted packages (auth failure on the zip API,
 * while a plain git clone over the same host works fine), so composer
 * silently falls back to "install from source" — a full git clone,
 * .git and all, INSIDE vendor/. Kathryn's own machine may never hit this,
 * but the bundle must not depend on which download path Composer happened
 * to use, so this is filtered unconditionally rather than assumed away. A
 * package's tests/ directory is never needed at runtime either. */
const SKIP_DIR_NAMES = array('.git', '.github', 'tests', 'test', 'Tests');

/* Must all be present in the finished bundle or the build fails. Hiding
 * dotfiles is the file-manager default, so a missing one is invisible
 * until someone fetches /schema.sql or /config.php over HTTP and gets it. */
const REQUIRED_HTACCESS = array(
    '.htaccess',
    'lib/.htaccess',
    'tools/.htaccess',
);

function main(): void
{
    $root    = APP_ROOT;
    $out     = $root . '/' . BUNDLE_NAME;
    $zipPath = $root . '/' . BUNDLE_NAME . '.zip';

    if (!is_dir($root . '/vendor/mpdf')) {
        fail("vendor/mpdf is missing — run 'composer install --no-dev --optimize-autoloader' first");
    }

    /* A stale bundle is worse than none: it looks current and ships last
     * week's code. Rebuild from empty every time. */
    if (is_dir($out)) {
        rrmdir($out);
    }
    mkdir($out, 0755, true);

    $copied = array();

    foreach (INCLUDE_ROOT_FILES as $file) {
        if (!is_file($root . '/' . $file)) {
            fail($file . ' is missing from the repository');
        }
        copy_file($root . '/' . $file, $out . '/' . $file);
        $copied[] = $file;
    }

    foreach (INCLUDE_DIRS as $dir) {
        $copied = array_merge($copied, copy_tree($root . '/' . $dir, $out . '/' . $dir, $dir));
    }

    /* public/ KEEPS ITS NAME — the root .htaccess shim rewrites into it by
     * name, matching Personal CRM's own note on this exact pitfall. This is
     * also right for the better case (document root pointed straight at
     * .../keepsake/public): the shim just never runs, and the name is
     * irrelevant. Renaming only ever helps the layout this app doesn't use. */
    $copied = array_merge($copied, copy_tree($root . '/public', $out . '/public', 'public'));

    /* ---- assertions: a bundle that fails these must not ship ---- */

    foreach (REQUIRED_HTACCESS as $needed) {
        if (!is_file($out . '/' . $needed)) {
            fail('the bundle is missing ' . $needed . ' — that file is what keeps config.php/schema.sql off the web');
        }
    }

    if (is_file($out . '/config.php')) {
        fail('config.php reached the bundle — it holds your database password');
    }

    foreach (array('tools/test-harness.php') as $devFile) {
        if (is_file($out . '/' . $devFile)) {
            fail($devFile . ' reached the bundle');
        }
    }
    foreach (glob($out . '/tools/verify-*.php') ?: array() as $strayVerify) {
        fail(basename($strayVerify) . ' (a dev-only test script) reached the bundle');
    }

    if (!is_file($out . '/public/index.php')) {
        fail('public/index.php is missing');
    }
    if (!is_file($out . '/tools/make-hash.php')) {
        fail('tools/make-hash.php is missing — there would be no way to set the login password');
    }
    if (!is_file($out . '/vendor/autoload.php')) {
        fail('vendor/autoload.php is missing — PDF export cannot work without it and there is no Composer on most shared hosts');
    }

    /* Hard guarantee, not just trust in the SKIP_DIR_NAMES filter above: a
     * stray .git anywhere in the bundle is the kind of thing worth failing
     * loudly over rather than shipping and finding out later (a readable
     * .git/config over HTTP is a real credential-leak vector, not just
     * bytes wasted). find, not glob() — glob() doesn't recurse. */
    exec('find ' . escapeshellarg($out) . " -iname '.git' -o -iname '.github'", $strayGitDirs);
    if ($strayGitDirs !== array()) {
        fail('a .git/.github directory reached the bundle: ' . implode(', ', $strayGitDirs));
    }

    /* uploads/ is meant to be servable (it's inside public/), but it should
     * still be EMPTY in the bundle — anything already in it is a stray dev
     * artifact from testing, not something to ship to a fresh install. */
    foreach (glob($out . '/public/uploads/*') ?: array() as $stray) {
        if (basename($stray) !== '.gitkeep') {
            fail('a stray file reached the bundle: public/uploads/' . basename($stray));
        }
    }

    /* The bundle and the shim have to agree on the folder name — the last
     * time a sibling app got this wrong the whole site was a 404 with
     * nothing in any log to say why. */
    $shim = (string) file_get_contents($out . '/.htaccess');
    if (!str_contains($shim, 'public/$1')) {
        fail('the root .htaccess no longer rewrites into public/ — bundle layout and shim disagree');
    }

    /* ---- zip ---- */

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('could not create ' . $zipPath);
    }
    foreach ($copied as $relative) {
        $zip->addFile($out . '/' . $relative, $relative);
    }
    $zip->close();

    sort($copied);
    printf("Bundle:  %s/\n", BUNDLE_NAME);
    printf("Archive: %s.zip  (%s)\n", BUNDLE_NAME, human_size((int) filesize($zipPath)));
    printf("Files:   %d\n\n", count($copied));
    printf("All %d .htaccess files present. config.php excluded. vendor/ included (%d files).\n",
        count(REQUIRED_HTACCESS),
        count(array_filter($copied, static fn(string $f): bool => str_starts_with($f, 'vendor/')))
    );
}

/** Recursively copy $from to $to, returning the bundle-relative paths written. */
function copy_tree(string $from, string $to, string $prefix): array
{
    if (!is_dir($from)) {
        fail($from . ' is missing from the repository');
    }

    $written = array();
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }

    $entries = scandir($from);
    if ($entries === false) {
        fail('could not read ' . $from);
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || skipped($entry)) {
            continue;
        }

        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;

        if (is_dir($src)) {
            if (in_array($entry, SKIP_DIR_NAMES, true)) {
                continue;
            }
            $written = array_merge($written, copy_tree($src, $dst, $prefix . '/' . $entry));
            continue;
        }

        copy_file($src, $dst);
        $written[] = $prefix . '/' . $entry;
    }

    return $written;
}

function skipped(string $name): bool
{
    if (in_array($name, SKIP_FILES, true)) {
        return true;
    }
    foreach (SKIP_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }
    return false;
}

function copy_file(string $src, string $dst): void
{
    $dir = dirname($dst);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!copy($src, $dst)) {
        fail('could not copy ' . $src);
    }
}

function rrmdir(string $dir): void
{
    $entries = scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function human_size(int $bytes): string
{
    return $bytes < 1024 * 1024
        ? sprintf('%.0f KB', $bytes / 1024)
        : sprintf('%.1f MB', $bytes / 1024 / 1024);
}

function fail(string $why): void
{
    fwrite(STDERR, "build-deploy: " . $why . PHP_EOL);
    exit(1);
}

main();
