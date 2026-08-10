<?php
/* Book layout engine — Phase 5, brief §4.3/§4.5/§7 (see PLAN.md: "the hard
 * part").
 *
 * Turns one year_project's reviewed content into an ordered list of book
 * pages, and persists it as a VERSIONED layout (lib/repo.php's book_layout
 * and book_page functions write the rows; this file decides what the rows
 * should say).
 *
 * ============================================================ THE PIPELINE
 *
 *   layout_load_year_content()  what's eligible (skip_for_book excluded here,
 *                               once, so nothing downstream has to remember)
 *        |
 *   layout_plan()               PURE. content -> ordered list of page specs.
 *        |                      No DB access at all, which is what lets
 *        |                      tools/verify-layout.php assert on a whole
 *        |                      book's shape without writing a row.
 *        v
 *   layout_write_pages()        page specs -> book_pages/book_page_photos
 *
 * layout_generate() runs all three against a new book_layouts row.
 * layout_reflow_from() runs all three again inside an EXISTING layout,
 * starting at a given page number — see its own comment.
 *
 * ==================================================== WHAT DECIDES A PAGE
 *
 * Everything that decides anything is a small pure function, because brief §7
 * and PLAN.md's delegate prompt both say in as many words that Kathryn will
 * want to hand-tune this after seeing a real draft. In rough order of how
 * likely each is to be the thing that needs changing:
 *
 *   layout_orientation_score()   is this SET of orientations a good page?
 *                                (the primary driver, brief §4.3 — and the
 *                                one table in this app that isn't in config;
 *                                see config.example.php's 'layout' block for
 *                                why)
 *   layout_variety_penalty()     have we just done this page size to death?
 *   layout_density_preference()  house preference per page size, in the
 *                                abstract (config)
 *   layout_page_score()          the three above, combined, for one candidate
 *   layout_merge_lone_subgroups() a photo with nobody to share a page with
 *                                goes and finds a neighbour
 *   layout_partition_subgroup()  one page-group -> its pages
 *
 * TWO TO THREE PHOTOS A PAGE IS A CONSTRAINT, NOT A PREFERENCE (PLAN.md,
 * Round 5). Kathryn asked twice for fewer single-photo pages; both earlier
 * answers were scoring changes (density_preference[1] lowered, then an
 * unconditional singles_penalty) and both left 1-up able to win occasionally,
 * because anything decided by a score can be won by a score. So page size is
 * no longer scored at all: layout_partition_subgroup() only ever considers
 * partitions in which every page holds page_size_min..page_size_max photos,
 * and scoring chooses among those. The two 1-photo pages a reader will still
 * see are both deliberate — a photos.full_page shot, and a photo that has no
 * neighbour to pair with even after the merging pass.
 *
 * SEARCHED PER GROUP, WALKED IN BOOK ORDER. Within a page-group the engine
 * now enumerates every legal partition and keeps the best, which the hard
 * bounds make cheap; across the book it is still a single pass in reading
 * order, because the variety heuristic is a function of the pages already
 * emitted ACROSS THE WHOLE BOOK — event groups, full-page photos and snapshot
 * pages interleaved — and "optimal within this group" would be optimising the
 * wrong thing. That is the same shape the brief describes ("across the book")
 * and the one whose decisions a human can still follow when retuning.
 *
 * ============================================== WHAT NEVER COMPETES FOR A SLOT
 *
 *   - photos.full_page       its own page, always, one slot. Breaks out of
 *                            the density logic entirely (brief §2.4/§4.3).
 *   - snapshots              their own page_type='snapshot' page, always,
 *                            zero slots — the template renders from
 *                            snapshot_id (brief §2.3, schema.sql).
 *   - photos.caption         renders inline in its photo's own slot. It is
 *                            not a content type and takes no slot of its own
 *                            (brief §2.5).
 *   - photos.skip_for_book   excluded from the pool entirely, not
 *                            deprioritised (brief §4.2).
 *   - a snapshot's hero photo  consumed by that snapshot's page; kept out of
 *                            the photo flow so it can't also appear loose two
 *                            pages later.
 *
 * A quote/anecdote DOES compete for a slot: short ones ride along as a text
 * card in one of a photo page's slots, long ones (over the configurable
 * ~180-char threshold) get a page to themselves. Never as a photo's caption —
 * see keepsake-brief.md §2.5 for that removed idea.
 */

declare(strict_types=1);

/* The template library and its shape rules. layout.php decides HOW MANY photos
 * share a page; compose.php decides whether those particular shapes are a page
 * at all, which is a question the old orientation table could not ask. */
require_once __DIR__ . '/compose.php';

/* =========================================================== tuning ======= */

/**
 * Every knob, with its default. config.php's 'layout' block overrides any
 * subset of these; anything it doesn't mention falls back here, so an older
 * config.php can't half-configure the engine into a shape nobody tested.
 *
 * Read once per generation run and passed down as an argument rather than
 * being re-read by each scoring function — the scorers stay pure, and a test
 * can score a page against tuning that isn't in any config file.
 */
function layout_tuning(): array
{
    $defaults = array(
        'subgroup_gap_hours'     => 5.0,
        'text_page_chars'        => 180,
        'text_attach_days'       => 2,
        'orientation_weight'     => 1.0,
        'density_weight'         => 0.5,
        // Kept in sync with config.example.php's own copy of this array —
        // see that file's comment for why 1-up was lowered post-launch, and
        // why entries 1 and 4 are now only reachable by widening the page
        // size bounds below.
        'density_preference'     => array(1 => 0.18, 2 => 1.0, 3 => 0.95, 4 => 0.85),
        'variety_window'         => 4,
        'variety_repeat_penalty' => 0.18,
        'variety_echo_factor'    => 0.5,
        // How many photos a page_type='photos' page may carry, as a HARD
        // CONSTRAINT rather than a preference. Round 5 set this to 2..3
        // because two earlier rounds had tried to make 1-up pages rare by
        // SCORING them lower and a score can always be won.
        //
        // Round 6 opened it back to 1..4, because the thing being constrained
        // changed underneath it. The template library is now Kathryn's own
        // sketches, which include four-photo pages, and she added the
        // all-portrait 2x2 herself; the book she approved has 15 four-ups out
        // of 44 pages. And a short page no longer needs a scoring guard,
        // because compose_accepts() refuses any page no template can draw —
        // shape feasibility forces a single now, not preference.
        'page_size_min'          => 1,
        'page_size_max'          => 4,
        // How far a LONE photo may reach to ride along with its ungrouped
        // neighbours instead of taking a page of its own — see
        // layout_merge_lone_subgroups(), and config.example.php for the
        // reasoning behind a day-ish default.
        'lone_merge_gap_hours'   => 24.0,
        // REMOVED in Round 5: 'orphan_page_penalty' and 'singles_penalty'.
        // Both were scoring nudges against page sizes the bounds above now
        // forbid outright — stranding exactly one photo is structurally
        // impossible (layout_partition_feasible()), and size 1 is no longer
        // a candidate to charge. array_merge() below means an older
        // config.php that still sets them is harmless: they land in $tuning,
        // nothing reads them, and nothing warns.
    );

    $configured = function_exists('cfg') ? cfg('layout', array()) : array();
    if (!is_array($configured)) {
        $configured = array();
    }

    $tuning = array_merge($defaults, $configured);

    // The one nested value: a config file that sets only some page sizes
    // still gets defaults for the rest rather than a preference of 0 (which
    // would quietly forbid that page size).
    $tuning['density_preference'] = is_array($tuning['density_preference'])
        ? ($tuning['density_preference'] + $defaults['density_preference'])
        : $defaults['density_preference'];

    return $tuning;
}

/** Hard ceiling on slots per page — book_page_photos' own CHECK (1..4). */
const LAYOUT_MAX_SLOTS = 4;

/* ================================================== orientation & scoring == */

/**
 * 'portrait' | 'landscape' | 'flex'.
 *
 * 'flex' is a WILDCARD, not a third shape: it means "this element will read
 * fine in whichever hole the page has left". Three things resolve to it, and
 * they behave identically on purpose:
 *
 *   - a square-ish photo (within 5% of 1:1) — genuinely fits either slot;
 *   - a photo with no stored width/height — fail soft: missing metadata
 *     should not push a photo out of a good page, and Phase 2 leaves both
 *     columns NULL until the thumbnailer has run;
 *   - a text card (see layout_orientation_score()) — it is set type, and set
 *     type reflows to its box.
 *
 * Derived from width/height every time rather than stored: schema.sql's
 * comment on photos.width says exactly why there is no orientation column.
 */
function layout_orientation(array $photo): string
{
    $w = isset($photo['width']) ? (int) $photo['width'] : 0;
    $h = isset($photo['height']) ? (int) $photo['height'] : 0;

    if ($w <= 0 || $h <= 0) {
        return 'flex';
    }

    $ratio = $w / $h;
    if ($ratio > 1.05) {
        return 'landscape';
    }
    if ($ratio < 0.95) {
        return 'portrait';
    }
    return 'flex';
}

