/* Book-layouts screen controller — public/layout.php.
 *
 * FOUR JOBS, delegated off `document` (same reasoning review.js/inline-
 * edit.js/swipe.js already give for delegating off their own roots: this
 * screen can render a whole year's worth of pages, and one listener handles
 * all of them without a re-attach after any reload):
 *
 *   1. Generate a new version / activate a version — Phase 5, unchanged.
 *      Both reload on success, same as before.
 *   2. Subtitle tap-to-edit and cover-photo pick — brief §4.6, Phase 6. The
 *      subtitle wiring is the literal same pattern review.js already uses
 *      (same endpoint, same inline-edit.js call) — not re-derived here.
 *   3. "Reflow from here" — POSTs to api/book-layouts-reflow.php, which is a
 *      thin wrapper over lib/layout.php's layout_reflow_from(). Reloads on
 *      success: pages from that point on are wholesale replaced, and this
 *      screen has no per-page patch logic to keep in sync with what that
 *      function just did (same "structural change reloads" rule review.js's
 *      own header documents for merge/split/delete).
 *   4. Drag-and-drop, PHOTOS ONLY (see lib/repo.php's book_page_slot_swap()/
 *      _move() for why): native HTML5 drag events, not swipe.js/reorder.js —
 *      neither fits. swipe.js is a single-row delete gesture; reorder.js
 *      reorders SIBLINGS within one list via pointer-tracked vertical
 *      movement, and this is a two-dimensional drop between arbitrary
 *      slots/pages, sometimes crossing page boundaries entirely. Native drag
 *      events are the right-sized tool and need no new shared module.
 *
 *      Drop ON another photo slot -> SWAP (api/book-page-slots-swap.php).
 *      Drop on a photos-page's open background (not on a slot) -> MOVE
 *      (api/book-page-slots-move.php), landing at that page's next open
 *      slot. BOTH RELOAD ON SUCCESS, post-launch (PLAN.md) — this used to
 *      patch the two swapped/moved DOM nodes directly, back when a page was
 *      a flat row of equal-size cells and a swap/move only ever meant "these
 *      two nodes trade parents". Now that a page's shape is a composition
 *      tree keyed off every slot's orientation (lib/layout_render.php),
 *      trading ONE photo for a different-shaped one can reshape the WHOLE
 *      page's tree (a portrait swapped in for a landscape can flip a 2-up
 *      page between side-by-side and stacked) — sometimes on both the
 *      source AND destination page for a move. There is no longer a DOM
 *      patch that's "fully described by these two nodes", so this now
 *      follows the same reload rule reflow/generate/activate already use.
 *
 *   6. The page's foot caption — the one line that prints under the photos.
 *      Normally derived from the page's photos' own captions; typing over it
 *      saves an override on the PAGE (api/book-pages-caption.php), and
 *      emptying it back to nothing clears the override so the derived line
 *      returns. Saved on blur rather than per keystroke: a caption is a
 *      sentence, and a request per character would be both wasteful and
 *      impossible to reason about if two landed out of order.
 *
 *      Does NOT reload. Unlike a swap or a move, a caption cannot reshape the
 *      page — it is drawn in the white space the solver already left below
 *      the photos, and its own length does not move a photo.
 *
 *   5. "Adjust crop" — a photo slot's optional manual crop override
 *      (book_page_photos.crop_x/y/w/h). Opens crop.js's openCropper() locked
 *      to that slot's own rendered shape (read straight off the DOM — see
 *      adjustCrop() below), saves via api/book-page-photos-crop.php, and
 *      patches that one slot's background-image/img in place — this one
 *      genuinely IS fully described by "this one slot's own crop changed",
 *      since it can never reshape the page (the target aspect a crop is
 *      locked to is exactly the box the solver already gave this slot).
 *
 *      Since Round 6 this is the ONLY cropping the app does on Kathryn's
 *      behalf. The engine used to crop every photo to whatever cell it landed
 *      in; it no longer reshapes a photo at all, except to match same-shape
 *      neighbours. So this control changed from "fix what the layout did to
 *      this photo" to "trim this photo because I want it trimmed", which is
 *      the job she asked it to keep.
 */

