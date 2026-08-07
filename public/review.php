<?php
/* Desktop review/browse screen — brief §5.2, Phase 3 (see PLAN.md).
 *
 * public/review.php?year=YYYY&view=timeline|grid|groups[&type=quote|anecdote|snapshot|photo|all]
 *
 * Three views behind one query string, all reading the SAME data fetched
 * once at the top of this file — no separate views/partials layer, matching
 * every other screen in this app:
 *
 *   timeline (default) — every quote/anecdote/snapshot/photo for the year,
 *     chronological, grouped by month. The default per brief §5.2.
 *   grid    — flat, filterable by type (brief: "flat list/grid view,
 *     filterable by type"). Filtering to Photo renders an actual thumbnail
 *     grid with the skip_for_book/full_page toggles visible at a glance
 *     (the exit criterion) plus an "Edit photos" list below it reusing the
 *     identical per-photo accordion as Timeline. Filtering to any other
 *     type renders a flat, ungrouped list of that type's accordions.
 *   groups  — event-group review: rename/merge/split (brief §4.1/§5.2).
 *     Phase 4's auto-detection hasn't run (see PLAN.md), so this table is
 *     usually empty; a "New group" form makes manual creation possible,
 *     which is the only way a group exists to review before Phase 4 does.
 *
 * FULL EDIT ON EVERY ENTRY (brief §5.2's exit criterion) is one PHP-rendered
 * <details class="accordion"> per entry, summary collapsed / body a real
 * <form> with every field Section 2 defines for that content type. Four
 * render_entry_*() functions below build that markup once and are reused
 * across all three views rather than duplicated per view.
 *
 * DELETE IS A PLAIN BUTTON + CONFIRM, NOT SWIPE-TO-DELETE. swipe.js's
 * swipe-past-threshold-and-release-with-undo gesture (ported in Phase 0) is,
 * by personal-cms's own written convention (see that app's CLAUDE.md), a fit
 * for a low-stakes, quickly-retyped item on a phone held one-handed — not
 * for a desktop screen (brief §5.2: "Primarily desktop") deleting an entry
 * that can carry a caption, a crop, and a date nobody wants to retype from
 * memory. review.js's delete handler asks window.confirm() before ever
 * calling an -delete.php endpoint; there is no undo.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';

require_login_page();

/* ---------------------------------------------------------------- inputs */

$year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$view = in_array($_GET['view'] ?? '', array('timeline', 'grid', 'groups'), true) ? $_GET['view'] : 'timeline';
$type = in_array($_GET['type'] ?? '', array('all', 'quote', 'anecdote', 'snapshot', 'photo'), true)
    ? $_GET['type'] : 'photo';

$project = $year > 0 ? year_project_get_by_year($year) : null;

/* ------------------------------------------------------------- rendering */

/** First ~70 chars of a run of text, for an accordion's collapsed summary. */
function snippet(string $text, int $len = 70): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}

/** 'March 2026' from a 'Y-m' key. Pure string work, no date-arithmetic risk. */
function month_label(string $ymKey): string
{
    [$y, $m] = explode('-', $ymKey);
    $names = array(
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    );
    return ($names[(int) $m] ?? $m) . ' ' . $y;
}

function fmt_date_human(string $ymd): string
{
    $ts = strtotime($ymd);
    return $ts === false ? $ymd : date('M j, Y', $ts);
}

function render_entry_quote(array $q): string
{
    ob_start();
    $id = (int) $q['id'];
    ?>
    <details class="accordion entry" data-type="quote" data-id="<?= $id ?>">
      <summary class="accordion-head">
        <span class="pill">Quote</span>
        <span class="entry-summary-text"><?= h(snippet($q['quote_text'])) ?></span>
        <span class="accordion-count"><?= h(fmt_date_human($q['entry_date'])) ?></span>
      </summary>
      <div class="accordion-body">
        <form class="stack" data-role="entry-form">
          <label class="field">
            <span>What was said</span>
            <textarea name="quote_text" rows="2" maxlength="2000" required><?= h($q['quote_text']) ?></textarea>
          </label>
          <label class="field">
            <span>Who said it</span>
            <select name="who_said_it">
              <option value="Kathryn"<?= $q['who_said_it'] === 'Kathryn' ? ' selected' : '' ?>>Kathryn</option>
              <option value="Emma"<?= $q['who_said_it'] === 'Emma' ? ' selected' : '' ?>>Emma</option>
            </select>
          </label>
          <label class="field">
            <span>Date</span>
            <input type="date" name="entry_date" value="<?= h($q['entry_date']) ?>" required>
          </label>
          <p class="field-err" data-role="error"></p>
          <div class="row-between entry-actions">
            <button type="button" class="btn-danger" data-act="delete">Delete</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </form>
      </div>
    </details>
    <?php
    return ob_get_clean();
}

