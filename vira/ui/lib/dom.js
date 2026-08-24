/** ابزارهای کوچک DOM — بدون فریم‌ورک، چون نه build داریم نه می‌خواهیم داشته باشیم. */
import { iconSvg } from './icons.js';

export const $ = ( s, root = document ) => root.querySelector( s );
export const $$ = ( s, root = document ) => [ ...root.querySelectorAll( s ) ];

/**
 * ساخت المان.
 * @param {string} tag
 * @param {string|null} [cls]
 * @param {string} [text]
 */
export function el( tag, cls, text ) {
	const n = document.createElement( tag );
	if ( cls ) {
		n.className = cls;
	}
	if ( text !== undefined ) {
		n.textContent = text;
	}
	return n;
}

/**
 * ساخت المان با ویژگی‌ها و فرزندان — برای فرم‌های تنظیمات که بدون این، خواندنشان سخت است.
 * @param {string} tag
 * @param {Record<string, any>} [attrs]
 * @param {(Node|string|null|undefined|false)[]} [children]
 */
export function h( tag, attrs = {}, children = [] ) {
	const n = document.createElement( tag );
	for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
		if ( v === undefined || v === null || v === false ) {
			continue;
		}
		if ( k === 'class' ) {
			n.className = v;
		} else if ( k === 'text' ) {
			n.textContent = v;
		} else if ( k === 'html' ) {
			n.innerHTML = v;
		} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
			n.addEventListener( k.slice( 2 ).toLowerCase(), v );
		} else if ( k === 'dataset' ) {
			Object.assign( n.dataset, v );
		} else if ( v === true ) {
			n.setAttribute( k, '' );
		} else {
			n.setAttribute( k, String( v ) );
		}
	}
	for ( const c of children.flat() ) {
		if ( c === null || c === undefined || c === false ) {
			continue;
		}
		n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
	}
	return n;
}

export const esc = ( s ) =>
	String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

/** @param {number} ts */
export function timeAgo( ts ) {
	const diff = Date.now() - Number( ts || 0 );
	const min = Math.round( diff / 60_000 );
	if ( min < 1 ) {
		return 'همین حالا';
	}
	if ( min < 60 ) {
		return `${ min } دقیقه پیش`;
	}
	const hr = Math.round( min / 60 );
	if ( hr < 24 ) {
		return `${ hr } ساعت پیش`;
	}
	const day = Math.round( hr / 24 );
	if ( day < 30 ) {
		return `${ day } روز پیش`;
	}
	return new Date( ts ).toLocaleDateString( 'fa-IR' );
}

/** @param {number} n */
export function fmtTokens( n ) {
	const v = Number( n ) || 0;
	if ( v < 1000 ) {
		return String( v );
	}
	if ( v < 1_000_000 ) {
		return `${ ( v / 1000 ).toFixed( 1 ) }k`;
	}
	return `${ ( v / 1_000_000 ).toFixed( 2 ) }M`;
}

/** یک toast کوچک، چون alert مرورگر وسط کار زشت است. */
export function toast( text, kind = '' ) {
	/*
	 * میزبان پیام باید همیشه در بالاترین لایهٔ دیدنی باشد: دیالوگِ بازشده با showModal
	 * در «top layer» مرورگر می‌نشیند و هیچ عنصری از body — با هر z-index — از آن
	 * بالاتر نمی‌رود. پس اگر دیالوگی باز است، میزبان پیام را داخل همان دیالوگ
	 * می‌بریم؛ وگرنه body. (باگ ثبت‌شدهٔ ۱۴۰۵/۰۵/۲۸: پیام‌های «تست اتصال» زیر مودال
	 * تنظیمات گم می‌شدند — DESIGN-PROVIDER-UI §۶.)
	 */
	const openDialog = [ ...document.querySelectorAll( 'dialog' ) ].filter( ( d ) => d.getAttribute( 'open' ) !== null ).pop();
	let host = $( '#toasts' );
	if ( ! host ) {
		host = el( 'div' );
		host.id = 'toasts';
	}
	( openDialog || document.body ).appendChild( host );
	const t = el( 'div', `toast ${ kind }`, text );
	host.appendChild( t );
	setTimeout( () => t.classList.add( 'in' ), 10 );
	setTimeout( () => {
		t.classList.remove( 'in' );
		setTimeout( () => t.remove(), 300 );
	}, 3600 );
}

