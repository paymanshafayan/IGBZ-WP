/**
 * لاگر ویرا (۰.۹.۶) — پیشینهٔ درخواست کارفرما: «بخشی برای بررسی کامل خطاها».
 *
 * سه لایه: بافر حلقوی در حافظه (برای صفحهٔ لاگ‌ها) + سطر JSON در
 * `~/.vira/logs/vira.log` (برای بررسی پس از کرش) + کانال‌های موضوعی
 * (server/hub/tunnel/net/app). سطح‌ها: debug/info/warn/error.
 */

import fs from 'node:fs';
import path from 'node:path';
import { HOME } from './config.js';

const RING = 2000;

/** @type {{entries: any[], seq: number}} */
const state = { entries: [], seq: 1 };

function logPath() {
	return path.join( String( HOME ), 'logs', 'vira.log' );
}

function writeThrough( row ) {
	try {
		fs.mkdirSync( path.dirname( logPath() ), { recursive: true } );
		fs.appendFileSync( logPath(), JSON.stringify( row ) + '\n' );
	} catch {
		// نوشتن لاگ نباید خودش برنامه را بخواباند.
	}
}

/**
 * @param {'debug'|'info'|'warn'|'error'} level
 * @param {string} channel
 * @param {string} message
 * @param {any} [data] — زمینهٔ اضافه؛ هرگز راز/کلید خام ندهید.
 */
export function log( level, channel, message, data = undefined ) {
	const row = {
		id: state.seq++,
		at: new Date().toISOString(),
		level,
		channel,
		message: String( message ).slice( 0, 500 ),
		data: data === undefined ? null : data,
	};
	state.entries.push( row );
	if ( state.entries.length > RING ) {
		state.entries.shift();
	}
	if ( level !== 'debug' ) {
		writeThrough( row );
	}
	return row;
}

export const logError = ( channel, message, data ) => log( 'error', channel, message, data );
export const logWarn = ( channel, message, data ) => log( 'warn', channel, message, data );
export const logInfo = ( channel, message, data ) => log( 'info', channel, message, data );

/**
 * خواندن با فیلتر.
 * @param {{level?:string, channel?:string, q?:string, limit?:number}} [filter]
 */
export function recent( filter = {} ) {
	const limit = Math.min( Number( filter.limit ) || 300, RING );
	const q = String( filter.q || '' ).toLowerCase();
	const out = [];
	for ( let i = state.entries.length - 1; i >= 0 && out.length < limit; i -= 1 ) {
		const e = state.entries[ i ];
		if ( filter.level && filter.level !== 'all' && e.level !== filter.level ) { continue; }
		if ( filter.channel && filter.channel !== 'all' && e.channel !== filter.channel ) { continue; }
		if ( q && ! ( `${ e.message } ${ e.channel } ${ JSON.stringify( e.data ) }`.toLowerCase().includes( q ) ) ) { continue; }
		out.push( e );
	}
	return out;
}

export function clear() {
	const n = state.entries.length;
	state.entries = [];
	log( 'info', 'app', 'لاگ‌ها پاک شد.', { cleared: n } );
	return n;
}

export function channels() {
	return [ ...new Set( state.entries.map( ( e ) => e.channel ) ) ];
}
