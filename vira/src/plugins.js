/**
 * پلاگین‌ها و مارکت‌پلیس.
 *
 * یک پلاگین فقط یک پوشه است که می‌تواند هر ترکیبی از این‌ها را داشته باشد:
 *
 *   plugin.json      { name, version, description }
 *   skills/<x>/SKILL.md
 *   commands/<x>.md
 *   .mcp.json        { mcpServers: { … } }
 *   hooks.json       { PreToolUse: [ … ] }
 *
 * نصب از سه جا: مخزن گیت، پوشهٔ محلی، یا یک مارکت‌پلیس (که خودش یک مخزن گیت است با
 * فایل `marketplace.json` که پلاگین‌هایش را فهرست کرده).
 *
 * نصب یعنی کپی‌کردن پوشه در ~/.vira/plugins/<name>. عمداً چیزی اجرا نمی‌شود: نصب یک
 * پلاگین نباید کد اجرا کند. کد فقط وقتی اجرا می‌شود که خود عامل ابزاری را صدا بزند و از
 * دروازهٔ مجوز رد شود.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';
import os from 'node:os';

/** @param {string} home */
export function pluginsDir( home ) {
	return path.join( home, 'plugins' );
}

/**
 * @param {string} home
 * @returns {Promise<{name:string,dir:string,manifest:any,enabled:boolean,has:{skills:number,commands:number,mcp:boolean,hooks:boolean}}[]>}
 */
export async function listPlugins( home ) {
	const root = pluginsDir( home );
	let entries;
	try {
		entries = await fs.readdir( root, { withFileTypes: true } );
	} catch {
		return [];
	}

	const out = [];
	for ( const e of entries ) {
		if ( ! e.isDirectory() ) {
			continue;
		}
		const dir = path.join( root, e.name );
		const manifest = await readJson( path.join( dir, 'plugin.json' ) );
		out.push( {
			name: manifest?.name || e.name,
			dir,
			manifest: manifest || {},
			enabled: ! ( await exists( path.join( dir, '.disabled' ) ) ),
			has: {
				skills: ( await safeReaddir( path.join( dir, 'skills' ) ) ).length,
				commands: ( await safeReaddir( path.join( dir, 'commands' ) ) ).filter( ( f ) => f.endsWith( '.md' ) )
					.length,
				mcp: await exists( path.join( dir, '.mcp.json' ) ),
				hooks: await exists( path.join( dir, 'hooks.json' ) ),
			},
		} );
	}
	return out;
}

/** پلاگین‌های فعال، برای تغذیهٔ اسکیل/دستور/MCP/هوک. */
export async function activePlugins( home ) {
	return ( await listPlugins( home ) ).filter( ( p ) => p.enabled );
}

/**
 * نصب پلاگین از گیت یا مسیر محلی.
 *
 * @param {string} home
 * @param {string} source  آدرس گیت، `owner/repo`، یا مسیر محلی
 * @param {string} [nameHint]
 */
export async function installPlugin( home, source, nameHint ) {
	const root = pluginsDir( home );
	await fs.mkdir( root, { recursive: true } );

	const isLocal = source.startsWith( '.' ) || source.startsWith( '/' ) || /^[A-Za-z]:\\/.test( source );
	const name = ( nameHint || guessName( source ) ).replace( /[^\w.-]/g, '-' );
	const target = path.join( root, name );

	if ( await exists( target ) ) {
		throw new Error( `پلاگینی به نام «${ name }» از قبل نصب است.` );
	}

	if ( isLocal ) {
		const src = path.resolve( source );
		if ( ! ( await exists( path.join( src, 'plugin.json' ) ) ) && ! ( await exists( path.join( src, 'skills' ) ) ) ) {
			throw new Error( 'این پوشه شبیه پلاگین نیست (نه plugin.json دارد نه skills/).' );
		}
		await copyDir( src, target );
	} else {
		const url = source.includes( '://' ) || source.startsWith( 'git@' )
			? source
			: `https://github.com/${ source }.git`;
		const tmp = path.join( os.tmpdir(), `vira-plugin-${ Date.now() }` );
		await git( [ 'clone', '--depth', '1', url, tmp ] );
		await fs.rm( path.join( tmp, '.git' ), { recursive: true, force: true } );
		await fs.rename( tmp, target ).catch( async () => {
			await copyDir( tmp, target );
			await fs.rm( tmp, { recursive: true, force: true } );
		} );
	}

	const manifest = ( await readJson( path.join( target, 'plugin.json' ) ) ) || {};
	return { name: manifest.name || name, dir: target, manifest };
}