/**
 * دیالوگ تأیید — جایگزین confirm بومی، تا با تم برنامه یکی باشد.
 * @param {string} message
 * @param {{confirmText?:string, danger?:boolean}} [opts]
 * @returns {Promise<boolean>}
 */
export function confirmDialog( message, opts = {} ) {
	return new Promise( ( resolve ) => {
		const dlg = h( 'dialog', { class: 'modal small' }, [
			h( 'div', { class: 'modal-body' }, [
				h( 'p', { class: 'confirm-text', text: message } ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => done( false ) } ),
					h( 'button', {
						class: `btn ${ opts.danger ? 'outline danger' : 'solid' }`,
						text: opts.confirmText || 'تأیید',
						onClick: () => done( true ),
					} ),
				] ),
			] ),
		] );

		function done( value ) {
			dlg.close();
			dlg.remove();
			resolve( value );
		}

		document.body.appendChild( dlg );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			done( false );
		} );
		dlg.showModal();
	} );
}

/**
 * دیالوگ ورودی متنی.
 * @param {string} label
 * @param {string} [value]
 * @returns {Promise<string|null>}
 */
export function promptDialog( label, value = '' ) {
	return new Promise( ( resolve ) => {
		const input = h( 'input', { type: 'text', value, class: 'field' } );
		const dlg = h( 'dialog', { class: 'modal small' }, [
			h( 'div', { class: 'modal-body' }, [
				h( 'label', { class: 'field-label' }, [ label, input ] ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => done( null ) } ),
					h( 'button', { class: 'btn solid', text: 'ذخیره', onClick: () => done( input.value ) } ),
				] ),
			] ),
		] );

		function done( v ) {
			dlg.close();
			dlg.remove();
			resolve( v );
		}

		document.body.appendChild( dlg );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			done( null );
		} );
		dlg.showModal();
		input.focus();
		input.select();
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				done( input.value );
			}
		} );
	} );
}

/** کپی در کلیپ‌بورد با پیام. */
export async function copyText( text ) {
	try {
		await navigator.clipboard.writeText( text );
		toast( 'کپی شد.' );
	} catch {
		toast( 'کپی نشد — دسترسی کلیپ‌بورد نداریم.', 'error' );
	}
}

/**
 * منوی راست‌کلیک — یک منوی شناور سرِ جای نشانگر.
 *
 * خواستهٔ کارفرما از روی رابط Claude: راست‌کلیک روی هر گفتگو باید منو باز کند. منو
 * `position: fixed` است چون باید از هر ظرفِ اسکرول‌داری بیرون بزند، و با کلیک بیرون،
 * Escape یا اسکرول بسته می‌شود.
 *
 * @param {{x:number, y:number, items:({label:string, ico?:string, hint?:string, danger?:boolean, onPick:()=>any}|'-')[]}} opts
 */