/**
 * How well a SET of orientations reads as one page of an 8.5"x8.5" book,
 * 0..1. Brief §4.3's primary driver.
 *
 * THIS TABLE IS THE ALGORITHM'S TASTE, and the first thing to change if real
 * spreads pair badly. It is keyed by "<portraits>,<landscapes>" within each
 * page size, and the reasoning per row is the layout it implies on a SQUARE
 * page:
 *
 *   1 photo   0.75  a single photo makes no pairing decision at all. Scored
 *                   BELOW a well-matched pair deliberately: it is the value
 *                   that decides whether this engine drifts back into the
 *                   one-photo-per-page look brief §4 exists to avoid. A photo
 *                   that has earned a page of its own gets there via
 *                   photos.full_page, which never reaches this function.
 *   2 photos  1.00  two portraits side by side, or two landscapes stacked —
 *                   the two cleanest pages in the book.
 *             0.45  one portrait + one landscape: no arrangement of these two
 *                   on a square page avoids a large dead corner. The engine
 *                   should nearly always prefer to pull a third photo in.
 *   3 photos  0.90  three portraits in a row (portraits tolerate narrow), or
 *                   a full-width landscape banner over two portraits.
 *             0.88  one full-height portrait beside two stacked landscapes.
 *             0.70  three stacked landscapes — thin strips on a square page.
 *   4 photos  0.92  2+2: each ROW of the grid is uniform, which is what makes
 *                   a mixed page read as designed rather than as leftovers.
 *             0.85  four of a kind in a 2x2 — fine, just busy.
 *             0.60  3+1: the odd one out is what breaks a grid.
 *
 * Wildcards ('flex', see layout_orientation()) are resolved by trying every
 * split of them between portrait and landscape and keeping the best — a
 * square photo or a text card fills whichever hole the page has, which is the
 * whole point of calling it flexible.
 *
 * ONE KNOWN CONSEQUENCE, WORTH KNOWING BEFORE RETUNING: because a text card
 * is a perfect partner for anything, a page carrying one tends to take FEWER
 * photos than its neighbours — often a single photo beside the quote. That is
 * the intended reading of "a quote occupies one of the page's slots" and it
 * looks deliberate on the page, but if real drafts come back too sparse, the
 * lever is to stop treating the card as a wildcard here (score it as a fixed
 * portrait-shaped block) rather than to touch the photo rows above.
 */
function layout_orientation_table(): array
{
    static $table = array(
        1 => array('1,0' => 0.75, '0,1' => 0.75),
        2 => array('2,0' => 1.00, '0,2' => 1.00, '1,1' => 0.45),
        3 => array('3,0' => 0.90, '2,1' => 0.90, '1,2' => 0.88, '0,3' => 0.70),
        4 => array('4,0' => 0.85, '0,4' => 0.85, '2,2' => 0.92, '3,1' => 0.60, '1,3' => 0.60),
    );
    return $table;
}

function layout_orientation_score(array $orientations): float
{
    $table    = layout_orientation_table();
    $density  = count($orientations);
    $resolved = layout_resolve_orientations($orientations);

    if ($density < 1 || $density > LAYOUT_MAX_SLOTS) {
        return 0.0;
    }

    $portraits  = 0;
    $landscapes = 0;
    foreach ($resolved as $role) {
        if ($role === 'portrait') {
            $portraits++;
        } else {
            $landscapes++;
        }
    }

    $key = $portraits . ',' . $landscapes;
    return (float) ($table[$density][$key] ?? 0.0);
}

/**
 * Resolve every element of an orientation list to a concrete 'portrait' or
 * 'landscape' role, choosing whichever split of the 'flex' elements
 * (layout_orientation()'s wildcard — a square photo, a photo with no stored
 * dimensions, or a text card riding along) scores best against
 * layout_orientation_table(). Pure, deterministic, and this IS what
 * layout_orientation_score() measures — that function now just re-looks-up
 * the table for whatever this one resolved, so the two can never disagree.
 *
 * DELIBERATELY NOT CACHED ANYWHERE: a page's rendering (public/layout.php,
 * lib/pdfexport.php) calls this again at render time rather than reading
 * back a decision made at generate time, for the same reason
 * photos.width/height has no stored `orientation` column (schema.sql) — a
 * resolved role stored once could disagree with the photo currently in that
 * slot after a Phase 6 manual swap/move. Recomputing is cheap (one pass over
 * at most 4 elements) and can never go stale.
 *
 * Ties go to the FIRST split that reaches the best score, i.e. flex elements
 * fill 'portrait' before 'landscape' — arbitrary but fixed, so two calls
 * against the same input always agree.
 *
 * @param list<string> $orientations 'portrait' | 'landscape' | 'flex', in order
 * @return list<string> 'portrait' | 'landscape', same length and order
 */
function layout_resolve_orientations(array $orientations): array
{
    $table   = layout_orientation_table();
    $density = count($orientations);

    $portraits  = 0;
    $landscapes = 0;
    $flexAt     = array();
    foreach ($orientations as $i => $orientation) {
        if ($orientation === 'portrait') {
            $portraits++;
        } elseif ($orientation === 'landscape') {
            $landscapes++;
        } else {
            $flexAt[] = $i;
        }
    }
    $flex = count($flexAt);

    $bestScore     = -1.0;
    $bestToPortrait = 0;
    for ($toPortrait = 0; $toPortrait <= $flex; $toPortrait++) {
        $key   = ($portraits + $toPortrait) . ',' . ($landscapes + $flex - $toPortrait);
        $score = $table[$density][$key] ?? 0.0;
        if ($score > $bestScore) {
            $bestScore      = $score;
            $bestToPortrait = $toPortrait;
        }
    }

    $resolved = array();
    foreach ($orientations as $i => $orientation) {
        $resolved[$i] = $orientation === 'flex' ? null : $orientation;
    }
    foreach ($flexAt as $rank => $i) {
        $resolved[$i] = $rank < $bestToPortrait ? 'portrait' : 'landscape';
    }

    return array_values($resolved);
}

/** House preference for a page size in the abstract, from config. */
function layout_density_preference(int $density, array $tuning): float
{
    return (float) ($tuning['density_preference'][$density] ?? 0.0);
}

/**
 * Brief §4.3's "intentional visual variety": how much this page size costs
 * given the sizes of the pages just emitted. Pure — $recentDensities is the
 * book so far, oldest first.
 *
 * Two components, because "I have just done this twice" and "I have done this
 * a lot lately" are different complaints:
 *
 *   run   — pages of this size immediately before this one. Charged at full
 *           repeat_penalty EACH, so the third 2-up in a row costs twice what
 *           the second did and something else nearly always wins.
 *   echo  — other pages of this size still inside the window but with
 *           something else in between. Charged at echo_factor of that, since
 *           alternating 2,3,2,3 is rhythm, not monotony.
 */
function layout_variety_penalty(int $density, array $recentDensities, array $tuning): float
{
    $window  = max(0, (int) $tuning['variety_window']);
    $repeat  = (float) $tuning['variety_repeat_penalty'];
    $echoing = (float) $tuning['variety_echo_factor'];

    if ($window === 0 || $repeat <= 0.0) {
        return 0.0;
    }

    $recent = array_slice($recentDensities, -$window);

    $run = 0;
    for ($i = count($recent) - 1; $i >= 0; $i--) {
        if ((int) $recent[$i] !== $density) {
            break;
        }
        $run++;
    }

    $matches = 0;
    foreach ($recent as $seen) {
        if ((int) $seen === $density) {
            $matches++;
        }
    }

    return $repeat * $run + $repeat * $echoing * ($matches - $run);
}

/**
 * The score for ONE candidate page: orientation (primary) plus size
 * preference (secondary), minus what repeating yourself costs. Pure.
 *
 * $orientations is every element that would sit on the page — photos AND a
 * text card if one rides along, since a reader sees four things on a 3-photos-
 * plus-a-quote page, not three.
 */
function layout_page_score(array $orientations, array $recentDensities, array $tuning): float
{
    $density = count($orientations);

    return (float) $tuning['orientation_weight'] * layout_orientation_score($orientations)
         + (float) $tuning['density_weight'] * layout_density_preference($density, $tuning)
         - layout_variety_penalty($density, $recentDensities, $tuning);
}

/**
 * The page-size bounds, clamped to something the rest of the engine can
 * actually honour. Pure.
 *
 * page_size_max is capped at LAYOUT_MAX_SLOTS because book_page_photos' own
 * CHECK constraint would reject anything larger — a typo in config.php should
 * degrade to "the busiest page this schema allows", not to a write that fails
 * halfway through a book. page_size_min is capped at page_size_max for the
 * same reason: min > max describes no page at all.
 *
 * @return array{0:int, 1:int} min, max
 */