import { apiPost, ApiError } from './api.js';
import { showSnackbar } from './swipe.js';
import { attachInlineEdit } from './inline-edit.js';
import { openPhotoPicker } from './photo-picker.js';
import { openCropper } from './crop.js';

function describe(err) {
  if (err instanceof ApiError && err.detail) { return err.detail; }
  if (err instanceof ApiError) { return err.code; }
  return 'Something went wrong.';
}

/* ----------------------------------------------------- generate / activate */

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-act]');
  if (!button) { return; }

  const action = button.dataset.act;

  if (action === 'generate' || action === 'activate') {
    /* Generating a full year's book is the one action here that can take a
       noticeable moment — disable the button while it runs so a second click
       can't create a second version nobody asked for. */
    button.disabled = true;
    try {
      if (action === 'generate') {
        const bar = button.closest('[data-year-project]');
        const result = await apiPost('api/book-layouts-create.php', {
          year_project_id: Number(bar.dataset.yearProject),
        });
        showSnackbar('Version ' + result.version + ' — ' + result.pages + ' pages');
      } else {
        await apiPost('api/book-layouts-activate.php', {
          layout_id: Number(button.dataset.layout),
        });
      }
      window.location.reload();
    } catch (err) {
      button.disabled = false;
      showSnackbar(describe(err));
    }
    return;
  }

  if (action === 'reflow') {
    await reflowFrom(button);
    return;
  }

  if (action === 'pick-cover') {
    await pickCover(button);
  }

  if (action === 'adjust-crop') {
    await adjustCrop(button);
  }
});

/* -------------------------------------------------------------- reflow --- */

async function reflowFrom(button) {
  const page = Number(button.dataset.page);
  const layoutId = Number(document.querySelector('.ks-book')?.dataset.layoutId);
  if (!layoutId || !page) { return; }

  if (!window.confirm(
    `Reflow from page ${page} onward? Every page from ${page} to the end of `
    + 'this version is regenerated. Pages before it are never touched.'
  )) {
    return;
  }

  button.disabled = true;
  try {
    const result = await apiPost('api/book-layouts-reflow.php', {
      layout_id: layoutId,
      from_page_number: page,
    });
    showSnackbar(`Reflowed from page ${result.from} — ${result.pages} pages regenerated.`);
    window.location.reload();
  } catch (err) {
    button.disabled = false;
    showSnackbar(describe(err), { isError: true });
  }
}

/* --------------------------------------------------------- cover photo --- */

async function pickCover(button) {
  const card = button.closest('[data-role="title-card"]');
  const yearProjectId = Number(card.dataset.yearProject);

  const photo = await openPhotoPicker({ title: 'Choose a cover photo' });
  if (!photo) { return; }

  button.disabled = true;
  try {
    await apiPost('api/year-projects-update.php', {
      id: yearProjectId,
      cover_photo_id: photo.id,
    });
  } catch (err) {
    button.disabled = false;
    showSnackbar(describe(err), { isError: true });
    return;
  }
  button.disabled = false;

  const thumb = card.querySelector('.ks-cover-thumb');
  thumb.classList.remove('ks-cover-empty');
  thumb.removeAttribute('aria-hidden');
  if (thumb.tagName !== 'IMG') {
    const img = document.createElement('img');
    img.className = 'ks-cover-thumb';
    img.alt = '';
    thumb.replaceWith(img);
    img.src = photo.thumb_url || '';
  } else {
    thumb.src = photo.thumb_url || '';
  }
  card.querySelector('[data-role="cover-status"]').textContent = 'Cover photo set.';
  button.textContent = 'Change cover photo';
  showSnackbar('Cover photo set.');
}

/* -------------------------------------------------------------- crop ----- */
/* "Adjust crop" (see this file's header, point 5). Opens crop.js LOCKED to
   this slot's own rendered shape — read straight off the frame's live
   getBoundingClientRect(), not recomputed from role/aspect math, so the lock
   always matches EXACTLY what the composition tree actually gave this slot,
   including gaps/rounding, with zero risk of drifting from it. */