function render_entry_anecdote(array $a): string
{
    ob_start();
    $id = (int) $a['id'];
    ?>
    <details class="accordion entry" data-type="anecdote" data-id="<?= $id ?>">
      <summary class="accordion-head">
        <span class="pill">Anecdote</span>
        <span class="entry-summary-text"><?= h(snippet($a['anecdote_text'])) ?></span>
        <span class="accordion-count"><?= h(fmt_date_human($a['entry_date'])) ?></span>
      </summary>
      <div class="accordion-body">
        <form class="stack" data-role="entry-form">
          <label class="field">
            <span>What happened</span>
            <textarea name="anecdote_text" rows="3" maxlength="4000" required><?= h($a['anecdote_text']) ?></textarea>
          </label>
          <label class="field">
            <span>Date</span>
            <input type="date" name="entry_date" value="<?= h($a['entry_date']) ?>" required>
          </label>
          <p class="field-err" data-role="error"></p>
          <div class="row-between entry-actions">
            <button type="button" class="btn-danger" data-act="delete">Delete</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </form>
      </div>
    </details>
    <?php
    return ob_get_clean();
}

function render_entry_snapshot(array $s): string
{
    ob_start();
    $id = (int) $s['id'];
    $isBirthday = $s['type'] === 'birthday';
    $heroLabel = $s['hero_photo_id'] ? ('Hero photo: #' . (int) $s['hero_photo_id']) : '';

    $bits = array();
    if ($isBirthday) {
        if ($s['age'] !== null) { $bits[] = 'Age ' . $s['age']; }
        if ($s['height']) { $bits[] = (string) $s['height']; }
    } else {
        if ($s['grade']) { $bits[] = (string) $s['grade']; }
        if ($s['school']) { $bits[] = (string) $s['school']; }
    }
    $summaryText = $bits !== array() ? implode(' · ', $bits) : ($isBirthday ? 'Birthday' : 'School year');
    ?>
    <details class="accordion entry" data-type="snapshot" data-id="<?= $id ?>">
      <summary class="accordion-head">
        <span class="pill"><?= $isBirthday ? 'Birthday' : 'School year' ?></span>
        <span class="entry-summary-text"><?= h($summaryText) ?></span>
        <span class="accordion-count"><?= h(fmt_date_human($s['entry_date'])) ?></span>
      </summary>
      <div class="accordion-body">
        <form class="stack" data-role="entry-form">
          <label class="field">
            <span>Date</span>
            <input type="date" name="entry_date" value="<?= h($s['entry_date']) ?>" required>
          </label>

          <?php if ($isBirthday): ?>
            <label class="field"><span>Age</span>
              <input type="number" name="age" min="0" max="130" value="<?= h((string) ($s['age'] ?? '')) ?>">
            </label>
            <label class="field"><span>Height</span>
              <input type="text" name="height" value="<?= h((string) ($s['height'] ?? '')) ?>">
            </label>
          <?php else: ?>
            <label class="field"><span>Grade</span><input type="text" name="grade" value="<?= h((string) ($s['grade'] ?? '')) ?>"></label>
            <label class="field"><span>School</span><input type="text" name="school" value="<?= h((string) ($s['school'] ?? '')) ?>"></label>
            <label class="field"><span>Teacher</span><input type="text" name="teacher" value="<?= h((string) ($s['teacher'] ?? '')) ?>"></label>
            <label class="field"><span>Favorite color</span><input type="text" name="favorite_color" value="<?= h((string) ($s['favorite_color'] ?? '')) ?>"></label>
            <label class="field"><span>Dream job</span><input type="text" name="dream_job" value="<?= h((string) ($s['dream_job'] ?? '')) ?>"></label>
            <label class="field"><span>Favorite class</span><input type="text" name="favorite_class" value="<?= h((string) ($s['favorite_class'] ?? '')) ?>"></label>
          <?php endif; ?>

          <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="3"><?= h((string) ($s['notes'] ?? '')) ?></textarea>
          </label>

          <div class="field">
            <span>Hero photo</span>
            <input type="hidden" name="hero_photo_id" value="<?= h((string) ($s['hero_photo_id'] ?? '')) ?>">
            <button type="button" class="btn-ghost" data-act="pick-hero"><?= $s['hero_photo_id'] ? 'Change hero photo' : 'Choose hero photo (optional)' ?></button>
            <p class="hint" data-role="hero-chosen"<?= $heroLabel === '' ? ' hidden' : '' ?>><?= h($heroLabel) ?></p>
          </div>

          <p class="field-err" data-role="error"></p>
          <div class="row-between entry-actions">
            <button type="button" class="btn-danger" data-act="delete">Delete</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </form>
      </div>
    </details>
    <?php
    return ob_get_clean();
}

