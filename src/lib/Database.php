<?php

declare(strict_types=1);

namespace Keepsake;

/**
 * Thin PDO wrapper. One lazily-created shared connection per request.
 *
 * Why PDO over mysqli: named parameters (readable multi-column inserts as
 * the schema grows in Phase 1), a consistent exception-based error model,
 * and it's the more common default for new suite apps going forward — see
 * PLAN.md "Architecture decisions". Nothing here is exotic; swap to mysqli
 * later if a sibling-app convention turns out to disagree, it's a small
 * surface to change.
 */
final class Database
{
    private static ?array $config = null;
    private static ?\PDO $connection = null;

    public static function init(array $dbConfig): void
    {
        self::$config = $dbConfig;
        // Allow re-init (e.g. in tests) to force a fresh connection.
        self::$connection = null;
    }

    public static function connection(): \PDO
    {
        if (self::$connection === null) {
            if (self::$config === null) {
                throw new \RuntimeException(
                    'Database::init() must be called (see src/bootstrap.php) before Database::connection().'
                );
            }

            $c = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'] ?? 3306,
                $c['database'],
                $c['charset'] ?? 'utf8mb4'
            );

            self::$connection = new \PDO($dsn, $c['username'], $c['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$connection;
    }
}
