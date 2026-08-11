/* Review/browse screen controller — wires public/review.php's server-
 * rendered entries, photo grid and event-group list to public/api/*.php.
 *
 * EVERYTHING IS EVENT-DELEGATED off `document`, not attached per-form/per-
 * button. This page can render a full year's worth of entries as
 * individually-collapsed <details> — delegating means one listener handles
 * all of them, now and after any DOM surgery below, the same reasoning
 * inline-edit.js/swipe.js already delegate off their own root.
 *
 * TWO UPDATE STRATEGIES, chosen per action:
 *   - Field edits (Save), the two photo flag toggles, and a recrop update
 *     the DOM IN PLACE — no reload, since the change is confined to one
 *     entry's own summary/thumbnail.
 *   - Anything that changes which ROWS exist or how they relate to each
 *     other (delete an entry, create/merge/split/ungroup an event group)
 *     reloads the page after the request succeeds. Patching a merge's
 *     effect on every affected group/photo row by hand client-side would
 *     re-implement the exact logic lib/repo.php's event_group_merge()/
 *     _split() already got right — reloading re-renders from the same
 *     server state those functions just committed, which can't drift from
 *     it the way a hand-rolled DOM patch could.
 */

import { apiPost } from './api.js';
import { showSnackbar } from './swipe.js';
import { attachInlineEdit } from './inline-edit.js';
import { openCropper } from './crop.js';
import { openPhotoPicker } from './photo-picker.js';
/* Imported for its side effect only — it wires the entry overlay on load and
   this module talks to it through events, never through a handle. Imported
   HERE rather than added as a second <script> on the page so that a screen
   loading review.js cannot end up without it. */
import './entry-modal.js';

const UPDATE_ENDPOINT = {
  quote: 'api/quotes-update.php',
  anecdote: 'api/anecdotes-update.php',
  snapshot: 'api/snapshots-update.php',
  photo: 'api/photos-update.php',
};
const DELETE_ENDPOINT = {
  quote: 'api/quotes-delete.php',
  anecdote: 'api/anecdotes-delete.php',
  snapshot: 'api/snapshots-delete.php',
  photo: 'api/photos-delete.php',
};

const CONFIRM_LABEL = {
  quote: 'this quote',
  anecdote: 'this anecdote',
  snapshot: 'this snapshot',
  photo: 'this photo',
};

/** Every value on a form as a plain object, one key per input `name`. */
function formValues(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name) { continue; }
    out[el.name] = el.value;
  }
  return out;
}

/* --------------------------------------------------------- entry save/delete */

