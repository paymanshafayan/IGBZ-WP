/**
 * مسیریاب — قلب هاب.
 *
 * ورودی: یک درخواست و وضعیت زندهٔ سیستم. خروجی: **فهرست مرتب نامزدها**، نه یک انتخاب.
 * این تصمیم عمدی است: هاب باید بتواند وقتی اولی ۵۰۰ داد، بی‌صدا برود سراغ دومی؛ اگر
 * مسیریاب فقط یک نام برگرداند، هر شکست یعنی یک دور کامل محاسبه از نو.
 *
 * هر راهبردی که در `STRATEGIES` هست، اینجا یک شاخهٔ صریح دارد و در تست یک نمونهٔ
 * ساختگی که ثابت می‌کند واقعاً همان را انتخاب می‌کند (بند ۱۰).
 */

import { modelKey } from './schema.js';
import { priceOf } from '../usage.js';

/**
 * @typedef {Object} RouteInput
 * @property {any} hub
 * @property {import('./health.js').Health} health
 * @property {import('./learning.js').Learning} learning
 * @property {string} [category]
 * @property {boolean} [needsTools]
 * @property {boolean} [needsVision]
 * @property {number} [estimateTokens]
 * @property {string} [comboId]
 * @property {string} [pinModel]  کلید مدل، وقتی کاربر خودش انتخاب کرده
 * @property {() => number} [rng]
 */

/**
 * @param {RouteInput} input
 * @returns {{candidates:any[], blocked:{key:string,reason:string}[], strategy:string, comboId:string, category:string}}
 */
export function route( input ) {
	const hub = input.hub || {};
	const category = input.category || 'general';
	const rng = input.rng || Math.random;

	const combo = pickCombo( hub, category, input.comboId );
	const strategy = combo?.strategy || hub.routing?.strategy || 'auto';

	/** @type {any[]} */
	const pool = [];
	/** @type {{key:string, reason:string}[]} */
	const blocked = [];

	const members = combo?.members?.length ? combo.members : Object.keys( hub.models || {} );

	for ( const key of members ) {
		const model = hub.models?.[ key ];
		if ( ! model ) {
			blocked.push( { key, reason: 'مدل در رجیستری نیست' } );
			continue;
		}
		const conn = hub.connections?.[ model.connectionId ];
		if ( ! conn ) {
			blocked.push( { key, reason: 'اتصال پیدا نشد' } );
			continue;
		}
		if ( ! model.enabled ) {
			blocked.push( { key, reason: 'مدل خاموش است' } );
			continue;
		}
		if ( conn.enabled === false ) {
			blocked.push( { key, reason: 'اتصال خاموش است' } );
			continue;
		}
		if ( input.needsTools && model.caps?.tools === false ) {
			blocked.push( { key, reason: 'ابزار پشتیبانی نمی‌کند' } );
			continue;
		}
		if ( input.needsVision && ! model.caps?.vision ) {
			blocked.push( { key, reason: 'بینایی ندارد' } );
			continue;
		}
		if ( ! input.health.available( key ) ) {
			const e = input.health.entries.get( key );
			blocked.push( { key, reason: e?.exhausted ? 'اعتبار این اتصال تمام شده' : 'مدارشکن باز است' } );
			continue;
		}
		const cap = Number( conn.dailyCap );
		if ( Number.isFinite( cap ) && cap > 0 && ( input.health.entry( key ).usedToday || 0 ) >= cap ) {
			blocked.push( { key, reason: 'سقف روزانهٔ اتصال پر شده' } );
			continue;
		}
		pool.push( { key, model, conn } );
	}

	if ( input.pinModel ) {
		const pinned = pool.filter( ( c ) => c.key === input.pinModel );
		const rest = pool.filter( ( c ) => c.key !== input.pinModel );
		if ( pinned.length ) {
			return {
				candidates: [ ...pinned, ...sortBy( rest, 'priority', input, category, rng ) ].map( ( c ) => decorate( c, input, category ) ),
				blocked,
				strategy: 'pinned',
				comboId: combo?.id || '',
				category,
			};
		}
	}

	const ordered = sortBy( pool, strategy, input, category, rng );
	return {
		candidates: ordered.map( ( c ) => decorate( c, input, category ) ),
		blocked,
		strategy,
		comboId: combo?.id || '',
		category,
	};
}

