/* A confirmation dialog for actions that cannot be undone.
 *
 * WHY THIS EXISTS RATHER THAN window.confirm(). The rest of the app uses the
 * browser's confirm for "delete this quote", and that is fine — a quote is
 * thirty characters and retyping it costs nothing. Deleting a PROJECT unlinks
 * the photo files, so there is nothing left to restore from and no snackbar
 * that could promise otherwise. That deserves a dialog that can:
 *
 *   - name what is about to go, on its own line, in a size you actually read
 *   - put the destructive word on the destructive button ("Delete project",
 *     not "OK"), because "OK" is what you tap to make a dialog go away
 *   - make Cancel the biggest, nearest thing to your thumb
 *
 * window.confirm can do none of those, and on iOS it prefixes the message with
 * the hostname, which makes a deliberate warning look like a browser error.
 *
 * BUILT ON .sheet, like menu.js, and for the same reason its header gives: an
 * empty fixed-position element sitting in every page is one z-index mistake
 * away from swallowing every tap underneath it. It is created on demand and
 * removed on close.
 *
 * NO CSS OF ITS OWN beyond what the stylesheet already carries for sheets —
 * .sheet / .sheet-panel / .sheet-cancel, plus .sheet-danger for the one button
 * that needed a colour the menu never used.
 *
 * DEGRADATION: there is none, deliberately. A caller awaits this before doing
 * something destructive, so if the module fails to load, the destructive thing
 * never runs. Failing closed is the correct direction here.
 */

/* One dialog at a time, module-global — the same rule menu.js uses for the
   sheet and swipe.js for the snackbar. */
let openDialog = null;

function close() {
  if (openDialog === null) { return; }

  const { el, onKeydown, restoreFocus } = openDialog;
  openDialog = null;

  document.removeEventListener('keydown', onKeydown);
  el.remove();

  if (restoreFocus && typeof restoreFocus.focus === 'function') {
    restoreFocus.focus();
  }
}

/**
 * Ask before doing something irreversible.
 *
 *   const ok = await confirmDanger({
 *     title: 'Delete 2025?',
 *     body: 'This removes 123 photos and 2 layouts. It cannot be undone.',
 *     confirmLabel: 'Delete project',
 *   });
 *   if (!ok) { return; }
 *
 * @param {object} opts
 * @param {string} opts.title REQUIRED. The question, as a question.
 * @param {string} [opts.body] What will actually happen. Rendered as plain
 *        text — never as HTML, since callers build it from a book title
 *        Kathryn typed.
 * @param {string} [opts.confirmLabel='Delete'] The destructive button. Say the
 *        verb and the noun; never "OK".
 * @param {string} [opts.cancelLabel='Cancel']
 * @returns {Promise<boolean>} true only if the destructive button was tapped.
 */
export function confirmDanger(opts = {}) {
  const {
    title,
    body = '',
    confirmLabel = 'Delete',
    cancelLabel = 'Cancel',
  } = opts;

  if (typeof title !== 'string' || title === '') {
    throw new TypeError('confirmDanger: title is required');
  }

  /* A second dialog while one is open would stack backdrops and leave the
     first promise unsettled forever. Refuse rather than queue: nothing in this
     app legitimately asks two irreversible questions at once. */
  if (openDialog !== null) {
    return Promise.resolve(false);
  }

  return new Promise((resolve) => {
    const restoreFocus = document.activeElement;

    const el = document.createElement('div');
    el.className = 'sheet';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', title);

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    const heading = document.createElement('p');
    heading.className = 'sheet-title';
    heading.textContent = title;
    panel.appendChild(heading);

    if (body !== '') {
      const detail = document.createElement('p');
      detail.className = 'sheet-body';
      detail.textContent = body;
      panel.appendChild(detail);
    }

    /* Settle once and only once, whichever way we leave. Without the guard,
       a backdrop tap during the closing animation could resolve a promise the
       Confirm button already resolved. */
    let settled = false;
    const finish = (value) => {
      if (settled) { return; }
      settled = true;
      close();
      resolve(value);
    };

    const confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'sheet-danger';
    confirmBtn.textContent = confirmLabel;
    confirmBtn.addEventListener('click', () => finish(true));
    panel.appendChild(confirmBtn);

    /* Cancel last, as in every other sheet in the suite — it is the row
       nearest the bottom edge and therefore nearest the thumb, which is what
       you want to be true of the harmless option. */
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'sheet-cancel';
    cancelBtn.textContent = cancelLabel;
    cancelBtn.addEventListener('click', () => finish(false));
    panel.appendChild(cancelBtn);

    el.appendChild(panel);

    el.addEventListener('click', (event) => {
      if (event.target === el) { finish(false); }
    });

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        finish(false);
      }
    };
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(el);
    openDialog = { el, onKeydown, restoreFocus };

    /* Focus CANCEL, not Confirm. A dialog that opens with the destructive
       button focused turns a stray Enter — from the keypress that opened it —
       into the delete itself. */
    cancelBtn.focus();
  });
}

