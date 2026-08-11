/* Capture screen controller — wires public/capture.php's four accordion
 * forms to public/api/*.php.
 */

import { apiPost, apiUpload } from './api.js';
import { attachSections } from './sections.js';

/* Which section headings each template starts a new snapshot with. Must match
   SNAPSHOT_TEMPLATES in lib/repo.php — the server seeds the same list when a
   create request omits `sections` entirely, and the two disagreeing would mean
   a snapshot added with JS off looked different from one added with it on. */
const SNAPSHOT_TEMPLATES = {
  birthday: ['Age', 'Height'],
  school_year: ['Grade', 'School', 'Teacher', 'Favorite color', 'Dream job', 'Favorite class'],
};

/* Which project everything on this screen belongs to, or 0 for "the date
   decides". Set by public/capture.php from ?project=, which is what the +
   inside a project passes — see that file's header. Read once: it is a
   property of the screen and cannot change without a navigation. */
const YEAR_PROJECT_ID = Number(document.body.dataset.yearProjectId || 0);
import { showSnackbar } from './swipe.js';
import { openPhotoPicker } from './photo-picker.js';
import { openBatch } from './photo-batch.js';

/* NOTE: openPhotoPicker() has one caller left in this file —
 * attachSnapshotForm()'s manual hero-photo selection (brief §2.3). A quote
 * or anecdote is never attached to a photo (Kathryn's call — see
 * public/api/quotes.php's header for the history), so attachQuickAddForm()
 * below no longer offers a photo picker at all. */

/** Today, as the browser sees it — brief §3: every date defaults to today. */
function today() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/* -------------------------------------------------------- quote/anecdote */

/**
 * Wires a quick-add form (quote or anecdote): text + date, nothing else.
 * Both forms share this shape exactly (brief §2.1/§2.2); only the endpoint
 * and the text field's name differ. No photo attachment here — a quote or
 * anecdote is always standalone (see the note above this function).
 */
function attachQuickAddForm(form, { endpoint, textField, extra = () => ({}) }) {
  const dateInput = form.querySelector('[name="entry_date"]');
  dateInput.value = today();

  const errorEl = form.querySelector('[data-role="error"]');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.textContent = '';

    const text = form.querySelector(`[name="${textField}"]`).value.trim();
    if (text === '') { return; }

    const body = {
      [textField]: text,
      entry_date: dateInput.value || today(),
      year_project_id: YEAR_PROJECT_ID,
      ...extra(form),
    };

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await apiPost(endpoint, body);
    } catch (err) {
      errorEl.textContent = err.message || 'Could not save — try again.';
      button.disabled = false;
      return;
    }
    button.disabled = false;

    showSnackbar('Saved.');
    form.reset();
    dateInput.value = today();
  });
}

/* -------------------------------------------------------------- snapshot */

function attachSnapshotForm(form) {
  const dateInput = form.querySelector('[name="entry_date"]');
  dateInput.value = today();

  const typeSelect = form.querySelector('#snapshot-type');

  /* The type dropdown seeds the section headings and does nothing else. It
     used to show and hide two fixed sets of inputs; a snapshot no longer has
     fixed fields at all — see sections.js and schema.sql on snapshots. */
  const sections = attachSections(form.querySelector('[data-role="sections"]'));

  function seedFromTemplate() {
    /* setTemplate() refuses when anything has been typed, so switching the
       dropdown after filling a section in cannot wipe it. */
    sections.setTemplate(SNAPSHOT_TEMPLATES[typeSelect.value] || []);
  }
  typeSelect.addEventListener('change', seedFromTemplate);
  seedFromTemplate();

  const pickBtn = form.querySelector('[data-act="pick-hero"]');
  const chosenEl = form.querySelector('[data-role="hero-chosen"]');
  const errorEl = form.querySelector('[data-role="error"]');
  let heroPhotoId = null;

  pickBtn.addEventListener('click', async () => {
    const photo = await openPhotoPicker({
      title: 'Choose a hero photo',
      yearProjectId: YEAR_PROJECT_ID,
    });
    if (!photo) { return; }
    heroPhotoId = photo.id;
    chosenEl.textContent = `Hero photo: #${photo.id}`;
    chosenEl.hidden = false;
    pickBtn.textContent = 'Change hero photo';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.textContent = '';

    const body = {
      type: typeSelect.value,
      entry_date: dateInput.value || today(),
      title: form.querySelector('[name="title"]').value.trim(),
      hero_photo_id: heroPhotoId,
      year_project_id: YEAR_PROJECT_ID,
      /* Always sent, even when empty — an explicit empty array means "no
         sections", which the endpoint has to be able to tell apart from
         "seed me from the template". */
      sections: sections.read(),
    };

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await apiPost('api/snapshots.php', body);
    } catch (err) {
      errorEl.textContent = err.message || 'Could not save — try again.';
      button.disabled = false;
      return;
    }
    button.disabled = false;

    showSnackbar('Saved.');
    form.reset();
    dateInput.value = today();
    heroPhotoId = null;
    chosenEl.hidden = true;
    pickBtn.textContent = 'Choose hero photo (optional)';

    /* form.reset() empties the inputs but leaves the ROWS, so the next entry
       would start with the last one's headings and no way to tell they are
       stale. Clearing them first makes setTemplate() see an empty editor and
       re-seed, which is what a fresh form should look like. */
    form.querySelectorAll('[data-role="section-row"]').forEach((row) => row.remove());
    seedFromTemplate();
  });
}