/**
 * ترکیبی که باید استفاده شود: انتخاب صریح ← ترکیب پیش‌فرض این دسته ← هیچ (همهٔ مدل‌ها).
 * @param {any} hub
 * @param {string} category
 * @param {string} [comboId]
 */
export function pickCombo( hub, category, comboId ) {
	if ( comboId && hub.combos?.[ comboId ] ) {
		return hub.combos[ comboId ];
	}
	const byCategory = hub.categoryCombo?.[ category ];
	if ( byCategory && hub.combos?.[ byCategory ] ) {
		return hub.combos[ byCategory ];
	}
	return null;
}

/**
 * @param {any[]} pool
 * @param {string} strategy
 * @param {RouteInput} input
 * @param {string} category
 * @param {() => number} rng
 */
function sortBy( pool, strategy, input, category, rng ) {
	const list = [ ...pool ];

	switch ( strategy ) {
		case 'priority':
			// عدد کوچک‌تر یعنی جلوتر — مثل سطح اولویت در صف.
			return list.sort( ( a, b ) => order( a, b ) );

		case 'cost-optimized':
			return list.sort( ( a, b ) => costOf( a, input ) - costOf( b, input ) || order( a, b ) );

		case 'fastest':
			return list.sort( ( a, b ) => ( latencyOf( a, input ) ?? Infinity ) - ( latencyOf( b, input ) ?? Infinity ) || order( a, b ) );

		case 'least-used':
			return list.sort( ( a, b ) => usedOf( a, input ) - usedOf( b, input ) || order( a, b ) );

		case 'fill-first':
			// همان اولویت است، ولی معنایش فرق دارد: تا سهمیهٔ اولی پر نشده، سراغ دومی نرو.
			// «پرشدن» را فیلترِ بالادست (`dailyCap`) از فهرست بیرون می‌اندازد.
			return list.sort( ( a, b ) => order( a, b ) );

		case 'round-robin': {
			// چرخش بر اساس مجموع مصرف امروز: هرکه تازه استفاده شده، ته صف.
			const sorted = list.sort( ( a, b ) => order( a, b ) );
			const total = sorted.reduce( ( n, c ) => n + usedOf( c, input ), 0 );
			const shift = sorted.length ? total % sorted.length : 0;
			return [ ...sorted.slice( shift ), ...sorted.slice( 0, shift ) ];
		}

		case 'weighted': {
			// قرعه‌کشی به نسبت وزن، بدون جایگذاری: هر بار برنده از کیسه بیرون می‌آید.
			const out = [];
			const bag = [ ...list ];
			while ( bag.length ) {
				const sum = bag.reduce( ( n, c ) => n + Math.max( 0, c.model.weight || 1 ), 0 );
				let ticket = rng() * sum;
				let idx = bag.length - 1;
				for ( let i = 0; i < bag.length; i++ ) {
					ticket -= Math.max( 0, bag[ i ].model.weight || 1 );
					if ( ticket <= 0 ) {
						idx = i;
						break;
					}
				}
				out.push( bag.splice( idx, 1 )[ 0 ] );
			}
			return out;
		}

		case 'p2c': {
			// دو نامزد تصادفی، بهترشان جلو. پخش بار بدون تمرکز روی «بهترین» ثابت.
			const out = [];
			const bag = [ ...list ];
			while ( bag.length ) {
				if ( bag.length === 1 ) {
					out.push( bag.pop() );
					break;
				}
				const i = Math.floor( rng() * bag.length );
				let j = Math.floor( rng() * bag.length );
				if ( j === i ) {
					j = ( j + 1 ) % bag.length;
				}
				const winner = scoreOf( bag[ i ], input, category ) >= scoreOf( bag[ j ], input, category ) ? i : j;
				out.push( bag.splice( winner, 1 )[ 0 ] );
			}
			return out;
		}

		case 'auto':
		default:
			return list.sort( ( a, b ) => scoreOf( b, input, category ) - scoreOf( a, input, category ) || order( a, b ) );
	}
}

/**
 * امتیازدهی زندهٔ حالت خودکار — نُه عاملی که در سند طراحی شمرده شد.
 *
 * وزن‌ها جمعشان یک است تا امتیاز همیشه بین صفر و یک بماند و در رابط قابل نمایش باشد.
 *
 * @param {any} c
 * @param {RouteInput} input
 * @param {string} category
 */
