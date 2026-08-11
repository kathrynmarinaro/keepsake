<?php
/* The Content tab of a project screen (public/project.php?tab=content).
 *
 * This was public/review.php, whole. It is a VIEW rather than an entry point
 * now: public/project.php resolves the project, renders the chrome and the tab
 * strip, and requires this file with $project already in scope and guaranteed
 * non-null. Everything below the inputs is unchanged from the screen it came
 * from — the merge moved code, it did not rewrite it.
 *
 * WHY THE MERGE. Content and Book were two screens with two URLs, and moving
 * between them meant going up to the project list and back down. They are two
 * views of one project, which is what the tab strip now says.
 *
 * $project is provided by the caller. Nothing here may assume a `year`: a
 * project can be a trip rather than a calendar year (schema.sql on
 * year_projects.year), so anything printing an identity for the book goes
 * through year_project_title().
 */

declare(strict_types=1);

/* Belt and braces: this file is required, never requested. Without this a
   misconfigured server that serves lib/ would run it with no $project. */
if (!isset($project) || !is_array($project)) {
    http_response_code(404);
    exit;
}

/* For event_grouping_internal_gaps()/event_grouping_gap_days() — the Groups
 * type asks whether each group would still be clustered the way it is, which
 * has to be the same threshold the grouper itself uses.
 *
 * REQUIRED HERE, not by public/project.php. A view pulls in what it calls: the
 * screen this came from did exactly this, and losing the require in the move
 * left the Groups view fatally erroring on a function that was never loaded. */
require_once __DIR__ . '/../grouping.php';

/* Named once, here. Everything below prints the book's own name rather than
   its year, because a project may not have one — year_project_title() is the
   single place that decides what a book is called. */
$projectId   = (int) $project['id'];
$projectName = year_project_title($project);

/* ---------------------------------------------------------------- inputs */

/* VIEW is how the content is arranged; TYPE is what is in it. They used to be
 * tangled: "groups" was a third VIEW, so it could not be combined with either
 * of the other two, and the type filter only existed inside the grid — switch
 * to Timeline and it silently went away along with whatever it was set to.
 *
 * Now they are two independent axes, both always on screen. Groups is a TYPE,
 * because that is what it is: a way of looking at the photos, not a different
 * arrangement of the page.
 *
 * Grid is the default view — it is what she opens this tab to do. Type
 * defaults to 'all' rather than to 'photo': with the filter permanently
 * visible, a default that hides three of the four content types without
 * saying so is a filter you have to notice before you trust the screen. */
$view = ($_GET['view'] ?? '') === 'timeline' ? 'timeline' : 'grid';
$type = in_array($_GET['type'] ?? '', array('all', 'photo', 'snapshot', 'quote', 'anecdote', 'groups'), true)
    ? $_GET['type'] : 'all';

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

/* ---------------------------------------------------------- entry bodies
 *
 * The edit form for one entry, on its own, so the two collapsed shapes that
 * can hold it — a full-width row and a grid cell — share exactly one copy.
 *
 * They did not, before. render_entry_photo() and render_photo_cell() each
 * carried their own transcription of the same four fields, which is what
 * PLAN.md's "there is exactly one markup for editing a photo" was supposed to
 * mean and did not. Adding grid cells for the other three types would have
 * made that four duplications instead of one, so the form came out first.
 */

