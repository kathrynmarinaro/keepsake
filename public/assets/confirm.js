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
 * Ask for one or more lines of text — a new project's name, a book's title and
 * subtitle together.
 *
 * Same sheet, same lifecycle as confirmDanger(), opposite disposition: the
 * primary button is filled teal rather than red, and it is what Enter
 * triggers, because nothing here destroys anything.
 *
 *   const values = await promptFields({
 *     title: 'Rename project',
 *     fields: [
 *       { name: 'title',    label: 'Title',    value: 'Iceland' },
 *       { name: 'subtitle', label: 'Subtitle', value: 'June 2026' },
 *     ],
 *   });
 *   if (values === null) { return; }        // cancelled
 *   values.title; values.subtitle;          // trimmed strings
 *
 * WHY FIELDS RATHER THAN ONE VALUE. A book's title and subtitle are one
 * decision — they print together on the cover, and renaming a book while
 * leaving a subtitle that no longer fits it is the mistake two separate
 * dialogs invite. promptText() below is this function with one field, kept
 * because "what shall this be called" really is a single question.
 *
 * @param {object} opts
 * @param {string} opts.title REQUIRED. The dialog's heading.
 * @param {string} [opts.body] A line of explanation under it.
 * @param {Array} opts.fields REQUIRED, at least one. Each is
 *        { name, label?, value?, placeholder?, required? }. `required` fields
 *        keep the primary button disabled until they have something in them,
 *        rather than letting an empty string through for the caller to
 *        re-validate; every field defaults to NOT required, because clearing a
 *        subtitle is a thing you are allowed to do.
 * @param {string} [opts.confirmLabel='Save']
 * @param {string} [opts.cancelLabel='Cancel']
 * @returns {Promise<object|null>} trimmed values by field name, or null if
 *          cancelled.
 */
export function promptFields(opts = {}) {
  const {
    title,
    body = '',
    fields = [],
    confirmLabel = 'Save',
    cancelLabel = 'Cancel',
  } = opts;

  if (typeof title !== 'string' || title === '') {
    throw new TypeError('promptFields: title is required');
  }
  if (!Array.isArray(fields) || fields.length === 0) {
    throw new TypeError('promptFields: at least one field is required');
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

    const inputs = [];
    fields.forEach((field) => {
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'input sheet-input';
      input.value = field.value ?? '';
      input.placeholder = field.placeholder ?? '';
      /* Off, all four. These are proper nouns she is inventing — a trip name,
         a book title — and iOS autocapitalising and autocorrecting one into a
         word it already knows is worse than no help at all. */
      input.autocapitalize = 'off';
      input.autocomplete = 'off';
      input.autocorrect = 'off';
      input.spellcheck = false;

      /* A label only when there is more than one field to tell apart. One
         unlabelled box under a heading that already asks the question does not
         need "Name:" written above it. */
      if (field.label) {
        const wrap = document.createElement('label');
        wrap.className = 'field sheet-field';
        const span = document.createElement('span');
        span.textContent = field.label;
        wrap.appendChild(span);
        wrap.appendChild(input);
        panel.appendChild(wrap);
      } else {
        panel.appendChild(input);
      }

      inputs.push({ field, input });
    });

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

    const complete = () => inputs.every(
      ({ field, input }) => !field.required || input.value.trim() !== ''
    );
    const sync = () => { okBtn.disabled = !complete(); };
    inputs.forEach(({ input }) => input.addEventListener('input', sync));
    sync();

    /* A <form> so the phone keyboard's blue key says "Go" and submits, which
       is the whole reason this is not a div full of listeners: on iOS a bare
       input in a dialog gives you a "return" key that inserts a newline into a
       single-line field and does nothing else. */
    panel.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!complete()) { return; }

      const values = {};
      inputs.forEach(({ field, input }) => { values[field.name] = input.value.trim(); });
      finish(values);
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

    inputs[0].input.focus();
    inputs[0].input.select();
  });
}

/**
 * Ask for one line of text. promptFields() with a single unlabelled field.
 *
 *   const name = await promptText({
 *     title: 'New project',
 *     placeholder: 'Iceland',
 *     confirmLabel: 'Create',
 *   });
 *   if (name === null) { return; }   // cancelled
 *
 * @param {object} opts As promptFields(), minus `fields`, plus `value`,
 *        `placeholder`, and `allowEmpty` (default false — the one-field case
 *        usually IS required, which is the opposite of promptFields' default).
 * @returns {Promise<string|null>} the trimmed text, or null if cancelled.
 */
export function promptText(opts = {}) {
  const { value = '', placeholder = '', allowEmpty = false, ...rest } = opts;

  return promptFields({
    ...rest,
    fields: [{ name: 'value', value, placeholder, required: !allowEmpty }],
  }).then((result) => (result === null ? null : result.value));
}
