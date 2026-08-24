/**
 * نوار کناری — همان شکلی که در تصاویر Claude هست.
 *
 * ترتیب از بالا: واژه‌نشان، «گفتگوی تازه»، ناوبری، یک گروه امکانات، «اخیر» با فهرست
 * گفتگوها، و ته نوار ردیف حساب که منویش از همان‌جا بالا می‌آید.
 *
 * دو چیز عمداً عوض شد نسبت به نسخهٔ قبل:
 *   ۱) گفتگوهای اخیر از ستون میانی به همین‌جا آمدند — Claude ستون میانی ندارد.
 *   ۲) ردیف‌های اخیر فقط **عنوان**اند؛ نه کارت، نه قاب، نه زیرنویس.
 */

import { $, h, timeAgo, toast, confirmDialog, promptDialog, contextMenu } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';
import { logoSvg } from './lib/logo.js';
import { t, lang, LANGS } from './lib/i18n.js';
import { iconSvg } from './lib/icons.js';

let onResume = () => {};
let onView = () => {};
let onCommand = () => {};
let sessions = [];

/** @param {{onResume:(id:string)=>void, onView:(view:string)=>void, onCommand:(name:string)=>void}} opts */
export function initSidebar( opts ) {
	onResume = opts.onResume;
	onView = opts.onView;
	onCommand = opts.onCommand || ( () => {} );

	for ( const b of document.querySelectorAll( '.nav-item[data-view]' ) ) {
		b.onclick = () => onView( b.dataset.view );
	}

	$( '#btn-collapse' ).onclick = () => {
		document.body.classList.toggle( 'sidebar-collapsed' );
		localStorage.setItem( 'vira-sidebar', document.body.classList.contains( 'sidebar-collapsed' ) ? '1' : '' );
	};
	if ( localStorage.getItem( 'vira-sidebar' ) ) {
		document.body.classList.add( 'sidebar-collapsed' );
	}

	$( '#btn-recents-more' ).onclick = () => onView( 'chats' );
	$( '#btn-account' ).onclick = ( e ) => {
		e.stopPropagation();
		toggleAccountMenu();
	};

	document.addEventListener( 'click', ( e ) => {
		const menu = $( '#account-menu' );
		if ( ! menu.hidden && ! menu.contains( e.target ) ) {
			menu.hidden = true;
		}
	} );
}

/** @param {string} view */
export function markActiveView( view ) {
	for ( const b of document.querySelectorAll( '.nav-item[data-view]' ) ) {
		b.classList.toggle( 'active', b.dataset.view === view );
	}
}

export async function refreshSessions() {
	const out = await api( '/api/sessions' );
	sessions = out.sessions || [];
	paint();
	return sessions;
}

export function allSessions() {
	return sessions;
}

/** گروه‌بندی زمانی، همان‌طور که Claude گفتگوها را دسته می‌کند. */
export function groupOf( updatedAt, now = Date.now() ) {
	const day = 86_400_000;
	const diff = now - Number( updatedAt || 0 );
	if ( diff < day ) {
		return 'امروز';
	}
	if ( diff < 7 * day ) {
		return 'هفت روز گذشته';
	}
	if ( diff < 30 * day ) {
		return 'سی روز گذشته';
	}
	return 'قدیمی‌تر';
}

function paint() {
	const box = $( '#session-list' );
	if ( ! box ) {
		return;
	}
	box.replaceChildren();

	const s = getState();
	if ( ! sessions.length ) {
		box.appendChild( h( 'div', { class: 'empty small', text: t( 'هنوز گفتگویی نیست' ) } ) );
		return;
	}

	// سنجاق‌شده‌ها بالا می‌مانند؛ بقیه به ترتیب زمان.
	const pins = pinned();
	const ordered = [ ...sessions ].sort( ( a, b ) => Number( pins.has( b.id ) ) - Number( pins.has( a.id ) ) );

	// در نوار کناری فقط چند تای آخر؛ بقیه در صفحهٔ «گفتگوها».
	for ( const item of ordered.slice( 0, 14 ) ) {
		const project = item.project ? item.project.split( /[\\/]/ ).filter( Boolean ).pop() : '';
		const row = h( 'div', { class: `recent-item ${ s?.sessionId === item.id ? 'active' : '' }` }, [
			pins.has( item.id ) ? h( 'span', { class: 'pin-dot', title: t( 'سنجاق‌شده' ), html: iconSvg( 'pin', 11 ) } ) : null,
			h( 'button', {
				class: 'btn quiet row rt',
				'data-no-t': '',
				title: `${ item.title }\n${ timeAgo( item.updatedAt ) }`,
				onClick: () => onResume( item.id ),
			}, [
				h( 'span', { class: 'rt-title', text: item.title || t( 'بدون عنوان' ) } ),
				/*
				 * نام پروژه، همان‌جا که کاربر آن را ست کرده.
				 *
				 * «افزودن به پروژه» کار می‌کرد ولی هیچ اثری دیده نمی‌شد — نه در نوار
				 * کناری و نه در منو — و کارفرما درست گفت «کار نمی‌کند». چیزی که دیده
				 * نمی‌شود، از نظر کاربر انجام نشده است.
				 */
				project ? h( 'span', { class: 'rt-project', 'data-no-t': '', text: project } ) : null,
			] ),
			h( 'button', {
				class: 'btn icon round quiet reveal row-menu',
				title: t( 'گزینه‌ها' ),
				html: iconSvg( 'more', 15 ),
				onClick: ( e ) => {
					e.stopPropagation();
					const box2 = e.currentTarget?.getBoundingClientRect?.() || { left: 60, bottom: 80 };
					sessionMenu( item, box2.left, box2.bottom + 4 );
				},
			} ),
		] );
		// راست‌کلیک، همان منو را سرِ جای نشانگر باز می‌کند — خواستهٔ کارفرما.
		row.oncontextmenu = ( e ) => {
			e.preventDefault?.();
			sessionMenu( item, e.clientX ?? 60, e.clientY ?? 80 );
			return false;
		};
		box.appendChild( row );
	}
}

