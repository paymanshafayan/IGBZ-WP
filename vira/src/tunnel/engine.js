/**
 * موتور تونل ویرا (۰.۹.۶) — جمع‌آوری کانفیگ رایگان، تست، انبارِ کارکردني‌ها،
 * درگاه محلی پایدار با چرخش خودکار. درخواست کارفرما: «یک موتور داخلی مثل v2ray».
 *
 * مدل: هستهٔ xray دو نقش دارد —
 *   ۱) آزمونگر: برای هر کانفیگ کاندید، یک پروسهٔ کوتاه با درگاه socks موقت.
 *   ۲) سرویس: یک پروسهٔ بلند با socks:7809 و http:7810 که از بهترینِ انبار می‌گذرد.
 */

import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import net from 'node:net';
import { HOME } from '../config.js';
import { coreBin, corePresent } from './core.js';
import { parseAll } from './parse.js';
import { logInfo, logWarn, logError } from '../logs.js';

export const SOCKS_PORT = 7809;
export const HTTP_PORT = 7810;
const POOL_FILE = () => path.join( String( HOME ), 'tunnel', 'pool.json' );

/**
 * مقصدهای غربال سریع (مرحلهٔ ۱).
 *
 * تا ۰.۹.۶ فقط یک آدرس بود (`api.ipify.org`) و اگر خودش پاسخ نمی‌داد — مسدود، خطای
 * DNS، یا rate-limit — **همهٔ کانفیگ‌ها یک‌جا «خراب» علامت می‌خوردند**؛ همان تجربهٔ
 * «هیچ‌کدام اکتیو نیست» با کانفیگ‌هایی که در Hiddify کار می‌کردند. حالا چند مقصد سبک
 * داریم و شکست فقط وقتی است که **همه** رد شوند. `generate_204` بدنه ندارد، پس
 * پارس JSON هم لازم نیست (همان چیزی که Hiddify استفاده می‌کند).
 */
const PROBE_URLS = [
	'http://cp.cloudflare.com/generate_204',
	'http://www.gstatic.com/generate_204',
	'https://api.ipify.org?format=json',
];

/**
 * منابع پیش‌فرض — فهرست شش‌گانهٔ کارفرما (۱۴۰۵/۰۵/۲۹) با مسیرهای raw راستی‌آزمایی‌شده
 * از API گیت‌هاب. در دسترس‌بودنشان همیشه تغییر می‌کند؛ فهرست در صفحهٔ پراکسی قابل
 * ویرایش است.
 */
export const DEFAULT_SOURCES = [
	// همهٔ پروتکل‌ها، هر ۱۵ دقیقه (کارفرما: ebrasha)
	'https://raw.githubusercontent.com/ebrasha/free-v2ray-public-list/main/V2Ray-Config-By-EbraSha-All-Type.txt',
	// کلاسیک‌ترین مخزن، کانفیگ‌های تست‌شده (کارفرما: barry-far)
	'https://raw.githubusercontent.com/barry-far/V2ray-Configs/main/All_Configs_Sub.txt',
	// جمع‌آوری از صدها منبع، به‌تفکیک پروتکل (کارفرما: MohammadBahemmat)
	'https://raw.githubusercontent.com/MohammadBahemmat/V2ray-Collector/main/all_servers.txt',
	// پرسرعت و بدون تبلیغ، هر ۱۰ دقیقه (کارفرما: FreeFolksOn)
	'https://raw.githubusercontent.com/FreeFolksOn/abc-configs-free-vpn-proxy-list/main/All_Configs_Sub.txt',
	// تست‌پینگ‌شدهٔ دوساعته (کارفرما: MahanKenway)
	'https://raw.githubusercontent.com/MahanKenway/Freedom-V2Ray/main/configs/mix.txt',
	// گردآوری از وب با هستهٔ Xray تست‌شده (کارفرما: Delta-Kronecker)
	'https://raw.githubusercontent.com/Delta-Kronecker/V2ray-Config/main/config/all_configs.txt',
];

