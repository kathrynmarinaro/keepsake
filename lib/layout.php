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
 *   layout_choose_page_size()    how many photos the NEXT page gets
 *   layout_partition_subgroup()  the greedy walk over one page-group
 *
 * GREEDY, NOT GLOBALLY OPTIMAL, ON PURPOSE. A dynamic program could pick the
 * best partition of one page-group, but the variety heuristic is a function of
 * the pages already emitted ACROSS THE WHOLE BOOK — event groups, full-page
 * photos and snapshot pages interleaved — so "optimal within this group" is
 * optimising the wrong thing. Walking the book once in reading order, choosing
 * each page against what a reader has just seen, is both the shape that
 * matches the brief's own description ("across the book") and the shape whose
 * decisions a human can follow one page at a time when retuning.
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
        'density_preference'     => array(1 => 0.35, 2 => 1.0, 3 => 0.95, 4 => 0.85),
        'variety_window'         => 4,
        'variety_repeat_penalty' => 0.18,
        'variety_echo_factor'    => 0.5,
        'orphan_page_penalty'    => 0.35,
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
function layout_orientation_score(array $orientations): float
{
    static $table = array(
        1 => array('1,0' => 0.75, '0,1' => 0.75),
        2 => array('2,0' => 1.00, '0,2' => 1.00, '1,1' => 0.45),
        3 => array('3,0' => 0.90, '2,1' => 0.90, '1,2' => 0.88, '0,3' => 0.70),
        4 => array('4,0' => 0.85, '0,4' => 0.85, '2,2' => 0.92, '3,1' => 0.60, '1,3' => 0.60),
    );

    $density = count($orientations);
    if ($density < 1 || $density > LAYOUT_MAX_SLOTS) {
        return 0.0;
    }

    $portraits = 0;
    $landscapes = 0;
    $flex = 0;
    foreach ($orientations as $orientation) {
        if ($orientation === 'portrait') {
            $portraits++;
        } elseif ($orientation === 'landscape') {
            $landscapes++;
        } else {
            $flex++;
        }
    }

    $best = 0.0;
    for ($toPortrait = 0; $toPortrait <= $flex; $toPortrait++) {
        $key = ($portraits + $toPortrait) . ',' . ($landscapes + $flex - $toPortrait);
        $score = $table[$density][$key] ?? 0.0;
        if ($score > $best) {
            $best = $score;
        }
    }

    return $best;
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
 * How many PHOTOS the next page takes off the front of what's left. Pure.
 *
 * @param array $remaining     orientations of the photos still to place, in order
 * @param array $recentDensities the book so far
 * @param bool  $withCard      is a text card already committed to this page?
 * @return int 1..4 (1..3 with a card), or 0 if there are no photos left
 */
function layout_choose_page_size(array $remaining, array $recentDensities, bool $withCard, array $tuning): int
{
    $left = count($remaining);
    if ($left === 0) {
        return 0;
    }

    $maxPhotos = min(LAYOUT_MAX_SLOTS - ($withCard ? 1 : 0), $left);

    /* Candidates are tried in preference order, and a tie KEEPS THE EARLIER
     * one (strictly-greater below), so 2- and 3-up win coin flips and a
     * single photo only wins when it actually scores higher. */
    $best      = 1;
    $bestScore = -INF;

    foreach (array(2, 3, 4, 1) as $size) {
        if ($size > $maxPhotos) {
            continue;
        }

        $orientations = array_slice($remaining, 0, $size);
        if ($withCard) {
            $orientations[] = 'flex';
        }

        $score = layout_page_score($orientations, $recentDensities, $tuning);

        /* Lookahead, one page deep and no further: a size that strands
         * exactly one photo at the end of this page-group is how a stray
         * orphan page happens. Cheaper and far easier to retune than a real
         * multi-page search, and it catches the case that actually shows up. */
        if ($left - $size === 1) {
            $score -= (float) $tuning['orphan_page_penalty'];
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $size;
        }
    }

    return $best;
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
 * Deliberately assumes the busiest ordinary page (3), which UNDER-estimates:
 * an underestimate means a card scheduled for "page 4" of a group that turns
 * out to have 5 pages simply lands one page early, while an overestimate
 * would schedule cards onto pages that never get emitted and spill them onto
 * pages of their own.
 */
function layout_estimate_page_count(int $photoCount): int
{
    if ($photoCount <= 0) {
        return 0;
    }
    return max(1, (int) ceil($photoCount / 3));
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
 * The greedy walk over one page-group: how many photos on each page, and
 * which pages carry a text card. Pure — no DB, no photo rows, just
 * orientations in order — so a test can assert the SHAPE of a book without
 * constructing one.
 *
 * @param array $orientations     one per photo, chronological
 * @param int   $cardCount        short texts to weave into this group
 * @param array $recentDensities  pages already emitted in the book, oldest first
 * @return list<array{count:int, card:bool}> in page order. count 0 with
 *   card true is a text that found no page to ride on and needs one of its own.
 */
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

    $schedule = array_flip(layout_card_schedule(layout_estimate_page_count($total), $cardsLeft));

    $history   = $recentDensities;
    $placed    = 0;
    $pageIndex = 0;

    while ($placed < $total) {
        $withCard = $cardsLeft > 0 && isset($schedule[$pageIndex]);

        $size = layout_choose_page_size(
            array_slice($orientations, $placed),
            $history,
            $withCard,
            $tuning
        );
        // layout_choose_page_size() cannot return 0 while photos remain; the
        // guard is here so a future edit to it can't turn this into a spin.
        $size = max(1, $size);

        $pages[] = array('count' => $size, 'card' => $withCard);

        if ($withCard) {
            $cardsLeft--;
        }
        $history[] = $size + ($withCard ? 1 : 0);
        $placed   += $size;
        $pageIndex++;
    }

    // Cards the schedule never reached: fewer real pages than estimated, or
    // more cards than this group has pages for. They get pages of their own
    // rather than being dropped or doubled up.
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
        foreach (layout_subgroup_photos($rows, (float) $tuning['subgroup_gap_hours']) as $cluster) {
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
