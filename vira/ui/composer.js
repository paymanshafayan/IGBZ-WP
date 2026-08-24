/**
 * کامپوزر — کپی از Claude.
 *
 * چیدمان: کادر گرد، `+` سمت راست‌ترین (در RTL: ابتدای ردیف)، بعد فضای خالی، بعد نشان حالت،
 * نشان مدل با فلش، و دکمهٔ گرد ارسال. زیرش، همان جملهٔ کوچک «ویرا هم اشتباه می‌کند».
 *
 * منوی `+` همان کاری را می‌کند که در Claude: فایل و تصویر، کانکتورها، اسکیل‌ها، حالت کار.
 * یعنی چیزهای پرکاربرد **از داخل خود کامپوزر** در دسترس‌اند، نه ته یک پنجرهٔ تنظیمات.
 */

import { $, h, toast } from './lib/dom.js';
import { api, post, getState, refreshState } from './lib/api.js';
import { showWorking, hideWorking } from './thread.js';
import { speechSupported, startDictation, speak, stopSpeaking, isSpeaking } from './lib/voice.js';
import { iconSvg } from './lib/icons.js';
import { setGitLock } from './gitbar.js';

const MODES = [ 'plan', 'default', 'auto' ];
const MODE_LABEL = { plan: 'پلن', default: 'عادی', auto: 'خودکار' };
const MODE_HINT = {
	plan: 'فقط بررسی و خواندن — چیزی تغییر نمی‌کند',
	default: 'نوشتن و اجرا با تأیید تو',
	auto: 'بدون تأیید، جز آنچه ممنوع کرده‌ای',
};

let input;
let menu;
let items = [];
let index = 0;
let mode = 'default';
/** @type {string[]} */
let history = [];
let historyIndex = -1;
/** @type {{text:string, images:any[]}[]} */
const queue = [];
let busy = false;

/** @type {{name:string, mediaType:string, data:string, url:string}[]} */
let attachments = [];

/** @type {(text:string, images:any[])=>Promise<any>} */
let send = async () => {};
/** @type {(view:string)=>void} */
let openView = () => {};

/**
 * @param {{onSend:(text:string, images:any[])=>Promise<any>, onView:(v:string)=>void}} deps
 */
export function initComposer( deps ) {
	input = $( '#input' );
	menu = $( '#cmd-menu' );
	send = deps.onSend;
	openView = deps.onView;

	$( '#composer' ).onsubmit = async ( e ) => {
		e.preventDefault();
		await submit();
	};

	$( '#stop' ).onclick = () => post( '/api/stop', {} );
	$( '#pill-mode' ).onclick = () => cycleMode();
	$( '#pill-model' ).onclick = () => toggleModelMenu();
	$( '#btn-plus' ).onclick = () => togglePlusMenu();
	$( '#file-input' ).onchange = ( e ) => addFiles( e.target.files );
	$( '#btn-mic' ).onclick = () => toggleDictation();
	$( '#btn-voice' ).onclick = () => {
		if ( isSpeaking() ) {
			stopSpeaking();
			return;
		}
		const last = [ ...document.querySelectorAll( '.msg.assistant .md' ) ].pop();
		if ( ! last ) {
			toast( 'هنوز پاسخی برای خواندن نیست.' );
			return;
		}
		speak( last.textContent || '' );
	};

	input.addEventListener( 'input', () => {
		autoGrow();
		syncSendButton();
		refreshMenu();
	} );
	input.addEventListener( 'keydown', onKeyDown );

	input.addEventListener( 'paste', ( e ) => {
		const files = [ ...( e.clipboardData?.files || [] ) ].filter( ( f ) => f.type.startsWith( 'image/' ) );
		if ( files.length ) {
			e.preventDefault();
			addFiles( files );
		}
	} );

	const form = $( '#composer' );
	for ( const name of [ 'dragover', 'dragenter' ] ) {
		form.addEventListener( name, ( e ) => {
			if ( [ ...( e.dataTransfer?.types || [] ) ].includes( 'Files' ) ) {
				e.preventDefault();
				form.classList.add( 'dropping' );
			}
		} );
	}
	for ( const name of [ 'dragleave', 'drop' ] ) {
		form.addEventListener( name, () => form.classList.remove( 'dropping' ) );
	}
	form.addEventListener( 'drop', ( e ) => {
		const files = [ ...( e.dataTransfer?.files || [] ) ].filter( ( f ) => f.type.startsWith( 'image/' ) );
		if ( files.length ) {
			e.preventDefault();
			addFiles( files );
		}
	} );

	document.addEventListener( 'click', ( e ) => {
		closeIfOutside( '#plus-menu', '#btn-plus', e );
		closeIfOutside( '#model-menu', '#pill-model', e );
	} );
}

