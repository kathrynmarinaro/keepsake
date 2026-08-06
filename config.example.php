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
     * One password, no username, matching every sibling app. Setting it up:
     *   1. php tools/make-hash.php
     *   2. paste the printed hash below as 'password_hash'
     *
     * THE GATE FAILS OPEN until this is set, same as every sibling: a
     * deploy with no hash configured yet is reachable by anyone who finds
     * the URL rather than being reachable by nobody, including you.
     * SET THIS BEFORE POINTING A REAL DOMAIN AT THIS.
     */
    'password_hash' => 'CHANGE_ME',

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
