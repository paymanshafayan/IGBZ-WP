/**
 * امضای خطا و پاک‌سازی متن خطا.
 *
 * امضا یعنی: «این همان خطای قبلی است؟» — بدون اینکه شمارهٔ درخواست، زمان، و شناسهٔ
 * یکتای هر بار، دو خطای یکسان را دو چیز متفاوت جلوه بدهد.
 *
 * پاک‌سازی یعنی (بند ۱۴): اگر قرار است متن خطا برای عیب‌یاب بیرون برود، نباید کلید،
 * آدرس داخلی، توکن یا بدنهٔ درخواست همراهش برود.
 */

/** الگوهایی که در امضا بی‌معنا هستند و باید یکدست شوند. */
const NOISE = [
	[ /\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi, '<uuid>' ],
	[ /\b[0-9a-f]{24,}\b/gi, '<hash>' ],
	[ /\b\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}\S*)?/g, '<date>' ],
	[ /https?:\/\/[^\s"')]+/gi, '<url>' ],
	[ /\b\d+(\.\d+)?\b/g, '<n>' ],
	[ /"[^"]{0,80}"/g, '"<s>"' ],
	[ /'[^']{0,80}'/g, "'<s>'" ],
	[ /«[^»]{0,80}»/g, '«<s>»' ],
];

/** چیزهایی که هرگز نباید از این ماشین بیرون بروند. */
const SECRETS = [
	[ /\bsk-[A-Za-z0-9_-]{8,}/g, '<key>' ],
	[ /\bsk_(live|test)_[A-Za-z0-9]{8,}/g, '<key>' ],
	[ /\b(gh[pousr]|github_pat)_[A-Za-z0-9_]{10,}/g, '<token>' ],
	[ /\bxox[baprs]-[A-Za-z0-9-]{10,}/g, '<token>' ],
	[ /\bAIza[0-9A-Za-z_-]{20,}/g, '<key>' ],
	[ /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}/g, '<jwt>' ],
	[ /(api[-_ ]?key|apikey|authorization|bearer|token|secret|password|passwd)\s*[:=]\s*\S+/gi, '$1: <redacted>' ],
	[ /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/g, '<email>' ],
	[ /\b(?:\d{1,3}\.){3}\d{1,3}\b/g, '<ip>' ],
];

/**
 * امضای یک خطا.
 *
 * @param {{kind?:string, status?:number|string, message?:string, connectionKind?:string}} error
 */
export function signatureOf( error = {} ) {
	let text = String( error.message || '' ).toLowerCase();
	for ( const [ re, to ] of SECRETS ) {
		text = text.replace( re, typeof to === 'string' ? to.toLowerCase() : to );
	}
	for ( const [ re, to ] of NOISE ) {
		text = text.replace( re, to );
	}
	text = text.replace( /\s+/g, ' ' ).trim().slice( 0, 120 );

	return [
		error.connectionKind || 'openai',
		error.status ? String( error.status ) : '0',
		error.kind || 'unknown',
		text,
	].join( '|' );
}

/**
 * متن پاک‌شده — تنها چیزی که اجازه دارد از این ماشین بیرون برود.
 * @param {string} text
 */
export function sanitize( text ) {
	let out = String( text || '' );
	for ( const [ re, to ] of SECRETS ) {
		out = out.replace( re, to );
	}
	// مسیرهای فایل سیستم هم اطلاعات ساختار سرور را لو می‌دهند.
	out = out.replace( /(\/(?:home|Users|var|etc|opt|srv)\/[^\s"')]+)/g, '<path>' );
	out = out.replace( /([A-Za-z]:\\[^\s"')]+)/g, '<path>' );
	return out.slice( 0, 4000 );
}

/**
 * کد وضعیت را از متن خطای آداپتور درمی‌آورد.
 * آداپتورها خطا را به شکل «پاسخ ۴۰۴ از پرووایدر: …» برمی‌گردانند.
 * @param {string} message
 */
export function statusOf( message ) {
	const text = String( message || '' );
	const m = /پاسخ (\d{3})/.exec( text ) || /\b(4\d\d|5\d\d)\b/.exec( text );
	return m ? Number( m[ 1 ] ) : 0;
}
