/**
 * عیب‌یاب — نردبان چهارپلهٔ بند ۶.
 *
 *   ۱ دفتر راه‌حل‌ها  → صفر هزینه
 *   ۲ تعمیر قاعده‌ای  → صفر هزینه
 *   ۳ مدل عیب‌یاب     → یک تماس، فقط وقتی پله‌های قبل جواب ندادند
 *   ۴ تأیید           → درخواست با وصله دوباره اجرا می‌شود؛ فقط اگر جواب داد ثبت می‌شود
 *
 * پلهٔ چهارم اینجا نیست، در `hub/index.js` است — چون تنها جایی که می‌شود واقعاً آزمود،
 * همان‌جایی است که درخواست اصلی اجرا می‌شود. این کلاس فقط **پیشنهاد** می‌دهد و بعد
 * نتیجه را می‌شنود.
 *
 * چهار قیدی که در سند آمد، اینجا کد شده‌اند:
 *   ۱) وصله شیء ساختاریافته است  → هر خروجی مدل از `validatePatch` رد می‌شود.
 *   ۲) عیب‌یاب مدارشکن خودش را دارد → `minFailures`، سقف هر امضا در هر بازه، بودجهٔ جدا.
 *   ۳) بدون آزمون ثبت نمی‌شود      → `report()` فقط با `ok:true` به دفتر می‌رود.
 *   ۴) دفتر دیدنی و برگشت‌پذیر است → `Ledger.list/forget/promote`.
 */

import { rulePatch, validatePatch, PATCH_OPS } from './repair.js';
import { sanitize } from './signature.js';

const HOUR = 3_600_000;

export class Diagnoser {
	/**
	 * @param {{
	 *   ledger: import('./ledger.js').Ledger,
	 *   config?: any,
	 *   now?: () => number,
	 *   callModel?: (prompt:string) => Promise<string>,
	 *   fetchDocs?: (query:string) => Promise<string>,
	 *   log?: (entry:any) => void,
	 * }} opts
	 */
	constructor( opts ) {
		this.ledger = opts.ledger;
		this.config = {
			enabled: true,
			minFailures: 2,
			perSignaturePerHour: 1,
			dailyBudget: null,
			internet: false,
			...( opts.config || {} ),
		};
		this.now = opts.now || ( () => Date.now() );
		this.callModel = opts.callModel || null;
		this.fetchDocs = opts.fetchDocs || null;
		this.log = opts.log || ( () => {} );

		/** @type {Map<string, {count:number, lastModelAt:number, calls:number[]}>} */
		this.seen = new Map();
		this.spentToday = 0;
		this.day = new Date( this.now() ).toISOString().slice( 0, 10 );
		/** @type {any[]} */
		this.journal = [];
	}

	/** @param {any} config */
	setConfig( config ) {
		this.config = { ...this.config, ...( config || {} ) };
		return this.config;
	}

