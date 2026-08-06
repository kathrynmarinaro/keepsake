/* Capture screen controller — wires public/capture.php's four accordion
 * forms to public/api/*.php.
 */

import { apiPost, apiUpload } from './api.js';
import { showSnackbar } from './swipe.js';
import { openPhotoPicker } from './photo-picker.js';
import { openBatch } from './photo-batch.js';

/** Today, as the browser sees it — brief §3: every date defaults to today. */
function today() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/* -------------------------------------------------------- quote/anecdote */

/**
 * Wires a quick-add form (quote or anecdote) that optionally bundles a
 * photo. Both forms share this shape exactly (brief §2.1/§2.2/§2.5); only
 * the endpoint and the text field's name differ.
 */
function attachQuickAddForm(form, { endpoint, textField, extra = () => ({}) }) {
  const dateInput = form.querySelector('[name="entry_date"]');
  dateInput.value = today();

  const pickBtn = form.querySelector('[data-act="pick-photo"]');
  const chosenEl = form.querySelector('[data-role="photo-chosen"]');
  const errorEl = form.querySelector('[data-role="error"]');
  let photoId = null;

  pickBtn?.addEventListener('click', async () => {
    const photo = await openPhotoPicker({ title: 'Attach a photo' });
    if (!photo) { return; }
    photoId = photo.id;
    chosenEl.textContent = `Attached: photo #${photo.id}`;
    chosenEl.hidden = false;
    pickBtn.textContent = 'Change photo';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.textContent = '';

    const text = form.querySelector(`[name="${textField}"]`).value.trim();
    if (text === '') { return; }

    const body = {
      [textField]: text,
      entry_date: dateInput.value || today(),
      photo_id: photoId,
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
    photoId = null;
    chosenEl.hidden = true;
    pickBtn.textContent = 'Attach a photo (optional)';
  });
}

/* -------------------------------------------------------------- snapshot */

function attachSnapshotForm(form) {
  const dateInput = form.querySelector('[name="entry_date"]');
  dateInput.value = today();

  const typeSelect = form.querySelector('#snapshot-type');
  const groups = form.querySelectorAll('[data-fields]');

  function syncFields() {
    for (const group of groups) {
      group.hidden = group.dataset.fields !== typeSelect.value;
    }
  }
  typeSelect.addEventListener('change', syncFields);
  syncFields();

  const pickBtn = form.querySelector('[data-act="pick-hero"]');
  const chosenEl = form.querySelector('[data-role="hero-chosen"]');
  const errorEl = form.querySelector('[data-role="error"]');
  let heroPhotoId = null;

  pickBtn.addEventListener('click', async () => {
    const photo = await openPhotoPicker({ title: 'Choose a hero photo' });
    if (!photo) { return; }
    heroPhotoId = photo.id;
    chosenEl.textContent = `Hero photo: #${photo.id}`;
    chosenEl.hidden = false;
    pickBtn.textContent = 'Change hero photo';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.textContent = '';

    const type = typeSelect.value;
    const body = {
      type,
      entry_date: dateInput.value || today(),
      notes: form.querySelector('[name="notes"]').value.trim(),
      hero_photo_id: heroPhotoId,
    };

    const fieldNames = type === 'birthday'
      ? ['age', 'height']
      : ['grade', 'school', 'teacher', 'favorite_color', 'dream_job', 'favorite_class'];
    for (const name of fieldNames) {
      const el = form.querySelector(`[name="${name}"]`);
      body[name] = el.value.trim();
    }

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
    syncFields();
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

function attachPhotoUpload() {
  const input = document.getElementById('photo-file-input');
  const status = document.getElementById('photo-upload-status');

  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.value = '';           // let the same file be picked again later
    if (!files.length) { return; }

    const form = new FormData();
    for (const file of files) { form.append('files[]', file, file.name); }

    status.hidden = false;
    status.textContent = `Uploading ${files.length} photo${files.length === 1 ? '' : 's'}…`;

    let result;
    try {
      result = await apiUpload('api/photos-upload.php', form, (frac) => {
        status.textContent = `Uploading… ${Math.round(frac * 100)}%`;
      });
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
