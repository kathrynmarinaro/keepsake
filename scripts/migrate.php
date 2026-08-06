<?php

/**
 * Minimal migration runner.
 *
 * Applies every migrations/*.sql file in filename order, tracking what's
 * already been applied in a `schema_migrations` table so re-running is
 * safe. Each migration file is expected to contain a single statement (or
 * statements that don't rely on multi-statement execution) — keep
 * migrations one-file-per-change, not one giant multi-statement file.
 *
 * Usage:
 *   php scripts/migrate.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Keepsake\Database;

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
sort($files);

if ($files === []) {
    echo "No migration files found in migrations/.\n";
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo "skip   {$name} (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "Could not read {$name}, skipping.\n");
        continue;
    }

    echo "apply  {$name}\n";
    $pdo->exec($sql);

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $stmt->execute(['migration' => $name]);
}

echo "Done.\n";