function closeIfOutside( menuSel, triggerSel, e ) {
	const box = $( menuSel );
	const trigger = $( triggerSel );
	if ( box && ! box.hidden && ! box.contains( e.target ) && ! trigger.contains( e.target ) ) {
		box.hidden = true;
	}
}

// ─────────────────────────────────────────────────────────── حالت و مدل

export function setMode( next ) {
	mode = MODES.includes( next ) ? next : 'default';
	const pill = $( '#pill-mode' );
	pill.textContent = MODE_LABEL[ mode ];
	pill.title = MODE_HINT[ mode ];
	pill.dataset.mode = mode;
	document.body.dataset.mode = mode;
}

export function currentMode() {
	return mode;
}

export async function cycleMode() {
	const next = MODES[ ( MODES.indexOf( mode ) + 1 ) % MODES.length ];
	setMode( next );
	await post( '/api/mode', { mode: next } );
	toast( `حالت: ${ MODE_LABEL[ next ] } — ${ MODE_HINT[ next ] }` );
}

export function setBusy( value ) {
	busy = value;
	syncSendButton();
	document.body.classList.toggle( 'busy', value );

	if ( value ) {
		showWorking();
	} else {
		hideWorking();
	}

	if ( ! value && queue.length ) {
		const next = queue.shift();
		paintQueue();
		submitText( next.text, next.images );
	}
}

export function focusComposer() {
	input?.focus();
}

// ──────────────────────────────────────────────────────────── ارسال

/**
 * سه دکمهٔ انتهای کامپوزر، که در هر لحظه فقط **یکی**شان هست.
 *
 * تصویرها این را دقیق نشان می‌دهند و اولش اشتباه پیاده کرده بودم:
 *
 *   کادر خالی        →  میکروفون + موج صدا، بدون دکمهٔ ارسال
 *   کاربر تایپ کرد   →  دکمهٔ ارسال **جای موج صدا** می‌نشیند
 *   در حال اجرا      →  دکمهٔ توقف جای هر دو
 *
 * نکتهٔ ظریف: ارسال کنار موج صدا **اضافه نمی‌شود**، جایش را می‌گیرد. برای همین در
 * چیدمان، `#send` بلافاصله بعد از `#btn-voice` است تا وقتی یکی پنهان می‌شود، دیگری
 * دقیقاً همان‌جا بیفتد و بقیهٔ نوار تکان نخورد.
 */
export function syncSendButton() {
	const send = $( '#send' );
	const voice = $( '#btn-voice' );
	const stop = $( '#stop' );
	if ( ! send ) {
		return;
	}
	const hasText = Boolean( $( '#input' )?.value.trim() ) || attachments.length > 0;

	if ( stop ) {
		stop.hidden = ! busy;
	}
	send.hidden = busy || ! hasText;
	if ( voice ) {
		voice.hidden = busy || hasText;
	}
}

/** کف و سقف ارتفاع کادر نوشتن — همان اعدادی که در CSS هم هستند (`--composer-h`). */
const BOX_MIN = 70;
const BOX_MAX = 168;

function autoGrow() {
	syncSendButton();
	input.style.height = 'auto';
	/*
	 * سقف و کف هر دو ثابت‌اند و از ارتفاع پنجره مستقل. قبلاً سقف ۴۰٪ ارتفاع پنجره بود و
	 * روی نمایشگر بلند یعنی نصف صفحه؛ و کفی هم نبود، پس مقدارِ خالی به چند پیکسل می‌افتاد
	 * و کادر می‌پرید.
	 */
	input.style.height = Math.max( BOX_MIN, Math.min( input.scrollHeight, BOX_MAX ) ) + 'px';
}

