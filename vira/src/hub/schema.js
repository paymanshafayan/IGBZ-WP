/**
 * شکل دادهٔ هاب پرووایدر.
 *
 * این فایل فقط «شکل» را تعریف می‌کند: مقدار پیش‌فرض، نرمال‌سازی، اعتبارسنجی، و نسخهٔ
 * ماسک‌شده برای رابط. هیچ ورودی/خروجی و هیچ شبکه‌ای اینجا نیست تا بشود بدون کلید و
 * بدون دیسک تستش کرد.
 *
 * سه لایه‌ای که در سند طراحی آمد (بند ۴) اینجا سه جدول‌اند:
 *   اتصال (connection)  →  مدل (model)  →  ترکیب (combo)
 * و یک جدول چهارم که چسبِ آن‌هاست: دستهٔ کار (category).
 */

/** دسته‌های کار — فهرست بسته ولی قابل‌ویرایش (بند ۴.۴). */
export const CATEGORIES = [
	{ id: 'coding', label: 'کدنویسی' },
	{ id: 'debug', label: 'عیب‌یابی' },
	{ id: 'persian', label: 'متن فارسی' },
	{ id: 'support', label: 'پاسخ به مشتری' },
	{ id: 'data', label: 'تحلیل داده' },
	{ id: 'vision', label: 'بینایی' },
	{ id: 'reasoning', label: 'استدلال بلند' },
	{ id: 'translate', label: 'ترجمه' },
	{ id: 'cheap', label: 'خلاصه‌سازی ارزان' },
	{ id: 'general', label: 'عمومی' },
];

export const CATEGORY_IDS = CATEGORIES.map( ( c ) => c.id );

/**
 * راهبردهای مسیریابی.
 *
 * عمداً کمتر از هجده‌تای OmniRoute است: هر راهبردی که اینجا هست، در `router.js` یک تست
 * دارد که ثابت می‌کند واقعاً همان را انتخاب می‌کند. راهبردی که تست ندارد، تبلیغات است.
 */
export const STRATEGIES = [
	{ id: 'auto', label: 'خودکار (امتیازدهی زنده)', note: 'ویرا خودش جنس درخواست را می‌فهمد و مدل همان زمینه را برمی‌دارد.' },
	{ id: 'priority', label: 'اولویت', note: 'به ترتیب فهرست؛ اولی که سالم باشد.' },
	{ id: 'weighted', label: 'وزنی', note: 'پخش تصادفی به نسبت وزن هر مدل.' },
	{ id: 'round-robin', label: 'نوبتی', note: 'یکی در میان، تا بار پخش شود.' },
	{ id: 'least-used', label: 'کم‌کارترین', note: 'هرکه امروز کمتر صدا زده شده.' },
	{ id: 'fill-first', label: 'پرکردن اولی', note: 'تا سهمیهٔ اولی تمام نشده، سراغ دومی نرو.' },
	{ id: 'cost-optimized', label: 'ارزان‌ترین', note: 'کمترین هزینهٔ تخمینی برای همین درخواست.' },
	{ id: 'fastest', label: 'سریع‌ترین', note: 'کمترین صدک ۹۵ تأخیر در نمونه‌های اخیر.' },
	{ id: 'p2c', label: 'دو انتخاب تصادفی', note: 'دو نامزد تصادفی، بهترشان — پخش بار بدون تمرکز.' },
];

export const STRATEGY_IDS = STRATEGIES.map( ( s ) => s.id );

/** سبک‌های احراز هویت برای اتصال سازگار (بند ۴.۱). */
export const AUTH_STYLES = [
	{ id: 'bearer', label: 'Authorization: Bearer' },
	{ id: 'x-api-key', label: 'x-api-key' },
	{ id: 'header', label: 'هدر دلخواه' },
	{ id: 'query', label: 'پارامتر آدرس' },
	{ id: 'none', label: 'بدون احراز' },
];