/** @type {{ pool: any[], sources: string[], running: boolean, proc: any, currentIdx: number, startedAt: string, lastCheck: any, exitIp: string, checks: number }} */
const S = { pool: [], sources: [ ...DEFAULT_SOURCES ], running: false, proc: null, currentIdx: -1, startedAt: '', lastCheck: null, exitIp: '', checks: 0, testing: null };

export function loadState() {
	try {
		const saved = JSON.parse( fs.readFileSync( POOL_FILE(), 'utf8' ) );
		S.pool = saved.pool || [];
		S.sources = saved.sources?.length ? saved.sources : [ ...DEFAULT_SOURCES ];
	} catch {
		/* نصب تازه */
	}
	return S;
}
function saveState() {
	fs.mkdirSync( path.dirname( POOL_FILE() ), { recursive: true } );
	fs.writeFileSync( POOL_FILE(), JSON.stringify( { pool: S.pool.slice( 0, 60 ), sources: S.sources }, null, 2 ) );
}

export function status() {
	return {
		corePresent: corePresent(),
		running: S.running,
		ports: { socks: SOCKS_PORT, http: HTTP_PORT },
		current: S.currentIdx >= 0 && S.pool[ S.currentIdx ] ? { name: S.pool[ S.currentIdx ].name, proto: S.pool[ S.currentIdx ].proto, ms: S.pool[ S.currentIdx ].ms } : null,
		startedAt: S.startedAt || null,
		lastCheck: S.lastCheck,
		exitIp: S.exitIp,
		poolSize: S.pool.length,
		working: S.pool.filter( ( c ) => c.ok ).length,
		internetOnly: S.pool.filter( ( c ) => c.ok1 && ! c.serviceOk ).length,
		pool: S.pool,
		sources: S.sources,
		defaults: DEFAULT_SOURCES,
		// آزمونِ در جریان — تا بستن و بازکردن پنجره، نوار پیشرفت را گم نکند.
		testing: S.testing ? { ...S.testing } : null,
	};
}

/** جمع‌آوری از همهٔ منابع — نتیجه: کاندیدهای تازه (بدون تست). */
export async function harvest() {
	const found = [];
	const seen = new Set( S.pool.map( ( c ) => `${ c.proto }|${ c.host }:${ c.port }` ) );
	for ( const url of S.sources ) {
		try {
			const text = await ( await fetch( url, { headers: { 'user-agent': 'vira-tunnel' }, signal: AbortSignal.timeout( 25_000 ) } ) ).text();
			const parsed = parseAll( text );
			for ( const p of parsed.slice( 0, 400 ) ) {
				const key = `${ p.proto }|${ p.host }:${ p.port }`;
				if ( seen.has( key ) ) { continue; }
				seen.add( key );
				found.push( { id: `t${ Date.now().toString( 36 ) }${ found.length }`, proto: p.proto, name: p.name, host: p.host, port: p.port, outbound: p.outbound, ok: false, ms: 0, lastCheck: null, enabled: true, pinned: false, source: url.slice( 0, 60 ) } );
			}
			logInfo( 'tunnel', 'منبع خوانده شد.', { url: url.slice( 0, 60 ), found: parsed.length } );
		} catch ( e ) {
			logWarn( 'tunnel', 'خواندن منبع نشد.', { url: url.slice( 0, 60 ), error: String( e?.message || e ).slice( 0, 120 ) } );
		}
	}
	S.pool = [ ...S.pool, ...found ].slice( 0, 400 );
	saveState();
	logInfo( 'tunnel', 'جمع‌آوری تمام شد.', { newFound: found.length, total: S.pool.length } );
	return { ok: true, added: found.length, total: S.pool.length };
}