async function saveEntry(details) {
  const type = details.dataset.type;
  const id = Number(details.dataset.id);
  const form = details.querySelector('[data-role="entry-form"]');
  const errorEl = form.querySelector('[data-role="error"]');
  const button = form.querySelector('button[type="submit"]');
  errorEl.textContent = '';

  const values = formValues(form);
  const body = { id, ...values };

  // Booleans and numbers travel through <input>/<select> as strings; only
  // photos/snapshots have fields that need coercing back.
  if (type === 'photo') {
    // skip_for_book/full_page are NOT on this form (they're the always-on
    // toggle pills in the summary, saved instantly on click — see
    // toggleFlag() below) — nothing to coerce for them here.
    if (body.event_group_id === '') { body.event_group_id = null; }
  }
  if (type === 'snapshot') {
    if (body.hero_photo_id === '') { body.hero_photo_id = null; }
    if ('age' in body && body.age === '') { body.age = null; }
  }

  button.disabled = true;
  let result;
  try {
    result = await apiPost(UPDATE_ENDPOINT[type], body);
  } catch (err) {
    errorEl.textContent = err.message || 'Could not save — try again.';
    button.disabled = false;
    return;
  }
  button.disabled = false;

  // A date edit can move an entry into a DIFFERENT year_project (brief §3:
  // "the auto-assigned year is editable") — this page is scoped to one
  // year, so an entry that just moved off it no longer belongs in the list
  // it's still sitting in. Removing it here (rather than leaving it until a
  // reload) is the same "don't show what the database no longer agrees
  // with" rule swipe.js's own undo-restore path follows.
  const pageYearProjectId = Number(document.body.dataset.yearProjectId || 0);
  if (result.year_project_id && pageYearProjectId && result.year_project_id !== pageYearProjectId) {
    // A photo's grid tile IS this same <details> in Grid view — one
    // removal covers it. It used to also be a separate, always-present
    // entry in a duplicate "Edit photos" list, which needed its own
    // removal; that list is gone (see render_photo_cell()'s header).
    /* Announced before the removal so entry-modal.js, if this entry is open
       full screen, can tear the overlay down instead of putting a detached
       element back into a list it no longer belongs to. */
    document.dispatchEvent(new CustomEvent('keepsake:entry-removed', { detail: { details } }));
    details.remove();
    showSnackbar('Date changed — moved to a different project, off this page.');
    return;
  }

  updateSummary(details, type, result, values);
  showSnackbar('Saved.');

  /* Saving closes the entry when it is open full screen — entry-modal.js
     listens. Dispatched rather than called so this module keeps working with
     the modal module absent, which is also how it behaved before the modal
     existed: the accordion simply stays open. */
  document.dispatchEvent(new CustomEvent('keepsake:entry-saved', { detail: { details, type } }));
}

/** Refresh an entry's collapsed <summary> line after a successful save. */
function updateSummary(details, type, result, values) {
  const dateEl = details.querySelector('.accordion-count');
  const textEl = details.querySelector('.entry-summary-text');

  const dateStr = result.entry_date ?? values.entry_date;
  if (dateEl && dateStr) {
    dateEl.textContent = humanDate(dateStr);
  }

  if (type === 'quote' && textEl) {
    textEl.textContent = snippet(result.quote_text ?? values.quote_text);
  } else if (type === 'anecdote' && textEl) {
    textEl.textContent = snippet(result.anecdote_text ?? values.anecdote_text);
  } else if (type === 'photo') {
    if (textEl) {
      const caption = (result.caption ?? values.caption ?? '').trim();
      textEl.textContent = caption === '' ? '(no caption)' : snippet(caption, 40);
    }
  }
  // Snapshots' summary line (age/height or grade/school) is cheap to leave
  // as originally rendered until the next full page load — none of its
  // fields are as central to "did this save?" as a quote/anecdote/photo's
  // own text, and re-deriving the same bits-joining logic review.php uses
  // here would be a second copy of that formatting to keep in sync.
}

function snippet(text, len = 70) {
  const flat = (text || '').replace(/\s+/g, ' ').trim();
  if (flat === '') { return ''; }
  return flat.length > len ? flat.slice(0, len - 1) + '…' : flat;
}

function humanDate(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  if (!y || !m || !d) { return ymd; }
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return `${names[m - 1]} ${d}, ${y}`;
}

async function deleteEntry(details) {
  const type = details.dataset.type;
  const id = Number(details.dataset.id);
  if (!window.confirm(`Delete ${CONFIRM_LABEL[type] || 'this entry'}? This can't be undone.`)) {
    return;
  }

  try {
    await apiPost(DELETE_ENDPOINT[type], { id });
  } catch (err) {
    showSnackbar(err.message || 'Could not delete — try again.', { isError: true });
    return;
  }

  // In Grid view a photo's grid tile IS this <details> — one removal
  // covers both what it looks like collapsed and its edit form.
  details.remove();
  showSnackbar('Deleted.');
}

/* --------------------------------------------------------- photo flag toggles */

/**
 * Flips skip_for_book/full_page instantly on click — no Save button, per
 * the exit criterion ("these need to visibly persist, not just save
 * silently") — optimistic, with a revert if the request fails.
 */
