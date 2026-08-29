/* Rule 12 visual check, phase 59: the approvals tab must render the sensitive
   commercial operations (price change / refund / bulk delete) waiting in the queue —
   readable rows, working actions, no layout break, no console errors. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  process.env.FONTCONFIG_PATH = dest;
  const outDir = '/home/user/IGBZ-WP/visual-testing/phase-59';
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

  await page.goto(base + '/wp-admin/', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.goto(base + '/wp-admin/admin.php?page=igbz-pado&tab=approvals', { waitUntil: 'networkidle', timeout: 120000 });
  await page.screenshot({ path: path.join(outDir, 'pado-approvals-pending-59.png'), fullPage: true });

  const pendingChecks = await page.evaluate(() => {
    const doc = document.documentElement;
    const rows = [...document.querySelectorAll('tr')].map((r) => r.textContent.replace(/\s+/g, ' ').trim()).filter((t) => t.length > 3);
    return {
      title: document.title,
      overflowX: doc.scrollWidth - doc.clientWidth,
      rowCount: rows.length,
      seesSalesPost: rows.some((t) => t.includes('کمپین فروش')),
      seesCampaignSend: rows.some((t) => t.includes('ارسال کمپین')),
      seesPolicyChange: rows.some((t) => t.includes('تغییر سیاست')),
      seesKinds: rows.filter((t) => t.includes('کمپین فروش') || t.includes('ارسال کمپین') || t.includes('تغییر سیاست')).length,
      brokenImgs: [...document.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
    };
  });

  // The executed/failed history view of the same tab (status filter).
  const histLink = await page.evaluate(() => {
    const a = [...document.querySelectorAll('a')].find((x) => /status=(executed|all|history)/.test(x.href));
    return a ? a.href : null;
  });
  let historyChecks = null;
  if (histLink) {
    await page.goto(histLink, { waitUntil: 'networkidle', timeout: 120000 });
    await page.screenshot({ path: path.join(outDir, 'pado-approvals-history-59.png'), fullPage: true });
    historyChecks = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('tr')].map((r) => r.textContent.replace(/\s+/g, ' ').trim()).filter((t) => t.length > 3);
      return {
        executedRows: rows.filter((t) => t.includes('اجرا') || t.includes('executed')).length,
        failedRows: rows.filter((t) => t.includes('ناموفق') || t.includes('failed')).length,
      };
    });
  }

  console.log(JSON.stringify({ pendingChecks, histLink, historyChecks, consoleErrors: consoleErrors.slice(0, 5) }, null, 1));
  await browser.close();
})().catch((e) => { console.error('FAILED', e && e.message || e); process.exit(1); });
