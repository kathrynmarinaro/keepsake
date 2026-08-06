<?php

/**
 * Seed (or reset) the single allowed Keepsake user.
 *
 * Run this once after migrating, and again any time the password needs to
 * change. It's an upsert keyed on username, so re-running with the same
 * username just updates the password hash.
 *
 * Usage:
 *   php scripts/seed_user.php <username> <password>
 *   php scripts/seed_user.php              (prompts interactively)
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Keepsake\Database;

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
    fwrite(STDERR, "Password should be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash) VALUES (:username, :hash)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute(['username' => $username, 'hash' => $hash]);

echo "User '{$username}' seeded.\n";
