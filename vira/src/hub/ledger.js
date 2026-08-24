/**
 * دفتر راه‌حل‌ها — پلهٔ اول نردبان و حافظهٔ بلندمدت عیب‌یاب.
 *
 * قاعدهٔ سختی که سند گذاشت و اینجا در کد اجباری شده است: **راه‌حل بدون آزمون ثبت نمی‌شود.**
 * `remember()` بدون `verified: true` چیزی ذخیره نمی‌کند و صریحاً برمی‌گرداند که ذخیره نکرد.
 * «فکر می‌کنم مشکل این است» ارزش ذخیره ندارد؛ اگر ذخیره شود، دفعهٔ بعد یک حدسِ غلط را
 * به‌عنوان دانش قطعی روی درخواست کاربر اعمال می‌کنیم.
 *
 * `domain` از روز اول اینجاست چون بند ۱۵ گفت موتور باید عمومی باشد: امروز فقط هاب،
 * فردا درگاه پرداخت و پیامک و صرافی — بدون بازنویسی.
 */

export const TEMPORARY = 'temporary';
export const PERMANENT = 'permanent';

export class Ledger {
	/**
	 * @param {{data?:any, now?:()=>number}} [opts]
	 */
	constructor( opts = {} ) {
		this.now = opts.now || ( () => Date.now() );
		/** @type {Record<string, any>} */
		this.entries = { ...( opts.data?.entries || {} ) };
		this.dirty = false;
	}

	/**
	 * @param {string} signature
	 * @param {string} [domain]
	 */
	lookup( signature, domain = 'hub' ) {
		const e = this.entries[ signature ];
		if ( ! e || e.domain !== domain ) {
			return null;
		}
		return e;
	}

	/**
	 * ثبت یک راه‌حل **آزموده‌شده**.
	 *
	 * @param {{signature:string, patches:any[], why?:string, origin?:string, domain?:string, connectionId?:string, verified?:boolean}} entry
	 * @returns {{stored:boolean, reason?:string, entry?:any}}
	 */
	remember( entry ) {
		if ( ! entry?.verified ) {
			return { stored: false, reason: 'وصلهٔ آزمون‌نداده ثبت نمی‌شود.' };
		}
		if ( ! entry.signature || ! Array.isArray( entry.patches ) || ! entry.patches.length ) {
			return { stored: false, reason: 'امضا یا وصله ناقص است.' };
		}

		const previous = this.entries[ entry.signature ];
		const stored = {
			signature: entry.signature,
			domain: entry.domain || 'hub',
			// کدام اتصال؟ بدون این، «ماندگارکردن» نمی‌داند وصله را کجا بچسباند.
			connectionId: entry.connectionId || previous?.connectionId || '',
			patches: entry.patches,
			why: String( entry.why || previous?.why || '' ).slice( 0, 300 ),
			origin: entry.origin || previous?.origin || 'rule',
			discovered: previous?.discovered || new Date( this.now() ).toISOString(),
			lastUsed: new Date( this.now() ).toISOString(),
			ok: ( previous?.ok || 0 ) + 1,
			fail: previous?.fail || 0,
			// بند ۱۳: وصله موقت ثبت می‌شود؛ ماندگارشدنش تأیید مدیر می‌خواهد.
			state: previous?.state === PERMANENT ? PERMANENT : TEMPORARY,
		};
		this.entries[ entry.signature ] = stored;
		this.dirty = true;
		return { stored: true, entry: stored };
	}

	/**
	 * نتیجهٔ استفادهٔ دوباره از یک وصلهٔ ثبت‌شده.
	 *
	 * @param {string} signature
	 * @param {boolean} ok
	 */
	hit( signature, ok ) {
		const e = this.entries[ signature ];
		if ( ! e ) {
			return null;
		}
		e.lastUsed = new Date( this.now() ).toISOString();
		if ( ok ) {
			e.ok += 1;
		} else {
			e.fail += 1;
			// وصله‌ای که دیگر جواب نمی‌دهد، دانش نیست. سه شکست پشت سر هم = فراموشی.
			if ( e.fail >= 3 && e.state !== PERMANENT ) {
				delete this.entries[ signature ];
			}
		}
		this.dirty = true;
		return e;
	}

	/** تأیید مدیر: این وصله ماندگار شود. */
	promote( signature ) {
		const e = this.entries[ signature ];
		if ( ! e ) {
			return null;
		}
		e.state = PERMANENT;
		this.dirty = true;
		return e;
	}

	/** مدیر می‌گوید این را یاد نگرفته باش. */
	forget( signature ) {
		const existed = Boolean( this.entries[ signature ] );
		delete this.entries[ signature ];
		this.dirty = true;
		return existed;
	}

	/** @param {string} [domain] */
	list( domain ) {
		return Object.values( this.entries )
			.filter( ( e ) => ! domain || e.domain === domain )
			.sort( ( a, b ) => ( a.lastUsed < b.lastUsed ? 1 : -1 ) );
	}

	toJSON() {
		return { entries: this.entries };
	}
}
