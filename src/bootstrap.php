<?php

/**
 * Shared bootstrap for every entry point (public/*.php and scripts/*.php).
 *
 * Responsibilities: load config, wire up the DB connection and auth
 * session. Kept dependency-free (no Composer autoloader) — the app is
 * small enough that explicit requires are simpler than an autoloader to
 * maintain, consistent with "no framework beyond what the suite already
 * uses" (PLAN.md Architecture decisions).
 */

declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    die(
        "Missing config/config.php.\n\n" .
        "Copy config/config.php.example to config/config.php and fill in " .
        "your database credentials, then reload."
    );
}

/** @var array{app: array, db: array} $config */
$config = require $configPath;

// Stash for the config() helper (see src/lib/helpers.php).
$GLOBALS['__keepsake_config'] = $config;

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';

if (($config['app']['env'] ?? 'production') === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

\Keepsake\Database::init($config['db']);

// Session setup. Entry points that don't need a session (none currently,
// but e.g. a future health-check endpoint might) can skip calling this
// file, or session_start() below is cheap/idempotent to leave in.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'keepsake_session');

    // Reasonable session-cookie defaults for a self-hosted single-user app
    // served over HTTP in dev and HTTPS in production. `secure` is left
    // off here since local dev usually isn't TLS; put this app behind
    // HTTPS in production and consider hardening this further then.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

\Keepsake\Auth::init($config['app']);