function render_quote_body(array $q): string
{
    ob_start();
    ?>
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
          <?php /* Cancel closes the modal without saving. Only meaningful
                   once entry-modal.js has one open, so it is hidden until
                   then — a Cancel button on an inline accordion would be a
                   third word for "collapse this", next to the summary you
                   can already tap. */ ?>
          <div class="row entry-actions-right">
            <button type="button" class="btn-ghost entry-cancel" data-act="cancel" hidden>Cancel</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </div>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

function render_anecdote_body(array $a): string
{
    ob_start();
    ?>
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
          <?php /* Cancel closes the modal without saving. Only meaningful
                   once entry-modal.js has one open, so it is hidden until
                   then — a Cancel button on an inline accordion would be a
                   third word for "collapse this", next to the summary you
                   can already tap. */ ?>
          <div class="row entry-actions-right">
            <button type="button" class="btn-ghost entry-cancel" data-act="cancel" hidden>Cancel</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </div>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

function render_snapshot_body(array $s): string
{
    ob_start();
    /* Derived here rather than passed in: this function is called from a row
       summary and from a grid cell, and a body that depends on its caller
       having computed the right locals first is the bug that came out of
       splitting it off. */
    $isBirthday = $s['type'] === 'birthday';
    $heroLabel  = $s['hero_photo_id'] ? ('Hero photo: #' . (int) $s['hero_photo_id']) : '';
    ?>
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
          <?php /* Cancel closes the modal without saving. Only meaningful
                   once entry-modal.js has one open, so it is hidden until
                   then — a Cancel button on an inline accordion would be a
                   third word for "collapse this", next to the summary you
                   can already tap. */ ?>
          <div class="row entry-actions-right">
            <button type="button" class="btn-ghost entry-cancel" data-act="cancel" hidden>Cancel</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </div>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

function render_photo_body(array $p, array $eventGroups): string
{
    ob_start();
    /* Same reasoning as render_snapshot_body(): self-contained, because two
       different collapsed shapes call it. */
    $entryDate = substr((string) $p['captured_at'], 0, 10);
    ?>
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

        <p class="field-err" data-role="error"></p>
        <div class="row-between entry-actions">
          <button type="button" class="btn-danger" data-act="delete">Delete</button>
          <?php /* Cancel closes the modal without saving. Only meaningful
                   once entry-modal.js has one open, so it is hidden until
                   then — a Cancel button on an inline accordion would be a
                   third word for "collapse this", next to the summary you
                   can already tap. */ ?>
          <div class="row entry-actions-right">
            <button type="button" class="btn-ghost entry-cancel" data-act="cancel" hidden>Cancel</button>
            <button type="submit" class="btn-primary">Save</button>
          </div>
        </div>
      </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * One NON-PHOTO entry as a grid cell.
 *
 * WHY THIS EXISTS. "Grid" used to mean a grid only for photos; every other
 * type fell through to a stack of full-width rows, which is a timeline with
 * the month headings taken off. That was survivable while the default type was
 * Photos and you had to choose your way into the other case. Once the filters
 * became permanent and the default became All, it was the first thing on the
 * screen — and Kathryn reported it as exactly what it looked like: "the Grid
 * view is showing a timeline view".
 *
 * Grid now means grid for every type. This is the text equivalent of
 * render_photo_cell(): the same <details>, the same data attributes, the same
 * shared body — only the collapsed shape differs, which is the same split
 * render_photo_cell()'s header already describes for photos.
 *
 * @param string $kind 'quote' | 'anecdote' | 'snapshot'
 * @param string $label The pill.
 * @param string $text  The line of content to preview.
 */
function render_entry_cell(array $row, string $kind, string $label, string $text, string $date): string
{
    ob_start();
    $id = (int) $row['id'];

    $body = '';
    if ($kind === 'quote') {
        $body = render_quote_body($row);
    } elseif ($kind === 'anecdote') {
        $body = render_anecdote_body($row);
    } else {
        $body = render_snapshot_body($row);
    }
    ?>
    <details class="accordion entry text-cell-details" data-type="<?= h($kind) ?>" data-id="<?= $id ?>" id="entry-<?= h($kind) ?>-<?= $id ?>">
      <summary class="text-cell-head">
        <span class="pill<?= $kind === 'snapshot' ? '' : ' is-plain' ?>"><?= h($label) ?></span>
        <span class="text-cell-text"><?= h($text !== '' ? $text : '(empty)') ?></span>
        <span class="text-cell-date"><?= h(fmt_date_human($date)) ?></span>
      </summary>
      <?= $body ?>
    </details>
    <?php
    return ob_get_clean();
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
      <?= render_quote_body($q) ?>
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
      <?= render_anecdote_body($a) ?>
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
      <?= render_snapshot_body($s) ?>
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
        <?php /* The same three actions as the grid cell's, in the same order.
                 This is the other collapsed shape for the same photo — see
                 render_photo_cell()'s header — so they have to agree about
                 what you can do to it. */ ?>
        <button type="button" class="pill pill-toggle" data-act="recrop" data-src="<?= $original ?>">Recrop</button>
        <span class="accordion-count"><?= h(fmt_date_human($entryDate)) ?></span>
      </summary>
      <?= render_photo_body($p, $eventGroups) ?>
    </details>
    <?php
    return ob_get_clean();
}

/**
 * The photo-grid overview cell — SAME element as the edit accordion, not a
 * separate read-only preview linking to one elsewhere. Post-launch feedback
 * (see PLAN.md): tapping a grid cell used to scroll to a duplicate
 * accordion in a separate "Edit photos" list further down the page, which
 * meant losing your place in the grid every time you edited one photo.
 * This is a <details>, same as render_entry_photo(), same id/data
 * attributes so every review.js handler that does .closest('.entry') still
 * finds it — only the SUMMARY's layout differs (compact, image-forward,
 * grid-cell-shaped when collapsed, vs. render_entry_photo()'s horizontal
 * list row for Timeline/the "all types" grid). The edit form in the body
 * is identical either way; there is exactly one markup for "edit a photo",
 * per PLAN.md's original review-screen design goal — this only changes
 * what the COLLAPSED state looks like in this one context.
 */
function render_photo_cell(array $p, array $eventGroups): string
{
    ob_start();
    $id = (int) $p['id'];
    $entryDate = substr((string) $p['captured_at'], 0, 10);
    $skip = (bool) $p['skip_for_book'];
    $full = (bool) $p['full_page'];
    $thumb = h((string) ($p['thumb_path'] ?: $p['original_path']));
    $original = h((string) $p['original_path']);
    ?>
    <details class="accordion entry photo-cell-details<?= $skip ? ' is-skipped' : '' ?><?= $full ? ' is-full-page' : '' ?>"
              data-type="photo" data-id="<?= $id ?>" id="entry-photo-<?= $id ?>">
      <summary class="photo-cell-head">
        <img class="thumb" src="<?= $thumb ?>" alt="" data-role="thumb">
        <span class="photo-cell-date accordion-count"><?= h(fmt_date_human($entryDate)) ?></span>
        <span class="photo-cell-bar">
          <button type="button" class="pill pill-toggle<?= $skip ? ' is-plain' : '' ?>" data-act="toggle-skip" data-id="<?= $id ?>" data-value="<?= $skip ? '1' : '0' ?>">
            <?= $skip ? 'Skipped' : 'In book' ?>
          </button>
          <button type="button" class="pill pill-toggle<?= $full ? '' : ' is-plain' ?>" data-act="toggle-full" data-id="<?= $id ?>" data-value="<?= $full ? '1' : '0' ?>">
            <?= $full ? 'Full page' : 'Full page: off' ?>
          </button>
          <?php /* Recrop sits with the two toggles rather than inside the form
                   below them: all three are things you do TO the photo you are
                   looking at, and it was the only one buried under a field
                   label. It is not a form field — it opens the cropper and
                   saves on its own — so it never belonged in the form. */ ?>
          <button type="button" class="pill pill-toggle" data-act="recrop" data-src="<?= $original ?>">Recrop</button>
        </span>
      </summary>
      <?= render_photo_body($p, $eventGroups) ?>
    </details>
    <?php
    return ob_get_clean();
}

?>
  <?php
      $yearProjectId = (int) $project['id'];
      $quotes    = quotes_for_year($yearProjectId);
      $anecdotes = anecdotes_for_year($yearProjectId);
      $snapshots = snapshots_for_year($yearProjectId);
      $photos    = photos_for_year($yearProjectId);
      $groups    = event_groups_for_year($yearProjectId);
  ?>

    <div class="row-between subtitle-row">
      <!-- Pre-layout subtitle (brief §4.6). Tap-to-edit via inline-edit.js,
           same gesture as every other tap-to-edit field in the suite. -->
      <ul class="list" id="subtitle-list">
        <li class="list-row" data-id="<?= $yearProjectId ?>">
          <div class="row-slide">
            <div class="row-body">
              <span class="row-sub">Book subtitle</span>
              <span class="row-text<?= $project['subtitle'] ? '' : ' muted' ?>" data-role="subtitle"><?= h($project['subtitle'] ?: 'Tap to add a subtitle…') ?></span>
            </div>
            <?php /* inline-edit.js treats an emptied input as a cancel, so
                     removing a subtitle needs its own control — same button and
                     same endpoint as the one on the layout screen's title card.
                     The BOOK'S NAME is edited over there, on the card that shows
                     the cover it prints on; this screen keeps the one field it
                     has always had. */ ?>
            <button type="button" class="tap-text" data-act="clear-subtitle"
                    data-year-project="<?= $yearProjectId ?>"
                    <?= $project['subtitle'] ? '' : 'hidden' ?>>Remove</button>
          </div>
        </li>
      </ul>
      <!-- Phase 5's generated layouts for this year. Review comes first —
           skip-for-book, full-page flags and event groups all change what
           the arrangement engine does — so this is a link out, not a tab.
           Sits next to the subtitle deliberately: both are the two things
           worth doing right before generating a layout (brief §4.6). -->
      <a class="link-btn create-book-btn" href="<?= h(project_url($projectId, 'book')) ?>">Create Book</a>
    </div>

    <?php /* Both filters, always. Neither is inside the other any more, and
             neither disappears when the other changes — the pair is the one
             control that says what you are looking at, and half of it going
             missing is what made "why can't I find my quotes" a reasonable
             question. Every combination is a real URL. */ ?>
    <div class="filters">
      <div class="row filter-row" aria-label="View">
        <span class="filter-label">View:</span>
        <?php foreach (array('grid' => 'Grid', 'timeline' => 'Timeline') as $v => $label): ?>
          <a class="pill<?= $view === $v ? '' : ' is-plain' ?>"
             href="<?= h(project_url($projectId, 'content', array('view' => $v, 'type' => $type))) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="row filter-row" aria-label="Filter by type">
        <span class="filter-label">Type:</span>
        <?php
        /* Photo carries its own count, which is the count Kathryn asked for,
           put where she is already looking when she wants it rather than on a
           separate line elsewhere on the page. */
        $typeLabels = array(
            'all'      => 'All',
            'photo'    => 'Photos (' . count($photos) . ')',
            'snapshot' => 'Snapshots',
            'quote'    => 'Quotes',
            'anecdote' => 'Anecdotes',
            'groups'   => 'Groups (' . count($groups) . ')',
        );
        foreach ($typeLabels as $t => $label): ?>
          <a class="pill<?= $type === $t ? '' : ' is-plain' ?>"
             href="<?= h(project_url($projectId, 'content', array('view' => $view, 'type' => $t))) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php /* THREE BRANCHES, and the type is checked first. Groups is a type
             rather than a view (see the inputs above), so it renders the same
             either way and short-circuits the view question entirely. */ ?>
    <?php if ($type === 'groups'): ?>

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
        <p class="hint">
          You rarely need this now: correcting a photo's date re-groups that
          photo automatically, which is the case it used to be needed for.
          Assigning a group by hand still overrides it and is never undone.
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

      <?php
        /* PHOTOS IN NO GROUP AT ALL, which this screen used to show nowhere.
           It listed event_groups rows and nothing else, so an ungrouped photo
           was invisible here — and an ungrouped photo is not inert: the layout
           engine puts it in its own bucket, clusters it by time of day, and
           sweeps whatever is left over onto shared pages. "I can't find them in
           the group page" was a true and complete description of the bug. */
        $orphans = array_values(array_filter(
            $photos,
            static fn(array $p): bool => $p['event_group_id'] === null
        ));
      ?>
      <?php if ($orphans !== array()): ?>
        <div class="card">
          <p><strong><?= count($orphans) ?> photo<?= count($orphans) === 1 ? '' : 's' ?> in no group</strong></p>
          <p class="hint">
            These are placed in the book by date alone, and a lone one may be
            combined onto a page with other leftovers. <strong>Group photos</strong>
            above files them with the event they belong to.
          </p>
          <div class="group-members">
            <?php foreach ($orphans as $o): ?>
              <a class="group-member" href="<?= h(project_url($projectId, 'content', array('view' => 'grid', 'type' => 'photo'), 'entry-photo-' . (int) $o['id'])) ?>">
                <img class="thumb" src="<?= h((string) ($o['thumb_path'] ?: $o['original_path'])) ?>" alt="">
                <span class="hint"><?= h(fmt_date_human(substr((string) $o['captured_at'], 0, 10))) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($groups === array()): ?>
        <div class="empty"><p>No event groups yet for <?= h($projectName) ?>.</p></div>
      <?php else: ?>
        <div id="group-list">
          <?php foreach ($groups as $g):
              $groupId = (int) $g['id'];
              $members = array_values(array_filter($photos, static fn(array $p): bool => (int) ($p['event_group_id'] ?? 0) === $groupId));

              /* Would these photos still be grouped together if they were
                 grouped today? A group is clustered from the dates its photos
                 had at the time, and correcting a date afterwards deliberately
                 does NOT move the photo — auto-grouping never touches a photo
                 already in a group, which is what stops it overruling a merge
                 or a split made by hand. The cost is that a photo uploaded with
                 the wrong date stays filed under the wrong date's neighbours,
                 and until now the only place that showed was the finished book.
                 See event_grouping_internal_gaps(). */
              $memberDates = array_map(
                  static fn(array $p): string => substr((string) $p['captured_at'], 0, 10),
                  $members
              );
              $gaps = event_grouping_internal_gaps($memberDates, event_grouping_gap_days());
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

              <?php if ($gaps !== array()): ?>
                <?php /* Named rather than counted: "10 days between Nov 16 and
                         Nov 26" is something she can check against her memory
                         of the year, where "spans 13 days" is not. */ ?>
                <p class="field-err group-gap-warn">
                  These photos would not be grouped together today —
                  <?php $shown = array_slice($gaps, 0, 2); ?>
                  <?= h(implode(', ', array_map(
                      static fn(array $gap): string => $gap['days'] . ' days between '
                          . fmt_date_human($gap['from']) . ' and ' . fmt_date_human($gap['to']),
                      $shown
                  ))) ?><?= count($gaps) > count($shown) ? ', and ' . (count($gaps) - count($shown)) . ' more' : '' ?>.
                  Usually this means a date was corrected after the group was made.
                  <strong>Ungroup</strong> it, then <strong>Group photos</strong> at the top,
                  to file these by their real dates — then generate the book again.
                </p>
              <?php endif; ?>

              <?php if ($members !== array()): ?>
                <?php /* The group's photos, with their dates, WITHOUT having to
                         open anything. They used to be visible only inside the
                         "Split…" accordion below, which meant a group could sit
                         in this list holding four photos from four different
                         weeks and read, at a glance, exactly like a correct
                         one. The whole point of this screen is seeing what is
                         in a group. */ ?>
                <div class="group-members">
                  <?php foreach ($members as $m): ?>
                    <a class="group-member" href="<?= h(project_url($projectId, 'content', array('view' => 'grid', 'type' => 'photo'), 'entry-photo-' . (int) $m['id'])) ?>">
                      <img class="thumb" src="<?= h((string) ($m['thumb_path'] ?: $m['original_path'])) ?>" alt="">
                      <span class="hint"><?= h(fmt_date_human(substr((string) $m['captured_at'], 0, 10))) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>

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

    <?php elseif ($view === 'timeline'): ?>

      <?php
      /* Filtered by TYPE, which the timeline used to ignore entirely — it
         always showed all four, so switching to it from a filtered grid
         silently widened what you were looking at. */
      $entries = array();
      if ($type === 'all' || $type === 'quote') {
          foreach ($quotes as $q) { $entries[] = array('date' => $q['entry_date'], 'order' => 0, 'html' => render_entry_quote($q)); }
      }
      if ($type === 'all' || $type === 'anecdote') {
          foreach ($anecdotes as $a) { $entries[] = array('date' => $a['entry_date'], 'order' => 1, 'html' => render_entry_anecdote($a)); }
      }
      if ($type === 'all' || $type === 'snapshot') {
          foreach ($snapshots as $s) { $entries[] = array('date' => $s['entry_date'], 'order' => 2, 'html' => render_entry_snapshot($s)); }
      }
      if ($type === 'all' || $type === 'photo') {
          foreach ($photos as $p) { $entries[] = array('date' => substr((string) $p['captured_at'], 0, 10), 'order' => 3, 'html' => render_entry_photo($p, $groups)); }
      }

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
          <p>Nothing <?= $type === 'all' ? 'captured' : 'of that type' ?> for <?= h($projectName) ?> yet.</p>
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

    <?php else: /* grid */ ?>
      <?php
      /* GRID MEANS GRID FOR EVERY TYPE. It used to mean a grid only for
         photos, and every other type fell through to a stack of full-width
         rows — a timeline with the month headings taken off. See
         render_entry_cell(). Photos keep their image-forward cell; the other
         three get the text cell; All mixes them in one grid, in date order,
         which is the only order they have in common. */
      $cells = array();
      if ($type === 'all' || $type === 'photo') {
          foreach ($photos as $p) {
              $cells[] = array(
                  'date'  => substr((string) $p['captured_at'], 0, 10),
                  'order' => 0,
                  'html'  => render_photo_cell($p, $groups),
              );
          }
      }
      if ($type === 'all' || $type === 'snapshot') {
          foreach ($snapshots as $sn) {
              $isBirthday = $sn['type'] === 'birthday';
              $bits = array();
              if ($isBirthday) {
                  if ($sn['age'] !== null) { $bits[] = 'Age ' . $sn['age']; }
                  if ($sn['height']) { $bits[] = (string) $sn['height']; }
              } else {
                  if ($sn['grade']) { $bits[] = (string) $sn['grade']; }
                  if ($sn['school']) { $bits[] = (string) $sn['school']; }
              }
              $cells[] = array(
                  'date'  => (string) $sn['entry_date'],
                  'order' => 1,
                  'html'  => render_entry_cell(
                      $sn,
                      'snapshot',
                      $isBirthday ? 'Birthday' : 'School year',
                      $bits !== array() ? implode(' · ', $bits) : '',
                      (string) $sn['entry_date']
                  ),
              );
          }
      }
      if ($type === 'all' || $type === 'quote') {
          foreach ($quotes as $q) {
              $cells[] = array(
                  'date'  => (string) $q['entry_date'],
                  'order' => 2,
                  'html'  => render_entry_cell(
                      $q, 'quote', 'Quote', snippet((string) $q['quote_text'], 90), (string) $q['entry_date']
                  ),
              );
          }
      }
      if ($type === 'all' || $type === 'anecdote') {
          foreach ($anecdotes as $a) {
              $cells[] = array(
                  'date'  => (string) $a['entry_date'],
                  'order' => 3,
                  'html'  => render_entry_cell(
                      $a, 'anecdote', 'Anecdote', snippet((string) $a['anecdote_text'], 90), (string) $a['entry_date']
                  ),
              );
          }
      }

      usort($cells, static function (array $x, array $y): int {
          return $x['date'] <=> $y['date'] ?: $x['order'] <=> $y['order'];
      });
      ?>

      <?php if ($cells === array()): ?>
        <div class="empty">
          <p>Nothing <?= $type === 'all' ? 'captured' : 'of that type' ?> for <?= h($projectName) ?> yet.</p>
        </div>
      <?php else: ?>
        <div class="photo-grid" id="photo-grid">
          <?php foreach ($cells as $c) { echo $c['html']; } ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