/**
 * @param {string} home
 * @param {string} name
 */
export async function removePlugin( home, name ) {
	const dir = path.join( pluginsDir( home ), name.replace( /[^\w.-]/g, '-' ) );
	if ( ! ( await exists( dir ) ) ) {
		throw new Error( 'چنین پلاگینی نصب نیست.' );
	}
	await fs.rm( dir, { recursive: true, force: true } );
	return true;
}

/**
 * @param {string} home
 * @param {string} name
 * @param {boolean} enabled
 */
export async function setPluginEnabled( home, name, enabled ) {
	const dir = path.join( pluginsDir( home ), name.replace( /[^\w.-]/g, '-' ) );
	const flag = path.join( dir, '.disabled' );
	if ( enabled ) {
		await fs.rm( flag, { force: true } );
	} else {
		await fs.writeFile( flag, '', 'utf8' );
	}
	return true;
}

/**
 * خواندن فهرست یک مارکت‌پلیس. مارکت‌پلیس = مخزنی با فایل marketplace.json:
 *   { "name": "…", "plugins": [ { "name":"seo", "source":"owner/repo", "description":"…" } ] }
 *
 * @param {string} source
 */
export async function fetchMarketplace( source ) {
	// مسیر محلی
	if ( source.startsWith( '.' ) || source.startsWith( '/' ) ) {
		const data = await readJson( path.join( path.resolve( source ), 'marketplace.json' ) );
		if ( ! data ) {
			throw new Error( 'فایل marketplace.json پیدا نشد.' );
		}
		return data;
	}

	// روی گیت‌هاب، بدون clone: فایل خام را می‌خوانیم.
	const repo = source.includes( '://' ) ? new URL( source ).pathname.replace( /^\/|\.git$/g, '' ) : source;
	for ( const branch of [ 'main', 'master' ] ) {
		const url = `https://raw.githubusercontent.com/${ repo }/${ branch }/marketplace.json`;
		const res = await fetch( url ).catch( () => null );
		if ( res?.ok ) {
			return res.json();
		}
	}
	throw new Error( 'marketplace.json در این مخزن پیدا نشد.' );
}

// ------------------------------------------------------------------ کمکی‌ها

/** @param {string[]} args */
function git( args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( 'git', args, { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
		let err = '';
		child.stderr.on( 'data', ( d ) => {
			err += d.toString();
		} );
		child.on( 'error', () => reject( new Error( 'git روی این سیستم پیدا نشد.' ) ) );
		child.on( 'close', ( code ) =>
			code === 0 ? resolve( true ) : reject( new Error( `git شکست خورد: ${ err.slice( 0, 300 ) }` ) )
		);
	} );
}

/** @param {string} source */
function guessName( source ) {
	return String( source )
		.replace( /\.git$/, '' )
		.split( /[\\/]/ )
		.filter( Boolean )
		.pop() || 'plugin';
}

async function copyDir( src, dest ) {
	await fs.cp( src, dest, { recursive: true, filter: ( p ) => ! p.includes( `${ path.sep }.git${ path.sep }` ) } );
}

async function exists( p ) {
	return fs
		.access( p )
		.then( () => true )
		.catch( () => false );
}

async function readJson( p ) {
	try {
		return JSON.parse( await fs.readFile( p, 'utf8' ) );
	} catch {
		return null;
	}
}

async function safeReaddir( p ) {
	try {
		return await fs.readdir( p );
	} catch {
		return [];
	}
}
