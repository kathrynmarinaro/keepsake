/* Opening an entry opens it full screen, instead of expanding it in place.
 *
 * WHY. Every entry on the Content tab is a <details> accordion: tap the
 * summary, it expands, and the form pushes everything below it down the page.
 * On a phone that means the thing you just opened is half off the bottom of
 * the screen, the list has jumped under your thumb, and closing it jumps it
 * back. With a grid of a hundred photos it is worse — the row you opened
 * reflows the entire grid.
 *
 * HOW, AND WHY IT IS NOT A REWRITE. The <details> element is MOVED into a
 * fixed overlay and opened there, leaving a placeholder behind; closing moves
 * it back. It is the same element, still in the document, so every delegated
 * listener in review.js goes on working without knowing this module exists —
 * `closest('.entry')`, `dataset.id`, the submit handler, the toggle pills, the
 * cropper. Rebuilding the form inside a dialog would have meant a second copy
 * of markup that PHP already renders, kept in sync by hand.
 *
 * That is also why it moves rather than clones. A clone would have the same
 * data-id as the original and both would answer to the same delegated handler,
 * so a save would update one and leave the other showing the old values.
 *
 * DEGRADATION: without this module every entry is still a working <details>
 * that expands in place, which is exactly what it did before. Nothing here is
 * the only way to reach anything.
 */

import { closeMenu } from './menu.js';

/* One at a time. Two open entries would mean two forms bound to the same
   delegated submit handler and a second placeholder to keep track of. */
let open = null;

/**
 * Put the entry back where it came from and tear the overlay down.
 *
 * Safe to call when nothing is open, so callers — Escape, Cancel, a successful
 * save, a backdrop tap — do not each need their own guard.
 */
export function closeEntry() {
  if (open === null) { return; }

  const { details, placeholder, overlay, onKeydown, restoreFocus } = open;
  open = null;

  document.removeEventListener('keydown', onKeydown);

  /* Back to its own place in the list, not appended to the end of it — the
     placeholder is the only thing that remembers where in a hundred-photo grid
     this tile was. */
  details.open = false;
  details.classList.remove('is-modal');
  placeholder.replaceWith(details);

  overlay.remove();
  document.body.classList.remove('has-entry-modal');

  /* Put focus back on the entry that was opened. Without this it lands on
     <body> and the next Tab starts from the top of the document — which on a
     screen reader means being read the whole page again. */
  const target = restoreFocus && document.contains(restoreFocus) ? restoreFocus : details;
  target?.querySelector?.('summary')?.focus?.() ?? target?.focus?.();
}

/** Move `details` into a fresh overlay and open it. */
function openInModal(details) {
  if (open !== null) { closeEntry(); }

  /* Any sheet raised from the header would sit above this one and outlive it,
     since it is appended to <body> too. */
  closeMenu();

  const restoreFocus = details.querySelector('summary');

  const placeholder = document.createElement('span');
  placeholder.hidden = true;
  details.replaceWith(placeholder);

  const overlay = document.createElement('div');
  overlay.className = 'entry-modal';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Edit entry');

  const panel = document.createElement('div');
  panel.className = 'entry-modal-panel';

  /* The X, top right, as Kathryn asked. A real button before the content so it
     is the first thing tabbed to and the first thing a screen reader reaches —
     the way out should not be at the bottom of a long form. */
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'entry-modal-close icon-btn';
  close.setAttribute('aria-label', 'Close');
  close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  close.addEventListener('click', closeEntry);
  panel.appendChild(close);

  details.classList.add('is-modal');
  details.open = true;
  panel.appendChild(details);

  overlay.appendChild(panel);

  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) { closeEntry(); }
  });

  const onKeydown = (event) => {
    if (event.key !== 'Escape') { return; }
    /* Not while the cropper is up: crop.js puts its own full-screen surface
       over this one, and Escape there means "cancel the crop", not "close the
       entry". Closing this from underneath would leave the cropper attached to
       an element that is no longer in the document. */
    if (document.querySelector('.cropper')) { return; }
    event.preventDefault();
    closeEntry();
  };
  document.addEventListener('keydown', onKeydown);

  document.body.appendChild(overlay);
  document.body.classList.add('has-entry-modal');

  open = { details, placeholder, overlay, onKeydown, restoreFocus };

  /* Cancel only means anything while this is open — see the comment on the
     button in lib/views/content.php. */
  details.querySelectorAll('.entry-cancel').forEach((btn) => { btn.hidden = false; });

  /* Scrolled to the top: the panel is reused for a tall snapshot form and a
     short quote alike, and a panel that opens mid-form looks like a rendering
     bug. */
  panel.scrollTop = 0;
}

/* ------------------------------------------------------------------ wiring */

/* Intercepting the SUMMARY's click, rather than listening for `toggle` on the
   <details>: by the time toggle fires the browser has already expanded the
   element in place, so there is a frame where the page reflows before the
   overlay covers it. */
document.addEventListener('click', (event) => {
  const summary = event.target.closest('.entry > summary');
  if (!summary) { return; }

  /* The summary carries the two toggle pills and Recrop — real buttons that
     act on the photo without opening anything. Their clicks bubble through
     here on the way to review.js's own delegated handler. */
  if (event.target.closest('button')) { return; }

  const details = summary.parentElement;

  /* Inside the overlay the summary is a header, not a control: letting the
     native toggle through would collapse the form you are looking at and leave
     an overlay containing a closed accordion. The way out is the X. */
  event.preventDefault();
  if (details.classList.contains('is-modal')) { return; }

  openInModal(details);
});

document.addEventListener('click', (event) => {
  if (event.target.closest('[data-act="cancel"]')) {
    event.preventDefault();
    closeEntry();
  }
});

/* review.js dispatches this after a save the server accepted. Saving and then
   having to close the thing yourself is the sort of small friction that makes
   editing forty captions feel like eighty actions. */
document.addEventListener('keepsake:entry-saved', closeEntry);

/* An entry that the save removed from this project — a date correction that
   moved it — takes the overlay with it, since there is nothing left to put
   back. */
document.addEventListener('keepsake:entry-removed', (event) => {
  if (open !== null && event.detail?.details === open.details) {
    const { placeholder, overlay, onKeydown } = open;
    open = null;
    document.removeEventListener('keydown', onKeydown);
    placeholder.remove();
    overlay.remove();
    document.body.classList.remove('has-entry-modal');
  }
});

/* A bfcache restore can bring back a page with the overlay still in the DOM
   and the entry still moved out of the list underneath it. */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) { closeEntry(); }
});

/* ARRIVING FROM THE BOOK TAB. A text slot on the Book tab links here as
   #entry-quote-12, because the detail form lives on this screen and only this
   screen renders it. Landing on the anchor scrolls to the entry but leaves it
   closed, which is not what the link promised, so this opens it.
 
   The summary is CLICKED rather than the details being opened directly: the
   click path above is the one that puts it in the overlay, and going straight
   to `details.open` would expand it in place — the very reflow this module
   exists to avoid. */
function openFromHash() {
  const match = /^#entry-(quote|anecdote|snapshot|photo)-(\d+)$/.exec(window.location.hash || '');
  if (!match) { return; }

  const details = document.getElementById(`entry-${match[1]}-${match[2]}`);
  const summary = details && details.querySelector(':scope > summary');
  if (!summary) { return; }

  summary.click();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', openFromHash);
} else {
  openFromHash();
}

/* Back/forward between two entries on the same screen. */
window.addEventListener('hashchange', openFromHash);
