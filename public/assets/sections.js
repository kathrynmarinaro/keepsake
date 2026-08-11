/* The heading-and-body rows on a snapshot form.
 *
 * A snapshot used to be two fixed templates with nine columns between them —
 * Age and Height, or Grade / School / Teacher / Favorite color / Dream job /
 * Favorite class — and the form showed one template's inputs at a time.
 * Kathryn wants a page for her own 40th next to Emma's 8th, with whatever
 * headings each deserves, so the fields became a list you build: any number of
 * sections, each a heading and a body, both freely typed.
 *
 * The TYPE dropdown survives and still does something useful: it seeds a NEW
 * entry's sections with that template's headings. After that it is just a
 * list. Changing the type on a form you have already typed into does not
 * silently rewrite it — see applyTemplate().
 *
 * ONE MODULE, TWO FORMS. The add form (public/capture.php) and the edit form
 * inside the entry modal (lib/views/content.php) are the same editor over the
 * same shape, and the endpoints take the same `sections` array from both.
 *
 * NO IDS ON A SECTION. The server replaces the whole list on every save
 * (snapshot_sections_replace), because a section has no identity beyond its
 * position and nothing links to one. That is why this module can add, remove
 * and reorder rows freely without tracking anything.
 *
 * DEGRADATION: with no JS the rows PHP rendered are still real inputs inside
 * the form and still submit — you just cannot add or remove any. That is why
 * the row markup is built the same way here and on the server.
 */

/** The name attributes the server reads. Index is positional, not an id. */
function nameFor(index, part) {
  return `sections[${index}][${part}]`;
}

/** One editable row: heading, body, and a remove button. */
function buildRow(heading = '', body = '') {
  const row = document.createElement('div');
  row.className = 'section-row';
  row.dataset.role = 'section-row';

  row.innerHTML = `
    <div class="section-row-head">
      <input type="text" class="input section-heading" data-role="section-heading"
             placeholder="Section title" autocomplete="off">
      <button type="button" class="icon-btn section-remove" data-act="remove-section"
              aria-label="Remove this section">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <textarea class="section-body" data-role="section-body" rows="2"
              placeholder="Section content" autocomplete="off"></textarea>`;

  /* Set as values rather than interpolated into the HTML above: a heading
     containing a quote mark would otherwise break out of the attribute, and
     these are strings Kathryn typed. */
  row.querySelector('[data-role="section-heading"]').value = heading;
  row.querySelector('[data-role="section-body"]').value = body;

  return row;
}

/**
 * Wire a container as a sections editor.
 *
 * @param {Element|string} container The element holding the rows.
 * @param {object} [opts]
 * @param {Array} [opts.sections] Rows to start with, [{heading, body}, …].
 *        Ignored if the container already holds server-rendered rows.
 * @returns {object} { read, setTemplate, isEmpty }
 */
export function attachSections(container, opts = {}) {
  const root = typeof container === 'string' ? document.querySelector(container) : container;
  if (!root) { return { read: () => [], setTemplate: () => {}, isEmpty: () => true }; }

  const list = root.querySelector('[data-role="section-list"]') || root;
  const addBtn = root.querySelector('[data-act="add-section"]');

  /* Server-rendered rows win over anything passed in: the edit form already
     has the saved sections in the page, and re-seeding over them would throw
     away what is on screen. */
  if (list.querySelector('[data-role="section-row"]') === null && Array.isArray(opts.sections)) {
    opts.sections.forEach((s) => list.appendChild(buildRow(s.heading || '', s.body || '')));
  }

  function renumber() {
    list.querySelectorAll('[data-role="section-row"]').forEach((row, i) => {
      row.querySelector('[data-role="section-heading"]').name = nameFor(i, 'heading');
      row.querySelector('[data-role="section-body"]').name = nameFor(i, 'body');
    });
  }
  renumber();

  function add(heading = '', body = '', focus = true) {
    const row = buildRow(heading, body);
    list.appendChild(row);
    renumber();
    if (focus) { row.querySelector('[data-role="section-heading"]').focus(); }
    return row;
  }

  addBtn?.addEventListener('click', () => add());

  root.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-act="remove-section"]');
    if (!remove) { return; }
    event.preventDefault();
    remove.closest('[data-role="section-row"]')?.remove();
    renumber();
  });

  /** The rows as the endpoints want them, in the order they are shown. */
  function read() {
    return Array.from(list.querySelectorAll('[data-role="section-row"]')).map((row) => ({
      heading: row.querySelector('[data-role="section-heading"]').value.trim(),
      body: row.querySelector('[data-role="section-body"]').value.trim(),
    }));
  }

  /** True when there is nothing worth saving — every row blank, or no rows. */
  function isEmpty() {
    return read().every((s) => s.heading === '' && s.body === '');
  }

  /**
   * Replace the rows with a template's headings.
   *
   * REFUSES WHEN ANYTHING HAS BEEN TYPED. Switching the type dropdown after
   * filling in Emma's age should not wipe it — the dropdown's job is to give a
   * new entry a running start, not to reset one in progress. Returns false so
   * the caller can leave the dropdown where the user put it.
   */
  function setTemplate(headings) {
    if (!isEmpty()) { return false; }

    list.querySelectorAll('[data-role="section-row"]').forEach((row) => row.remove());
    headings.forEach((h) => add(h, '', false));
    renumber();
    return true;
  }

  return { read, setTemplate, isEmpty, add };
}