/** پورت آزاد تصادفی برای آزمونگر. */
function freePort() {
	return new Promise( ( res, rej ) => {
		const srv = net.createServer();
		srv.unref();
		srv.on( 'error', rej );
		srv.listen( 0, '127.0.0.1', () => { const p = srv.address().port; srv.close( () => res( p ) ); } );
	} );
}

/**
 * صبر تا وقتی درگاه محلی واقعاً جواب بدهد.
 *
 * جایگزین `setTimeout(350)`ِ قبلی. روی ویندوز، xray برای خواندن کانفیگ و آماده‌کردن
 * inbound معمولاً ۱ تا ۳ ثانیه می‌خواهد — مخصوصاً بار اول که Defender فایل را اسکن
 * می‌کند. با صبر ثابتِ کوتاه، `fetch` زودتر می‌رسید، `ECONNREFUSED` می‌گرفت و کانفیگِ
 * **سالم** «خراب» علامت می‌خورد. (DESIGN-HUB-UI-FIX §۲.۷ باگ ۱)
 *
 * @param {number} port
 * @param {number} timeoutMs
 */
function waitForPort( port, timeoutMs ) {
	const deadline = Date.now() + timeoutMs;
	return new Promise( ( resolve ) => {
		const attempt = () => {
			if ( Date.now() > deadline ) {
				resolve( false );
				return;
			}
			const sock = net.connect( { host: '127.0.0.1', port } );
			sock.setTimeout( 400 );
			const retry = () => {
				sock.destroy();
				setTimeout( attempt, 120 );
			};
			sock.on( 'connect', () => { sock.destroy(); resolve( true ); } );
			sock.on( 'timeout', retry );
			sock.on( 'error', retry );
		};
		attempt();
	} );
}

/**
 * یک تماس از راه درگاه SOCKS محلی.
 *
 * @param {number} port
 * @param {string} url
 * @param {number} ttlMs
 * @returns {Promise<{ok:boolean, ms:number, status?:number, error?:string}>}
 */
async function probeThrough( port, url, ttlMs ) {
	const t0 = Date.now();
	try {
		const { socksDispatcher } = await import( 'fetch-socks' );
		/*
		 * ⚠️ `undiciFetch`، نه `fetch` سراسری.
		 *
		 * داسپچرِ بستهٔ npm (undici 7) با fetch داخلی Node ناسازگار است و خطای
		 * «invalid onRequestStart method» می‌دهد. `net.js` این را از ۰.۹.۵ می‌دانست،
		 * ولی موتور تونل همچنان `fetch` سراسری را صدا می‌زد — یعنی **هر تست کانفیگ
		 * صرف‌نظر از سالم‌بودن سرور شکست می‌خورد** و کاربر «هیچ کانفیگ سالمی نیست»
		 * می‌دید، حتی برای کانفیگ‌هایی که در Hiddify کار می‌کردند.
		 */
		const { fetch: undiciFetch } = await import( 'undici' );
		/*
		 * و شکل آرگومان: `{ type, host, port }` — نه `{ url }`.
		 * با `{ url }` کتابخانه «Invalid SOCKS proxy details were provided» می‌دهد.
		 * این دو باگ روی هم، تست تونل را صددرصد شکست‌خورده می‌کردند.
		 */
		const res = await undiciFetch( url, {
			dispatcher: socksDispatcher( { type: 5, host: '127.0.0.1', port } ),
			signal: AbortSignal.timeout( ttlMs ),
		} );
		return { ok: res.ok || res.status === 204, ms: Date.now() - t0, status: res.status };
	} catch ( e ) {
		const msg = String( e?.cause?.message || e?.message || e ).slice( 0, 80 );
		return { ok: false, ms: Date.now() - t0, error: msg };
	}
}