	#track( signature ) {
		let s = this.seen.get( signature );
		if ( ! s ) {
			s = { count: 0, lastModelAt: 0, calls: [] };
			this.seen.set( signature, s );
		}
		return s;
	}

	#rolloverBudget() {
		const day = new Date( this.now() ).toISOString().slice( 0, 10 );
		if ( day !== this.day ) {
			this.day = day;
			this.spentToday = 0;
		}
	}

	/**
	 * آیا اجازه داریم برای این امضا مدل را صدا بزنیم؟
	 *
	 * این تابع، همان «مدارشکن عیب‌یاب» است: صد خطای هم‌امضا نباید صد تماس بسازد.
	 *
	 * @param {string} signature
	 * @returns {{allowed:boolean, reason:string}}
	 */
	canAskModel( signature ) {
		this.#rolloverBudget();
		if ( ! this.config.enabled ) {
			return { allowed: false, reason: 'عیب‌یاب خاموش است.' };
		}
		if ( ! this.callModel ) {
			return { allowed: false, reason: 'مدل عیب‌یاب تنظیم نشده است.' };
		}
		const s = this.#track( signature );
		if ( s.count < ( this.config.minFailures || 1 ) ) {
			return { allowed: false, reason: 'هنوز به آستانهٔ شکست‌های هم‌امضا نرسیده‌ایم.' };
		}
		const window = s.calls.filter( ( t ) => this.now() - t < HOUR );
		if ( window.length >= ( this.config.perSignaturePerHour || 1 ) ) {
			return { allowed: false, reason: 'سقف تماس برای این امضا در این ساعت پر شده است.' };
		}
		const cap = Number( this.config.dailyBudget );
		if ( Number.isFinite( cap ) && cap > 0 && this.spentToday >= cap ) {
			return { allowed: false, reason: 'بودجهٔ روزانهٔ عیب‌یاب تمام شده است.' };
		}
		return { allowed: true, reason: '' };
	}

	/**
	 * پیشنهاد وصله. سه پله را به ترتیب می‌رود و اولین جوابی که پیدا کرد برمی‌گرداند.
	 *
	 * @param {{
	 *   signature: string,
	 *   error: {status?:number, message?:string, kind?:string},
	 *   cfg: {baseUrl?:string, kind?:string, authStyle?:string, applied?:any[]},
	 *   shape?: any,
	 *   domain?: string,
	 * }} input
	 * @returns {Promise<{source:string, patches:any[], why:string}|null>}
	 */
	async suggest( input ) {
		const domain = input.domain || 'hub';
		const signature = input.signature;

		// پایان اعتبار خطا نیست. اینجا کار مسیریاب است، نه عیب‌یاب.
		if ( input.error?.kind === 'credit' ) {
			this.#note( { signature, step: 'skip', why: 'پایان اعتبار — عیب‌یاب دخالت نمی‌کند.' } );
			return null;
		}

		this.#track( signature ).count += 1;

		// پلهٔ ۱ — دفتر.
		const known = this.ledger?.lookup( signature, domain );
		if ( known ) {
			this.#note( { signature, step: 'ledger', why: known.why } );
			return { source: 'ledger', patches: known.patches, why: known.why || 'راه‌حل ثبت‌شده از دفعهٔ قبل.' };
		}

		// پلهٔ ۲ — قاعده.
		const rule = rulePatch( input.error || {}, input.cfg || {} );
		if ( rule ) {
			const check = validatePatch( rule.patch, { baseUrl: input.cfg?.baseUrl } );
			if ( check.ok ) {
				this.#note( { signature, step: 'rule', why: rule.why } );
				return { source: 'rule', patches: [ check.patch ], why: rule.why };
			}
			this.#note( { signature, step: 'rule-rejected', why: check.reason } );
		}

		// پلهٔ ۳ — مدل.
		const gate = this.canAskModel( signature );
		if ( ! gate.allowed ) {
			this.#note( { signature, step: 'model-skipped', why: gate.reason } );
			return null;
		}

		const s = this.#track( signature );
		s.calls.push( this.now() );
		s.lastModelAt = this.now();
		this.spentToday += 1;

		let answer = '';
		try {
			answer = await this.callModel( await this.#prompt( input ) );
		} catch ( e ) {
			this.#note( { signature, step: 'model-failed', why: String( e?.message || e ).slice( 0, 200 ) } );
			return null;
		}

		const parsed = parsePatches( answer );
		/** @type {any[]} */
		const patches = [];
		for ( const p of parsed ) {
			const check = validatePatch( p, { baseUrl: input.cfg?.baseUrl } );
			if ( check.ok ) {
				patches.push( check.patch );
			} else {
				this.#note( { signature, step: 'model-rejected', why: check.reason } );
			}
		}
		if ( ! patches.length ) {
			return null;
		}
		this.#note( { signature, step: 'model', why: 'مدل وصله پیشنهاد داد.' } );
		return { source: 'model', patches, why: 'پیشنهاد مدل عیب‌یاب.' };
	}

	/**
	 * پلهٔ ۴ — نتیجهٔ آزمون.
	 *
	 * @param {{signature:string, source:string, patches:any[], why?:string, ok:boolean, domain?:string, connectionId?:string}} r
	 */
	report( r ) {
		const domain = r.domain || 'hub';
		if ( r.source === 'ledger' ) {
			this.ledger?.hit( r.signature, r.ok );
			this.#note( { signature: r.signature, step: r.ok ? 'ledger-ok' : 'ledger-failed', why: '' } );
			return { stored: false };
		}
		if ( ! r.ok ) {
			this.#note( { signature: r.signature, step: 'unverified', why: 'وصله جواب نداد؛ ثبت نشد.' } );
			return { stored: false, reason: 'وصله جواب نداد.' };
		}
		const out = this.ledger?.remember( {
			signature: r.signature,
			patches: r.patches,
			why: r.why,
			origin: r.source,
			domain,
			// بدون این، «ماندگارکردن» نمی‌داند وصله را روی کدام اتصال بچسباند.
			connectionId: r.connectionId,
			verified: true,
		} );
		this.#note( { signature: r.signature, step: 'stored', why: r.why || '' } );
		return out || { stored: false };
	}

	/** متن پرامپت مدل عیب‌یاب. فقط دادهٔ پاک‌سازی‌شده. */
	async #prompt( input ) {
		const err = sanitize( String( input.error?.message || '' ) ).slice( 0, 1200 );
		const shape = sanitize( JSON.stringify( input.shape || {}, null, 1 ) ).slice( 0, 1200 );

		let docs = '';
		// بند ۱۴: اینترنت مجاز است، ولی فقط متن خطای پاک‌سازی‌شده بیرون می‌رود.
		if ( this.config.internet && this.fetchDocs ) {
			docs = await this.fetchDocs( err.slice( 0, 200 ) ).catch( () => '' );
			docs = sanitize( docs || '' ).slice( 0, 1500 );
		}

		return [
			'تو عیب‌یاب یک هاب مدل زبانی هستی. یک درخواست به سرویس‌دهنده شکست خورده است.',
			'کار تو پیشنهاد یک «وصلهٔ ساختاریافته» است تا همان درخواست دوباره اجرا شود.',
			'',
			`عملیات مجاز (فقط همین‌ها): ${ PATCH_OPS.join( '، ' ) }`,
			'',
			'قواعد سخت:',
			'- خروجی فقط JSON باشد، بدون توضیح و بدون بلوک کد.',
			'- شکل خروجی: {"patches":[{"op":"...", ...}], "why":"یک جملهٔ کوتاه"}',
			'- اگر مطمئن نیستی یا خطا با تغییر شکل درخواست حل نمی‌شود: {"patches":[]}',
			'- تغییر میزبان آدرس پایه ممنوع است.',
			'',
			`نوع سرویس: ${ input.cfg?.kind || 'openai' }`,
			`سبک احراز: ${ input.cfg?.authStyle || 'bearer' }`,
			`کد وضعیت: ${ input.error?.status || 0 }`,
			'',
			'متن خطا:',
			err,
			'',
			'شکل درخواست:',
			shape,
			docs ? `\nمستندات مرتبط:\n${ docs }` : '',
		].join( '\n' );
	}

	#note( entry ) {
		const row = { at: new Date( this.now() ).toISOString(), ...entry };
		this.journal.push( row );
		if ( this.journal.length > 200 ) {
			this.journal.shift();
		}
		this.log( row );
	}

	snapshot() {
		this.#rolloverBudget();
		return {
			enabled: Boolean( this.config.enabled ),
			hasModel: Boolean( this.callModel ),
			spentToday: this.spentToday,
			dailyBudget: this.config.dailyBudget ?? null,
			signatures: [ ...this.seen ].map( ( [ signature, s ] ) => ( {
				signature,
				failures: s.count,
				modelCalls: s.calls.length,
			} ) ),
			journal: this.journal.slice( -30 ).reverse(),
		};
	}
}

