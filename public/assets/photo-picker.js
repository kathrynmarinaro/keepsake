/* Recent-photos picker: pick one already-uploaded photo to attach to
 * something else.
 *
 * One caller today: a snapshot's hero-photo selector ("Kathryn selects
 * manually" — brief §2.3 — not auto-pulled). An earlier version also backed
 * the quick-add quote/anecdote form's photo-bundling step; that was removed
 * on request — a quote/anecdote is never attached to a photo (see
 * public/api/quotes.php's header) — leaving this module in place since the
 * hero-photo use case still needs it.
 *
 * "Pick one of what I recently captured", not a full year-browsing gallery
 * — that's Phase 3's review/browse screen, not this one. Shares its
 * open/close Promise shape with crop.js's openCropper() for the same reason:
 * one predictable pattern for "a full-screen picker resolves to a value or
 * null", not a bespoke callback wiring per caller.
 */

import { apiGet } from './api.js';

/**
 * @param {object} [opts]
 * @param {string} [opts.title]
 * @returns {Promise<object|null>} the chosen photo row, or null if cancelled
 */
export function openPhotoPicker({ title = 'Choose a photo' } = {}) {
  return new Promise((resolve) => {
    const root = document.createElement('div');
    root.className = 'sheet';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', title);

    root.innerHTML = `
      <div class="sheet-panel">
        <p class="hint" data-role="status">Loading recent photos…</p>
        <div class="photopicker-grid" data-role="grid" hidden></div>
        <button type="button" class="sheet-cancel" data-act="cancel">Cancel</button>
      </div>`;

    function finish(value) {
      root.remove();
      resolve(value);
    }

    root.addEventListener('click', (e) => {
      if (e.target === root || e.target.dataset?.act === 'cancel') { finish(null); }
    });

    function onKey(e) {
      if (e.key === 'Escape') { finish(null); }
    }
    document.addEventListener('keydown', onKey, { once: true });

    document.body.append(root);

    (async () => {
      const status = root.querySelector('[data-role="status"]');
      const grid = root.querySelector('[data-role="grid"]');

      let photos = [];
      try {
        photos = await apiGet('api/photos-recent.php');
      } catch {
        status.textContent = "Couldn't load recent photos.";
        return;
      }

      if (!photos.length) {
        status.textContent = 'No photos uploaded yet.';
        return;
      }

      status.hidden = true;
      grid.hidden = false;

      for (const photo of photos) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'photopicker-item';
        button.innerHTML = `<img class="thumb" alt="" src="${photo.thumb_url ?? ''}">`;
        button.addEventListener('click', () => finish(photo));
        grid.append(button);
      }
    })();
  });
}