/**
 * یک کانفیگ را بالا می‌آورد و آزمون‌های خواسته‌شده را از راهش می‌زند.
 *
 * دو مرحله (خواستهٔ کارفرما ۱۴۰۵/۰۵/۳۰):
 *   ۱) غربال سریع: آیا تونل اصلاً به اینترنت می‌رسد؟
 *   ۲) آزمون سرویس: آیا از این تونل، **پرووایدر واقعی** جواب می‌دهد؟
 *
 * مرحلهٔ ۲ فقط وقتی اجرا می‌شود که `serviceUrl` داده شده باشد — و فراخواننده آن را
 * فقط برای بازماندگان مرحلهٔ ۱ می‌دهد، تا کانفیگ مرده بی‌خود سرویس را صدا نزند.
 *
 * @param {any} outbound
 * @param {number} port
 * @param {{ttlMs?:number, serviceUrl?:string, serviceTtlMs?:number}} [opts]
 */
function runOnce( outbound, port, opts = {} ) {
	const ttlMs = opts.ttlMs || 6000;
	const serviceUrl = opts.serviceUrl || '';
	const serviceTtl = opts.serviceTtlMs || 12000;

	return new Promise( ( resolve ) => {
		const cfg = {
			inbounds: [ { listen: '127.0.0.1', port, protocol: 'socks', settings: { udp: false } } ],
			outbounds: [ outbound, { protocol: 'freedom', tag: 'direct' } ],
		};
		const tmp = path.join( String( HOME ), 'tunnel', `probe-${ port }.json` );
		fs.mkdirSync( path.dirname( tmp ), { recursive: true } );
		fs.writeFileSync( tmp, JSON.stringify( cfg ) );

		let proc;
		try {
			proc = spawn( coreBin(), [ 'run', '-c', tmp ], { stdio: 'ignore' } );
		} catch ( e ) {
			fs.rmSync( tmp, { force: true } );
			resolve( { ok: false, ms: 0, error: String( e?.message || e ).slice( 0, 80 ) } );
			return;
		}

		let settled = false;
		const done = ( result ) => {
			if ( settled ) { return; }
			settled = true;
			try { proc.kill(); } catch {}
			fs.rmSync( tmp, { force: true } );
			resolve( result );
		};

		proc.on( 'error', ( e ) => done( { ok: false, ms: 0, error: String( e.message ).slice( 0, 80 ) } ) );

		( async () => {
			// مرحلهٔ صفر: تا درگاه بالا نیامده، هیچ تماسی معنا ندارد.
			if ( ! ( await waitForPort( port, 5000 ) ) ) {
				done( { ok: false, ms: 0, error: 'هسته بالا نیامد' } );
				return;
			}

			// مرحلهٔ ۱ — غربال سریع، با چند مقصد جایگزین.
			let stage1 = null;
			for ( const url of PROBE_URLS ) {
				stage1 = await probeThrough( port, url, ttlMs );
				if ( stage1.ok ) { break; }
			}
			if ( ! stage1?.ok ) {
				done( { ok: false, stage: 1, ms: 0, error: stage1?.error || 'اینترنت رد شد' } );
				return;
			}

			if ( ! serviceUrl ) {
				// بدون سرویس معیار: فقط اینترنت تأیید شد.
				done( { ok: true, stage: 1, ms: stage1.ms, serviceOk: null } );
				return;
			}

			// مرحلهٔ ۲ — آیا پرووایدر واقعی از این تونل جواب می‌دهد؟
			const stage2 = await probeThrough( port, serviceUrl, serviceTtl );

			/*
			 * ظرافت مهم: ۴۰۱/۴۰۳ـِ خودِ سرویس یعنی **تونل سالم است** و فقط کلید
			 * اشکال دارد — به آنجا رسیده‌ایم. بدون این تفکیک، یک کلید غلط باعث
			 * می‌شد همهٔ کانفیگ‌های خوب دور ریخته شوند.
			 */
			const reached = stage2.ok || stage2.status === 401 || stage2.status === 403;
			done( {
				ok: true,
				stage: reached ? 2 : 1,
				ms: stage1.ms,
				serviceMs: stage2.ms,
				serviceOk: reached,
				error: reached ? '' : ( stage2.error || `سرویس پاسخ نداد (${ stage2.status || '—' })` ),
			} );
		} )();
	} );
}