async function submit() {
	const text = input.value.trim();
	if ( ! text && ! attachments.length ) {
		return;
	}
	// با اولین پیام، مخزن و شاخهٔ این گفتگو قفل می‌شوند — همان قاعده‌ای که سرور هم دارد.
	setGitLock( true );
	const images = attachments.map( ( a ) => ( { name: a.name, mediaType: a.mediaType, data: a.data } ) );

	input.value = '';
	attachments = [];
	paintAttachments();
	autoGrow();
	menu.hidden = true;
	if ( text ) {
		history.unshift( text );
	}
	historyIndex = -1;

	if ( busy ) {
		queue.push( { text, images } );
		paintQueue();
		return;
	}
	await submitText( text, images );
}

async function submitText( text, images = [] ) {
	setBusy( true );
	const out = await send( text, images );
	if ( out?.error || out?.handled ) {
		setBusy( false );
	}
}

function paintQueue() {
	const box = $( '#queue' );
	box.replaceChildren();
	box.hidden = ! queue.length;
	queue.forEach( ( q, i ) => {
		box.appendChild(
			h( 'div', { class: 'queued' }, [
				h( 'span', { html: iconSvg( 'clock', 13 ) } ),
				h( 'span', { class: 'q-text', text: q.text || `${ q.images.length } تصویر` } ),
				h( 'button', {
					class: 'btn icon sm quiet',
					html: iconSvg( 'times', 13 ),
					onClick: () => {
						queue.splice( i, 1 );
						paintQueue();
					},
				} ),
			] )
		);
	} );
}

// ───────────────────────────────────────────────────────── پیوست‌ها

const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

/** @param {FileList|File[]} files */
async function addFiles( files ) {
	for ( const file of [ ...files ].slice( 0, 8 ) ) {
		if ( ! file.type.startsWith( 'image/' ) ) {
			continue;
		}
		if ( file.size > MAX_IMAGE_BYTES ) {
			toast( `«${ file.name }» بزرگ‌تر از ۴ مگابایت است.`, 'error' );
			continue;
		}
		const url = await readAsDataUrl( file );
		attachments.push( {
			name: file.name || 'تصویر',
			mediaType: file.type,
			data: url.replace( /^data:[^;]+;base64,/, '' ),
			url,
		} );
	}
	paintAttachments();
	input.focus();
}

function readAsDataUrl( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( String( reader.result || '' ) );
		reader.onerror = reject;
		reader.readAsDataURL( file );
	} );
}

function paintAttachments() {
	const box = $( '#attachments' );
	box.replaceChildren();
	box.hidden = ! attachments.length;
	attachments.forEach( ( a, i ) => {
		box.appendChild(
			h( 'div', { class: 'attachment', title: a.name }, [
				h( 'img', { src: a.url, alt: a.name } ),
				h( 'button', {
					class: 'btn icon sm quiet',
					type: 'button',
					html: iconSvg( 'times', 13 ),
					onClick: () => {
						attachments.splice( i, 1 );
						paintAttachments();
					},
				} ),
			] )
		);
	} );
}

// ─────────────────────────────────────────────────────── منوی «+»

function menuItem( ico, label, desc, onClick, checked ) {
	return h( 'div', { class: 'btn quiet row menu-item', onClick }, [
		h( 'span', { class: 'm-ico', html: iconSvg( ico, 16 ) } ),
		h( 'b', { text: label } ),
		desc ? h( 'span', { class: 'm-desc', text: desc } ) : null,
		checked ? h( 'span', { class: 'm-check', html: iconSvg( 'check', 13 ) } ) : null,
	] );
}

