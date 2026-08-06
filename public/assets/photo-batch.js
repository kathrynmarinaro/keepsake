/* Batch step-through: after a multi-photo upload, step through the new
 * photos one at a time setting caption / location / date / crop.
 *
 * SHAPE ported from Inspiration Board's public/assets/annotate.js
 * (openAnnotator) — see PLAN.md's "What to port from Inspiration Board":
 * a module-global `session` object, `move(delta)` that persists the current
 * card before stepping, `render()` that redraws the current card, `close()`
 * on finish or skip-all. The FIELDS are entirely different — caption,
 * location, date and a crop button, not description/tags/URL-paste — because
 * Keepsake photos don't have tags or a source URL. tagfield.js and url.js
 * are NOT ported; this module doesn't import them.
 *
 * Every upload is already saved (brief: skip_for_book defaults to included)
 * by the time this opens — like annotate.js, this whole panel is optional
 * polish on rows that already exist, and can be abandoned at any point
 * without losing the photos themselves.
 */

import { apiPost } from './api.js';
import { openCropper } from './crop.js';
import { showSnackbar } from './swipe.js';

let session = null;   // { photos, index, onDone, dirty }

/**
 * @param {Array<object>} photos Rows from photos-upload.php's `created`
 *        list: { id, thumb_url, original_url, caption, location_text,
 *        entry_date, width, height }.
 * @param {object} [opts]
 * @param {() => void} [opts.onDone] called once, when the whole batch is
 *        finished, skipped or closed — the caller's cue to refresh anything
 *        it shows (a "recently added" list, a count).
 */
export function openBatch(photos, { onDone } = {}) {
  if (!photos?.length) { return; }
  session = { photos: photos.map((p) => ({ ...p })), index: 0, onDone, dirty: false };

  const root = document.createElement('div');
  root.className = 'cropper';                 // reuses the same full-screen
  root.id = 'photo-batch-root';                // overlay chrome as crop.js
  document.body.append(root);

  render();
}

function close() {
  const el = document.getElementById('photo-batch-root');
  const callback = session?.onDone;
  session = null;
  el?.remove();
  callback?.();
}

async function move(delta) {
  if (!session) { return; }

  await persist();

  const next = session.index + delta;
  if (next < 0) { return; }             // Back is disabled at index 0; ignore anyway
  if (next >= session.photos.length) {
    close();
    return;
  }
  session.index = next;
  render();
}

/** Save the current card if it was actually touched. Never blocks navigation. */
async function persist() {
  if (!session || !session.dirty) { return; }

  const node = document.querySelector('.batch-panel');
  if (!node) { return; }

  const photo = session.photos[session.index];
  const caption = node.querySelector('[data-field="caption"]').value.trim();
  const location = node.querySelector('[data-field="location"]').value.trim();
  const date = node.querySelector('[data-field="date"]').value;

  photo.caption = caption;
  photo.location_text = location;
  if (date) { photo.entry_date = date; }

  session.dirty = false;

  try {
    await apiPost('api/photos-update.php', {
      id: photo.id,
      caption,
      location_text: location,
      // Time-of-day is preserved server-side (photos-update.php keeps the
      // existing time and only replaces the date part) — the batch step only
      // offers a date field, matching quotes/anecdotes/snapshots, which are
      // DATE-only to begin with.
      entry_date: date || null,
    });
  } catch (err) {
    showSnackbar(`Couldn't save that photo's details: ${err.message}`, { isError: true });
  }
}

async function runCrop(photo, node) {
  if (!photo.original_url) { return; }

  const rect = await openCropper(photo.original_url);
  if (!rect) { return; }              // cancelled, or a full-frame no-op

  const button = node.querySelector('[data-act="crop"]');
  if (button) { button.disabled = true; button.textContent = 'Cropping…'; }

  let result;
  try {
    result = await apiPost('api/photos-crop.php', { id: photo.id, rect });
  } catch (err) {
    showSnackbar(`Crop failed: ${err.message}`, { isError: true });
    if (button) { button.disabled = false; button.textContent = 'Crop'; }
    return;
  }

  photo.thumb_url = result.thumb_url;
  photo.original_url = result.original_url;
  photo.width = result.width;
  photo.height = result.height;

  if (button) { button.disabled = false; button.textContent = 'Crop'; }
  const img = node.querySelector('[data-role="preview"]');
  if (img) { img.src = photo.thumb_url; }
}

function render() {
  if (!session) { return; }

  const root = document.getElementById('photo-batch-root');
  if (!root) { return; }

  const photo = session.photos[session.index];
  const isLast = session.index === session.photos.length - 1;

  const panel = document.createElement('div');
  panel.className = 'batch-panel card';
  panel.innerHTML = `
    <div class="row-between">
      <span class="pill">${session.index + 1} of ${session.photos.length}</span>
      <button type="button" class="link-btn" data-act="skip">${isLast ? 'Done' : 'Skip all'}</button>
    </div>

    <div class="batch-stage">
      <img class="thumb" alt="" data-role="preview" src="${photo.thumb_url ?? ''}">
    </div>
    <button type="button" class="btn-ghost" data-act="crop">Crop</button>

    <label class="field">
      <span>Caption</span>
      <textarea data-field="caption" rows="2" placeholder="Optional">${photo.caption ?? ''}</textarea>
    </label>

    <label class="field">
      <span>Location</span>
      <input type="text" data-field="location" placeholder="Optional" value="${photo.location_text ?? ''}">
    </label>

    <label class="field">
      <span>Date</span>
      <input type="date" data-field="date" value="${photo.entry_date ?? ''}">
    </label>

    <div class="row-between">
      <button type="button" class="btn-ghost" data-act="prev" ${session.index === 0 ? 'disabled' : ''}>Back</button>
      <button type="button" class="btn-primary" data-act="next">${isLast ? 'Finish' : 'Next'}</button>
    </div>`;

  root.replaceChildren();

  const scroll = document.createElement('div');
  scroll.className = 'batch-scroll';
  scroll.append(panel);
  root.append(scroll);

  for (const input of panel.querySelectorAll('[data-field]')) {
    input.addEventListener('input', () => { session.dirty = true; });
  }

  panel.querySelector('[data-act="prev"]').addEventListener('click', () => move(-1));
  panel.querySelector('[data-act="next"]').addEventListener('click', () => move(1));
  panel.querySelector('[data-act="skip"]').addEventListener('click', close);
  panel.querySelector('[data-act="crop"]').addEventListener('click', () => runCrop(photo, panel));
}
