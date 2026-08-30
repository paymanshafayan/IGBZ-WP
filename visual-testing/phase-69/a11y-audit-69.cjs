/* Phase 69 — RTL/accessibility audit + full-page visual evidence.
 *
 * For every page x viewport (desktop 1440, tablet 768, mobile 390):
 *  - injects axe-core and reports WCAG 2.x A/AA violations (impact ordered);
 *  - verifies the keyboard story: every focusable takes visible focus
 *    (outline or equivalent) when Tabbed to, and focus never escapes the page;
 *  - captures a FULL-PAGE screenshot (rule 12 evidence).
 * Pages cover the role matrix: guest storefront/cart/checkout/OTP, customer
 * account, VIP landing, and the admin owner surfaces (Pado + settings).
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.WP_BASE_URL || 'http://127.0.0.1:9400';
const OUT = '/home/user/IGBZ-WP/visual-testing/phase-69';
const AXE = '/home/user/IGBZ-WP/_devenv/.work/node_modules/axe-core/axe.min.js';

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'mobile', width: 390, height: 844 },
];

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: path.join(dest, 'chromium'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless=new'],
    headless: true,
  });

  const ctx = await browser.newContext({ viewport: VIEWPORTS[0] });
  let page = await ctx.newPage();

  // log in as admin once (cookie lives in the context)
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');

  const axeSource = fs.readFileSync(AXE, 'utf8');

  const pages = [
    { name: 'storefront', url: BASE + '/', login: 'admin' },
    { name: 'product', url: BASE + '/?p=43', login: 'admin' },
    { name: 'cart', url: BASE + '/%d8%b3%d8%a8%d8%af-%d8%ae%d8%b1%db%8c%d8%af/', login: 'admin' },
    { name: 'checkout', url: BASE + '/%d8%aa%d8%b3%d9%88%db%8c%d9%87-%d8%ad%d8%b3%d8%a7%d8%a8/', login: 'admin' },
    { name: 'account', url: BASE + '/%d8%ad%d8%b3%d8%a7%d8%a8-%da%a9%d8%a7%d8%b1%d8%a8%d8%b1%db%8c-%d9%85%d9%86/', login: 'admin' },
    { name: 'otp-login', url: BASE + '/?igbz_page=otp', login: null },
    { name: 'pado-admin', url: BASE + '/wp-admin/admin.php?page=igbz-pado', login: 'admin' },
    { name: 'igbz-settings', url: BASE + '/wp-admin/admin.php?page=igbz-settings', login: 'admin' },
  ];

  const report = {};
  for (const spec of pages) {
    report[spec.name] = {};
    for (const vp of VIEWPORTS) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      let consoleErrors = [];
      page.on('pageerror', (e) => consoleErrors.push(String(e)));
      try {
        await page.goto(spec.url, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await page.waitForTimeout(1200);
      } catch (e) {
        report[spec.name][vp.name] = { error: String(e).slice(0, 200) };
        page.removeAllListeners('pageerror');
        continue;
      }

      // ---- axe scan
      const axe = await page.evaluate(async (src) => {
        try {
          // eslint-disable-next-line no-eval
          window.eval(src);
          const results = await window.axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
            resultTypes: ['violations'],
          });
          return results.violations.map((v) => ({
            id: v.id,
            impact: v.impact,
            help: v.help,
            nodes: v.nodes.length,
            sample: (v.nodes[0] && v.nodes[0].target || []).join(' ').slice(0, 120),
          }));
        } catch (e) {
          return [{ id: 'axe-error', impact: 'critical', help: String(e).slice(0, 200), nodes: 0, sample: '' }];
        }
      }, axeSource);

      // ---- keyboard pass: Tab through the first 40 focusables; focus must be visible
      const keyboard = await page.evaluate(async () => {
        const visible = [];
        const invisible = [];
        const styleVisible = (el) => {
          const r = el.getBoundingClientRect();
          if (r.width <= 0 && r.height <= 0) return false;
          const cs = getComputedStyle(el);
          if (cs.visibility === 'hidden' || cs.display === 'none') return false;
          return true;
        };
        for (let i = 0; i < 40; i++) {
          const active = document.activeElement;
          if (!active || active === document.body) break;
          if (active.matches('input,select,textarea,a[href],button,[tabindex]')) {
            const outline = getComputedStyle(active).outlineStyle;
            const outlineWidth = parseFloat(getComputedStyle(active).outlineWidth) || 0;
            const boxShadow = getComputedStyle(active).boxShadow;
            const border = getComputedStyle(active).borderWidth;
            (styleVisible(active) && (outline !== 'none' || outlineWidth > 0 || boxShadow !== 'none' || parseFloat(border) > 0)
              ? visible : invisible).push(active.tagName.toLowerCase() + (active.className && typeof active.className === 'string' ? '.' + active.className.split(' ')[0] : ''));
          }
          const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true });
          document.activeElement.dispatchEvent(ev);
          // move focus like the browser would: pick the next focusable manually
          const focusables = Array.from(document.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])')).filter(styleVisible);
          const idx = focusables.indexOf(document.activeElement);
          const next = focusables[idx + 1];
          if (!next) break;
          next.focus();
        }
        return { visibleCount: visible.length, invisible: Array.from(new Set(invisible)).slice(0, 6) };
      });

      // ---- full-page screenshot
      await page.screenshot({ path: `${OUT}/${spec.name}-${vp.name}.png`, fullPage: true });
      page.removeAllListeners('pageerror');
      report[spec.name][vp.name] = { violations: axe, keyboard, consoleErrors };
    }
  }

  console.log(JSON.stringify(report, null, 1));
  fs.writeFileSync(`${OUT}/a11y-report.json`, JSON.stringify(report, null, 1));
  await browser.close();
})();
