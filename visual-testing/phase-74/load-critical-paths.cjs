#!/usr/bin/env node
/**
 * فاز ۷۴ — بار/دوام مسیرهای بحرانی روی پلی‌گراند زنده (پورت ۹۴۰۰).
 *
 * مسیرها: ویترین، صفحهٔ محصول، سبد (Store API مهمان)، سلامت محصول.
 * پروفایل‌ها: burst (همروندی ۴ و ۱۶، هر کدام ۲۰ث) + soak (۹۰ث، همروندی ۲،
 * پایش سلامت هر ۱۰ث + نمونه‌برداری RSS فرایند node).
 * خروجی: load-report.json + خلاصهٔ کنسول. p50/p95/p99/max + RPS + کدها.
 *
 * توجه: پلی‌گراند php-wasm تک‌کارگر است — این اعداد «این محیط پیش‌نمایش» را
 * توصیف می‌کنند، نه ظرفیت تولید؛ اسکریپت با BASE قابل حمل است.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const BASE_HOST = process.env.LOAD_HOST || '127.0.0.1';
const BASE_PORT = parseInt(process.env.LOAD_PORT || '9400', 10);
const OUT = __dirname;

const PATHS = [
  { name: 'home', path: '/' },
  { name: 'product', path: '/?p=43' },
  { name: 'cart-store-api', path: '/?rest_route=/wc/store/v1/cart' },
  { name: 'health', path: '/?igbz_health=1' },
];

const agent = new http.Agent({ keepAlive: true, maxSockets: 64 });

const COOKIE = process.env.LOAD_COOKIE || (fs.existsSync('/tmp/cj')
  ? fs.readFileSync('/tmp/cj', 'utf8').split(String.fromCharCode(10)).map((l) => l.split(String.fromCharCode(9)))
      .filter((p) => p.length >= 7 && (p[0] === '127.0.0.1' || p[0] === '#HttpOnly_127.0.0.1'))
      .map((p) => p[5] + '=' + p[6]).join('; ')
  : '');

function once(p) {
  return new Promise((resolve) => {
    const t0 = process.hrtime.bigint();
    const req = http.get({ host: BASE_HOST, port: BASE_PORT, path: p, agent, headers: COOKIE ? { cookie: COOKIE } : {} }, (res) => {
      res.resume();
      res.on('end', () => resolve({ ok: true, code: res.statusCode, ms: Number(process.hrtime.bigint() - t0) / 1e6 }));
    });
    req.on('error', (e) => resolve({ ok: false, code: 0, ms: Number(process.hrtime.bigint() - t0) / 1e6, err: String(e.code || e.message).slice(0, 40) }));
    req.setTimeout(30000, () => { req.destroy(new Error('timeout')); });
  });
}

function percentile(sorted, p) {
  if (!sorted.length) return 0;
  const i = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
  return Math.round(sorted[i] * 10) / 10;
}

function summarize(name, samples, wallSeconds) {
  const ok = samples.filter((s) => s.ok && s.code < 400 && s.code !== 302);
  const fail = samples.filter((s) => !s.ok);
  const lat = ok.map((s) => s.ms).sort((a, b) => a - b);
  const codes = {};
  for (const s of samples) codes[s.code] = (codes[s.code] || 0) + 1;
  return {
    name,
    requests: samples.length,
    rps: Math.round((samples.length / wallSeconds) * 10) / 10,
    ok: ok.length,
    failed: fail.length,
    codes,
    p50: percentile(lat, 50), p95: percentile(lat, 95), p99: percentile(lat, 99),
    max: lat.length ? Math.round(lat[lat.length - 1] * 10) / 10 : 0,
  };
}

async function profile(label, concurrency, seconds, paths) {
  const samples = { };
  paths.forEach((p) => (samples[p.name] = []));
  const stopAt = Date.now() + seconds * 1000;
  const t0 = Date.now();

  await Promise.all(
    Array.from({ length: concurrency }, async () => {
      let i = 0;
      while (Date.now() < stopAt) {
        const p = paths[i++ % paths.length];
        samples[p.name].push(await once(p.path));
      }
    })
  );
  const wall = (Date.now() - t0) / 1000;
  const result = { label, concurrency, seconds: Math.round(wall * 10) / 10, paths: {} };
  let total = 0, failed = 0;
  for (const p of paths) {
    result.paths[p.name] = summarize(p.name, samples[p.name], wall);
    total += samples[p.name].length;
    failed += samples[p.name].filter((s) => !s.ok || s.code === 302 || s.code >= 400).length;
  }
  result.total = total;
  result.totalFailed = failed;
  return result;
}

function nodeRssKb() {
  try {
    const status = fs.readFileSync(`/proc/${process.pid}/status`, 'utf8');
    const m = status.match(/VmRSS:\s+(\d+) kB/);
    return m ? parseInt(m[1], 10) : null;
  } catch {
    return null;
  }
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const report = { startedAt: new Date().toISOString(), env: { host: BASE_HOST, port: BASE_PORT, note: 'php-wasm single-worker preview' } };

  // warmup (paths exist, code paths compiled)
  for (const p of PATHS) {
    const r = await once(p.path);
    if (!r.ok || r.code >= 400 || r.code === 302) console.warn(`WARN warmup ${p.name} -> ${r.code} (session cookie carried? ${COOKIE ? 'yes' : 'NO'})`);
  }
  console.log('warmup done');

  report.burst4 = await profile('burst-c4', 4, 20, PATHS);
  console.log(`burst c4: ${report.burst4.total} req, failed ${report.burst4.totalFailed}, rps ${Math.round(report.burst4.total / report.burst4.seconds)}`);

  report.burst16 = await profile('burst-c16', 16, 20, PATHS);
  console.log(`burst c16: ${report.burst16.total} req, failed ${report.burst16.totalFailed}, rps ${Math.round(report.burst16.total / report.burst16.seconds)}`);

  // soak: 90s at c2, health watched separately
  const rssStart = nodeRssKb();
  const healthWatch = [];
  const soakHealth = setInterval(async () => {
    const r = await once('/?igbz_health=1');
    healthWatch.push({ t: new Date().toISOString(), code: r.code, ok: r.ok });
  }, 10000);
  report.soak = await profile('soak-c2', 2, 90, PATHS);
  clearInterval(soakHealth);
  report.soak.healthWatch = healthWatch;
  report.soak.rssKbStart = rssStart;
  report.soak.rssKbEnd = nodeRssKb();
  console.log(`soak c2: ${report.soak.total} req, failed ${report.soak.totalFailed}, rss ${rssStart}→${report.soak.rssKbEnd} kB`);

  fs.writeFileSync(path.join(OUT, 'load-report.json'), JSON.stringify(report, null, 2));
  console.log('written load-report.json');
  process.exit(0);
})();