/** @returns {any} */
export function defaultHub() {
	return {
		enabled: false,
		version: 1,
		/** @type {Record<string, any>} */
		connections: {},
		/** @type {Record<string, any>} */
		models: {},
		/** @type {Record<string, any>} */
		combos: {},
		/** دستهٔ کار → شناسهٔ ترکیب پیش‌فرض. */
		/** @type {Record<string, string>} */
		categoryCombo: {},
		routing: {
			strategy: 'auto',
			fallback: true,
			maxAttempts: 3,
			/** وقتی قاعده مطمئن نیست، از یک مدل کوچک بپرس (راه «ب» در بند ۵). */
			classifierModel: '',
			classifierMinConfidence: 0.45,
		},
		budget: {
			// بند ۱۶: مکانیزم ساخته می‌شود، عدد خالی می‌ماند.
			daily: null,
			perAdmin: null,
			perTask: null,
			warnAt: 0.8,
		},
		cache: { enabled: true, ttlMs: 300000, max: 200 },
		diagnoser: {
			enabled: true,
			// «جدای از هاب تعریف و تنظیم شود» — پس اتصال و مدل خودش را دارد.
			connectionId: '',
			model: '',
			minFailures: 2,
			perSignaturePerHour: 1,
			dailyBudget: null,
			internet: false,
			autoApply: true,
			// بند ۱۳: ماندگارشدن وصله تأیید مدیر می‌خواهد.
			autoPromote: false,
		},
	};
}

/** شناسهٔ یکتا و ساده — فقط ASCII، چون در URL و کلید JSON می‌نشیند. */
export function hubId( prefix = 'c' ) {
	return `${ prefix }_${ Math.random().toString( 36 ).slice( 2, 8 ) }${ Date.now().toString( 36 ).slice( -3 ) }`;
}

/** کلید یکتای یک مدل در کل هاب: اتصال + شناسهٔ مدل. */
export function modelKey( connectionId, modelId ) {
	return `${ connectionId }::${ modelId }`;
}

/** @param {string} key */
export function splitModelKey( key ) {
	const i = String( key || '' ).indexOf( '::' );
	if ( i < 0 ) {
		return { connectionId: '', modelId: String( key || '' ) };
	}
	return { connectionId: key.slice( 0, i ), modelId: key.slice( i + 2 ) };
}

/**
 * نرمال‌سازی یک اتصال: هرچه از رابط یا فایل می‌آید، از این در رد می‌شود.
 *
 * @param {any} raw
 * @param {any} [previous] مقدار قبلی — برای وقتی کلید ماسک‌شده برگشته و نباید پاک شود
 */
