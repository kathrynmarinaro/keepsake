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
 * IT USED TO BE "pick one of what I recently captured" — the last 24 uploaded,
 * globally. That was the wrong list for the one job it has: a hero photo for a
 * birthday page is a photo OF that birthday, which is nowhere near the most
 * recent 24 when a book is assembled months later. Given a project it now
 * offers every photo in it, newest first, and the sheet scrolls.
 *
 * Shares its open/close Promise shape with crop.js's openCropper() for the
 * same reason as ever: one predictable pattern for "a full-screen picker
 * resolves to a value or null", not a bespoke callback wiring per caller.
 */

import { apiGet } from './api.js';

/**
 * @param {object} [opts]
 * @param {string} [opts.title]
 * @param {number} [opts.yearProjectId] Scope to one project's photos, newest
 *        first. Omitted, it falls back to the most recently uploaded across
 *        every project — see public/api/photos-recent.php.
 * @returns {Promise<object|null>} the chosen photo row, or null if cancelled
 */
export function openPhotoPicker({ title = 'Choose a photo', yearProjectId = 0 } = {}) {
  return new Promise((resolve) => {
    const root = document.createElement('div');
    root.className = 'sheet';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', title);

    root.innerHTML = `
      <div class="sheet-panel">
        <p class="hint" data-role="status">Loading photos…</p>
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
        photos = await apiGet(
          'api/photos-recent.php',
          yearProjectId > 0 ? { year_project_id: yearProjectId } : undefined
        );
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
