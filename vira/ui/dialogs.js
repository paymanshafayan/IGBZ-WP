/** دیالوگ‌ها: نمایش فایل، بازگشت (rewind)، پالت فرمان، و راهنمای میان‌برها. */

import { el, h, toast, timeAgo } from './lib/dom.js';
import { api, getState } from './lib/api.js';
import { highlight } from './lib/markdown.js';

// ─────────────────────────────────────────────────────────── نمایش فایل

export async function openFile( relPath ) {
	const clean = String( relPath || '' ).trim().replace( /^\.\//, '' );
	const out = await api( `/api/file?path=${ encodeURIComponent( clean ) }` );

	const body = out.error
		? h( 'p', { class: 'note error', text: out.error } )
		: out.binary
		? h( 'p', { class: 'note', text: 'فایل باینری است.' } )
		: out.tooBig
		? h( 'p', { class: 'note', text: `فایل بزرگ است (${ out.size } بایت).` } )
		: h( 'pre', { class: 'file-view mono', html: numbered( out.text || '' ) } );

	const dlg = h( 'dialog', { class: 'modal wide' }, [
		h( 'div', { class: 'modal-body' }, [
			h( 'div', { class: 'panel-head' }, [ h( 'h2', { class: 'mono', text: clean } ) ] ),
			body,
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', { class: 'btn outline', text: 'بستن', onClick: () => close() } ),
			] ),
		] ),
	] );

	function close() {
		dlg.close();
		dlg.remove();
	}

	document.body.appendChild( dlg );
	dlg.addEventListener( 'close', () => dlg.remove() );
	dlg.showModal();
}

function numbered( text ) {
	return text
		.split( '\n' )
		.map( ( line, i ) => `<span class="ln">${ String( i + 1 ).padStart( 4 ) }</span>${ highlight( line ) }` )
		.join( '\n' );
}

// ──────────────────────────────────────────────────────────────── بازگشت

/** @param {(id:string, opts:any)=>void} run */
export async function openRewind( run, preselect ) {
	const out = await api( '/api/checkpoints' );
	const list = out.checkpoints || [];

	if ( ! list.length ) {
		toast( 'هنوز چک‌پوینتی ساخته نشده.' );
		return;
	}

	let chosen = preselect || list[ list.length - 1 ].id;
	const files = h( 'input', { type: 'checkbox', checked: true } );
	const convo = h( 'input', { type: 'checkbox', checked: true } );

	const rows = el( 'div', 'card-list' );
	const paint = () => {
		rows.replaceChildren();
		for ( const c of [ ...list ].reverse() ) {
			rows.appendChild(
				h( 'div', { class: `item ${ c.id === chosen ? 'active' : '' }`, onClick: () => {
					chosen = c.id;
					paint();
				} }, [
					h( 'div', { class: 'item-main' }, [
						h( 'b', { text: c.label || 'بدون عنوان' } ),
						h( 'p', { class: 'note', text: `${ timeAgo( c.at ) } · ${ c.fileCount } فایل · ${ c.messageCount } پیام` } ),
						c.files?.length ? h( 'p', { class: 'mono note', text: c.files.slice( 0, 6 ).join( '، ' ) } ) : null,
					] ),
					c.id === chosen ? h( 'span', { class: 'tag ok', text: 'انتخاب‌شده' } ) : null,
				] )
			);
		}
	};
	paint();

	const dlg = h( 'dialog', { class: 'modal wide' }, [
		h( 'div', { class: 'modal-body' }, [
			h( 'div', { class: 'panel-head' }, [ h( 'h2', { text: 'بازگشت به یک چک‌پوینت' } ) ] ),
			h( 'p', { class: 'note', text: 'فایل‌ها به وضعیت همان لحظه برمی‌گردند و گفتگو تا همان نقطه بریده می‌شود.' } ),
			rows,
			h( 'div', { class: 'row' }, [
				h( 'label', { class: 'check' }, [ files, h( 'span', { text: 'بازگرداندن فایل‌ها' } ) ] ),
				h( 'label', { class: 'check' }, [ convo, h( 'span', { text: 'بریدن گفتگو' } ) ] ),
			] ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => close() } ),
				h( 'button', {
					class: 'btn solid',
					text: 'برگرد',
					onClick: async () => {
						close();
						await run( chosen, { files: files.checked, conversation: convo.checked } );
					},
				} ),
			] ),
		] ),
	] );

	function close() {
		dlg.close();
		dlg.remove();
	}

	document.body.appendChild( dlg );
	dlg.addEventListener( 'close', () => dlg.remove() );
	dlg.showModal();
}

// ─────────────────────────────────────────────────────────── پالت فرمان

/**
 * Ctrl+K — همه‌چیز از یک جا: نشست‌ها، دستورها، فایل‌ها، تنظیمات.
 * @param {{onSession:(id:string)=>void, onCommand:(name:string)=>void, onFile:(p:string)=>void, onSettings:(tab:string)=>void}} deps
 */
