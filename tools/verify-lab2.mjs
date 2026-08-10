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
        const rects = solve(bind(tpl.tree, pg.ids), policy, fill, tpl.grid);
        const where = `${label}[${i}] ${pg.tpl} ${policy} ${fill}`;

        if (rects.length !== tpl.n) bad.push(`${where}: ${rects.length} rects, want ${tpl.n}`);

        for (const r of rects) {
          // 1. aspect ratio preserved -> no crop, no stretch
          const got = r.w / r.h, want = PHOTOS[r.id].ar;
          if (Math.abs(got - want) / want > 2e-3) {
            bad.push(`${where}: photo ${r.id} ar ${got.toFixed(4)} != ${want.toFixed(4)}`);
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
        /* 4. The complaint that prompted the grid policy: in a 2x2 the top and
         * bottom dividers must be the same line. Checked as "every photo has
         * the same width, and there are exactly two distinct left edges" —
         * which is what a person means by the lines agreeing. */
        if (tpl.grid && policy === 'grid') {
          const w0 = rects[0].w;
          if (rects.some(r => Math.abs(r.w - w0) > 0.01)) {
            bad.push(`${where}: grid cells differ in width (${rects.map(r => r.w.toFixed(2)).join(', ')})`);
          }
          const lefts = [...new Set(rects.map(r => r.x.toFixed(3)))];
          if (lefts.length !== 2) {
            bad.push(`${where}: ${lefts.length} distinct column edges, want 2 (${lefts.join(', ')})`);
          }
        }
        checked++;
      });
    }
  }
}

console.log(`checked ${checked} page renderings`);
if (bad.length) { console.log(`FAIL (${bad.length}):`); bad.slice(0, 15).forEach(b => console.log('  ' + b)); process.exit(1); }
console.log('OK — aspect ratios exact, no overlap, all within page');
