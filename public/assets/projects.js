/* The project list screen (public/index.php).
 *
 * Three controls, all of them menus: the hamburger (app-level actions), one
 * kebab per project card (that project's actions), and the floating + (which
 * project am I adding to?).
 *
 * The three per-card actions live in project-menu.js, shared with the project
 * screen's own kebab — see that file for why it is a separate module rather
 * than an export from this one.
 *
 * WHY DELETE AND RENAME REFRESH THE PAGE instead of patching the DOM: the card
 * carries a derived status ("layout generated", "in progress") that this
 * module has no way to recompute, and the empty state is a different block
 * entirely. A reload after a once-in-a-while action is not the thing to
 * optimise; a list that disagrees with the database is.
 */

import { apiPost, ApiError } from './api.js';
import { attachMenu, closeMenu } from './menu.js';
import { showSnackbar } from './swipe.js';
import { promptText } from './confirm.js';
import { projectMenuItems } from './project-menu.js';

/* ------------------------------------------------------------------ helpers */

/** A sentence for the user out of whatever went wrong. */
function describe(err) {
  if (err instanceof ApiError) {
    if (err.code === 'unauthorized') { return 'Signed out — reload and sign in again.'; }
    return err.detail || err.message || 'That did not work.';
  }
  return 'That did not work.';
}

/* ------------------------------------------------------------------- wiring */

/** The app menu. Two entries today; both are also real URLs. */
attachMenu('.screen-head .icon-btn', {
  label: 'Keepsake menu',
  items: [
    { label: 'Export all data', href: 'api/year-projects-export.php?all=1' },
    { label: 'Log out', form: '#logout-form', danger: true },
  ],
});

/* One kebab per card, wired up front. attachMenu() opens on the trigger's own
   click, so a delegated document listener would have to re-dispatch that click
   to get the sheet open — and then guard against its own synthetic click
   coming back round. Not worth it for a list that is a handful of rows: this
   is the same wiring, without the trick. */
document.querySelectorAll('.project-card').forEach((card) => {
  const button = card.querySelector('[data-project-menu]');
  if (!button) { return; }

  const id    = Number(card.dataset.project);
  const title = card.dataset.title || 'Untitled project';

  attachMenu(button, {
    label: title,
    items: projectMenuItems(id, title),
  });
});

/* --------------------------------------------------------------- the plus */

/* From the LIST screen there is no project in context, so + has to ask which
   one — that is exactly the question Kathryn asked it to ask, and it is why
   this is a button raising a sheet rather than a link to capture.php. Inside a
   project the same + is a plain link, because there is nothing to ask. */
const fab = document.getElementById('add-fab');

if (fab) {
  attachMenu(fab, {
    label: 'Add to which project?',
    items: [
      {
        label: 'New project…',
        onSelect: async () => {
          const name = await promptText({
            title: 'New project',
            body: 'A year, or a name — “Iceland” makes a book that is not tied to a year at all.',
            placeholder: 'Iceland',
            confirmLabel: 'Create',
          });
          if (name === null) { return; }

          try {
            const created = await apiPost('api/year-projects-create.php', { name });
            window.location.href = created.url;
          } catch (err) {
            showSnackbar(describe(err), { isError: true });
          }
        },
      },
      /* Every existing project, so adding to one you can see is one tap and
         not a detour through opening it first. capture.php reads ?project= and
         pins whatever is saved to it, whatever the date says. */
      ...projectCards().map(({ id, title }) => ({
        label: title,
        href: 'capture.php?project=' + encodeURIComponent(String(id)),
      })),
    ],
  });
}

/* Closing the menu on a back/forward restore: a bfcache'd page can come back
   with a sheet still in the DOM from before the navigation, and it would be
   sitting over a page whose buttons all still work underneath it. */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) { closeMenu(); }
});
