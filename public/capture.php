<?php
/* The capture screen — brief §5.1's mobile-first "quick-add" flow.
 *
 * One screen, four accordion sections (quote / anecdote / snapshot /
 * photos), matching the brief's own grouping rather than four separate
 * pages: on a phone, "quick-add" means not navigating away from where you
 * already are. Every date field defaults to today (capture.js sets it) and
 * stays editable, per brief §3.
 *
 * No separate views layer — this file IS the template, matching every
 * sibling app and every other screen in this app. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

require_login_page();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake — Add</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body>
<main class="wrap">
  <header class="screen-head">
    <h1>Add</h1>
    <div class="head-actions">
      <a class="link-btn" href="index.php">Years</a>
    </div>
  </header>

  <p class="hint">
    Everything below lands in the right year on its own, from its own date —
    no need to pick a year first.
  </p>

  <details class="accordion" open>
    <summary class="accordion-head">Quote</summary>
    <div class="accordion-body">
      <form class="stack" id="quote-form" data-form="quote">
        <label class="field">
          <span>What was said</span>
          <textarea name="quote_text" rows="2" maxlength="2000" required autocomplete="off"></textarea>
        </label>
        <label class="field">
          <span>Who said it</span>
          <select name="who_said_it" required>
            <option value="Kathryn">Kathryn</option>
            <option value="Emma">Emma</option>
          </select>
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

        <div data-fields="birthday">
          <label class="field">
            <span>Age</span>
            <input type="number" name="age" min="0" max="130" inputmode="numeric">
          </label>
          <label class="field">
            <span>Height</span>
            <input type="text" name="height" placeholder="e.g. 3&#8217;9&quot;" autocomplete="off">
          </label>
        </div>

        <div data-fields="school_year" hidden>
          <label class="field"><span>Grade</span><input type="text" name="grade" autocomplete="off"></label>
          <label class="field"><span>School</span><input type="text" name="school" autocomplete="off"></label>
          <label class="field"><span>Teacher</span><input type="text" name="teacher" autocomplete="off"></label>
          <label class="field"><span>Favorite color</span><input type="text" name="favorite_color" autocomplete="off"></label>
          <label class="field"><span>Dream job</span><input type="text" name="dream_job" autocomplete="off"></label>
          <label class="field"><span>Favorite class</span><input type="text" name="favorite_class" autocomplete="off"></label>
        </div>

        <label class="field">
          <span>Notes</span>
          <textarea name="notes" rows="3" autocomplete="off"></textarea>
        </label>

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

  <details class="accordion">
    <summary class="accordion-head">Photos</summary>
    <div class="accordion-body">
      <p class="hint">
        Choose one or more. Each one lands in its year automatically from its
        own date (EXIF, or today if it doesn't have one) — you'll caption,
        place and crop each right after.
      </p>
      <label class="btn-secondary" for="photo-file-input">Choose photos&hellip;</label>
      <input type="file" id="photo-file-input" class="sr-only" accept="image/*" multiple>
      <p class="hint" id="photo-upload-status" hidden></p>
    </div>
  </details>
</main>

<nav class="tabbar">
  <a href="index.php">Years</a>
  <a href="capture.php" class="is-active">Add</a>
</nav>

<script type="module" src="<?= asset('assets/capture.js') ?>"></script>
</body>
</html>
