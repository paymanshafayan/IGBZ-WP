/**
 * دیف متنی — برای اینکه کارت «ویرایش فایل» واقعاً دیف نشان بدهد، نه یک جملهٔ خبری.
 *
 * الگوریتم LCS ساده روی خط‌هاست. برای فایل‌های معمولی (تا چند هزار خط) کافی است و
 * وابستگی خارجی هم لازم ندارد. اگر فایل خیلی بزرگ بود، به‌جای دیف، خلاصه برمی‌گردانیم؛
 * چون هزینهٔ O(n·m) روی فایل ده‌هزار خطی، هم کند است هم بی‌فایده.
 */

const MAX_DIFF_LINES = 4000;

/**
 * @param {string[]} a
 * @param {string[]} b
 * @returns {{type:'same'|'del'|'add', text:string}[]}
 */
function lcsScript( a, b ) {
	const n = a.length;
	const m = b.length;

	// جدول طول LCS.
	const table = new Uint32Array( ( n + 1 ) * ( m + 1 ) );
	const at = ( i, j ) => i * ( m + 1 ) + j;

	for ( let i = n - 1; i >= 0; i-- ) {
		for ( let j = m - 1; j >= 0; j-- ) {
			table[ at( i, j ) ] =
				a[ i ] === b[ j ]
					? table[ at( i + 1, j + 1 ) ] + 1
					: Math.max( table[ at( i + 1, j ) ], table[ at( i, j + 1 ) ] );
		}
	}

	/** @type {{type:'same'|'del'|'add', text:string}[]} */
	const out = [];
	let i = 0;
	let j = 0;
	while ( i < n && j < m ) {
		if ( a[ i ] === b[ j ] ) {
			out.push( { type: 'same', text: a[ i ] } );
			i++;
			j++;
		} else if ( table[ at( i + 1, j ) ] >= table[ at( i, j + 1 ) ] ) {
			out.push( { type: 'del', text: a[ i ] } );
			i++;
		} else {
			out.push( { type: 'add', text: b[ j ] } );
			j++;
		}
	}
	while ( i < n ) {
		out.push( { type: 'del', text: a[ i++ ] } );
	}
	while ( j < m ) {
		out.push( { type: 'add', text: b[ j++ ] } );
	}
	return out;
}

/**
 * دیف یکپارچه با شماره‌خط، به شکلی که رابط کاربری بتواند رنگش کند.
 *
 * @param {string} before
 * @param {string} after
 * @param {{context?:number, path?:string}} [opts]
 * @returns {{text:string, added:number, removed:number, truncated:boolean}}
 */
export function unifiedDiff( before, after, opts = {} ) {
	const context = opts.context ?? 3;
	const a = String( before ).split( '\n' );
	const b = String( after ).split( '\n' );

	if ( a.length + b.length > MAX_DIFF_LINES ) {
		return {
			text: `(فایل بزرگ است — دیف نمایش داده نشد؛ ${ a.length } خط → ${ b.length } خط)`,
			added: Math.max( 0, b.length - a.length ),
			removed: Math.max( 0, a.length - b.length ),
			truncated: true,
		};
	}

	const script = lcsScript( a, b );

	let added = 0;
	let removed = 0;
	for ( const s of script ) {
		if ( s.type === 'add' ) {
			added++;
		}
		if ( s.type === 'del' ) {
			removed++;
		}
	}

	if ( ! added && ! removed ) {
		return { text: '(بدون تغییر)', added: 0, removed: 0, truncated: false };
	}

	// فقط دور و بر تغییرها را نشان بده.
	const keep = new Array( script.length ).fill( false );
	script.forEach( ( s, idx ) => {
		if ( s.type === 'same' ) {
			return;
		}
		for ( let k = Math.max( 0, idx - context ); k <= Math.min( script.length - 1, idx + context ); k++ ) {
			keep[ k ] = true;
		}
	} );

	/** @type {string[]} */
	const lines = [];
	let lineA = 0;
	let lineB = 0;
	let gap = false;

	script.forEach( ( s, idx ) => {
		if ( s.type !== 'add' ) {
			lineA++;
		}
		if ( s.type !== 'del' ) {
			lineB++;
		}
		if ( ! keep[ idx ] ) {
			gap = true;
			return;
		}
		if ( gap ) {
			lines.push( '@@ …' );
			gap = false;
		}
		const num = s.type === 'add' ? lineB : lineA;
		const sign = s.type === 'add' ? '+' : s.type === 'del' ? '-' : ' ';
		lines.push( `${ sign }${ String( num ).padStart( 5 ) }  ${ s.text }` );
	} );

	const head = opts.path ? `--- ${ opts.path }\n+++ ${ opts.path }\n` : '';
	return { text: head + lines.join( '\n' ), added, removed, truncated: false };
}

/** خلاصهٔ یک‌خطی برای هدر کارت. */
export function diffStat( added, removed ) {
	return `+${ added } −${ removed }`;
}