/** درخواست لغو آزمون در حال اجرا (دکمهٔ «لغو» در رابط). */
export function cancelTest() {
	if ( ! S.testing ) {
		return { ok: false, error: 'آزمونی در جریان نیست.' };
	}
	S.testing.cancelled = true;
	logInfo( 'tunnel', 'لغو آزمون درخواست شد.' );
	return { ok: true };
}

/**
 * تست همهٔ کاندیدهای فعال — دومرحله‌ای، با هم‌زمانی محدود.
 *
 * مرحلهٔ ۱ روی همه؛ مرحلهٔ ۲ (سرویس واقعی) **فقط روی بازماندگان**. خواستهٔ کارفرما:
 * «بعد از تست اولیه، کانفیگ‌های غیرفعال را بی‌خود تست نگیرد.»
 *
 * @param {(p:any)=>void} [onProgress]
 * @param {{serviceUrl?:string, serviceLabel?:string}} [opts]
 */
export async function testAll( onProgress = () => {}, opts = {} ) {
	if ( ! corePresent() ) {
		return { ok: false, error: 'هستهٔ xray نصب نیست — اول «دانلود هسته».' };
	}
	if ( S.testing && ! S.testing.cancelled ) {
		return { ok: false, error: 'یک آزمون همین حالا در جریان است.' };
	}

	const cands = S.pool.filter( ( c ) => c.enabled );
	const serviceUrl = String( opts.serviceUrl || '' );

	// وضعیت پایدار: اگر کاربر پنجره را ببندد و برگردد، نوار پیشرفت گم نمی‌شود.
	S.testing = {
		phase: 1,
		done: 0,
		total: cands.length,
		healthy: 0,
		internetOnly: 0,
		broken: 0,
		name: '',
		service: String( opts.serviceLabel || '' ),
		cancelled: false,
		startedAt: new Date().toISOString(),
	};

	const emit = () => onProgress( { ...S.testing } );
	emit();

	/** یک صف با هم‌زمانی مشخص. */
	const drain = async ( queue, conc, work ) => {
		const worker = async () => {
			while ( queue.length && ! S.testing.cancelled ) {
				await work( queue.shift() );
			}
		};
		await Promise.all( Array.from( { length: Math.min( conc, Math.max( 1, queue.length ) ) }, worker ) );
	};

	// ── مرحلهٔ ۱: غربال سریع روی همه ─────────────────────────────────────────
	await drain( [ ...cands ], 8, async ( c ) => {
		const port = await freePort();
		const r = await runOnce( c.outbound, port, { ttlMs: 6000 } );
		c.ok1 = r.ok;
		c.ms = r.ok ? r.ms : 0;
		c.error = r.error || '';
		c.serviceOk = null;
		c.lastCheck = new Date().toISOString();
		S.testing.done += 1;
		S.testing.name = c.name;
		if ( ! r.ok ) { S.testing.broken += 1; }
		emit();
	} );

	// ── مرحلهٔ ۲: فقط بازماندگان، و فقط اگر سرویس معیار داریم ────────────────
	const survivors = cands.filter( ( c ) => c.ok1 );
	if ( serviceUrl && survivors.length && ! S.testing.cancelled ) {
		S.testing.phase = 2;
		S.testing.done = 0;
		S.testing.total = survivors.length;
		emit();

		await drain( [ ...survivors ], 3, async ( c ) => {
			const port = await freePort();
			const r = await runOnce( c.outbound, port, { ttlMs: 6000, serviceUrl, serviceTtlMs: 12000 } );
			c.serviceOk = Boolean( r.serviceOk );
			c.serviceMs = r.serviceMs || 0;
			if ( ! c.serviceOk ) { c.error = r.error || 'سرویس از این مسیر جواب نداد'; }
			c.lastCheck = new Date().toISOString();
			S.testing.done += 1;
			S.testing.name = c.name;
			if ( c.serviceOk ) { S.testing.healthy += 1; } else { S.testing.internetOnly += 1; }
			emit();
		} );
	} else if ( ! serviceUrl ) {
		// بدون سرویس معیار، بازمانده‌ها «فقط اینترنت» می‌مانند.
		S.testing.internetOnly = survivors.length;
	}

	/*
	 * `ok` نهایی = به سرویس واقعی رسیدیم. اگر سرویس معیاری نبود، همان مرحلهٔ ۱ ملاک
	 * است تا کاربرِ بدون اتصال بن‌بست نخورد.
	 */
	for ( const c of cands ) {
		c.ok = serviceUrl ? Boolean( c.serviceOk ) : Boolean( c.ok1 );
	}

	// ✅ سالم (سرویس‌دیده) → 🟡 فقط اینترنت → ❌ خراب
	const rank = ( c ) => ( c.serviceOk ? 2 : c.ok1 ? 1 : 0 );
	S.pool.sort( ( a, b ) =>
		( Number( b.pinned ) - Number( a.pinned ) ) ||
		( rank( b ) - rank( a ) ) ||
		( ( a.ms || 1e9 ) - ( b.ms || 1e9 ) )
	);
	S.pool = S.pool.slice( 0, 60 );

	const cancelled = S.testing.cancelled;
	const summary = {
		ok: true,
		cancelled,
		working: S.pool.filter( ( c ) => c.ok ).length,
		internetOnly: S.pool.filter( ( c ) => c.ok1 && ! c.serviceOk ).length,
		total: S.pool.length,
	};
	S.testing = null;
	saveState();
	logInfo( 'tunnel', cancelled ? 'آزمون لغو شد.' : 'آزمون همه تمام شد.', summary );
	return summary;
}

