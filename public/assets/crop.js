/* Crop overlay.
 *
 *   const rect = await openCropper(src);                          // free-form
 *   const rect = await openCropper(src, { lockAspect, initial });  // pan/zoom
 *
 * Returns the selection in NORMALISED coordinates — fractions of the displayed
 * image, not pixels. Pixel coordinates would silently cut the wrong region if
 * the displayed size and the size the server crops against ever disagree, and
 * fractions survive that no matter what.
 *
 * Ported close to verbatim from Inspiration Board's public/assets/crop.js —
 * see PLAN.md's "What to port from Inspiration Board" — with one adjustment:
 * Keepsake crops directly against the kept full-resolution original (no
 * separate "detail" copy exists — see lib/imageproc.php's header for why),
 * so `src` here is photos.original_path, not a 1600px derivative.
 *
 * No dependencies and no build step, matching the rest of the app: the whole
 * interaction is pointer events against one absolutely positioned box.
 *
 * POST-LAUNCH ADDITION, `opts.lockAspect` (public/layout.php's "Adjust crop"
 * — see PLAN.md): a book page's composition tree gives a photo slot a FIXED
 * target shape, so that slot's crop isn't free-form — it's a pan-and-zoom
 * within one aspect ratio (width/height). Every corner handle still works
 * exactly as before EXCEPT resizing now holds the aspect fixed (the box
 * grows/shrinks along whichever axis the drag moved more, the other axis
 * follows), and "Reset" goes back to the largest centered box at that aspect
 * instead of the full image. `opts.initial`, if given, seeds the starting box
 * (editing a crop that already exists) instead of that centered default.
 * Neither option changes anything for the two existing free-form callers
 * (photo-batch.js, review.js) — they don't pass opts, so lockAspect stays
 * null and every branch below falls through to the original behavior.
 */

/** Matches IMAGEPROC_MIN_CROP server-side — reject the same crops it would. */
const MIN_FRAC = 0.02;

/** Handles, as [name, x, y] anchors in normalised box space. */
const HANDLES = [
  ['nw', 0, 0],
  ['ne', 1, 0],
  ['sw', 0, 1],
  ['se', 1, 1],
];

/** Largest box at `aspect` (width/height) centered in the unit square. */
function centeredBoxAt(aspect) {
  let w = 1;
  let h = 1 / aspect;
  if (h > 1) {
    h = 1;
    w = aspect;
  }
  return { x: (1 - w) / 2, y: (1 - h) / 2, w, h };
}

