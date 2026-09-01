#!/usr/bin/env node
/**
 * Live verification of the Zernio + inference-provider keys and endpoint contracts
 * (the PV-ZERNIO-* and ADR-0005 provider benchmark gates).
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
 *
 *   OPENROUTER_API_KEY      OpenRouter key (openai dialect)
 *   OPENROUTER_ENDPOINT     default https://openrouter.ai/api/v1/chat/completions
 *   OPENROUTER_MODELS       comma list; empty = no-credit smoke model
 *
 *   GROQ_API_KEY            Groq key (openai dialect)
 *   GROQ_ENDPOINT           default https://api.groq.com/openai/v1/chat/completions
 *   GROQ_MODELS             comma list; empty = plugin pinned list
 *
 *   NARAROUTER_API_KEY      NaraRouter key (openai dialect; optional test provider)
 *   NARAROUTER_ENDPOINT     default https://router.bynara.id/v1/chat/completions
 *   NARAROUTER_MODELS       comma list; empty = direct free smoke model
 *
 *   ANTHROPIC_API_KEY       Anthropic key (anthropic dialect; optional)
 *   ANTHROPIC_ENDPOINT      default https://api.anthropic.com/v1/messages
 *   ANTHROPIC_MODELS        comma list; empty = plugin pinned list
 *
 *   BENCHMARK_PROMPT        Persian prompt (tiny, ~128 tokens)
 *   RUN_ZERNIO / RUN_OPENROUTER / RUN_GROQ / RUN_NARAROUTER / RUN_ANTHROPIC
 *                            'false'/'0' to skip
 */

// Default probe lists. OpenRouter's plugin-pinned production models still need credits,
// so the default here follows the current smoke-test router alias; pass OPENROUTER_MODELS explicitly
// when running the production benchmark. NaraRouter is test-only, currently skipped
// by default, and uses a direct free-plan alias from /api/plans when explicitly enabled
// because auto/bynara returned 403 in the first live run.
const PINNED = {
  openrouter: ['openrouter/free'],
  groq: ['qwen/qwen3.6-27b'],
  nararouter: ['glm-5.3-flash-free'],
  anthropic: ['claude-sonnet-4-20250514'],
};

const OPENAI_ENDPOINTS = {
  openrouter: 'https://openrouter.ai/api/v1/chat/completions',
  groq: 'https://api.groq.com/openai/v1/chat/completions',
  nararouter: 'https://router.bynara.id/v1/chat/completions',
};

const REDACT = /("?(?:api_?key|key|token|secret|access_token|authUrl|auth_url)"?\s*[:=]\s*)("[^"]*"|[^\s,}]+)/gi;
const scrub = (s) => String(s).replace(REDACT, '$1"***"');

function firstObj(v) {
  if (Array.isArray(v)) return v[0] || {};
  return v && typeof v === 'object' ? v : {};
}

