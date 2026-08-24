/**
 * دیدِ برنامه به پوشهٔ کاری: درخت فایل، جستجوی فازی برای «@»، خواندن امن فایل، و وضعیت گیت.
 *
 * چرا جدا از ابزارهای مدل: این‌ها برای **رابط کاربری**اند نه برای مدل. وقتی کاربر «@» را
 * می‌زند باید در چند میلی‌ثانیه فهرست بگیرد؛ نمی‌شود برای هر حرف، ابزار مدل را صدا زد.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { execFile } from 'node:child_process';

const SKIP = new Set( [
	'node_modules',
	'.git',
	'dist',
	'build',
	'.next',
	'vendor',
	'__pycache__',
	'.venv',
	'coverage',
	'.cache',
	'.work',
] );

const MAX_FILES = 20_000;

/**
 * @param {string} root
 * @returns {Promise<string[]>}
 */
export async function listFiles( root ) {
	/** @type {string[]} */
	const out = [];

	/** @param {string} dir @param {number} depth */
	async function walk( dir, depth ) {
		if ( out.length >= MAX_FILES || depth > 14 ) {
			return;
		}
		let entries;
		try {
			entries = await fs.readdir( dir, { withFileTypes: true } );
		} catch {
			return;
		}
		for ( const e of entries ) {
			if ( out.length >= MAX_FILES ) {
				return;
			}
			if ( SKIP.has( e.name ) ) {
				continue;
			}
			const full = path.join( dir, e.name );
			if ( e.isDirectory() ) {
				await walk( full, depth + 1 );
			} else if ( e.isFile() ) {
				out.push( path.relative( root, full ).split( path.sep ).join( '/' ) );
			}
		}
	}

	await walk( root, 0 );
	return out;
}

/**
 * جستجوی فازی سبک — همان چیزی که یک منوی «@» لازم دارد.
 * امتیاز: تطبیق کامل زیررشته > تطبیق در نام فایل > تطبیق پراکندهٔ حروف.
 *
 * @param {string[]} files
 * @param {string} query
 * @param {number} [limit]
 */
export function fuzzyFilter( files, query, limit = 20 ) {
	const q = String( query || '' ).trim().toLowerCase();
	if ( ! q ) {
		return files.slice( 0, limit );
	}

	/** @type {{file:string, score:number}[]} */
	const scored = [];
	for ( const file of files ) {
		const lower = file.toLowerCase();
		const base = lower.slice( lower.lastIndexOf( '/' ) + 1 );
		let score = -1;

		if ( base === q ) {
			score = 1000;
		} else if ( base.startsWith( q ) ) {
			score = 900 - base.length;
		} else if ( base.includes( q ) ) {
			score = 700 - base.length;
		} else if ( lower.includes( q ) ) {
			score = 500 - lower.length;
		} else {
			// حروف به‌ترتیب ولی پراکنده
			let i = 0;
			for ( const ch of lower ) {
				if ( ch === q[ i ] ) {
					i++;
				}
				if ( i === q.length ) {
					break;
				}
			}
			if ( i === q.length ) {
				score = 200 - lower.length;
			}
		}

		if ( score > -1 ) {
			scored.push( { file, score } );
		}
	}

	return scored
		.sort( ( a, b ) => b.score - a.score || a.file.length - b.file.length )
		.slice( 0, limit )
		.map( ( s ) => s.file );
}

/**
 * خواندن فایل برای نمایش در رابط کاربری (نه برای مدل).
 * @param {string} root
 * @param {string} rel
 */
export async function readWorkspaceFile( root, rel ) {
	const abs = path.resolve( root, rel );
	if ( abs !== path.resolve( root ) && ! abs.startsWith( path.resolve( root ) + path.sep ) ) {
		throw new Error( 'مسیر بیرون از پوشهٔ کاری است.' );
	}
	const stat = await fs.stat( abs );
	if ( stat.size > 1_000_000 ) {
		return { path: rel, tooBig: true, size: stat.size, text: '' };
	}
	const buf = await fs.readFile( abs );
	if ( buf.includes( 0 ) ) {
		return { path: rel, binary: true, size: stat.size, text: '' };
	}
	return { path: rel, size: stat.size, text: buf.toString( 'utf8' ) };
}

/**
 * @param {string} cwd
 * @param {string[]} args
 * @returns {Promise<string>}
 */
function git( cwd, args ) {
	return new Promise( ( resolve ) => {
		execFile( 'git', args, { cwd, maxBuffer: 10_000_000 }, ( err, stdout ) => {
			resolve( err ? '' : String( stdout ) );
		} );
	} );
}

/** وضعیت گیت برای نوار پایین — اگر گیت نبود، ساکت رد می‌شود. */
export async function gitStatus( cwd ) {
	const inside = ( await git( cwd, [ 'rev-parse', '--is-inside-work-tree' ] ) ).trim();
	if ( inside !== 'true' ) {
		return null;
	}
	const branch = ( await git( cwd, [ 'rev-parse', '--abbrev-ref', 'HEAD' ] ) ).trim();
	const porcelain = await git( cwd, [ 'status', '--porcelain' ] );
	const files = porcelain
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => ( { state: line.slice( 0, 2 ).trim(), path: line.slice( 3 ) } ) );
	return { branch, files, dirty: files.length > 0 };
}

/**
 * دیف گیت یک فایل (یا کل پوشه) برای نمایش در رابط.
 * @param {string} cwd
 * @param {string} [file]
 */
export async function gitDiff( cwd, file ) {
	const args = [ 'diff', '--no-color' ];
	if ( file ) {
		args.push( '--', file );
	}
	const text = await git( cwd, args );
	return text || ( await git( cwd, [ 'diff', '--no-color', '--staged', ...( file ? [ '--', file ] : [] ) ] ) );
}