/**
 * خروجی مدل را به فهرست وصله تبدیل می‌کند.
 *
 * مدل‌ها گاهی JSON را داخل بلوک کد می‌گذارند و گاهی یک شیء تنها برمی‌گردانند؛ هر دو
 * باید کار کنند، ولی هرچه از اینجا رد شود باز هم از `validatePatch` می‌گذرد.
 *
 * @param {string} text
 */
export function parsePatches( text ) {
	const raw = String( text || '' ).trim();
	const body = /```(?:json)?\s*([\s\S]*?)```/.exec( raw )?.[ 1 ] || raw;
	const start = body.search( /[[{]/ );
	if ( start < 0 ) {
		return [];
	}
	let parsed;
	try {
		parsed = JSON.parse( body.slice( start ) );
	} catch {
		// یک شیء تکی که دنبالش حرف اضافه آمده — تا اولین آکولاد بسته را بردار.
		const end = body.lastIndexOf( '}' );
		try {
			parsed = JSON.parse( body.slice( start, end + 1 ) );
		} catch {
			return [];
		}
	}
	if ( Array.isArray( parsed ) ) {
		return parsed;
	}
	if ( Array.isArray( parsed?.patches ) ) {
		return parsed.patches;
	}
	if ( parsed?.op ) {
		return [ parsed ];
	}
	return [];
}