function togglePlusMenu() {
	const box = $( '#plus-menu' );
	if ( ! box.hidden ) {
		box.hidden = true;
		return;
	}
	$( '#model-menu' ).hidden = true;

	const s = getState();
	const close = () => ( box.hidden = true );
	const go = ( view ) => () => {
		close();
		openView( view );
	};

	// ترتیب و گروه‌بندی از روی تصویر منوی «+» در Claude است؛ چهار قلم اول همان‌هاست.
	box.replaceChildren(
		menuItem( 'camera', 'افزودن فایل یا تصویر', 'Ctrl+U — یا فقط بچسبان', () => {
			close();
			$( '#file-input' ).click();
		} ),
		menuItem( 'at', 'اشاره به فایل پروژه', 'جستجوی فازی', () => {
			close();
			insertAtCursor( '@' );
			refreshMenu();
		} ),
		menuItem( 'projects', 'پروژه', shortPath( s?.config?.workspace ), go( 'workspace' ) ),
		menuItem( 'skills', 'اسکیل‌ها', `${ ( s?.skills || [] ).length } نصب‌شده`, go( 'skills' ) ),
		menuItem( 'connectors', 'کانکتورها', `${ ( s?.connectors || [] ).length } تعریف‌شده`, go( 'connectors' ) ),
		menuItem( 'tools', 'ابزارها', `${ ( s?.tools || [] ).length } در دسترس`, go( 'tools' ) ),
		menuItem( 'subagents', 'زیرعامل‌ها', `${ ( s?.agents || [] ).length } تعریف‌شده`, go( 'agents' ) ),
		h( 'div', { class: 'menu-sep' } ),
		h( 'div', { class: 'menu-label', text: 'حالت کار' } ),
		...MODES.map( ( m ) =>
			menuItem(
				m === 'plan' ? '◇' : m === 'default' ? '◈' : '◆',
				MODE_LABEL[ m ],
				MODE_HINT[ m ],
				async () => {
					close();
					setMode( m );
					await post( '/api/mode', { mode: m } );
				},
				m === mode
			)
		),
		h( 'div', { class: 'menu-sep' } ),
		menuItem( '↶', 'بازگشت به چک‌پوینت', `${ ( s?.checkpoints || [] ).length } نقطه`, () => {
			close();
			document.dispatchEvent( new CustomEvent( 'vira:rewind' ) );
		} )
	);
	box.hidden = false;
}

function shortPath( p ) {
	const parts = String( p || '' ).split( /[\\/]/ ).filter( Boolean );
	return parts.slice( -2 ).join( '/' ) || '—';
}

// ─────────────────────────────────────────────────────── منوی مدل

async function toggleModelMenu() {
	const box = $( '#model-menu' );
	if ( ! box.hidden ) {
		box.hidden = true;
		return;
	}
	$( '#plus-menu' ).hidden = true;

	box.replaceChildren( h( 'div', { class: 'btn quiet row menu-item', text: 'در حال گرفتن فهرست مدل‌ها…' } ) );
	box.hidden = false;

	const out = await api( '/api/models' );
	const s = getState();
	const current = s?.config?.profiles?.[ s?.config?.activeProfile ]?.model;
	box.replaceChildren();

	if ( out.error || ! ( out.models || [] ).length ) {
		box.appendChild(
			h( 'div', { class: 'btn quiet row menu-item' }, [
				h( 'b', { text: 'فهرست نیامد' } ),
				h( 'span', { class: 'm-desc', text: out.error || 'این پرووایدر فهرست مدل نمی‌دهد.' } ),
			] )
		);
	} else {
		for ( const m of out.models.slice( 0, 80 ) ) {
			box.appendChild(
				menuItem(
					'',
					m,
					'',
					async () => {
						box.hidden = true;
						await post( '/api/message', { text: `/model ${ m }` } );
						await refreshState();
						toast( `مدل شد: ${ m }` );
					},
					m === current
				)
			);
		}
	}

	box.appendChild( h( 'div', { class: 'menu-sep' } ) );
	box.appendChild(
		menuItem( 'settings', 'تنظیمات پرووایدر', 'کلید، آدرس، پروفایل‌ها', () => {
			box.hidden = true;
			document.dispatchEvent( new CustomEvent( 'vira:settings', { detail: 'provider' } ) );
		} )
	);
}

// ─────────────────────────────────────────── منوهای «/» و «@»

function insertAtCursor( text ) {
	const start = input.selectionStart ?? input.value.length;
	const end = input.selectionEnd ?? input.value.length;
	input.value = input.value.slice( 0, start ) + text + input.value.slice( end );
	input.selectionStart = input.selectionEnd = start + text.length;
	input.focus();
	autoGrow();
}

function context() {
	const value = input.value;
	const pos = input.selectionStart ?? value.length;
	const before = value.slice( 0, pos );

	if ( /^\/[\w-]*$/.test( before ) && ! value.includes( '\n' ) ) {
		return { kind: 'command', query: before.slice( 1 ).toLowerCase(), start: 0 };
	}

	const at = before.lastIndexOf( '@' );
	if ( at > -1 && ! /\s/.test( before.slice( at + 1 ) ) && ( at === 0 || /\s/.test( before[ at - 1 ] ) ) ) {
		return { kind: 'file', query: before.slice( at + 1 ), start: at };
	}
	return null;
}