function layout_page_size_bounds(array $tuning): array
{
    $max = (int) ($tuning['page_size_max'] ?? 3);
    $min = (int) ($tuning['page_size_min'] ?? 2);

    $max = max(1, min(LAYOUT_MAX_SLOTS, $max));
    $min = max(1, min($max, $min));

    return array($min, $max);
}

/**
 * Can $remaining photos be split into pages of $min..$max each? Pure.
 *
 * This is what makes "no orphan page" a STRUCTURAL fact instead of the
 * one-page-lookahead penalty it used to be (removed in Round 5): a size is
 * only ever offered if what it leaves behind can itself be partitioned, so a
 * page that would strand a single photo is never on the table in the first
 * place. True for 0 (nothing left to place is a valid end).
 */
function layout_partition_feasible(int $remaining, int $min, int $max): bool
{
    if ($remaining === 0) {
        return true;
    }
    for ($pages = 1; $pages * $min <= $remaining; $pages++) {
        if ($remaining <= $pages * $max) {
            return true;
        }
    }
    return false;
}

/**
 * How many partitions of $total into parts of $min..$max exist, SATURATING at
 * $cap. Pure, O(total x max), and the reason layout_partition_subgroup() can
 * decide between exhaustive search and the greedy fallback before doing
 * either.
 *
 * Saturation rather than a real count keeps this honest on a 400-photo group:
 * the true count of {2,3}-compositions grows like 1.3247^n (Padovan), which
 * passes PHP's integer range somewhere around n=300, and a number we only
 * ever compare against a few thousand does not need to be exact above it.
 */
function layout_partition_candidate_count(int $total, int $min, int $max, int $cap): int
{
    $ways    = array_fill(0, $total + 1, 0);
    $ways[0] = 1;

    for ($r = $min; $r <= $total; $r++) {
        $sum = 0;
        for ($size = $min; $size <= $max && $size <= $r; $size++) {
            $sum += $ways[$r - $size];
        }
        $ways[$r] = min($sum, $cap + 1);
    }

    return $ways[$total];
}

/**
 * Above this many candidate partitions, layout_partition_subgroup() stops
 * enumerating and walks the group greedily instead (see there). Sized so the
 * exhaustive path covers every page-group a real year plausibly contains — at
 * the default 2..3 bounds this is reached somewhere around 40 photos in ONE
 * sub-group, i.e. forty photos with no gap over subgroup_gap_hours between
 * any two consecutive ones — while keeping the worst case a few thousand
 * cheap scoring passes rather than an unbounded one.
 */
const LAYOUT_PARTITION_MAX_CANDIDATES = 4000;

/**
 * Every partition of $total photos into consecutive pages of $min..$max, in
 * LARGEST-PAGE-FIRST order. Pure.
 *
 * The order is the tie-break rule: layout_partition_subgroup() keeps the
 * first candidate that reaches the best score (strictly-greater), so two
 * partitions that score identically resolve to the one that packs more photos
 * onto earlier pages — fewer, fuller pages, which is the direction Kathryn
 * has asked for twice.
 *
 * @param array<int,bool> $cardPages page indexes carrying a text card, so a
 *   page can be held to LAYOUT_MAX_SLOTS-1 photos where the card takes the
 *   fourth slot. Only bites if page_size_max is raised to 4; at the shipped
 *   2..3 bounds a card always fits.
 * @return list<list<int>> photo counts per page
 */
function layout_partition_candidates(int $total, int $min, int $max, array $cardPages): array
{
    $out = array();

    $walk = static function (int $placed, int $pageIndex, array $sizes) use (&$walk, &$out, $total, $min, $max, $cardPages): void {
        if ($placed === $total) {
            $out[] = $sizes;
            return;
        }

        $ceiling = min($max, $total - $placed, LAYOUT_MAX_SLOTS - (isset($cardPages[$pageIndex]) ? 1 : 0));

        for ($size = $ceiling; $size >= $min; $size--) {
            if (!layout_partition_feasible($total - $placed - $size, $min, $max)) {
                continue;
            }
            $sizes[] = $size;
            $walk($placed + $size, $pageIndex + 1, $sizes);
            array_pop($sizes);
        }
    };

    $walk(0, 0, array());

    return $out;
}

/**
 * The score of a whole candidate partition: every page scored by
 * layout_page_score() against the book as a reader will have seen it BY THAT
 * PAGE, summed. Pure.
 *
 * The running history is what makes this a sum over a walk rather than a sum
 * over independent pages — page 3's variety penalty depends on what pages 1
 * and 2 turned out to be, and $recentDensities seeds it with the rest of the
 * book so a group doesn't restart the rhythm as though it were page one.
 */
function layout_partition_score(array $orientations, array $sizes, array $cardPages, array $recentDensities, array $tuning): float
{
    $history = $recentDensities;
    $total   = 0.0;
    $placed  = 0;

    foreach ($sizes as $pageIndex => $size) {
        $page = array_slice($orientations, $placed, $size);
        if (isset($cardPages[$pageIndex])) {
            // A text card is one more thing on the page and reads as one —
            // see layout_page_score()'s own comment.
            $page[] = 'flex';
        }

        $total    += layout_page_score($page, $history, $tuning);
        $history[] = count($page);
        $placed   += $size;
    }

    return $total;
}

/**
 * The fallback for a page-group too large to enumerate: one greedy walk,
 * choosing each page's size against the book so far and never offering a size
 * whose remainder couldn't itself be partitioned. Pure.
 *
 * Same bounds, same tie-break (larger page wins a tie, since sizes are tried
 * descending and the comparison is strictly-greater), same hard 2-3
 * guarantee — only the search is smaller. A greedy walk is also what this
 * engine did everywhere before Round 5, so the large-group path is the
 * behaviour that shipped, not a new untested one.
 *
 * The `$size === 0` branch is a fail-soft guard for a config that describes
 * no legal partition at all (page_size_min 3, page_size_max 3, four photos):
 * rather than loop forever or throw away photos, it emits the largest page it
 * can and lets the group end short of the minimum. A book with one odd page
 * beats a screen that 500s.
 *
 * @return list<int> photo counts per page
 */
function layout_partition_greedy(array $orientations, array $cardPages, array $recentDensities, int $min, int $max, array $tuning): array
{
    $total   = count($orientations);
    $history = $recentDensities;
    $sizes   = array();
    $placed  = 0;

    while ($placed < $total) {
        $pageIndex = count($sizes);
        $ceiling   = min($max, $total - $placed, LAYOUT_MAX_SLOTS - (isset($cardPages[$pageIndex]) ? 1 : 0));

        $best      = 0;
        $bestScore = -INF;

        for ($size = $ceiling; $size >= $min; $size--) {
            if (!layout_partition_feasible($total - $placed - $size, $min, $max)) {
                continue;
            }

            $page = array_slice($orientations, $placed, $size);
            if (isset($cardPages[$pageIndex])) {
                $page[] = 'flex';
            }

            $score = layout_page_score($page, $history, $tuning);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $size;
            }
        }

        if ($best === 0) {
            $best = max(1, $ceiling);
        }

        $sizes[]   = $best;
        $history[] = $best + (isset($cardPages[$pageIndex]) ? 1 : 0);
        $placed   += $best;
    }

    return $sizes;
}

/* ============================================ page-groups within an event == */

/** Elapsed hours between two photos' captured_at. INF if either is unreadable. */
function layout_photo_hours_apart(array $a, array $b): float
{
    $ta = strtotime((string) $a['captured_at']);
    $tb = strtotime((string) $b['captured_at']);
    if ($ta === false || $tb === false) {
        // Fail soft, and fail APART: an unreadable timestamp puts the photo on
        // its own page rather than silently gluing it to whatever it happens
        // to sit next to in the sort.
        return INF;
    }
    return abs($ta - $tb) / 3600.0;
}

/**
 * Brief §4.3's "sub-groups photos by day / close timing", the reason
 * photos.captured_at is a DATETIME (schema.sql).
 *
 * Reuses Phase 4's clustering primitive (event_grouping_cluster_by() in
 * lib/grouping.php) with an HOURS metric instead of Phase 4's calendar-days
 * one — same single implementation of "split where consecutive rows are more
 * than X apart", two different notions of X. See that function's comment for
 * why the metric is injected rather than the two passes sharing a threshold
 * in one unit.
 *
 * No separate calendar-day rule: a night's sleep is a gap far wider than the
 * ~5-hour default, so days separate themselves, and the cases a hard day
 * boundary would get WRONG (a party that runs 23:30 to 00:30) stay together
 * where they belong.
 *
 * @return list<list<array>> photo rows, clustered and chronological
 */
