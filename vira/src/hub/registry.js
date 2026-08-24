/**
 * رجیستری مدل‌ها.
 *
 * کشف خودکار از اتصال، و **حدس اولیهٔ** توانایی و برچسب زمینه. کلمهٔ کلیدی «اولیه» است:
 * این جدول فقط نقطهٔ شروع است. ویرایش مدیر روی آن می‌نشیند و یادگیری از نتیجه
 * (`learning.js`) در عمل بر هر دو می‌چربد — همان ترتیبی که در بند ۸ سند تصویب شد.
 *
 * پس اگر حدس اینجا برای مدلی غلط بود، فاجعه نیست؛ سیستم بعد از چند نوبت خودش اصلاح
 * می‌کند. چیزی که فاجعه است، پاک‌کردن ویرایش مدیر در کشف بعدی است — و همان چیزی است که
 * `mergeDiscovered` مراقبش است.
 */

import { modelKey, normalizeModel } from './schema.js';
import { priceOf } from '../usage.js';

/** الگوهایی که می‌گویند مدل بیناست. */
const VISION = /gpt-4o|gpt-4\.1|gpt-5|o3|o4|claude-(3|4|opus|sonnet|haiku)|gemini|llama-?3\.2-vision|qwen.*vl|pixtral|vision|llava|grok-(2-)?vision|grok-4/i;

/** الگوهایی که می‌گویند مدل استدلالی است. */
const REASONING = /^o[134]|o[134]-mini|deepseek-r1|qwq|thinking|reasoner|gpt-5|magistral/i;

/** مدل‌هایی که ابزار ندارند (تکمیل متن، جاسازی، صوت، تصویر). */
const NO_TOOLS = /embed|whisper|tts|dall-e|stable-diffusion|flux|rerank|moderation|clip|bge-|e5-/i;

/** مدل‌های ارزان و کوچک — نامزد خوب دستهٔ «خلاصه‌سازی ارزان». */
const SMALL = /mini|flash|haiku|small|tiny|1b|3b|7b|8b|lite|nano|turbo/i;

/** مدل‌هایی که در عمل برای کد شناخته شده‌اند. */
const CODER = /cod(er|ex|estral)|deepseek-(chat|coder)|claude-(sonnet|opus)|gpt-4\.1|gpt-5|qwen.*coder|devstral|kimi/i;

/** پنجرهٔ کانتکست حدسی از روی نام. */
const CONTEXT_HINTS = [
	[ /1m|1000k/i, 1_000_000 ],
	[ /200k/i, 200_000 ],
	[ /128k/i, 128_000 ],
	[ /64k/i, 64_000 ],
	[ /32k/i, 32_000 ],
	[ /gpt-4\.1|gpt-5/i, 1_000_000 ],
	[ /claude/i, 200_000 ],
	[ /gemini-(1\.5|2|2\.5)/i, 1_000_000 ],
	[ /gpt-4o|o3|o4/i, 128_000 ],
	[ /deepseek/i, 64_000 ],
];

/** @param {string} id */
export function inferCaps( id ) {
	const name = String( id || '' );
	return {
		tools: ! NO_TOOLS.test( name ),
		vision: VISION.test( name ),
		reasoning: REASONING.test( name ),
		stream: true,
		json: true,
	};
}

/** @param {string} id */
export function inferContext( id ) {
	for ( const [ re, value ] of CONTEXT_HINTS ) {
		if ( re.test( String( id || '' ) ) ) {
			return value;
		}
	}
	return 0;
}

/**
 * برچسب زمینهٔ حدسی.
 * @param {string} id
 */