async function adjustCrop(button) {
  const figure = button.closest('.ks-slot-photo');
  const frame = figure?.querySelector('.ks-slot-photo-frame');
  if (!figure || !frame) { return; }

  const rect = frame.getBoundingClientRect();
  if (!rect.width || !rect.height) { return; } // not laid out yet — nothing to lock to

  let initial = null;
  if (figure.dataset.crop) {
    try { initial = JSON.parse(figure.dataset.crop); } catch { /* malformed — treat as none */ }
  }

  const result = await openCropper(figure.dataset.original, {
    lockAspect: rect.width / rect.height,
    initial,
  });
  // crop.js resolves null for BOTH "cancelled" and "applied with no change
  // from what it opened with" (see crop.js's own apply handler) — there is
  // no case where null means "clear an existing crop", so this is always a
  // safe no-op, whether or not `initial` was set. Don't mistake this for
  // "reset to auto-fit": Reset moves the box to the centered default and
  // then Apply saves THAT rect explicitly (a concrete value, not null) —
  // there's currently no path back to the NULL "keep auto-fitting forever"
  // state once a slot has a manual crop.
  if (result === null) { return; }

  button.disabled = true;
  let saved;
  try {
    saved = await apiPost('api/book-page-photos-crop.php', {
      slot_id: Number(figure.dataset.slotId),
      rect: result,
    });
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
    return;
  } finally {
    button.disabled = false;
  }

  figure.dataset.crop = result ? JSON.stringify(result) : '';
  frame.querySelectorAll('img, .ks-slot-photo-bg').forEach((el) => el.remove());

  if (saved.css) {
    const bg = document.createElement('div');
    bg.className = 'ks-slot-photo-bg';
    bg.style.backgroundImage = `url('${figure.dataset.src}')`;
    bg.style.backgroundSize = saved.css.size;
    bg.style.backgroundPosition = saved.css.position;
    frame.prepend(bg);
  } else {
    const img = document.createElement('img');
    img.src = figure.dataset.src;
    img.alt = '';
    img.loading = 'lazy';
    frame.prepend(img);
  }

  showSnackbar(saved.cropped ? 'Crop adjusted.' : 'Crop reset to auto-fit.');
}

/* Subtitle tap-to-edit (brief §4.6) — the SAME endpoint and gesture Phase 3
   wired on review.php, reused here rather than duplicated. */
if (document.getElementById('subtitle-list')) {
  attachInlineEdit('#subtitle-list', {
    rowSelector: '.list-row',
    textSelector: '[data-role="subtitle"]',
    maxLength: 190,
    onSave: async (id, text) => {
      const result = await apiPost('api/year-projects-update.php', { id: Number(id), subtitle: text });
      const subtitleEl = document.querySelector('[data-role="subtitle"]');
      subtitleEl.classList.remove('muted');
      return result.subtitle || text;
    },
  });
}

/* --------------------------------------------------------- drag and drop - */
/* Native HTML5 drag events, delegated off `document` — see this file's
   header for why neither swipe.js nor reorder.js fits this gesture. */

let dragSlotId = null;

document.addEventListener('dragstart', (event) => {
  const slot = event.target.closest('.ks-slot-photo');
  if (!slot) { return; }
  dragSlotId = slot.dataset.slotId;
  event.dataTransfer.effectAllowed = 'move';
  // Firefox requires setData to be called for a drag to proceed at all.
  event.dataTransfer.setData('text/plain', dragSlotId);
  slot.classList.add('is-dragging');
});

document.addEventListener('dragend', (event) => {
  event.target.closest('.ks-slot-photo')?.classList.remove('is-dragging');
  dragSlotId = null;
  document.querySelectorAll('.ks-slot.is-drop-target, .ks-page.is-drop-target')
    .forEach((el) => el.classList.remove('is-drop-target'));
});

/* Checking closest('.ks-slot-photo') BEFORE closest('.ks-page[...]') is what
   makes dropping ON a photo swap, while dropping on the page's open
   background around its photos moves — the same element can match both
   selectors (a slot is always inside its page), and slot wins. */
function dropTarget(event) {
  const overSlot = event.target.closest('.ks-slot-photo');
  if (overSlot && overSlot.dataset.slotId !== dragSlotId) {
    return { kind: 'swap', el: overSlot };
  }
  const overPage = event.target.closest('.ks-page[data-page-type="photos"]');
  if (overPage) {
    return { kind: 'move', el: overPage };
  }
  return null;
}