function layout_subgroup_photos(array $photos, float $gapHours): array
{
    return event_grouping_cluster_by(
        $photos,
        static fn(array $photo): string => (string) $photo['captured_at'],
        'layout_photo_hours_apart',
        $gapHours
    );
}

/**
 * Two page-groups belong to the same run of the book if they came out of the
 * same bucket: the same event group, or both ungrouped. Pure.
 *
 * The boundary is never crossed by the merging pass below, and that is the
 * point of having this as its own predicate: an event group is a statement
 * ("these photos are the Myrtle Beach trip") and folding an unrelated photo
 * into it would make the book claim something Kathryn didn't. Two DIFFERENT
 * event groups are two different occasions for the same reason.
 */
function layout_subgroup_same_bucket(array $a, array $b): bool
{
    $ga = $a['event_group_id'] === null ? null : (int) $a['event_group_id'];
    $gb = $b['event_group_id'] === null ? null : (int) $b['event_group_id'];
    return $ga === $gb;
}

/** One page-group's photos plus another's, rebuilt into a single group. Pure. */
function layout_subgroup_absorb(array $host, array $lone): array
{
    $photos = array_merge($host['photos'], $lone['photos']);

    // Sorted rather than concatenated: the caller knows the two are adjacent,
    // but this function shouldn't have to trust that, and a group whose
    // photos are out of order would put a page's photos out of order too.
    usort($photos, static function (array $a, array $b): int {
        return array((string) $a['captured_at'], (int) $a['id'])
           <=> array((string) $b['captured_at'], (int) $b['id']);
    });

    $first = $photos[0];
    $last  = $photos[count($photos) - 1];

    $host['photos']     = $photos;
    $host['start']      = (string) $first['captured_at'];
    $host['start_date'] = substr((string) $first['captured_at'], 0, 10);
    $host['end_date']   = substr((string) $last['captured_at'], 0, 10);
    $host['first_id']   = (int) $first['id'];

    return $host;
}

/**
 * THE STRUCTURAL HALF OF "fewer 1-up pages" (PLAN.md, Round 5): a page-group
 * holding exactly ONE photo goes and joins a chronological neighbour, so the
 * partitioner has something to pair it with. Pure; expects $subgroups already
 * in chronological order and returns them the same way.
 *
 * Round 4 ended by naming this as the honest limit of any scoring change: a
 * page-group that only ever HAS one photo has no alternative page size to
 * reach for, so no penalty against 1-up can do anything about it. The fix has
 * to happen before the partitioner runs, and this is it.
 *
 * TWO DIFFERENT RULES, because "alone" means two different things:
 *
 *   inside an event group — merge, ALWAYS, however wide the gap. The event is
 *     already the statement that these photos are one occasion; a single shot
 *     from the far end of a three-day trip still belongs to the trip, and the
 *     sub-grouping gap that separated it (subgroup_gap_hours, ~5h) is a
 *     rhythm heuristic, not a claim that this photo is its own event.
 *   ungrouped — merge only within $loneMergeGapHours. There is no event here
 *     asserting the photos belong together, so the gap is all the evidence
 *     there is: a lone shot from the same day-ish rides along with its
 *     neighbours; a lone shot from a different week is genuinely its own
 *     moment and has earned the page it gets.
 *
 * DETERMINISM. Groups are visited in chronological order. Each side's
 * distance is measured photo-to-photo across the join (the neighbour's
 * closest photo to this one), not group-start to group-start, since that is
 * the gap a reader would feel. The nearer side wins; A TIE GOES TO THE
 * EARLIER neighbour — a lone shot reads more naturally as the tail of what
 * just happened than as a preface to what follows, and "the one before it" is
 * the answer someone re-reading this can predict without running it.
 *
 * A merge that produces a pair is the whole point. Two adjacent lone photos
 * merging into one 2-up page is the same case, handled by the same walk: the
 * first merges into the second, and the second is no longer lone.
 *
 * @param list<array> $subgroups as built by layout_plan(), chronological
 * @return list<array> same shape, chronological, possibly shorter
 */
function layout_merge_lone_subgroups(array $subgroups, float $loneMergeGapHours): array
{
    $list = array_values($subgroups);

    for ($i = 0; $i < count($list); ) {
        if (count($list[$i]['photos']) !== 1) {
            $i++;
            continue;
        }

        $lone      = $list[$i];
        $lonePhoto = $lone['photos'][0];
        $grouped   = $lone['event_group_id'] !== null;

        /* Scan outward for the nearest group from the SAME bucket rather than
         * looking only at index +/-1: an ungrouped photo taken in the middle
         * of an event's date range can sit between two of that event's own
         * sub-groups, and neither of them should be treated as that photo's
         * neighbour just because it is adjacent in the sort. */
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (layout_subgroup_same_bucket($list[$j], $lone)) {
                $prev = $j;
                break;
            }
        }
        $next = null;
        for ($j = $i + 1; $j < count($list); $j++) {
            if (layout_subgroup_same_bucket($list[$j], $lone)) {
                $next = $j;
                break;
            }
        }

        $prevGap = INF;
        if ($prev !== null) {
            $photos  = $list[$prev]['photos'];
            $prevGap = layout_photo_hours_apart($photos[count($photos) - 1], $lonePhoto);
        }
        $nextGap = INF;
        if ($next !== null) {
            $nextGap = layout_photo_hours_apart($lonePhoto, $list[$next]['photos'][0]);
        }

        if (!$grouped) {
            // The window only applies to the ungrouped bucket; INF (an
            // unreadable timestamp, per layout_photo_hours_apart()) fails it,
            // which is the same "fail apart" the sub-grouper already chose.
            if ($prevGap > $loneMergeGapHours) {
                $prev = null;
            }
            if ($nextGap > $loneMergeGapHours) {
                $next = null;
            }
        }

        // <= so a tie goes to the earlier neighbour; INF <= INF keeps that
        // true when both gaps are unmeasurable inside one event group.
        $into = null;
        if ($prev !== null && ($next === null || $prevGap <= $nextGap)) {
            $into = $prev;
        } elseif ($next !== null) {
            $into = $next;
        }

        if ($into === null) {
            $i++;
            continue;
        }

        $list[$into] = layout_subgroup_absorb($list[$into], $lone);
        array_splice($list, $i, 1);
        // Deliberately no $i++: index $i is now whatever followed the group
        // just removed, and it has not been examined yet. The host itself is
        // never re-examined — it holds two photos or more by construction.
    }

    /* A merge can move a group's start earlier (absorbing a lone photo that
     * preceded it), so the chronological order is re-established rather than
     * assumed. Same comparator layout_plan() sorts with, so "chronological"
     * means one thing in this file. */
    usort($list, static function (array $a, array $b): int {
        return array($a['start'], $a['first_id']) <=> array($b['start'], $b['first_id']);
    });

    return $list;
}

/* ==================================================== standalone text ===== */

/**
 * Brief §4.3: over the threshold, a quote/anecdote gets a page to itself
 * instead of sharing one as a card. Measured in CHARACTERS of the trimmed
 * text, multibyte-aware — a threshold that counted bytes would give a quote
 * with an em dash in it a different fate than the same quote without.
 */
function layout_text_is_long(string $text, int $threshold): bool
{
    return mb_strlen(trim($text)) > $threshold;
}

/**
 * Two leftover lone photos, from DIFFERENT occasions, sharing a page.
 *
 * Runs after layout_merge_lone_subgroups() has done everything it is allowed
 * to, and picks up what it deliberately would not: that pass never crosses an
 * event-group boundary, because folding a stray photo into "the Myrtle Beach
 * trip" would make the book claim something Kathryn didn't say.
 *
 * This makes a weaker claim, and that is why it is allowed to cross. It never
 * puts a photo INTO an event. It takes two photos that each ended up alone and
 * seats them on one page, which asserts nothing about either occasion beyond
 * the fact that both happened — and Kathryn reviewed exactly these pages in the
 * layout lab, where they were outlined so they could not be missed, and asked
 * for them in the book.
 *
 * ONLY ADJACENT SINGLETONS PAIR. A lone photo with a real event between it and
 * the next lone photo stays alone: the event is a wall, and reaching over it
 * would put two photos together whose only relationship is that a third thing
 * happened in between. This is what makes the rule chronological rather than
 * merely tidy.
 *
 * PAIRS ONLY, never three or four. A run of five lone photos becomes two pages
 * of two and one page of one, not a 4-up and a single. These photos are not one
 * occasion, and a 4-up reads as an event; two photos read as two photos.
 *
 * The merged group's event_group_id becomes NULL, because it now belongs to
 * neither event. That is the honest answer, and it keeps
 * layout_subgroup_same_bucket() from ever treating this page as part of an
 * event a later pass might merge more photos into.
 *
 * Pure; expects chronological order and returns it.
 */
