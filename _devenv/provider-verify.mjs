#!/usr/bin/env node
/**
 * Live verification of the Zernio and DeepInfra keys + endpoint contracts
 * (the PV-ZERNIO-* and DeepInfra benchmark gates).
 *
 * Runs in GitHub Actions, where outbound HTTPS is open. The dev sandbox only
 * reaches registry.npmjs.org, so this script cannot run there — this is by
 * design and already re-verified.
 *
 * Secrets come from the environment only; no key is ever echoed back to logs.
 *
 *   ZERNIO_API_KEY          central IGBZ key (profile plane)
 *   ZERNIO_BASE_URL         default https://zernio.com/api/v1
 *   ZERNIO_AUTH_SCHEME      default Bearer
 *   ZERNIO_PROBE_SLUG       probe profile name prefix (default igbz-ci-probe)
 *   DEEPINFRA_API_KEY       store's DeepInfra key
 *   DEEPINFRA_ENDPOINT      default https://api.deepinfra.com/v1/openai/chat/completions
 *   DEEPINFRA_MODELS        comma list; empty = plugin pinned list
 *   DEEPINFRA_BENCHMARK_PROMPT  Persian prompt (tiny, ~128 tokens)
 *   RUN_ZERNIO / RUN_DEEPINFRA  'false'/'0' to skip
 */

// Matches the plugin's pinned defaults (DeepInfraAdapter::DEFAULT_MODELS).
const PINNED_MODELS = [
  'deepseek-ai/DeepSeek-V3',
  'moonshotai/Kimi-K2.7-Code',
  'moonshotai/Kimi-K3',
];

const REDACT = /("?(?:api_?key|key|token|secret|access_token|authUrl|auth_url)"?\s*[:=]\s*)("[^"]*"|[^\s,}]+)/gi;
const scrub = (s) => String(s).replace(REDACT, '$1"***"');

function firstObj(v) {
  if (Array.isArray(v)) return v[0] || {};
  return v && typeof v === 'object' ? v : {};
}

async function req(method, url, { key, scheme = 'Bearer', json, timeoutMs = 30000 } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  const headers = { Accept: 'application/json' };
  if (key) headers.Authorization = `${scheme} ${key}`;
  let body;
  if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  }
  const t0 = Date.now();
  try {
    const res = await fetch(url, { method, headers, body, signal: ctrl.signal });
    const text = await res.text();
    let parsed = null;
    try { parsed = text ? JSON.parse(text) : null; } catch { parsed = null; }
    return { status: res.status, ms: Date.now() - t0, raw: text.slice(0, 600), parsed };
  } catch (e) {
    return { status: 0, ms: Date.now() - t0, raw: '', parsed: null, error: String((e && e.message) || e).slice(0, 200) };
  } finally {
    clearTimeout(timer);
  }
}

const results = [];
async function check(name, fn) {
  try {
    const r = await fn();
    results.push({ name, ok: !!r.ok, detail: r.detail || '' });
  } catch (e) {
    results.push({ name, ok: false, detail: 'exception: ' + String((e && e.message) || e).slice(0, 200) });
  }
}

const env = process.env;
const RUN_ZERNIO = env.RUN_ZERNIO !== 'false' && env.RUN_ZERNIO !== '0';
const RUN_DEEPINFRA = env.RUN_DEEPINFRA !== 'false' && env.RUN_DEEPINFRA !== '0';
const ZERNIO_BASE = (env.ZERNIO_BASE_URL || 'https://zernio.com/api/v1').replace(/\/+$/, '');
const ZERNIO_SCHEME = env.ZERNIO_AUTH_SCHEME || 'Bearer';
const DEEPINFRA_ENDPOINT = env.DEEPINFRA_ENDPOINT || 'https://api.deepinfra.com/v1/openai/chat/completions';
const DEEPINFRA_MODELS = (env.DEEPINFRA_MODELS || '').split(',').map((s) => s.trim()).filter(Boolean);
const BENCH_PROMPT = env.DEEPINFRA_BENCHMARK_PROMPT || 'در یک جمله کوتاه، مهم‌ترین مزیت یک فروشگاه اینستاگرامی را بنویس.';

