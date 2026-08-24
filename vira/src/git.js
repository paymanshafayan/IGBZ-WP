/**
 * گیت — اتصال به مخزن، نه فقط یک پوشه.
 *
 * خواستهٔ کارفرما (سند `DESIGN-PADO.md` §۱۶): مدیری که وسط خرابی نشسته باید بدون هیچ
 * کلیکی بداند **روی کدام مخزن، کدام شاخه، و چقدر عوض شده** — و یک راه برای بستن کار داشته
 * باشد.
 *
 * سه قاعده که در همهٔ توابع این فایل رعایت می‌شوند:
 *
 *   ۱) **هیچ‌وقت روی شاخهٔ اصلی کار نمی‌کنیم.** اگر روی main باشیم و کاری بخواهد بنویسد،
 *      اول شاخه ساخته می‌شود.
 *   ۲) **توکن هرگز در خط فرمان نمی‌آید.** نه در آرگومان، نه در آدرس ریموت. از راه یک
 *      credential helper موقت تزریق می‌شود تا در `ps` و در لاگ‌ها دیده نشود.
 *   ۳) **خروجی پاک‌سازی می‌شود.** اگر رازی در خروجی گیت ظاهر شد، قبل از برگشتن ماسک می‌خورد.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { execFile } from 'node:child_process';

const MAX_BUFFER = 20 * 1024 * 1024;

/** شاخه‌هایی که ویرا مستقیم رویشان کار نمی‌کند. */
export const PROTECTED_BRANCHES = [ 'main', 'master', 'production', 'prod' ];

/**
 * اجرای گیت. هیچ‌وقت با `shell: true` — تا نقل‌قول و تزریق موضوعیت نداشته باشد.
 *
 * @param {string[]} args
 * @param {{cwd:string, env?:NodeJS.ProcessEnv, timeout?:number}} opts
 * @returns {Promise<{ok:boolean, stdout:string, stderr:string, code:number}>}
 */
export function git( args, { cwd, env, timeout = 120_000 } = {} ) {
	return new Promise( ( resolve ) => {
		execFile(
			'git',
			args,
			{ cwd, env: env || process.env, maxBuffer: MAX_BUFFER, timeout },
			( err, stdout, stderr ) => {
				resolve( {
					ok: ! err,
					stdout: String( stdout || '' ),
					stderr: redact( String( stderr || '' ) ),
					code: err?.code ?? 0,
				} );
			}
		);
	} );
}

/**
 * ماسک‌کردن چیزهایی که شبیه راز هستند.
 *
 * این تور آخر است، نه اول: کار اصلی را credential helper می‌کند. ولی پیام خطای گیت گاهی
 * آدرسِ حاوی توکن را بازتاب می‌دهد و همان یک بار کافی است تا توکن در دفتر گفتگو بنشیند و
 * از آنجا به مدل برود.
 *
 * @param {string} text
 */
export function redact( text ) {
	return String( text ?? '' )
		.replace( /(https?:\/\/)[^@\s/]+:[^@\s/]+@/g, '$1•••:•••@' )
		.replace( /\b(gh[pousr]_[A-Za-z0-9]{16,})\b/g, '•••' )
		.replace( /\b(github_pat_[A-Za-z0-9_]{20,})\b/g, '•••' )
		.replace( /\b(glpat-[A-Za-z0-9_-]{16,})\b/g, '•••' )
		.replace( /\b(sk-[A-Za-z0-9_-]{20,})\b/g, '•••' );
}

/** @param {string} dir */
export async function isRepo( dir ) {
	const r = await git( [ 'rev-parse', '--is-inside-work-tree' ], { cwd: dir } );
	return r.ok && r.stdout.trim() === 'true';
}

/**
 * وضعیت کامل مخزن — همان چیزی که نوار پایینی کامپوزر نشان می‌دهد.
 *
 * @param {string} dir
 */