function layout_pair_lone_subgroups(array $subgroups): array
{
    $list = array_values($subgroups);
    $out  = array();

    for ($i = 0; $i < count($list); $i++) {
        $isLone = count($list[$i]['photos']) === 1;
        $nextIsLone = isset($list[$i + 1]) && count($list[$i + 1]['photos']) === 1;

        if ($isLone && $nextIsLone) {
            $merged = layout_subgroup_absorb($list[$i], $list[$i + 1]);
            $merged['event_group_id'] = null;
            $out[] = $merged;
            $i++;   // the partner is consumed; a third lone photo starts a new pair
            continue;
        }

        $out[] = $list[$i];
    }

    return $out;
}

/**
 * Which page-group (if any) each short text belongs to. Pure.
 *
 * Brief §4.3 places a quote/anecdote by DATE: one whose entry_date falls
 * inside an event group's range "occupies one of the page's slots". So:
 *
 *   1. If the text's date falls inside any event group's [start,end], it
 *      belongs to that event, full stop — and lands on the page-group within
 *      that event whose own dates are closest. Distance never disqualifies it
 *      here; the brief made the decision at the event level already.
 *   2. Otherwise it is a leftover (no event that day, or the year has no
 *      groups at all). It attaches to the nearest page-group in the whole
 *      year IF that group is within text_attach_days, so a quote from a day
 *      Kathryn also took photos rides along with them.
 *   3. Otherwise it stands alone on its own page, in date order.
 *
 * Distance uses Phase 4's event_grouping_range_gap_days() — the same "0 if
 * they overlap, else whole days between" measure the event grouper uses — so
 * "close" means one thing in this app.
 *
 * @param array $texts      array('kind','id','text','date'), any order
 * @param array $subgroups  array('event_group_id','start_date','end_date',...)
 * @param array $groups     event_groups rows ('id','start_date','end_date')
 * @return array{assigned: array<int,list<array>>, standalone: list<array>}
 *   assigned is keyed by index into $subgroups
 */
