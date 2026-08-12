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

    /* ---- book layout engine (Phase 5, brief §4.3/§7) --------------------
     * EVERY NUMBER IN HERE IS A STARTING POINT, NOT A RESEARCHED CONSTANT.
     * Brief §7 says the auto-arrange algorithm "will likely need iteration
     * after seeing real output" — this block is where that iteration
     * happens, so it is deliberately over-exposed: change a value, re-run
     * "Create book layout", compare the new version against the old one on
     * public/layout.php (versions are never overwritten, so nothing is lost
     * by trying).
     *
     * THE ONE TUNABLE THAT IS NOT HERE: the orientation-pairing score table
     * (is a portrait+landscape pair worse than two portraits, and by how
     * much?). It is 12 numbers keyed by a shape, which reads as noise in a
     * config file and as a table in code — it lives at the top of
     * layout_orientation_score() in lib/layout.php and nowhere else. That
     * function is the first place to look if pages are pairing badly; this
     * block is the place to look if pages are the wrong SIZE or the book
     * feels monotonous.
     */
    'layout' => array(

        /* ---- sub-grouping within an event group (brief §4.3) ----
         * A gap of more than this many hours between two consecutive photos
         * starts a new page-group inside the same event, so "a beach
         * morning vs. a dinner that evening" don't share a page. 5 hours is
         * chosen to sit above the gaps inside one outing (lunch, a drive, a
         * nap) and below the gap between two separate outings in a day; a
         * night's sleep clears it easily, which is what makes this double as
         * "sub-group by day" without a separate calendar-day rule.
         * Fractional values are fine (2.5).
         */
        'subgroup_gap_hours' => 5.0,

        /* ---- standalone text (brief §4.3) ----
         * A quote or anecdote longer than this many characters gets a full
         * page of its own instead of sharing a page as a text card. ~180 is
         * the brief's own suggested starting point, explicitly "a starting
         * point to adjust after seeing a real draft".
         */
        'text_page_chars' => 180,

        /* How far, in days, a quote/anecdote that falls in NO event group's
         * date range may reach to attach itself to a nearby page-group as a
         * text card. Text inside an event's range always attaches to that
         * event regardless of this value (the brief requires it); this is
         * only about the leftovers. Beyond this window a short text gets a
         * page to itself — so if a draft comes back with too many
         * one-quote pages, widen this before anything else.
         */
        'text_attach_days' => 2,

        /* ---- what makes a page good (brief §4.3) ----
         * Two scores are added per candidate page: how well its photos'
         * orientations read together (the PRIMARY driver, per the brief) and
         * how much this app likes that page size in the abstract (the
         * SECONDARY influence). These weights set the balance between them —
         * raising density_weight makes the engine chase its preferred page
         * sizes even when the orientations don't really suit.
         */
        'orientation_weight' => 1.0,
        'density_weight'     => 0.5,

        /* ---- how many photos a page may hold (PLAN.md, Round 5) ----
         * A HARD CONSTRAINT, not a preference: every page_type='photos' page
         * carries between page_size_min and page_size_max photos, and the
         * engine will not emit one that doesn't. Scoring still chooses
         * BETWEEN legal pages (which photos, in what grouping) — it just no
         * longer gets a vote on whether a 1-up page is allowed at all.
         *
         * This exists because two rounds of scoring changes didn't settle
         * it. "Mostly multi-image pages" was asked for twice; the answer the
         * first two times was to make 1-up score worse (density_preference[1]
         * down to 0.18 below, then an unconditional singles_penalty), and
         * each time singles still turned up, because anything decided by a
         * score can be won by a score — a page size that scores badly is
         * still a page size that wins occasionally. So it stopped being a
         * score.
         *
         * TWO KINDS OF 1-PHOTO PAGE STILL EXIST IN A BOOK, both deliberate:
         * a photo Kathryn ticked "full page" on (§2.4 — that flag has always
         * bypassed this whole mechanism), and a photo that genuinely has no
         * neighbour to share a page with, after lone_merge_gap_hours below
         * has tried to find it one. Nothing can pair a photo with nothing.
         *
         * RAISING page_size_max TO 4 BRINGS 4-UP PAGES BACK, exactly as they
         * behaved before Round 5 — the ceiling is tunable precisely so that
         * decision stays Kathryn's. 4 is the hard maximum (the database's
         * own CHECK on book_page_photos); anything larger is clamped to it.
         * Lowering page_size_min to 1 re-allows single-photo pages, which
         * puts the decision back in the hands of density_preference[1] and
         * lands you where Round 4 was.
         */
        /* RAISED TO 1..4 IN ROUND 6, after Kathryn reviewed a full book built
         * from the redrawn template library and chose it page by page.
         *
         * Round 5 set these to 2..3 because "mostly multi-image pages" had been
         * asked for twice and scoring kept letting singles through. That
         * reasoning still holds and is left in place above — what changed is
         * that the templates below it are no longer the same. Her sketched
         * layouts include four-photo pages, and she added the all-portrait 2x2
         * herself on seeing that every drawn 4-up needed a landscape, which
         * would have capped 20 of her 36 event groups at three photos a page.
         * The approved book has 15 four-up pages out of 44.
         *
         * page_size_min back to 1 for the same reason: shape feasibility, not
         * preference, is now what forces a short page. compose_accepts() will
         * refuse a page no template can draw, so a single is what remains when
         * a photo genuinely has nowhere to sit — and the partitioner charges
         * 0.25 for one anyway, so it is still the last resort rather than the
         * easy way out. */
        'page_size_min' => 1,
        'page_size_max' => 4,

        /* How far, in hours, a LONE photo may reach to join its neighbours
         * rather than take a page of its own. This is the structural half of
         * "fewer single-photo pages": no page-size rule can help a
         * page-group that only ever HAS one photo in it, so a group of one
         * goes and merges into a chronological neighbour before pages are
         * decided at all.
         *
         * IT ONLY APPLIES TO UNGROUPED PHOTOS. A lone photo inside an event
         * group merges into that event's neighbouring page-group ALWAYS,
         * however wide the gap — the event is already the statement that
         * those photos are one occasion, and a single shot from the far end
         * of a three-day trip still belongs to the trip. With no event
         * saying so, the gap is the only evidence there is: a day-ish (24h)
         * says "same stretch of life, ride along", while a lone shot from a
         * different week is genuinely its own moment and has earned its
         * page. Lower this if stray photos are being glued to days they
         * don't belong to; raise it if the book still has too many
         * single-photo pages from ungrouped stretches.
         *
         * A merge never crosses the grouped/ungrouped boundary, and never
         * joins two different event groups. Fractional values are fine.
         */
        'lone_merge_gap_hours' => 24.0,

        /* House preference for each page size, before orientation and
         * variety have their say — the tie-breaker between the LEGAL page
         * sizes above, now that legality is decided separately. 2- and 3-up
         * are the book's default voice.
         *
         * ENTRIES 1 AND 4 ARE UNREACHABLE AT THE DEFAULT BOUNDS and are kept
         * for exactly the case where they aren't: widen page_size_min/max
         * and these are the numbers that decide how eagerly the engine
         * reaches for the sizes you just re-allowed. 1-up is deliberately
         * LOW because "one photo per page for everything" is the exact look
         * brief §4 exists to avoid — a photo that deserves a page of its own
         * gets there by Kathryn ticking "full page" on it (§2.4), not by the
         * engine drifting there.
         *
         * LOWERED POST-LAUNCH (PLAN.md), from 0.35: a mismatched pair used
         * to have a real visual cost — the old renderer force-cropped every
         * photo into a square, so pairing a portrait with a landscape meant
         * one of them lost real content off its edges, and 1-up was a
         * legitimate way to avoid that. lib/layout_render.php's composition
         * tree removed that cost (a mismatched pair now gets an asymmetric
         * split sized to each photo's own shape, no crop beyond what
         * layout_auto_crop_rect() already does everywhere), so there's much
         * less reason for the engine to reach for 1-up as an escape hatch —
         * "mostly multi-image pages" (Kathryn's own request) needed this
         * turned down further to actually show up in a generated book.
         */
        'density_preference' => array(1 => 0.18, 2 => 1.0, 3 => 0.95, 4 => 0.85),

        /* ---- rhythm / variety (brief §4.3: "not ten 2-up spreads in a row") ----
         * How many recently-emitted pages the engine remembers, and how hard
         * it pushes away from repeating a page size. The penalty is applied
         * once per page in the immediately preceding RUN of the same size
         * (two 2-ups in a row make a third cost 2 x this), and at
         * echo_factor of it for other pages of that size still inside the
         * window. Raise repeat_penalty for a more restless book; set it to 0
         * to turn the variety heuristic off entirely and let orientation
         * matching decide alone.
         */
        'variety_window'         => 4,
        'variety_repeat_penalty' => 0.18,
        'variety_echo_factor'    => 0.5,

        /* GONE IN ROUND 5: 'orphan_page_penalty' (0.35) and
         * 'singles_penalty' (0.5), both removed rather than retuned. Both
         * were charges against page sizes that page_size_min/page_size_max
         * above now forbid outright: a partition that would strand exactly
         * one photo at the end of a page-group is no longer among the
         * candidates at all, and there is no 1-up candidate left to charge.
         * A knob that can only ever be multiplied by zero is worse than no
         * knob — it reads like a lever and isn't one.
         *
         * LEAVING THEM IN AN EXISTING config.php IS HARMLESS. Unknown keys
         * in this block are merged over the engine's defaults and then
         * simply never read (lib/layout.php's layout_tuning()); nothing
         * warns, nothing misbehaves. Delete them at your leisure.
         */
    ),

    /* ---- PDF export (Phase 7, brief §5.5) -------------------------------
     * Printer-agnostic on purpose (brief §5.5: "the same PDF should be
     * uploadable to multiple print-on-demand services"): every number below
     * came from actually reading Lulu's and Mixam's own current spec pages
     * (checked 2026-08-06), not assumed, per PLAN.md's explicit instruction
     * that print specs change over time. See lib/pdfexport.php's
     * pdf_export_geometry() for how these combine into the actual PDF page
     * size mPDF is told to render.
     */
    'export' => array(

        /* Trim size in inches — brief §5.5's own spec: "8.5 x 8.5". Both
         * printers offer this as a standard square softcover trim, so
         * there's no printer-specific adjustment needed here.
         */
        'trim_width_in'  => 8.5,
        'trim_height_in' => 8.5,

        /* THE SPINE, for the separate spine PDF the cover printer asks for.
         *
         * WIDTH DEPENDS ON YOUR PAGE COUNT AND YOUR PAPER, so the printer tells
         * you what it is — it is in the filename of the template they hand you
         * (…_Hardcover_Photo_Books_0_35.pdf is a 0.35in spine). It is NOT
         * something this app can work out: it knows the page count but not the
         * stock. Change this when the printer gives you a new number, and export
         * the spine again.
         *
         * The spine PAGE is this wide by trim-height + 2 × spine_bleed_in tall.
         * Mixam's 8.5x8.5 hardcover template ships its spine page as
         * 0.35in x 10.10in — 8.50 of visible spine with 0.80 of wrap top and
         * bottom — and that 0.80 is NOT the same as the interior's 0.125: a
         * hardcover's boards wrap around the block, so the cover parts carry
         * far more bleed than the pages do.
         */
        'spine_width_in' => 0.35,
        'spine_bleed_in' => 0.8,

        /* How far to keep type off the spine's long edges, where the fold
         * lands. The spine is a third of an inch wide, so this is the
         * difference between a title that reads and one that runs into the
         * hinge. */
        'spine_safe_in'  => 0.04,

        /* HOW MUCH OF THE SPINE'S LENGTH THE WORDS FILL, as a fraction of the
         * visible spine — 0.80 leaves a tenth of the spine clear at the head
         * and a tenth at the foot. The type size is solved BACKWARDS from this
         * rather than being a number of points: a spine looks wrong when the
         * words are the wrong PROPORTION of it, and the proportion is the thing
         * that should stay fixed while the name changes.
         *
         * It is a target, not a promise. A short name would need type taller
         * than the spine is wide to fill 80% of its length, and there the width
         * wins — the words come out shorter and centred, which is what a short
         * title on a wide-ish spine should look like anyway. */
        'spine_fill'     => 0.80,

        /* Bleed, in inches, added to EACH of the four edges — the exported
         * PDF page is trim + 2×bleed on both dimensions (8.75in x 8.75in at
         * the defaults above). THE TWO PRINTERS AGREE EXACTLY HERE, no
         * reconciliation needed:
         *   - Lulu: "trimming tolerance is 0.125 in/3.175 mm", and Lulu's
         *     own 8.5x8.5 interior template ships sized at 8.75in x 8.75in —
         *     exactly trim + 2x0.125in. (Lulu Help Center, "What is Full
         *     Bleed?", https://help.lulu.com/en/support/solutions/articles/64000255584-what-is-full-bleed-;
         *     Lulu Book Creation Guide, https://assets.lulu.com/media/guides/en/lulu-book-creation-guide.pdf)
         *   - Mixam: "All print items... require a 0.125in bleed area
         *     outside your trim line... every file must include a 0.125in
         *     bleed." (Mixam Support, "Full Bleed Printing Explained",
         *     https://mixam.com/support/bleed; corroborated by Mixam's own
         *     digest-size worked example, 5.5x8.5 trim -> 5.75x8.75 file,
         *     the same 0.125in-per-edge math.)
         */
        'bleed_in' => 0.125,

        /* Safety margin, in inches, measured IN FROM THE TRIM EDGE (not the
         * outer bleed edge) — keep text, faces, anything you'd mind losing,
         * inside this. THE TWO PRINTERS DO NOT QUITE AGREE HERE, and this
         * app renders one page at a time with no separate inner/outer-edge
         * treatment for a bound gutter, so this takes the LARGER, more
         * conservative of the two rather than trying to vary margin by which
         * edge is which:
         *   - Lulu: flat 0.5in everywhere — "Important images and text
         *     should be kept 0.5in from the trimmed edge." (Lulu Book
         *     Creation Guide, as above.)
         *   - Mixam: a smaller 0.25in "quiet area" for ordinary content, but
         *     a separate, larger 0.5in "gutter margin" specifically for the
         *     bound/spine edge of a softcover interior page. (Mixam Support,
         *     "Print File Setup Guide", https://mixam.com/support/filesetup.)
         * One uniform 0.5in clears both: it matches Lulu's number on every
         * edge, and it's AT LEAST as conservative as Mixam's on every edge
         * too (more generous than Mixam's 0.25in quiet area, exactly equal
         * to Mixam's own 0.5in gutter number on the one edge that matters
         * most). See lib/pdfexport.php's header and the Phase 7 session
         * report for the full reasoning — this is the one place brief/
         * PLAN.md's "verify against both services' spec sheets" turned up a
         * real disagreement to reconcile, not just a number to confirm.
         */
        'safety_margin_in' => 0.5,

        /* Resolution photos are embedded at, in dots per inch of their FINAL
         * printed size. 300 is what commercial printing asks for and what it
         * can resolve; above it the extra pixels only make the file bigger.
         *
         * This exists because the first successful export was over 400 MB: a
         * phone photo is three or four times 300dpi once it is a quarter of a
         * page, and all of that surplus was being embedded. Lower it to shrink
         * a proof you only mean to look at on screen; do not raise it expecting
         * a better print. */
        'print_dpi' => 300,
    ),
);
