<?php
/* Shared bootstrap: config, requires, JSON helpers.
 * Every entry point (public/*.php, public/api/*.php, tools/*.php) starts by
 * requiring this file.
 *
 * Ported from Personal CRM, which ports it from Grocery, which ports it from
 * Book Tracker and the Workout Generator. Fixes to what is shared should land
 * in the siblings too.
 *
 * Deliberately does NOT carry Personal CRM's clock-pinning additions
 * (date_default_timezone_set() run at config-load time, fmt_date(), and
 * lib/db.php's MYSQL_ATTR_INIT_COMMAND) — those exist because CRM's
 * reach-out/birthday due-date arithmetic cannot tolerate PHP and MySQL
 * disagreeing about what day it is. This is the plainer, Grocery/Inspiration
 * shape. Photo EXIF dates and year-project isolation (Phase 1+) are
 * date-shaped too; revisit this if they turn out to need one clock as badly
 * as CRM does. */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_DIR', APP_ROOT . '/public');

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("config.php is missing. Copy config.example.php to config.php and fill it in.\n");
}
$GLOBALS['config'] = require $configFile;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (cfg('env', 'production') === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

/** Read a config value with dot notation: cfg('db.host'). */
function cfg(string $path, $default = null)
{
    $node = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

/* ---------------------------------------------------------------- JSON I/O */

function json_out($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, int $status = 400, ?string $detail = null): void
{
    $body = array('error' => $code);
    if ($detail !== null) {
        $body['detail'] = $detail;
    }
    json_out($body, $status);
}

/** Decode a JSON request body into an array. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        json_error('invalid_json', 400);
    }
    return $parsed;
}

/** Restrict an endpoint to specific HTTP verbs. */
function require_method(string ...$allowed): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('method_not_allowed', 405);
    }
    return $method;
}

/* ------------------------------------------------------------ fatal errors */

/**
 * Bail out with an error rendered in the format the caller can actually use.
 *
 *   CLI    -> STDERR + exit 1, so tools/ scripts fail loudly
 *   /api/* -> JSON, because that's what a fetch() on the other end is parsing
 *   else   -> a minimal HTML page using the real stylesheet
 *
 * Routing off the /api/ path segment is safe because public/api/ is where
 * JSON endpoints live and nowhere else, matching the suite convention.
 */
function fatal_error(string $code, string $human, int $status = 500): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $code . ': ' . $human . PHP_EOL);
        exit(1);
    }

    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        json_error($code, $status);
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $safe = htmlspecialchars($human, ENT_QUOTES, 'UTF-8');
    // Cache-busted like everywhere else, but NOT via asset(): this can fire
    // before config is trustworthy, and a fatal handler that itself fatals
    // shows the user a blank page.
    $mtime   = @filemtime(PUBLIC_DIR . '/assets/styles.css');
    $cssHref = 'assets/styles.css' . ($mtime ? '?v=' . $mtime : '');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake</title>
<link rel="stylesheet" href="/{$cssHref}">
</head>
<body class="login-body">
  <main class="login-card">
    <h1 class="login-title">Keepsake</h1>
    <p>{$safe}</p>
    <p class="hint">This is a server problem, not something you did.</p>
  </main>
</body>
</html>

HTML;
    exit;
}

/* ---------------------------------------------------------------- misc */

/**
 * Cache-busted URL for a file under the web root.
 *
 *   <link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
 *   -> assets/styles.css?v=1751049600
 *
 * Keyed to the file's own mtime, so a changed file busts itself and an
 * unchanged one stays cached. Falls back to no version if the file can't be
 * stat'd, which only costs the cache benefit rather than breaking the link.
 */
function asset(string $relative): string
{
    $relative = ltrim($relative, '/');
    $stamp    = @filemtime(PUBLIC_DIR . '/' . $relative);

    return h($stamp === false ? $relative : $relative . '?v=' . $stamp);
}

/** Escape for HTML output. Shorthand because templates are full of it. */
function h(?string $raw): string
{
    return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
}