export async function status( dir ) {
	if ( ! ( await isRepo( dir ) ) ) {
		return null;
	}

	const [ branch, remote, porcelain, ahead, stat ] = await Promise.all( [
		git( [ 'rev-parse', '--abbrev-ref', 'HEAD' ], { cwd: dir } ),
		git( [ 'remote', 'get-url', 'origin' ], { cwd: dir } ),
		git( [ 'status', '--porcelain' ], { cwd: dir } ),
		git( [ 'rev-list', '--left-right', '--count', 'HEAD...@{upstream}' ], { cwd: dir } ),
		git( [ 'diff', '--shortstat', 'HEAD' ], { cwd: dir } ),
	] );

	const files = porcelain.stdout
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => ( { state: line.slice( 0, 2 ).trim() || '?', path: line.slice( 3 ).trim() } ) );

	const [ aheadN, behindN ] = ahead.ok ? ahead.stdout.trim().split( /\s+/ ).map( Number ) : [ 0, 0 ];
	const m = /(\d+) insertion|(\d+) deletion/g;
	let added = 0;
	let removed = 0;
	for ( const hit of stat.stdout.matchAll( /(\d+) (insertion|deletion)/g ) ) {
		if ( hit[ 2 ] === 'insertion' ) {
			added = Number( hit[ 1 ] );
		} else {
			removed = Number( hit[ 1 ] );
		}
	}

	const url = remote.ok ? redact( remote.stdout.trim() ) : '';

	return {
		branch: branch.stdout.trim(),
		remote: url,
		name: repoName( url ) || path.basename( path.resolve( dir ) ),
		files,
		dirty: files.length > 0,
		ahead: Number.isFinite( aheadN ) ? aheadN : 0,
		behind: Number.isFinite( behindN ) ? behindN : 0,
		added,
		removed,
		protected: PROTECTED_BRANCHES.includes( branch.stdout.trim() ),
	};
}

/** @param {string} url */
export function repoName( url ) {
	const clean = String( url || '' ).replace( /\.git$/, '' );
	const parts = clean.split( /[:/]/ ).filter( Boolean );
	return parts.length >= 2 ? `${ parts[ parts.length - 2 ] }/${ parts[ parts.length - 1 ] }` : parts.pop() || '';
}

/**
 * دیف تجمعی نشست: از یک نقطهٔ مبنا تا الان، شامل تغییرات ذخیره‌نشده.
 *
 * چرا `merge-base` نه خودِ مبنا: اگر شاخهٔ مبنا جلو رفته باشد، دیف مستقیم پر از تغییرات
 * دیگران می‌شود و عدد «چقدر من عوض کرده‌ام» دروغ می‌گوید.
 *
 * @param {string} dir
 * @param {{base?:string, file?:string, staged?:boolean}} [opts]
 */
export async function diff( dir, { base, file } = {} ) {
	const args = [ 'diff', '--no-color' ];

	if ( base ) {
		const merge = await git( [ 'merge-base', 'HEAD', base ], { cwd: dir } );
		args.push( merge.ok ? merge.stdout.trim() : base );
	} else {
		// بدون مبنا یعنی «همهٔ کارِ ذخیره‌نشدهٔ من» — که شامل استیج‌شده‌ها هم هست.
		// بدون HEAD، فایلی که `git add` شده در دیف دیده نمی‌شود و عدد دروغ می‌گوید.
		args.push( 'HEAD' );
	}
	if ( file ) {
		args.push( '--', file );
	}

	const out = await git( args, { cwd: dir } );
	return redact( out.stdout );
}

/**
 * آمار دیف به تفکیک فایل — برای پنل تغییرات.
 * @param {string} dir
 * @param {string} [base]
 */