export function contextMenu( { x, y, items } ) {
	closeContextMenu();

	const menu = h( 'div', { class: 'pop-menu ctx-menu', id: 'ctx-menu' } );

	/**
	 * یک ردیف منو.
	 *
	 * ردیفی که زیرمنو دارد، **کنار** خودش یک منوی دوم باز می‌کند — نه اینکه جای منوی
	 * اول را بگیرد. (نسخهٔ قبل جایگزین می‌کرد و کارفرما درست گفت که آن چیزی نیست که
	 * می‌خواهد.)
	 */
	const rowOf = ( item ) => {
		if ( item === '-' ) {
			return h( 'div', { class: 'menu-sep' } );
		}
		const node = h( 'button', {
			class: `btn quiet row menu-item ${ item.danger ? 'danger' : '' }`,
			onClick: ( e ) => {
				e?.stopPropagation?.();
				if ( item.submenu ) {
					openSub( node, item.submenu() );
					return;
				}
				closeContextMenu();
				item.onPick();
			},
		}, [
			h( 'span', { class: 'm-ico', html: item.ico ? iconSvg( item.ico, 16 ) : '' } ),
			h( 'span', { text: item.label } ),
			item.submenu
				? h( 'span', { class: 'm-end', html: iconSvg( 'chevron-right', 12 ) } )
				: item.hint
				? h( 'span', { class: 'm-end', text: item.hint } )
				: null,
		] );
		if ( item.submenu ) {
			node.addEventListener( 'mouseenter', () => openSub( node, item.submenu() ) );
		}
		return node;
	};

	/** منوی دوم، چسبیده به همان ردیف. */
	function openSub( anchor, items2 ) {
		closeSub();
		const sub = h( 'div', { class: 'pop-menu ctx-menu ctx-sub', id: 'ctx-sub' }, items2.map( rowOf ) );
		document.body.appendChild( sub );

		const box = anchor.getBoundingClientRect?.() || { top: 0, left: 0, right: 0, width: 240 };
		const width = 240;
		const vw = globalThis.innerWidth || 1280;
		const vh = globalThis.innerHeight || 800;
		const rtl = ( document.documentElement?.dir || 'rtl' ) === 'rtl';
		// در راست‌به‌چپ، «کنار» یعنی سمت چپ؛ اگر جا نبود، آن طرف.
		let left = rtl ? box.left - width - 4 : box.right + 4;
		if ( left < 8 || left + width > vw - 8 ) {
			left = rtl ? box.right + 4 : box.left - width - 4;
		}
		sub.style.left = `${ Math.max( 8, Math.min( left, vw - width - 8 ) ) }px`;
		sub.style.top = `${ Math.max( 8, Math.min( box.top, vh - Math.min( items2.length * 34 + 12, 420 ) - 8 ) ) }px`;
		return sub;
	}

	menu.replaceChildren( ...items.map( rowOf ) );
	document.body.appendChild( menu );

	// نگذار از لبهٔ پایین/کنار صفحه بیرون بزند.
	const w = 240;
	const h1 = Math.min( items.length * 34 + 12, 420 );
	const vw = globalThis.innerWidth || 1280;
	const vh = globalThis.innerHeight || 800;
	menu.style.left = `${ Math.max( 8, Math.min( x, vw - w - 8 ) ) }px`;
	menu.style.top = `${ Math.max( 8, Math.min( y, vh - h1 - 8 ) ) }px`;

	/*
	 * بستن با کلیکِ بیرون — نه با هر کلیکی.
	 *
	 * نسخهٔ اول یک شنوندهٔ `{ once: true }` روی document می‌گذاشت؛ کلیک روی خودِ ردیف‌های
	 * منو هم به document می‌رسید و منو را همان لحظه می‌بست.
	 */
	setTimeout( () => {
		document.addEventListener( 'click', onCtxClick );
		document.addEventListener( 'keydown', onCtxKey );
	}, 0 );
	return menu;
}

/** بستن فقط منوی دوم. */
function closeSub() {
	$( '#ctx-sub' )?.remove();
}

function onCtxKey( e ) {
	if ( e.key === 'Escape' ) {
		closeContextMenu();
	}
}

function onCtxClick( e ) {
	const menu = $( '#ctx-menu' );
	const sub = $( '#ctx-sub' );
	if ( menu && ! menu.contains( e.target ) && ! sub?.contains( e.target ) ) {
		closeContextMenu();
	}
}

/** بستن منوی راست‌کلیک، اگر بازی هست. */
export function closeContextMenu() {
	document.removeEventListener( 'keydown', onCtxKey );
	document.removeEventListener( 'click', onCtxClick );
	closeSub();
	$( '#ctx-menu' )?.remove();
}
