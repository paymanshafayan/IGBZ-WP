/**
 * کانکتورها (سرورهای MCP) — افزودن، ویرایش، خاموش/روشن، حذف و آزمودن، از داخل خود برنامه.
 *
 * تا امروز برای اضافه‌کردن یک کانکتور باید دستی JSON ویرایش می‌شد. این فایل همان کار را
 * قابل‌کلیک می‌کند و دو محدوده را از هم جدا نگه می‌دارد:
 *
 *   سراسری → ~/.vira/config.json  کلید mcpServers
 *   پروژه‌ای → <workspace>/.vira/mcp.json
 *
 * قالب تنظیمات همان قالب استاندارد است تا هر فایل آمادهٔ موجود، بدون تغییر کار کند.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { loadConfig, saveConfig } from './config.js';

/** @param {string} workspace */
function projectFile( workspace ) {
	return path.join( workspace, '.vira', 'mcp.json' );
}

/** @param {string} workspace */
async function readProject( workspace ) {
	try {
		const parsed = JSON.parse( await fs.readFile( projectFile( workspace ), 'utf8' ) );
		return parsed.mcpServers || parsed || {};
	} catch {
		return {};
	}
}

/**
 * @param {string} workspace
 * @param {Record<string, any>} servers
 */
async function writeProject( workspace, servers ) {
	const file = projectFile( workspace );
	await fs.mkdir( path.dirname( file ), { recursive: true } );
	await fs.writeFile( file, JSON.stringify( { mcpServers: servers }, null, 2 ), 'utf8' );
}

/**
 * فهرست همهٔ کانکتورهای تعریف‌شده، با محدوده‌شان.
 * @param {{workspace:string}} opts
 */
export async function listConnectors( { workspace } ) {
	const cfg = await loadConfig();
	const globals = cfg.mcpServers || {};
	const project = await readProject( workspace );

	/** @type {{name:string, scope:'user'|'project', config:any, disabled:boolean, kind:'stdio'|'http'}[]} */
	const out = [];
	for ( const [ name, config ] of Object.entries( globals ) ) {
		out.push( { name, scope: 'user', config, disabled: Boolean( config?.disabled ), kind: config?.url ? 'http' : 'stdio' } );
	}
	for ( const [ name, config ] of Object.entries( project ) ) {
		const existing = out.findIndex( ( c ) => c.name === name );
		const item = {
			name,
			scope: /** @type {const} */ ( 'project' ),
			config,
			disabled: Boolean( config?.disabled ),
			kind: /** @type {const} */ ( config?.url ? 'http' : 'stdio' ),
		};
		if ( existing > -1 ) {
			out[ existing ] = item; // پروژه روی سراسری می‌نشیند.
		} else {
			out.push( item );
		}
	}
	return out;
}

/**
 * ساخت تنظیمات از ورودی فرم.
 * @param {any} body
 */
export function normalizeConnector( body ) {
	const name = String( body.name || '' ).trim();
	if ( ! /^[\w-]{1,64}$/.test( name ) ) {
		throw new Error( 'نام کانکتور فقط حرف انگلیسی، رقم، خط تیره و زیرخط باشد.' );
	}

	/** @type {any} */
	const config = {};

	if ( body.kind === 'http' ) {
		const url = String( body.url || '' ).trim();
		if ( ! /^https?:\/\//.test( url ) ) {
			throw new Error( 'آدرس باید با http:// یا https:// شروع شود.' );
		}
		config.url = url;
		if ( body.headers && Object.keys( body.headers ).length ) {
			config.headers = body.headers;
		}
	} else {
		const command = String( body.command || '' ).trim();
		if ( ! command ) {
			throw new Error( 'فرمان اجرای سرور خالی است.' );
		}
		config.command = command;
		const args = Array.isArray( body.args )
			? body.args
			: String( body.args || '' )
					.split( /\s+/ )
					.filter( Boolean );
		if ( args.length ) {
			config.args = args;
		}
		if ( body.env && Object.keys( body.env ).length ) {
			config.env = body.env;
		}
		if ( body.cwd ) {
			config.cwd = String( body.cwd );
		}
	}

	if ( body.disabled ) {
		config.disabled = true;
	}
	if ( body.timeout ) {
		config.timeout = Number( body.timeout );
	}

	return { name, config };
}