function layout_assign_texts(array $texts, array $subgroups, array $groups, int $attachDays): array
{
    $assigned   = array();
    $standalone = array();

    foreach ($texts as $text) {
        $date = (string) $text['date'];

        // Which events (if any) were running that day.
        $eventIds = array();
        foreach ($groups as $group) {
            if ((string) $group['start_date'] <= $date && $date <= (string) $group['end_date']) {
                $eventIds[(int) $group['id']] = true;
            }
        }

        $bestIndex = null;
        $bestDist  = null;
        $restrict  = $eventIds !== array();

        foreach ($subgroups as $index => $subgroup) {
            $groupId = $subgroup['event_group_id'];
            if ($restrict && ($groupId === null || !isset($eventIds[(int) $groupId]))) {
                continue;
            }

            $dist = event_grouping_range_gap_days(
                $date,
                $date,
                (string) $subgroup['start_date'],
                (string) $subgroup['end_date']
            );

            if ($bestDist === null || $dist < $bestDist) {
                $bestDist  = $dist;
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null || (!$restrict && $bestDist > $attachDays)) {
            $standalone[] = $text;
            continue;
        }

        $assigned[$bestIndex][] = $text;
    }

    return array('assigned' => $assigned, 'standalone' => $standalone);
}

/* ================================================= partitioning a group === */

/**
 * Roughly how many pages a page-group of $photoCount photos will need, used
 * ONLY to space text cards out across it before the real partition exists.
 *
 * Deliberately assumes the busiest page the bounds allow, which UNDER-
 * estimates: an underestimate means a card scheduled for "page 4" of a group
 * that turns out to have 5 pages simply lands one page early, while an
 * overestimate would schedule cards onto pages that never get emitted and
 * spill them onto pages of their own.
 *
 * Since Round 5 that underestimate is EXACT rather than merely safe: with
 * every page holding page_size_min..page_size_max photos, the fewest pages
 * $photoCount can occupy is precisely ceil(count / max), so every scheduled
 * card index is guaranteed to exist on whatever partition wins. $maxPageSize
 * is a parameter and not a hardcoded 3 so that raising page_size_max in
 * config.php can't quietly turn this back into an OVER-estimate.
 */
function layout_estimate_page_count(int $photoCount, int $maxPageSize = 3): int
{
    if ($photoCount <= 0) {
        return 0;
    }
    return max(1, (int) ceil($photoCount / max(1, $maxPageSize)));
}

/**
 * Which page indexes of a page-group carry a text card, spread as evenly as
 * the counts allow. Pure.
 *
 * 5 pages / 2 cards -> pages 1 and 3, not 0 and 1: cards read as punctuation
 * between photos, and punctuation bunched at the start of a section is just a
 * preface. At most one card per page — schema.sql's own note on
 * page_type='photos' ("may include ONE text-card slot mixed in among the
 * photos"), and more than one would make a text-heavy page pretending to be a
 * photo page.
 *
 * @return list<int> page indexes, ascending
 */
function layout_card_schedule(int $pageCount, int $cardCount): array
{
    if ($pageCount <= 0 || $cardCount <= 0) {
        return array();
    }

    $count   = min($pageCount, $cardCount);
    $indexes = array();

    for ($i = 0; $i < $count; $i++) {
        $index = (int) round((($i + 0.5) * $pageCount / $count) - 0.5);
        $indexes[max(0, min($pageCount - 1, $index))] = true;
    }

    $out = array_keys($indexes);
    sort($out);
    return $out;
}

/**
 * How one page-group becomes pages: how many photos on each, and which carry
 * a text card. Pure — no DB, no photo rows, just orientations in order — so a
 * test can assert the SHAPE of a book without constructing one.
 *
 * EVERY PAGE HOLDS page_size_min..page_size_max PHOTOS. That is a constraint,
 * not a preference, and it is the whole point of this function since Round 5
 * (PLAN.md): Kathryn asked twice for fewer single-photo pages, and two rounds
 * of scoring tweaks — density_preference[1] down to 0.18, then an
 * unconditional singles_penalty — each only made 1-up rarer, because anything
 * that competes on score can still win on score. The size is now decided
 * before taste gets a vote; taste decides only WHICH legal partition wins.
 *
 * The two exceptions, both real photos and neither reachable from here:
 *   - a photos.full_page photo, which layout_plan() emits directly and which
 *     never enters this function;
 *   - a group of one photo, which is what is left after
 *     layout_merge_lone_subgroups() has tried and failed to find it a
 *     neighbour. You cannot pair a photo with nothing.
 *
 * HOW THE PARTITION IS CHOSEN: every legal partition is enumerated and scored
 * page by page against the running book (layout_partition_score()), and the
 * best total wins — ties keeping the largest-pages-first candidate, per
 * layout_partition_candidates(). This is a real search where the old greedy
 * walk was a one-page lookahead, which it can afford to be BECAUSE the
 * candidate set is small: compositions of n into {2,3} grow like 1.3247^n,
 * so an ordinary page-group has single digits of them. Past
 * LAYOUT_PARTITION_MAX_CANDIDATES it falls back to layout_partition_greedy()
 * — same bounds, same guarantee, smaller search.
 *
 * A NOTE ON SCOPE, unchanged from the greedy era: the search is per-group but
 * the variety history is the whole book, seeded through $recentDensities.
 * "Optimal within this group" was never the goal — a reader sees event
 * groups, full-page photos and snapshot pages interleaved — which is why
 * groups are still visited in reading order, each scored against what a
 * reader has just been shown.
 *
 * @param array $orientations     one per photo, chronological
 * @param int   $cardCount        short texts to weave into this group
 * @param array $recentDensities  pages already emitted in the book, oldest first
 * @return list<array{count:int, card:bool}> in page order. count 0 with
 *   card true is a text that found no page to ride on and needs one of its own.
 */
/**
 * One occupant's shape as lib/compose.php's template library talks about it.
 *
 * 'flex' — a near-square photo, or one whose dimensions were never read —
 * becomes a wildcard rather than being forced to a side. It is the same
 * treatment a text card gets, and for the same reason: neither has a shape the
 * templates need to respect, so pinning one to 'portrait' would rule out pages
 * it would sit in perfectly well.
 */
function layout_shape_of(string $orientation): string
{
    if ($orientation === 'portrait')  { return 'P'; }
    if ($orientation === 'landscape') { return 'L'; }
    return '*';
}

/**
 * The shapes a page would hold, given where it starts and whether a card rides
 * along. Cards go last, matching how compose_fill() prefers to seat real photos
 * before wildcards.
 */
function layout_page_shapes(array $orientations, int $offset, int $count, bool $withCard): array
{
    $shapes = array();
    for ($i = 0; $i < $count; $i++) {
        $shapes[] = layout_shape_of((string) ($orientations[$offset + $i] ?? 'flex'));
    }
    if ($withCard) { $shapes[] = '*'; }
    return $shapes;
}

/**
 * Can every page of this candidate partition actually be drawn?
 *
 * A size vector that was fine under the old engine can be undrawable under
 * shape-required templates — "four photos" is a page only if those four photos'
 * shapes are a page. Checked here rather than inside the scorer because an
 * infeasible candidate is not a low-scoring one, it is not a candidate.
 */
function layout_partition_shapes_feasible(array $orientations, array $sizes, array $schedule, int $cardsLeft): bool
{
    $offset = 0;
    foreach ($sizes as $pageIndex => $size) {
        $withCard = $cardsLeft > 0 && isset($schedule[$pageIndex]);
        if ($withCard) { $cardsLeft--; }

        // A card-only page is a text page; it has no photo template to satisfy.
        if ($size > 0 && !compose_accepts(layout_page_shapes($orientations, $offset, $size, $withCard))) {
            return false;
        }
        $offset += $size;
    }
    return true;
}

/**
 * A partition that is guaranteed drawable, for when scoring finds nothing.
 *
 * Exact dynamic programme over (photos placed, pages used), which is small
 * enough to solve outright — a subgroup is at most a few dozen photos and a
 * page holds at most four. Consecutive photos only: the book is a chronological
 * record, and a partition that reordered across pages to find prettier shape
 * matches would quietly rewrite the day.
 *
 * The cost is the lab's, because that is what Kathryn reviewed: one per page,
 * so the baseline is "as few pages as possible", plus a nudge against a
 * single-photo page inside a group that has more to say. Template variety is
 * NOT part of it — that is chosen afterwards by least-recently-used, so page
 * sizes and page styling stay independent decisions.
 *
 * Always finds something: the single-P and single-L templates between them
 * accept any one photo, so an all-singles partition is always available.
 */
function layout_partition_shape_dp(array $orientations, array $schedule, int $cardsLeft, int $min, int $max, array $tuning): array
{
    $n = count($orientations);
    if ($n === 0) { return array(); }

    $pref   = $tuning['density_preference'];
    $weight = (float) $tuning['density_weight'];
    $repeat = (float) $tuning['variety_repeat_penalty'];

    /* What one page costs. The 1.0 is the important term: it is what makes this
     * minimise PAGES, and it is the whole reason the old scorer had to be
     * replaced rather than retuned. That scorer rated pages, not books, so with
     * the ceiling raised to four it cheerfully chose 2,1,2,1,2 over 4,4 —
     * five pages that each score well beat two that score slightly less well.
     * Page count was never in it.
     *
     * density_preference still has its say, as a modifier rather than the whole
     * verdict, so the config keeps meaning what it says: a 1-up at 0.18 costs
     * about 40% more than a 2-up at 1.0, which is enough to make a single the
     * last resort without letting it be forbidden outright. */
    $pageCost = static function (int $size) use ($pref, $weight, $min): float {
        $c = 1.0 + (1.0 - (float) ($pref[$size] ?? 0.5)) * $weight;
        if ($size < $min) { $c += 0.4; }
        return $c;
    };

    /* State is (photos placed, page index, size of the page just laid), so the
     * variety penalty can see one page back. Page index is in the key because
     * the card schedule is fixed by index before the partition is chosen. */
    $best = array(0 => array('0|0' => array('cost' => 0.0, 'sizes' => array(), 'cards' => $cardsLeft, 'page' => 0, 'last' => 0)));

    for ($placed = 0; $placed < $n; $placed++) {
        foreach ($best[$placed] ?? array() as $state) {
            $withCard = $state['cards'] > 0 && isset($schedule[$state['page']]);
            $ceiling  = min($max, $n - $placed, LAYOUT_MAX_SLOTS - ($withCard ? 1 : 0));

            for ($size = 1; $size <= $ceiling; $size++) {
                if (!compose_accepts(layout_page_shapes($orientations, $placed, $size, $withCard))) {
                    continue;
                }

                $cost = $state['cost'] + $pageCost($size);
                if ($size === $state['last']) { $cost += $repeat; }

                $sizes   = $state['sizes'];
                $sizes[] = $size;

                $to  = $placed + $size;
                $key = ($state['page'] + 1) . '|' . $size;
                if (!isset($best[$to][$key]) || $best[$to][$key]['cost'] > $cost) {
                    $best[$to][$key] = array(
                        'cost'  => $cost,
                        'sizes' => $sizes,
                        'cards' => $state['cards'] - ($withCard ? 1 : 0),
                        'page'  => $state['page'] + 1,
                        'last'  => $size,
                    );
                }
            }
        }
    }

    $win = null;
    foreach ($best[$n] ?? array() as $state) {
        if ($win === null || $state['cost'] < $win['cost']) { $win = $state; }
    }

    /* Unreachable while a single-photo template exists for every shape. Kept as
     * a loud, drawable answer rather than an empty group, because losing photos
     * out of a book silently is the worst failure this file has. */
    return $win === null ? array_fill(0, $n, 1) : $win['sizes'];
}

function layout_partition_subgroup(array $orientations, int $cardCount, array $recentDensities, array $tuning): array
{
    $pages     = array();
    $total     = count($orientations);
    $cardsLeft = max(0, $cardCount);

    if ($total === 0) {
        while ($cardsLeft-- > 0) {
            $pages[] = array('count' => 0, 'card' => true);
        }
        return $pages;
    }

    [$min, $max] = layout_page_size_bounds($tuning);

    /* The card schedule is fixed BEFORE the partition is chosen, and is the
     * same for every candidate — otherwise candidates would be scored against
     * different page contents and the comparison would mean nothing. It can
     * do that safely because layout_estimate_page_count() returns the FEWEST
     * pages this group can occupy, so every scheduled index exists on every
     * candidate. */
    $schedule = array_flip(layout_card_schedule(layout_estimate_page_count($total, $max), $cardsLeft));

    if ($total < $min) {
        /* Fewer photos than a page is supposed to hold: the merging pass
         * upstream already looked for a neighbour and found none, so this is
         * a genuinely isolated photo (or, if someone raises page_size_min, a
         * genuinely isolated pair). One page holding what there is — and a
         * card may ride along on it, which costs nothing: the card is not the
         * reason this page is short. */
        $sizes = array($total);
    } else {
        /* One exact dynamic programme, no candidate enumeration and no greedy
         * fallback. Both of those existed to search a space that the templates
         * now cut down for us: a page is only a page if compose_accepts() says
         * those shapes can be drawn, and inside that constraint the DP is
         * small enough to solve outright. */
        $sizes = layout_partition_shape_dp($orientations, $schedule, $cardsLeft, $min, $max, $tuning);
    }

    foreach ($sizes as $pageIndex => $size) {
        $withCard = $cardsLeft > 0 && isset($schedule[$pageIndex]);
        $pages[]  = array('count' => $size, 'card' => $withCard);
        if ($withCard) {
            $cardsLeft--;
        }
    }

    // Cards the schedule never reached: more cards than this group has pages
    // for. They get pages of their own rather than being dropped or doubled
    // up (schema.sql: at most one text card per photo page).
    while ($cardsLeft-- > 0) {
        $pages[] = array('count' => 0, 'card' => true);
    }

    return $pages;
}

/* ========================================================== the plan ====== */

/** A book_page_photos occupant for one text entry. */
function layout_text_slot(array $text): array
{
    return $text['kind'] === 'quote'
        ? array('quote_id' => (int) $text['id'])
        : array('anecdote_id' => (int) $text['id']);
}

/**
 * THE ARRANGEMENT (brief §4.3), pure: eligible content in, ordered page specs
 * out. No database access, no side effects, deterministic.
 *
 * @param array $content array{
 *     photos: list<array>,      photos rows, already filtered to eligible
 *     snapshots: list<array>,   snapshots rows
 *     texts: list<array>,       array('kind','id','text','date')
 *     groups: list<array>       event_groups rows
 *   }
 * @param array $historySeed page densities already in the book ahead of this
 *   plan — empty for a fresh generation, the retained pages' sizes for a
 *   reflow, so the rhythm continues from what Kathryn is already looking at.
 * @return list<array{page_type:string, snapshot_id:?int, slots:list<array>}>
 */
function layout_plan(array $content, array $tuning, array $historySeed = array()): array
{
    $photos    = $content['photos'] ?? array();
    $snapshots = $content['snapshots'] ?? array();
    $texts     = $content['texts'] ?? array();
    $groups    = $content['groups'] ?? array();

    /* ---- 1. full-page photos step out of the flow entirely (brief §2.4) */
    $fullPage = array();
    $flow     = array();
    foreach ($photos as $photo) {
        if (!empty($photo['full_page'])) {
            $fullPage[] = $photo;
        } else {
            $flow[] = $photo;
        }
    }

    /* ---- 2. page-groups: event group first, then day/close timing inside it.
     * Photos with no event group are bucketed together and clustered by the
     * same rule — an ungrouped run of photos from one afternoon is still one
     * afternoon, and Phase 4's grouper may simply not have run yet. */
    $buckets = array();
    foreach ($flow as $photo) {
        $key = $photo['event_group_id'] !== null ? 'g' . (int) $photo['event_group_id'] : 'u';
        $buckets[$key][] = $photo;
    }

    $subgroups = array();
    foreach ($buckets as $key => $rows) {
        /* AN EVENT GROUP IS NOT SPLIT BY TIME.
         *
         * The day/close-timing clustering below applies only to UNGROUPED
         * photos. An event group is already Kathryn's statement that these
         * photos are one occasion — she made it, by hand or by accepting the
         * grouper's suggestion — and re-cutting it by a five-hour gap
         * overrules her with a heuristic. Event group 2 of her 2025 book is
         * eight photos taken between January 15th and January 23rd: clustered,
         * that became eight single-day groups which could only pair up into
         * 2-ups, and she reviewed a proof that kept them together and
         * preferred it.
         *
         * This also settles a disagreement that was already in the file.
         * layout_merge_lone_subgroups() has always merged across ANY gap
         * inside an event, on the stated grounds that "the event is already
         * the statement that these photos are one occasion" — while this line
         * was busy splitting that same event apart on the gap it then ignored.
         * One of the two had to go.
         *
         * Ungrouped photos keep the clustering, and for the reason it was
         * written: with no event asserting they belong together, the gap is
         * the only evidence there is. */
        $clusters = $key === 'u'
            ? layout_subgroup_photos($rows, (float) $tuning['subgroup_gap_hours'])
            : array($rows);

        foreach ($clusters as $cluster) {
            $first = $cluster[0];
            $last  = $cluster[count($cluster) - 1];
            $subgroups[] = array(
                'event_group_id' => $key === 'u' ? null : (int) substr($key, 1),
                'photos'         => $cluster,
                'start'          => (string) $first['captured_at'],
                'start_date'     => substr((string) $first['captured_at'], 0, 10),
                'end_date'       => substr((string) $last['captured_at'], 0, 10),
                'first_id'       => (int) $first['id'],
            );
        }
    }

    usort($subgroups, static function (array $a, array $b): int {
        return array($a['start'], $a['first_id']) <=> array($b['start'], $b['first_id']);
    });

    /* A page-group of ONE photo has no page size to choose between, so it is
     * the one case the partitioner's 2-3 rule cannot fix on its own — it gets
     * folded into a chronological neighbour first (PLAN.md, Round 5). Done
     * here, before text assignment, so a quote attaches to the page-group
     * that will actually exist rather than to one about to disappear. */
    $subgroups = layout_merge_lone_subgroups($subgroups, (float) $tuning['lone_merge_gap_hours']);

    /* Whatever is still alone after that pass may pair with an adjacent orphan,
     * across event boundaries — the one place the boundary is crossed, and only
     * ever between two photos that both ended up with nobody. Runs BEFORE text
     * assignment so a text lands on the page as it will actually be printed
     * rather than on a group that is about to be merged out from under it. */
    $subgroups = layout_pair_lone_subgroups($subgroups);

    /* ---- 3. text: long ones always stand alone, short ones look for a page */
    $threshold = (int) $tuning['text_page_chars'];
    $short     = array();
    $alone     = array();
    foreach ($texts as $text) {
        if (layout_text_is_long((string) $text['text'], $threshold)) {
            $alone[] = $text;
        } else {
            $short[] = $text;
        }
    }

    $assignment = layout_assign_texts($short, $subgroups, $groups, (int) $tuning['text_attach_days']);
    $cards      = $assignment['assigned'];
    foreach ($assignment['standalone'] as $text) {
        $alone[] = $text;
    }

    foreach ($cards as $index => $list) {
        usort($list, static function (array $a, array $b): int {
            return array($a['date'], $a['kind'], (int) $a['id']) <=> array($b['date'], $b['kind'], (int) $b['id']);
        });
        $cards[$index] = $list;
    }

    /* ---- 4. everything that becomes pages, in reading order.
     * The book is chronological end to end, so page-groups, full-page photos,
     * snapshot pages and standalone text pages are merged into ONE ordered
     * list before any page is emitted — that is also what lets the variety
     * heuristic see the book as a reader will, rather than one event group at
     * a time. `rank` only breaks ties on the same instant: a snapshot opens
     * its day, then a full-page photo, then ordinary pages, then loose text. */
    $blocks = array();

    foreach ($subgroups as $index => $subgroup) {
        $blocks[] = array(
            'key'   => $subgroup['start'],
            'rank'  => 2,
            'id'    => $subgroup['first_id'],
            'kind'  => 'subgroup',
            'index' => $index,
        );
    }
    foreach ($fullPage as $photo) {
        $blocks[] = array(
            'key'   => (string) $photo['captured_at'],
            'rank'  => 1,
            'id'    => (int) $photo['id'],
            'kind'  => 'fullpage',
            'photo' => $photo,
        );
    }
    foreach ($snapshots as $snapshot) {
        $blocks[] = array(
            'key'      => (string) $snapshot['entry_date'] . ' 00:00:00',
            'rank'     => 0,
            'id'       => (int) $snapshot['id'],
            'kind'     => 'snapshot',
            'snapshot' => $snapshot,
        );
    }
    foreach ($alone as $text) {
        $blocks[] = array(
            'key'  => (string) $text['date'] . ' 00:00:00',
            'rank' => 3,
            'id'   => (int) $text['id'],
            'kind' => 'text',
            'text' => $text,
        );
    }

    usort($blocks, static function (array $a, array $b): int {
        return array($a['key'], $a['rank'], $a['kind'], $a['id'])
           <=> array($b['key'], $b['rank'], $b['kind'], $b['id']);
    });

    /* ---- 5. walk the book once, emitting pages */
    $pages   = array();
    $history = $historySeed;

    foreach ($blocks as $block) {
        try {
            switch ($block['kind']) {
                case 'snapshot':
                    // Brief §2.3: its own fixed full-page template, always,
                    // regardless of what is happening around it. No slots —
                    // schema.sql: everything the template needs is snapshot_id.
                    $pages[] = array(
                        'page_type'   => 'snapshot',
                        'snapshot_id' => (int) $block['snapshot']['id'],
                        'slots'       => array(),
                    );
                    break;

                case 'fullpage':
                    // Counts toward the variety history: it IS a one-up photo
                    // page as far as a reader's eye is concerned, so a run of
                    // them should push the next ordinary page away from 1-up.
                    $pages[] = array(
                        'page_type'   => 'photos',
                        'snapshot_id' => null,
                        'slots'       => array(array('photo_id' => (int) $block['photo']['id'])),
                    );
                    $history[] = 1;
                    break;

                case 'text':
                    // A page whose only occupant is a quote/anecdote — whether
                    // it got here by being long or by having no page to ride
                    // on. page_type='text' means exactly that, one slot.
                    // Snapshot and text pages do NOT feed the variety history:
                    // they are a different visual language, and a run of them
                    // should not make the next photo page feel obliged to
                    // change size.
                    $pages[] = array(
                        'page_type'   => 'text',
                        'snapshot_id' => null,
                        'slots'       => array(layout_text_slot($block['text'])),
                    );
                    break;

                case 'subgroup':
                    $subgroup = $subgroups[$block['index']];
                    $list     = $cards[$block['index']] ?? array();

                    $orientations = array_map('layout_orientation', $subgroup['photos']);
                    $partition    = layout_partition_subgroup($orientations, count($list), $history, $tuning);

                    $taken = 0;
                    $card  = 0;
                    foreach ($partition as $page) {
                        $slots = array();
                        for ($i = 0; $i < $page['count']; $i++) {
                            $slots[] = array('photo_id' => (int) $subgroup['photos'][$taken + $i]['id']);
                        }
                        $taken += $page['count'];

                        /* The card takes the LAST slot on its page. Which slot
                         * a text card occupies is a rendering decision Phase 6
                         * and Phase 7 own; putting it last keeps the photos in
                         * chronological order within the page, which is the
                         * part this engine actually has an opinion about. */
                        if ($page['card'] && isset($list[$card])) {
                            $slots[] = layout_text_slot($list[$card]);
                            $card++;
                        }

                        if ($slots === array()) {
                            continue;
                        }

                        $isTextOnly = $page['count'] === 0;
                        $pages[] = array(
                            'page_type'   => $isTextOnly ? 'text' : 'photos',
                            'snapshot_id' => null,
                            'slots'       => $slots,
                        );
                        if (!$isTextOnly) {
                            $history[] = count($slots);
                        }
                    }
                    break;
            }
        } catch (Throwable $e) {
            /* Fail soft, per house style: one malformed block (a photo row
             * with no id, a text with an unexpected kind) costs that block its
             * pages, not the whole book. */
            error_log('layout_plan: skipped a ' . $block['kind'] . ' block: ' . $e->getMessage());
        }
    }

    return $pages;
}

/* ==================================================== loading & writing === */

/**
 * Everything in one year that is eligible to appear in a book, shaped for
 * layout_plan(). The ONE place inclusion rules are applied:
 *
 *   - skip_for_book photos are excluded here and nowhere else (brief §4.2:
 *     "all uploaded, non-skipped photos ... are included by default").
 *   - a snapshot's hero photo is excluded from the loose photo flow: it is
 *     already on that snapshot's page, and a photo book that shows the same
 *     picture twice, two pages apart, looks like a bug because it is one.
 *   - year_projects.cover_photo_id is NOT excluded. The cover is not a
 *     book_pages row at all (schema.sql), a cover photo appearing again
 *     inside the book is ordinary in a printed photo book, and silently
 *     deleting a photo from the interior because Kathryn picked it for the
 *     cover would be a surprise. Flagged here rather than decided silently.
 *
 * @param array $exclude array{photo?:list<int>, quote?:list<int>,
 *   anecdote?:list<int>, snapshot?:list<int>} — content already spoken for,
 *   used by layout_reflow_from() to leave the retained pages' content alone.
 */
function layout_load_year_content(int $yearProjectId, array $exclude = array()): array
{
    $skip = static function (array $exclude, string $kind, $id): bool {
        return isset($exclude[$kind]) && in_array((int) $id, $exclude[$kind], true);
    };

    $heroes = array();
    foreach (q(
        'SELECT hero_photo_id FROM snapshots WHERE year_project_id = ? AND hero_photo_id IS NOT NULL',
        array($yearProjectId)
    )->fetchAll() as $row) {
        $heroes[(int) $row['hero_photo_id']] = true;
    }

    $photos = array();
    foreach (q(
        'SELECT * FROM photos
          WHERE year_project_id = ? AND skip_for_book = 0
          ORDER BY captured_at, id',
        array($yearProjectId)
    )->fetchAll() as $photo) {
        if (isset($heroes[(int) $photo['id']]) || $skip($exclude, 'photo', $photo['id'])) {
            continue;
        }
        $photos[] = $photo;
    }

    $texts = array();
    foreach (quotes_for_year($yearProjectId) as $quote) {
        if ($skip($exclude, 'quote', $quote['id'])) {
            continue;
        }
        $texts[] = array(
            'kind' => 'quote',
            'id'   => (int) $quote['id'],
            'text' => (string) $quote['quote_text'],
            'date' => (string) $quote['entry_date'],
        );
    }
    foreach (anecdotes_for_year($yearProjectId) as $anecdote) {
        if ($skip($exclude, 'anecdote', $anecdote['id'])) {
            continue;
        }
        $texts[] = array(
            'kind' => 'anecdote',
            'id'   => (int) $anecdote['id'],
            'text' => (string) $anecdote['anecdote_text'],
            'date' => (string) $anecdote['entry_date'],
        );
    }

    $snapshots = array();
    foreach (snapshots_for_year($yearProjectId) as $snapshot) {
        if ($skip($exclude, 'snapshot', $snapshot['id'])) {
            continue;
        }
        $snapshots[] = $snapshot;
    }

    return array(
        'photos'    => $photos,
        'texts'     => $texts,
        'snapshots' => $snapshots,
        'groups'    => q(
            'SELECT id, start_date, end_date FROM event_groups WHERE year_project_id = ?',
            array($yearProjectId)
        )->fetchAll(),
    );
}

/**
 * Write page specs into an existing layout, numbering from $startPageNumber.
 * Returns how many pages were actually written.
 *
 * A page whose insert fails is skipped WITHOUT consuming a page number, so a
 * single bad page (a slot pointing at a row deleted since the plan was made,
 * say) leaves a shorter book rather than a book with a hole in its numbering
 * — book_pages.uniq_layout_page and Phase 7's export both read page_number as
 * a dense sequence.
 */
function layout_write_pages(int $layoutId, array $pages, int $startPageNumber = 1): int
{
    $pageNumber = max(1, $startPageNumber);
    $written    = 0;

    foreach ($pages as $page) {
        try {
            $pageId = book_page_create(
                $layoutId,
                $pageNumber,
                (string) $page['page_type'],
                $page['snapshot_id'] ?? null
            );

            $slot = 1;
            foreach ($page['slots'] as $occupant) {
                if ($slot > LAYOUT_MAX_SLOTS) {
                    break;
                }
                book_page_slot_create($pageId, $slot, $occupant);
                $slot++;
            }

            $pageNumber++;
            $written++;
        } catch (Throwable $e) {
            error_log('layout_write_pages: skipped page ' . $pageNumber . ': ' . $e->getMessage());
        }
    }

    return $written;
}

/**
 * "Create Book Layout" (brief §5.3) for one year: a NEW book_layouts row every
 * time, never an overwrite (brief §4.5, schema.sql's book_layouts comment), so
 * Kathryn can generate v2, dislike it, and still have v1 exactly as it was.
 *
 * The new layout becomes the year's active one only if there wasn't one
 * already — schema.sql's comment on year_projects.active_book_layout_id is
 * explicit that "most recent" and "the one I'm working from" are different
 * facts, so a regeneration must not yank the version Kathryn is reviewing out
 * from under her. Switching versions is a deliberate act (public/layout.php).
 *
 * @return array{layout_id:int, version:int, pages:int}
 */
function layout_generate(int $yearProjectId): array
{
    $tuning  = layout_tuning();
    $content = layout_load_year_content($yearProjectId);
    $pages   = layout_plan($content, $tuning);

    $layoutId = book_layout_create($yearProjectId);
    $written  = layout_write_pages($layoutId, $pages, 1);

    $project = year_project_get($yearProjectId);
    if ($project !== null && $project['active_book_layout_id'] === null) {
        year_project_set_active_layout($yearProjectId, $layoutId);
    }

    $layout = book_layout_get($layoutId);

    return array(
        'layout_id' => $layoutId,
        'version'   => $layout === null ? 0 : (int) $layout['version'],
        'pages'     => $written,
    );
}

/**
 * Brief §4.5's "reflow from here": regenerate this layout from $fromPageNumber
 * onward, leaving every earlier page exactly as it is — including whatever
 * Kathryn changed by hand there (that is the whole point; §4.5's "local by
 * default" edits live on those pages).
 *
 * The rule that makes it work is simple and worth stating: EVERYTHING ON THE
 * RETAINED PAGES IS SPOKEN FOR. The content on pages 1..N-1 is collected and
 * excluded, the rest of the year is re-planned from scratch, and the result is
 * numbered from N. Two consequences, both correct and both deliberate:
 *
 *   - a photo Kathryn dragged onto page 2 will not turn up again on page 40;
 *   - a photo she dragged OFF page 2 becomes available again and is placed
 *     downstream, rather than vanishing from the book.
 *
 * The variety heuristic is seeded from the retained pages' own sizes, so page
 * N doesn't restart the rhythm as though the book began there.
 *
 * @return array{from:int, kept:int, pages:int}
 */
function layout_reflow_from(int $layoutId, int $fromPageNumber): array
{
    $layout = book_layout_get($layoutId);
    if ($layout === null) {
        throw new InvalidArgumentException('unknown book layout: ' . $layoutId);
    }

    $fromPageNumber = max(1, $fromPageNumber);
    $exclude = array('photo' => array(), 'quote' => array(), 'anecdote' => array(), 'snapshot' => array());
    $history = array();
    $kept    = 0;

    foreach (book_pages_for_layout($layoutId) as $page) {
        if ((int) $page['page_number'] >= $fromPageNumber) {
            continue;
        }
        $kept++;

        if ($page['snapshot_id'] !== null) {
            $exclude['snapshot'][] = (int) $page['snapshot_id'];
        }
        foreach ($page['slots'] as $slot) {
            foreach (array('photo' => 'photo_id', 'quote' => 'quote_id', 'anecdote' => 'anecdote_id') as $kind => $column) {
                if ($slot[$column] !== null) {
                    $exclude[$kind][] = (int) $slot[$column];
                }
            }
        }

        // Same rule as layout_plan()'s own history: photo pages set the
        // rhythm, snapshot and text pages don't.
        if ($page['page_type'] === 'photos') {
            $history[] = count($page['slots']);
        }
    }

    $tuning  = layout_tuning();
    $content = layout_load_year_content((int) $layout['year_project_id'], $exclude);
    $pages   = layout_plan($content, $tuning, $history);

    book_layout_delete_pages_from($layoutId, $fromPageNumber);
    $written = layout_write_pages($layoutId, $pages, $fromPageNumber);

    return array('from' => $fromPageNumber, 'kept' => $kept, 'pages' => $written);
}