export async function diffStat( dir, base ) {
	const args = [ 'diff', '--numstat' ];
	if ( base ) {
		const merge = await git( [ 'merge-base', 'HEAD', base ], { cwd: dir } );
		args.push( merge.ok ? merge.stdout.trim() : base );
	} else {
		args.push( 'HEAD' );
	}
	const out = await git( args, { cwd: dir } );
	if ( ! out.ok ) {
		return [];
	}
	return out.stdout
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => {
			const [ add, del, ...rest ] = line.split( '\t' );
			return { added: Number( add ) || 0, removed: Number( del ) || 0, path: rest.join( '\t' ) };
		} );
}

/**
 * ساخت یا تعویض شاخه.
 * @param {string} dir
 * @param {string} name
 * @param {{create?:boolean}} [opts]
 */
export async function branch( dir, name, { create = false } = {} ) {
	const safe = String( name || '' ).trim();
	if ( ! /^[\w./-]{1,120}$/.test( safe ) ) {
		throw new Error( 'نام شاخه معتبر نیست.' );
	}
	const out = create
		? await git( [ 'checkout', '-b', safe ], { cwd: dir } )
		: await git( [ 'checkout', safe ], { cwd: dir } );
	if ( ! out.ok ) {
		throw new Error( out.stderr || 'تعویض شاخه نشد.' );
	}
	return { branch: safe };
}

/** فهرست شاخه‌های محلی. */
export async function branches( dir ) {
	const out = await git( [ 'branch', '--format=%(refname:short)|%(committerdate:relative)' ], { cwd: dir } );
	return out.stdout
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => {
			const [ name, when ] = line.split( '|' );
			return { name, when: when || '', protected: PROTECTED_BRANCHES.includes( name ) };
		} );
}

/**
 * کامیت. اگر روی شاخهٔ محافظت‌شده باشیم، **اول شاخه می‌سازد** — قاعدهٔ یکِ این فایل.
 *
 * @param {string} dir
 * @param {{message:string, paths?:string[], branch?:string}} opts
 */
export async function commit( dir, { message, paths, branch: wanted } ) {
	const text = String( message || '' ).trim();
	if ( ! text ) {
		throw new Error( 'پیام کامیت خالی است.' );
	}

	const before = await status( dir );
	if ( ! before ) {
		throw new Error( 'اینجا مخزن گیت نیست.' );
	}

	let movedTo = null;
	if ( before.protected || wanted ) {
		const name = wanted || `vira/${ slug( text ) }-${ Date.now().toString( 36 ).slice( -4 ) }`;
		if ( name !== before.branch ) {
			await branch( dir, name, { create: true } );
			movedTo = name;
		}
	}

	const add = paths?.length ? [ 'add', '--', ...paths ] : [ 'add', '-A' ];
	const staged = await git( add, { cwd: dir } );
	if ( ! staged.ok ) {
		throw new Error( staged.stderr || 'افزودن فایل‌ها نشد.' );
	}

	const out = await git(
		[ '-c', 'user.name=Vira', '-c', 'user.email=vira@igbz.local', 'commit', '-m', text ],
		{ cwd: dir }
	);
	if ( ! out.ok ) {
		throw new Error( out.stderr || out.stdout || 'کامیت نشد.' );
	}

	const sha = ( await git( [ 'rev-parse', '--short', 'HEAD' ], { cwd: dir } ) ).stdout.trim();
	return { sha, branch: movedTo || before.branch, movedTo, message: text };
}

/**
 * نام شاخه از پیام کامیت.
 *
 * فقط ASCII: نام شاخهٔ فارسی از نظر گیت مجاز است ولی روی ابزارهای دیگر و در URL دردسر
 * می‌سازد. اگر پیام تماماً فارسی باشد، نام از زمان ساخته می‌شود.
 *
 * @param {string} text
 */
export function slug( text ) {
	const ascii = String( text )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.slice( 0, 40 );
	return ascii || `kar-${ Date.now().toString( 36 ).slice( -6 ) }`;
}

