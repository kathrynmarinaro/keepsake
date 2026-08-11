<?php
/* The capture screen — brief §5.1's mobile-first "quick-add" flow.
 *
 * One screen, four accordion sections (quote / anecdote / snapshot /
 * photos), matching the brief's own grouping rather than four separate
 * pages: on a phone, "quick-add" means not navigating away from where you
 * already are. Every date field defaults to today (capture.js sets it) and
 * stays editable, per brief §3.
 *
 * REACHED FROM THE FLOATING +, which is why there is no longer an "Add" tab:
 * adding is an action, not a place (lib/page.php's header has the reasoning).
 *
 * ?project=N PINS EVERYTHING SAVED HERE TO THAT PROJECT, whatever its date
 * says. That is the whole difference between the + inside a project and the +
 * on the list: inside a project you have already answered "which book", so a
 * photo taken last December still belongs to the trip book you are adding it
 * to. With no ?project= the original rule stands and the date decides — see
 * year_project_for_new() in lib/repo.php.
 *
 * No separate views layer — this file IS the template, matching every
 * sibling app and every other screen in this app. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/page.php';

require_login_page();

/* A project id that does not resolve is dropped rather than refused: the
   screen still works, everything filed by its date, which is the behaviour
   this screen had before ?project= existed. */
$project   = isset($_GET['project']) ? year_project_get((int) $_GET['project']) : null;
$projectId = $project === null ? 0 : (int) $project['id'];

/* Names already used on a quote, plus Kathryn and Emma — see quote_speakers().
   Offered as suggestions on a text input rather than as a closed <select>:
   Kathryn asked to be able to type a name that is not on the list. */
$speakers = quote_speakers();

page_head(array(
    'title'      => $project === null ? 'Add' : 'Add to ' . year_project_title($project),
    /* capture.js reads this and sends it with every save. On the body rather
       than on each of the four forms: it is a property of the screen, and four
       hidden inputs would be four chances to forget one. */
    'body_attrs' => array('data-year-project-id' => $projectId ?: null),
));

page_screen_head(array(
    'heading' => 'Add',
    'sub'     => $project === null ? '' : 'to ' . year_project_title($project),
    'back'    => $project === null ? 'index.php' : project_url($projectId),
));
?>

  <p class="hint">
    <?php if ($project !== null): ?>
      Everything below goes into <?= h(year_project_title($project)) ?>, whatever
      its date says.
    <?php else: ?>
      Everything below lands in the right year on its own, from its own date —
      no need to pick a year first.
    <?php endif; ?>
  </p>

  <!-- Photos first and open by default: this is what gets added most —
       Kathryn's own usage, not a guess (see PLAN.md's post-launch note). -->
  <details class="accordion" open>
    <summary class="accordion-head">Photos</summary>
    <div class="accordion-body">
      <p class="hint">
        Choose one or more. Each one lands in its year automatically from its
        own date (EXIF, or today if it doesn't have one) — you'll caption,
        place and crop each right after.
      </p>
      <p class="hint">
        Uploading a lot at once? <strong>10&ndash;15 photos per batch</strong>
        works best — a single request that runs long risks timing out and
        losing the whole batch. Not a hard limit; just what tends to go
        smoothly. <strong>Keep this page open</strong> until the upload
        finishes — leaving or closing the tab mid-upload cancels it.
      </p>
      <label class="btn-secondary" for="photo-file-input">Choose photos&hellip;</label>
      <input type="file" id="photo-file-input" class="sr-only" accept="image/*" multiple>
      <p class="hint" id="photo-upload-status" hidden></p>
    </div>
  </details>

  <details class="accordion">
    <summary class="accordion-head">Quote</summary>
    <div class="accordion-body">
      <form class="stack" id="quote-form" data-form="quote">
        <label class="field">
          <span>What was said</span>
          <textarea name="quote_text" rows="2" maxlength="2000" required autocomplete="off"></textarea>
        </label>
        <?php /* A TEXT INPUT WITH SUGGESTIONS, not a <select>. It was a closed
                 dropdown of two, matching a column that was an ENUM of two;
                 both changed together so a grandparent, a teacher or a friend
                 can be quoted too.

                 <datalist> rather than a JS combo: the browser gives the
                 dropdown affordance, the filtering and the keyboard behaviour
                 for free, it degrades to a plain text box everywhere it is not
                 supported, and there is no third-party widget to keep working.
                 The list grows by being used — quote_speakers() is a SELECT
                 DISTINCT over the quotes themselves. */ ?>
        <label class="field">
          <span>Who said it</span>
          <input type="text" name="who_said_it" list="speaker-names" required
                 maxlength="190" autocomplete="off" autocapitalize="words"
                 placeholder="Pick a name or type a new one">
        </label>
        <label class="field">
          <span>Date</span>
          <input type="date" name="entry_date" required>
        </label>
        <p class="field-err" data-role="error"></p>
        <button type="submit" class="btn-primary">Add quote</button>
      </form>
    </div>
  </details>

  <details class="accordion">
    <summary class="accordion-head">Anecdote</summary>
    <div class="accordion-body">
      <form class="stack" id="anecdote-form" data-form="anecdote">
        <label class="field">
          <span>What happened</span>
          <textarea name="anecdote_text" rows="3" maxlength="4000" required autocomplete="off"></textarea>
        </label>
        <label class="field">
          <span>Date</span>
          <input type="date" name="entry_date" required>
        </label>
        <p class="field-err" data-role="error"></p>
        <button type="submit" class="btn-primary">Add anecdote</button>
      </form>
    </div>
  </details>

  <details class="accordion">
    <summary class="accordion-head">Snapshot</summary>
    <div class="accordion-body">
      <form class="stack" id="snapshot-form" data-form="snapshot">
        <label class="field">
          <span>Type</span>
          <select name="type" id="snapshot-type">
            <option value="birthday">Birthday</option>
            <option value="school_year">School year (first/last day)</option>
          </select>
        </label>
        <label class="field">
          <span>Date</span>
          <input type="date" name="entry_date" required>
        </label>

        <?php /* The type dropdown seeds the SECTIONS below with that
                 template's headings and does nothing else — it used to say
                 which of nine fixed columns this row had. See schema.sql on
                 snapshots, and sections.js for why switching it will not
                 overwrite anything already typed. */ ?>
        <label class="field">
          <span>Title</span>
          <input type="text" name="title" placeholder="e.g. Emma&#8217;s 8th Birthday" autocomplete="off">
        </label>

        <div class="sections-editor" data-role="sections">
          <span class="label">Sections</span>
          <p class="hint">A heading and what goes under it. Add as many as the
          page needs, or delete the ones you do not want.</p>
          <div data-role="section-list"></div>
          <button type="button" class="btn-ghost" data-act="add-section">Add a section</button>
        </div>

        <div class="field">
          <span>Hero photo</span>
          <button type="button" class="btn-ghost" data-act="pick-hero">Choose hero photo (optional)</button>
          <p class="hint" data-role="hero-chosen" hidden></p>
        </div>

        <p class="field-err" data-role="error"></p>
        <button type="submit" class="btn-primary">Add snapshot</button>
      </form>
    </div>
  </details>

<datalist id="speaker-names">
  <?php foreach ($speakers as $name): ?><option value="<?= h($name) ?>"></option><?php endforeach; ?>
</datalist>

<?php page_foot(array('scripts' => array('assets/capture.js'))) ?>
