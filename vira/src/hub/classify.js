/**
 * تشخیص جنس درخواست — راه «الف» بند ۵ سند طراحی.
 *
 * قاعده‌ای، بدون هیچ تماس شبکه‌ای، و عمداً قابل توضیح: هر تصمیمی که می‌گیرد، دلیلش را
 * هم برمی‌گرداند تا در صفحهٔ «این درخواست به کجا می‌رود» بشود نشانش داد.
 *
 * یک درس گران که اینجا رعایت شده: `\b` در جاوااسکریپت برای فارسی کار نمی‌کند —
 * `/\bخطا\b/` روی «خطا» هم می‌گیرد و هم نمی‌گیرد، بسته به همسایه‌اش. پس مرز کلمه را
 * با lookaround یونی‌کدی می‌سازیم، نه با `\b`.
 */

import { CATEGORY_IDS } from './schema.js';

/** پسوند فایل → دستهٔ کار. */
const EXTENSIONS = {
	coding: [
		'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'kt', 'swift',
		'c', 'h', 'cpp', 'cs', 'sh', 'bash', 'ps1', 'sql', 'css', 'scss', 'html', 'vue', 'svelte',
	],
	data: [ 'csv', 'tsv', 'xlsx', 'parquet', 'ipynb' ],
	vision: [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg' ],
};

/** ابزارِ درگیر → نشانهٔ دسته. */
const TOOL_HINTS = {
	bash: 'coding',
	edit_file: 'coding',
	write_file: 'coding',
	multi_edit: 'coding',
	notebook_edit: 'data',
	git_diff: 'coding',
	git_status: 'coding',
	git_commit: 'coding',
	grep: 'debug',
	web_search: 'general',
	web_fetch: 'general',
};

/**
 * کلیدواژه‌ها. وزن‌ها عمدی‌اند: یک واژهٔ بسیار مشخص («traceback») بیشتر از یک واژهٔ
 * چندپهلو («خطا») امتیاز می‌گیرد.
 * @type {Record<string, [string, number][]>}
 */
const KEYWORDS = {
	coding: [
		[ 'کد', 1 ], [ 'تابع', 1.5 ], [ 'کلاس', 1 ], [ 'ریفکتور', 2 ], [ 'بازنویسی', 1 ], [ 'پیاده‌سازی', 1.5 ],
		[ 'پیاده سازی', 1.5 ], [ 'تست', 1 ], [ 'کامیت', 1.5 ], [ 'مخزن', 1 ], [ 'برنچ', 1.5 ], [ 'شاخه', 0.5 ],
		[ 'refactor', 2 ], [ 'implement', 1.5 ], [ 'function', 1.5 ], [ 'class', 1 ], [ 'commit', 1.5 ],
		[ 'pull request', 2 ], [ 'unit test', 2 ], [ 'compile', 1.5 ], [ 'build', 1 ], [ 'lint', 1.5 ],
	],
	debug: [
		[ 'خطا', 1.5 ], [ 'باگ', 2 ], [ 'ارور', 2 ], [ 'کرش', 2 ], [ 'خراب', 1 ], [ 'کار نمی‌کند', 2 ],
		[ 'کار نمیکند', 2 ], [ 'عیب‌یابی', 2.5 ], [ 'عیب یابی', 2.5 ], [ 'رفع اشکال', 2.5 ], [ 'چرا نمی', 1.5 ],
		[ 'bug', 2 ], [ 'error', 1.5 ], [ 'exception', 2 ], [ 'traceback', 3 ], [ 'stack trace', 3 ],
		[ 'stacktrace', 3 ], [ 'debug', 2.5 ], [ 'crash', 2 ], [ 'fails', 1.5 ], [ 'failing', 1.5 ], [ 'fatal', 2 ],
	],
	persian: [
		[ 'کپشن', 2.5 ], [ 'متن فارسی', 3 ], [ 'محتوا بنویس', 2 ], [ 'بازنویسی متن', 2 ], [ 'ویراستاری', 2.5 ],
		[ 'نگارش', 2 ], [ 'لحن', 1.5 ], [ 'تیتر', 1.5 ], [ 'شعار', 1.5 ], [ 'توضیح محصول', 2 ],
	],
	support: [
		[ 'مشتری', 2 ], [ 'تیکت', 2.5 ], [ 'پشتیبانی', 2.5 ], [ 'شکایت', 2 ], [ 'پاسخ بده به کاربر', 2 ],
		[ 'customer', 2 ], [ 'ticket', 2 ], [ 'support reply', 2.5 ],
	],
	data: [
		[ 'کوئری', 2 ], [ 'گزارش', 1.5 ], [ 'نمودار', 2 ], [ 'تحلیل', 1.5 ], [ 'آمار', 1.5 ], [ 'دیتابیس', 2 ],
		[ 'جدول', 1 ], [ 'sql', 2 ], [ 'query', 1.5 ], [ 'dataset', 2 ], [ 'analytics', 2 ], [ 'chart', 1.5 ],
	],
	vision: [
		[ 'تصویر', 2 ], [ 'عکس', 2 ], [ 'اسکرین‌شات', 2.5 ], [ 'اسکرین شات', 2.5 ], [ 'screenshot', 2.5 ],
		[ 'image', 1.5 ], [ 'ocr', 3 ],
	],
	reasoning: [
		[ 'معماری', 2 ], [ 'طراحی کن', 2 ], [ 'مقایسه کن', 1.5 ], [ 'استدلال', 2.5 ], [ 'اثبات', 2.5 ],
		[ 'برنامه‌ریزی', 2 ], [ 'برنامه ریزی', 2 ], [ 'راهبرد', 2 ], [ 'architecture', 2 ], [ 'trade-off', 2.5 ],
		[ 'tradeoff', 2.5 ], [ 'reason step by step', 3 ], [ 'plan', 1 ],
	],
	translate: [
		[ 'ترجمه', 3 ], [ 'به انگلیسی', 2 ], [ 'به فارسی', 2 ], [ 'translate', 3 ], [ 'translation', 2.5 ],
	],
	cheap: [
		[ 'خلاصه', 2 ], [ 'خلاصه کن', 3 ], [ 'جمع‌بندی', 2 ], [ 'جمع بندی', 2 ], [ 'summarize', 3 ],
		[ 'summary', 2 ], [ 'tl;dr', 3 ],
	],
};

/**
 * آیا این عبارت به‌عنوان یک «کلمه» در متن هست؟
 *
 * @param {string} haystack
 * @param {string} needle
 */
export function hasWord( haystack, needle ) {
	const escaped = needle.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	// مرز کلمه‌ای که فارسی را هم می‌فهمد: هر چیزی که حرف یا رقم نباشد.
	const re = new RegExp( `(?<![\\p{L}\\p{N}_])${ escaped }(?![\\p{L}\\p{N}_])`, 'iu' );
	return re.test( haystack );
}

/** نسبت نویسه‌های فارسی/عربی در متن. */
export function persianRatio( text ) {
	const s = String( text || '' );
	const letters = s.match( /\p{L}/gu ) || [];
	if ( ! letters.length ) {
		return 0;
	}
	const fa = s.match( /[\u0600-\u06FF]/gu ) || [];
	return fa.length / letters.length;
}

/**
 * @param {{text?:string, tools?:string[], files?:string[], hasImages?:boolean}} input
 * @returns {{category:string, confidence:number, scores:Record<string,number>, reasons:string[]}}
 */
export function classify( input = {} ) {
	const text = String( input.text || '' );
	/** @type {Record<string, number>} */
	const scores = Object.fromEntries( CATEGORY_IDS.map( ( id ) => [ id, 0 ] ) );
	/** @type {string[]} */
	const reasons = [];

	// ۱) تصویر در ورودی، یعنی بدون بینایی اصلاً جواب نمی‌دهد. این یک نشانه نیست، یک شرط است.
	if ( input.hasImages ) {
		scores.vision += 6;
		reasons.push( 'در ورودی تصویر هست' );
	}

	// ۲) پسوند فایل‌های لمس‌شده.
	for ( const file of input.files || [] ) {
		const ext = String( file ).split( '.' ).pop()?.toLowerCase() || '';
		for ( const [ cat, list ] of Object.entries( EXTENSIONS ) ) {
			if ( list.includes( ext ) ) {
				scores[ cat ] += 1.5;
				reasons.push( `فایل .${ ext }` );
			}
		}
	}

	// ۳) ابزارهای درگیر.
	for ( const tool of input.tools || [] ) {
		const cat = TOOL_HINTS[ tool ];
		if ( cat && scores[ cat ] !== undefined ) {
			scores[ cat ] += 1;
			reasons.push( `ابزار ${ tool }` );
		}
	}

	// ۴) کلیدواژه‌ها.
	for ( const [ cat, list ] of Object.entries( KEYWORDS ) ) {
		for ( const [ word, weight ] of list ) {
			if ( hasWord( text, word ) ) {
				scores[ cat ] += weight;
				reasons.push( `واژهٔ «${ word }»` );
			}
		}
	}

	// ۵) زبان — با یک قید که در تست پیدا شد و مهم است.
	//
	// نسخهٔ اول این قاعده هر متن فارسی را «کار محتوایی» می‌دید. در یک ابزار فارسی‌زبان،
	// یعنی *همه‌چیز* محتوایی است و کل تشخیص بی‌اثر می‌شود. حالا زبان فقط وقتی وزن دارد
	// که متن به‌اندازهٔ یک درخواست محتوایی بلند باشد و هیچ نشانهٔ فنی‌ای نداشته باشد.
	const fa = persianRatio( text );
	if ( fa > 0.4 && text.length >= 60 && scores.coding < 2 && scores.debug < 2 && scores.data < 2 ) {
		scores.persian += 1.2;
		reasons.push( 'متن فارسیِ بلند بدون نشانهٔ فنی' );
	}

	// ۶) نشانه‌های ساختاری: بلوک کد یا رد پشته، از هر کلیدواژه‌ای گویاترند.
	if ( /```/.test( text ) ) {
		scores.coding += 1.5;
		reasons.push( 'بلوک کد در متن' );
	}
	if ( /(^|\n)\s*at .+:\d+:\d+|Traceback \(most recent call last\)|PHP (Fatal|Warning|Notice)/.test( text ) ) {
		scores.debug += 3;
		reasons.push( 'رد پشتهٔ خطا در متن' );
	}

	const ranked = Object.entries( scores ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
	const [ topCat, topScore ] = ranked[ 0 ];
	const second = ranked[ 1 ]?.[ 1 ] || 0;

	if ( topScore <= 0 ) {
		return { category: 'general', confidence: 0, scores, reasons: [ 'هیچ نشانهٔ روشنی نبود' ] };
	}

	// اطمینان = چقدر برنده از نفر دوم جلوتر است، نه اینکه چند امتیاز گرفته.
	// دو دستهٔ هم‌امتیاز یعنی «نمی‌دانم»، حتی اگر هر دو امتیاز بالایی داشته باشند.
	const gap = ( topScore - second ) / topScore;
	const mass = Math.min( 1, topScore / 4 );
	const confidence = Math.round( Math.min( 1, 0.35 * mass + 0.65 * gap ) * 100 ) / 100;

	return { category: topCat, confidence, scores, reasons: [ ...new Set( reasons ) ].slice( 0, 8 ) };
}

/** آیا این درخواست حتماً مدل بینا می‌خواهد؟ */
export function needsVision( input = {} ) {
	return Boolean( input.hasImages );
}
