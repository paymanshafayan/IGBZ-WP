/**
 * نگه‌داری هاب روی دیسک.
 *
 * سه فایل جدا، چون سه عمر متفاوت دارند:
 *   hub.json         — تعریفِ مدیر. کم عوض می‌شود، ارزش پشتیبان دارد.
 *   hub-state.json   — سلامت، آمار و امتیاز یادگیری. مدام عوض می‌شود، پاک‌شدنش فاجعه نیست.
 *   hub-ledger.json  — دفتر راه‌حل‌ها. چیزی که ابزار «یاد گرفته» و نباید با ریست آمار برود.
 *
 * نوشتن اتمیک است (فایل موقت + rename): یک کرش وسط نوشتن نباید تنظیمات مدیر را
 * به یک JSON نیمه‌کاره تبدیل کند.
 */

import fs from 'node:fs/promises';
import fssync from 'node:fs';
import path from 'node:path';
import { defaultHub } from './schema.js';

export const HUB_FILE = 'hub.json';
export const STATE_FILE = 'hub-state.json';
export const LEDGER_FILE = 'hub-ledger.json';

/**
 * @param {string} file
 * @param {any} fallback
 */
export async function readJson( file, fallback ) {
	try {
		return JSON.parse( await fs.readFile( file, 'utf8' ) );
	} catch {
		return fallback;
	}
}

/**
 * @param {string} file
 * @param {any} data
 * @param {{secret?:boolean}} [opts]
 */
export async function writeJson( file, data, opts = {} ) {
	await fs.mkdir( path.dirname( file ), { recursive: true } );
	const tmp = `${ file }.${ process.pid }.tmp`;
	await fs.writeFile( tmp, JSON.stringify( data, null, 2 ), 'utf8' );
	await fs.rename( tmp, file );
	if ( opts.secret ) {
		try {
			fssync.chmodSync( file, 0o600 );
		} catch {
			// روی ویندوز اهمیتی ندارد.
		}
	}
	return data;
}

/** @param {string} home */
export async function loadHub( home ) {
	const stored = await readJson( path.join( home, HUB_FILE ), null );
	if ( ! stored ) {
		return defaultHub();
	}
	// ادغام کم‌عمق کافی نیست: بخش‌های تودرتو باید کلیدهای تازهٔ نسخه‌های بعدی را بگیرند.
	const base = defaultHub();
	return {
		...base,
		...stored,
		routing: { ...base.routing, ...( stored.routing || {} ) },
		budget: { ...base.budget, ...( stored.budget || {} ) },
		cache: { ...base.cache, ...( stored.cache || {} ) },
		diagnoser: { ...base.diagnoser, ...( stored.diagnoser || {} ) },
		connections: stored.connections || {},
		models: stored.models || {},
		combos: stored.combos || {},
		categoryCombo: stored.categoryCombo || {},
	};
}

/**
 * @param {string} home
 * @param {any} hub
 */
export function saveHub( home, hub ) {
	// کلید API داخلش هست، پس مثل config.json روی ۶۰۰ بسته می‌شود.
	return writeJson( path.join( home, HUB_FILE ), hub, { secret: true } );
}

/** @param {string} home */
export function loadHubState( home ) {
	return readJson( path.join( home, STATE_FILE ), { models: {}, connections: {}, learning: {}, spend: {}, rr: {} } );
}

/**
 * @param {string} home
 * @param {any} state
 */
export function saveHubState( home, state ) {
	return writeJson( path.join( home, STATE_FILE ), state );
}

/** @param {string} home */
export function loadLedger( home ) {
	return readJson( path.join( home, LEDGER_FILE ), { entries: {} } );
}

/**
 * @param {string} home
 * @param {any} ledger
 */
export function saveLedger( home, ledger ) {
	return writeJson( path.join( home, LEDGER_FILE ), ledger );
}
