/* The three actions that belong to one project: rename, export, delete.
 *
 * ITS OWN MODULE, with no side effects at all, because two screens need it and
 * they need it for the same reason: the kebab on a project's card in the list
 * and the kebab in that project's own header are the same menu about the same
 * thing. Two copies would eventually disagree about what "Export data" means.
 *
 * The reason this is not simply exported from projects.js — where it started —
 * is that projects.js wires the LIST screen on import: its hamburger, its
 * cards, its +. Importing it from the project screen to reach one function
 * would run all of that too, and `attachMenu('.screen-head .icon-btn')` would
 * cheerfully wire the project screen's KEBAB with the list screen's app menu.
 * A module with side effects is not importable for its exports.
 */

import { apiPost, apiGet, ApiError } from './api.js';
import { showSnackbar } from './swipe.js';
import { confirmDanger, promptText } from './confirm.js';

/** A sentence for the user out of whatever went wrong. */
function describe(err) {
  if (err instanceof ApiError) {
    if (err.code === 'unauthorized') { return 'Signed out — reload and sign in again.'; }
    return err.detail || err.message || 'That did not work.';
  }
  return 'That did not work.';
}

/* ------------------------------------------------------- project-level menu */

/**
 * The three per-project actions. Shared verbatim with the project screen's own
 * kebab (project.js builds the same list) — same glyph, same scope, same three
 * entries, which is the whole convention lib/page.php's header describes.
 */
export function projectMenuItems(id, title, opts = {}) {
  /* Two callbacks, not one. A rename wants the screen re-rendered — the
     header, the <title> and half the links carry the old name. A delete on the
     project's OWN screen has to leave it, because there is nothing there any
     more; on the list screen both are the same reload. Defaulting each to a
     reload keeps the list screen's call site to two arguments. */
  const reload    = () => window.location.reload();
  const onRenamed = typeof opts.onRenamed === 'function' ? opts.onRenamed : reload;
  const onDeleted = typeof opts.onDeleted === 'function' ? opts.onDeleted : reload;

  return [
    {
      label: 'Rename',
      onSelect: async () => {
        const name = await promptText({
          title: 'Rename project',
          body: 'This is the name on the cover. Clearing it puts the book back to being called by its year.',
          value: title,
          confirmLabel: 'Rename',
          allowEmpty: true,
        });
        if (name === null) { return; }

        try {
          await apiPost('api/year-projects-update.php', { id, title: name });
          onRenamed();
        } catch (err) {
          showSnackbar(describe(err), { isError: true });
        }
      },
    },
    {
      /* A real link, not a fetch: the response is a file download, and letting
         the browser navigate to it is what makes "save to Files" work on a
         phone. menu.js renders href items as <a>, so this also survives a long
         press and an open-in-new-tab. */
      label: 'Export data',
      href: 'api/year-projects-export.php?id=' + encodeURIComponent(String(id)),
    },
    {
      label: 'Delete',
      danger: true,
      onSelect: async () => {
        /* Ask the server what is in there before asking her to confirm. A
           dialog that can say "123 photos, 4 groups and 2 layouts" is one she
           can check against the project she meant; "are you sure?" is not. */
        let counts = null;
        try {
          counts = (await apiGet('api/year-projects-delete.php', { id })).counts;
        } catch (err) {
          showSnackbar(describe(err), { isError: true });
          return;
        }

        const ok = await confirmDanger({
          title: 'Delete ' + title + '?',
          body: describeContents(counts)
            + ' The photo files are deleted from the server too. This is permanent and cannot be undone.',
          confirmLabel: 'Delete project',
        });
        if (!ok) { return; }

        try {
          await apiPost('api/year-projects-delete.php', { id });
          onDeleted();
        } catch (err) {
          showSnackbar(describe(err), { isError: true });
        }
      },
    },
  ];
}

/**
 * "123 photos, 4 groups and 2 layouts" — only the parts that are not zero, so
 * an empty project does not get a sentence full of noughts to read past.
 */
function describeContents(counts) {
  if (!counts) { return 'This deletes the project and everything in it.'; }

  const parts = [];
  const push = (n, one, many) => {
    if (n > 0) { parts.push(n + ' ' + (n === 1 ? one : many)); }
  };

  push(counts.photos, 'photo', 'photos');
  push(counts.quotes, 'quote', 'quotes');
  push(counts.anecdotes, 'anecdote', 'anecdotes');
  push(counts.snapshots, 'snapshot', 'snapshots');
  push(counts.groups, 'group', 'groups');
  push(counts.layouts, 'layout', 'layouts');

  if (parts.length === 0) { return 'This project is empty.'; }

  const last = parts.pop();
  const list = parts.length === 0 ? last : parts.join(', ') + ' and ' + last;
  return 'This deletes ' + list + '.';
}
