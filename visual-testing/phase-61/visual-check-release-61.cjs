/* Rule 12 visual check, phase 61: the same storefront through the LIVE base
   theme and through Pado's signed child artefact — side-by-side evidence for
   the release pipeline (preview vs live visual comparison). */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  // NOTE: FONTCONFIG_PATH (whose fonts.conf points at non-existent dirs) crashes the
  // renderer on this run — dropped; system font fallback applies to the screenshot.
  const outDir = '/home/user/IGBZ-WP/visual-testing/phase-61';
  fs.mkdirSync(outDir, { recursive: true });
  const which = process.argv[2] || 'child'; // which theme is active right now

  const browser = await chromium.launch({
    executablePath: path.join(dest, 'chromium'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless=new'],
    headless: true,
  });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  let page = await ctx.newPage();
  const consoleErrors = [];
  page.on('pageerror', (e) => consoleErrors.push(String(e)));
  const base = process.env.WP_BASE_URL || 'http://127.0.0.1:9400';

  // The renderer occasionally crashes under load here — retry with a fresh page.
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await page.goto(base + '/', { waitUntil: 'domcontentloaded', timeout: 120000 });
      break;
    } catch (e) {
      if (attempt === 3) throw e;
      await page.close().catch(() => {});
      page = await ctx.newPage();
    }
  }
  await page.waitForTimeout(4000); // let CSS/images settle
  await page.screenshot({ path: path.join(outDir, `storefront-${which}.png`), fullPage: true });
  const checks = await page.evaluate(() => ({
    footerFa: ((document.querySelector('footer') || {}).textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80),
    hasOurFooter: !!document.querySelector('.site-footer'),
    productCards: document.querySelectorAll('li.product, .wp-block-query li').length,
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    brokenImgs: [...document.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
  }));
  console.log(JSON.stringify({ which, checks, consoleErrors: consoleErrors.slice(0, 5) }, null, 1));
  await browser.close();
})().catch((e) => { console.error('FAILED', e && e.message || e); process.exit(1); });