/**
 * پوش — با تزریق امن اعتبارنامه.
 *
 * توکن نه در آرگومان می‌آید نه در آدرس ریموت. یک فایل موقت به‌عنوان credential helper
 * ساخته می‌شود، گیت از آن می‌خواند، و بعد پاک می‌شود.
 *
 * @param {string} dir
 * @param {{branch?:string, token?:string, username?:string}} [opts]
 */
export async function push( dir, { branch: name, token, username = 'x-access-token' } = {} ) {
	const current = name || ( await status( dir ) )?.branch;
	if ( ! current ) {
		throw new Error( 'شاخهٔ جاری معلوم نیست.' );
	}
	if ( PROTECTED_BRANCHES.includes( current ) ) {
		throw new Error( `پوش مستقیم روی «${ current }» مجاز نیست. اول یک شاخهٔ کاری بساز.` );
	}

	let helper = null;
	let env = process.env;

	if ( token ) {
		helper = await fs.mkdtemp( path.join( os.tmpdir(), 'vira-cred-' ) );
		const file = path.join( helper, 'askpass.sh' );
		await fs.writeFile(
			file,
			[
				'#!/bin/sh',
				'case "$1" in',
				`*[Uu]sername*) printf '%s' "$VIRA_GIT_USER" ;;`,
				`*[Pp]assword*) printf '%s' "$VIRA_GIT_TOKEN" ;;`,
				'esac',
				'',
			].join( '\n' ),
			{ mode: 0o700 }
		);
		env = {
			...process.env,
			GIT_ASKPASS: file,
			GIT_TERMINAL_PROMPT: '0',
			VIRA_GIT_USER: username,
			VIRA_GIT_TOKEN: token,
		};
	}

	try {
		const out = await git( [ 'push', '-u', 'origin', current ], { cwd: dir, env } );
		if ( ! out.ok ) {
			throw new Error( out.stderr || 'پوش نشد.' );
		}
		return { branch: current, output: redact( out.stderr || out.stdout ) };
	} finally {
		if ( helper ) {
			await fs.rm( helper, { recursive: true, force: true } ).catch( () => {} );
		}
	}
}

/**
 * کلون یک مخزن در فضای کاری مدیریت‌شده.
 *
 * @param {{url:string, into:string, name?:string, token?:string, username?:string, branch?:string}} opts
 */
export async function clone( { url, into, name, token, username = 'x-access-token', branch: wanted } ) {
	const clean = String( url || '' ).trim();
	if ( ! /^(https?:\/\/|git@)/.test( clean ) ) {
		throw new Error( 'آدرس مخزن باید با https:// یا git@ شروع شود.' );
	}

	const folder = ( name || repoName( clean ).split( '/' ).pop() || 'repo' ).replace( /[^\w.-]/g, '-' );
	const target = path.join( into, folder );

	const exists = await fs
		.stat( target )
		.then( () => true )
		.catch( () => false );
	if ( exists ) {
		throw new Error( `پوشه‌ای به نام «${ folder }» از قبل هست.` );
	}

	let helper = null;
	let env = process.env;
	if ( token ) {
		helper = await fs.mkdtemp( path.join( os.tmpdir(), 'vira-cred-' ) );
		const file = path.join( helper, 'askpass.sh' );
		await fs.writeFile(
			file,
			[ '#!/bin/sh', 'case "$1" in', `*[Uu]sername*) printf '%s' "$VIRA_GIT_USER" ;;`, `*[Pp]assword*) printf '%s' "$VIRA_GIT_TOKEN" ;;`, 'esac', '' ].join( '\n' ),
			{ mode: 0o700 }
		);
		env = { ...process.env, GIT_ASKPASS: file, GIT_TERMINAL_PROMPT: '0', VIRA_GIT_USER: username, VIRA_GIT_TOKEN: token };
	}

	try {
		await fs.mkdir( into, { recursive: true } );
		const args = [ 'clone', '--depth', '50' ];
		if ( wanted ) {
			args.push( '--branch', wanted );
		}
		args.push( clean, target );

		const out = await git( args, { cwd: into, env, timeout: 600_000 } );
		if ( ! out.ok ) {
			throw new Error( out.stderr || 'کلون نشد.' );
		}
		return { path: target, name: repoName( clean ) || folder };
	} finally {
		if ( helper ) {
			await fs.rm( helper, { recursive: true, force: true } ).catch( () => {} );
		}
	}
}

