/* Rule 12 visual check, phase 60: the storefront and a single product rendered
   through Pado's PHP-free block child theme (igbz-store-theme golden skeleton). */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  process.env.FONTCONFIG_PATH = dest;
  const outDir = '/home/user/IGBZ-WP/visual-testing/phase-60';
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({
    executablePath: path.join(dest, 'chromium'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless=new'],
    headless: true,
  });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('pageerror', (e) => consoleErrors.push(String(e)));
  const base = process.env.WP_BASE_URL || 'http://127.0.0.1:9400';

  await page.goto(base + '/', { waitUntil: 'networkidle', timeout: 120000 });
  await page.screenshot({ path: path.join(outDir, 'storefront-child-theme.png'), fullPage: true });
  const front = await page.evaluate(() => {
    const doc = document.documentElement;
    return {
      dir: doc.getAttribute('dir'),
      hasHeader: !!document.querySelector('.site-header'),
      hasFooter: !!document.querySelector('.site-footer'),
      footerFa: (document.querySelector('.site-footer') || {}).textContent || '',
      productCards: document.querySelectorAll('li.product, .wp-block-query li').length,
      overflowX: doc.scrollWidth - doc.clientWidth,
      brokenImgs: [...document.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
    };
  });

  await page.goto(base + '/?p=15', { waitUntil: 'networkidle', timeout: 120000 });
  await page.screenshot({ path: path.join(outDir, 'single-product-child-theme.png'), fullPage: true });
  const product = await page.evaluate(() => ({
    title: (document.querySelector('h1') || {}).textContent || '',
    hasFooter: !!document.querySelector('.site-footer'),
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  }));

  console.log(JSON.stringify({ front, product, consoleErrors: consoleErrors.slice(0, 5) }, null, 1));
  await browser.close();
})().catch((e) => { console.error('FAILED', e && e.message || e); process.exit(1); });
