/**
 * سندباکس اجرای فرمان — گزینهٔ «ج» که کارفرما انتخاب کرد: کانتینر.
 *
 * چه چیزی داخل سندباکس می‌رود و چه چیزی نه:
 *
 *   • `bash` و شل‌های پس‌زمینه → **داخل کانتینر**. اینجا جایی است که کد دلخواه اجرا می‌شود.
 *   • خواندن/نوشتن/ویرایش فایل → روی میزبان می‌مانند. این‌ها همین حالا هم به «پوشهٔ کاری»
 *     محدودند و بردنشان به کانتینر فقط کندی می‌آورد بدون اینکه چیزی امن‌تر شود.
 *
 * پوشهٔ کاری روی `/work` سوار می‌شود و همان‌جا هم پوشهٔ جاری است، پس مسیرهای نسبی مثل قبل
 * کار می‌کنند. شبکه به‌طور پیش‌فرض **بسته** است.
 *
 * سیاست شکست، عمداً «بسته» است: اگر سندباکس روشن باشد ولی داکر پیدا نشود، فرمان **اجرا
 * نمی‌شود**. یک قابلیت امنیتی که بی‌سروصدا به حالت ناامن برگردد، بدتر از نداشتنش است —
 * چون کاربر فکر می‌کند محافظت دارد. اگر کسی برگشت به میزبان را بخواهد، باید صریح
 * `allowHostFallback` را روشن کند.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';

/** @type {{enabled:boolean, runtime:'auto'|'docker'|'podman', image:string, network:'none'|'bridge'|'host', memory:string, cpus:string, readOnlyRoot:boolean, mounts:string[], allowHostFallback:boolean, user:boolean}} */
export const DEFAULT_SANDBOX = {
	enabled: false,
	runtime: 'auto',
	image: 'node:22-bookworm-slim',
	network: 'none',
	memory: '2g',
	cpus: '2',
	readOnlyRoot: false,
	mounts: [],
	allowHostFallback: false,
	user: true,
};

/** @param {any} cfg تنظیمات کامل برنامه */
export function resolveSandbox( cfg ) {
	return { ...DEFAULT_SANDBOX, ...( cfg?.sandbox || {} ) };
}

/**
 * پیداکردن فایل اجرایی در PATH — بدون `which`، تا روی ویندوز هم کار کند.
 *
 * @param {string} name
 * @param {NodeJS.ProcessEnv} [env]
 */
export async function findExecutable( name, env = process.env ) {
	const isWin = process.platform === 'win32';
	const exts = isWin ? ( env.PATHEXT || '.EXE;.CMD;.BAT' ).split( ';' ) : [ '' ];
	const dirs = ( env.PATH || '' ).split( isWin ? ';' : ':' ).filter( Boolean );

	for ( const dir of dirs ) {
		for ( const ext of exts ) {
			const candidate = path.join( dir, name + ext.toLowerCase() );
			const alt = path.join( dir, name + ext );
			for ( const file of new Set( [ candidate, alt ] ) ) {
				const ok = await fs
					.access( file, fs.constants.X_OK )
					.then( () => true )
					.catch( () => false );
				if ( ok ) {
					return file;
				}
			}
		}
	}
	return null;
}

/**
 * کدام موتور کانتینر در دسترس است.
 *
 * @param {'auto'|'docker'|'podman'} preferred
 * @param {NodeJS.ProcessEnv} [env]
 * @returns {Promise<{name:'docker'|'podman', path:string}|null>}
 */
export async function detectRuntime( preferred = 'auto', env = process.env ) {
	const order = preferred === 'auto' ? [ 'docker', 'podman' ] : [ preferred ];
	for ( const name of order ) {
		const file = await findExecutable( name, env );
		if ( file ) {
			return { name: /** @type {'docker'|'podman'} */ ( name ), path: file };
		}
	}
	return null;
}

/**
 * ساخت آرگومان‌های `docker run`.
 *
 * این تابع عمداً **خالص** است: هیچ چیزی را اجرا نمی‌کند و هیچ حالتی نگه نمی‌دارد. دلیلش
 * این است که مهم‌ترین بخش امنیتِ این ماژول، دقیقاً همین فهرست آرگومان‌هاست و باید بدون
 * داشتن داکر هم قابل‌آزمودن باشد.
 *
 * @param {{sandbox:any, workspace:string, command:string, interactive?:boolean, platform?:string, uid?:number, gid?:number}} opts
 */
export function buildRunArgs( { sandbox, workspace, command, interactive = false, platform = process.platform, uid, gid } ) {
	const s = { ...DEFAULT_SANDBOX, ...( sandbox || {} ) };

	/** @type {string[]} */
	const args = [ 'run', '--rm' ];

	if ( interactive ) {
		args.push( '-i' );
	}

	// شبکه: پیش‌فرض بسته.
	args.push( '--network', s.network === 'bridge' ? 'bridge' : s.network === 'host' ? 'host' : 'none' );

	// سقف منابع — جلوی fork bomb و پرکردن رم را می‌گیرد.
	if ( s.memory ) {
		args.push( '--memory', String( s.memory ) );
	}
	if ( s.cpus ) {
		args.push( '--cpus', String( s.cpus ) );
	}

	// بالابردن سطح دسترسی، ممنوع.
	args.push( '--security-opt', 'no-new-privileges', '--cap-drop', 'ALL' );

	if ( s.readOnlyRoot ) {
		args.push( '--read-only', '--tmpfs', '/tmp:rw,size=256m' );
	}

	// روی ویندوز، نگاشت شناسهٔ کاربر معنا ندارد و فقط خراب می‌کند.
	if ( s.user && platform !== 'win32' ) {
		const u = uid ?? ( typeof process.getuid === 'function' ? process.getuid() : 0 );
		const g = gid ?? ( typeof process.getgid === 'function' ? process.getgid() : 0 );
		args.push( '--user', `${ u }:${ g }` );
	}

	args.push( '-v', `${ workspace }:/work`, '-w', '/work' );

	for ( const m of s.mounts || [] ) {
		if ( String( m ).trim() ) {
			args.push( '-v', String( m ).trim() );
		}
	}

	args.push( s.image, 'sh', '-lc', command );
	return args;
}

