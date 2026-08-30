/* Phase 69 — guest pass: the same axe + keyboard check, no cookies (the role
 * matrix's anonymous row: what a signed-out visitor actually gets). */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.WP_BASE_URL || 'http://127.0.0.1:9400';
const OUT = '/home/user/IGBZ-WP/visual-testing/phase-69';
const AXE = '/home/user/IGBZ-WP/_devenv/.work/node_modules/axe-core/axe.min.js';

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].join(':');
  const browser = await chromium.launch({
    executablePath: path.join(dest, 'chromium'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless=new'],
    headless: true,
  });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const axeSource = fs.readFileSync(AXE, 'utf8');
  const report = {};

  const pages = [
    { name: 'guest-storefront', url: BASE + '/' },
    { name: 'guest-cart', url: BASE + '/%d8%b3%d8%a8%d8%af-%d8%ae%d8%b1%db%8c%d8%af/' },
    { name: 'guest-otp', url: BASE + '/?igbz_page=otp' },
  ];

  for (const spec of pages) {
    await page.goto(spec.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(1200);
    const violations = await page.evaluate(async (src) => {
      try {
        window.eval(src);
        const r = await window.axe.run(document, {
          runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
        });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, sample: (v.nodes[0].target || []).join(' ').slice(0, 90) }));
      } catch (e) {
        return [{ id: 'axe-error', impact: 'critical', nodes: 0, sample: String(e).slice(0, 120) }];
      }
    }, axeSource);
    await page.screenshot({ path: `${OUT}/${spec.name}-mobile.png`, fullPage: true });
    report[spec.name] = violations;
  }
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