document.addEventListener('dragover', (event) => {
  if (dragSlotId === null) { return; }
  const target = dropTarget(event);
  if (!target) { return; }
  event.preventDefault(); // required for 'drop' to fire at all
  event.dataTransfer.dropEffect = 'move';
});

document.addEventListener('dragenter', (event) => {
  if (dragSlotId === null) { return; }
  const target = dropTarget(event);
  document.querySelectorAll('.ks-slot.is-drop-target, .ks-page.is-drop-target')
    .forEach((el) => el.classList.remove('is-drop-target'));
  target?.el.classList.add('is-drop-target');
});

document.addEventListener('drop', async (event) => {
  if (dragSlotId === null) { return; }
  const target = dropTarget(event);
  if (!target) { return; }
  event.preventDefault();

  const slotId = dragSlotId;
  target.el.classList.remove('is-drop-target');

  if (target.kind === 'swap') {
    await swapSlots(slotId, target.el.dataset.slotId);
  } else {
    await moveSlot(slotId, target.el.dataset.pageId);
  }
});

async function swapSlots(slotIdA, slotIdB) {
  try {
    await apiPost('api/book-page-slots-swap.php', {
      slot_id_a: Number(slotIdA),
      slot_id_b: Number(slotIdB),
    });
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
    return;
  }

  // See this file's header, point 4: a swap can reshape either page's whole
  // composition tree, not just the two cells involved, so there's no DOM
  // patch that's fully described by "these two nodes traded content".
  showSnackbar('Swapped.');
  window.location.reload();
}

async function moveSlot(slotId, targetPageId) {
  const node = document.querySelector(`.ks-slot-photo[data-slot-id="${slotId}"]`);
  const sourcePage = node?.closest('.ks-page');
  if (!node || !sourcePage) { return; }
  if (sourcePage.dataset.pageId === String(targetPageId)) { return; } // dropped back on its own page

  try {
    await apiPost('api/book-page-slots-move.php', {
      slot_id: Number(slotId),
      target_page_id: Number(targetPageId),
    });
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
    return;
  }

  // See this file's header, point 4 — a move reshapes both the source and
  // destination page's composition tree, so this always reloads now (not
  // just the "emptied the source page" case Phase 8 originally reloaded for).
  showSnackbar('Moved.');
  window.location.reload();
}


/* ------------------------------------------------------------ page caption */

/* What the field held when it was focused, so blur can tell "she changed it"
   from "she tabbed through it" without a request either way. */
let captionBefore = null;

document.addEventListener('focusin', (event) => {
  const field = event.target.closest('.ks-page-caption');
  if (field) { captionBefore = field.textContent.trim(); }
});

document.addEventListener('focusout', async (event) => {
  const field = event.target.closest('.ks-page-caption');
  if (!field || captionBefore === null) { return; }

  const before = captionBefore;
  captionBefore = null;

  const text = field.textContent.trim();
  if (text === before) { return; }

  /* Emptying the line means "go back to what the photos say", not "print an
     empty caption" — the two are different states on the page row, and null is
     the one that lets a later caption edit flow through again. Someone who
     genuinely wants a bare page has the photos' captions to clear. */
  const caption = text === '' ? null : text;

  field.classList.add('is-saving');
  try {
    const res = await apiPost('api/book-pages-caption.php', {
      page_id: Number(field.dataset.pageId),
      caption,
    });
    /* Redraw from the response rather than from what was typed: clearing an
       override brings the derived line back, and the server is the only thing
       that knows what that line says. */
    field.textContent = res.caption;
    field.dataset.original = res.caption;
  } catch (err) {
    field.textContent = field.dataset.original || '';
    showSnackbar(describe(err));
  } finally {
    field.classList.remove('is-saving');
  }
});

/* Enter commits rather than opening a second line: this is one printed line,
   and a stray newline in it would be invisible here and wrong in the PDF. */
document.addEventListener('keydown', (event) => {
  const field = event.target.closest('.ks-page-caption');
  if (!field) { return; }
  if (event.key === 'Enter') {
    event.preventDefault();
    field.blur();
  }
  if (event.key === 'Escape') {
    event.preventDefault();
    field.textContent = field.dataset.original || '';
    captionBefore = null;
    field.blur();
  }
});
