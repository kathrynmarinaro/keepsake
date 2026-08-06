<?php
/**
 * Seed (or reset) an allowed Keepsake user.
 *
 * Run this once after loading schema.sql, and again any time a password
 * needs to change. It's an upsert keyed on username, so re-running with the
 * same username just updates the password hash.
 *
 * Usage:
 *   php tools/seed_user.php <username> <password>
 *   php tools/seed_user.php              (prompts interactively)
 *
 * CLI only, same reasoning as tools/make-hash.php in the sibling apps: this
 * turns any string into a valid login, so it must never be reachable over
 * HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/bootstrap.php';

function keepsake_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
}

$username = $argv[1] ?? keepsake_prompt('Username: ');
$password = $argv[2] ?? keepsake_prompt('Password: ');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Both a username and password are required.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Warning: shorter than 8 characters.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

q(
    'INSERT INTO users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
    array($username, $hash)
);

echo "User '{$username}' seeded.\n";