export function openCropper(src, opts = {}) {
  const lockAspect = opts.lockAspect || null;

  return new Promise((resolve) => {
    const root = document.createElement('div');
    root.className = 'cropper';
    root.innerHTML = `
      <div class="cropper-stage">
        <div class="cropper-frame">
          <img class="cropper-img" alt="">
          <div class="cropper-sel">
            <div class="cropper-grid" aria-hidden="true"></div>
            ${HANDLES.map(([n]) => `<span class="cropper-handle is-${n}" data-handle="${n}"></span>`).join('')}
          </div>
        </div>
      </div>
      <div class="cropper-bar">
        <button class="link-btn" data-act="cancel">Cancel</button>
        <span class="cropper-size" data-role="size"></span>
        <button class="btn-ghost" data-act="reset">Reset</button>
        <button class="btn-primary" data-act="apply">Crop</button>
      </div>`;

    const img = root.querySelector('.cropper-img');
    const stage = root.querySelector('.cropper-stage');
    const frame = root.querySelector('.cropper-frame');
    const sel = root.querySelector('.cropper-sel');
    const sizeOut = root.querySelector('[data-role="size"]');

    /**
     * Size the frame to the largest box the stage can hold at the image's own
     * aspect ratio, in pixels.
     *
     * This cannot be left to CSS. `max-height: 100%` on the image resolves
     * against the frame, whose height is content-based — and a percentage
     * against an indefinite height is treated as `none`, so any image taller
     * than the stage rendered at full natural height and ran off the bottom of
     * the screen, taking the button bar with it.
     *
     * The frame has to end up exactly the painted image, not merely contain it:
     * the selection is measured as fractions of the frame, so a letterboxed
     * frame would put every crop rect off by the size of the letterbox.
     */
    function fit() {
      const nw = img.naturalWidth;
      const nh = img.naturalHeight;
      if (!nw || !nh) return;

      const cs = getComputedStyle(stage);
      const availW = stage.clientWidth
        - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      const availH = stage.clientHeight
        - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
      if (availW <= 0 || availH <= 0) return;

      // Upscaling a small image is allowed: placing a crop accurately matters
      // more here than keeping it pixel-sharp.
      const scale = Math.min(availW / nw, availH / nh);
      frame.style.width = `${Math.round(nw * scale)}px`;
      frame.style.height = `${Math.round(nh * scale)}px`;
    }

    /**
     * `lockAspect` is a REAL-WORLD ratio (e.g. "this slot is rendered 1.8:1
     * wide" — public/assets/layout.js reads it straight off the DOM), but
     * every box in this file is a FRACTION of the displayed image, which has
     * its OWN aspect ratio. A box that's `lockAspect` wide-for-tall AS A
     * FRACTION only comes out `lockAspect` wide-for-tall ON SCREEN if the
     * source image is itself square — for any other photo the fraction-space
     * aspect has to be scaled by the image's own aspect first. Needs
     * naturalWidth/Height, so this can't run until the image has loaded.
     */
    function lockFraction() {
      if (!lockAspect) { return null; }
      const nw = img.naturalWidth;
      const nh = img.naturalHeight;
      return nw && nh ? lockAspect / (nw / nh) : lockAspect;
    }

    // The selection, in fractions of the image.
    // What "Reset" goes back to, vs. what the cropper actually OPENED with —
    // the same thing unless opts.initial seeds an existing crop, in which
    // case Reset still means "start over from scratch" but "untouched" (see
    // the apply handler below) means "didn't change what was already saved".
    // opts.initial is already in fraction space (it's a rect THIS SAME tool
    // saved before), so it needs no conversion — only the computed default
    // does, and only once naturalWidth/Height are known (see lockFraction()),
    // so both start as a placeholder and are corrected in the img 'load'
    // handler below.
    let defaultBox = lockAspect ? centeredBoxAt(lockAspect) : { x: 0, y: 0, w: 1, h: 1 };
    let startBox = opts.initial ? { ...opts.initial } : { ...defaultBox };
    let box = { ...startBox };

    function paint() {
      sel.style.left = `${box.x * 100}%`;
      sel.style.top = `${box.y * 100}%`;
      sel.style.width = `${box.w * 100}%`;
      sel.style.height = `${box.h * 100}%`;

      // Reported against the natural size, so the number means the pixels the
      // crop will actually keep rather than the pixels on screen.
      const nw = img.naturalWidth || 0;
      const nh = img.naturalHeight || 0;
      sizeOut.textContent = nw && nh
        ? `${Math.round(box.w * nw)} × ${Math.round(box.h * nh)}`
        : '';
    }

    function reset() {
      box = { ...defaultBox };
      paint();
    }

    // Rotating a phone changes which axis is the binding one, so the frame has
    // to be recomputed rather than kept from open time.
    const onResize = () => { fit(); paint(); };
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onResize);

    /* ---------------------------------------------------------- dragging */

    let drag = null;

    function pointToFrac(e) {
      const r = frame.getBoundingClientRect();
      return {
        x: r.width ? (e.clientX - r.left) / r.width : 0,
        y: r.height ? (e.clientY - r.top) / r.height : 0,
      };
    }

    const clamp01 = (v) => Math.max(0, Math.min(1, v));

    function onDown(e) {
      const handle = e.target.dataset?.handle;
      if (!handle && e.target !== sel && !sel.contains(e.target)) return;

      e.preventDefault();
      drag = {
        handle: handle || 'move',
        start: pointToFrac(e),
        origin: { ...box },
      };
      e.target.setPointerCapture?.(e.pointerId);
    }

    function onMove(e) {
      if (!drag) return;
      e.preventDefault();

      const now = pointToFrac(e);
      const dx = now.x - drag.start.x;
      const dy = now.y - drag.start.y;
      const o = drag.origin;

      if (drag.handle === 'move') {
        // Slide within the image; the size is fixed, so clamp the origin.
        box.x = Math.max(0, Math.min(1 - o.w, o.x + dx));
        box.y = Math.max(0, Math.min(1 - o.h, o.y + dy));
        paint();
        return;
      }

      // Resize from the grabbed corner, holding the opposite one still.
      const west = drag.handle === 'nw' || drag.handle === 'sw';
      const north = drag.handle === 'nw' || drag.handle === 'ne';

      const right = o.x + o.w;
      const bottom = o.y + o.h;

      const aspect = lockFraction();
      if (aspect) {
        // Pan/zoom within a fixed shape: the box may only grow or shrink,
        // never change proportions. The axis the drag moved MORE (in width-
        // equivalent terms) drives the resize; the other is derived to hold
        // the aspect — same "dominant axis wins" idea a free resize already
        // has per-axis, just resolved to one shared scale here. Uses the
        // FRACTION-space aspect (see lockFraction()), not the real-world one
        // opts.lockAspect was given in — dx/dy/w/h are all fractions here.
        const growX = west ? -dx : dx;
        const growY = north ? -dy : dy;
        const growW = Math.abs(growX) >= Math.abs(growY * aspect) ? growX : growY * aspect;

        // Bound BOTH dimensions jointly: how far w can grow before ITS OWN
        // edge leaves the image, and how far it can grow before the
        // aspect-derived h would push ITS edge out — take the tighter.
        const maxWSelf = west ? right : (1 - o.x);
        const maxHOther = north ? bottom : (1 - o.y);
        const maxW = Math.min(maxWSelf, maxHOther * aspect);

        const w = Math.max(MIN_FRAC, Math.min(maxW, o.w + growW));
        const h = w / aspect;

        box = {
          x: west ? right - w : o.x,
          y: north ? bottom - h : o.y,
          w,
          h,
        };
        paint();
        return;
      }

      let x = west ? clamp01(o.x + dx) : o.x;
      let y = north ? clamp01(o.y + dy) : o.y;
      let w = west ? right - x : clamp01(right + dx) - o.x;
      let h = north ? bottom - y : clamp01(bottom + dy) - o.y;

      // Stop at the minimum rather than letting the corner cross the anchor
      // and invert the box.
      if (w < MIN_FRAC) {
        w = MIN_FRAC;
        if (west) x = right - MIN_FRAC;
      }
      if (h < MIN_FRAC) {
        h = MIN_FRAC;
        if (north) y = bottom - MIN_FRAC;
      }

      box = { x, y, w, h };
      paint();
    }

    function onUp(e) {
      if (!drag) return;
      e.target.releasePointerCapture?.(e.pointerId);
      drag = null;
    }

    sel.addEventListener('pointerdown', onDown);
    root.addEventListener('pointermove', onMove);
    root.addEventListener('pointerup', onUp);
    root.addEventListener('pointercancel', onUp);

    /* ------------------------------------------------------------ chrome */

    function finish(value) {
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', onResize);
      window.removeEventListener('orientationchange', onResize);
      root.remove();
      resolve(value);
    }

    function onKey(e) {
      if (e.key === 'Escape') finish(null);
    }
    document.addEventListener('keydown', onKey);

    root.addEventListener('click', (e) => {
      const act = e.target.dataset?.act;
      if (act === 'cancel') finish(null);
      if (act === 'reset') reset();
      if (act === 'apply') {
        // A selection identical to what the cropper opened with (startBox)
        // is a no-op — don't spend a re-encode (free-form) or a save
        // (locked) to produce the same result. Free-form opens at the full
        // frame, so this is the same "w=h=1, x=y=0" check as before; locked
        // opens at defaultBox, or opts.initial if editing an existing crop,
        // so "untouched" means "didn't change it", not "covers everything".
        const EPS = 0.001;
        const same = (a, b) =>
          Math.abs(a.x - b.x) < EPS && Math.abs(a.y - b.y) < EPS
          && Math.abs(a.w - b.w) < EPS && Math.abs(a.h - b.h) < EPS;
        finish(same(box, startBox) ? null : { ...box });
      }
    });

    img.addEventListener('load', () => {
      fit();                          // needs naturalWidth, so not before load

      // defaultBox needs the image's real aspect (lockFraction()), which
      // isn't known until now — recompute it, and only OVERWRITE the current
      // selection with it if there was no opts.initial to seed from (an
      // initial rect is already correct fraction-space and shouldn't be
      // discarded just because the image finished loading).
      defaultBox = lockAspect ? centeredBoxAt(lockFraction()) : { x: 0, y: 0, w: 1, h: 1 };
      if (!opts.initial) {
        startBox = { ...defaultBox };
        box = { ...defaultBox };
      }
      paint();
    });
    img.src = src;

    document.body.append(root);
    paint();
  });
}
