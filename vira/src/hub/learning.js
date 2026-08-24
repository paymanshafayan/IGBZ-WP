/**
 * یادگیری از نتیجه — راه «ج» بند ۵، که در سند نوشتیم از کاتالوگ اولیه مهم‌تر است.
 *
 * دلیلش ساده است: جدول ترجیحی که امروز می‌نویسم سه ماه دیگر کهنه است، ولی این جدول بعد از
 * دو هفته از دادهٔ **همین نصب** می‌فهمد کدام مدل برای کپشن فارسی بهتر است.
 *
 * امتیاز هر (مدل × دسته) یک میانگین متحرک نمایی است روی چهار چیز:
 *   موفقیت (بیشترین وزن) · تأخیر · هزینه · رضایت کاربر (اگر ابراز شده باشد)
 *
 * چرا EWMA و نه میانگین ساده: مدلی که ماه پیش خوب بود و این هفته خراب شده، باید سریع
 * سقوط کند. میانگین ساده با هزار نمونهٔ قدیمی، تازه‌ها را خفه می‌کند.
 */

const ALPHA = 0.25;
const NEUTRAL = 0.5;

export class Learning {
	/**
	 * @param {{alpha?:number, state?:any}} [opts]
	 */
	constructor( opts = {} ) {
		this.alpha = opts.alpha ?? ALPHA;
		/** @type {Map<string, {score:number, n:number, ok:number, fail:number}>} */
		this.entries = new Map();
		if ( opts.state ) {
			this.fromJSON( opts.state );
		}
	}

	/**
	 * @param {string} modelKey
	 * @param {string} category
	 */
	static key( modelKey, category ) {
		return `${ modelKey }@${ category || 'general' }`;
	}

	/**
	 * امتیاز خام یک نتیجه، بین ۰ و ۱.
	 *
	 * @param {{ok:boolean, ms?:number, cost?:number|null, satisfaction?:number|null}} r
	 */
	static outcomeScore( r ) {
		if ( ! r.ok ) {
			return 0;
		}
		let score = 0.7;
		// تأخیر: زیر ۳ ثانیه عالی، بالای ۶۰ ثانیه بی‌ارزش.
		if ( typeof r.ms === 'number' ) {
			const fast = Math.max( 0, Math.min( 1, 1 - ( r.ms - 3000 ) / 57_000 ) );
			score += 0.12 * fast;
		} else {
			score += 0.06;
		}
		// هزینه: زیر یک سنت عالی، بالای ده سنت بی‌ارزش.
		if ( typeof r.cost === 'number' && r.cost !== null ) {
			const cheap = Math.max( 0, Math.min( 1, 1 - ( r.cost - 0.01 ) / 0.09 ) );
			score += 0.08 * cheap;
		} else {
			score += 0.04;
		}
		// رضایت کاربر، اگر ابراز شده باشد، مستقیم‌ترین سیگنالی است که داریم.
		if ( typeof r.satisfaction === 'number' ) {
			score += 0.1 * Math.max( 0, Math.min( 1, r.satisfaction ) );
		} else {
			score += 0.05;
		}
		return Math.max( 0, Math.min( 1, score ) );
	}

	/**
	 * @param {{modelKey:string, category:string, ok:boolean, ms?:number, cost?:number|null, satisfaction?:number|null}} r
	 */
	record( r ) {
		const key = Learning.key( r.modelKey, r.category );
		const cur = this.entries.get( key ) || { score: NEUTRAL, n: 0, ok: 0, fail: 0 };
		const value = Learning.outcomeScore( r );
		cur.score = cur.n === 0 ? value : cur.score + this.alpha * ( value - cur.score );
		cur.n += 1;
		if ( r.ok ) {
			cur.ok += 1;
		} else {
			cur.fail += 1;
		}
		this.entries.set( key, cur );
		return cur;
	}

	/**
	 * امتیاز یک مدل در یک دسته.
	 *
	 * تا وقتی نمونهٔ کافی نداریم، به‌جای اعتماد کامل به دادهٔ کم، امتیاز را به سمت خنثی
	 * می‌کشیم. یک مدل که یک بار جواب داده، نباید مدلی را که صد بار جواب داده کنار بزند.
	 *
	 * @param {string} modelKey
	 * @param {string} category
	 */
	score( modelKey, category ) {
		const e = this.entries.get( Learning.key( modelKey, category ) );
		if ( ! e || ! e.n ) {
			return NEUTRAL;
		}
		const trust = Math.min( 1, e.n / 8 );
		return NEUTRAL + ( e.score - NEUTRAL ) * trust;
	}

	/** @param {string} modelKey */
	forget( modelKey ) {
		for ( const key of [ ...this.entries.keys() ] ) {
			if ( key.startsWith( `${ modelKey }@` ) ) {
				this.entries.delete( key );
			}
		}
	}

	/** بهترین مدل‌های هر دسته — برای نمایش «ویرا چه یاد گرفته». */
	table() {
		/** @type {Record<string, {modelKey:string, score:number, n:number}[]>} */
		const out = {};
		for ( const [ key, e ] of this.entries ) {
			const at = key.lastIndexOf( '@' );
			const modelKey = key.slice( 0, at );
			const category = key.slice( at + 1 );
			( out[ category ] = out[ category ] || [] ).push( {
				modelKey,
				score: Math.round( this.score( modelKey, category ) * 100 ) / 100,
				n: e.n,
			} );
		}
		for ( const list of Object.values( out ) ) {
			list.sort( ( a, b ) => b.score - a.score );
		}
		return out;
	}

	toJSON() {
		return Object.fromEntries( this.entries );
	}

	/** @param {any} data */
	fromJSON( data ) {
		for ( const [ k, v ] of Object.entries( data || {} ) ) {
			this.entries.set( k, { score: NEUTRAL, n: 0, ok: 0, fail: 0, ...v } );
		}
		return this;
	}
}
