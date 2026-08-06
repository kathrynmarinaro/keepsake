<?php
/* PDO connection, created once per request on first use.
 *
 * Ported from Personal CRM, which ports it from Grocery, which ports it from
 * Book Tracker and the Workout Generator. Fixes here should land in the
 * siblings too.
 *
 * ONE ADDITION over a plain sibling connection: a CLI-only override so
 * tools/test-harness.php can point db() at an in-memory SQLite database built
 * from schema.sql. There is no MySQL in this build environment, so without
 * this override nothing that calls q() is testable.
 *
 * Deliberately NOT ported: Personal CRM's MYSQL_ATTR_INIT_COMMAND time-zone
 * pin. That exists because CRM's due-date arithmetic cannot tolerate PHP and
 * MySQL disagreeing about what day it is; Keepsake has no cron and no
 * NOW()-driven query yet. Revisit if a later phase (event-group date-gap
 * detection, say) turns out to need one clock as badly as CRM does. */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /* THE ONE DEVIATION FROM A PLAIN CONNECTION.
     *
     * tools/test-harness.php builds an in-memory SQLite database from
     * schema.sql and installs it here, so tests exercise the REAL repo
     * functions through the REAL q() rather than a parallel query path that
     * could drift from what ships.
     *
     * Gated on CLI so this is not reachable over HTTP under any
     * circumstances — not even with a crafted request — because $GLOBALS is
     * not populated from request input. */
    if (PHP_SAPI === 'cli'
        && isset($GLOBALS['keepsake_pdo_override'])
        && $GLOBALS['keepsake_pdo_override'] instanceof PDO
    ) {
        $pdo = $GLOBALS['keepsake_pdo_override'];
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        cfg('db.host', 'localhost'),
        cfg('db.name'),
        cfg('db.charset', 'utf8mb4')
    );

    try {
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    } catch (PDOException $e) {
        // The real reason goes to the log, never to a web response — a
        // connection error message can carry the host, database name and
        // user.
        error_log('DB connect failed: ' . $e->getMessage());

        if (PHP_SAPI === 'cli') {
            // On the command line there's nobody to hide it from: you're
            // already logged into the account with config.php open.
            fatal_error(
                'db_unavailable',
                'Could not connect to the database: ' . $e->getMessage()
                    . "\n\nCheck the db block in config.php.",
                500
            );
        }

        fatal_error('db_unavailable', 'The database is unavailable right now.', 500);
    }

    return $pdo;
}

/** Run a query with bound params and return the statement. */
function q(string $sql, array $params = array()): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
