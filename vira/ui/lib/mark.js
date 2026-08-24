/**
 * هندسهٔ نشان ویرا — **شمسهٔ هشت‌پر**.
 *
 * چرا این شکل: ستارهٔ هشت‌پر (خاتم / شمسه) شناخته‌شده‌ترین نقش هندسی معماری ایرانی است —
 * از کاشی‌کاری مسجد شیخ لطف‌الله تا خاتم شیراز. برخلاف ستارهٔ Claude که پرتوهای آزاد دارد،
 * این نقش **بسته و منظم** است: دو مربع چرخیده روی هم، با یک هشت‌ضلعی و نقطهٔ مرکزی.
 *
 * هندسه یک جا تعریف می‌شود تا SVG رابط، فایل روی دیسک، و آیکون‌های PNG هیچ‌وقت از هم
 * جدا نیفتند — همه از همین اعداد ساخته می‌شوند.
 */

export const VIEW = 32;
export const CENTER = VIEW / 2;

/** شعاع نوک پرها و شعاع فرورفتگی بین آن‌ها. نسبت ۰٫۷۶۵ همان نسبت دو مربعِ چرخیده است. */
export const OUTER = 14.6;
export const INNER = OUTER * 0.7654;

/** هشت‌ضلعی میانی که وسط ستاره را خالی می‌کند، و شمسهٔ کوچکِ درونش. */
export const RING = 8.1;
export const CORE = 5.4;
export const DOT = 2.7;

/**
 * نقاط یک ستارهٔ چندپر.
 *
 * @param {number} points تعداد پرها
 * @param {number} outer
 * @param {number} inner
 * @param {number} rotate چرخش اولیه به درجه
 * @returns {[number, number][]}
 */
export function starPoints( points = 8, outer = OUTER, inner = INNER, rotate = -90 ) {
	/** @type {[number, number][]} */
	const out = [];
	const step = 180 / points;
	for ( let i = 0; i < points * 2; i++ ) {
		const r = i % 2 === 0 ? outer : inner;
		const a = ( ( rotate + i * step ) * Math.PI ) / 180;
		out.push( [ CENTER + r * Math.cos( a ), CENTER + r * Math.sin( a ) ] );
	}
	return out;
}

/**
 * نقاط یک چندضلعی منتظم — برای هشت‌ضلعی میانی.
 *
 * @param {number} sides
 * @param {number} radius
 * @param {number} rotate
 * @returns {[number, number][]}
 */
export function polygonPoints( sides = 8, radius = RING, rotate = -90 + 180 / 8 ) {
	/** @type {[number, number][]} */
	const out = [];
	for ( let i = 0; i < sides; i++ ) {
		const a = ( ( rotate + ( i * 360 ) / sides ) * Math.PI ) / 180;
		out.push( [ CENTER + radius * Math.cos( a ), CENTER + radius * Math.sin( a ) ] );
	}
	return out;
}

/** @param {[number, number][]} pts */
export function toPath( pts ) {
	return `${ pts.map( ( [ x, y ], i ) => `${ i === 0 ? 'M' : 'L' }${ x.toFixed( 2 )} ${ y.toFixed( 2 ) }` ).join( ' ' ) } Z`;
}

/** مسیر کامل نشان: ستاره با هشت‌ضلعیِ خالی وسط (قاعدهٔ even-odd). */
export function markPath() {
	return `${ toPath( starPoints() ) } ${ toPath( polygonPoints() ) }`;
}

/**
 * شمسهٔ کوچکِ درونی — «ستاره در ستاره»، همان چیزی که کاشی‌کاری ایرانی را از یک ستارهٔ ساده
 * جدا می‌کند. کمی چرخیده تا پرهایش وسط پرهای بیرونی بنشیند.
 */
export function innerStarPath() {
	return toPath( starPoints( 8, CORE, CORE * 0.7654, -90 + 22.5 ) );
}

/** رنگ‌های نشان. */
export const COLORS = {
	/*
	 * فیروزهٔ ایرانی — رنگ برند ویرا.
	 *
	 * نارنجیِ Crail مالِ آنتروپیک است. شمسه رنگ خودش را دارد و همان از روز اول اینجا
	 * تعریف شده بود؛ حالا واقعاً استفاده می‌شود.
	 */
	from: '#39b0c7',
	to: '#227f92',
	turquoise: '#2a9db5',
};