async function deepinfra() {
  if (!env.DEEPINFRA_API_KEY) {
    results.push({ name: 'deepinfra', ok: false, detail: 'DEEPINFRA_API_KEY secret missing' });
    return;
  }
  const models = DEEPINFRA_MODELS.length ? DEEPINFRA_MODELS : PINNED_MODELS;
  for (const model of models) {
    await check(`deepinfra:${model}`, async () => {
      const r = await req('POST', DEEPINFRA_ENDPOINT, {
        key: env.DEEPINFRA_API_KEY,
        json: {
          model,
          messages: [{ role: 'user', content: BENCH_PROMPT }],
          max_tokens: 128,
          temperature: 0.2,
        },
      });
      if (r.status === 0) return { ok: false, detail: `network/abort: ${r.error}` };
      if (r.status === 401 || r.status === 403) {
        return { ok: false, detail: `HTTP ${r.status} — key rejected. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status < 200 || r.status >= 300) {
        return { ok: false, detail: `HTTP ${r.status} — ${scrub(r.raw).slice(0, 160)}` };
      }
      const u = (r.parsed && r.parsed.usage) || {};
      const content = (((r.parsed && r.parsed.choices && r.parsed.choices[0]) || {}).message || {}).content || '';
      return {
        ok: true,
        detail: `HTTP ${r.status} in ${r.ms}ms · model=${(r.parsed && r.parsed.model) || model} · estimated_cost=${u.estimated_cost ?? 'n/a'} · tokens=${u.prompt_tokens ?? '?'}+${u.completion_tokens ?? '?'} · reply=${scrub(content).slice(0, 120)}`,
      };
    });
  }
}

async function zernio() {
  if (!env.ZERNIO_API_KEY) {
    results.push({ name: 'zernio', ok: false, detail: 'ZERNIO_API_KEY secret missing' });
    return;
  }
  const key = env.ZERNIO_API_KEY;
  let profileId = '';
  await check('zernio:create-profile', async () => {
    const slug = `${env.ZERNIO_PROBE_SLUG || 'igbz-ci-probe'}-${Date.now().toString(36)}`;
    const r = await req('POST', `${ZERNIO_BASE}/profiles`, {
      key, scheme: ZERNIO_SCHEME,
      json: { name: slug, description: 'IGBZ CI probe (auto-deleted)' },
    });
    if (r.status === 0) return { ok: false, detail: `network/abort: ${r.error}` };
    if (r.status === 401 || r.status === 403) {
      return { ok: false, detail: `HTTP ${r.status} — key rejected. ${scrub(r.raw).slice(0, 160)}` };
    }
    if (r.status < 200 || r.status >= 300) {
      return { ok: false, detail: `HTTP ${r.status} — ${scrub(r.raw).slice(0, 160)}` };
    }
    const d = firstObj(r.parsed && r.parsed.data ? r.parsed.data : r.parsed);
    profileId = String(d.profile_id ?? d.profileId ?? d.id ?? '');
    return {
      ok: !!profileId,
      detail: profileId
        ? `HTTP ${r.status} · profile_id=${profileId}`
        : `HTTP ${r.status} · no profile id in ${scrub(r.raw).slice(0, 160)}`,
    };
  });
  if (profileId) {
    await check('zernio:delete-profile (cleanup)', async () => {
      const r = await req('DELETE', `${ZERNIO_BASE}/profiles/${encodeURIComponent(profileId)}`, { key, scheme: ZERNIO_SCHEME });
      const ok = r.status >= 200 && r.status < 300;
      return { ok, detail: `HTTP ${r.status}${ok ? '' : ' — ' + scrub(r.raw).slice(0, 160)}` };
    });
  }
}

async function main() {
  if (RUN_DEEPINFRA) await deepinfra();
  else results.push({ name: 'deepinfra', ok: true, detail: 'skipped (RUN_DEEPINFRA=false)' });

  if (RUN_ZERNIO) await zernio();
  else results.push({ name: 'zernio', ok: true, detail: 'skipped (RUN_ZERNIO=false)' });

  let failed = 0;
  for (const r of results) {
    if (!r.ok) failed += 1;
    console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}  ${r.detail}`);
  }
  console.log(`\n${results.length - failed}/${results.length} checks passed.`);
  process.exitCode = failed ? 1 : 0;
}

main();