async function toggleFlag(el) {
  const field = el.dataset.act === 'toggle-skip' ? 'skip_for_book' : 'full_page';
  const id = Number(el.dataset.id);
  const next = el.dataset.value !== '1';

  applyFlagState(id, field, next);

  try {
    await apiPost('api/photos-update.php', { id, [field]: next });
  } catch (err) {
    applyFlagState(id, field, !next); // revert
    showSnackbar(err.message || "That didn't save — try again.", { isError: true });
    return;
  }
  showSnackbar(field === 'skip_for_book' ? (next ? 'Skipped.' : 'Back in the book.') : (next ? 'Marked full page.' : 'No longer full page.'));
}

/**
 * Paints every element for this photo id/field pair: the toggle pill
 * itself, plus — only in Grid view, where the collapsed tile's dimming/
 * border IS the "at a glance" point of the feature (see .photo-grid's own
 * header in styles.css) — the enclosing .photo-cell-details tile.
 */
function applyFlagState(id, field, value) {
  const act = field === 'skip_for_book' ? 'toggle-skip' : 'toggle-full';
  const onLabel = field === 'skip_for_book' ? 'Skipped' : 'Full page';
  const offLabel = field === 'skip_for_book' ? 'In book' : 'Full page: off';
  // is-plain is the "off"/quiet look for skip (In book is the emphasized
  // default) but the REVERSE for full_page (off is the quiet default) —
  // matches the classes review.php already renders server-side.
  const isPlainWhenOn = field === 'skip_for_book';

  for (const el of document.querySelectorAll(`[data-act="${act}"][data-id="${id}"]`)) {
    el.dataset.value = value ? '1' : '0';
    el.textContent = value ? onLabel : offLabel;
    el.classList.toggle('is-plain', isPlainWhenOn ? value : !value);
  }

  const cell = document.querySelector(`.photo-cell-details[data-id="${id}"]`);
  if (cell) {
    cell.classList.toggle(field === 'skip_for_book' ? 'is-skipped' : 'is-full-page', value);
  }
}

/* --------------------------------------------------------------------- recrop */

async function recrop(button) {
  const details = button.closest('.entry');
  const id = Number(details.dataset.id);
  const src = button.dataset.src;

  const rect = await openCropper(src);
  if (!rect) { return; }

  let result;
  try {
    result = await apiPost('api/photos-crop.php', { id, rect });
  } catch (err) {
    showSnackbar(err.message || 'Crop failed — try again.', { isError: true });
    return;
  }

  button.dataset.src = result.original_url;
  // One thumbnail element either way now — Timeline's entry row and Grid
  // view's tile are the same <details>, both with data-role="thumb" on
  // their <img> (see render_entry_photo()/render_photo_cell()).
  const thumbImg = details.querySelector('[data-role="thumb"]');
  if (thumbImg) { thumbImg.src = result.thumb_url; }
  showSnackbar('Cropped.');
}

/* ------------------------------------------------------------ hero photo pick */

async function pickHero(button) {
  const photo = await openPhotoPicker({ title: 'Choose a hero photo' });
  if (!photo) { return; }

  const form = button.closest('form');
  form.querySelector('[name="hero_photo_id"]').value = photo.id;
  const chosenEl = form.querySelector('[data-role="hero-chosen"]');
  chosenEl.textContent = `Hero photo: #${photo.id}`;
  chosenEl.hidden = false;
  button.textContent = 'Change hero photo';
}

/* ------------------------------------------------------------ event groups */
/* Structural changes (create/merge/split/ungroup) reload the page rather
   than patch the DOM — see this file's header. */