/* ------------------------------------------------------------------ photos */

/** Server reject reasons → something worth reading. */
const REJECT_COPY = {
  unsupported_type:           "isn't an image the app can read",
  too_large:                  'is over the size limit',
  empty_file:                 'was empty',
  incomplete_upload:          "didn't finish uploading",
  upload_failed:              'failed to upload',
  save_failed:                "couldn't be saved on the server",
  heic_unsupported_on_server: "is a HEIC file this server can't decode",
};

function describeRejections(rejected) {
  const [first] = rejected;
  const why = REJECT_COPY[first.reason] ?? `was rejected (${first.reason})`;
  return rejected.length === 1
    ? `"${first.name}" ${why}.`
    : `${rejected.length} photos skipped — "${first.name}" ${why}.`;
}

/**
 * Native "leave this page?" confirmation while an upload is in flight.
 *
 * There's no queue (see lib/imageproc.php's header) — the request has to
 * stay open for the whole batch, so navigating away or closing the tab
 * mid-upload cancels it outright and loses whatever hadn't been saved yet.
 * This doesn't stop that, it just stops it happening BY ACCIDENT: the
 * browser's own prompt is the one thing here that survives even if this
 * whole page's JS somehow locked up.
 */
function warnBeforeUnloadDuring(promise) {
  const onBeforeUnload = (e) => { e.preventDefault(); };
  window.addEventListener('beforeunload', onBeforeUnload);
  return promise.finally(() => window.removeEventListener('beforeunload', onBeforeUnload));
}

function attachPhotoUpload() {
  const input = document.getElementById('photo-file-input');
  const status = document.getElementById('photo-upload-status');

  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.value = '';           // let the same file be picked again later
    if (!files.length) { return; }

    const form = new FormData();
    for (const file of files) { form.append('files[]', file, file.name); }
    /* Multipart, so this rides along in the body beside the files rather than
       as JSON — photos-upload.php reads it out of $_POST. */
    if (YEAR_PROJECT_ID > 0) { form.append('year_project_id', String(YEAR_PROJECT_ID)); }

    status.hidden = false;
    status.textContent = `Uploading ${files.length} photo${files.length === 1 ? '' : 's'}…`;

    let result;
    try {
      result = await warnBeforeUnloadDuring(
        apiUpload('api/photos-upload.php', form, (frac) => {
          // The progress event tracks BYTES SENT, not work done — it hits
          // 100% the instant the browser finishes transmitting, which is
          // well before the server has read EXIF, made a thumbnail and
          // written a row for every photo in the batch (all synchronous,
          // no queue — see photos-upload.php's own header). Without this
          // split, the status line freezes at "100%" for however long that
          // server-side pass takes and looks stuck rather than working.
          status.textContent = frac < 1
            ? `Uploading… ${Math.round(frac * 100)}%`
            : 'Upload complete — processing photos…';
        })
      );
    } catch (err) {
      status.hidden = true;
      showSnackbar(
        err.code === 'payload_too_large'
          ? 'That batch is too big to upload in one go — try fewer at a time.'
          : `Upload failed: ${err.message}`,
        { isError: true }
      );
      return;
    }

    status.hidden = true;

    if (result.rejected?.length) {
      showSnackbar(describeRejections(result.rejected), { isError: true });
    }

    if (result.created?.length) {
      openBatch(result.created, {
        onDone: () => showSnackbar(
          `${result.created.length} photo${result.created.length === 1 ? '' : 's'} added.`
        ),
      });
    }
  });
}

/* --------------------------------------------------------------------- init */

attachQuickAddForm(document.getElementById('quote-form'), {
  endpoint: 'api/quotes.php',
  textField: 'quote_text',
  extra: (form) => ({ who_said_it: form.querySelector('[name="who_said_it"]').value }),
});

attachQuickAddForm(document.getElementById('anecdote-form'), {
  endpoint: 'api/anecdotes.php',
  textField: 'anecdote_text',
});

attachSnapshotForm(document.getElementById('snapshot-form'));
attachPhotoUpload();
