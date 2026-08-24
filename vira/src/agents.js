/**
 * زیرعامل‌های تعریف‌شدهٔ کاربر (همان چیزی که در Claude Code به آن «agents» می‌گویند).
 *
 * هر عامل یک فایل مارک‌داون با فرانت‌متر است:
 *
 *   ---
 *   name: reviewer
 *   description: کد را مرور می‌کند و ایراد امنیتی می‌گیرد
 *   tools: read_file, grep, glob
 *   model: gpt-4o-mini
 *   ---
 *   تو یک مرورگر کد سخت‌گیر هستی…
 *
 * جای فایل‌ها:
 *   ~/.vira/agents/<name>.md              سراسری
 *   <workspace>/.vira/agents/<name>.md    مخصوص پروژه (اولویت بالاتر)
 *   <plugin>/agents/<name>.md               از راه پلاگین
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { parseFrontmatter } from './skills.js';

/**
 * @typedef {Object} AgentDef
 * @property {string} name
 * @property {string} description
 * @property {string} prompt
 * @property {string[]} [tools]
 * @property {string} [model]
 * @property {string} source
 * @property {string} file
 */

/**
 * @param {string} dir
 * @param {string} source
 * @returns {Promise<AgentDef[]>}
 */
export async function loadAgentsFrom( dir, source ) {
	/** @type {AgentDef[]} */
	const out = [];
	let files;
	try {
		files = await fs.readdir( dir );
	} catch {
		return out;
	}
	for ( const f of files ) {
		if ( ! f.endsWith( '.md' ) ) {
			continue;
		}
		const file = path.join( dir, f );
		const text = await fs.readFile( file, 'utf8' ).catch( () => '' );
		if ( ! text.trim() ) {
			continue;
		}
		const { data, body } = parseFrontmatter( text );
		out.push( {
			name: String( data.name || path.basename( f, '.md' ) ),
			description: String( data.description || '' ).slice( 0, 300 ),
			prompt: body.trim(),
			tools: toList( data.tools ),
			model: data.model ? String( data.model ) : undefined,
			source,
			file,
		} );
	}
	return out;
}

/** @param {any} value */
function toList( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( String ).filter( Boolean );
	}
	if ( typeof value === 'string' && value.trim() ) {
		return value
			.split( ',' )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
	}
	return undefined;
}

/**
 * @param {{home:string, workspace:string, pluginDirs?:{name:string,dir:string}[]}} opts
 * @returns {Promise<AgentDef[]>}
 */
export async function collectAgents( { home, workspace, pluginDirs = [] } ) {
	/** @type {AgentDef[]} */
	const all = [];
	all.push( ...( await loadAgentsFrom( path.join( home, 'agents' ), 'user' ) ) );
	for ( const p of pluginDirs ) {
		all.push( ...( await loadAgentsFrom( path.join( p.dir, 'agents' ), p.name ) ) );
	}
	all.push( ...( await loadAgentsFrom( path.join( workspace, '.vira', 'agents' ), 'project' ) ) );

	/** @type {Map<string, AgentDef>} */
	const byName = new Map();
	for ( const a of all ) {
		byName.set( a.name, a );
	}
	return [ ...byName.values() ];
}

/**
 * نوشتن یا به‌روزرسانی یک عامل.
 *
 * @param {{home:string, workspace:string}} roots
 * @param {{name:string, description?:string, prompt:string, tools?:string[], model?:string, scope?:'user'|'project'}} def
 */
export async function saveAgent( roots, def ) {
	const name = String( def.name || '' ).trim();
	if ( ! /^[\w-]{1,60}$/.test( name ) ) {
		throw new Error( 'نام عامل فقط می‌تواند حرف انگلیسی، رقم، خط تیره و زیرخط باشد.' );
	}
	const base = def.scope === 'project' ? path.join( roots.workspace, '.vira', 'agents' ) : path.join( roots.home, 'agents' );
	await fs.mkdir( base, { recursive: true } );

	const front = [
		'---',
		`name: ${ name }`,
		`description: ${ String( def.description || '' ).replace( /\n/g, ' ' ) }`,
		...( def.tools?.length ? [ `tools: ${ def.tools.join( ', ' ) }` ] : [] ),
		...( def.model ? [ `model: ${ def.model }` ] : [] ),
		'---',
		'',
	].join( '\n' );

	const file = path.join( base, `${ name }.md` );
	await fs.writeFile( file, front + String( def.prompt || '' ).trim() + '\n', 'utf8' );
	return { name, file };
}

/**
 * @param {{home:string, workspace:string}} roots
 * @param {string} name
 */
export async function removeAgent( roots, name ) {
	const safe = String( name ).replace( /[^\w-]/g, '' );
	let removed = false;
	for ( const base of [ path.join( roots.home, 'agents' ), path.join( roots.workspace, '.vira', 'agents' ) ] ) {
		const file = path.join( base, `${ safe }.md` );
		const exists = await fs
			.stat( file )
			.then( () => true )
			.catch( () => false );
		if ( ! exists ) {
			continue;
		}
		await fs.rm( file, { force: true } );
		removed = true;
	}
	if ( ! removed ) {
		throw new Error( 'عامل پیدا نشد.' );
	}
	return { ok: true };
}
