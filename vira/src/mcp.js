/**
 * کلاینت MCP — اتصال به سرورهای بیرونی و آوردن ابزارهایشان داخل ویرا.
 *
 * از SDK رسمی استفاده می‌کنیم (تصمیم کارفرما). دلیلش ساده است: MCP یک پروتکل زنده است و
 * پیاده‌سازی دستی‌اش یعنی هر بار که پروتکل تکان بخورد، ما عقب بمانیم.
 *
 * تنظیمات، دو جا خوانده می‌شود و با هم ادغام می‌شوند:
 *   ~/.vira/config.json  → کلید mcpServers   (سراسری)
 *   <workspace>/.vira/mcp.json               (مخصوص پروژه)
 *
 * شکل تنظیمات همان چیزی است که بقیهٔ ابزارها استفاده می‌کنند، پس یک فایل آماده را
 * می‌شود مستقیم کپی کرد:
 *
 *   { "mcpServers": {
 *       "files":  { "command": "npx", "args": ["-y","@modelcontextprotocol/server-filesystem","/tmp"] },
 *       "remote": { "url": "https://example.com/mcp", "headers": { "Authorization": "Bearer …" } }
 *   } }
 *
 * ابزارهای هر سرور با نام `mcp__<server>__<tool>` ثبت می‌شوند تا با ابزارهای داخلی
 * قاطی نشوند و بشود در قواعد مجوز جداگانه نشانه‌شان گرفت.
 */

import fs from 'node:fs/promises';
import path from 'node:path';

/** @typedef {{name:string, status:'connected'|'failed'|'disabled', error?:string, tools:string[], prompts?:{name:string,description:string}[], resources?:{uri:string,name:string}[]}} McpStatus */

export class McpManager {
	constructor() {
		/** @type {Map<string, any>} */
		this.clients = new Map();
		/** @type {Map<string, {server:string, toolName:string, spec:any}>} */
		this.tools = new Map();
		/** @type {McpStatus[]} */
		this.status = [];
	}

	/**
	 * @param {{home:string, workspace:string, servers?:Record<string,any>}} opts
	 */
	async connectAll( { home, workspace, servers = {} } ) {
		await this.close();

		/** @type {Record<string,any>} */
		const merged = { ...servers };

		// فایل مخصوص پروژه، روی تنظیمات سراسری می‌نشیند.
		try {
			const raw = await fs.readFile( path.join( workspace, '.vira', 'mcp.json' ), 'utf8' );
			const parsed = JSON.parse( raw );
			Object.assign( merged, parsed.mcpServers || parsed );
		} catch {
			// نبودنش عادی است.
		}

		this.status = [];
		for ( const [ name, cfg ] of Object.entries( merged ) ) {
			if ( cfg?.disabled ) {
				this.status.push( { name, status: 'disabled', tools: [] } );
				continue;
			}
			try {
				await this.#connectOne( name, cfg, home );
			} catch ( e ) {
				this.status.push( { name, status: 'failed', error: e?.message || String( e ), tools: [] } );
			}
		}

		return this.status;
	}

	/**
	 * @param {string} name
	 * @param {any} cfg
	 * @param {string} home
	 */
	async #connectOne( name, cfg, home ) {
		const { Client } = await import( '@modelcontextprotocol/sdk/client/index.js' );

		let transport;
		if ( cfg.url ) {
			const { StreamableHTTPClientTransport } = await import(
				'@modelcontextprotocol/sdk/client/streamableHttp.js'
			);
			transport = new StreamableHTTPClientTransport( new URL( cfg.url ), {
				requestInit: cfg.headers ? { headers: cfg.headers } : undefined,
			} );
		} else if ( cfg.command ) {
			const { StdioClientTransport, getDefaultEnvironment } = await import(
				'@modelcontextprotocol/sdk/client/stdio.js'
			);
			transport = new StdioClientTransport( {
				command: cfg.command,
				args: cfg.args || [],
				cwd: cfg.cwd || home,
				env: { ...getDefaultEnvironment(), ...( cfg.env || {} ) },
				stderr: 'ignore',
			} );
		} else {
			throw new Error( 'تنظیمات سرور باید یا command داشته باشد یا url.' );
		}

		const client = new Client( { name: 'vira', version: '0.2.0' }, { capabilities: {} } );

		// یک سرور خراب نباید بالا آمدن برنامه را نگه دارد.
		await withTimeout( client.connect( transport ), cfg.timeout || 20_000, `اتصال به «${ name }» طول کشید` );

		const listed = await withTimeout( client.listTools(), 15_000, `فهرست ابزار «${ name }» نیامد` );
		const names = [];

		for ( const t of listed.tools || [] ) {
			const full = `mcp__${ name }__${ t.name }`;
			this.tools.set( full, {
				server: name,
				toolName: t.name,
				spec: {
					name: full,
					description: `[MCP:${ name }] ${ t.description || t.name }`,
					parameters: t.inputSchema || { type: 'object', properties: {} },
				},
			} );
			names.push( t.name );
		}

		// پرامپت‌ها و منابع، بخش‌های کم‌استفاده‌ترِ MCP اند و خیلی سرورها اصلاً ندارند؛
		// پس نبودنشان خطا نیست و ساکت رد می‌شود.
		const prompts = await withTimeout( client.listPrompts(), 8000, 'پرامپت‌ها' )
			.then( ( r ) => ( r.prompts || [] ).map( ( p ) => ( { name: p.name, description: p.description || '' } ) ) )
			.catch( () => [] );

