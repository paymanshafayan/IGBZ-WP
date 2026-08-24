/**
 * هوک‌ها — نقطه‌های اتصال کاربر به چرخهٔ عامل.
 *
 * هر هوک یک فرمان پوسته است که JSON روی stdin می‌گیرد. اگر چیزی روی stdout بنویسد که
 * JSON معتبر باشد، خوانده می‌شود؛ در غیر این صورت متن خام به‌عنوان «کانتکست اضافه» به
 * مدل داده می‌شود.
 *
 * رویدادها (هستهٔ لازم؛ بقیه بعداً روی همین ساختار اضافه می‌شوند):
 *   SessionStart      شروع نشست
 *   UserPromptSubmit  کاربر پیام داد — می‌تواند جلوی ارسال را بگیرد یا کانتکست اضافه کند
 *   PreToolUse        قبل از اجرای ابزار — می‌تواند **جلویش را بگیرد**
 *   PostToolUse       بعد از اجرای موفق ابزار
 *   Stop              نوبت مدل تمام شد
 *   SessionEnd        پایان نشست
 *
 * قرارداد توقف: خروج با کد ۲، یا چاپ {"decision":"block","reason":"…"} یعنی «نه».
 * این همان قراردادی است که ابزارهای این خانواده دارند، پس هوک‌های آماده کار می‌کنند.
 *
 * شکل تنظیمات (در config.json یا hooks.json پلاگین):
 *   { "PreToolUse": [ { "matcher": "bash", "command": "./scripts/audit.sh" } ] }
 */

import { spawn } from 'node:child_process';

export const HOOK_EVENTS = /** @type {const} */ ( [
	'SessionStart',
	'UserPromptSubmit',
	'PreToolUse',
	'PostToolUse',
	'Stop',
	'SessionEnd',
] );

export class HookRunner {
	/**
	 * @param {{hooks?:Record<string,any[]>, workspace:string, emit?:(ev:any)=>void}} opts
	 */
	constructor( opts ) {
		this.hooks = opts.hooks || {};
		this.workspace = opts.workspace;
		this.emit = opts.emit || ( () => {} );
	}

	/** @param {Record<string,any[]>} hooks */
	setHooks( hooks ) {
		this.hooks = hooks || {};
	}

	/**
	 * @param {string} event
	 * @param {any} payload
	 * @returns {Promise<{blocked:boolean, reason?:string, context:string[]}>}
	 */
	async run( event, payload = {} ) {
		const list = this.hooks[ event ] || [];
		/** @type {string[]} */
		const context = [];

		for ( const hook of list ) {
			if ( hook.matcher && ! matches( hook.matcher, payload ) ) {
				continue;
			}
			if ( ! hook.command ) {
				continue;
			}

			this.emit( { type: 'hook', event, command: hook.command } );

			const result = await this.#exec( hook.command, { event, ...payload }, hook.timeout_ms || 30_000 ).catch(
				( e ) => ( { code: 1, stdout: '', stderr: e?.message || String( e ) } )
			);

			// کد ۲ = جلویش را بگیر.
			if ( result.code === 2 ) {
				return { blocked: true, reason: result.stderr.trim() || result.stdout.trim() || 'هوک اجازه نداد.', context };
			}

			const out = result.stdout.trim();
			if ( ! out ) {
				continue;
			}

			try {
				const parsed = JSON.parse( out );
				if ( parsed.decision === 'block' ) {
					return { blocked: true, reason: parsed.reason || 'هوک اجازه نداد.', context };
				}
				if ( parsed.additionalContext ) {
					context.push( String( parsed.additionalContext ) );
				}
			} catch {
				context.push( out.slice( 0, 4000 ) );
			}
		}

		return { blocked: false, context };
	}

	/**
	 * @param {string} command
	 * @param {any} payload
	 * @param {number} timeout
	 */
	#exec( command, payload, timeout ) {
		return new Promise( ( resolve, reject ) => {
			const child = spawn( command, {
				shell: true,
				cwd: this.workspace,
				env: { ...process.env, VIRA_EVENT: payload.event || '' },
			} );

			let stdout = '';
			let stderr = '';
			const timer = setTimeout( () => {
				child.kill( 'SIGKILL' );
				reject( new Error( `هوک بعد از ${ timeout }ms متوقف شد.` ) );
			}, timeout );

			child.stdout.on( 'data', ( d ) => {
				stdout += d.toString();
			} );
			child.stderr.on( 'data', ( d ) => {
				stderr += d.toString();
			} );
			child.on( 'error', ( e ) => {
				clearTimeout( timer );
				reject( e );
			} );
			child.on( 'close', ( code ) => {
				clearTimeout( timer );
				resolve( { code: code ?? 0, stdout, stderr } );
			} );

			try {
				child.stdin.write( JSON.stringify( payload ) );
				child.stdin.end();
			} catch {
				// اگر هوک stdin نخواند، مهم نیست.
			}
		} );
	}
}

/**
 * matcher می‌تواند نام ابزار باشد یا regex ساده.
 * @param {string} matcher
 * @param {any} payload
 */
function matches( matcher, payload ) {
	const subject = String( payload.tool || payload.name || '' );
	if ( matcher === '*' || matcher === subject ) {
		return true;
	}
	try {
		return new RegExp( `^${ matcher }$` ).test( subject );
	} catch {
		return false;
	}
}