export function normalizeConnection( raw, previous = null ) {
	const kind = raw?.kind === 'anthropic' ? 'anthropic' : raw?.kind === 'mock' ? 'mock' : 'openai';
	const apiKey = isMasked( raw?.apiKey ) ? previous?.apiKey || '' : String( raw?.apiKey ?? previous?.apiKey ?? '' );

	return {
		id: String( raw?.id || previous?.id || hubId( 'conn' ) ),
		label: String( raw?.label || previous?.label || 'اتصال تازه' ).slice( 0, 80 ),
		// `provider` شناسهٔ کاتالوگ است؛ `custom` یعنی اتصال سازگارِ دست‌ساز.
		provider: String( raw?.provider || previous?.provider || 'openai-compatible' ),
		kind,
		baseUrl: String( raw?.baseUrl ?? previous?.baseUrl ?? '' ).trim().replace( /\/+$/, '' ),
		apiKey,
		/** ارجاع به کلید در کارگزار کلید (بند ۱۲) — به‌جای خود کلید. */
		keyRef: String( raw?.keyRef ?? previous?.keyRef ?? '' ),
		authStyle: pick( raw?.authStyle ?? previous?.authStyle, AUTH_STYLES.map( ( a ) => a.id ), 'bearer' ),
		authHeader: String( raw?.authHeader ?? previous?.authHeader ?? '' ).slice( 0, 60 ),
		authPrefix: String( raw?.authPrefix ?? previous?.authPrefix ?? '' ).slice( 0, 30 ),
		headers: normalizeHeaders( raw?.headers ?? previous?.headers ),
		modelsPath: String( raw?.modelsPath ?? previous?.modelsPath ?? '' ).trim(),
		enabled: raw?.enabled === undefined ? previous?.enabled !== false : Boolean( raw.enabled ),
		priority: clampInt( raw?.priority ?? previous?.priority, 1, 999, 100 ),
		weight: clampInt( raw?.weight ?? previous?.weight, 0, 1000, 1 ),
		maxConcurrent: clampInt( raw?.maxConcurrent ?? previous?.maxConcurrent, 1, 128, 4 ),
		dailyCap: numberOrNull( raw?.dailyCap ?? previous?.dailyCap ),
		notes: String( raw?.notes ?? previous?.notes ?? '' ).slice( 0, 500 ),
		/*
		 * پراکسیِ مخصوص این اتصال (۰.۹.۵) — خالی یعنی از پراکسی سراسری هاب. مقصدهای
		 * محلی در net.js همیشه مستقیم می‌روند، صرف‌نظر از این مقدار.
		 */
		proxy: String( raw?.proxy ?? previous?.proxy ?? '' ).trim().slice( 0, 200 ),
		// وصله‌های **دائمی** این اتصال: تأییدشدهٔ مدیر، و پیش از اولین تلاش اعمال می‌شوند.
		// وصلهٔ موقت اینجا نمی‌آید؛ آن در دفتر می‌ماند و فقط بعد از خطا استفاده می‌شود.
		patches: Array.isArray( raw?.patches ) ? raw.patches : previous?.patches || [],
		createdAt: previous?.createdAt || new Date().toISOString(),
	};
}

/**
 * @param {any} conn
 * @returns {{ok:boolean, missing:string[]}}
 */
export function validateConnection( conn ) {
	/** @type {string[]} */
	const missing = [];
	if ( ! conn?.label ) {
		missing.push( 'نام' );
	}
	if ( conn?.kind !== 'mock' ) {
		if ( ! conn?.baseUrl ) {
			missing.push( 'آدرس پایه' );
		} else if ( ! /^https?:\/\//i.test( conn.baseUrl ) ) {
			missing.push( 'آدرس پایه باید با http یا https شروع شود' );
		}
		if ( conn?.authStyle === 'header' && ! conn?.authHeader ) {
			missing.push( 'نام هدر احراز' );
		}
		if ( conn?.authStyle !== 'none' && ! conn?.apiKey && ! conn?.keyRef && ! isLocal( conn?.baseUrl ) ) {
			missing.push( 'کلید API' );
		}
	}
	return { ok: missing.length === 0, missing };
}

/**
 * نرمال‌سازی یک مدل در رجیستری.
 *
 * @param {any} raw
 * @param {any} [previous]
 */
export function normalizeModel( raw, previous = null ) {
	const caps = { ...( previous?.caps || {} ), ...( raw?.caps || {} ) };
	return {
		key: String( raw?.key || previous?.key || '' ),
		connectionId: String( raw?.connectionId || previous?.connectionId || '' ),
		modelId: String( raw?.modelId || previous?.modelId || '' ),
		label: String( raw?.label || previous?.label || raw?.modelId || '' ).slice( 0, 120 ),
		context: clampInt( raw?.context ?? previous?.context, 0, 100_000_000, 0 ),
		priceIn: numberOrNull( raw?.priceIn ?? previous?.priceIn ),
		priceOut: numberOrNull( raw?.priceOut ?? previous?.priceOut ),
		caps: {
			tools: caps.tools !== false,
			vision: Boolean( caps.vision ),
			reasoning: Boolean( caps.reasoning ),
			stream: caps.stream !== false,
			json: caps.json !== false,
		},
		tags: uniqueTags( raw?.tags ?? previous?.tags ),
		weight: clampInt( raw?.weight ?? previous?.weight, 0, 1000, 1 ),
		priority: clampInt( raw?.priority ?? previous?.priority, 1, 999, 100 ),
		enabled: raw?.enabled === undefined ? previous?.enabled !== false : Boolean( raw.enabled ),
		/** از کجا آمده: کشف خودکار یا دست مدیر. مهم است چون کشف دوباره نباید ویرایش مدیر را بشوید. */
		source: String( raw?.source || previous?.source || 'discovered' ),
		editedByAdmin: Boolean( raw?.editedByAdmin ?? previous?.editedByAdmin ),
	};
}