		const resources = await withTimeout( client.listResources(), 8000, 'منابع' )
			.then( ( r ) => ( r.resources || [] ).map( ( x ) => ( { uri: x.uri, name: x.name || x.uri } ) ) )
			.catch( () => [] );

		this.clients.set( name, client );
		this.status.push( { name, status: 'connected', tools: names, prompts, resources } );
	}

	/** همهٔ پرامپت‌های همهٔ سرورها، با نام یکتا. */
	promptEntries() {
		/** @type {{name:string, server:string, prompt:string, description:string}[]} */
		const out = [];
		for ( const s of this.status ) {
			for ( const p of s.prompts || [] ) {
				out.push( { name: `mcp__${ s.name }__${ p.name }`, server: s.name, prompt: p.name, description: p.description } );
			}
		}
		return out;
	}

	/** همهٔ منابع همهٔ سرورها. */
	resourceEntries() {
		/** @type {{server:string, uri:string, name:string}[]} */
		const out = [];
		for ( const s of this.status ) {
			for ( const r of s.resources || [] ) {
				out.push( { server: s.name, uri: r.uri, name: r.name } );
			}
		}
		return out;
	}

	/**
	 * گرفتن متن یک پرامپت MCP تا به‌عنوان پیام کاربر فرستاده شود.
	 * @param {string} server
	 * @param {string} prompt
	 * @param {Record<string,any>} [args]
	 */
	async getPrompt( server, prompt, args ) {
		const client = this.clients.get( server );
		if ( ! client ) {
			throw new Error( `سرور MCP «${ server }» وصل نیست.` );
		}
		const res = await withTimeout(
			client.getPrompt( { name: prompt, arguments: args || {} } ),
			30_000,
			`پرامپت «${ prompt }» نیامد`
		);
		return ( res.messages || [] )
			.map( ( m ) => {
				const c = m.content;
				if ( Array.isArray( c ) ) {
					return c.map( ( x ) => x.text || '' ).join( '\n' );
				}
				return c?.text || '';
			} )
			.filter( Boolean )
			.join( '\n\n' );
	}

	/**
	 * خواندن یک منبع MCP.
	 * @param {string} server
	 * @param {string} uri
	 */
	async readResource( server, uri ) {
		const client = this.clients.get( server );
		if ( ! client ) {
			throw new Error( `سرور MCP «${ server }» وصل نیست.` );
		}
		const res = await withTimeout( client.readResource( { uri } ), 30_000, `منبع «${ uri }» نیامد` );
		return ( res.contents || [] )
			.map( ( c ) => c.text || `[${ c.mimeType || 'باینری' }: ${ c.uri }]` )
			.join( '\n' )
			.trim();
	}

	/** ابزارهای MCP به شکل رجیستری داخلی ویرا. */
	toolEntries() {
		/** @type {Record<string, any>} */
		const out = {};
		for ( const [ full, entry ] of this.tools ) {
			out[ full ] = {
				// ابزار بیرونی را نمی‌شناسیم، پس محتاطانه «اجرا» حسابش می‌کنیم تا در حالت
				// عادی تأیید بخواهد. اگر به سروری اعتماد داری، در allow بگذارش.
				risk: 'exec',
				spec: entry.spec,
				run: async ( input ) => this.call( full, input ),
			};
		}
		return out;
	}

	/**
	 * @param {string} fullName
	 * @param {any} input
	 */
	async call( fullName, input ) {
		const entry = this.tools.get( fullName );
		if ( ! entry ) {
			throw new Error( `ابزار MCP ناشناخته: ${ fullName }` );
		}
		const client = this.clients.get( entry.server );
		if ( ! client ) {
			throw new Error( `سرور MCP «${ entry.server }» وصل نیست.` );
		}

		const res = await withTimeout(
			client.callTool( { name: entry.toolName, arguments: input || {} } ),
			120_000,
			`ابزار «${ fullName }» پاسخ نداد`
		);

		if ( res.isError ) {
			throw new Error( textOf( res ) || 'ابزار MCP خطا داد.' );
		}
		return textOf( res ) || '(بدون خروجی متنی)';
	}

	async close() {
		for ( const [ , client ] of this.clients ) {
			try {
				await client.close();
			} catch {
				// در حال خاموش‌شدن، خطا مهم نیست.
			}
		}
		this.clients.clear();
		this.tools.clear();
	}
}

/** @param {any} res */
function textOf( res ) {
	const parts = Array.isArray( res?.content ) ? res.content : [];
	return parts
		.map( ( c ) => {
			if ( c.type === 'text' ) {
				return c.text;
			}
			if ( c.type === 'resource' ) {
				return c.resource?.text || `[منبع: ${ c.resource?.uri || '' }]`;
			}
			return `[${ c.type }]`;
		} )
		.join( '\n' )
		.trim();
}

/**
 * @template T
 * @param {Promise<T>} p
 * @param {number} ms
 * @param {string} message
 */
function withTimeout( p, ms, message ) {
	/** @type {NodeJS.Timeout} */
	let timer;
	const guard = new Promise( ( _, reject ) => {
		timer = setTimeout( () => reject( new Error( `${ message } (${ ms }ms)` ) ), ms );
		// تایمرِ پاک‌نشده، پروسه را زنده نگه می‌دارد؛ این یک بار باعث شد سوئیت تست دو دقیقه
		// بعد از پایانِ واقعی کارش تمام شود.
		timer.unref?.();
	} );

	return Promise.race( [ p, guard ] ).finally( () => clearTimeout( timer ) );
}
