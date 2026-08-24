/**
 * سلامت اتصال‌ها و مدل‌ها: تأخیر، نرخ موفقیت، مدارشکن، و علامت «اعتبار تمام».
 *
 * چرا مدارشکن لازم است: یک پرووایدرِ خوابیده اگر علامت نخورد، در هر نوبت دوباره امتحان
 * می‌شود و هر بار چند ثانیه تأخیر به کاربر تحمیل می‌کند. مدارشکن بعد از چند شکست پشت‌سرهم
 * آن مقصد را برای یک بازه کنار می‌گذارد و بعد **یک** درخواست آزمایشی می‌فرستد (نیمه‌باز).
 *
 * تمام حالت اینجا در حافظه است و با `toJSON`/`fromJSON` روی دیسک می‌رود؛ دلیلش این است
 * که تست بتواند بدون دیسک و بدون زمان واقعی (تزریق `now`) کل رفتار را بسنجد.
 */

const SAMPLE_LIMIT = 50;

export const OPEN = 'open';
export const CLOSED = 'closed';
export const HALF_OPEN = 'half-open';

export class Health {
	/**
	 * @param {{failuresToOpen?:number, cooldownMs?:number, now?:()=>number, state?:any}} [opts]
	 */
	constructor( opts = {} ) {
		this.failuresToOpen = opts.failuresToOpen || 3;
		this.cooldownMs = opts.cooldownMs || 60_000;
		this.now = opts.now || ( () => Date.now() );
		/** @type {Map<string, any>} */
		this.entries = new Map();
		/** ترافیک به تفکیک اتصال — برای ضخامت یال‌های نمای توپولوژی. */
		this.routes = {};
		if ( opts.state ) {
			this.fromJSON( opts.state );
		}
	}

	/** @param {string} key */
	entry( key ) {
		let e = this.entries.get( key );
		if ( ! e ) {
			e = {
				ok: 0,
				fail: 0,
				consecutiveFail: 0,
				samples: [],
				lastError: '',
				lastErrorKind: '',
				openedAt: 0,
				probing: false,
				exhausted: false,
				inFlight: 0,
				lastUsedAt: 0,
				usedToday: 0,
				day: '',
			};
			this.entries.set( key, e );
		}
		return e;
	}

	/**
	 * ثبت یک نتیجه.
	 * @param {string} key
	 * @param {{ok:boolean, ms?:number, kind?:string, message?:string}} result
	 */
	record( key, result ) {
		const e = this.entry( key );
		const connId = String( key ).split( '::' )[ 0 ];
		this.routes[ connId ] = ( this.routes[ connId ] || 0 ) + 1;
		const day = new Date( this.now() ).toISOString().slice( 0, 10 );
		if ( e.day !== day ) {
			e.day = day;
			e.usedToday = 0;
		}
		e.usedToday += 1;
		e.lastUsedAt = this.now();
		// نتیجهٔ **آخرین** تلاش — مبنای حالت «error» در یال‌های توپولوژی.
		e.lastOk = Boolean( result.ok );

		if ( result.ok ) {
			e.ok += 1;
			e.consecutiveFail = 0;
			e.openedAt = 0;
			e.probing = false;
			// موفقیت یعنی سرویس برگشته؛ اگر قبلاً «خالی» علامت خورده بود، برداشته می‌شود.
			e.exhausted = false;
			if ( typeof result.ms === 'number' ) {
				e.samples.push( result.ms );
				if ( e.samples.length > SAMPLE_LIMIT ) {
					e.samples.shift();
				}
			}
			return e;
		}

		e.fail += 1;
		e.consecutiveFail += 1;
		e.lastError = String( result.message || '' ).slice( 0, 300 );
		e.lastErrorKind = String( result.kind || '' );

		// پایان اعتبار خطا نیست، یک واقعیت است: اتصال «خالی» علامت می‌خورد و از دور خارج
		// می‌شود، ولی این با «خراب است» فرق دارد و عیب‌یاب نباید صدا زده شود.
		if ( result.kind === 'credit' ) {
			e.exhausted = true;
			e.openedAt = this.now();
			return e;
		}

		if ( e.consecutiveFail >= this.failuresToOpen || e.probing ) {
			e.openedAt = this.now();
			e.probing = false;
		}
		return e;
	}

	/**
	 * وضعیت مدار.
	 * @param {string} key
	 * @returns {'closed'|'open'|'half-open'}
	 */
	circuit( key ) {
		const e = this.entries.get( key );
		if ( ! e || ! e.openedAt ) {
			return CLOSED;
		}
		if ( this.now() - e.openedAt >= this.cooldownMs ) {
			return HALF_OPEN;
		}
		return OPEN;
	}

	/**
	 * آیا می‌شود الان سراغ این مقصد رفت؟
	 * @param {string} key
	 */
	available( key ) {
		const e = this.entries.get( key );
		if ( ! e ) {
			return true;
		}
		const c = this.circuit( key );
		if ( c === OPEN ) {
			return false;
		}
		if ( e.exhausted && c !== HALF_OPEN ) {
			return false;
		}
		if ( e.inFlight >= ( e.maxConcurrent || Infinity ) ) {
			return false;
		}
		return true;
	}

