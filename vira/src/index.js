/**
 * API برنامه‌نویسی ویرا — برای وقتی که کسی می‌خواهد ویرا را **داخل** برنامهٔ خودش اجرا کند،
 * نه از راه رابط کاربری.
 *
 * دو سطح:
 *
 *   query()      یک پرسش، یک جواب. ساده‌ترین حالت (همان چیزی که حالت headless می‌سازد).
 *   createVira() یک نشست زنده با کنترل کامل: رویدادها، مجوز، ابزار سفارشی.
 *
 * نمونه:
 *
 *   import { query } from 'vira';
 *   const out = await query( { prompt: 'تست‌ها را اجرا کن', workspace: '.', mode: 'auto' } );
 *   console.log( out.text );
 *
 *   import { createVira } from 'vira';
 *   const h = await createVira( { workspace: '.', onEvent: ( e ) => console.log( e.type ) } );
 *   h.onPermission( async ( req ) => req.name === 'read_file' );
 *   await h.send( 'این پروژه چه می‌کند؟' );
 */

import { loadConfig, saveConfig, HOME } from './config.js';
import { Runtime } from './runtime.js';
import { textOf } from './content.js';
import { MODES } from './permissions.js';

/**
 * @typedef {Object} ViraOptions
 * @property {string} [workspace]
 * @property {'plan'|'default'|'auto'} [mode]
 * @property {string[]} [allowedTools]  فقط این ابزارها در دسترس مدل باشند
 * @property {string[]} [allow]         قواعد مجوز اضافه
 * @property {string[]} [deny]
 * @property {number} [maxTurns]
 * @property {string} [model]
 * @property {(ev:any)=>void} [onEvent]
 */

/**
 * یک نشست زنده.
 * @param {ViraOptions} [options]
 */
export async function createVira( options = {} ) {
	const cfg = await loadConfig();
	if ( options.workspace ) {
		cfg.workspace = options.workspace;
	}
	if ( options.mode && MODES.includes( options.mode ) ) {
		cfg.permissions.mode = options.mode;
	}
	if ( options.allow?.length ) {
		cfg.permissions.allow = [ ...new Set( [ ...( cfg.permissions.allow || [] ), ...options.allow ] ) ];
	}
	if ( options.deny?.length ) {
		cfg.permissions.deny = [ ...new Set( [ ...( cfg.permissions.deny || [] ), ...options.deny ] ) ];
	}
	if ( options.model && cfg.profiles?.[ cfg.activeProfile ] ) {
		cfg.profiles[ cfg.activeProfile ].model = options.model;
	}

	/** @type {((ev:any)=>void)[]} */
	const listeners = [];
	if ( options.onEvent ) {
		listeners.push( options.onEvent );
	}

	/** @type {((req:any)=>Promise<boolean>|boolean)|null} */
	let permissionHandler = null;
	/** @type {((req:any)=>Promise<any>|any)|null} */
	let questionHandler = null;

	const emit = ( ev ) => {
		// دروازهٔ تأیید: اگر برنامهٔ میزبان تصمیم‌گیر داده، جواب می‌دهیم؛ وگرنه رد.
		if ( ev.type === 'permission_request' ) {
			Promise.resolve( permissionHandler ? permissionHandler( ev ) : false )
				.catch( () => false )
				.then( ( ok ) => runtime.agent?.resolvePermission( ev.id, ok ? 'allow' : 'deny' ) );
		}
		if ( ev.type === 'ask_user' ) {
			Promise.resolve( questionHandler ? questionHandler( ev ) : null )
				.catch( () => null )
				.then( ( value ) => runtime.agent?.resolveQuestion( ev.id, value ) );
		}
		for ( const fn of listeners ) {
			try {
				fn( ev );
			} catch {
				// شنوندهٔ میزبان نباید عامل را بخواباند.
			}
		}
	};

	const runtime = new Runtime( emit );
	// تنظیمات را روی دیسک ننویس مگر لازم باشد؛ فقط همین نشست را با آن بساز.
	runtime.config = cfg;
	await runtime.reload();
	runtime.config = cfg;
	if ( runtime.agent ) {
		runtime.agent.rules = cfg.permissions;
		runtime.agent.workspace = cfg.workspace;
	}
	await runtime.loadProjectMemory();

	if ( options.allowedTools?.length ) {
		const allowed = new Set( options.allowedTools );
		const original = runtime.tools.bind( runtime );
		runtime.tools = ( depth, only ) => {
			const all = original( depth, only );
			/** @type {Record<string,any>} */
			const picked = {};
			for ( const [ name, tool ] of Object.entries( all ) ) {
				if ( allowed.has( name ) ) {
					picked[ name ] = tool;
				}
			}
			return picked;
		};
	}

	if ( options.maxTurns && runtime.agent ) {
		runtime.agent.maxSteps = options.maxTurns;
	}

	return {
		runtime,
		get ready() {
			return runtime.ready;
		},
		get messages() {
			return runtime.agent?.messages || [];
		},
		get usage() {
			return runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 };
		},
		/** @param {(ev:any)=>void} fn */
		on( fn ) {
			listeners.push( fn );
			return () => listeners.splice( listeners.indexOf( fn ), 1 );
		},
		/** @param {(req:any)=>Promise<boolean>|boolean} fn */
		onPermission( fn ) {
			permissionHandler = fn;
		},
		/** @param {(req:any)=>Promise<any>|any} fn */
		onQuestion( fn ) {
			questionHandler = fn;
		},
		/**
		 * @param {string} text
		 * @param {{images?:any[]}} [opts]
		 */
		async send( text, opts ) {
			if ( ! runtime.ready.ok ) {
				throw new Error( `تنظیمات ناقص است: ${ runtime.ready.missing.join( '، ' ) }` );
			}
			await runtime.agent.run( text, opts );
			return lastAssistantText( runtime.agent.messages );
		},
		stop() {
			runtime.agent?.stop();
		},
		async close() {
			await runtime.close();
		},
	};
}

/**
 * یک پرسش، یک جواب — بدون سرور و بدون رابط.
 *
 * @param {ViraOptions & {prompt:string, images?:any[]}} options
 * @returns {Promise<{text:string, usage:any, messages:any[], events:any[]}>}
 */
export async function query( options ) {
	/** @type {any[]} */
	const events = [];
	const h = await createVira( { ...options, onEvent: ( e ) => events.push( e ) } );

	// در حالت بدون‌رابط، «پرسیدن» معنا ندارد: هرچه تأیید بخواهد رد می‌شود مگر حالت auto
	// یا قاعدهٔ allow اجازه داده باشد. این رفتار عمدی است — یک اسکریپت خودکار نباید
	// بی‌سروصدا اجازهٔ کار خطرناک بگیرد.
	h.onPermission( () => false );
	h.onQuestion( () => null );

	try {
		const text = await h.send( options.prompt, { images: options.images } );
		return { text, usage: h.usage, messages: h.messages, events };
	} finally {
		await h.close();
	}
}

/** @param {any[]} messages */
export function lastAssistantText( messages ) {
	const last = [ ...( messages || [] ) ].reverse().find( ( m ) => m.role === 'assistant' && textOf( m.content ).trim() );
	return textOf( last?.content || '' ).trim();
}

export { HOME, loadConfig, saveConfig };
export { startServer } from './server.js';