const PIN_KEY = 'vira-pinned';

/** شناسهٔ گفتگوهای سنجاق‌شده. سمت مرورگر نگه داشته می‌شود؛ سرور از آن خبر ندارد. */
function pinned() {
	try {
		return new Set( JSON.parse( localStorage.getItem( PIN_KEY ) || '[]' ) );
	} catch {
		return new Set();
	}
}

/** @param {string} id */
function togglePin( id ) {
	const set = pinned();
	if ( set.has( id ) ) {
		set.delete( id );
	} else {
		set.add( id );
	}
	localStorage.setItem( PIN_KEY, JSON.stringify( [ ...set ] ) );
	paint();
}

/**
 * منوی یک گفتگو — چه از سه‌نقطه باز شود چه از راست‌کلیک.
 *
 * فهرست از روی تصویر Claude است، منهای دو قلم که در ویرا معنا ندارند: «علامت
 * خوانده‌نشده» (ویرا وضعیت خوانده/نخوانده ندارد) و «انتقال به گروه» (گروه نداریم).
 * گذاشتنِ دکمهٔ بی‌کار، بدتر از نگذاشتنش است.
 *
 * @param {{id:string, title?:string}} item
 */
function sessionMenu( item, x, y ) {
	const isPinned = pinned().has( item.id );
	contextMenu( {
		x,
		y,
		items: [
			{ ico: 'pin', label: isPinned ? t( 'برداشتن سنجاق' ) : t( 'سنجاق' ), hint: 'P', onPick: () => togglePin( item.id ) },
			{ ico: 'edit', label: t( 'تغییر نام' ), hint: 'R', onPick: () => renameSession( item ) },
			{ ico: 'folder-plus', label: t( 'افزودن به پروژه' ), submenu: () => projectItems( item ) },
			{ ico: 'open-external', label: t( 'باز کردن در تب تازه' ), onPick: () => openInNewTab( item.id ) },
			'-',
			{ ico: 'trash', label: t( 'حذف' ), hint: 'D', danger: true, onPick: () => removeSession( item ) },
		],
	} );
}

/**
 * فهرست پروژه‌ها برای زیرمنوی «افزودن به پروژه».
 *
 * پروژه در ویرا یک پوشه است، پس فهرست همان پوشه‌های اخیرِ همین مرورگر به‌علاوهٔ پوشهٔ
 * کاریِ فعلی است. انتخاب هر کدام، نسبتِ گفتگو را روی سرور ذخیره می‌کند — نه فقط در
 * حافظهٔ مرورگر — تا با باز و بسته‌شدن برنامه هم بماند.
 *
 * @param {{id:string, project?:string}} item
 */
function projectItems( item ) {
	const current = getState()?.config?.workspace || '';
	/** @type {string[]} */
	let list = [];
	try {
		list = JSON.parse( localStorage.getItem( 'vira-projects' ) || '[]' );
	} catch {
		list = [];
	}
	const all = [ ...new Set( [ current, ...list ].filter( Boolean ) ) ];

	const rows = all.map( ( dir ) => ( {
		ico: item.project === dir ? 'check' : 'projects',
		label: dir.split( /[\\/]/ ).filter( Boolean ).pop() || dir,
		hint: dir === current ? t( 'پروژهٔ فعلی' ) : '',
		onPick: () => assignProject( item, dir ),
	} ) );

	/*
	 * «پروژهٔ تازه» صفحهٔ پروژه‌ها را باز می‌کند و یادش می‌ماند که این گفتگو منتظر
	 * است؛ به‌محض ساخته‌شدن پروژه، گفتگو به همان اضافه می‌شود. این‌طور کاربر وسط راه
	 * دو جا کار نمی‌کند.
	 */
	rows.push( '-', {
		ico: 'folder-plus',
		label: t( 'پروژهٔ تازه' ),
		onPick: () => {
			document.dispatchEvent( new CustomEvent( 'vira:new-project-for', { detail: { id: item.id } } ) );
			onView( 'projects' );
		},
	} );

	if ( item.project ) {
		rows.push( { ico: 'times', label: t( 'برداشتن از پروژه' ), onPick: () => assignProject( item, '' ) } );
	}
	return rows;
}

