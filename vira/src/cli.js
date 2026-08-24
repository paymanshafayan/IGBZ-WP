#!/usr/bin/env node
/**
 * ورودی خط فرمان ویرا.
 *
 * دو حالت:
 *
 *   vira                       رابط کاربری (سرور محلی + باز کردن پنجره)
 *   vira -p "کاری که می‌خواهی"   بدون رابط: اجرا کن، جواب را چاپ کن، برو
 *
 * حالت دوم برای اسکریپت و CI است و همان چیزی است که در Claude Code به آن headless
 * می‌گویند. عمداً محتاط است: هر ابزاری که تأیید بخواهد **رد می‌شود**، مگر حالت auto
 * بدهی یا با --allow قاعده بگذاری. یک اسکریپت خودکار نباید بی‌سروصدا اجازهٔ کار خطرناک
 * بگیرد.
 */

import { spawn } from 'node:child_process';
import path from 'node:path';
import { startServer } from './server.js';
import { VERSION, ROOT, installInfo, buildLine } from './version.js';

const args = process.argv.slice( 2 );

function flag( name, fallback = undefined ) {
	const i = args.indexOf( `--${ name }` );
	if ( i === -1 ) {
		return fallback;
	}
	const next = args[ i + 1 ];
	return next && ! next.startsWith( '--' ) ? next : true;
}

/** پارامتری که می‌تواند چند بار بیاید یا با کاما جدا شود. */
function list( name ) {
	/** @type {string[]} */
	const out = [];
	args.forEach( ( a, i ) => {
		if ( a === `--${ name }` && args[ i + 1 ] && ! args[ i + 1 ].startsWith( '--' ) ) {
			out.push( ...String( args[ i + 1 ] ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) );
		}
	} );
	return out;
}

const HELP = `
ویرا — دستیار عامل بومی

  رابط کاربری:
    vira                       اجرا با تنظیمات ذخیره‌شده
    vira --dir <path>          تعیین پوشهٔ کاری
    vira --port <n>            پورت (پیش‌فرض 7788)
    vira --host <h>            میزبان (پیش‌فرض 127.0.0.1)
    vira --no-open             پنجره/مرورگر باز نشود

  بدون رابط (headless):
    vira -p "<پرامپت>"          اجرا کن و جواب را چاپ کن
    vira -p - < file.txt       پرامپت را از ورودی استاندارد بخوان
    --output-format text|json|stream-json
    --mode plan|default|auto     حالت مجوز (پیش‌فرض default)
    --allowed-tools a,b          فقط این ابزارها در دسترس مدل باشند
    --allow "bash:git status"    قاعدهٔ مجوز اضافه (چند بار قابل تکرار)
    --deny "bash:rm"             قاعدهٔ ممنوع
    --max-turns <n>              سقف گام (پیش‌فرض ۲۴)
    --model <name>               مدل، فقط برای همین اجرا

  عمومی:
    vira --version
    vira --help
`;

if ( args.includes( '--help' ) || args.includes( '-h' ) ) {
	console.log( HELP );
	process.exit( 0 );
}

if ( args.includes( '--version' ) || args.includes( '-v' ) ) {
	console.log( buildLine() );
	console.log( `اجرا از: ${ ROOT }` );
	if ( installInfo().frozen ) {
		console.log( installInfo().hint );
	}
	process.exit( 0 );
}

// ─────────────────────────────────────────────────────────── حالت headless

