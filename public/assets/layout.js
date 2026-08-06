/* Book-layouts screen controller — public/layout.php.
 *
 * Two buttons and nothing else: generate a new version, and pick which
 * version is the active one. Both RELOAD the page on success rather than
 * patching the DOM, following review.js's own split: anything that changes
 * which rows exist (a whole new layout, a moved pointer) re-renders from the
 * server state that was just committed, because a hand-rolled patch of a
 * version list and a page inspector could drift from it.
 *
 * Delegated off `document`, same as review.js, so nothing has to be re-bound
 * after a reload or a future partial render.
 */

import { apiPost, ApiError } from './api.js';
import { showSnackbar } from './swipe.js';

function describe(err) {
  if (err instanceof ApiError && err.detail) { return err.detail; }
  if (err instanceof ApiError) { return err.code; }
  return 'Something went wrong.';
}

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-act]');
  if (!button) { return; }

  const action = button.dataset.act;
  if (action !== 'generate' && action !== 'activate') { return; }

  /* Generating a full year's book is the one action in this app that can take
     a noticeable moment — disable the button while it runs so a second click
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
});