async function createGroup(form) {
  const errorEl = form.querySelector('[data-role="error"]');
  errorEl.textContent = '';

  const values = formValues(form);
  const yearProjectId = Number(document.querySelector('#subtitle-list .list-row').dataset.id);

  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await apiPost('api/event-groups-create.php', {
      year_project_id: yearProjectId,
      name: values.name,
      start_date: values.start_date,
      end_date: values.end_date,
      location_name: values.location_name,
    });
  } catch (err) {
    errorEl.textContent = err.message || 'Could not create — try again.';
    button.disabled = false;
    return;
  }

  window.location.reload();
}

async function splitGroup(form) {
  const errorEl = form.querySelector('[data-role="error"]');
  errorEl.textContent = '';

  const sourceId = Number(form.dataset.groupId);
  const photoIds = Array.from(form.querySelectorAll('input[name="photo_ids[]"]:checked')).map((el) => Number(el.value));
  const newName = form.querySelector('[name="new_name"]').value.trim();

  if (photoIds.length === 0) {
    errorEl.textContent = 'Choose at least one photo to split off.';
    return;
  }

  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await apiPost('api/event-groups-split.php', { source_id: sourceId, photo_ids: photoIds, new_name: newName });
  } catch (err) {
    errorEl.textContent = err.message || 'Could not split — try again.';
    button.disabled = false;
    return;
  }

  window.location.reload();
}

async function deleteGroup(button) {
  const id = Number(button.dataset.id);
  if (!window.confirm('Ungroup these photos? The group is removed; the photos themselves are not touched.')) {
    return;
  }
  try {
    await apiPost('api/event-groups-delete.php', { id });
  } catch (err) {
    showSnackbar(err.message || 'Could not ungroup — try again.', { isError: true });
    return;
  }
  window.location.reload();
}

/**
 * "Group photos" (brief §4.1/§5.2, PLAN.md Phase 4) — re-runs date-gap
 * clustering + reverse geocoding over whatever's currently ungrouped for
 * this year. Reloads on success for the same reason every other structural
 * group change here does (see this file's header): the response is a
 * summary count, not the resulting rows, and a reload renders exactly what
 * lib/grouping.php just committed.
 */
async function runGrouping(button) {
  const yearProjectId = Number(document.querySelector('#subtitle-list .list-row').dataset.id);

  button.disabled = true;
  let result;
  try {
    result = await apiPost('api/event-groups-auto.php', { year_project_id: yearProjectId });
  } catch (err) {
    showSnackbar(err.message || 'Could not group photos — try again.', { isError: true });
    button.disabled = false;
    return;
  }

  if (result.photos_grouped === 0) {
    showSnackbar('Nothing to group — every photo already belongs to a group.');
    button.disabled = false;
    return;
  }

  const bits = [];
  if (result.groups_created) { bits.push(`${result.groups_created} new group${result.groups_created === 1 ? '' : 's'}`); }
  if (result.groups_extended) { bits.push(`${result.groups_extended} group${result.groups_extended === 1 ? '' : 's'} extended`); }
  showSnackbar(`Grouped ${result.photos_grouped} photo${result.photos_grouped === 1 ? '' : 's'} (${bits.join(', ')}).`);
  window.location.reload();
}

async function mergeGroups() {
  const targetSelect = document.getElementById('merge-target');
  const targetId = Number(targetSelect.value);
  const sourceIds = Array.from(document.querySelectorAll('[data-role="merge-select"]:checked'))
    .map((el) => Number(el.closest('.group-row').dataset.id))
    .filter((id) => id !== targetId);

  if (sourceIds.length === 0) {
    showSnackbar('Check at least one OTHER group to merge into the target.', { isError: true });
    return;
  }

  try {
    await apiPost('api/event-groups-merge.php', { source_ids: sourceIds, target_id: targetId });
  } catch (err) {
    showSnackbar(err.message || 'Could not merge — try again.', { isError: true });
    return;
  }
  window.location.reload();
}

/* --------------------------------------------------------------------- init */

