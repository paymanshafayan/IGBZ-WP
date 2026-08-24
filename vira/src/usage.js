/**
 * مصرف و هزینه.
 *
 * دو کار می‌کند: تخمین هزینهٔ دلاری از روی توکن‌ها، و نگه‌داشتن جمع مصرف روزانه در
 * `~/.vira/usage.json` تا `/usage` بتواند بگوید این هفته چقدر خرج شده.
 *
 * قیمت‌ها تقریبی و به‌ازای هر یک میلیون توکن‌اند. عمداً در یک جدول ساده‌اند تا کاربر
 * بتواند در config.json با کلید `pricing` عوضشان کند — قیمت‌ها هر چند ماه تکان می‌خورند و
 * ما نمی‌خواهیم برای هر تغییر قیمت، نسخهٔ جدید بدهیم.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { textOf } from './content.js';

/** @type {Record<string, {in:number, out:number}>} دلار به‌ازای یک میلیون توکن */
export const DEFAULT_PRICING = {
	'claude-opus-4': { in: 15, out: 75 },
	'claude-sonnet-4': { in: 3, out: 15 },
	'claude-3-5-haiku': { in: 0.8, out: 4 },
	'gpt-4o': { in: 2.5, out: 10 },
	'gpt-4o-mini': { in: 0.15, out: 0.6 },
	'gpt-4.1': { in: 2, out: 8 },
	'gpt-4.1-mini': { in: 0.4, out: 1.6 },
	o3: { in: 2, out: 8 },
	'o4-mini': { in: 1.1, out: 4.4 },
	'gemini-2.5-pro': { in: 1.25, out: 10 },
	'gemini-2.5-flash': { in: 0.3, out: 2.5 },
	'deepseek-chat': { in: 0.27, out: 1.1 },
	'qwen-max': { in: 1.6, out: 6.4 },
	'grok-4': { in: 3, out: 15 },
};

/**
 * قیمت مدل را با تطبیق پیشوندی پیدا می‌کند، چون نام واقعی مدل‌ها معمولاً دنباله دارد
 * (`claude-sonnet-4-20250514`, `gpt-4o-2024-11-20`).
 *
 * @param {string} model
 * @param {Record<string, {in:number,out:number}>} [table]
 */
export function priceOf( model, table ) {
	const all = { ...DEFAULT_PRICING, ...( table || {} ) };
	const name = String( model || '' ).toLowerCase();
	if ( all[ name ] ) {
		return all[ name ];
	}
	let best = null;
	for ( const [ key, value ] of Object.entries( all ) ) {
		if ( name.includes( key.toLowerCase() ) && ( ! best || key.length > best.key.length ) ) {
			best = { key, value };
		}
	}
	return best?.value || null;
}

/**
 * @param {string} model
 * @param {{inputTokens:number, outputTokens:number}} usage
 * @param {Record<string, {in:number,out:number}>} [table]
 */
export function estimateCost( model, usage, table ) {
	const price = priceOf( model, table );
	if ( ! price ) {
		return null;
	}
	const cost = ( ( usage.inputTokens || 0 ) * price.in + ( usage.outputTokens || 0 ) * price.out ) / 1_000_000;
	return Math.round( cost * 10_000 ) / 10_000;
}

/** @param {number} n */
export function formatTokens( n ) {
	const v = Number( n ) || 0;
	if ( v < 1000 ) {
		return String( v );
	}
	if ( v < 1_000_000 ) {
		return `${ ( v / 1000 ).toFixed( 1 ) }k`;
	}
	return `${ ( v / 1_000_000 ).toFixed( 2 ) }M`;
}

/** @param {string} home */
function usageFile( home ) {
	return path.join( home, 'usage.json' );
}

/**
 * ثبت مصرف یک نوبت در دفتر روزانه.
 *
 * @param {string} home
 * @param {{model:string, inputTokens:number, outputTokens:number, cost:number|null}} entry
 */
export async function recordUsage( home, entry ) {
	const file = usageFile( home );
	/** @type {any} */
	let data = { days: {} };
	try {
		data = JSON.parse( await fs.readFile( file, 'utf8' ) );
	} catch {
		// دفتر تازه است.
	}
	const day = new Date().toISOString().slice( 0, 10 );
	const bucket = ( data.days[ day ] = data.days[ day ] || { inputTokens: 0, outputTokens: 0, cost: 0, models: {} } );
	bucket.inputTokens += entry.inputTokens || 0;
	bucket.outputTokens += entry.outputTokens || 0;
	bucket.cost += entry.cost || 0;
	const m = ( bucket.models[ entry.model || 'نامعلوم' ] = bucket.models[ entry.model || 'نامعلوم' ] || {
		inputTokens: 0,
		outputTokens: 0,
		cost: 0,
	} );
	m.inputTokens += entry.inputTokens || 0;
	m.outputTokens += entry.outputTokens || 0;
	m.cost += entry.cost || 0;

	await fs.mkdir( home, { recursive: true } );
	await fs.writeFile( file, JSON.stringify( data, null, 2 ), 'utf8' );
	return data;
}

/** @param {string} home */
export async function readUsage( home ) {
	try {
		const data = JSON.parse( await fs.readFile( usageFile( home ), 'utf8' ) );
		const days = Object.entries( data.days || {} )
			.sort( ( a, b ) => ( a[ 0 ] < b[ 0 ] ? 1 : -1 ) )
			.slice( 0, 30 )
			.map( ( [ date, v ] ) => ( { date, ...v } ) );
		const total = days.reduce(
			( acc, d ) => ( {
				inputTokens: acc.inputTokens + ( d.inputTokens || 0 ),
				outputTokens: acc.outputTokens + ( d.outputTokens || 0 ),
				cost: acc.cost + ( d.cost || 0 ),
			} ),
			{ inputTokens: 0, outputTokens: 0, cost: 0 }
		);
		return { days, total };
	} catch {
		return { days: [], total: { inputTokens: 0, outputTokens: 0, cost: 0 } };
	}
}

/**
 * تخمین اندازهٔ کانتکست مصرف‌شده — برای نوار «چقدر از پنجره پر شده».
 * تقریب رایج: هر ۴ نویسه ≈ یک توکن (برای فارسی بدبینانه‌تر: هر ۳).
 *
 * @param {any[]} messages
 */
export function estimateContextTokens( messages ) {
	let chars = 0;
	for ( const m of messages || [] ) {
		chars += textOf( m?.content ?? '' ).length;
		for ( const c of m?.toolCalls || [] ) {
			chars += JSON.stringify( c?.input ?? {} ).length + 40;
		}
	}
	return Math.round( chars / 3.2 );
}