export function inferTags( id ) {
	const name = String( id || '' );
	/** @type {string[]} */
	const tags = [];
	if ( CODER.test( name ) ) {
		tags.push( 'coding', 'debug' );
	}
	if ( REASONING.test( name ) ) {
		tags.push( 'reasoning' );
	}
	if ( VISION.test( name ) ) {
		tags.push( 'vision' );
	}
	if ( SMALL.test( name ) ) {
		tags.push( 'cheap', 'translate' );
	}
	// مدل‌های چندزبانهٔ بزرگ، نامزد پیش‌فرض متن فارسی و پاسخ به مشتری‌اند.
	if ( /claude|gpt-4|gpt-5|gemini|command-r|qwen|aya|llama-3/i.test( name ) && ! SMALL.test( name ) ) {
		tags.push( 'persian', 'support' );
	}
	return [ ...new Set( tags ) ];
}

/**
 * یک مدل تازه‌کشف‌شده را به شکل رجیستری درمی‌آورد.
 *
 * @param {string} connectionId
 * @param {string} modelId
 */
export function buildModel( connectionId, modelId ) {
	const price = priceOf( modelId );
	return normalizeModel( {
		key: modelKey( connectionId, modelId ),
		connectionId,
		modelId,
		label: modelId,
		context: inferContext( modelId ),
		priceIn: price?.in ?? null,
		priceOut: price?.out ?? null,
		caps: inferCaps( modelId ),
		tags: inferTags( modelId ),
		source: 'discovered',
	} );
}

/**
 * ادغام نتیجهٔ کشف با رجیستری موجود.
 *
 * سه قاعده:
 *   - مدل تازه اضافه می‌شود و پیش‌فرض **روشن** است.
 *   - مدلی که مدیر ویرایش کرده، دست‌نخورده می‌ماند.
 *   - مدلی که دیگر در فهرست سرویس نیست، **حذف نمی‌شود** — فقط `missing` می‌خورد.
 *     حذفش یعنی از دست دادن آمار و امتیاز یادگیری‌اش، برای یک قطعیِ موقت سرویس.
 *
 * @param {any} hub
 * @param {string} connectionId
 * @param {string[]} ids
 */
export function mergeDiscovered( hub, connectionId, ids ) {
	const models = { ...( hub.models || {} ) };
	const seen = new Set( ids.map( ( id ) => modelKey( connectionId, id ) ) );
	let added = 0;
	let kept = 0;

	for ( const id of ids ) {
		const key = modelKey( connectionId, id );
		const previous = models[ key ];
		if ( ! previous ) {
			models[ key ] = buildModel( connectionId, id );
			added += 1;
			continue;
		}
		kept += 1;
		if ( previous.editedByAdmin ) {
			models[ key ] = { ...previous, missing: false };
			continue;
		}
		const fresh = buildModel( connectionId, id );
		models[ key ] = { ...fresh, enabled: previous.enabled, weight: previous.weight, priority: previous.priority, missing: false };
	}

	let missing = 0;
	for ( const [ key, model ] of Object.entries( models ) ) {
		if ( model.connectionId === connectionId && ! seen.has( key ) ) {
			models[ key ] = { ...model, missing: true };
			missing += 1;
		}
	}

	return { models, added, kept, missing };
}

/**
 * مدل‌های یک اتصال.
 * @param {any} hub
 * @param {string} connectionId
 */
export function modelsOf( hub, connectionId ) {
	return Object.values( hub.models || {} ).filter( ( m ) => m.connectionId === connectionId );
}

/**
 * آیا هاب اصلاً چیزی برای کار دارد؟
 * @param {any} hub
 */
export function hubReady( hub ) {
	if ( ! hub?.enabled ) {
		return { ok: false, reason: 'هاب خاموش است.' };
	}
	const conns = Object.values( hub.connections || {} ).filter( ( c ) => c.enabled !== false );
	if ( ! conns.length ) {
		return { ok: false, reason: 'هیچ اتصال روشنی تعریف نشده است.' };
	}
	const models = Object.values( hub.models || {} ).filter( ( m ) => m.enabled && ! m.missing );
	if ( ! models.length ) {
		return { ok: false, reason: 'هیچ مدل روشنی در رجیستری نیست. یک بار «کشف مدل‌ها» را بزن.' };
	}
	return { ok: true, reason: '' };
}