/**
 * وضعیت سندباکس برای پنل تنظیمات و `/doctor`.
 * @param {any} cfg
 */
export async function sandboxStatus( cfg ) {
	const s = resolveSandbox( cfg );
	if ( ! s.enabled ) {
		return { ...s, available: false, runtimeName: null, message: 'سندباکس خاموش است؛ فرمان‌ها مستقیم روی سیستم اجرا می‌شوند.' };
	}
	const rt = await detectRuntime( s.runtime );
	return {
		...s,
		available: Boolean( rt ),
		runtimeName: rt?.name || null,
		runtimePath: rt?.path || null,
		message: rt
			? `${ rt.name } پیدا شد — فرمان‌ها داخل «${ s.image }» اجرا می‌شوند، شبکه: ${ s.network }.`
			: 'سندباکس روشن است ولی نه docker پیدا شد نه podman؛ فرمان‌ها اجرا نمی‌شوند.',
	};
}

/** خطای «سندباکس در دسترس نیست» با راهنمای رفع. */
export function unavailableError( sandbox ) {
	const err = new Error(
		'سندباکس روشن است ولی موتور کانتینر پیدا نشد (نه docker، نه podman).\n' +
			'یا Docker Desktop را نصب/اجرا کن، یا در تنظیمات → سندباکس خاموشش کن.\n' +
			'اگر عمداً می‌خواهی بدون سندباکس ادامه بدهی، گزینهٔ «برگشت به میزبان» را روشن کن.'
	);
	err.code = 'SANDBOX_UNAVAILABLE';
	err.sandbox = sandbox;
	return err;
}

/**
 * اجرای یک فرمان — داخل کانتینر اگر سندباکس روشن باشد، وگرنه روی میزبان.
 *
 * @param {{command:string, workspace:string, sandbox?:any, env?:NodeJS.ProcessEnv}} opts
 * @returns {Promise<{child:import('node:child_process').ChildProcess, mode:'host'|'container', runtime?:string}>}
 */
export async function spawnShell( { command, workspace, sandbox, env } ) {
	const s = resolveSandbox( { sandbox } );

	if ( ! s.enabled ) {
		return { child: spawn( command, { shell: true, cwd: workspace, env: env || process.env } ), mode: 'host' };
	}

	const rt = await detectRuntime( s.runtime );
	if ( ! rt ) {
		if ( ! s.allowHostFallback ) {
			throw unavailableError( s );
		}
		return { child: spawn( command, { shell: true, cwd: workspace, env: env || process.env } ), mode: 'host' };
	}

	const args = buildRunArgs( { sandbox: s, workspace, command, interactive: true } );
	return {
		child: spawn( rt.path, args, { env: env || process.env } ),
		mode: 'container',
		runtime: rt.name,
	};
}

/**
 * آزمودن واقعی سندباکس: یک فرمان بی‌ضرر داخل کانتینر اجرا کن و ببین چه برمی‌گردد.
 * @param {any} cfg
 * @param {string} workspace
 */
export async function testSandbox( cfg, workspace ) {
	const s = resolveSandbox( cfg );
	if ( ! s.enabled ) {
		return { ok: false, message: 'اول سندباکس را روشن کن.' };
	}

	const rt = await detectRuntime( s.runtime );
	if ( ! rt ) {
		return { ok: false, message: 'موتور کانتینر پیدا نشد (نه docker، نه podman).' };
	}

	const args = buildRunArgs( {
		sandbox: s,
		workspace,
		command: 'echo vira-sandbox-ok; id -u 2>/dev/null; pwd',
	} );

	return new Promise( ( resolve ) => {
		const child = spawn( rt.path, args, { env: process.env } );
		let out = '';
		let err = '';
		const timer = setTimeout( () => {
			child.kill( 'SIGKILL' );
			resolve( { ok: false, message: 'کانتینر در ۹۰ ثانیه بالا نیامد. شاید ایمیج در حال دانلود است؛ یک بار دستی `docker pull` بزن.' } );
		}, 90_000 );

		child.stdout?.on( 'data', ( d ) => ( out += d ) );
		child.stderr?.on( 'data', ( d ) => ( err += d ) );
		child.on( 'error', ( e ) => {
			clearTimeout( timer );
			resolve( { ok: false, message: `اجرای ${ rt.name } شکست خورد: ${ e?.message || e }` } );
		} );
		child.on( 'close', ( code ) => {
			clearTimeout( timer );
			if ( code === 0 && out.includes( 'vira-sandbox-ok' ) ) {
				resolve( {
					ok: true,
					message: `${ rt.name } کار می‌کند. ایمیج «${ s.image }»، شبکه ${ s.network }.\n${ out.trim() }`,
				} );
				return;
			}
			resolve( { ok: false, message: `کد خروج ${ code }\n${ ( err || out ).slice( 0, 600 ) }` } );
		} );
	} );
}