/**
 * گفتگو را به یک پروژه می‌سپارد و **همان‌جا ادامه‌اش می‌دهد**.
 *
 * خواستهٔ کارفرما: «در انتها کاربر چت را در پروژهٔ انتخاب‌شده ادامه می‌دهد.» پس فقط
 * برچسب‌زدن کافی نیست؛ پوشهٔ کاری هم به همان پروژه می‌رود و گفتگو باز می‌شود.
 *
 * @param {{id:string}} item
 * @param {string} dir
 */
export async function assignProject( item, dir ) {
	const out = await post( '/api/sessions', { action: 'project', id: item.id, project: dir } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}

	if ( dir && dir !== getState()?.config?.workspace ) {
		const moved = await post( '/api/workspace', { path: dir } );
		if ( moved.error ) {
			toast( moved.error, 'error' );
		}
	}

	toast( dir ? `${ t( 'افزوده شد به' ) } ${ dir.split( /[\\/]/ ).filter( Boolean ).pop() || dir }` : t( 'از پروژه برداشته شد' ) );
	await refreshSessions();
	if ( dir ) {
		onResume( item.id );
	}
}

/** @param {string} id */
function openInNewTab( id ) {
	const url = `${ location.origin }/?session=${ encodeURIComponent( id ) }`;
	if ( typeof window.open === 'function' ) {
		window.open( url, '_blank' );
	}
}

async function renameSession( item ) {
	const title = await promptDialog( t( 'نام تازهٔ گفتگو' ), item.title || '' );
	if ( title === null || ! title.trim() ) {
		return;
	}
	await post( '/api/sessions', { action: 'rename', id: item.id, title } );
	await refreshSessions();
}

async function removeSession( item ) {
	if ( ! ( await confirmDialog( t( 'این گفتگو حذف شود؟' ), { danger: true } ) ) ) {
		return;
	}
	const out = await post( '/api/sessions', { action: 'delete', id: item.id } );
	if ( out.error ) {
		toast( out.error, 'error' );
	}
	await refreshSessions();
}

/**
 * منوی حساب — نظیر همان منویی که در Claude از روی ردیف پایین باز می‌شود.
 * @returns {void}
 */
function toggleAccountMenu() {
	const menu = $( '#account-menu' );
	if ( ! menu.hidden ) {
		menu.hidden = true;
		return;
	}

	const s = getState() || {};
	const item = ( ico, label, end, onClick ) =>
		h( 'button', { class: 'btn quiet row menu-item', onClick: () => {
			menu.hidden = true;
			onClick();
		} }, [
			h( 'span', { class: 'm-ico', html: iconSvg( ico, 16 ) } ),
			h( 'span', { text: label } ),
			end ? h( 'span', { class: 'm-end', text: end } ) : null,
		] );

	// نام زبانِ **دیگر** را نشان می‌دهیم، چون این ردیف یک کلید تعویض است نه یک برچسب.
	const other = LANGS.find( ( l ) => l.code !== lang() );

	menu.replaceChildren(
		h( 'div', { class: 'menu-mail', text: String( s.config?.workspace || '' ) } ),
		item( 'settings', t( 'تنظیمات' ), 'Ctrl+,', () => onCommand( 'settings' ) ),
		item( 'theme', t( 'ظاهر' ), '', () => onCommand( 'theme' ) ),
		item( 'language', t( 'زبان' ), other.label, () => onCommand( 'lang' ) ),
		item( 'help', t( 'راهنما و میان‌برها' ), '?', () => onCommand( 'shortcuts' ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( 'usage', t( 'مصرف و هزینه' ), '', () => onCommand( 'usage' ) ),
		item( 'status', t( 'وضعیت و تشخیص' ), '', () => onCommand( 'status' ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( 'reload', t( 'بارگذاری دوباره' ), '', () => onCommand( 'reload' ) )
	);
	menu.hidden = false;
}

/** @param {any} s */
export function paintSidebarState( s ) {
	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	const hub = s.hub?.active;
	$( '#account-name' ).textContent = hub ? t( 'هاب پرووایدر' ) : p.label || s.config.activeProfile || t( 'پروفایل' );
	/*
	 * فقط نام سرویس، بدون نام مدل.
	 *
	 * نام مدل هم اینجا بود هم روی خود کامپوزر؛ دوبار نوشتنش ردیف حساب را شلوغ می‌کرد و
	 * با شناسه‌های بلند (`openrouter/…`) از کادر می‌زد بیرون. خواستهٔ صریح کارفرما.
	 */
	$( '#chip-provider' ).textContent = hub ? t( 'مسیریابی خودکار' ) : p.provider || '—';
	// آواتار، نشان خودِ ویراست — همان‌جایی که در Claude دایرهٔ حساب می‌نشیند.
	const dot = $( '#account-initial' );
	if ( ! dot.innerHTML.includes( 'svg' ) ) {
		dot.innerHTML = logoSvg( 18, 'logo avatar-logo' );
	}

	const changed = ( s.git?.files || [] ).length;
	const badge = $( '#nav-changes-count' );
	badge.hidden = ! changed;
	badge.textContent = String( changed );

	paint();
}