function serviceConfig( outbound ) {
	return {
		inbounds: [
			{ listen: '127.0.0.1', port: SOCKS_PORT, protocol: 'socks', settings: { udp: false } },
			{ listen: '127.0.0.1', port: HTTP_PORT, protocol: 'http' },
		],
		outbounds: [ outbound, { protocol: 'freedom', tag: 'direct' } ],
	};
}

/**
 * بهترین کانفیگ از `from` به بعد.
 *
 * اول ✅ سالم‌ها (به سرویس واقعی رسیده‌اند)، و فقط اگر هیچ‌کدام نبود 🟡 «فقط اینترنت».
 * کانفیگی که سرویس از راهش جواب می‌دهد، بر کانفیگی که صرفاً اینترنت دارد مقدم است.
 */
function pickBest( from = 0 ) {
	const usable = ( c ) => c.enabled !== false;
	for ( let i = from; i < S.pool.length; i += 1 ) {
		if ( usable( S.pool[ i ] ) && S.pool[ i ].serviceOk ) { return i; }
	}
	for ( let i = from; i < S.pool.length; i += 1 ) {
		if ( usable( S.pool[ i ] ) && ( S.pool[ i ].ok || S.pool[ i ].ok1 ) ) { return i; }
	}
	return -1;
}

async function spawnService() {
	const idx = pickBest( 0 );
	if ( idx === -1 ) {
		return { ok: false, error: 'هیچ کانفیگ سالمی در انبار نیست — اول «به‌روزرسانی منابع» و «تست همه».' };
	}
	S.currentIdx = idx;
	const cfgPath = path.join( String( HOME ), 'tunnel', 'service.json' );
	fs.mkdirSync( path.dirname( cfgPath ), { recursive: true } );
	fs.writeFileSync( cfgPath, JSON.stringify( serviceConfig( S.pool[ idx ].outbound ) ) );
	S.proc = spawn( coreBin(), [ 'run', '-c', cfgPath ], { stdio: 'ignore' } );
	S.proc.on( 'exit', ( code ) => {
		logWarn( 'tunnel', 'پروسهٔ سرویس بسته شد.', { code } );
		S.running = false;
	} );
	S.running = true;
	S.startedAt = new Date().toISOString();
	await new Promise( ( r ) => setTimeout( r, 600 ) );
	logInfo( 'tunnel', 'تونل روشن شد.', { config: S.pool[ idx ].name, socks: SOCKS_PORT, http: HTTP_PORT } );
	return { ok: true, current: S.pool[ idx ].name };
}