const printIndex = args.findIndex( ( a ) => a === '-p' || a === '--print' );
if ( printIndex > -1 ) {
	const raw = args[ printIndex + 1 ];
	const prompt = raw === '-' || raw === undefined || raw.startsWith( '--' ) ? await readStdin() : raw;

	if ( ! String( prompt ).trim() ) {
		console.error( 'پرامپت خالی است.' );
		process.exit( 2 );
	}

	const format = String( flag( 'output-format', 'text' ) );
	const { createVira, lastAssistantText } = await import( './index.js' );

	const started = Date.now();
	/** @type {any[]} */
	const events = [];

	const h = await createVira( {
		workspace: typeof flag( 'dir' ) === 'string' ? String( flag( 'dir' ) ) : undefined,
		mode: /** @type {any} */ ( flag( 'mode', 'default' ) ),
		allowedTools: list( 'allowed-tools' ),
		allow: list( 'allow' ),
		deny: list( 'deny' ),
		maxTurns: Number( flag( 'max-turns', 0 ) ) || undefined,
		model: typeof flag( 'model' ) === 'string' ? String( flag( 'model' ) ) : undefined,
		onEvent: ( ev ) => {
			events.push( ev );
			if ( format === 'stream-json' ) {
				process.stdout.write( `${ JSON.stringify( ev ) }\n` );
			} else if ( format === 'text' && ev.type === 'text' ) {
				process.stdout.write( ev.text );
			}
		},
	} );

	h.onPermission( () => false );
	h.onQuestion( () => null );

	if ( ! h.ready.ok ) {
		console.error( `تنظیمات ناقص است: ${ h.ready.missing.join( '، ' ) }` );
		console.error( 'یک بار `vira` را باز کن و پرووایدر را تنظیم کن.' );
		process.exit( 3 );
	}

	let failed = false;
	try {
		await h.send( String( prompt ) );
	} catch ( e ) {
		console.error( e?.message || String( e ) );
		failed = true;
	}

	const text = lastAssistantText( h.messages );
	const errored = failed || events.some( ( e ) => e.type === 'error' );

	if ( format === 'json' ) {
		process.stdout.write(
			`${ JSON.stringify(
				{
					ok: ! errored,
					text,
					usage: h.usage,
					durationMs: Date.now() - started,
					tools: events.filter( ( e ) => e.type === 'tool_start' ).map( ( e ) => e.name ),
					denied: events.filter( ( e ) => e.type === 'tool_denied' ).map( ( e ) => e.summary ),
					errors: events.filter( ( e ) => e.type === 'error' ).map( ( e ) => e.error ),
				},
				null,
				2
			) }\n`
		);
	} else if ( format === 'text' ) {
		process.stdout.write( '\n' );
	}

	await h.close();
	process.exit( errored ? 1 : 0 );
}

// ───────────────────────────────────────────────────────────── حالت رابط

const port = Number( flag( 'port', 7788 ) );
const host = String( flag( 'host', '127.0.0.1' ) );
const dir = flag( 'dir' );
const noOpen = args.includes( '--no-open' );

const { config } = await startServer( {
	port,
	host,
	workspace: typeof dir === 'string' ? dir : undefined,
} );

const shown = host === '0.0.0.0' ? '127.0.0.1' : host;
const url = `http://${ shown }:${ port }`;

console.log( '' );
console.log( `  ویرا ${ buildLine() }` );
console.log( `  آدرس:       ${ url }` );
console.log( `  پوشهٔ کاری:  ${ config.workspace }` );
// مسیر واقعیِ کدی که اجرا می‌شود. اگر کسی یک بار `npm install -g .` زده باشد، دستور
// `vira` یک **کپیِ منجمد** را اجرا می‌کند نه مخزن را — و بدون این خط، هیچ راهی نیست که
// بفهمد چرا تغییراتش را نمی‌بیند.
console.log( `  اجرا از:     ${ ROOT }` );

// یک خط ساکت کافی نبود. اگر این یک کپیِ منجمد است، باید داد بزند — چون علامتش دقیقاً
// همان چیزی است که کاربر گزارش می‌کند: «نسخهٔ قدیمی بالا می‌آید».
if ( installInfo().frozen ) {
	console.log( '' );
	console.log( '  ⚠  این یک کپیِ نصب‌شده است، نه کد مخزن.' );
	console.log( '     هر تغییری که در مخزن بدهی، اینجا دیده نمی‌شود.' );
	console.log( '     درستش:  npm rm -g vira   سپس در پوشهٔ مخزن:  npm link' );
}
console.log( '' );

if ( ! noOpen ) {
	openBrowser( url );
}

/** @param {string} target */
function openBrowser( target ) {
	const cmd =
		process.platform === 'darwin' ? 'open' : process.platform === 'win32' ? 'start' : 'xdg-open';
	try {
		const child = spawn( cmd, [ target ], {
			shell: process.platform === 'win32',
			stdio: 'ignore',
			detached: true,
		} );
		child.on( 'error', () => {} );
		child.unref();
	} catch {
		// روی سرور بدون محیط گرافیکی طبیعی است؛ آدرس بالا چاپ شده.
	}
}

function readStdin() {
	return new Promise( ( resolve ) => {
		if ( process.stdin.isTTY ) {
			resolve( '' );
			return;
		}
		let data = '';
		process.stdin.setEncoding( 'utf8' );
		process.stdin.on( 'data', ( c ) => ( data += c ) );
		process.stdin.on( 'end', () => resolve( data.trim() ) );
	} );
}
