/* Crop overlay.
 *
 *   const rect = await openCropper(src);   // null if cancelled
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

export function openCropper(src) {
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

    // The selection, in fractions of the image.
    let box = { x: 0, y: 0, w: 1, h: 1 };

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
      box = { x: 0, y: 0, w: 1, h: 1 };
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
        // A full-frame selection is a no-op, not a crop — don't spend a
        // re-encode and a colour re-extraction to produce the same image.
        const untouched =
          box.w > 0.999 && box.h > 0.999 && box.x < 0.001 && box.y < 0.001;
        finish(untouched ? null : { ...box });
      }
    });

    img.addEventListener('load', () => {
      fit();                          // needs naturalWidth, so not before load
      reset();
    });
    img.src = src;

    document.body.append(root);
    paint();
  });
}
