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
 *      slot. Both patch the DOM in place on success rather than reloading —
 *      unlike the structural actions above, a swap/move's effect is fully
 *      described by "these two DOM nodes trade parents/positions", so a
 *      reload would be a slower way to show exactly what's already visible.
 */

import { apiPost, ApiError } from './api.js';
import { showSnackbar } from './swipe.js';
import { attachInlineEdit } from './inline-edit.js';
import { openPhotoPicker } from './photo-picker.js';

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
  const nodeA = document.querySelector(`.ks-slot-photo[data-slot-id="${slotIdA}"]`);
  const nodeB = document.querySelector(`.ks-slot-photo[data-slot-id="${slotIdB}"]`);
  if (!nodeA || !nodeB) { return; }

  try {
    await apiPost('api/book-page-slots-swap.php', {
      slot_id_a: Number(slotIdA),
      slot_id_b: Number(slotIdB),
    });
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
    return;
  }

  // The request only swapped CONTENT (photo_id) between the two rows (see
  // lib/repo.php's book_page_slot_swap()), so the DOM patch is exactly the
  // mirror of that: swap the two nodes' positions, and each node keeps its
  // own slot-id (the row identity never moved) while what's INSIDE it is now
  // the other photo — so their inner img/figcaption/data-photo-id swap too.
  const markerA = document.createComment('');
  nodeA.before(markerA);
  nodeB.before(nodeA);
  markerA.replaceWith(nodeB);

  showSnackbar('Swapped.');
}

async function moveSlot(slotId, targetPageId) {
  const node = document.querySelector(`.ks-slot-photo[data-slot-id="${slotId}"]`);
  const targetSlots = document.querySelector(`.ks-page[data-page-id="${targetPageId}"] .ks-slots`);
  if (!node || !targetSlots) { return; }

  const sourceSlots = node.closest('.ks-slots');
  if (sourceSlots === targetSlots) { return; } // dropped back on its own page

  let result;
  try {
    result = await apiPost('api/book-page-slots-move.php', {
      slot_id: Number(slotId),
      target_page_id: Number(targetPageId),
    });
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
    return;
  }

  targetSlots.append(node);
  targetSlots.dataset.count = String(targetSlots.querySelectorAll('.ks-slot').length);
  if (sourceSlots) {
    sourceSlots.dataset.count = String(sourceSlots.querySelectorAll('.ks-slot').length);
  }
  showSnackbar('Moved to page ' + (document.querySelector(`.ks-page[data-page-id="${targetPageId}"]`)?.dataset.pageNumber ?? '') + '.');
}
