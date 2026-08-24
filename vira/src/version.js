/**
 * نسخه و مسیر واقعیِ کدی که اجرا می‌شود.
 *
 * تا امروز نسخه در **سه جا** دستی نوشته شده بود: `cli.js`، `server.js` و README.
 * نتیجه‌اش قابل پیش‌بینی بود — یکی به‌روز می‌شد و بقیه جا می‌ماندند. حالا یک منبع
 * حقیقت هست و آن `package.json` است.
 *
 * و یک کار دوم که مهم‌تر است: تشخیص **کپیِ منجمد**.
 *
 * `npm install -g .` پوشه را کپی می‌کند. از آن لحظه دستور `vira` همان کپی را اجرا
 * می‌کند و هیچ `git pull` ای رویش اثر ندارد؛ کاربر ماه‌ها یک نسخهٔ قدیمی می‌بیند و
 * فکر می‌کند تغییراتش اعمال نشده. یک بار در همین پروژه اتفاق افتاد. پیام روی ترمینال
 * کافی نبود، چون کسی که با رابط کار می‌کند ترمینال را نمی‌بیند — پس این تشخیص از
 * `/api/state` هم بیرون می‌رود تا خودِ برنامه بتواند بگوید «من کد مخزن نیستم».
 *
 * و کار سوم، که از یک شکایت واقعی آمد: **مهر ساخت**.
 *
 * کارفرما نوشت «باز هم نسخهٔ قبلی را نشان می‌دهد (۰.۷.۰)». حق داشت — سه دور پشت سر هم
 * رابط عوض شد و عدد نسخه دست‌نخورده ماند. تنها نشانه‌ای که کاربر دارد تا بفهمد `git pull`
 * اثر کرده، همان عدد است؛ و اگر من یادم برود بالا ببرمش، او هیچ راهی ندارد.
 *
 * پس علاوه بر نسخه، شناسهٔ کامیت و تاریخش هم خوانده می‌شود. آن **خودکار** با هر کامیت
 * عوض می‌شود و به حافظهٔ من وابسته نیست.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** ریشهٔ پوشهٔ ویرا — همان جایی که `package.json` در آن است. */
export const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/** @returns {string} */
function readVersion() {
	try {
		const raw = fs.readFileSync( path.join( ROOT, 'package.json' ), 'utf8' );
		return String( JSON.parse( raw ).version || '' ) || 'نامعلوم';
	} catch {
		return 'نامعلوم';
	}
}

export const VERSION = readVersion();

/**
 * شناسهٔ کامیت و تاریخش، از خودِ مخزن.
 *
 * بدون اجرای `git`: فایل‌های `.git` مستقیم خوانده می‌شوند. دلیلش این است که اجرای یک
 * پروسهٔ جانبی در هر بالا آمدن، هم کند است و هم روی سرور ممکن است `git` اصلاً نصب
 * نباشد. اگر هر مرحله‌ای نشد، بی‌سروصدا خالی برمی‌گردد — مهر ساخت نباید هیچ‌وقت جلوی
 * بالا آمدن برنامه را بگیرد.
 *
 * @returns {{commit:string, date:string, branch:string}}
 */
function readBuild() {
	const empty = { commit: '', date: '', branch: '' };
	try {
		const gitDir = [ path.join( ROOT, '.git' ), path.join( ROOT, '..', '.git' ) ].find( ( d ) => fs.existsSync( d ) );
		if ( ! gitDir ) {
			return empty;
		}

		// در ورک‌تری‌ها، `.git` یک فایل است که به پوشهٔ واقعی اشاره می‌کند.
		let dir = gitDir;
		if ( fs.statSync( dir ).isFile() ) {
			const pointer = /gitdir:\s*(.+)/.exec( fs.readFileSync( dir, 'utf8' ) )?.[ 1 ]?.trim();
			if ( ! pointer ) {
				return empty;
			}
			dir = path.resolve( path.dirname( gitDir ), pointer );
		}

		const head = fs.readFileSync( path.join( dir, 'HEAD' ), 'utf8' ).trim();
		const ref = /^ref:\s*(.+)$/.exec( head )?.[ 1 ];
		const branch = ref ? ref.replace( /^refs\/heads\//, '' ) : '(جدا از شاخه)';

		let sha = ref ? '' : head;
		if ( ref ) {
			const loose = path.join( dir, ref );
			if ( fs.existsSync( loose ) ) {
				sha = fs.readFileSync( loose, 'utf8' ).trim();
			} else {
				// شاخه‌های فشرده‌شده در packed-refs می‌نشینند.
				const packed = path.join( dir, 'packed-refs' );
				if ( fs.existsSync( packed ) ) {
					const line = fs.readFileSync( packed, 'utf8' ).split( '\n' ).find( ( l ) => l.endsWith( ` ${ ref }` ) );
					sha = line ? line.split( ' ' )[ 0 ] : '';
				}
			}
		}
		if ( ! sha ) {
			return { ...empty, branch };
		}

		// تاریخ را از زمان تغییر همان ref می‌گیریم؛ دقیق‌تر از هیچ و بدون خواندن آبجکت.
		let date = '';
		try {
			const target = fs.existsSync( path.join( dir, ref || '' ) ) ? path.join( dir, ref ) : path.join( dir, 'HEAD' );
			date = new Date( fs.statSync( target ).mtime ).toISOString().slice( 0, 16 ).replace( 'T', ' ' );
		} catch {
			date = '';
		}

		return { commit: sha.slice( 0, 7 ), date, branch };
	} catch {
		return empty;
	}
}

export const BUILD = readBuild();

/** یک رشتهٔ کوتاه که هم نسخه را دارد هم ساخت — همان چیزی که باید نشان داد. */
export function buildLine() {
	return [ VERSION, BUILD.commit && `ساخت ${ BUILD.commit }`, BUILD.date ].filter( Boolean ).join( ' · ' );
}

/**
 * این کدی که اجرا می‌شود، از کجا آمده؟
 *
 * @returns {{root:string, version:string, frozen:boolean, git:boolean, hint:string}}
 */
export function installInfo() {
	// اگر مسیر از داخل node_modules رد می‌شود، این یک نصبِ کپی‌شده است نه یک چک‌اوت.
	const frozen = ROOT.split( path.sep ).includes( 'node_modules' );

	// یک چک‌اوت واقعی، بالادستش .git دارد (چون ویرا زیرپوشهٔ مخزن IGBZ-WP است).
	const git = fs.existsSync( path.join( ROOT, '.git' ) ) || fs.existsSync( path.join( ROOT, '..', '.git' ) );

	return {
		root: ROOT,
		version: VERSION,
		build: BUILD,
		buildLine: buildLine(),
		frozen,
		git,
		hint: frozen
			? 'این یک کپیِ نصب‌شده است، نه کد مخزن. با «npm rm -g vira» و بعد «npm link» در پوشهٔ مخزن درست می‌شود.'
			: '',
	};
}