export function scoreOf( c, input, category ) {
	const key = c.key;
	const health = input.health;
	const learning = input.learning;

	// ۱) تناسب برچسب: مدلی که مدیر برای همین زمینه علامت زده.
	const tagFit = ( c.model.tags || [] ).includes( category ) ? 1 : ( c.model.tags || [] ).length ? 0.35 : 0.5;

	// ۲) یادگیری از نتیجه — بند ۵ گفت این بر کاتالوگ اولیه اولویت دارد، پس وزنش بیشتر است.
	const learned = learning ? learning.score( key, category ) : 0.5;

	// ۳) نرخ موفقیت.
	const success = health.successRate( key );

	// ۴) تأخیر: صدک ۹۵ نمونه‌های اخیر، نرمال‌شده روی ۳۰ ثانیه.
	const p95 = health.latency( key, 0.95 );
	const speed = p95 === null ? 0.6 : Math.max( 0, Math.min( 1, 1 - p95 / 30_000 ) );

	// ۵) هزینه.
	const cost = costOf( c, input );
	const cheap = cost === null ? 0.5 : Math.max( 0, Math.min( 1, 1 - cost / 0.05 ) );

	// ۶) سهمیهٔ باقی‌مانده.
	const cap = Number( c.conn.dailyCap );
	const used = health.entry( key ).usedToday || 0;
	const quota = Number.isFinite( cap ) && cap > 0 ? Math.max( 0, 1 - used / cap ) : 1;

	// ۷) وضعیت مدارشکن: نیمه‌باز یعنی «داریم امتحان می‌کنیم»، نه «سالم است».
	const circuit = health.circuit( key ) === 'half-open' ? 0.3 : 1;

	// ۸) خطاهای اخیر.
	const recent = Math.max( 0, 1 - ( health.entry( key ).consecutiveFail || 0 ) / 3 );

	// ۹) نزدیکی به قفل‌شدن: چند درخواست هم‌زمان روی این اتصال در جریان است.
	const maxc = Number( c.conn.maxConcurrent ) || 4;
	const room = Math.max( 0, 1 - ( health.entry( key ).inFlight || 0 ) / maxc );

	const score =
		0.24 * learned +
		0.16 * tagFit +
		0.16 * success +
		0.12 * speed +
		0.1 * cheap +
		0.08 * quota +
		0.06 * circuit +
		0.05 * recent +
		0.03 * room;

	return Math.round( score * 1000 ) / 1000;
}

/** هزینهٔ تخمینی همین درخواست روی این مدل (دلار). */
export function costOf( c, input ) {
	const tokens = Number( input?.estimateTokens ) || 1000;
	const priceIn = c.model.priceIn ?? priceOf( c.model.modelId )?.in ?? null;
	const priceOut = c.model.priceOut ?? priceOf( c.model.modelId )?.out ?? null;
	if ( priceIn === null && priceOut === null ) {
		return null;
	}
	// تخمین محافظه‌کارانه: خروجی حدود یک‌چهارم ورودی.
	return ( ( priceIn || 0 ) * tokens + ( priceOut || 0 ) * tokens * 0.25 ) / 1_000_000;
}

function latencyOf( c, input ) {
	return input.health.latency( c.key, 0.95 );
}

function usedOf( c, input ) {
	return input.health.entry( c.key ).usedToday || 0;
}

function order( a, b ) {
	return ( a.model.priority || 100 ) - ( b.model.priority || 100 ) || ( a.conn.priority || 100 ) - ( b.conn.priority || 100 ) || ( a.key < b.key ? -1 : 1 );
}

function decorate( c, input, category ) {
	return {
		key: c.key,
		connectionId: c.model.connectionId,
		modelId: c.model.modelId,
		label: c.model.label || c.model.modelId,
		connectionLabel: c.conn.label,
		score: scoreOf( c, input, category ),
		cost: costOf( c, input ),
		p95: input.health.latency( c.key, 0.95 ),
		successRate: Math.round( input.health.successRate( c.key ) * 100 ) / 100,
		circuit: input.health.circuit( c.key ),
	};
}

export { modelKey };
