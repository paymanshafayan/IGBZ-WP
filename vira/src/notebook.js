/**
 * دفترچه‌های Jupyter (`.ipynb`).
 *
 * یک نوت‌بوک از بیرون فقط یک فایل JSON است، ولی با `edit_file` دست‌زدن به آن فاجعه است:
 * متنِ هر سلول در واقع یک **آرایه از خط‌ها** است، خروجی‌ها و شمارهٔ اجرا کنارش نشسته‌اند، و
 * یک ویرگول جابه‌جا کل فایل را برای Jupyter غیرقابل‌خواندن می‌کند.
 *
 * پس دو کار جدا لازم است:
 *   render()  — نمایش خوانا برای مدل (سلول‌ها، نوع، شناسه، و خلاصهٔ خروجی)
 *   apply()   — ویرایش امن: جایگزینی، افزودن، حذف — با حفظ ساختار nbformat
 *
 * قاعده‌ای که رعایت می‌شود: وقتی متن یک سلول کد عوض شد، **خروجی قبلی‌اش پاک می‌شود** و
 * `execution_count` صفر می‌شود. خروجیِ کدِ قدیمی کنار کدِ جدید، دروغ است.
 */

import fs from 'node:fs/promises';
import crypto from 'node:crypto';

const MAX_OUTPUT_CHARS = 600;

/** @param {any} source متن سلول در nbformat یا رشته است یا آرایهٔ خط‌ها */
export function sourceToText( source ) {
	if ( Array.isArray( source ) ) {
		return source.join( '' );
	}
	return String( source ?? '' );
}

/**
 * برعکسش: متن به آرایهٔ خط‌ها، با همان قراردادی که خود Jupyter می‌نویسد —
 * هر خط `\n` خودش را دارد جز خط آخر.
 *
 * @param {string} text
 */
export function textToSource( text ) {
	const s = String( text ?? '' );
	if ( s === '' ) {
		return [];
	}
	const lines = s.split( '\n' );
	return lines.map( ( line, i ) => ( i === lines.length - 1 ? line : `${ line }\n` ) ).filter( ( l, i ) => ! ( l === '' && i === lines.length - 1 ) );
}

/** @param {any} cell */
function outputText( cell ) {
	const outs = Array.isArray( cell.outputs ) ? cell.outputs : [];
	/** @type {string[]} */
	const parts = [];
	for ( const o of outs ) {
		if ( o.output_type === 'stream' ) {
			parts.push( sourceToText( o.text ) );
		} else if ( o.output_type === 'error' ) {
			parts.push( `${ o.ename }: ${ o.evalue }` );
		} else if ( o.data ) {
			if ( o.data['text/plain'] ) {
				parts.push( sourceToText( o.data['text/plain'] ) );
			} else {
				parts.push( `[${ Object.keys( o.data ).join( '، ' ) }]` );
			}
		}
	}
	const text = parts.join( '\n' ).trim();
	return text.length > MAX_OUTPUT_CHARS ? `${ text.slice( 0, MAX_OUTPUT_CHARS ) }\n… (بریده شد)` : text;
}

/**
 * نمایش خوانای یک نوت‌بوک.
 * @param {any} nb
 */
export function render( nb ) {
	const cells = Array.isArray( nb?.cells ) ? nb.cells : [];
	const lang = nb?.metadata?.kernelspec?.language || nb?.metadata?.language_info?.name || 'نامشخص';

	/** @type {string[]} */
	const out = [ `نوت‌بوک: ${ cells.length } سلول · زبان: ${ lang } · nbformat ${ nb?.nbformat ?? '?' }`, '' ];

	cells.forEach( ( cell, index ) => {
		const id = cell.id || `#${ index }`;
		const head = `── سلول ${ index } [${ cell.cell_type }] id=${ id }${
			cell.execution_count ? ` اجرا=${ cell.execution_count }` : ''
		}`;
		out.push( head );
		out.push( sourceToText( cell.source ).replace( /\n$/, '' ) || '(خالی)' );

		const o = outputText( cell );
		if ( o ) {
			out.push( '   ↳ خروجی:' );
			out.push( o.split( '\n' ).map( ( l ) => `   │ ${ l }` ).join( '\n' ) );
		}
		out.push( '' );
	} );

	return out.join( '\n' ).trimEnd();
}

/**
 * پیداکردن سلول با شناسه یا شماره.
 * @param {any[]} cells
 * @param {string|number|undefined} ref
 */