export function openPalette( deps ) {
	const input = h( 'input', { class: 'palette-input', placeholder: 'جستجو: گفتگو، دستور، فایل، تنظیمات…' } );
	const list = el( 'div', 'palette-list' );
	const dlg = h( 'dialog', { class: 'modal palette' }, [ h( 'div', { class: 'modal-body' }, [ input, list ] ) ] );

	let items = [];
	let index = 0;

	const close = () => {
		dlg.close();
		dlg.remove();
	};

	const build = async () => {
		const q = input.value.trim().toLowerCase();
		const s = getState();
		/** @type {{label:string, hint:string, kind:string, run:()=>void}[]} */
		const out = [];

		for ( const t of [
			[ 'تنظیمات: پرووایدر و مدل', 'provider' ],
			[ 'تنظیمات: کانکتورها (MCP)', 'connectors' ],
			[ 'تنظیمات: اسکیل‌ها', 'skills' ],
			[ 'تنظیمات: پلاگین‌ها', 'plugins' ],
			[ 'تنظیمات: زیرعامل‌ها', 'agents' ],
			[ 'تنظیمات: دستورها', 'commands' ],
			[ 'تنظیمات: هوک‌ها', 'hooks' ],
			[ 'تنظیمات: مجوزها', 'permissions' ],
			[ 'تنظیمات: حافظهٔ پروژه', 'memory' ],
			[ 'تنظیمات: مصرف و هزینه', 'usage' ],
			[ 'تنظیمات: وضعیت', 'status' ],
		] ) {
			out.push( { label: t[ 0 ], hint: '', kind: 'تنظیمات', run: () => deps.onSettings( t[ 1 ] ) } );
		}

		for ( const c of s?.commands || [] ) {
			out.push( { label: `/${ c.name }`, hint: c.description || '', kind: 'دستور', run: () => deps.onCommand( c.name ) } );
		}

		const sessions = ( await api( '/api/sessions' ) ).sessions || [];
		for ( const x of sessions.slice( 0, 30 ) ) {
			out.push( { label: x.title || 'بدون عنوان', hint: timeAgo( x.updatedAt ), kind: 'گفتگو', data: true, run: () => deps.onSession( x.id ) } );
		}

		if ( q ) {
			const files = ( await api( `/api/files?q=${ encodeURIComponent( q ) }` ) ).files || [];
			for ( const f of files.slice( 0, 10 ) ) {
				out.push( { label: f, hint: '', kind: 'فایل', data: true, run: () => deps.onFile( f ) } );
			}
		}

		items = q ? out.filter( ( o ) => `${ o.label } ${ o.hint }`.toLowerCase().includes( q ) ) : out;
		items = items.slice( 0, 40 );
		index = 0;
		paint();
	};

	const paint = () => {
		list.replaceChildren();
		items.forEach( ( item, i ) => {
			list.appendChild(
				h( 'div', {
					class: `palette-item ${ i === index ? 'active' : '' }`,
					onClick: () => {
						close();
						item.run();
					},
				}, [
					h( 'span', { class: 'pal-kind', text: item.kind } ),
					h( 'b', { 'data-no-t': item.data ? '' : null, text: item.label } ),
					h( 'span', { class: 'note', text: item.hint } ),
				] )
			);
		} );
	};

	input.addEventListener( 'input', build );
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			index = Math.min( index + 1, items.length - 1 );
			paint();
			list.children[ index ]?.scrollIntoView( { block: 'nearest' } );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			index = Math.max( index - 1, 0 );
			paint();
			list.children[ index ]?.scrollIntoView( { block: 'nearest' } );
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			const item = items[ index ];
			if ( item ) {
				close();
				item.run();
			}
		}
	} );

	document.body.appendChild( dlg );
	dlg.addEventListener( 'close', () => dlg.remove() );
	dlg.showModal();
	input.focus();
	build();
}

// ──────────────────────────────────────────────────────────── میان‌برها

const SHORTCUTS = [
	[ 'Enter', 'ارسال پیام' ],
	[ 'Shift + Enter', 'خط تازه' ],
	[ 'Shift + Tab', 'چرخش حالت: پلن → عادی → خودکار' ],
	[ 'Esc', 'توقف کار در حال اجرا' ],
	[ 'Esc Esc', 'باز کردن بازگشت (rewind)' ],
	[ '@', 'اشاره به فایل' ],
	[ '/', 'دستورها' ],
	[ 'Ctrl + K', 'پالت فرمان' ],
	[ 'Ctrl + N', 'گفتگوی تازه' ],
	[ 'Ctrl + B', 'باز/بستن ریل کناری' ],
	[ 'Ctrl + ,', 'تنظیمات' ],
	[ 'Ctrl + M', 'میکروفن: گفتن به‌جای نوشتن' ],
	[ '↑', 'ویرایش آخرین پیام (وقتی کادر خالی است)' ],
	[ '?', 'همین راهنما (وقتی کادر خالی است)' ],
];

export function openShortcuts() {
	const dlg = h( 'dialog', { class: 'modal' }, [
		h( 'div', { class: 'modal-body' }, [
			h( 'div', { class: 'panel-head' }, [ h( 'h2', { text: 'میان‌برها' } ) ] ),
			h(
				'div',
				{ class: 'shortcuts' },
				SHORTCUTS.map( ( [ k, v ] ) => h( 'div', { class: 'shortcut' }, [ h( 'kbd', { text: k } ), h( 'span', { text: v } ) ] ) )
			),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', { class: 'btn outline', text: 'بستن', onClick: () => {
					dlg.close();
					dlg.remove();
				} } ),
			] ),
		] ),
	] );
	document.body.appendChild( dlg );
	dlg.addEventListener( 'close', () => dlg.remove() );
	dlg.showModal();
}