/**
 * @param {{workspace:string, scope?:'user'|'project'}} opts
 * @param {any} body
 */
export async function saveConnector( { workspace, scope = 'user' }, body ) {
	const { name, config } = normalizeConnector( body );

	if ( scope === 'project' ) {
		const servers = await readProject( workspace );
		servers[ name ] = config;
		await writeProject( workspace, servers );
	} else {
		const cfg = await loadConfig();
		cfg.mcpServers = { ...( cfg.mcpServers || {} ), [ name ]: config };
		await saveConfig( cfg );
	}

	// اگر همین نام قبلاً در محدودهٔ دیگر بود، مهاجرت کرده — نسخهٔ قبلی را برمی‌داریم.
	if ( body.previousScope && body.previousScope !== scope ) {
		await removeConnector( { workspace, scope: body.previousScope }, name ).catch( () => {} );
	}

	return { name, scope, config };
}

/**
 * @param {{workspace:string, scope?:'user'|'project'}} opts
 * @param {string} name
 */
export async function removeConnector( { workspace, scope = 'user' }, name ) {
	if ( scope === 'project' ) {
		const servers = await readProject( workspace );
		if ( ! servers[ name ] ) {
			throw new Error( 'کانکتور پیدا نشد.' );
		}
		delete servers[ name ];
		await writeProject( workspace, servers );
		return { ok: true };
	}
	const cfg = await loadConfig();
	if ( ! cfg.mcpServers?.[ name ] ) {
		throw new Error( 'کانکتور پیدا نشد.' );
	}
	delete cfg.mcpServers[ name ];
	await saveConfig( cfg );
	return { ok: true };
}

/**
 * @param {{workspace:string, scope?:'user'|'project'}} opts
 * @param {string} name
 * @param {boolean} enabled
 */
export async function setConnectorEnabled( { workspace, scope = 'user' }, name, enabled ) {
	if ( scope === 'project' ) {
		const servers = await readProject( workspace );
		if ( ! servers[ name ] ) {
			throw new Error( 'کانکتور پیدا نشد.' );
		}
		servers[ name ] = { ...servers[ name ], disabled: ! enabled };
		if ( enabled ) {
			delete servers[ name ].disabled;
		}
		await writeProject( workspace, servers );
		return { ok: true };
	}
	const cfg = await loadConfig();
	if ( ! cfg.mcpServers?.[ name ] ) {
		throw new Error( 'کانکتور پیدا نشد.' );
	}
	cfg.mcpServers[ name ] = { ...cfg.mcpServers[ name ], disabled: ! enabled };
	if ( enabled ) {
		delete cfg.mcpServers[ name ].disabled;
	}
	await saveConfig( cfg );
	return { ok: true };
}

/**
 * آزمودن یک کانکتور بدون ذخیره‌کردنش — وصل شو، ابزارها را بگیر، قطع کن.
 *
 * @param {any} body
 * @param {string} home
 */
export async function testConnector( body, home ) {
	const { McpManager } = await import( './mcp.js' );
	const { name, config } = normalizeConnector( body );
	const manager = new McpManager();
	try {
		const status = await manager.connectAll( {
			home,
			workspace: home, // فایل پروژه نباید در آزمون دخالت کند.
			servers: { [ name ]: { ...config, disabled: false } },
		} );
		const one = status[ 0 ];
		return one?.status === 'connected'
			? { ok: true, tools: one.tools, message: `وصل شد — ${ one.tools.length } ابزار: ${ one.tools.join( '، ' ) || '(بدون ابزار)' }` }
			: { ok: false, message: one?.error || 'اتصال برقرار نشد.' };
	} finally {
		await manager.close().catch( () => {} );
	}
}
