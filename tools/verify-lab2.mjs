/* Pulls the solver out of the generated lab and checks the invariants the whole
 * exercise depends on: no photo is distorted, nothing overlaps, nothing leaves
 * the page. Runs the real emitted file, not a copy of the source. */
import { readFileSync } from 'fs';

const html = readFileSync(process.argv[2], 'utf8');
const script = html.slice(html.indexOf('<script>') + 8, html.lastIndexOf('</script>'));

// Stub out the DOM the render half of the script touches.
const stub = { addEventListener(){}, replaceChildren(){}, appendChild(){}, setAttribute(){},
               querySelectorAll: () => [], set innerHTML(_){}, style:{}, dataset:{}, classList:{add(){}} };
globalThis.document = {
  getElementById: () => stub,
  createElement: () => ({ ...stub, style: {}, appendChild(){}, set className(_){}, set innerHTML(_){} }),
  createDocumentFragment: () => ({ appendChild(){} }),
};

const mod = new Function(script + '; return { solve, bind, PAGES, CATALOGUE, TEMPLATES, PHOTOS };')();
const { solve, bind, PAGES, CATALOGUE, TEMPLATES, PHOTOS } = mod;

let checked = 0, bad = [];
const EPS = 1e-4;

for (const [label, list] of [['book', PAGES], ['catalogue', CATALOGUE]]) {
  for (const policy of ['grid', 'ratio', 'even']) {
    for (const fill of [0.60, 0.72, 0.85]) {
      list.forEach((pg, i) => {
        const tpl = TEMPLATES[pg.tpl];
        const rects = solve(bind(tpl.tree, pg.ids), policy, fill);
        const where = `${label}[${i}] ${pg.tpl} ${policy} ${fill}`;

        if (rects.length !== tpl.n) bad.push(`${where}: ${rects.length} rects, want ${tpl.n}`);

        for (const r of rects) {
          /* 1. A photo is only ever reshaped to match same-shape neighbours,
           * and the rect must then agree with the crop it claims. Anything
           * with crop 0 keeps its exact native ratio. */
          const got = r.w / r.h, want = PHOTOS[r.id].ar;
          const off = Math.abs(got - want) / want;
          if (r.crop === 0 && off > 2e-3) {
            bad.push(`${where}: photo ${r.id} ar ${got.toFixed(4)} != ${want.toFixed(4)} with crop 0`);
          }
          if (r.crop > 0) {
            const claimed = 1 - Math.min(got, want) / Math.max(got, want);
            if (Math.abs(claimed - r.crop) > 2e-3) {
              bad.push(`${where}: photo ${r.id} crop ${r.crop.toFixed(4)} != actual ${claimed.toFixed(4)}`);
            }
            if (policy === 'ratio') bad.push(`${where}: photo ${r.id} cropped under the no-crop policy`);
          }
          // 2. inside the page
          if (r.x < -EPS || r.y < -EPS || r.x + r.w > 100 + EPS || r.y + r.h > 100 + EPS) {
            bad.push(`${where}: photo ${r.id} out of bounds ${r.x.toFixed(2)},${r.y.toFixed(2)} ${r.w.toFixed(2)}x${r.h.toFixed(2)}`);
          }
          if (r.w <= 0 || r.h <= 0) bad.push(`${where}: photo ${r.id} non-positive size`);
        }
        // 3. no overlap
        for (let a = 0; a < rects.length; a++) {
          for (let b = a + 1; b < rects.length; b++) {
            const p = rects[a], q = rects[b];
            const ox = Math.min(p.x + p.w, q.x + q.w) - Math.max(p.x, q.x);
            const oy = Math.min(p.y + p.h, q.y + q.h) - Math.max(p.y, q.y);
            if (ox > 0.01 && oy > 0.01) bad.push(`${where}: ${p.id} overlaps ${q.id} by ${ox.toFixed(3)}x${oy.toFixed(3)}`);
          }
        }
        /* 4. The complaint that prompted all of this: same-shape photos sitting
         * together must be the same size. Cells tagged with the same group id
         * came from one uniform row or column and must match exactly. */
        const groups = new Map();
        for (const r of rects) if (r.grp !== undefined) {
          if (!groups.has(r.grp)) groups.set(r.grp, []);
          groups.get(r.grp).push(r);
        }
        for (const [g, cells] of groups) {
          const a = cells[0];
          if (cells.some(c => Math.abs(c.w - a.w) > 1e-6 || Math.abs(c.h - a.h) > 1e-6)) {
            bad.push(`${where}: group ${g} cells differ (${cells.map(c => c.w.toFixed(2) + 'x' + c.h.toFixed(2)).join(', ')})`);
          }
        }
        checked++;
      });
    }
  }
}

console.log(`checked ${checked} page renderings`);
if (bad.length) { console.log(`FAIL (${bad.length}):`); bad.slice(0, 15).forEach(b => console.log('  ' + b)); process.exit(1); }
console.log('OK — ratios honoured, matched groups identical, no overlap, all within page');