/**
 * Ask for one line of text — a new project's name, a rename.
 *
 * Same sheet, same lifecycle, opposite disposition: the primary button is
 * .btn-primary rather than .sheet-danger, and it is what Enter triggers,
 * because nothing here destroys anything.
 *
 *   const name = await promptText({
 *     title: 'New project',
 *     placeholder: 'Iceland, 2026',
 *     confirmLabel: 'Create',
 *   });
 *   if (name === null) { return; }   // cancelled
 *
 * @param {object} opts
 * @param {string} opts.title REQUIRED.
 * @param {string} [opts.body] A line of explanation under the title.
 * @param {string} [opts.value] Pre-filled, and selected on open, so a rename
 *        can be typed straight over.
 * @param {string} [opts.placeholder]
 * @param {string} [opts.confirmLabel='Save']
 * @param {string} [opts.cancelLabel='Cancel']
 * @param {boolean} [opts.allowEmpty=false] When false — the default — the
 *        primary button stays disabled until something is typed, rather than
 *        letting an empty string through for the caller to re-validate.
 * @returns {Promise<string|null>} the trimmed text, or null if cancelled.
 */
export function promptText(opts = {}) {
  const {
    title,
    body = '',
    value = '',
    placeholder = '',
    confirmLabel = 'Save',
    cancelLabel = 'Cancel',
    allowEmpty = false,
  } = opts;

  if (typeof title !== 'string' || title === '') {
    throw new TypeError('promptText: title is required');
  }

  if (openDialog !== null) {
    return Promise.resolve(null);
  }

  return new Promise((resolve) => {
    const restoreFocus = document.activeElement;

    const el = document.createElement('div');
    el.className = 'sheet';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', title);

    const panel = document.createElement('form');
    panel.className = 'sheet-panel';

    const heading = document.createElement('p');
    heading.className = 'sheet-title';
    heading.textContent = title;
    panel.appendChild(heading);

    if (body !== '') {
      const detail = document.createElement('p');
      detail.className = 'sheet-body';
      detail.textContent = body;
      panel.appendChild(detail);
    }

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'input sheet-input';
    input.value = value;
    input.placeholder = placeholder;
    /* Off, all four. This is a proper noun she is inventing — a trip name, a
       book title — and iOS autocapitalising and autocorrecting it into a word
       it already knows is worse than no help at all. */
    input.autocapitalize = 'off';
    input.autocomplete = 'off';
    input.autocorrect = 'off';
    input.spellcheck = false;
    panel.appendChild(input);

    let settled = false;
    const finish = (result) => {
      if (settled) { return; }
      settled = true;
      close();
      resolve(result);
    };

    const okBtn = document.createElement('button');
    okBtn.type = 'submit';
    okBtn.className = 'btn-primary sheet-primary';
    okBtn.textContent = confirmLabel;
    panel.appendChild(okBtn);

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'sheet-cancel';
    cancelBtn.textContent = cancelLabel;
    cancelBtn.addEventListener('click', () => finish(null));
    panel.appendChild(cancelBtn);

    const sync = () => {
      okBtn.disabled = !allowEmpty && input.value.trim() === '';
    };
    input.addEventListener('input', sync);
    sync();

    /* A <form> so the phone keyboard's blue key says "Go" and submits, which
       is the whole reason this is not a div full of listeners: on iOS a bare
       input in a dialog gives you a "return" key that inserts a newline into a
       single-line field and does nothing else. */
    panel.addEventListener('submit', (event) => {
      event.preventDefault();
      const text = input.value.trim();
      if (!allowEmpty && text === '') { return; }
      finish(text);
    });

    el.appendChild(panel);

    el.addEventListener('click', (event) => {
      if (event.target === el) { finish(null); }
    });

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        finish(null);
      }
    };
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(el);
    openDialog = { el, onKeydown, restoreFocus };

    input.focus();
    input.select();
  });
}
