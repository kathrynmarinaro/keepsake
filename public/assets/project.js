/* The project screen's own chrome (public/project.php), on both tabs.
 *
 * Only the kebab. Everything inside a tab is review.js's or layout.js's, and
 * this module deliberately knows nothing about either — it loads alongside
 * whichever one the tab asked for, so the menu behaves identically on both
 * without either of them growing a copy of it.
 *
 * The three entries are projectMenuItems() from project-menu.js, imported
 * rather than re-declared: the kebab on this screen and the kebab on this
 * project's card in the list are the same menu about the same thing.
 */

import { attachMenu, closeMenu } from './menu.js';
import { projectMenuItems } from './project-menu.js';

const button = document.querySelector('.screen-head .icon-btn');
const id     = Number(document.body.dataset.yearProjectId || 0);

if (button && id > 0) {
  const title = document.querySelector('.screen-head h1')?.textContent?.trim() || 'this project';

  attachMenu(button, {
    label: title,
    items: projectMenuItems(id, title, {
      /* From the body's data-*, not from the header text: the header's second
         line shows the YEAR when a book has been renamed, so reading it would
         offer to save "2025" as the subtitle. */
      title: document.body.dataset.projectTitle || '',
      subtitle: document.body.dataset.projectSubtitle || '',
      /* Deleting the project you are looking at cannot reload this screen —
         there is nothing here any more, and project.php would render its
         "that project doesn't exist" state, which is correct but a dead end.
         Rename does reload: the header, the <title> and every link on the
         screen carry the old name. */
      onDeleted: () => { window.location.href = 'index.php'; },
    }),
  });
}

window.addEventListener('pageshow', (event) => {
  if (event.persisted) { closeMenu(); }
});
