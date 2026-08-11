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
import { confirmDanger, promptFields } from './confirm.js';

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
 *
 * `title` here is the DISPLAY title — what the book is called, which for an
 * unnamed book is its year. `opts.title` is the stored one, which may be empty,
 * and is what the rename dialog must pre-fill: seeding the box with "2025"
 * because that is what the card says would turn every unnamed book into one
 * literally titled "2025" the first time its subtitle was edited.
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
      onSelect: () => renameProject(id, {
        title: opts.title ?? title,
        subtitle: opts.subtitle ?? '',
        onDone: onRenamed,
      }),
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
 * Rename a book: its title AND its subtitle, in one dialog.
 *
 * BOTH, because they are one decision. They print together on the cover, and a
 * dialog that changes the title while leaving a subtitle that no longer fits it
 * is the mistake two separate controls invite. It is also why the Book tab's
 * "Title & cover" panel calls THIS rather than carrying its own tap-to-edit
 * fields — same question, same dialog, wherever you ask it from.
 *
 * Either may be cleared. An empty title puts the book back to being called by
 * its year (year_project_title()); an empty subtitle prints no second line.
 * That is why neither field is `required`.
 *
 * @param {number} id
 * @param {object} [opts]
 * @param {string} [opts.title] Current title. Read from the DOM by the caller,
 *        since both callers already have it on screen.
 * @param {string} [opts.subtitle] Current subtitle.
 * @param {() => void} [opts.onDone] Defaults to reloading, because the header,
 *        the <title> and the cover preview all carry the old text.
 */
export async function renameProject(id, opts = {}) {
  const done = typeof opts.onDone === 'function' ? opts.onDone : () => window.location.reload();

  const values = await promptFields({
    title: 'Book title & subtitle',
    body: 'Both print on the cover. Clear the title and the book goes back to being called by its year; clear the subtitle and the cover carries the title alone.',
    fields: [
      { name: 'title', label: 'Title', value: opts.title ?? '', placeholder: 'e.g. Iceland' },
      { name: 'subtitle', label: 'Subtitle', value: opts.subtitle ?? '', placeholder: 'optional' },
    ],
    confirmLabel: 'Save',
  });
  if (values === null) { return; }

  try {
    await apiPost('api/year-projects-update.php', {
      id,
      title: values.title,
      subtitle: values.subtitle,
    });
    done();
  } catch (err) {
    showSnackbar(describe(err), { isError: true });
  }
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
