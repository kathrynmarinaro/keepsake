/* Do lib/compose.php and the layout lab agree, rectangle for rectangle?
 *
 * The lab is what Kathryn reviewed and approved, page by page, over five
 * rounds. The production composer is a port of its solver. Nothing enforces
 * that the two stay in step except this file: an innocuous-looking change to
 * either one can move a photo half a millimetre on paper and no test that
 * looks at only one side would notice.
 *
 * So this drives BOTH solvers over the real 123-photo book and compares the
 * output exactly. Any difference at all is a failure — the tolerance is zero,
 * because the two implementations do the same arithmetic in the same order and
 * there is no reason for them to disagree even in the last bit.
 *
 * Run: node tools/verify-parity.mjs <lab2.html>
 *   where lab2.html is a build of tools/layout-lab2.php.
 */
import { readFileSync, writeFileSync, unlinkSync } from 'fs';
import { execFileSync } from 'child_process';
import { tmpdir } from 'os';
import { join } from 'path';

const labPath = process.argv[2];
if (!labPath) {
  console.error('usage: node tools/verify-parity.mjs <lab2.html>');
  process.exit(2);
}

/* Run the lab's own emitted solver, not a copy of it — a copy is the exact
 * thing that would silently rot. */
const html = readFileSync(labPath, 'utf8');
const script = html.slice(html.indexOf('<script>') + 8, html.lastIndexOf('</script>'));
const stub = {
  addEventListener() {}, replaceChildren() {}, appendChild() {}, setAttribute() {},
  querySelectorAll: () => [], set innerHTML(_) {}, set textContent(_) {}, style: {}, dataset: {},
};
globalThis.document = {
  getElementById: () => stub,
  createElement: () => ({ ...stub, style: {}, appendChild() {}, set className(_) {}, set innerHTML(_) {} }),
  createDocumentFragment: () => ({ appendChild() {} }),
};
const lab = new Function(script + '; return { solve, bind, PAGES, TEMPLATES, PHOTOS };')();

const FILL = 0.85;   // the margin Kathryn chose; COMPOSE_FILL must match

const plan = lab.PAGES.map(pg => ({
  tpl: pg.tpl,
  ar: pg.ids.map(i => lab.PHOTOS[i].ar),
  shape: pg.ids.map(i => lab.PHOTOS[i].s),
}));
const jsRects = lab.PAGES.map(pg =>
  lab.solve(lab.bind(lab.TEMPLATES[pg.tpl].tree, pg.ids), 'grid', FILL));

const planFile = join(tmpdir(), `parity-plan-${process.pid}.json`);
writeFileSync(planFile, JSON.stringify({ fill: FILL, pages: plan }));

let phpRects;
try {
  phpRects = JSON.parse(execFileSync('php', ['-r', `
    require getenv('COMPOSE_LIB');
    $plan = json_decode(file_get_contents($argv[1]), true);
    $out = array();
    foreach ($plan['pages'] as $pg) {
        $occ = array();
        foreach ($pg['ar'] as $i => $ar) { $occ[] = array('shape' => $pg['shape'][$i], 'ar' => $ar); }
        $out[] = compose_solve(compose_templates()[$pg['tpl']], $occ, (float) $plan['fill']);
    }
    print json_encode($out);
  `, planFile], {
    env: { ...process.env, COMPOSE_LIB: new URL('../lib/compose.php', import.meta.url).pathname },
    encoding: 'utf8',
  }));
} finally {
  unlinkSync(planFile);
}

let mismatches = 0, worst = 0;
jsRects.forEach((page, p) => page.forEach((r, i) => {
  const q = phpRects[p][i];
  for (const k of ['x', 'y', 'w', 'h', 'crop']) {
    const d = Math.abs(r[k] - q[k]);
    if (d > worst) worst = d;
    if (d > 0) {
      if (mismatches < 10) console.log(`  page ${p + 1} slot ${i} ${k}: js ${r[k]} vs php ${q[k]}`);
      mismatches++;
    }
  }
}));

console.log(`compared ${jsRects.length} pages`);
if (mismatches) {
  console.log(`FAILED — ${mismatches} differing values, worst ${worst.toExponential(2)}% of page`);
  process.exit(1);
}
console.log('OK — the production composer and the approved lab agree exactly');
