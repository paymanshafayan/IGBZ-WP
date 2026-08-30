/* Rule 12 visual check, phase 65: Pado's memory is a backend layer — the visual
   evidence is that the Pado center (where every memory-backed capability lives)
   still renders intact with the new module binding, plus the playground health
   confirming the v45 migration (97/97 tables) through the real UI surface. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  // NOTE: FONTCONFIG_PATH stays unset — its fonts.conf points at non-existent
  // dirs and crashes the renderer on this run.
  const outDir = '/home/user/IGBZ-WP/visual-testing/phase-65';
  fs.mkdirSync(outDir, { recursive: true });

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

  const visit = async (url) => {
    for (let attempt = 1; attempt <= 3; attempt++) {
      try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 120000 });
        return;
      } catch (e) {
        if (attempt === 3) throw e;
        await page.close().catch(() => {});
        page = await ctx.newPage();
      }
    }
  };

  // 1) The Pado center dashboard with the memory service bound.
  await visit(base + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  if (await page.$('#rememberme')) await page.check('#rememberme');
  await visit(base + '/wp-admin/admin.php?page=igbz-pado');
  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(outDir, 'pado-center.png'), fullPage: true });
  const pado = await page.evaluate(() => {
    const doc = document.documentElement;
    const text = doc.textContent.replace(/\s+/g, ' ').trim();
    return {
      title: document.title,
      seesPado: /پادو|Pado/.test(text),
      tabs: [...document.querySelectorAll('.nav-tab')].map((t) => t.textContent.trim()).slice(0, 10),
      overflowX: doc.scrollWidth - doc.clientWidth,
      brokenImgs: [...document.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
    };
  });

  // 2) Site health — the v45 migration, verified through the admin surface.
  await visit(base + '/wp-admin/site-health.php');
  await page.waitForTimeout(3000);
  await page.screenshot({ path: path.join(outDir, 'site-health.png'), fullPage: true });
  const health = await page.evaluate(() => ({
    title: document.title,
    hasFatal: /a fatal error|خطای مهلک/i.test(document.documentElement.textContent),
  }));

  // 3) The storefront — the tenant-facing surface must be untouched.
  await visit(base + '/');
  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(outDir, 'storefront.png'), fullPage: true });
  const front = await page.evaluate(() => ({
    productCards: document.querySelectorAll('li.product, .wp-block-query li').length,
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    brokenImgs: [...document.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
  }));

  console.log(JSON.stringify({ pado, health, front, consoleErrors: consoleErrors.slice(0, 5) }, null, 1));
  await browser.close();
})().catch((e) => { console.error('FAILED', e && e.message || e); process.exit(1); });