document.addEventListener('submit', (event) => {
  const entryForm = event.target.closest('[data-role="entry-form"]');
  if (entryForm) {
    event.preventDefault();
    saveEntry(entryForm.closest('.entry'));
    return;
  }

  if (event.target.id === 'new-group-form') {
    event.preventDefault();
    createGroup(event.target);
    return;
  }

  if (event.target.classList.contains('split-form')) {
    event.preventDefault();
    splitGroup(event.target);
  }
});

document.addEventListener('click', (event) => {
  const el = event.target.closest('[data-act]');
  if (!el) { return; }

  switch (el.dataset.act) {
    case 'toggle-skip':
    case 'toggle-full':
      // Always nested inside a <summary> — Timeline's entry row and the
      // photo grid's tile both use the same accordion shape now. Without
      // this, the click's default action also toggles the parent <details>
      // open/closed. Nothing here needs stopPropagation as well:
      // preventDefault alone cancels the details toggle, and returning
      // after the switch's case stops this same handler from acting on the
      // ancestor's data-act.
      event.preventDefault();
      toggleFlag(el);
      break;
    case 'delete':
      event.preventDefault();
      deleteEntry(el.closest('.entry'));
      break;
    case 'recrop':
      event.preventDefault();
      recrop(el);
      break;
    case 'pick-hero':
      event.preventDefault();
      pickHero(el);
      break;
    case 'delete-group':
      event.preventDefault();
      deleteGroup(el);
      break;
    case 'clear-subtitle':
      event.preventDefault();
      clearSubtitle(el);
      break;
  }
});

document.getElementById('merge-btn')?.addEventListener('click', mergeGroups);
document.getElementById('run-grouping-btn')?.addEventListener('click', (event) => runGrouping(event.currentTarget));

/* Subtitle tap-to-edit (brief §4.6) — ported ahead of need in Phase 0,
   used for the first time here. */
attachInlineEdit('#subtitle-list', {
  rowSelector: '.list-row',
  textSelector: '[data-role="subtitle"]',
  maxLength: 190,
  onSave: async (id, text) => {
    const result = await apiPost('api/year-projects-update.php', { id: Number(id), subtitle: text });
    const subtitleEl = document.querySelector('[data-role="subtitle"]');
    subtitleEl.classList.remove('muted');
    document.querySelector('[data-act="clear-subtitle"]')?.toggleAttribute('hidden', !result.subtitle);
    return result.subtitle || text;
  },
});

/* Removing the subtitle, which the tap-to-edit gesture deliberately cannot do:
   inline-edit.js treats an emptied input as a cancel, because in the app it was
   written for an emptied row means a delete. So clearing gets its own control,
   the same one and the same endpoint the layout screen uses. */
async function clearSubtitle(button) {
  button.disabled = true;
  try {
    await apiPost('api/year-projects-update.php', {
      id: Number(button.dataset.yearProject),
      subtitle: '',
    });
    const subtitleEl = document.querySelector('[data-role="subtitle"]');
    if (subtitleEl) {
      subtitleEl.textContent = 'Tap to add a subtitle…';
      subtitleEl.classList.add('muted');
    }
    button.hidden = true;
    showSnackbar('Subtitle removed.');
  } catch (err) {
    showSnackbar(err.message || "That didn't save — try again.", { isError: true });
  } finally {
    button.disabled = false;
  }
}

/* Event-group rename (brief §4.1: "Kathryn can rename any group") — the
   SAME tap-to-edit gesture as the subtitle above, on the group's name line.
   Delegated off #group-list (present only on the Groups view), so it needs
   no re-attach after a merge/split's page reload re-renders the list. */
if (document.getElementById('group-list')) {
  attachInlineEdit('#group-list', {
    rowSelector: '.list-row',
    textSelector: '[data-role="group-name"]',
    maxLength: 190,
    onSave: async (id, text) => {
      const result = await apiPost('api/event-groups-rename.php', { id: Number(id), name: text });
      return result.name || text;
    },
  });
}
