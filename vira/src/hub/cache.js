/**
 * کش پاسخ (بند ۱۰: کش بله، فشرده‌سازی نه).
 *
 * دو قید که عمدی‌اند:
 *
 * ۱) **پاسخی که فراخوانی ابزار دارد کش نمی‌شود.** درخواست یکسان با کانتکست یکسان ممکن است
 *    دنیای بیرون را عوض کند؛ اگر پاسخِ «این فایل را پاک کن» را از کش برگردانیم، ابزار
 *    دوباره اجرا می‌شود بی‌آنکه مدل واقعاً تصمیم گرفته باشد. پس فقط پاسخ متنی خالص.
 *
 * ۲) کلید کش شامل **همهٔ** چیزهایی است که روی خروجی اثر دارند: مدل، پرامپت سیستمی، همهٔ
 *    پیام‌ها، فهرست ابزارها و دما. کلیدِ ناقص یعنی جواب اشتباه — بدترین نوع باگ، چون
 *    بی‌صدا است.
 */

import crypto from 'node:crypto';

export class ResponseCache {
	/**
	 * @param {{ttlMs?:number, max?:number, enabled?:boolean, now?:()=>number}} [opts]
	 */
	constructor( opts = {} ) {
		this.ttlMs = opts.ttlMs ?? 300_000;
		this.max = opts.max ?? 200;
		this.enabled = opts.enabled !== false;
		this.now = opts.now || ( () => Date.now() );
		/** @type {Map<string, {at:number, events:any[]}>} */
		this.entries = new Map();
		this.hits = 0;
		this.misses = 0;
	}

	/**
	 * @param {any} req
	 * @param {string} [modelKey]
	 */
	static keyOf( req, modelKey = '' ) {
		const shape = {
			modelKey,
			model: req?.model || '',
			system: req?.system || '',
			temperature: req?.temperature ?? null,
			maxTokens: req?.maxTokens ?? null,
			tools: ( req?.tools || [] ).map( ( t ) => t.name ).sort(),
			messages: ( req?.messages || [] ).map( ( m ) => ( {
				role: m.role,
				content: typeof m.content === 'string' ? m.content : JSON.stringify( m.content ),
				toolCallId: m.toolCallId || '',
				toolCalls: ( m.toolCalls || [] ).map( ( c ) => `${ c.name }:${ JSON.stringify( c.input ?? {} ) }` ),
			} ) ),
		};
		return crypto.createHash( 'sha256' ).update( JSON.stringify( shape ) ).digest( 'hex' );
	}

	/** @param {string} key */
	get( key ) {
		if ( ! this.enabled ) {
			return null;
		}
		const hit = this.entries.get( key );
		if ( ! hit ) {
			this.misses += 1;
			return null;
		}
		if ( this.now() - hit.at > this.ttlMs ) {
			this.entries.delete( key );
			this.misses += 1;
			return null;
		}
		// تازه‌سازی ترتیب: کلیدی که استفاده می‌شود آخر صف می‌رود تا زودتر حذف نشود.
		this.entries.delete( key );
		this.entries.set( key, hit );
		this.hits += 1;
		return hit.events;
	}

	/**
	 * @param {string} key
	 * @param {any[]} events
	 */
	set( key, events ) {
		if ( ! this.enabled ) {
			return false;
		}
		if ( events.some( ( e ) => e.type === 'tool_call' ) ) {
			return false;
		}
		if ( ! events.some( ( e ) => e.type === 'text' && e.text ) ) {
			return false;
		}
		this.entries.set( key, { at: this.now(), events } );
		while ( this.entries.size > this.max ) {
			const oldest = this.entries.keys().next().value;
			this.entries.delete( oldest );
		}
		return true;
	}

	clear() {
		this.entries.clear();
		this.hits = 0;
		this.misses = 0;
	}

	stats() {
		return { size: this.entries.size, hits: this.hits, misses: this.misses, enabled: this.enabled };
	}
}