export function findCell( cells, ref ) {
	if ( ref === undefined || ref === null || ref === '' ) {
		return -1;
	}
	const byId = cells.findIndex( ( c ) => String( c.id ) === String( ref ) );
	if ( byId > -1 ) {
		return byId;
	}
	if ( /^\d+$/.test( String( ref ) ) ) {
		const n = Number( ref );
		return n >= 0 && n < cells.length ? n : -1;
	}
	return -1;
}

/** شناسهٔ سلول به سبک nbformat 4.5 */
function newId() {
	return crypto.randomBytes( 4 ).toString( 'hex' );
}

/**
 * اعمال یک ویرایش روی شیء نوت‌بوک. شیء ورودی تغییر نمی‌کند؛ نسخهٔ تازه برمی‌گردد.
 *
 * @param {any} nb
 * @param {{mode:'replace'|'insert'|'delete', cell?:string|number, cellType?:'code'|'markdown', source?:string}} edit
 * @returns {{notebook:any, message:string, index:number}}
 */
export function apply( nb, edit ) {
	const notebook = JSON.parse( JSON.stringify( nb ) );
	if ( ! Array.isArray( notebook.cells ) ) {
		notebook.cells = [];
	}
	const cells = notebook.cells;
	const mode = edit.mode || 'replace';

	if ( mode === 'delete' ) {
		const index = findCell( cells, edit.cell );
		if ( index === -1 ) {
			throw new Error( `سلولی با شناسهٔ «${ edit.cell }» پیدا نشد.` );
		}
		cells.splice( index, 1 );
		return { notebook, message: `سلول ${ index } حذف شد`, index };
	}

	if ( mode === 'insert' ) {
		const type = edit.cellType || 'code';
		if ( type !== 'code' && type !== 'markdown' ) {
			throw new Error( 'نوع سلول باید code یا markdown باشد.' );
		}
		const at = edit.cell === undefined || edit.cell === '' ? cells.length : findCell( cells, edit.cell );
		if ( at === -1 ) {
			throw new Error( `سلولی با شناسهٔ «${ edit.cell }» پیدا نشد.` );
		}
		/** @type {any} */
		const cell = {
			cell_type: type,
			id: newId(),
			metadata: {},
			source: textToSource( edit.source || '' ),
		};
		if ( type === 'code' ) {
			cell.execution_count = null;
			cell.outputs = [];
		}
		cells.splice( at, 0, cell );
		return { notebook, message: `سلول ${ type } در جایگاه ${ at } افزوده شد`, index: at };
	}

	// replace
	const index = findCell( cells, edit.cell );
	if ( index === -1 ) {
		throw new Error( `سلولی با شناسهٔ «${ edit.cell }» پیدا نشد.` );
	}
	const cell = cells[ index ];
	if ( edit.cellType && edit.cellType !== cell.cell_type ) {
		cell.cell_type = edit.cellType;
		if ( edit.cellType === 'markdown' ) {
			delete cell.outputs;
			delete cell.execution_count;
		} else {
			cell.outputs = [];
			cell.execution_count = null;
		}
	}
	cell.source = textToSource( edit.source ?? '' );

	// خروجیِ کدِ قدیمی کنار کدِ جدید، دروغ است.
	if ( cell.cell_type === 'code' ) {
		cell.outputs = [];
		cell.execution_count = null;
	}

	return { notebook, message: `سلول ${ index } بازنویسی شد`, index };
}

/**
 * خواندن و تجزیهٔ یک فایل نوت‌بوک با خطای قابل‌فهم.
 * @param {string} file
 */
export async function readNotebook( file ) {
	const raw = await fs.readFile( file, 'utf8' );
	try {
		const nb = JSON.parse( raw );
		if ( ! nb || typeof nb !== 'object' ) {
			throw new Error( 'ساختار نامعتبر' );
		}
		return { nb, raw };
	} catch ( e ) {
		throw new Error( `این فایل یک نوت‌بوک معتبر نیست: ${ e?.message || e }` );
	}
}

/**
 * نوشتن نوت‌بوک با همان قالبی که Jupyter می‌نویسد (تورفتگی یک فاصله و یک خط خالی آخر)،
 * تا دیفِ گیت با ذخیرهٔ بعدیِ خود Jupyter پر از نویز نشود.
 *
 * @param {any} nb
 */
export function serialize( nb ) {
	return `${ JSON.stringify( nb, null, 1 ) }\n`;
}