/**
 * @param {any} raw
 * @param {any} [previous]
 */
export function normalizeCombo( raw, previous = null ) {
	return {
		id: String( raw?.id || previous?.id || hubId( 'combo' ) ),
		label: String( raw?.label || previous?.label || 'ترکیب تازه' ).slice( 0, 80 ),
		strategy: pick( raw?.strategy ?? previous?.strategy, STRATEGY_IDS, 'auto' ),
		/** فهرست مرتب کلید مدل‌ها. خالی یعنی «هر مدل روشنی». */
		members: Array.isArray( raw?.members ) ? raw.members.map( String ).filter( Boolean ) : previous?.members || [],
		note: String( raw?.note ?? previous?.note ?? '' ).slice( 0, 300 ),
	};
}

/**
 * نسخهٔ امن برای رابط: کلید هیچ‌وقت بیرون نمی‌رود.
 *
 * همان قاعدهٔ `Crypto::MASK` در خود افزونه — مقدار واقعی هرگز به رابط برنمی‌گردد.
 * @param {any} hub
 */
export function publicHub( hub ) {
	/** @type {Record<string, any>} */
	const connections = {};
	for ( const [ id, c ] of Object.entries( hub?.connections || {} ) ) {
		connections[ id ] = { ...c, apiKey: maskKey( c.apiKey ), hasKey: Boolean( c.apiKey || c.keyRef ) };
	}
	return { ...hub, connections };
}

export const MASK = '••••••••';

/** @param {string} [key] */
export function maskKey( key ) {
	if ( ! key ) {
		return '';
	}
	return MASK + String( key ).slice( -4 );
}

/** @param {any} value */
export function isMasked( value ) {
	return typeof value === 'string' && value.startsWith( MASK );
}

// ------------------------------------------------------------------ کمکی‌ها

/** @param {any} value */
function normalizeHeaders( value ) {
	/** @type {Record<string,string>} */
	const out = {};
	if ( ! value || typeof value !== 'object' ) {
		return out;
	}
	for ( const [ k, v ] of Object.entries( value ) ) {
		const name = String( k ).trim();
		// هدر بی‌نام یا با نویسهٔ غیرمجاز، یعنی درخواست خراب — بی‌سروصدا می‌افتد بیرون.
		if ( ! name || ! /^[A-Za-z0-9-]+$/.test( name ) ) {
			continue;
		}
		out[ name ] = String( v ?? '' ).slice( 0, 500 );
	}
	return out;
}

function uniqueTags( value ) {
	const list = Array.isArray( value ) ? value : [];
	return [ ...new Set( list.map( String ).filter( ( t ) => CATEGORY_IDS.includes( t ) ) ) ];
}

function pick( value, allowed, fallback ) {
	return allowed.includes( value ) ? value : fallback;
}

function clampInt( value, min, max, fallback ) {
	const n = Number( value );
	if ( ! Number.isFinite( n ) ) {
		return fallback;
	}
	return Math.min( max, Math.max( min, Math.round( n ) ) );
}

function numberOrNull( value ) {
	if ( value === '' || value === null || value === undefined ) {
		return null;
	}
	const n = Number( value );
	return Number.isFinite( n ) && n >= 0 ? n : null;
}

/** @param {string} [url] */
export function isLocal( url ) {
	return /^https?:\/\/(127\.0\.0\.1|localhost|0\.0\.0\.0|\[::1\])/i.test( String( url || '' ) );
}
