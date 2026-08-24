/**
 * سرور محلی ویرا.
 *
 * یک سرور کوچک روی لوکال‌هاست که رابط کاربری را سرو می‌کند و رویدادهای عامل را با SSE
 * می‌فرستد. هرچه در رابط کاربری قابل‌کلیک است، اینجا یک مسیر دارد — قاعده این است که
 * **هیچ قابلیتی فقط با ویرایش دستی JSON در دسترس نباشد**.
 *
 * مسیرها با یک جدول تعریف شده‌اند (نه زنجیرهٔ if) تا اضافه‌کردن قابلیت بعدی، یک سطر باشد.
 */

import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadConfig, saveConfig, publicConfig, activeProfile, HOME } from './config.js';
import { PROVIDERS, createProvider, validateProfile, providerInfo } from './providers/index.js';
import { CATEGORIES, STRATEGIES, AUTH_STYLES, hubId } from './hub/schema.js';
import { handleChatCompletions, modelsResponse } from './hub/openai-api.js';
import { proxyFetch, normalizeProxy } from './net.js';
import { setProxyPolicy } from './net.js';
import * as logs from './logs.js';
import * as tunnel from './tunnel/engine.js';
import { downloadCore, corePresent, coreVersion } from './tunnel/core.js';
import { hubReady as hubReadyOf } from './hub/registry.js';
import { VERSION, installInfo } from './version.js';
import { MODES } from './permissions.js';
import { saveSession, listSessions, loadSession, deleteSession, renameSession, setSessionProject } from './session.js';
import { Runtime } from './runtime.js';
import { parseInput, BUILTIN_COMMANDS, saveCommand, removeCommand } from './commands.js';
import { listPlugins, installPlugin, removePlugin, setPluginEnabled, fetchMarketplace } from './plugins.js';
import { installSkill, removeSkill, setSkillEnabled } from './skillstore.js';
import { listConnectors, saveConnector, removeConnector, setConnectorEnabled, testConnector } from './connectors.js';
import { saveAgent, removeAgent } from './agents.js';
import { CheckpointStore } from './checkpoints.js';
import { shells } from './background.js';
import { listFiles, fuzzyFilter, readWorkspaceFile } from './workspace.js';
import * as vcs from './git.js';
import { estimateCost, estimateContextTokens, recordUsage, readUsage } from './usage.js';
import { toMarkdown, toJson } from './export.js';
import { diagnose } from './doctor.js';
import { sandboxStatus, testSandbox, resolveSandbox } from './sandbox.js';
import { explain } from './errors.js';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const UI_DIR = path.join( __dirname, '..', 'ui' );

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.ico': 'image/x-icon',
	'.woff2': 'font/woff2',
	'.json': 'application/json; charset=utf-8',
	'.webmanifest': 'application/manifest+json; charset=utf-8',
};

/** پنجرهٔ کانتکست تقریبی مدل‌های رایج — فقط برای نوار «چقدر پر شده». */
const CONTEXT_WINDOW = 200_000;

/**
 * رویدادهایی که در نوار گفتگو ذخیره می‌شوند.
 *
 * فهرست سفید است نه سیاه، و دلیلش یک باگ واقعی است: رویداد `rewound` خودِ نوار گفتگو را
 * با خودش حمل می‌کند؛ اگر آن را داخل همان نوار بگذاریم، ساختار حلقه می‌زند و
 * JSON.stringify کل درخواست را می‌ترکاند.
 */
const STORED_EVENTS = new Set( [
	'user',
	'assistant_end',
	'system',
	'notice',
	'error',
	'tool_start',
	'tool_result',
	'tool_error',
	'tool_denied',
	'permission_request',
	'ask_user',
	'subagent_start',
	'subagent_end',
	'compacted',
	'parallel',
] );