async function refreshMenu() {
	const ctx = context();
	if ( ! ctx ) {
		menu.hidden = true;
		items = [];
		return;
	}

	if ( ctx.kind === 'command' ) {
		const s = getState();
		items = ( s?.commands || [] )
			.filter( ( c ) => c.name.toLowerCase().startsWith( ctx.query ) )
			.slice( 0, 12 )
			.map( ( c ) => ( { label: `/${ c.name }`, hint: c.description || '', source: c.source, insert: `/${ c.name } `, start: 0 } ) );
	} else {
		const out = await api( `/api/files?q=${ encodeURIComponent( ctx.query ) }` );
		items = ( out.files || [] ).slice( 0, 12 ).map( ( f ) => ( { label: f, hint: '', source: 'فایل', insert: `@${ f } `, start: ctx.start } ) );
	}

	index = 0;
	paintMenu();
}

function paintMenu() {
	if ( ! items.length ) {
		menu.hidden = true;
		return;
	}
	menu.replaceChildren();
	items.forEach( ( it, i ) => {
		menu.appendChild(
			h( 'div', { class: `cmd-item ${ i === index ? 'active' : '' }`, onClick: () => pick( i ) }, [
				h( 'b', { text: it.label } ),
				h( 'span', { text: it.hint } ),
				h( 'em', { text: it.source } ),
			] )
		);
	} );
	menu.hidden = false;
}

function pick( i ) {
	const it = items[ i ];
	if ( ! it ) {
		return;
	}
	const pos = input.selectionStart ?? input.value.length;
	input.value = input.value.slice( 0, it.start ) + it.insert + input.value.slice( pos );
	input.selectionStart = input.selectionEnd = it.start + it.insert.length;
	menu.hidden = true;
	items = [];
	input.focus();
	autoGrow();
}

function onKeyDown( e ) {
	if ( ! menu.hidden && items.length ) {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			index = ( index + 1 ) % items.length;
			return paintMenu();
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			index = ( index - 1 + items.length ) % items.length;
			return paintMenu();
		}
		if ( e.key === 'Tab' || ( e.key === 'Enter' && ! e.shiftKey ) ) {
			e.preventDefault();
			return pick( index );
		}
		if ( e.key === 'Escape' ) {
			menu.hidden = true;
			return;
		}
	}

	if ( e.key === 'Tab' && e.shiftKey ) {
		e.preventDefault();
		cycleMode();
		return;
	}

	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		$( '#composer' ).requestSubmit();
		return;
	}

	if ( e.key === 'ArrowUp' && ! input.value.trim() && history.length ) {
		e.preventDefault();
		historyIndex = Math.min( historyIndex + 1, history.length - 1 );
		input.value = history[ historyIndex ];
		autoGrow();
		return;
	}
	if ( e.key === 'ArrowDown' && historyIndex > -1 ) {
		e.preventDefault();
		historyIndex--;
		input.value = historyIndex === -1 ? '' : history[ historyIndex ];
		autoGrow();
	}
}

export function fillComposer( text, submitNow = false ) {
	input.value = text;
	autoGrow();
	input.focus();
	if ( submitNow ) {
		$( '#composer' ).requestSubmit();
	}
}

export function composerIsEmpty() {
	return ! input.value.trim() && ! attachments.length;
}

// ─────────────────────────────────────────────────────── دیکتهٔ صوتی

/** @type {any} */
let recognizer = null;
let dictationBase = '';

export function toggleDictation() {
	const btn = $( '#btn-mic' );

	if ( recognizer ) {
		recognizer.stop();
		return;
	}

	if ( ! speechSupported() ) {
		toast( 'این مرورگر تشخیص گفتار ندارد. Chrome یا Edge را امتحان کن.', 'error' );
		return;
	}

	dictationBase = input.value ? `${ input.value.trim() } ` : '';
	btn.classList.add( 'recording' );
	toast( 'بگو… دوباره روی میکروفن بزن تا تمام شود.' );

	recognizer = startDictation( {
		onText: ( text ) => {
			input.value = dictationBase + text;
			autoGrow();
		},
		onEnd: () => {
			recognizer = null;
			btn.classList.remove( 'recording' );
			input.focus();
		},
		onError: ( message ) => {
			toast( message, 'error' );
			recognizer = null;
			btn.classList.remove( 'recording' );
		},
	} );

	if ( ! recognizer ) {
		btn.classList.remove( 'recording' );
	}
}
