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

    /* ---- uploads (Phase 2) ---------------------------------------------
     * Config over hardcoding, per PLAN.md: a magic number buried in
     * lib/imageproc.php would be invisible to Kathryn and to whoever else
     * eventually reads this repo. Tune per host — a shared host with a tight
     * memory_limit may need thumb_max lower than this default.
     */
    'uploads' => array(
        // Hard ceiling per uploaded file. 25MB matches Inspiration Board's
        // limit — generous for a modern phone photo (even an uncompressed
        // HEIC burst frame), small enough that one runaway batch can't fill
        // the disk unnoticed.
        'max_bytes' => 25 * 1024 * 1024,

        // Longest edge, in px, of the ONE derivative Keepsake generates per
        // photo. Unlike Inspiration Board there is no separate "detail" copy
        // — the crop tool works directly against the kept original (see
        // lib/imageproc.php), so this only has to be good enough for a list/
        // grid tile and the batch step-through's preview.
        'thumb_max' => 480,

        'webp_quality' => 82,
    ),

    /* ---- event grouping (Phase 4, brief §4.1/§7) ------------------------
     * Date-gap threshold, in days, between two photos' captured_at dates
     * before lib/grouping.php's auto-grouping pass starts a new event group
     * instead of extending the current one. 3 is PLAN.md's own suggested
     * starting point for a first pass, not a researched constant — brief §7
     * flags this exact number as the one most likely to need retuning once
     * Kathryn has real backfilled data to look at (a multi-week vacation
     * probably wants a much larger gap than a single day trip — see
     * keepsake-brief.md §8's open item on this).
     */
    'grouping' => array(
        'gap_days' => 3,
    ),

    /* ---- reverse geocoding (Phase 4, brief §4.1/§7) ---------------------
     * OpenStreetMap Nominatim — free, no API key, per the brief's "avoid
     * paid APIs" principle. Nominatim's usage policy
     * (https://operations.osmfoundation.org/policies/nominatim/) requires:
     *   - a descriptive User-Agent identifying the app and a way to reach
     *     its operator. The placeholder contact below is fine for a
     *     personal single-user deploy, but replace it with something real
     *     (an email you'd actually see, or a URL) before this runs against
     *     the live service — see lib/geocode.php.
     *   - no more than ~1 request/second — 'min_interval_seconds' below,
     *     enforced in lib/geocode.php's geocode_rate_limit() and only ever
     *     applied to an actual network call, never to a geocode_cache hit.
     */
    'geocode' => array(
        'endpoint'             => 'https://nominatim.openstreetmap.org/reverse',
        'user_agent'           => 'Keepsake/1.0 (personal photo-book app; contact: CHANGE_ME@example.com)',
        'min_interval_seconds' => 1.0,
    ),
);