export async function startServer( { port = 7788, host = '127.0.0.1', workspace } = {} ) {
	const boot = await loadConfig();
	if ( workspace ) {
		boot.workspace = path.resolve( workspace );
		await saveConfig( boot );
	}

	/** @type {Set<import('node:http').ServerResponse>} */
	const clients = new Set();
	/** @type {any[]} */
	let transcript = [];
	let sessionId = `s_${ Date.now().toString( 36 ) }`;
	let sessionTitle = '';
	/*
	 * انتخاب مخزن و شاخه فقط **پیش از اولین پیام** آزاد است.
	 *
	 * قاعده‌ای که کارفرما گذاشت: در ابتدای هر گفتگو، مخزن و شاخه انتخاب می‌شوند؛ با
	 * فرستادن اولین پیام قفل می‌شوند و تا گفتگوی تازه باز نمی‌شوند. دلیلش روشن است:
	 * وسط کار عوض‌کردن مخزن یعنی نیمی از گفتگو دربارهٔ کدی است که دیگر آنجا نیست.
	 */
	let gitLocked = false;
	/** @type {{content:string,status:string}[]} */
	let todos = [];
	/** @type {any[]} */
	let pendingAsk = [];
	/** @type {{inputTokens:number,outputTokens:number,cost:number}} */
	let sessionCost = { inputTokens: 0, outputTokens: 0, cost: 0 };

	const broadcast = ( ev ) => {

		// فهرست کارها را زنده نگه می‌داریم تا رابط کاربری بتواند همیشه نشانش بدهد.
		if ( ev.type === 'tool_log' && typeof ev.text === 'string' && ev.text.includes( '"todos"' ) ) {
			try {
				const parsed = JSON.parse( ev.text );
				if ( Array.isArray( parsed.todos ) ) {
					todos = parsed.todos;
					ev = { ...ev, todos };
				}
			} catch {
				// لاگ عادی بود، نه فهرست کار.
			}
		}

		if ( ev.type === 'ask_user' ) {
			pendingAsk.push( ev );
		}
		if ( ev.type === 'ask_answered' ) {
			pendingAsk = pendingAsk.filter( ( a ) => a.id !== ev.id );
		}

		if ( STORED_EVENTS.has( ev.type ) ) {
			transcript.push( { ...ev, at: Date.now() } );
		}

		const payload = `data: ${ JSON.stringify( ev ) }\n\n`;
		for ( const res of clients ) {
			res.write( payload );
		}
	};

	const runtime = new Runtime( broadcast );
	runtime.onTurnEnd = async ( { usage, model } ) => {
		const cfg = runtime.config;
		const cost = estimateCost( model, usage, cfg?.pricing );
		sessionCost = { ...usage, cost: cost ?? 0 };
		await recordUsage( HOME, {
			model,
			inputTokens: usage.inputTokens,
			outputTokens: usage.outputTokens,
			cost: cost ?? 0,
		} ).catch( () => {} );
		broadcast( { type: 'usage', usage, cost } );
	};

	await runtime.reload();
	await runtime.loadProjectMemory();
	runtime.checkpoints = new CheckpointStore( { home: HOME, workspace: runtime.config.workspace, sessionId } );
	if ( runtime.agent ) {
		runtime.agent.checkpoints = runtime.checkpoints;
	}
	await runtime.hooks.run( 'SessionStart', { sessionId } );

	/** پس از تغییر پوشهٔ کاری یا نشست، چک‌پوینت‌ها باید دنبالش بروند. */
	function rebindCheckpoints() {
		runtime.checkpoints = new CheckpointStore( { home: HOME, workspace: runtime.config.workspace, sessionId } );
		if ( runtime.agent ) {
			runtime.agent.checkpoints = runtime.checkpoints;
		}
	}

	// ------------------------------------------------------------------ وضعیت

	async function buildState() {
		const cfg = runtime.config;
		const active = activeProfile( cfg ) || {};
		const info = providerInfo( active.provider );
		const contextTokens = estimateContextTokens( runtime.agent?.messages || [] );

		return {
			config: publicConfig( cfg ),
			providers: PROVIDERS,
			modes: MODES,
			ready: runtime.ready,
			hasKey: Boolean( active.apiKey ),
			home: HOME,
			busy: Boolean( runtime.agent?.busy ),
			transcript,
			sessionId,
			sessionTitle,
			todos,
			pendingAsk,
			usage: {
				...( runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 } ),
				cost: sessionCost.cost,
			},
			context: { used: contextTokens, window: CONTEXT_WINDOW },
			tools: Object.values( runtime.tools() ).map( ( t ) => ( {
				name: t.spec.name,
				description: t.spec.description,
				risk: t.risk,
			} ) ),
			skills: runtime.skills.map( ( s ) => ( { name: s.name, description: s.description, source: s.source } ) ),
			agents: runtime.agents.map( ( a ) => ( {
				name: a.name,
				description: a.description,
				tools: a.tools || [],
				model: a.model || '',
				source: a.source,
				prompt: a.prompt,
			} ) ),
			commands: [
				...BUILTIN_COMMANDS.map( ( c ) => ( { ...c, source: 'builtin' } ) ),
				...runtime.commands.map( ( c ) => ( { name: c.name, description: c.description, source: c.source, body: c.body } ) ),
				...runtime.mcp.promptEntries().map( ( p ) => ( { name: p.name, description: p.description, source: 'MCP' } ) ),
			],
			resources: runtime.mcp.resourceEntries(),
			mcp: runtime.mcp.status,
			connectors: await listConnectors( { workspace: cfg.workspace } ),
			plugins: await listPlugins( HOME ),
			shells: shells.list(),
			checkpoints: await runtime.checkpoints.list(),
			memory: Boolean( runtime.projectMemory ),
			sandbox: await sandboxStatus( cfg ),
			git: await vcs.status( cfg.workspace ).catch( () => null ),
			providerInfo: info || null,
			hub: { active: runtime.hubActive(), enabled: Boolean( runtime.hub?.data?.enabled ), ready: runtime.hub ? hubReadyOf( runtime.hub.data ) : null },
			version: VERSION,
			// تا رابط بتواند بگوید «کدی که داری می‌بینی از کجا آمده» — بدون ترمینال.
			install: installInfo(),
		};
	}

	// ------------------------------------------------------------------ مسیرها

	/** @type {Record<string, (c:any)=>any>} */
	/*
	 * همگام‌سازی پراکسی (۰.۹.۶): صفحهٔ تنظیماتِ ویندوز-سبک مالک حالت است (config.proxy)
	 * و آدرسِ مؤثر را روی hub.data.proxy.url می‌نویسد تا همهٔ تماس‌های پرووایدر و
	 * «تست پراکسی» بدون تغییر از همان بگذرند. حالت engine = درگاه محلی موتور تونل.
	 */
	async function syncProxyToHub() {
		const p = runtime.config?.proxy || {};
		const url = p.mode === 'manual' ? `http://${ p.address || '127.0.0.1' }:${ p.port || 7890 }` : p.mode === 'engine' ? `http://127.0.0.1:${ tunnel.HTTP_PORT }` : '';
		setProxyPolicy( p );
		if ( ( runtime.hub?.data?.proxy?.url || '' ) !== url ) {
			await runtime.hub.update( { proxy: url ? { url } : { url: '' } } );
			await runtime.hub.saveState?.();
		}
		return url;
	}
	/*
	 * بار اول هم سیاست استثناها فعال باشد **و پراکسی روی هاب بنشیند**.
	 *
	 * باگ تا ۰.۹.۷: `syncProxyToHub()` فقط از مسیرهای `/api/proxy` و `/api/tunnel`
	 * صدا زده می‌شد — یعنی تنها وقتی کاربر صفحهٔ پراکسی را باز می‌کرد یا دکمه‌ای
	 * می‌زد. با هر بار بستن و باز کردن برنامه، `hub.data.proxy.url` خالی می‌ماند و
	 * تماس‌های هاب مستقیم می‌رفتند؛ نتیجه‌اش ۴۲۹/«درخواست‌ها زیاد است» از IP ایران
	 * بود، دقیقاً همان چیزی که کارفرما در Snap23 دید. حالا در بوت هم اعمال می‌شود.
	 */
	setProxyPolicy( runtime.config?.proxy || {} );
	syncProxyToHub().catch( () => {} );

	const routes = {
		'GET /api/state': async () => ( { status: 200, body: await buildState() } ),

		// ---------------------------------------------------------- پرووایدر
		'POST /api/profile': async ( { body } ) => saveProfileRoute( body ),
		'POST /api/profiles': async ( { body } ) => {
			const cfg = await loadConfig();
			if ( body.action === 'activate' ) {
				if ( ! cfg.profiles?.[ body.id ] ) {
					return { status: 404, body: { error: 'پروفایل پیدا نشد.' } };
				}
				cfg.activeProfile = body.id;
				await saveConfig( cfg );
				await runtime.reload();
				broadcast( { type: 'profile' } );
				return { status: 200, body: { ok: true, config: publicConfig( runtime.config ), ready: runtime.ready } };
			}
			if ( body.action === 'remove' ) {
				if ( Object.keys( cfg.profiles || {} ).length <= 1 ) {
					return { status: 400, body: { error: 'آخرین پروفایل را نمی‌شود حذف کرد.' } };
				}
				delete cfg.profiles[ body.id ];
				if ( cfg.activeProfile === body.id ) {
					cfg.activeProfile = Object.keys( cfg.profiles )[ 0 ];
				}
				await saveConfig( cfg );
				await runtime.reload();
				return { status: 200, body: { ok: true, config: publicConfig( runtime.config ) } };
			}
			return saveProfileRoute( body );
		},

		'POST /api/test-connection': async ( { body } ) => {
			const cfg = await loadConfig();
			const profile = body?.id ? cfg.profiles?.[ body.id ] : activeProfile( cfg );
			const check = validateProfile( profile );
			if ( ! check.ok ) {
				return { status: 200, body: { ok: false, message: `تنظیمات ناقص است: ${ check.missing.join( '، ' ) }` } };
			}
			const info = providerInfo( profile.provider );
			const base = profile.baseUrl || info?.baseUrl;
			try {
				const provider = createProvider( profile );
				let text = '';
				for await ( const ev of provider.stream( {
					model: profile.model || info?.defaultModel || '',
					messages: [ { role: 'user', content: 'بگو: سلام' } ],
					maxTokens: 16,
				} ) ) {
					if ( ev.type === 'text' ) {
						text += ev.text;
					}
					if ( ev.type === 'error' ) {
						throw new Error( ev.error );
					}
				}
				return { status: 200, body: { ok: true, message: `اتصال برقرار است. پاسخ مدل: «${ text.trim().slice( 0, 60 ) || '(خالی)' }»` } };
			} catch ( e ) {
				const info2 = explain( e, { baseUrl: base, model: profile.model, provider: profile.provider } );
				return { status: 200, body: { ok: false, message: info2.message, hint: info2.hint, kind: info2.kind } };
			}
		},

		'GET /api/models': async () => {
			const cfg = await loadConfig();
			const p = activeProfile( cfg );
			try {
				const provider = createProvider( p );
				return { status: 200, body: { models: await provider.listModels() } };
			} catch ( e ) {
				const info = explain( e, { baseUrl: p?.baseUrl, provider: p?.provider } );
				return { status: 200, body: { models: [], error: info.message, hint: info.hint } };
			}
		},

		// --------------------------------------------------------------- هاب
		'GET /api/hub': async () => ( {
			status: 200,
			body: {
				...runtime.hub.snapshot(),
				active: runtime.hubActive(),
				catalog: PROVIDERS,
				strategies: STRATEGIES,
				categories: CATEGORIES,
				authStyles: AUTH_STYLES,
			},
		} ),

		'POST /api/hub': async ( { body } ) => {
			const hub = runtime.hub;
			// هر تغییری در تعریف هاب یعنی دنیای عامل عوض شده؛ بدون بازساخت، عامل با
			// پرووایدر قدیمی کار می‌کند و مدیر فکر می‌کند تنظیمش بی‌اثر بوده.
			const rebuild = async () => {
				await runtime.reload();
				broadcast( { type: 'hub' } );
			};

			switch ( body?.action ) {
				case 'toggle': {
					const out = await hub.update( { enabled: Boolean( body.enabled ) } );
					await rebuild();
					return { status: 200, body: { ...out, active: runtime.hubActive(), ready: hub.snapshot().ready } };
				}
				case 'save-connection': {
					const out = await hub.saveConnection( { ...body.connection, id: body.connection?.id || hubId( 'conn' ) } );
					if ( out.ok ) {
						await rebuild();
					}
					return { status: out.ok ? 200 : 400, body: out };
				}
				case 'remove-connection': {
					const out = await hub.removeConnection( String( body.id || '' ) );
					await rebuild();
					return { status: out.ok ? 200 : 404, body: out };
				}
				case 'test-connection':
					return { status: 200, body: await hub.testConnection( String( body.id || '' ), body.model ) };
				case 'discover': {
					const out = await hub.discover( String( body.id || '' ) );
					if ( out.ok ) {
						await rebuild();
					}
					return { status: 200, body: out };
				}
				case 'save-model': {
					const out = await hub.saveModel( body.model || {} );
					await rebuild();
					return { status: out.ok ? 200 : 400, body: out };
				}
				case 'toggle-model': {
					const out = await hub.toggleModel( String( body.key || '' ), body.enabled );
					await rebuild();
					return { status: out.ok ? 200 : 404, body: out };
				}
				case 'save-combo': {
					const out = await hub.saveCombo( body.combo || {} );
					await rebuild();
					return { status: 200, body: out };
				}
				case 'remove-combo': {
					const out = await hub.removeCombo( String( body.id || '' ) );
					await rebuild();
					return { status: 200, body: out };
				}
				case 'update': {
					const out = await hub.update( body.patch || {} );
					await rebuild();
					return { status: 200, body: out };
				}
				case 'explain':
					return { status: 200, body: hub.explainRoute( { text: String( body.text || '' ), hasImages: Boolean( body.hasImages ), tools: body.tools || [] } ) };
				case 'forget-patch':
					return { status: 200, body: await hub.forgetPatch( String( body.signature || '' ) ) };
				case 'promote-patch': {
					const out = await hub.promotePatch( String( body.signature || '' ) );
					await rebuild();
					return { status: out.ok ? 200 : 404, body: out };
				}
				case 'reset-breaker':
					hub.health.reset( String( body.key || '' ) );
					await hub.saveState();
					return { status: 200, body: { ok: true } };
				case 'proxy-test': {
					// مقایسهٔ خروجی با/بدون پراکسی: IP و تأخیر، با مهلت هشت‌ثانیه‌ای.
					const url = normalizeProxy( String( body.proxy ?? hub.data?.proxy?.url ?? '' ) );
					const probe = async ( via ) => {
						const t0 = Date.now();
						try {
							const res = await proxyFetch( 'https://api.ipify.org?format=json', {
								signal: AbortSignal.timeout( 8000 ),
								headers: { 'user-agent': 'vira-proxy-test' },
							}, via );
							const out = await res.json().catch( () => ( {} ) );
							return { ok: res.ok, status: res.status, ip: out.ip || '', ms: Date.now() - t0 };
						} catch ( e ) {
							return { ok: false, error: String( e?.message || e ).slice( 0, 200 ), ms: Date.now() - t0 };
						}
					};
					return { status: 200, body: { ok: true, proxy: url, proxied: await probe( url ), direct: await probe( '' ) } };
				}
				case 'reset-provider': {
					const out = hub.resetProvider( String( body.id || '' ) );
					if ( out.ok ) {
						await hub.saveState();
						broadcast( { type: 'hub' } );
					}
					return { status: out.ok ? 200 : 404, body: out };
				}
				case 'reset-health':
					hub.health.resetAll();
					await hub.saveState();
					broadcast( { type: 'hub' } );
					return { status: 200, body: { ok: true } };
				case 'clear-cache':
					hub.cache.clear();
					return { status: 200, body: { ok: true } };
				default:
					return { status: 400, body: { error: 'کنش ناشناخته برای هاب.' } };
			}
		},

		// ------------------------------------------------------- تونل، پراکسی، لاگ (۰.۹.۶)
		'GET /api/tunnel': async () => ( {
			status: 200,
			body: { ok: true, ...tunnel.status(), core: { present: corePresent(), version: await coreVersion() } },
		} ),

		'POST /api/tunnel': async ( { body } ) => {
			switch ( body?.action ) {
				case 'download-core': {
					const out = await downloadCore( ( m ) => broadcast( { type: 'tunnel', message: m } ) );
					return { status: out.ok ? 200 : 500, body: out };
				}
				case 'harvest':
					return { status: 200, body: await tunnel.harvest() };
				case 'test-all': {
					/*
					 * سرویس معیار برای مرحلهٔ ۲ (خواستهٔ کارفرما ۱۴۰۵/۰۵/۳۰): کانفیگ وقتی
					 * «سالم» است که **پرووایدر واقعی** از راهش جواب بدهد، نه صرفاً اینترنت.
					 * فقط `GET {baseUrl}/models` زده می‌شود — بی‌هزینه و بدون مصرف توکن.
					 * اگر هیچ اتصالی نبود، مرحلهٔ ۲ رد می‌شود تا کاربر بن‌بست نخورد.
					 */
					const conns = Object.values( runtime.hub?.data?.connections || {} );
					const bench = conns.find( ( c ) => c.enabled !== false && ( c.apiKey || c.keyRef ) && c.baseUrl )
						|| conns.find( ( c ) => c.enabled !== false && c.baseUrl );
					const serviceUrl = bench ? `${ String( bench.baseUrl ).replace( /\/+$/, '' ) }/models` : '';

					const out = await tunnel.testAll(
						( p ) => broadcast( { type: 'tunnel', progress: p } ),
						{ serviceUrl, serviceLabel: bench?.label || '' }
					);
					broadcast( { type: 'tunnel' } );
					return { status: 200, body: out };
				}
				case 'cancel-test':
					return { status: 200, body: tunnel.cancelTest() };
				case 'start': {
					const out = await tunnel.start();
					if ( out.ok && runtime.config?.proxy?.mode !== 'engine' ) {
						runtime.config.proxy = { ...( runtime.config?.proxy || {} ), mode: 'engine' };
						await saveConfig( runtime.config );
					}
					await syncProxyToHub();
					broadcast( { type: 'tunnel' } );
					return { status: 200, body: out };
				}
				case 'stop': {
					const out = await tunnel.stop();
					if ( runtime.config?.proxy?.mode === 'engine' ) {
						runtime.config.proxy = { ...runtime.config.proxy, mode: 'off' };
						await saveConfig( runtime.config );
					}
					await syncProxyToHub();
					broadcast( { type: 'tunnel' } );
					return { status: 200, body: out };
				}
				case 'rotate':
					return { status: 200, body: await tunnel.rotate() };
				case 'set-sources':
					return { status: 200, body: tunnel.setSources( body.urls ) };
				case 'toggle-config': {
					const out = tunnel.toggleConfig( String( body.id || '' ), { enabled: Boolean( body.enabled ), pinned: Boolean( body.pinned ) } );
					return { status: out.ok ? 200 : 404, body: out };
				}
				default:
					return { status: 400, body: { error: 'کنش ناشناخته برای تونل.' } };
			}
		},

		'GET /api/proxy': async () => ( {
			status: 200,
			body: { ok: true, proxy: runtime.config?.proxy || {}, effective: await syncProxyToHub() },
		} ),

		'POST /api/proxy': async ( { body } ) => {
			runtime.config.proxy = {
				mode: [ 'off', 'manual', 'engine' ].includes( body.mode ) ? body.mode : 'off',
				address: String( body.address || '127.0.0.1' ).slice( 0, 100 ),
				port: Number( body.port ) || 7890,
				exceptions: String( body.exceptions || '' ).slice( 0, 1000 ),
				bypassLocal: Boolean( body.bypassLocal ),
			};
			await saveConfig( runtime.config );
			const url = await syncProxyToHub();
			broadcast( { type: 'tunnel' } );
			return { status: 200, body: { ok: true, effective: url } };
		},

		'GET /api/logs': async ( { query } ) => ( {
			status: 200,
			body: { ok: true, entries: logs.recent( query || {} ), channels: logs.channels() },
		} ),

		'POST /api/logs': async ( { body } ) => {
			if ( body?.action === 'clear' ) {
					return { status: 200, body: { ok: true, cleared: logs.clear() } };
				}
			return { status: 400, body: { error: 'کنش ناشناخته.' } };
		},

		// خروجی سازگار با OpenAI (تصمیم ۸) — فهرست مدل‌ها. تکمیل چت چون استریم دارد،
		// پایین‌تر در خود سرور مدیریت می‌شود.
		'GET /v1/models': async () => ( { status: 200, body: modelsResponse( runtime.hub ) } ),

		// ------------------------------------------------------------- حالت
		'POST /api/mode': async ( { body } ) => {
			if ( ! MODES.includes( body.mode ) ) {
				return { status: 400, body: { error: 'حالت نامعتبر' } };
			}
			await setMode( body.mode );
			return { status: 200, body: { ok: true } };
		},

		'POST /api/permissions': async ( { body } ) => {
			const cfg = await loadConfig();
			cfg.permissions = {
				mode: MODES.includes( body.mode ) ? body.mode : cfg.permissions.mode,
				allow: cleanList( body.allow ?? cfg.permissions.allow ),
				ask: cleanList( body.ask ?? cfg.permissions.ask ),
				deny: cleanList( body.deny ?? cfg.permissions.deny ),
			};
			await saveConfig( cfg );
			runtime.config = cfg;
			if ( runtime.agent ) {
				runtime.agent.rules = cfg.permissions;
			}
			broadcast( { type: 'mode', mode: cfg.permissions.mode } );
			return { status: 200, body: { ok: true, permissions: cfg.permissions } };
		},

		'POST /api/workspace': async ( { body } ) => {
			const dir = path.resolve( String( body.path || '' ) );
			const stat = await fs.stat( dir ).catch( () => null );
			if ( ! stat?.isDirectory() ) {
				return { status: 400, body: { error: 'این مسیر یک پوشه نیست.' } };
			}
			const cfg = await loadConfig();
			cfg.workspace = dir;
			await saveConfig( cfg );
			await runtime.reload();
			await runtime.loadProjectMemory();
			rebindCheckpoints();
			broadcast( { type: 'workspace', path: dir } );
			return { status: 200, body: { ok: true, path: dir } };
		},

		// -------------------------------------------------------- کانکتورها
		'POST /api/connectors': async ( { body } ) => {
			const scope = body.scope === 'project' ? 'project' : 'user';
			const opts = { workspace: runtime.config.workspace, scope };
			try {
				if ( body.action === 'test' ) {
					return { status: 200, body: await testConnector( body, HOME ) };
				}
				if ( body.action === 'save' ) {
					const out = await saveConnector( opts, body );
					await runtime.reload();
					broadcast( { type: 'notice', text: `کانکتور «${ out.name }» ذخیره شد.` } );
					return { status: 200, body: { ok: true, connector: out, mcp: runtime.mcp.status } };
				}
				if ( body.action === 'remove' ) {
					await removeConnector( opts, String( body.name ) );
					await runtime.reload();
					return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
				}
				if ( body.action === 'toggle' ) {
					await setConnectorEnabled( opts, String( body.name ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ----------------------------------------------------------- اسکیل
		'POST /api/skills': async ( { body } ) => {
			try {
				if ( body.action === 'install' ) {
					const out = await installSkill( HOME, String( body.source || '' ), body.name );
					await runtime.reload();
					return { status: 200, body: { ok: true, ...out } };
				}
				if ( body.action === 'remove' ) {
					await removeSkill( HOME, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'toggle' ) {
					await setSkillEnabled( HOME, String( body.name || '' ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- پلاگین
		'POST /api/plugins': async ( { body } ) => {
			try {
				if ( body.action === 'install' ) {
					const out = await installPlugin( HOME, String( body.source || '' ), body.name );
					await runtime.reload();
					return { status: 200, body: { ok: true, plugin: out } };
				}
				if ( body.action === 'remove' ) {
					await removePlugin( HOME, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'toggle' ) {
					await setPluginEnabled( HOME, String( body.name || '' ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'marketplace' ) {
					return { status: 200, body: { ok: true, marketplace: await fetchMarketplace( String( body.source || '' ) ) } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ---------------------------------------------------------- عامل‌ها
		'POST /api/agents': async ( { body } ) => {
			const roots = { home: HOME, workspace: runtime.config.workspace };
			try {
				if ( body.action === 'remove' ) {
					await removeAgent( roots, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				const out = await saveAgent( roots, body );
				await runtime.reload();
				return { status: 200, body: { ok: true, agent: out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- دستورها
		'POST /api/commands': async ( { body } ) => {
			const roots = { home: HOME, workspace: runtime.config.workspace };
			try {
				if ( body.action === 'remove' ) {
					await removeCommand( roots, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				const out = await saveCommand( roots, body );
				await runtime.reload();
				return { status: 200, body: { ok: true, command: out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ------------------------------------------------------------ هوک
		'POST /api/hooks': async ( { body } ) => {
			const cfg = await loadConfig();
			cfg.hooks = body.hooks && typeof body.hooks === 'object' ? body.hooks : {};
			await saveConfig( cfg );
			await runtime.reload();
			return { status: 200, body: { ok: true, hooks: cfg.hooks } };
		},

		'GET /api/hooks': async () => ( { status: 200, body: { hooks: runtime.config.hooks || {} } } ),

		// ------------------------------------------------------- حافظهٔ پروژه
		'GET /api/memory': async () => {
			const file = path.join( runtime.config.workspace, 'VIRA.md' );
			const text = await fs.readFile( file, 'utf8' ).catch( () => '' );
			return { status: 200, body: { path: file, text } };
		},

		'POST /api/memory': async ( { body } ) => {
			const file = path.join( runtime.config.workspace, 'VIRA.md' );
			await fs.writeFile( file, String( body.text ?? '' ), 'utf8' );
			await runtime.loadProjectMemory();
			return { status: 200, body: { ok: true, path: file } };
		},

		// ------------------------------------------------------------ فایل‌ها
		'GET /api/files': async ( { url } ) => {
			const q = url.searchParams.get( 'q' ) || '';
			const files = await listFiles( runtime.config.workspace );
			return { status: 200, body: { files: fuzzyFilter( files, q, 25 ), total: files.length } };
		},

		'GET /api/file': async ( { url } ) => {
			try {
				return { status: 200, body: await readWorkspaceFile( runtime.config.workspace, url.searchParams.get( 'path' ) || '' ) };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		'GET /api/git': async ( { url } ) => {
			const dir = runtime.config.workspace;
			const base = url.searchParams.get( 'base' ) || undefined;
			const wantDiff = url.searchParams.get( 'diff' );

			const st = await vcs.status( dir );
			if ( ! st ) {
				const known = url.searchParams.get( 'repos' ) !== null ? await vcs.repos() : undefined;
				return { status: 200, body: { git: null, locked: gitLocked, known } };
			}

			const [ stat, list, history ] = await Promise.all( [
				vcs.diffStat( dir, base ),
				vcs.branches( dir ),
				vcs.log( dir, 15 ),
			] );

			const diff = wantDiff !== null ? await vcs.diff( dir, { base, file: wantDiff || undefined } ) : undefined;
			const known = url.searchParams.get( 'repos' ) !== null ? await vcs.repos() : undefined;
			return { status: 200, body: { git: st, stat, branches: list, log: history, diff, locked: gitLocked, known } };
		},

		'POST /api/git': async ( { body } ) => {
			const dir = runtime.config.workspace;
			try {
				if ( [ 'branch', 'use-repo' ].includes( body.action ) && gitLocked ) {
					return { status: 409, body: { error: 'گفتگو شروع شده؛ مخزن و شاخه تا گفتگوی تازه قفل‌اند.' } };
				}
				/*
				 * انتخاب مخزن از فهرست مجاز.
				 *
				 * اگر همان‌جایی که هست کلون شده باشد، فقط پوشهٔ کاری عوض می‌شود؛ وگرنه یک
				 * بار کلون می‌شود کنار خانهٔ ویرا. هیچ‌وقت روی پوشهٔ فعلی چیزی بازنویسی
				 * نمی‌شود.
				 */
				if ( body.action === 'use-repo' ) {
					const wanted = String( body.repo || '' ).trim();
					if ( ! wanted ) {
						return { status: 400, body: { error: 'نام مخزن خالی است.' } };
					}
					const folder = wanted.split( '/' ).pop().replace( /[^\w.-]/g, '-' );
					const into = path.join( HOME, 'repos' );
					await fs.mkdir( into, { recursive: true } );
					const target = path.join( into, folder );
					const already = await vcs.isRepo( target );
					if ( ! already ) {
						const out = await vcs.clone( { url: `https://github.com/${ wanted }.git`, into, name: folder, branch: body.branch } );
						if ( out?.ok === false ) {
							return { status: 400, body: { error: out.message || 'کلون نشد.' } };
						}
					} else if ( body.branch ) {
						await vcs.branch( target, String( body.branch ), { create: false } );
					}
					runtime.config.workspace = target;
					await saveConfig( runtime.config );
					await runtime.reload( { keepHistory: true } );
					broadcast( { type: 'workspace', path: target } );
					return { status: 200, body: { ok: true, git: await vcs.status( target ), workspace: target } };
				}
				if ( body.action === 'branch' ) {
					const out = await vcs.branch( dir, String( body.name || '' ), { create: Boolean( body.create ) } );
					broadcast( { type: 'git', branch: out.branch } );
					return { status: 200, body: { ok: true, ...out } };
				}
				if ( body.action === 'commit' ) {
					const out = await vcs.commit( dir, {
						message: String( body.message || '' ),
						paths: Array.isArray( body.paths ) ? body.paths : undefined,
						branch: body.branch,
					} );
					broadcast( { type: 'notice', text: `کامیت ${ out.sha } روی «${ out.branch }»` } );
					broadcast( { type: 'git' } );
					return { status: 200, body: { ok: true, ...out } };
				}
				if ( body.action === 'push' ) {
					const out = await vcs.push( dir, { branch: body.branch, token: body.token } );
					broadcast( { type: 'notice', text: `شاخهٔ «${ out.branch }» فرستاده شد.` } );
					broadcast( { type: 'git' } );
					return { status: 200, body: { ok: true, ...out } };
				}
				if ( body.action === 'pr' ) {
					const out = await vcs.pullRequest( dir, {
						title: String( body.title || '' ),
						body: String( body.body || '' ),
						base: body.base,
					} );
					return { status: out.ok ? 200 : 400, body: out.ok ? { ok: true, url: out.url } : { error: out.message } };
				}
				if ( body.action === 'clone' ) {
					const into = path.join( HOME, 'repos' );
					const out = await vcs.clone( {
						url: String( body.url || '' ),
						into,
						name: body.name,
						token: body.token,
						branch: body.branch,
					} );
					const cfg = await loadConfig();
					cfg.workspace = out.path;
					await saveConfig( cfg );
					await runtime.reload();
					await runtime.loadProjectMemory();
					rebindCheckpoints();
					broadcast( { type: 'workspace', path: out.path } );
					return { status: 200, body: { ok: true, ...out } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// -------------------------------------------------------- چک‌پوینت
		'GET /api/checkpoints': async () => ( { status: 200, body: { checkpoints: await runtime.checkpoints.list() } } ),

		'POST /api/rewind': async ( { body } ) => {
			try {
				const out = await runtime.checkpoints.restore( String( body.id ), {
					files: body.files !== false,
					conversation: body.conversation !== false,
				} );
				if ( body.conversation !== false && runtime.agent ) {
					const kept = runtime.agent.messages.slice( 0, out.messageCount );
					runtime.agent.messages = kept;
					transcript = trimTranscript( transcript, kept.filter( ( m ) => m.role === 'user' ).length );
				}
				broadcast( {
					type: 'rewound',
					restored: out.restored,
					deleted: out.deleted,
					transcript,
				} );
				return { status: 200, body: { ok: true, ...out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ---------------------------------------------------------- شل‌ها
		'GET /api/shells': async () => ( { status: 200, body: { shells: shells.list() } } ),

		'POST /api/shells': async ( { body } ) => {
			try {
				if ( body.action === 'kill' ) {
					shells.kill( String( body.id ) );
					return { status: 200, body: { ok: true, shells: shells.list() } };
				}
				if ( body.action === 'read' ) {
					return { status: 200, body: shells.read( String( body.id ), { peek: true } ) };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- مصرف
		'GET /api/usage': async () => {
			const history = await readUsage( HOME );
			return {
				status: 200,
				body: {
					session: { ...( runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 } ), cost: sessionCost.cost },
					history,
					model: runtime.agent?.model || '',
				},
			};
		},

		'POST /api/sandbox': async ( { body } ) => {
			if ( body.action === 'test' ) {
				return { status: 200, body: await testSandbox( { sandbox: body.sandbox || runtime.config.sandbox }, runtime.config.workspace ) };
			}
			if ( body.action === 'save' ) {
				const cfg = await loadConfig();
				cfg.sandbox = resolveSandbox( { sandbox: { ...( cfg.sandbox || {} ), ...( body.sandbox || {} ) } } );
				await saveConfig( cfg );
				runtime.config = cfg;
				if ( runtime.agent ) {
					runtime.agent.sandbox = cfg.sandbox;
				}
				broadcast( {
					type: 'notice',
					text: cfg.sandbox.enabled
						? 'سندباکس روشن شد — از این پس فرمان‌ها داخل کانتینر اجرا می‌شوند.'
						: 'سندباکس خاموش شد.',
				} );
				return { status: 200, body: { ok: true, sandbox: await sandboxStatus( cfg ) } };
			}
			return { status: 400, body: { error: 'کنش ناشناخته' } };
		},

		'GET /api/sandbox': async () => ( { status: 200, body: await sandboxStatus( runtime.config ) } ),

		'GET /api/doctor': async () => {
			const active = activeProfile( runtime.config ) || {};
			return {
				status: 200,
				body: await diagnose( {
					home: HOME,
					workspace: runtime.config.workspace,
					config: runtime.config,
					runtime,
					providerInfo: providerInfo( active.provider ),
				} ),
			};
		},

		'GET /api/export': async ( { url } ) => {
			const data = {
				sessionId,
				transcript,
				messages: runtime.agent?.messages || [],
				model: runtime.agent?.model,
				workspace: runtime.config.workspace,
			};
			if ( url.searchParams.get( 'format' ) === 'json' ) {
				return { status: 200, raw: toJson( data ), type: 'application/json; charset=utf-8' };
			}
			return { status: 200, raw: toMarkdown( data ), type: 'text/markdown; charset=utf-8' };
		},

		// ------------------------------------------------------------- چت
		'POST /api/message': async ( { body } ) => {
			const text = String( body.text || '' ).trim();
			// از همین لحظه، مخزن و شاخهٔ این گفتگو قفل است.
			gitLocked = true;
			const images = normalizeImages( body.images );
			if ( ! text && ! images.length ) {
				return { status: 400, body: { error: 'متن خالی است.' } };
			}

			const intent = parseInput( text, runtime.commands );

			// پرامپت‌های MCP هم مثل دستور اسلش صدا زده می‌شوند، ولی متنشان از خود سرور
			// گرفته می‌شود: /mcp__<سرور>__<پرامپت> [آرگومان‌ها]
			if ( intent.kind === 'builtin' && intent.name.startsWith( 'mcp__' ) ) {
				const entry = runtime.mcp.promptEntries().find( ( p ) => p.name.toLowerCase() === intent.name );
				if ( entry ) {
					try {
						const filled = await runtime.mcp.getPrompt( entry.server, entry.prompt, parseArgs( intent.args ) );
						intent.kind = 'prompt';
						intent.text = filled || intent.args || entry.prompt;
					} catch ( e ) {
						return { status: 400, body: { error: e?.message || String( e ) } };
					}
				}
			}

			if ( intent.kind === 'builtin' ) {
				const out = await handleBuiltin( intent.name, intent.args );
				return { status: 200, body: { ok: true, handled: true, ...out } };
			}

			if ( ! runtime.ready.ok ) {
				return { status: 400, body: { error: `تنظیمات ناقص است: ${ runtime.ready.missing.join( '، ' ) }` } };
			}
			const agent = runtime.agent;
			if ( agent.busy ) {
				return { status: 409, body: { error: 'یک درخواست در حال اجراست.' } };
			}

			if ( ! sessionTitle ) {
				sessionTitle = text.slice( 0, 60 );
			}
			await runtime.checkpoints.begin( { label: text.slice( 0, 80 ), messageCount: agent.messages.length } );
			broadcast( { type: 'checkpoint', checkpoints: await runtime.checkpoints.list() } );

			agent
				.run( intent.text, { images } )
				.then( () => saveSession( sessionId, { messages: agent.messages, transcript, title: sessionTitle } ) );
			return { status: 202, body: { ok: true } };
		},

		'POST /api/permission': async ( { body } ) => {
			// فرمان مرکب، بیش از یک قاعده لازم دارد: «git status && npm test» یعنی دو قاعده.
			const rules = cleanList( body.rules?.length ? body.rules : body.rule ? [ body.rule ] : [] );

			if ( body.remember && rules.length ) {
				const cfg = await loadConfig();
				const bucket = body.decision === 'deny' ? 'deny' : 'allow';
				cfg.permissions[ bucket ] = [ ...new Set( [ ...( cfg.permissions[ bucket ] || [] ), ...rules ] ) ];
				await saveConfig( cfg );
				runtime.config = cfg;
				if ( runtime.agent ) {
					runtime.agent.rules = cfg.permissions;
				}
				broadcast( {
					type: 'notice',
					text:
						bucket === 'allow'
							? `از این پس بدون پرسش اجرا می‌شود: ${ rules.join( '، ' ) }`
							: `از این پس همیشه رد می‌شود: ${ rules.join( '، ' ) }`,
				} );
			}
			return { status: 200, body: { ok: Boolean( runtime.agent?.resolvePermission( body.id, body.decision ) ) } };
		},

		'POST /api/answer': async ( { body } ) => {
			// جواب دادن به ask_user_question یا تأیید نقشه (exit_plan_mode).
			if ( body.mode && MODES.includes( body.mode ) ) {
				await setMode( body.mode );
			}
			const ok = Boolean( runtime.agent?.resolveQuestion( body.id, body.value ) );
			broadcast( { type: 'ask_answered', id: body.id, value: body.value } );
			return { status: 200, body: { ok } };
		},

		'POST /api/stop': async () => {
			runtime.agent?.stop();
			return { status: 200, body: { ok: true } };
		},

		'POST /api/new': async () => {
			if ( runtime.agent && runtime.agent.messages.length ) {
				await saveSession( sessionId, { messages: runtime.agent.messages, transcript, title: sessionTitle } );
			}
			transcript = [];
			todos = [];
			pendingAsk = [];
			sessionTitle = '';
			sessionCost = { inputTokens: 0, outputTokens: 0, cost: 0 };
			sessionId = `s_${ Date.now().toString( 36 ) }`;
			gitLocked = false;
			await runtime.reload( { keepHistory: false } );
			rebindCheckpoints();
			broadcast( { type: 'reset', sessionId } );
			return { status: 200, body: { ok: true, sessionId } };
		},

		'GET /api/sessions': async () => ( { status: 200, body: { sessions: await listSessions() } } ),

		'POST /api/sessions': async ( { body } ) => {
			try {
				if ( body.action === 'delete' ) {
					await deleteSession( String( body.id ) );
					return { status: 200, body: { ok: true, sessions: await listSessions() } };
				}
				if ( body.action === 'rename' ) {
					await renameSession( String( body.id ), String( body.title || '' ) );
					if ( body.id === sessionId ) {
						sessionTitle = String( body.title || '' );
					}
					return { status: 200, body: { ok: true, sessions: await listSessions() } };
				}
				// نسبت‌دادن گفتگو به یک پروژه (پوشه). رشتهٔ خالی یعنی برداشتنِ نسبت.
				if ( body.action === 'project' ) {
					await setSessionProject( String( body.id ), String( body.project || '' ) );
					return { status: 200, body: { ok: true, sessions: await listSessions() } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		'POST /api/resume': async ( { body } ) => {
			const saved = await loadSession( String( body.id || '' ) );
			if ( ! saved ) {
				return { status: 404, body: { error: 'نشست پیدا نشد.' } };
			}
			await runtime.reload( { keepHistory: false } );
			runtime.agent.messages = saved.messages || [];
			transcript = saved.transcript || [];
			sessionId = saved.id;
			sessionTitle = saved.title || '';
			rebindCheckpoints();
			broadcast( { type: 'resumed', sessionId, transcript, title: sessionTitle } );
			return { status: 200, body: { ok: true, sessionId, transcript } };
		},

		'POST /api/reload': async () => {
			await runtime.reload();
			await runtime.loadProjectMemory();
			broadcast( { type: 'notice', text: 'اسکیل‌ها، پلاگین‌ها، عامل‌ها و کانکتورها دوباره بارگذاری شدند.' } );
			return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
		},
	};

	// ------------------------------------------------------------- کمکی‌ها

	/** @param {any} body */
	async function saveProfileRoute( body ) {
		const cfg = await loadConfig();
		const id = String( body.id || 'default' ).replace( /[^\w-]/g, '' ) || 'default';
		const prev = cfg.profiles[ id ] || {};
		const info = providerInfo( body.provider );
		cfg.profiles[ id ] = {
			label: body.label || prev.label || id,
			provider: body.provider || prev.provider,
			baseUrl: body.baseUrl ?? ( info?.editableBaseUrl ? prev.baseUrl : '' ) ?? '',
			apiKey: body.apiKey ? body.apiKey : prev.apiKey || '',
			model: body.model || info?.defaultModel || '',
		};
		if ( body.activate !== false ) {
			cfg.activeProfile = id;
		}
		await saveConfig( cfg );
		await runtime.reload();
		broadcast( { type: 'profile' } );
		return { status: 200, body: { ok: true, config: publicConfig( runtime.config ), ready: runtime.ready } };
	}

	/** @param {string} mode */
	async function setMode( mode ) {
		const cfg = await loadConfig();
		cfg.permissions.mode = mode;
		await saveConfig( cfg );
		runtime.config = cfg;
		if ( runtime.agent ) {
			runtime.agent.rules = cfg.permissions;
		}
		broadcast( { type: 'mode', mode } );
	}

	const server = http.createServer( async ( req, res ) => {
		const url = new URL( req.url || '/', `http://${ req.headers.host || 'localhost' }` );
		const send = ( code, body, type = 'application/json; charset=utf-8' ) => {
			res.writeHead( code, { 'Content-Type': type, 'Cache-Control': 'no-store' } );
			res.end( typeof body === 'string' || Buffer.isBuffer( body ) ? body : JSON.stringify( body ) );
		};

		try {
			// رویدادها (SSE)
			if ( url.pathname === '/api/events' ) {
				res.writeHead( 200, {
					'Content-Type': 'text/event-stream; charset=utf-8',
					'Cache-Control': 'no-cache',
					Connection: 'keep-alive',
					'X-Accel-Buffering': 'no',
				} );
				res.write( `data: ${ JSON.stringify( { type: 'hello', sessionId } ) }\n\n` );
				clients.add( res );
				const ping = setInterval( () => res.write( ': ping\n\n' ), 25_000 );
				req.on( 'close', () => {
					clearInterval( ping );
					clients.delete( res );
				} );
				return;
			}

			// تکمیل چت سازگار با OpenAI: پاسخش می‌تواند استریم باشد، پس مثل بقیهٔ مسیرها
			// از جدول رد نمی‌شود و مستقیم روی `res` می‌نویسد.
			if ( url.pathname === '/v1/chat/completions' && req.method === 'POST' ) {
				if ( ! runtime.hubActive() ) {
					return send( 503, { error: { message: 'هاب روشن یا آماده نیست.', type: 'hub_unavailable' } } );
				}
				const body = await readJson( req );
				await handleChatCompletions( runtime.hub, body, res );
				return;
			}

			const key = `${ req.method } ${ url.pathname }`;
			const handler = routes[ key ];
			if ( handler ) {
				const body = req.method === 'POST' ? await readJson( req ) : {};
				const out = await handler( { body, url, req } );
				if ( out.raw !== undefined ) {
					return send( out.status || 200, out.raw, out.type );
				}
				return send( out.status || 200, out.body );
			}

			// یک نشست مشخص
			if ( url.pathname.startsWith( '/api/sessions/' ) && req.method === 'GET' ) {
				const id = url.pathname.split( '/' ).pop();
				return send( 200, ( await loadSession( id ) ) || { error: 'پیدا نشد' } );
			}

			if ( url.pathname.startsWith( '/api/' ) ) {
				return send( 404, { error: `مسیر ناشناخته: ${ url.pathname }` } );
			}

			// فایل‌های رابط کاربری
			const rel = url.pathname === '/' ? 'index.html' : url.pathname.replace( /^\/+/, '' );
			const file = path.join( UI_DIR, rel );
			if ( ! file.startsWith( UI_DIR ) ) {
				return send( 403, { error: 'ممنوع' } );
			}
			const data = await fs.readFile( file ).catch( () => null );
			if ( ! data ) {
				return send( 404, { error: 'پیدا نشد' } );
			}
			return send( 200, data, MIME[ path.extname( file ) ] || 'application/octet-stream' );
		} catch ( e ) {
			return send( 500, { error: e?.message || String( e ) } );
		}
	} );

	/**
	 * دستورهای داخلی — این‌ها اصلاً به مدل نمی‌رسند.
	 * @param {string} name
	 * @param {string} args
	 */
	async function handleBuiltin( name, args ) {
		const say = ( text ) => {
			broadcast( { type: 'system', text } );
			return { text };
		};
		const open = ( panel, tab ) => {
			broadcast( { type: 'open_panel', panel, tab } );
			return { panel, tab };
		};

		switch ( name ) {
			case 'help': {
				const lines = [ '**دستورهای داخلی**', ...BUILTIN_COMMANDS.map( ( c ) => `/${ c.name } — ${ c.description }` ) ];
				if ( runtime.commands.length ) {
					lines.push( '', '**دستورهای خودت**' );
					lines.push( ...runtime.commands.map( ( c ) => `/${ c.name } — ${ c.description || '' } (${ c.source })` ) );
				}
				lines.push( '', 'میان‌برها: Shift+Tab حالت · Esc توقف · Esc Esc بازگشت · @ فایل · / دستور · Ctrl+K جستجو · ? میان‌برها' );
				return say( lines.join( '\n' ) );
			}

			case 'clear': {
				transcript = [];
				todos = [];
				pendingAsk = [];
				sessionTitle = '';
				sessionId = `s_${ Date.now().toString( 36 ) }`;
				await runtime.reload( { keepHistory: false } );
				rebindCheckpoints();
				broadcast( { type: 'reset', sessionId } );
				return { ok: true };
			}

			case 'compact': {
				if ( ! runtime.agent?.messages.length ) {
					return say( 'چیزی برای فشرده‌کردن نیست.' );
				}
				const r = await runtime.agent.compactNow();
				return say( `گفتگو فشرده شد: ${ r.before } پیام → ${ r.after } پیام.` );
			}

			case 'mode': {
				if ( ! args ) {
					return say( `حالت فعلی: ${ runtime.config.permissions.mode }` );
				}
				if ( ! MODES.includes( args ) ) {
					return say( `حالت نامعتبر. یکی از این‌ها: ${ MODES.join( ' | ' ) }` );
				}
				await setMode( args );
				return say( `حالت شد: ${ args }` );
			}

			case 'model': {
				const cfg = await loadConfig();
				const profile = activeProfile( cfg );
				if ( ! args ) {
					return say( `پرووایدر: ${ profile.provider }\nمدل: ${ profile.model }` );
				}
				profile.model = args;
				await saveConfig( cfg );
				await runtime.reload();
				broadcast( { type: 'profile' } );
				return say( `مدل شد: ${ args }` );
			}

			case 'config':
			case 'settings':
				return open( 'settings', 'provider' );

			case 'login':
			case 'provider':
				return open( 'settings', 'provider' );

			case 'connectors':
			case 'mcp': {
				if ( ! args ) {
					return open( 'settings', 'connectors' );
				}
				if ( ! runtime.mcp.status.length ) {
					return say( 'هیچ کانکتوری تنظیم نشده. از تنظیمات → کانکتورها یکی اضافه کن.' );
				}
				return say(
					runtime.mcp.status
						.map( ( s ) =>
							s.status === 'connected'
								? `✓ ${ s.name } — ${ s.tools.length } ابزار: ${ s.tools.join( ', ' ) }`
								: `✗ ${ s.name } — ${ s.status }${ s.error ? `: ${ s.error }` : '' }`
						)
						.join( '\n' )
				);
			}

			case 'tools':
				return open( 'settings', 'tools' );

			case 'skills':
				return open( 'settings', 'skills' );

			case 'install': {
				if ( ! args ) {
					return say( 'کاربرد: /install <owner/repo یا آدرس گیت یا مسیر محلی>\nیا همان آدرس را در گفتگو بینداز و بگو «نصبش کن».' );
				}
				try {
					const tool = runtime.tools().install;
					const out = await tool.run( { source: args }, { workspace: runtime.config.workspace } );
					broadcast( { type: 'notice', text: out } );
					return { ok: true };
				} catch ( e ) {
					return say( `نصب نشد: ${ e?.message || e }` );
				}
			}

			case 'git':
			case 'changes':
				broadcast( { type: 'open_view', view: 'changes' } );
				return { ok: true };

			case 'agents':
				return open( 'settings', 'agents' );

			case 'hooks':
				return open( 'settings', 'hooks' );

			case 'memory':
			case 'init':
				return open( 'settings', 'memory' );

			case 'permissions':
				return open( 'settings', 'permissions' );

			case 'sandbox':
				return open( 'settings', 'sandbox' );

			case 'usage':
			case 'cost':
				return open( 'settings', 'usage' );

			case 'doctor':
			case 'status':
				return open( 'settings', 'status' );

			case 'plugin': {
				const [ sub, ...rest ] = args.split( /\s+/ ).filter( Boolean );
				const value = rest.join( ' ' );
				if ( ! sub ) {
					return open( 'settings', 'plugins' );
				}
				try {
					if ( sub === 'list' ) {
						const list = await listPlugins( HOME );
						return say(
							list.length
								? list.map( ( p ) => `${ p.enabled ? '✓' : '✗' } ${ p.name }` ).join( '\n' )
								: 'هیچ پلاگینی نصب نیست.'
						);
					}
					if ( sub === 'install' ) {
						const out = await installPlugin( HOME, value );
						await runtime.reload();
						return say( `پلاگین «${ out.name }» نصب شد.` );
					}
					if ( sub === 'remove' ) {
						await removePlugin( HOME, value );
						await runtime.reload();
						return say( `پلاگین «${ value }» حذف شد.` );
					}
					return say( 'کاربرد: /plugin list | install <منبع> | remove <نام>' );
				} catch ( e ) {
					return say( `خطا: ${ e?.message || e }` );
				}
			}

			case 'rewind': {
				const list = await runtime.checkpoints.list();
				if ( ! list.length ) {
					return say( 'هنوز چک‌پوینتی ساخته نشده.' );
				}
				broadcast( { type: 'open_rewind', checkpoints: list } );
				return { ok: true };
			}

			case 'bashes': {
				const list = shells.list();
				if ( ! list.length ) {
					return say( 'شل پس‌زمینه‌ای در کار نیست.' );
				}
				return say( list.map( ( s ) => `• ${ s.id } [${ s.status }] ${ s.command }` ).join( '\n' ) );
			}

			case 'todos': {
				if ( ! todos.length ) {
					return say( 'فهرست کار خالی است.' );
				}
				const icon = { pending: '☐', in_progress: '▸', completed: '☑' };
				return say( todos.map( ( t ) => `${ icon[ t.status ] || '☐' } ${ t.content }` ).join( '\n' ) );
			}

			case 'export': {
				broadcast( { type: 'export', format: args || 'md' } );
				return { ok: true };
			}

			case 'workspace': {
				if ( ! args ) {
					return say( `پوشهٔ کاری: ${ runtime.config.workspace }` );
				}
				const dir = path.resolve( args );
				const stat = await fs.stat( dir ).catch( () => null );
				if ( ! stat?.isDirectory() ) {
					return say( 'این مسیر یک پوشه نیست.' );
				}
				const cfg = await loadConfig();
				cfg.workspace = dir;
				await saveConfig( cfg );
				await runtime.reload();
				await runtime.loadProjectMemory();
				rebindCheckpoints();
				broadcast( { type: 'workspace', path: dir } );
				return say( `پوشهٔ کاری شد: ${ dir }` );
			}

			case 'sessions':
			case 'resume': {
				broadcast( { type: 'open_sessions' } );
				return { ok: true };
			}

			default:
				return say( `دستور ناشناخته: /${ name }\nبرای فهرست دستورها /help را بزن.` );
		}
	}

	await new Promise( ( resolve ) => server.listen( port, host, resolve ) );

	const shutdown = async () => {
		await runtime.hooks.run( 'SessionEnd', { sessionId } ).catch( () => {} );
		shells.killAll();
		await runtime.close();
	};
	process.on( 'SIGINT', async () => {
		await shutdown();
		process.exit( 0 );
	} );
	process.on( 'SIGTERM', async () => {
		await shutdown();
		process.exit( 0 );
	} );

	return { server, port, host, config: runtime.config, runtime };
}

/**
 * پیوست‌های تصویری: فقط تصویر، فقط تا سقف معقول، و همیشه base64 خام.
 * @param {any} value
 */
function normalizeImages( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return value
		.slice( 0, 8 )
		.filter( ( x ) => x && typeof x.data === 'string' && /^image\//.test( String( x.mediaType || '' ) ) )
		.map( ( x ) => ( {
			name: String( x.name || 'image' ).slice( 0, 120 ),
			mediaType: String( x.mediaType ),
			data: String( x.data ).replace( /^data:[^;]+;base64,/, '' ),
		} ) );
}

/**
 * آرگومان‌های یک پرامپت MCP: یا `key=value` است یا یک رشتهٔ آزاد که به `input` می‌رود.
 * @param {string} args
 */
function parseArgs( args ) {
	const raw = String( args || '' ).trim();
	if ( ! raw ) {
		return {};
	}
	if ( ! /(^|\s)[\w-]+=/.test( raw ) ) {
		return { input: raw };
	}
	/** @type {Record<string,string>} */
	const out = {};
	for ( const m of raw.matchAll( /([\w-]+)=("[^"]*"|\S+)/g ) ) {
		out[ m[ 1 ] ] = m[ 2 ].replace( /^"|"$/g, '' );
	}
	return out;
}

/** @param {any} value */
function cleanList( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return [ ...new Set( value.map( ( v ) => String( v ).trim() ).filter( Boolean ) ) ];
}

/**
 * بریدن نوار رویدادها هم‌زمان با بریدن پیام‌ها.
 *
 * معیار، شمارش رویدادهای `user` است: اگر بعد از بازگشت فقط N پیام کاربر مانده، هرچه بعد از
 * شروع پیام کاربرِ N+1 آمده باید از صفحه هم برود. وگرنه کاربر متنی را می‌بیند که دیگر در
 * حافظهٔ مدل نیست — و این بدترین نوع سردرگمی است.
 *
 * @param {any[]} list
 * @param {number} userCount
 */
export function trimTranscript( list, userCount ) {
	if ( userCount <= 0 ) {
		return [];
	}
	let seen = 0;
	const out = [];
	for ( const ev of list ) {
		if ( ev.type === 'user' ) {
			seen++;
			if ( seen > userCount ) {
				break;
			}
		}
		out.push( ev );
	}
	return out;
}

/** @param {import('node:http').IncomingMessage} req */
function readJson( req ) {
	return new Promise( ( resolve, reject ) => {
		let raw = '';
		req.on( 'data', ( c ) => {
			raw += c;
			if ( raw.length > 5_000_000 ) {
				reject( new Error( 'بدنهٔ درخواست خیلی بزرگ است.' ) );
				req.destroy();
			}
		} );
		req.on( 'end', () => {
			try {
				resolve( raw ? JSON.parse( raw ) : {} );
			} catch {
				reject( new Error( 'JSON نامعتبر' ) );
			}
		} );
		req.on( 'error', reject );
	} );
}