export async function start() {
	if ( ! corePresent() ) {
		return { ok: false, error: 'هستهٔ xray نصب نیست — اول «دانلود هسته».' };
	}
	if ( S.running ) {
		return { ok: true, already: true };
	}
	loadState();
	return spawnService();
}

export async function stop() {
	try { S.proc?.kill(); } catch {}
	S.proc = null; S.running = false; S.currentIdx = -1; S.exitIp = '';
	logInfo( 'tunnel', 'تونل خاموش شد.' );
	return { ok: true };
}

/** چرخش به کانفیگ بعدی — دستی یا خودکار بعد از شکست سلامت. */
export async function rotate() {
	if ( ! S.running ) {
		return { ok: false, error: 'تونل روشن نیست.' };
	}
	const next = pickBest( S.currentIdx + 1 ) !== -1 ? pickBest( S.currentIdx + 1 ) : pickBest( 0 );
	if ( next === -1 || next === S.currentIdx ) {
		return { ok: false, error: 'کانفیگ جایگزین سالمی نیست — دوباره تست همه.' };
	}
	await stop();
	S.running = false;
	const out = await spawnService();
	logInfo( 'tunnel', 'چرخش انجام شد.', { to: S.pool[ next ]?.name } );
	return out;
}

/** بررسی سلامت — از server در هر دقیقه صدا زده می‌شود. */
export async function healthCheck() {
	if ( ! S.running ) { return; }
	S.checks += 1;
	try {
		const { socksDispatcher } = await import( 'fetch-socks' );
		// همان دلیل probeThrough: fetch سراسری داسپچر بسته را نمی‌پذیرد.
		const { fetch: undiciFetch } = await import( 'undici' );
		const t0 = Date.now();
		// آی‌پی خروجی را از ipify می‌گیریم (بدنه دارد)؛ سلامت خودش با همین یک تماس.
		const res = await undiciFetch( 'https://api.ipify.org?format=json', {
			dispatcher: socksDispatcher( { type: 5, host: '127.0.0.1', port: SOCKS_PORT } ),
			signal: AbortSignal.timeout( 9000 ),
		} );
		const body = await res.json().catch( () => ( {} ) );
		S.lastCheck = { at: new Date().toISOString(), ok: res.ok, ms: Date.now() - t0 };
		S.exitIp = body.ip || S.exitIp;
	} catch ( e ) {
		S.lastCheck = { at: new Date().toISOString(), ok: false, error: String( e?.message || e ).slice( 0, 80 ) };
		logWarn( 'tunnel', 'بررسی سلامت شکست خورد — چرخش.', S.lastCheck );
		await rotate();
	}
}

export function setSources( urls ) {
	S.sources = ( urls || [] ).map( String ).filter( ( u ) => /^https?:\/\//.test( u ) ).slice( 0, 20 );
	saveState();
	return { ok: true, count: S.sources.length };
}

export function toggleConfig( id, patch ) {
	const c = S.pool.find( ( x ) => x.id === id );
	if ( ! c ) { return { ok: false, error: 'کانفیگ پیدا نشد.' }; }
	if ( 'enabled' in patch ) { c.enabled = Boolean( patch.enabled ); }
	if ( 'pinned' in patch ) { c.pinned = Boolean( patch.pinned ); }
	saveState();
	return { ok: true };
}