	/** یک درخواست آزمایشی در حالت نیمه‌باز رفت. */
	markProbe( key ) {
		const e = this.entry( key );
		e.probing = true;
		e.openedAt = 0;
		return e;
	}

	/** دستی: مدیر دکمهٔ «بازکردن دوباره» را زد. */
	reset( key ) {
		const e = this.entry( key );
		e.consecutiveFail = 0;
		e.openedAt = 0;
		e.probing = false;
		e.exhausted = false;
		return e;
	}

	/**
	 * ریست در سطح اتصال — «ریست و رفع خطا»ی مدیر: مدارشکن‌ها، خطاها، آمار و
	 * پرچم «اعتبار تمام» همهٔ مدل‌های آن اتصال پاک می‌شود.
	 * @param {string} prefix
	 */
	resetPrefix( prefix ) {
		let n = 0;
		for ( const key of [ ...this.entries.keys() ] ) {
			if ( key.startsWith( prefix ) ) {
				this.entries.delete( key );
				n += 1;
			}
		}
		return n;
	}

	/** ریست کل سلامت — همهٔ مدارشکن‌ها، خطاها، آمار و شمارندهٔ ترافیک. */
	resetAll() {
		this.entries.clear();
		this.routes = {};
	}

	/** ترافیک به تفکیک اتصال (شمار تلاش‌های ثبت‌شده). */
	traffic() {
		return { ...this.routes };
	}

	/** @param {string} key */
	successRate( key ) {
		const e = this.entries.get( key );
		if ( ! e || e.ok + e.fail === 0 ) {
			// بدون سابقه، خوش‌بین نه بدبین — وگرنه مدل تازه هیچ‌وقت شانس نمی‌آورد.
			return 0.8;
		}
		return e.ok / ( e.ok + e.fail );
	}

	/**
	 * @param {string} key
	 * @param {number} p صدک بین ۰ و ۱
	 */
	latency( key, p = 0.95 ) {
		const e = this.entries.get( key );
		if ( ! e || ! e.samples.length ) {
			return null;
		}
		const sorted = [ ...e.samples ].sort( ( a, b ) => a - b );
		const idx = Math.min( sorted.length - 1, Math.max( 0, Math.ceil( p * sorted.length ) - 1 ) );
		return sorted[ idx ];
	}

	/** @param {string} key */
	begin( key ) {
		const e = this.entry( key );
		e.inFlight = ( e.inFlight || 0 ) + 1;
		return e;
	}

	/** @param {string} key */
	end( key ) {
		const e = this.entry( key );
		e.inFlight = Math.max( 0, ( e.inFlight || 0 ) - 1 );
		return e;
	}

	/** تصویر خلاصه برای صفحهٔ سلامت. */
	snapshot() {
		/** @type {Record<string, any>} */
		const out = {};
		for ( const [ key, e ] of this.entries ) {
			out[ key ] = {
				ok: e.ok,
				fail: e.fail,
				successRate: Math.round( this.successRate( key ) * 100 ) / 100,
				p50: this.latency( key, 0.5 ),
				p95: this.latency( key, 0.95 ),
				circuit: this.circuit( key ),
				exhausted: Boolean( e.exhausted ),
				lastError: e.lastError,
				lastErrorKind: e.lastErrorKind,
				usedToday: e.usedToday,
				/*
				 * این دو برای یال‌های سه‌حالتهٔ توپولوژی لازم‌اند (active/recent/error).
				 * `lastUsedAt` از قبل نگه داشته می‌شد ولی بیرون داده نمی‌شد؛ و `lastOk`
				 * لازم است چون «آخرین نتیجه خطا بود» با `fail > 0` قابل تشخیص نیست —
				 * یک شکستِ قدیمی که بعدش ده موفقیت آمده، خطا نیست.
				 */
				lastUsedAt: e.lastUsedAt || 0,
				lastOk: e.lastOk !== false,
			};
		}
		return out;
	}

	toJSON() {
		const out = Object.fromEntries( [ ...this.entries ].map( ( [ k, v ] ) => [ k, { ...v, inFlight: 0 } ] ) );
		// شمارندهٔ ترافیک کنار ورودی‌ها با کلید رزروشده ذخیره می‌شود؛ کلیدهای واقعی همیشه «::» دارند.
		out.__routes = { ...this.routes };
		return out;
	}

	/** @param {any} data */
	fromJSON( data ) {
		const saved = { ...( data || {} ) };
		if ( saved.__routes ) {
			this.routes = saved.__routes;
			delete saved.__routes;
		}
		for ( const [ k, v ] of Object.entries( saved ) ) {
			this.entries.set( k, { ...this.entry( k ), ...v, inFlight: 0 } );
		}
		return this;
	}
}