/**
 * ساخت درخواست ادغام با `gh` اگر نصب باشد.
 * @param {string} dir
 * @param {{title:string, body?:string, base?:string}} opts
 */
export async function pullRequest( dir, { title, body = '', base } ) {
	const args = [ 'pr', 'create', '--title', String( title ), '--body', String( body ) ];
	if ( base ) {
		args.push( '--base', base );
	}

	return new Promise( ( resolve ) => {
		execFile( 'gh', args, { cwd: dir, maxBuffer: MAX_BUFFER }, ( err, stdout, stderr ) => {
			if ( err ) {
				resolve( { ok: false, message: redact( String( stderr || err.message ) ) } );
				return;
			}
			resolve( { ok: true, url: String( stdout ).trim() } );
		} );
	} );
}

/**
 * مخزن‌هایی که ویرا اجازهٔ دسترسی به آن‌ها را دارد.
 *
 * منبع، خودِ `gh` است: هر مخزنی که توکنِ نصب‌شدهٔ کاربر به آن دسترسی دارد. یعنی فهرست
 * دقیقاً همان چیزی است که کاربر در گیت‌هاب به این ماشین مجوز داده — نه یک فهرست دستی
 * که فردا کهنه شود.
 *
 * اگر `gh` نباشد یا لاگین نشده باشد، به‌جای فهرست خالی، **دلیلش** برمی‌گردد؛ رابط باید
 * بتواند بگوید چرا چیزی نیست.
 *
 * @param {number} [limit]
 * @returns {Promise<{ok:boolean, repos:{nameWithOwner:string, defaultBranch:string, url:string, private:boolean, updatedAt:string}[], message?:string}>}
 */
export async function repos( limit = 100 ) {
	return new Promise( ( resolve ) => {
		execFile(
			'gh',
			[ 'repo', 'list', '--limit', String( limit ), '--json', 'nameWithOwner,defaultBranchRef,url,isPrivate,updatedAt' ],
			{ maxBuffer: MAX_BUFFER },
			( err, stdout, stderr ) => {
				if ( err ) {
					const message = redact( String( stderr || err.message ) );
					resolve( {
						ok: false,
						repos: [],
						message: /not found|ENOENT/i.test( message )
							? 'ابزار gh نصب نیست؛ بدون آن فهرست مخزن‌های مجاز در دسترس نیست.'
							: message,
					} );
					return;
				}
				try {
					const raw = JSON.parse( String( stdout || '[]' ) );
					resolve( {
						ok: true,
						repos: raw.map( ( r ) => ( {
							nameWithOwner: r.nameWithOwner,
							defaultBranch: r.defaultBranchRef?.name || 'main',
							url: r.url,
							private: Boolean( r.isPrivate ),
							updatedAt: r.updatedAt || '',
						} ) ),
					} );
				} catch ( e ) {
					resolve( { ok: false, repos: [], message: redact( String( e?.message || e ) ) } );
				}
			}
		);
	} );
}

/**
 * آخرین کامیت‌ها — برای پنل تغییرات.
 * @param {string} dir
 * @param {number} [limit]
 */
export async function log( dir, limit = 20 ) {
	const out = await git(
		[ 'log', `-${ limit }`, '--format=%h|%an|%ar|%s' ],
		{ cwd: dir }
	);
	return out.stdout
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => {
			const [ sha, author, when, ...rest ] = line.split( '|' );
			return { sha, author, when, subject: rest.join( '|' ) };
		} );
}
