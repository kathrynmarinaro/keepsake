<?php
/* =====================================================================
 * Keepsake — configuration
 * ---------------------------------------------------------------------
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored and must never be committed.
 *
 * On a shared host, keep this file OUTSIDE the web root, next to lib/ —
 * so it can never be served over the web even if PHP is misconfigured,
 * matching the rest of the suite.
 * ===================================================================== */

return array(

    /* ---- database ---------------------------------------------------
     * Create the DB, then paste those values here. Host is usually
     * 'localhost'.
     */
    'db' => array(
        'host'    => 'localhost',
        'name'    => 'keepsake',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ),

    // 'local' enables PHP error display; anything else hides it.
    'env' => 'local',

    /* ---- the gate -----------------------------------------------------
     * Unlike the siblings (a single password_hash living right here in
     * config.php), Keepsake keeps a real `users` table (see schema.sql)
     * seeded via `php tools/seed_user.php <username> <password>` — a
     * Phase 0 decision this file's reconciliation pass preserved rather
     * than replaced (see lib/auth.php).
     *
     * THE GATE FAILS OPEN until a user is seeded, same as every sibling:
     * a deploy with no user seeded yet is reachable by anyone who finds
     * the URL rather than being reachable by nobody, including you.
     * SEED A USER BEFORE POINTING A REAL DOMAIN AT THIS.
     */

    // PHP session cookie name. Namespaced so signing in or out here never
    // disturbs the RSS Reader, Grocery or Personal CRM on the same host.
    'session_name' => 'keepsake_session',

    /* ---- public address ------------------------------------------------
     * Where this app lives, e.g. 'https://keepsake.example.com'. Not
     * required for anything in Phase 0/1 (every link so far is relative);
     * kept as a config value rather than invented later, since Phase 7's
     * PDF export or a future emailed link is likely to want it.
     */
    'base_url' => 'http://localhost:8000',
);