async function req(method, url, { key, scheme = 'Bearer', headers: extra = {}, json, timeoutMs = 30000 } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  const headers = { Accept: 'application/json', ...extra };
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
const RUN_OPENROUTER = env.RUN_OPENROUTER !== 'false' && env.RUN_OPENROUTER !== '0';
const RUN_GROQ = env.RUN_GROQ !== 'false' && env.RUN_GROQ !== '0';
const RUN_NARAROUTER = env.RUN_NARAROUTER === 'true' || env.RUN_NARAROUTER === '1';
const RUN_ANTHROPIC = env.RUN_ANTHROPIC === 'true' || env.RUN_ANTHROPIC === '1';
const BENCH_PROMPT = env.BENCHMARK_PROMPT || 'در یک جمله کوتاه، مهم‌ترین مزیت یک فروشگاه اینستاگرامی را بنویس.';

function models(name, envList) {
  const list = (envList || '').split(',').map((s) => s.trim()).filter(Boolean);
  return list.length ? list : PINNED[name];
}

async function openaiDialect(name) {
  const keyEnv = `${name.toUpperCase()}_API_KEY`;
  const modelsEnv = `${name.toUpperCase()}_MODELS`;
  const endpoint = env[`${name.toUpperCase()}_ENDPOINT`] || OPENAI_ENDPOINTS[name];
  if (!env[keyEnv]) {
    results.push({ name, ok: false, detail: `${keyEnv} secret missing` });
    return;
  }
  for (const model of models(name, env[modelsEnv])) {
    await check(`${name}:${model}`, async () => {
      const r = await req('POST', endpoint, {
        key: env[keyEnv],
        json: { model, messages: [{ role: 'user', content: BENCH_PROMPT }], max_tokens: 128, temperature: 0.2 },
      });
      if (r.status === 0) return { ok: false, detail: `network/abort: ${r.error}` };
      if (r.status === 401) {
        return { ok: false, detail: `HTTP ${r.status} — key rejected. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status === 403) {
        return { ok: false, detail: `HTTP ${r.status} — forbidden/model not entitled or account forbidden. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status === 402) {
        return { ok: false, detail: `HTTP 402 — account has no credits/payment. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status < 200 || r.status >= 300) {
        return { ok: false, detail: `HTTP ${r.status} — ${scrub(r.raw).slice(0, 160)}` };
      }
      const u = (r.parsed && r.parsed.usage) || {};
      const choice = (r.parsed && r.parsed.choices && r.parsed.choices[0]) || {};
      const content = ((choice.message || {}).content || '').trim();
      const finish = choice.finish_reason || 'n/a';
      if (!content) {
        return {
          ok: false,
          detail: `HTTP ${r.status} in ${r.ms}ms · empty reply · finish=${finish} · model=${(r.parsed && r.parsed.model) || model} · tokens=${u.prompt_tokens ?? '?'}+${u.completion_tokens ?? '?'}`,
        };
      }
      return {
        ok: true,
        detail: `HTTP ${r.status} in ${r.ms}ms · model=${(r.parsed && r.parsed.model) || model} · estimated_cost=${u.estimated_cost ?? 'n/a'} · tokens=${u.prompt_tokens ?? '?'}+${u.completion_tokens ?? '?'} · reply=${scrub(content).slice(0, 120)}`,
      };
    });
  }
}

async function anthropic() {
  const endpoint = env.ANTHROPIC_ENDPOINT || 'https://api.anthropic.com/v1/messages';
  if (!env.ANTHROPIC_API_KEY) {
    results.push({ name: 'anthropic', ok: false, detail: 'ANTHROPIC_API_KEY secret missing' });
    return;
  }
  for (const model of models('anthropic', env.ANTHROPIC_MODELS)) {
    await check(`anthropic:${model}`, async () => {
      const r = await req('POST', endpoint, {
        key: env.ANTHROPIC_API_KEY,
        headers: { 'x-api-key': env.ANTHROPIC_API_KEY, 'anthropic-version': '2023-06-01' },
        json: { model, max_tokens: 128, messages: [{ role: 'user', content: BENCH_PROMPT }] },
      });
      if (r.status === 0) return { ok: false, detail: `network/abort: ${r.error}` };
      if (r.status === 401) {
        return { ok: false, detail: `HTTP ${r.status} — key rejected. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status === 403) {
        return { ok: false, detail: `HTTP ${r.status} — forbidden/model not entitled or account forbidden. ${scrub(r.raw).slice(0, 160)}` };
      }
      if (r.status < 200 || r.status >= 300) {
        return { ok: false, detail: `HTTP ${r.status} — ${scrub(r.raw).slice(0, 160)}` };
      }
      const blocks = Array.isArray(r.parsed && r.parsed.content) ? r.parsed.content : [];
      const content = blocks.filter((b) => b && b.type === 'text').map((b) => b.text || '').join('');
      const u = (r.parsed && r.parsed.usage) || {};
      return {
        ok: true,
        detail: `HTTP ${r.status} in ${r.ms}ms · model=${(r.parsed && r.parsed.model) || model} · tokens=${u.input_tokens ?? '?'}+${u.output_tokens ?? '?'} · reply=${scrub(content).slice(0, 120)}`,
      };
    });
  }
}

async function zernio() {
  const ZERNIO_BASE = (env.ZERNIO_BASE_URL || 'https://zernio.com/api/v1').replace(/\/+$/, '');
  const ZERNIO_SCHEME = env.ZERNIO_AUTH_SCHEME || 'Bearer';
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
  if (RUN_OPENROUTER) await openaiDialect('openrouter');
  else results.push({ name: 'openrouter', ok: true, detail: 'skipped (RUN_OPENROUTER=false)' });

  if (RUN_GROQ) await openaiDialect('groq');
  else results.push({ name: 'groq', ok: true, detail: 'skipped (RUN_GROQ=false)' });

  if (RUN_NARAROUTER) await openaiDialect('nararouter');
  else results.push({ name: 'nararouter', ok: true, detail: 'skipped (RUN_NARAROUTER=false)' });

  if (RUN_ANTHROPIC) await anthropic();
  else results.push({ name: 'anthropic', ok: true, detail: 'skipped (RUN_ANTHROPIC=false)' });

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