/**
 * @param array $eventGroups this year's event_groups rows, for the manual
 *   assignment <select> — see lib/repo.php's event_group_create() header for
 *   why this is the only way a photo gets grouped before Phase 4 exists.
 */
function render_entry_photo(array $p, array $eventGroups): string
{
    ob_start();
    $id = (int) $p['id'];
    $entryDate = substr((string) $p['captured_at'], 0, 10);
    $skip = (bool) $p['skip_for_book'];
    $full = (bool) $p['full_page'];
    $thumb = h((string) ($p['thumb_path'] ?: $p['original_path']));
    $original = h((string) $p['original_path']);
    ?>
    <details class="accordion entry" data-type="photo" data-id="<?= $id ?>" id="entry-photo-<?= $id ?>">
      <summary class="accordion-head">
        <img class="entry-summary-thumb" src="<?= $thumb ?>" alt="" data-role="thumb">
        <span class="pill">Photo</span>
        <span class="entry-summary-text"><?= h($p['caption'] !== null && $p['caption'] !== '' ? snippet((string) $p['caption'], 40) : '(no caption)') ?></span>
        <button type="button" class="pill pill-toggle<?= $skip ? ' is-plain' : '' ?>" data-act="toggle-skip" data-id="<?= $id ?>" data-value="<?= $skip ? '1' : '0' ?>">
          <?= $skip ? 'Skipped' : 'In book' ?>
        </button>
        <button type="button" class="pill pill-toggle<?= $full ? '' : ' is-plain' ?>" data-act="toggle-full" data-id="<?= $id ?>" data-value="<?= $full ? '1' : '0' ?>">
          <?= $full ? 'Full page' : 'Full page: off' ?>
        </button>
        <span class="accordion-count"><?= h(fmt_date_human($entryDate)) ?></span>
      </summary>
      <div class="accordion-body">
        <form class="stack" data-role="entry-form">
          <label class="field">
            <span>Caption</span>
            <textarea name="caption" rows="2"><?= h((string) ($p['caption'] ?? '')) ?></textarea>
          </label>
          <label class="field">
            <span>Location</span>
            <input type="text" name="location_text" value="<?= h((string) ($p['location_text'] ?? '')) ?>">
          </label>
          <label class="field">
            <span>Date</span>
            <input type="date" name="entry_date" value="<?= h($entryDate) ?>" required>
          </label>
          <label class="field">
            <span>Event group</span>
            <select name="event_group_id">
              <option value="">— none —</option>
              <?php foreach ($eventGroups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"<?= (int) ($p['event_group_id'] ?? 0) === (int) $g['id'] ? ' selected' : '' ?>>
                  <?= h($g['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="field">
            <span>Crop</span>
            <button type="button" class="btn-ghost" data-act="recrop" data-src="<?= $original ?>">Recrop</button>
          </div>

          <p class="field-err" data-role="error"></p>
          <div class="row-between entry-actions">
            <button type="button" class="btn-danger" data-act="delete">Delete</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </form>
      </div>
    </details>
    <?php
    return ob_get_clean();
}

/** The photo-grid overview cell — read-mostly, the two flag toggles live. */
function render_photo_cell(array $p): string
{
    ob_start();
    $id = (int) $p['id'];
    $skip = (bool) $p['skip_for_book'];
    $full = (bool) $p['full_page'];
    $thumb = h((string) ($p['thumb_path'] ?: $p['original_path']));
    $entryDate = substr((string) $p['captured_at'], 0, 10);
    ?>
    <button type="button" class="photo-cell<?= $skip ? ' is-skipped' : '' ?><?= $full ? ' is-full-page' : '' ?>"
            data-act="jump-to-photo" data-id="<?= $id ?>">
      <img class="thumb" src="<?= $thumb ?>" alt="">
      <span class="photo-cell-date"><?= h(fmt_date_human($entryDate)) ?></span>
      <span class="photo-cell-bar">
        <span class="pill pill-toggle<?= $skip ? ' is-plain' : '' ?>" data-act="toggle-skip" data-id="<?= $id ?>" data-value="<?= $skip ? '1' : '0' ?>">
          <?= $skip ? 'Skipped' : 'In book' ?>
        </span>
        <span class="pill pill-toggle<?= $full ? '' : ' is-plain' ?>" data-act="toggle-full" data-id="<?= $id ?>" data-value="<?= $full ? '1' : '0' ?>">
          <?= $full ? 'Full page' : 'Full page: off' ?>
        </span>
      </span>
    </button>
    <?php
    return ob_get_clean();
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake — <?= $project ? h((string) $project['year']) : 'Review' ?></title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body data-year-project-id="<?= $project ? (int) $project['id'] : '' ?>">
<main class="wrap">
  <header class="screen-head">
    <h1><?= $project ? h((string) $project['year']) : 'Review' ?></h1>
    <div class="head-actions">
      <?php if ($project !== null): ?>
        <!-- Phase 5's generated layouts for this year. Review comes first —
             skip-for-book, full-page flags and event groups all change what
             the arrangement engine does — so this is a link out, not a tab. -->
        <a class="link-btn" href="layout.php?year=<?= h((string) $project['year']) ?>">Book layouts</a>
      <?php endif; ?>
      <a class="link-btn" href="index.php">Years</a>
    </div>
  </header>

  <?php if ($project === null): ?>
    <div class="empty">
      <p><?= $year > 0 ? h((string) $year) . ' has no year_projects row yet.' : 'No year specified.' ?></p>
      <p class="hint">A year is created automatically the first time something
      is dated into it — add a quote, anecdote, snapshot or photo dated in
      this year from the Add tab, then come back.</p>
    </div>
  <?php else:
      $yearProjectId = (int) $project['id'];
      $quotes    = quotes_for_year($yearProjectId);
      $anecdotes = anecdotes_for_year($yearProjectId);
      $snapshots = snapshots_for_year($yearProjectId);
      $photos    = photos_for_year($yearProjectId);
      $groups    = event_groups_for_year($yearProjectId);
  ?>

    <!-- Pre-layout subtitle (brief §4.6). Tap-to-edit via inline-edit.js,
         same gesture as every other tap-to-edit field in the suite. -->
    <ul class="list" id="subtitle-list">
      <li class="list-row" data-id="<?= $yearProjectId ?>">
        <div class="row-slide">
          <div class="row-body">
            <span class="row-sub">Book subtitle</span>
            <span class="row-text<?= $project['subtitle'] ? '' : ' muted' ?>" data-role="subtitle"><?= h($project['subtitle'] ?: 'Tap to add a subtitle…') ?></span>
          </div>
        </div>
      </li>
    </ul>

    <div class="row view-tabs" aria-label="View" role="tablist">
      <a class="pill<?= $view === 'timeline' ? '' : ' is-plain' ?>" href="review.php?year=<?= $year ?>&amp;view=timeline">Timeline</a>
      <a class="pill<?= $view === 'grid' ? '' : ' is-plain' ?>" href="review.php?year=<?= $year ?>&amp;view=grid&amp;type=<?= h($type) ?>">Grid</a>
      <a class="pill<?= $view === 'groups' ? '' : ' is-plain' ?>" href="review.php?year=<?= $year ?>&amp;view=groups">Groups</a>
    </div>

    <?php if ($view === 'timeline'): ?>

      <?php
      $entries = array();
      foreach ($quotes as $q) { $entries[] = array('date' => $q['entry_date'], 'order' => 0, 'html' => render_entry_quote($q)); }
      foreach ($anecdotes as $a) { $entries[] = array('date' => $a['entry_date'], 'order' => 1, 'html' => render_entry_anecdote($a)); }
      foreach ($snapshots as $s) { $entries[] = array('date' => $s['entry_date'], 'order' => 2, 'html' => render_entry_snapshot($s)); }
      foreach ($photos as $p) { $entries[] = array('date' => substr((string) $p['captured_at'], 0, 10), 'order' => 3, 'html' => render_entry_photo($p, $groups)); }

      usort($entries, static function (array $a, array $b): int {
          return $a['date'] <=> $b['date'] ?: $a['order'] <=> $b['order'];
      });

      $byMonth = array();
      foreach ($entries as $e) {
          $byMonth[substr($e['date'], 0, 7)][] = $e;
      }
      ?>

      <?php if ($entries === array()): ?>
        <div class="empty">
          <p>Nothing captured for <?= h((string) $year) ?> yet.</p>
        </div>
      <?php else: ?>
        <div id="entry-list">
        <?php foreach ($byMonth as $monthKey => $monthEntries): ?>
          <div class="cat-group">
            <div class="cat-head">
              <span><?= h(month_label($monthKey)) ?></span>
              <span class="cat-count"><?= count($monthEntries) ?></span>
            </div>
            <?php foreach ($monthEntries as $e) { echo $e['html']; } ?>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'grid'): ?>

      <div class="row type-filter-row" aria-label="Filter by type">
        <?php foreach (array('all' => 'All', 'quote' => 'Quote', 'anecdote' => 'Anecdote', 'snapshot' => 'Snapshot', 'photo' => 'Photo') as $t => $label): ?>
          <a class="pill<?= $type === $t ? '' : ' is-plain' ?>" href="review.php?year=<?= $year ?>&amp;view=grid&amp;type=<?= $t ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <?php if ($type === 'photo'): ?>
        <?php if ($photos === array()): ?>
          <div class="empty"><p>No photos captured for <?= h((string) $year) ?> yet.</p></div>
        <?php else: ?>
          <div class="photo-grid" id="photo-grid">
            <?php foreach ($photos as $p) { echo render_photo_cell($p); } ?>
          </div>
          <h2>Edit photos</h2>
          <div id="entry-list">
            <?php foreach ($photos as $p) { echo render_entry_photo($p, $groups); } ?>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <?php
        $flat = array();
        if ($type === 'all' || $type === 'quote') {
            foreach ($quotes as $q) { $flat[] = array('date' => $q['entry_date'], 'html' => render_entry_quote($q)); }
        }
        if ($type === 'all' || $type === 'anecdote') {
            foreach ($anecdotes as $a) { $flat[] = array('date' => $a['entry_date'], 'html' => render_entry_anecdote($a)); }
        }
        if ($type === 'all' || $type === 'snapshot') {
            foreach ($snapshots as $s) { $flat[] = array('date' => $s['entry_date'], 'html' => render_entry_snapshot($s)); }
        }
        if ($type === 'all') {
            foreach ($photos as $p) { $flat[] = array('date' => substr((string) $p['captured_at'], 0, 10), 'html' => render_entry_photo($p, $groups)); }
        }
        usort($flat, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);
        ?>
        <?php if ($flat === array()): ?>
          <div class="empty"><p>Nothing here yet for <?= h((string) $year) ?>.</p></div>
        <?php else: ?>
          <div id="entry-list"><?php foreach ($flat as $e) { echo $e['html']; } ?></div>
        <?php endif; ?>
      <?php endif; ?>

    <?php else: /* groups */ ?>

      <div class="card">
        <p><strong>Automatic grouping</strong></p>
        <p class="hint">
          Clusters any ungrouped photos by date gap (currently
          <?= (int) cfg('grouping.gap_days', 3) ?> days between shots) and
          looks up a location for each new or extended group. Only touches
          photos with no event group yet — anything already grouped, by a
          prior run or by hand, is left alone. Clear a photo's "Event group"
          field (its own edit panel) to make it eligible again.
        </p>
        <button type="button" class="btn-secondary" id="run-grouping-btn">Group photos</button>
      </div>

      <p class="hint">
        Rename, merge or split any group below — auto-grouping never
        overwrites a name you've set by hand.
      </p>

      <details class="accordion" open>
        <summary class="accordion-head">New group</summary>
        <div class="accordion-body">
          <form class="stack" id="new-group-form">
            <label class="field"><span>Name</span><input type="text" name="name" required></label>
            <label class="field"><span>Start date</span><input type="date" name="start_date" required></label>
            <label class="field"><span>End date</span><input type="date" name="end_date" required></label>
            <label class="field"><span>Location (optional)</span><input type="text" name="location_name"></label>
            <p class="field-err" data-role="error"></p>
            <button type="submit" class="btn-primary">Create group</button>
          </form>
        </div>
      </details>

      <?php if ($groups === array()): ?>
        <div class="empty"><p>No event groups yet for <?= h((string) $year) ?>.</p></div>
      <?php else: ?>
        <div id="group-list">
          <?php foreach ($groups as $g):
              $groupId = (int) $g['id'];
              $members = array_values(array_filter($photos, static fn(array $p): bool => (int) ($p['event_group_id'] ?? 0) === $groupId));
          ?>
            <div class="card group-row" data-id="<?= $groupId ?>">
              <div class="row-between group-row-actions">
                <div class="row">
                  <!-- Not wrapped in a <label> with the name below it: a
                       <label> forwards a click ANYWHERE inside it to its
                       control, and the name needs its own unshared tap
                       target for inline-edit.js's rename gesture — sharing
                       one would make renaming also toggle this checkbox. -->
                  <input type="checkbox" data-role="merge-select" class="merge-checkbox" aria-label="Select &ldquo;<?= h($g['name']) ?>&rdquo; to merge">
                  <div>
                    <ul class="list group-name-list">
                      <li class="list-row" data-id="<?= $groupId ?>">
                        <div class="row-slide">
                          <span class="row-text" data-role="group-name"><?= h($g['name']) ?></span>
                        </div>
                      </li>
                    </ul>
                    <span class="hint">
                      <?= h(fmt_date_human($g['start_date'])) ?>–<?= h(fmt_date_human($g['end_date'])) ?>
                      <?= $g['location_name'] ? ' · ' . h($g['location_name']) : '' ?>
                      · <?= (int) $g['photo_count'] ?> photo<?= (int) $g['photo_count'] === 1 ? '' : 's' ?>
                    </span>
                  </div>
                </div>
                <button type="button" class="btn-danger" data-act="delete-group" data-id="<?= $groupId ?>">Ungroup</button>
              </div>

              <?php if ($members !== array()): ?>
                <details class="accordion">
                  <summary class="accordion-head">Split…</summary>
                  <div class="accordion-body">
                  <form class="stack split-form" data-group-id="<?= $groupId ?>">
                    <div class="group-photos">
                      <?php foreach ($members as $m): ?>
                        <label>
                          <input type="checkbox" name="photo_ids[]" value="<?= (int) $m['id'] ?>">
                          <img class="thumb" src="<?= h((string) ($m['thumb_path'] ?: $m['original_path'])) ?>" alt="">
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <label class="field"><span>New group name</span><input type="text" name="new_name" required></label>
                    <p class="field-err" data-role="error"></p>
                    <button type="submit" class="btn-secondary">Split selected into a new group</button>
                  </form>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card">
          <p><strong>Merge selected groups</strong></p>
          <label class="field">
            <span>Into</span>
            <select id="merge-target">
              <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>"><?= h($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" class="btn-secondary" id="merge-btn">Merge checked groups into this one</button>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  <?php endif; ?>
</main>

<nav class="tabbar">
  <a href="index.php">Years</a>
  <a href="capture.php">Add</a>
</nav>

<script type="module" src="<?= asset('assets/review.js') ?>"></script>
</body>
</html>
