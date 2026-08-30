#!/usr/bin/env node
/**
 * فاز ۷۱ — تست ویژوال پنل SLO روی صفحهٔ وضعیت (دسکتاپ + موبایل):
 * ورود ادمین، رندر بخش SLO، وضعیت سبز/قرمز، جدول سنجه‌ها، اسکرین‌شات تمام‌صفحه،
 * خطاهای کنسول، و صحت DOM (سطر request_id در جدول لاگ اگر ردیفی باشد).
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:9400';
const OUT = path.join(__dirname);
const AXE = path.join(__dirname, '..', '..', '_devenv', '.work', 'node_modules', 'axe-core', 'axe.min.js');

(async () => {
  const dest = '/tmp/chromium-extract';
  process.env.LD_LIBRARY_PATH = [path.join(dest, 'lib'), dest].filter(Boolean).join(':');
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: path.join(dest, 'chromium'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless=new'],
    headless: true,
  });

  const report = {};
  for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
    const ctx = await browser.newContext({ viewport: vp });
    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push(String(e)));

    await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');
    await page.waitForLoadState('domcontentloaded');

    await page.goto(BASE + '/wp-admin/admin.php?page=igbz', { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(1500);

    const dom = await page.evaluate(() => {
      const text = document.body.innerText;
      const headings = [...document.querySelectorAll('h2')].map((h) => h.textContent.trim());
      const notices = [...document.querySelectorAll('.notice')].map((n) => ({
        cls: n.className, text: n.textContent.trim().slice(0, 220),
      }));
      const sloNotice = notices.find((n) => /SLO|سطح سرویس|آستانه/.test(n.text) || n.cls.includes('success'));
      // جدول سنجه‌ها: سرستون‌های شش‌گانه
      const headerRow = [...document.querySelectorAll('thead th')].map((t) => t.textContent.trim());
      return {
        title: document.title,
        headings,
        notices,
        sloNotice: sloNotice || null,
        headerRow,
        overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        rtl: document.documentElement.dir === 'rtl' || getComputedStyle(document.body).direction === 'rtl',
      };
    });

    // سرستون‌های شش‌گانهٔ جدول SLO (ادمین انگلیسی است)
    const sloHeaderCount = dom.headerRow.filter((h) => /(done|failed|dead|pending|wait|errors)/i.test(h)).length;

    // ردیف لاگ با request_id؟ (اگر جدول لاق جدیدی هست)
    const logRequestId = await page.evaluate(() => {
      const cells = [...document.querySelectorAll('td code, td')];
      const hit = cells.find((c) => /[0-9a-f]{32}/.test(c.textContent || ''));
      return hit ? (hit.textContent.match(/[0-9a-f]{32}/) || [])[0] || null : null;
    });

    await page.screenshot({ path: path.join(OUT, `status-slo-${vp.name}.png`), fullPage: true });

    report[vp.name] = {
      url: '/wp-admin/admin.php?page=igbz',
      consoleErrors,
      rtl: dom.rtl,
      overflowX: dom.overflowX,
      headings: dom.headings,
      sloNotice: dom.sloNotice,
      sloMetricColumns: sloHeaderCount,
      logRequestIdOnPage: logRequestId,
    };
    await ctx.close();
  }

  // a11y سریع روی دسکتاپ: فقط قواعد بحرانی
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await page.click('#wp-submit');
  await page.goto(BASE + '/wp-admin/admin.php?page=igbz', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.waitForTimeout(1200);
  const axeSource = fs.readFileSync(AXE, 'utf8');
  report.a11y = await page.evaluate(async (src) => {
    // eslint-disable-next-line no-eval
    eval(src);
    const r = await window.axe.run(document, { runOnly: { type: 'tags', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, sel: (v.nodes[0] || {}).target }));
  }, axeSource).catch((e) => ({ error: String(e).slice(0, 200) }));
  await ctx.close();

  fs.writeFileSync(path.join(OUT, 'visual-slo-report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})();
