/**
 * ناحیهٔ گفتگو: پیام‌ها، کارت ابزارها، دروازهٔ تأیید، کارت نقشه و کارت پرسش.
 *
 * قاعدهٔ طراحی که از Claude Code گرفته‌ایم: **هر ابزار نمایش خودش را دارد**. یک <pre> برای
 * همه‌چیز، همان چیزی است که باعث می‌شود یک ابزار جدی، اسباب‌بازی به‌نظر برسد.
 */

import { $, el, h, esc, copyText, promptDialog } from './lib/dom.js';
import { markdown, wireCodeCopy } from './lib/markdown.js';
import { logoLiveSvg, logoSvg } from './lib/logo.js';
import { speak, stopSpeaking, isSpeaking, ttsSupported } from './lib/voice.js';
import { post } from './lib/api.js';
import { iconSvg } from './lib/icons.js';

let chat = null;
let streamEl = null;
let thinkEl = null;
/** @type {HTMLElement|null} دکمهٔ «برو به آخر» — وضعیتش از چند جا همگام می‌شود. */
let jumpBtn = null;
const toolEls = new Map();
/** @type {(text:string)=>void} */
let onResend = () => {};
/** @type {(path:string)=>void} */
let onOpenFile = () => {};

/**
 * @param {{root:HTMLElement, onResend:(t:string)=>void, onOpenFile:(p:string)=>void}} opts
 */
export function mountThread( opts ) {
	chat = opts.root;
	onResend = opts.onResend;
	onOpenFile = opts.onOpenFile;

	const jump = h( 'button', {
		class: 'btn icon round jump-down',
		id: 'jump-down',
		title: 'برو به آخر',
		hidden: true,
		onClick: () => scrollToEnd(),
	}, [ h( 'span', { html: iconSvg( 'jump-down', 14 ) } ) ] );
	/*
	 * داخل کانتینر خودش، نه چسبیده به نمای گفتگو.
	 *
	 * قبلاً با `translateX(50%)` وسط‌چین می‌شد و لحن `outline` در هاور همان transform را
	 * با `translateY(-1px)` بازنویسی می‌کرد — یعنی دکمه با نزدیک‌شدن نشانگر به چپ می‌پرید.
	 */
	( document.getElementById( 'jump-slot' ) || chat.parentElement ).appendChild( jump );

	/*
	 * وضعیت دکمه فقط با اسکرولِ کاربر به‌روز نمی‌شود.
	 *
	 * باگ تا ۰.۹.۶: دکمه `hidden: true` ساخته می‌شد و تنها جای عوض‌شدنش داخل شنوندهٔ
	 * `scroll` بود. تا کاربر دست به اسکرول نزند آن هندلر اجرا نمی‌شد — یعنی دقیقاً در
	 * پرکاربردترین حالت (پاسخ بلندی که خودش صفحه را پر می‌کند) دکمه پنهان می‌ماند.
	 * حالا محتوای تازه و تغییر اندازه هم آن را همگام می‌کنند.
	 */
	jumpBtn = jump;
	syncJump();
	chat.addEventListener( 'scroll', syncJump );

	// رشدِ خودِ محتوا (استریم پاسخ، باز/بستهٔ بلوک استدلال) هم اسکرول نیست.
	if ( typeof ResizeObserver !== 'undefined' ) {
		new ResizeObserver( syncJump ).observe( chat );
	}
	window.addEventListener( 'resize', syncJump );
}

/** همگام‌سازی دکمهٔ «برو به آخر» با وضعیت واقعی اسکرول. */
export function syncJump() {
	if ( ! jumpBtn || ! chat ) {
		return;
	}
	// وقتی محتوا کوتاه‌تر از قاب است، اصلاً اسکرولی در کار نیست.
	const scrollable = chat.scrollHeight - chat.clientHeight > 8;
	jumpBtn.hidden = ! scrollable || atBottom();
}

export function clearThread() {
	chat.replaceChildren();
	toolEls.clear();
	streamEl = null;
	thinkEl = null;
	clearInterval( workingTimer );
	workingTimer = null;
	workingEl = null;
}

/**
 * دکمهٔ کوچک آیکونی — همان ردیفی که در Claude زیر هر پاسخ می‌آید.
 * @param {string} title
 * @param {string} name نام آیکون در `lib/icons.js`
 * @param {()=>void} onClick
 */
function iconBtn( title, name, onClick ) {
	const b = el( 'button', 'btn icon sm quiet' );
	b.title = title;
	b.innerHTML = iconSvg( name, 15 );
	b.onclick = onClick;
	return b;
}

/** آخرین چیزی که کاربر گفته — برای دکمهٔ «دوباره» زیر پاسخ. */
function lastUserText() {
	const all = [ ...chat.querySelectorAll( '.msg.user .body' ) ];
	return all.length ? all[ all.length - 1 ].textContent || '' : '';
}

function atBottom() {
	return chat.scrollHeight - chat.scrollTop - chat.clientHeight < 160;
}

export function scrollToEnd() {
	chat.scrollTop = chat.scrollHeight;
}

function append( node ) {
	const stick = atBottom();
	// خوش‌آمد در ظرف خودش بیرون از رشتهٔ گفتگو است؛ با آمدن اولین چیز، برداشته می‌شود.
	$( '#welcome' )?.remove();
	chat.appendChild( node );
	if ( stick ) {
		scrollToEnd();
	}
	syncJump();
	return node;
}

// ─────────────────────────────────────────────────────────────────── پیام‌ها

export function addMessage( role, text, asMarkdown = true, images = [] ) {

	const wrap = el( 'div', `msg ${ role }` );

	/*
	 * پاسخ مدل، نشان کنارش دارد.
	 *
	 * در نسخهٔ قبل حذفش کرده بودم چون در تصویر صفحهٔ خالی دیده نمی‌شد. طرحی که کارفرما
	 * پذیرفت آن را دارد: یک ستارهٔ کوچک در ابتدای هر پاسخ، هم‌راستا با خط اول متن.
	 */
	if ( role === 'assistant' ) {
		wrap.appendChild( h( 'span', { class: 'msg-mark', html: logoSvg( 24 ) } ) );
	}

	const col = role === 'assistant' ? el( 'div', 'msg-col' ) : wrap;
	if ( col !== wrap ) {
		wrap.appendChild( col );
	}
	const body = el( 'div', 'body' );
	if ( asMarkdown ) {
		body.innerHTML = markdown( text );
		wireCodeCopy( body );
	} else {
		body.textContent = text;
	}
	col.appendChild( body );

	if ( images?.length ) {
		const strip = el( 'div', 'msg-images' );
		for ( const img of images ) {
			strip.appendChild(
				img.data
					? h( 'img', { src: `data:${ img.mediaType };base64,${ img.data }`, alt: img.name || 'تصویر' } )
					: h( 'span', { class: 'img-chip', text: `🖼 ${ img.name || 'تصویر' }` } )
			);
		}
		col.appendChild( strip );
	}

	const actions = el( 'div', 'msg-actions' );
	actions.appendChild(
		iconBtn( 'کپی', 'copy', () =>
			copyText( body.dataset.raw || body.textContent || '' )
		)
	);
	actions.appendChild(
		iconBtn( 'دوباره', 'retry', () =>
			onResend( role === 'user' ? body.textContent || '' : lastUserText() )
		)
	);

	// بلندخوانی — برای وقتی که جواب بلند است و حوصلهٔ خواندن نیست.
	if ( role === 'assistant' && ttsSupported() ) {
		actions.appendChild(
			iconBtn( 'بخوان', 'speak', () => {
				if ( isSpeaking() ) {
					stopSpeaking();
					return;
				}
				speak( body.dataset.raw || body.textContent || '' );
			} )
		);
	}

	col.appendChild( actions );
	append( wrap );
	return body;
}

// ────────────────────────────────── نشانگر «در حال کار» — ستارهٔ Claude

let workingEl = null;
let workingTimer = null;
let workingStart = 0;

/**
 * پیام‌هایی که کنار نشان متحرک می‌آیند.
 *
 * چرا برای هر ابزار یک جمله: «در حال کار» به کاربر هیچ نمی‌گوید. وقتی می‌نویسد «در حال
 * خواندن فایل» یا «در حال اجرای فرمان»، کاربر می‌فهمد کجای کار است و اگر طول کشید، می‌داند
 * چه چیزی طول کشیده.
 */
const WORKING_LABEL = {
	read_file: 'در حال خواندن فایل',
	write_file: 'در حال نوشتن فایل',
	edit_file: 'در حال ویرایش فایل',
	multi_edit: 'در حال ویرایش فایل',
	notebook_edit: 'در حال ویرایش نوت‌بوک',
	list_dir: 'در حال دیدن پوشه',
	glob: 'در حال گشتن دنبال فایل',
	grep: 'در حال جستجو در کد',
	bash: 'در حال اجرای فرمان',
	bash_output: 'در حال خواندن خروجی',
	kill_shell: 'در حال متوقف‌کردن شل',
	web_fetch: 'در حال گرفتن صفحه',
	web_search: 'در حال جستجو در وب',
	todo_write: 'در حال به‌روزکردن فهرست کار',
	skill: 'در حال باز کردن اسکیل',
	task: 'زیرعامل در حال کار',
	read_mcp_resource: 'در حال خواندن منبع',
};

/** @param {string} name */
export function workingLabelFor( name ) {
	if ( WORKING_LABEL[ name ] ) {
		return WORKING_LABEL[ name ];
	}
	if ( String( name ).startsWith( 'mcp__' ) ) {
		return `در حال کار با ${ String( name ).split( '__' )[ 1 ] }`;
	}
	return 'در حال کار';
}

/**
 * نشانگر «مشغولم» — نشان متحرک ویرا، یک جملهٔ گویا، و شمارندهٔ ثانیه.
 *
 * همیشه به **آخر** گفتگو منتقل می‌شود، چون وسط کار مدام کارت ابزار زیرش اضافه می‌شود و
 * اگر جا نماند، نشانگر بالای صفحه گم می‌شود.
 *
 * @param {string} [label]
 */
export function showWorking( label = 'در حال فکر کردن' ) {
	if ( ! workingEl ) {
		workingEl = h( 'div', { class: 'working' }, [
			h( 'span', { class: 'work-icon', html: logoLiveSvg( 20 ) } ),
			h( 'span', { class: 'label', text: label } ),
			h( 'span', { class: 'elapsed' } ),
			h( 'span', { class: 'hint-esc', text: 'Esc برای توقف' } ),
		] );
		workingStart = Date.now();
		workingTimer = setInterval( tickElapsed, 1000 );
	}

	workingEl.querySelector( '.label' ).textContent = label;
	append( workingEl ); // appendChild روی المان موجود، یعنی «ببرش آخر»
	tickElapsed();
	return workingEl;
}

function tickElapsed() {
	if ( ! workingEl ) {
		return;
	}
	const sec = Math.round( ( Date.now() - workingStart ) / 1000 );
	workingEl.querySelector( '.elapsed' ).textContent = sec >= 1 ? `${ sec } ثانیه` : '';
}

export function hideWorking() {
	clearInterval( workingTimer );
	workingTimer = null;
	workingEl?.remove();
	workingEl = null;
}

export function addNotice( text ) {
	append( h( 'div', { class: 'notice' }, [ h( 'span', { class: 'notice-ico', html: iconSvg( 'circle-dot', 12 ) } ), h( 'span', { text } ) ] ) );
}

/**
 * بلوک «استدلال» — متن فکرکردن مدل، جمع‌شونده.
 *
 * این تابع صدا زده می‌شد ولی هیچ‌جا تعریف نشده بود: هر رویداد `thinking` از پرووایدرهای
 * استدلالی، ReferenceError می‌داد و بقیهٔ رندرِ همان پاسخ می‌ایستاد. CSSش (`.thinking`)
 * از اول در فایل بود؛ فقط خودِ بلوک گم شده بود.
 *
 * @returns {HTMLElement & { _body: HTMLElement }}
 */
/**
 * بستنِ بلوک استدلال.
 *
 * قبلاً فقط کلاس `done` می‌گرفت و تا رفرش صفحه سر جایش می‌ماند — شکایت کارفرما. متن
 * استدلال چیزی نیست که بعد از رسیدنِ جواب به درد بخورد.
 */
export function dropThinking() {
	if ( thinkEl ) {
		thinkEl.remove();
		thinkEl = null;
	}
}

/**
 * جمع‌کردن خروجی ابزارها وقتی جواب مدل می‌رسد.
 *
 * کارفرما کارت‌های خروجی ابزار را هم جزو «باکس‌های استدلال» می‌شمارد (Snap24) و
 * می‌خواهد پیش از رسیدن جواب از صفحه بروند. اینجا کل کارت حذف نمی‌شود — سطر عنوانش
 * می‌ماند تا معلوم باشد چه ابزاری اجرا شده — ولی بدنهٔ پرحجم جمع می‌شود. باز و بستهٔ
 * دستی کاربر (`open`) محترم شمرده می‌شود.
 */
export function settleTools() {
	for ( const card of toolEls.values() ) {
		if ( ! card.classList.contains( 'open' ) ) {
			card.classList.add( 'settled' );
			card.querySelector( '.tool-body' )?.classList.remove( 'peek' );
		}
	}
}

/** چند خط آخرِ استدلال که در پنجره می‌ماند — خواستهٔ کارفرما: چهار تا پنج خط. */
const THINK_LINES = 5;

export function thinkingBlock() {
	const body = h( 'div', { class: 'thinking-body' } );
	// لایهٔ محوکنندهٔ بالای کادر روی همین ظرف می‌نشیند، نه روی متنِ اسکرول‌شونده.
	const view = h( 'div', { class: 'thinking-view' }, [ body ] );
	const head = h( 'div', { class: 'thinking-head' }, [
		h( 'span', { class: 'spin', html: iconSvg( 'spinner', 13 ) } ),
		h( 'b', { text: 'در حال استدلال' } ),
		h( 'span', { class: 'grow' } ),
		h( 'span', { class: 'm-ico', html: iconSvg( 'chevron-down', 13 ) } ),
	] );
	const box = h( 'div', { class: 'thinking' }, [ head, view ] );
	head.addEventListener( 'click', () => {
		view.hidden = ! view.hidden;
		body.hidden = view.hidden;
		head.querySelector( '.m-ico' ).textContent = body.hidden ? '▸' : '▾';
	} );
	box._body = body;

	/*
	 * پنجرهٔ کشویی: متن کامل نگه داشته می‌شود ولی فقط ۵ خط آخر رندر می‌شود.
	 *
	 * چرا نه با اسکرول: نسخهٔ قبلی `max-height` را با **`overflow: hidden`** گذاشته بود
	 * و بعد سعی می‌کرد با `_body.scrollTop = _body.scrollHeight` به آخر متن برود. روی
	 * المانی که `overflow:hidden` است این کار قابل اتکا نیست، پس متن از بالا ثابت
	 * می‌ماند و کاربر **۵ خط اول** را می‌دید نه ۵ خط آخر — یعنی دقیقاً برعکس خواسته.
	 * رندرِ «۵ خط آخر» به رفتار اسکرول مرورگر وابسته نیست و قطعی است.
	 * (DESIGN-HUB-UI-FIX §۲.۱۰.۳)
	 */
	box._full = '';
	box._push = ( text ) => {
		box._full += text;
		const lines = box._full.split( '\n' );
		const win = lines.slice( -THINK_LINES );
		body.replaceChildren(
			...win.map( ( line, i ) =>
				h( 'div', {
					// خط بالاییِ پنجره در حال بیرون‌رفتن است — محو می‌شود.
					class: `think-line${ i === 0 && lines.length > THINK_LINES ? ' fading' : '' }`,
					text: line,
				} )
			)
		);
	};

	append( box );
	return box;
}

export function addError( message, hint ) {
	const card = el( 'div', 'err-card' );
	card.appendChild( el( 'b', null, message ) );
	if ( hint ) {
		card.appendChild( el( 'p', null, hint ) );
	}
	append( card );
}

// ────────────────────────────────────────────────────────── کارت‌های ابزار

/**
 * نام نمایشی ابزارها — دقیقاً همان چیزی که در Claude Code روی کارت می‌بینی:
 * نام ابزار با فونت مونو و پررنگ، بعدش آرگومانش.
 */
const TOOL_LABEL = {
	read_file: 'Read',
	write_file: 'Write',
	edit_file: 'Edit',
	multi_edit: 'MultiEdit',
	notebook_edit: 'NotebookEdit',
	list_dir: 'LS',
	glob: 'Glob',
	grep: 'Grep',
	bash: 'Bash',
	bash_output: 'BashOutput',
	kill_shell: 'KillShell',
	web_fetch: 'WebFetch',
	web_search: 'WebSearch',
	todo_write: 'TodoWrite',
	skill: 'Skill',
	task: 'Task',
	read_mcp_resource: 'Resource',
	exit_plan_mode: 'ExitPlanMode',
	ask_user_question: 'AskUserQuestion',
};

export function toolMeta( name ) {
	if ( TOOL_LABEL[ name ] ) {
		return { label: TOOL_LABEL[ name ], ico: '' };
	}
	if ( String( name ).startsWith( 'mcp__' ) ) {
		const [ , server, tool ] = String( name ).split( '__' );
		return { label: `${ server }:${ tool }`, ico: '' };
	}
	return { label: name, ico: '' };
}

function toolCard( id, name, summary, sub ) {
	const meta = toolMeta( name );
	const card = el( 'div', 'tool' );
	const head = el( 'div', 'tool-head' );

	head.appendChild( el( 'span', 'tool-name', meta.label ) );
	if ( sub ) {
		head.appendChild( el( 'span', 'sub-tag', sub ) );
	}
	head.appendChild( el( 'span', 'tool-sum', summary || '' ) );

	const badge = el( 'span', 'tool-state run', '…' );
	head.appendChild( badge );
	head.appendChild( el( 'span', 'tool-chevron', '⌄' ) );

	head.onclick = () => {
		card.classList.toggle( 'open' );
		const body = card.querySelector( '.tool-body' );
		if ( body ) {
			body.hidden = ! card.classList.contains( 'open' );
		}
	};

	card.appendChild( head );
	card._badge = badge;
	card._name = name;
	append( card );
	toolEls.set( id, card );
	return card;
}

/** خروجی هر ابزار، به شکل خودش. */
function renderOutput( name, output ) {
	const text = String( output ?? '' );

	if ( name === 'write_file' || name === 'edit_file' || name === 'multi_edit' ) {
		return diffView( text );
	}
	if ( name === 'todo_write' ) {
		return todoView( text );
	}
	if ( name === 'bash' || name === 'bash_output' ) {
		return h( 'pre', { class: 'tool-body mono terminal', text } );
	}
	if ( name === 'grep' || name === 'glob' ) {
		return hitList( text );
	}
	if ( name === 'list_dir' ) {
		return dirView( text );
	}
	if ( name === 'read_file' ) {
		return h( 'pre', { class: 'tool-body mono code-lines', text } );
	}
	if ( name === 'web_search' ) {
		return linkList( text );
	}
	return h( 'pre', { class: 'tool-body mono', text } );
}

function diffView( output ) {
	const box = el( 'div', 'tool-body diff mono' );
	for ( const line of output.split( '\n' ) ) {
		const cls = line.startsWith( '+' )
			? 'add'
			: line.startsWith( '-' )
			? 'del'
			: line.startsWith( '@@' )
			? 'meta'
			: line.startsWith( '---' ) || line.startsWith( '+++' )
			? 'meta'
			: '';
		box.appendChild( el( 'div', cls, line ) );
	}
	return box;
}

function todoView( output ) {
	const box = el( 'div', 'tool-body todos' );
	for ( const line of output.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}
		const done = line.startsWith( '☑' );
		const doing = line.startsWith( '▸' );
		const row = el( 'div', `todo ${ done ? 'done' : doing ? 'doing' : '' }` );
		row.appendChild( el( 'span', 'box', done ? '☑' : doing ? '▸' : '☐' ) );
		row.appendChild( el( 'span', null, line.replace( /^[☑▸☐]\s*/, '' ) ) );
		box.appendChild( row );
	}
	return box;
}

function hitList( output ) {
	const box = el( 'div', 'tool-body hits mono' );
	for ( const line of output.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}
		const m = /^([^:]+):(\d+):\s?(.*)$/.exec( line );
		const row = el( 'div', 'hit' );
		if ( m ) {
			const link = el( 'button', 'btn link file-link', `${ m[ 1 ] }:${ m[ 2 ] }` );
			link.onclick = () => onOpenFile( m[ 1 ] );
			row.appendChild( link );
			row.appendChild( el( 'span', 'hit-text', m[ 3 ] ) );
		} else {
			const link = el( 'button', 'btn link file-link', line );
			link.onclick = () => onOpenFile( line.trim() );
			row.appendChild( link );
		}
		box.appendChild( row );
	}
	return box;
}

function dirView( output ) {
	const box = el( 'div', 'tool-body dir' );
	for ( const name of output.split( '\n' ) ) {
		if ( ! name.trim() ) {
			continue;
		}
		const isDir = name.endsWith( '/' );
		box.appendChild(
			h( 'div', { class: `dir-item ${ isDir ? 'is-dir' : '' }` }, [
				h( 'span', { class: 'dir-ico', text: isDir ? '▸' : '·' } ),
				h( 'span', { class: 'mono', text: name } ),
			] )
		);
	}
	return box;
}

function linkList( output ) {
	const box = el( 'div', 'tool-body links' );
	box.innerHTML = output
		.split( '\n' )
		.map( ( l ) => esc( l ).replace( /(https?:\/\/\S+)/g, '<a href="$1" target="_blank" rel="noreferrer">$1</a>' ) )
		.join( '<br />' );
	return box;
}

function finishTool( id, { output, error, denied, reason } ) {
	const card = toolEls.get( id );
	if ( ! card ) {
		return;
	}

	const badge = card._badge;
	if ( denied ) {
		badge.className = 'tool-state deny';
		badge.textContent = 'رد شد';
	} else if ( error ) {
		badge.className = 'tool-state err';
		badge.textContent = 'خطا';
	} else {
		badge.className = 'tool-state ok';
		badge.textContent = '✓';
	}

	const body = output ?? error ?? reason ?? '';
	if ( ! body ) {
		return;
	}

	const node = error || denied ? h( 'pre', { class: 'tool-body mono', text: String( body ) } ) : renderOutput( card._name, body );

	const lines = String( body ).split( '\n' ).length;
	const short = lines <= 14;
	node.hidden = ! short;
	if ( short ) {
		card.classList.add( 'open' );
	} else {
		// خروجی بلند، جمع می‌ماند ولی یک تکه‌اش با محوشدگی پیداست — همان کاری که
		// Claude می‌کند تا بدانی چیزی هست بدون اینکه صفحه را ببلعد.
		node.hidden = false;
		node.classList.add( 'peek' );
		card.addEventListener( 'click', () => node.classList.toggle( 'peek', ! card.classList.contains( 'open' ) ) );
	}

	// خط اول خروجی، خلاصهٔ خوبی برای هدر است (مثلاً «+12 −3»).
	const first = String( body ).split( '\n' )[ 0 ];
	if ( /[+−-]\d+/.test( first ) && first.length < 90 ) {
		card.querySelector( '.tool-sum' ).textContent = first;
	}

	card.appendChild( node );
	if ( atBottom() ) {
		scrollToEnd();
	}
}

// ──────────────────────────────────────────────────────── دروازهٔ تأیید

function askCard( ev ) {
	const meta = toolMeta( ev.name );
	const card = el( 'div', 'ask' );

	card.appendChild(
		h( 'div', { class: 'ask-head' }, [
			h( 'span', { class: 'ask-ico', text: meta.ico } ),
			h( 'b', { text: 'اجازه می‌دهی این کار انجام شود؟' } ),
			h( 'span', { class: 'ask-tool mono', text: ev.name } ),
		] )
	);

	card.appendChild( h( 'pre', { class: 'mono ask-body', text: ev.summary || JSON.stringify( ev.input, null, 2 ) } ) );

	// فرمان مرکب: بگو چند کار جداست و هرکدام چیست. کاربر باید بداند به چه چیزی اجازه
	// می‌دهد، نه فقط به کلمهٔ اولِ رشته.
	if ( ev.name === 'bash' && ( ev.rules || [] ).length > 1 ) {
		card.appendChild(
			h( 'div', { class: 'ask-parts' }, [
				h( 'span', { class: 'note', text: `این ${ ev.rules.length } فرمان جدا است:` } ),
				...ev.rules.map( ( r ) => h( 'code', { text: r.replace( /^bash:/, '' ) } ) ),
			] )
		);
	}

	// برای ویرایش فایل، نشان بده دقیقاً چه چیزی عوض می‌شود.
	if ( ev.name === 'edit_file' && ev.input?.old_string ) {
		const box = el( 'div', 'diff mono preview' );
		for ( const line of String( ev.input.old_string ).split( '\n' ) ) {
			box.appendChild( el( 'div', 'del', `-  ${ line }` ) );
		}
		for ( const line of String( ev.input.new_string ?? '' ).split( '\n' ) ) {
			box.appendChild( el( 'div', 'add', `+  ${ line }` ) );
		}
		card.appendChild( box );
	}

	const actions = el( 'div', 'ask-actions' );
	const allow = el( 'button', 'btn solid', 'اجازه بده' );
	const always = el( 'button', 'btn outline', alwaysLabel( ev ) );
	const deny = el( 'button', 'btn quiet', 'رد کن' );
	const never = el( 'button', 'btn quiet danger', 'هرگز' );

	const answer = async ( decision, remember ) => {
		for ( const b of [ allow, always, deny, never ] ) {
			b.disabled = true;
		}
		actions.replaceChildren(
			el(
				'span',
				`note ${ decision === 'allow' ? 'ok' : 'error' }`,
				decision === 'allow'
					? remember
						? 'اجازه داده شد و به یاد سپرده شد.'
						: 'اجازه داده شد.'
					: remember
					? 'رد شد و از این پس همیشه رد می‌شود.'
					: 'رد شد.'
			)
		);
		await post( '/api/permission', {
			id: ev.id,
			decision,
			remember,
			rules: remember ? rulesFor( ev ) : undefined,
		} );
	};

	allow.onclick = () => answer( 'allow', false );
	always.onclick = () => answer( 'allow', true );
	deny.onclick = () => answer( 'deny', false );
	never.onclick = () => answer( 'deny', true );

	actions.append( allow, always, deny, never );
	card.appendChild( actions );
	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
}

/**
 * قاعده‌ها را سرور می‌سازد — منطق شکستن فرمان پوسته آنجاست، نه اینجا. این فقط تور ایمنیِ
 * رویدادهای قدیمیِ ذخیره‌شده است.
 */
function rulesFor( ev ) {
	if ( Array.isArray( ev.rules ) && ev.rules.length ) {
		return ev.rules;
	}
	return [ ev.name ];
}

function alwaysLabel( ev ) {
	if ( ev.name !== 'bash' ) {
		return 'همیشه اجازه بده';
	}
	const names = rulesFor( ev ).map( ( r ) => r.replace( /^bash:/, '' ) );
	return names.length > 1 ? `همیشه برای این ${ names.length } فرمان` : `همیشه برای «${ names[ 0 ] || 'bash' }»`;
}

// ─────────────────────────────────────────── کارت نقشه و کارت پرسش

function planCard( ev ) {
	const card = el( 'div', 'plan-card' );
	card.appendChild( h( 'div', { class: 'plan-head' }, [ h( 'span', { html: iconSvg( 'circle-dot', 14 ) } ), h( 'b', { text: 'نقشهٔ کار آماده است' } ) ] ) );

	const body = el( 'div', 'plan-body' );
	body.innerHTML = markdown( ev.plan || '' );
	wireCodeCopy( body );
	card.appendChild( body );

	const actions = el( 'div', 'ask-actions' );
	const run = el( 'button', 'btn solid', 'تأیید و اجرا (با تأیید هر مرحله)' );
	const auto = el( 'button', 'btn outline', 'تأیید و اجرای خودکار' );
	const keep = el( 'button', 'btn quiet', 'نه، اصلاحش کن' );

	const answer = async ( value, mode ) => {
		for ( const b of [ run, auto, keep ] ) {
			b.disabled = true;
		}
		actions.replaceChildren( el( 'span', `note ${ value.approved ? 'ok' : '' }`, value.approved ? 'تأیید شد.' : 'برگشت به پلن.' ) );
		await post( '/api/answer', { id: ev.id, value, mode } );
	};

	run.onclick = () => answer( { approved: true, mode: 'default' }, 'default' );
	auto.onclick = () => answer( { approved: true, mode: 'auto' }, 'auto' );
	keep.onclick = async () => {
		const feedback = ( await promptDialog( 'چه چیزی را عوض کند؟', '' ) ) || '';
		answer( { approved: false, feedback }, 'plan' );
	};

	actions.append( run, auto, keep );
	card.appendChild( actions );
	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
}

function questionCard( ev ) {
	const card = el( 'div', 'q-card' );
	card.appendChild( h( 'div', { class: 'q-head' }, [ h( 'span', { html: iconSvg( 'help', 15 ) } ), h( 'b', { text: ev.question || 'یک انتخاب لازم است' } ) ] ) );

	const list = el( 'div', 'q-options' );
	const send = async ( value ) => {
		list.querySelectorAll( 'button' ).forEach( ( b ) => ( b.disabled = true ) );
		card.appendChild( el( 'div', 'note ok', `پاسخ: ${ value }` ) );
		await post( '/api/answer', { id: ev.id, value } );
	};

	for ( const opt of ev.options || [] ) {
		const btn = h( 'button', { class: 'btn outline row q-option', onClick: () => send( opt.label ) }, [
			h( 'b', { text: opt.label } ),
			opt.description ? h( 'span', { text: opt.description } ) : null,
		] );
		list.appendChild( btn );
	}
	card.appendChild( list );

	if ( ev.allowOther !== false ) {
		const input = h( 'input', { class: 'field', placeholder: 'یا خودت بنویس…' } );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' && input.value.trim() ) {
				send( input.value.trim() );
			}
		} );
		card.appendChild( input );
	}

	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
}

// ──────────────────────────────────────────────────────────── رویدادها

/** @param {any} ev */
export function handleEvent( ev ) {
	switch ( ev.type ) {
		case 'user':
			addMessage( 'user', ev.text, false, ev.images );
			break;

		case 'assistant_start':
			streamEl = addMessage( 'assistant', '' );
			streamEl._raw = '';
			showWorking( 'در حال فکر کردن' );
			break;

		case 'thinking': {
			showWorking( 'در حال استدلال' );
			if ( ! thinkEl ) {
				thinkEl = thinkingBlock();
			}
			// پنجرهٔ ۵ خطی: خط تازه از پایین می‌آید، بالایی محو می‌شود و بیرون می‌رود.
			thinkEl._push( ev.text );
			if ( atBottom() ) {
				scrollToEnd();
			}
			break;
		}

		case 'parallel':
			addNotice( `${ ev.count } ابزار خواندنی را با هم اجرا می‌کنم: ${ ( ev.names || [] ).join( '، ' ) }` );
			break;

		case 'text': {
			hideWorking();
			// استدلال، داربستِ ساختنِ جواب است؛ با آمدن خودِ جواب، جمع می‌شود.
			dropThinking();
			// خروجی ابزارها هم همین‌طور: جواب باید صفحه را در اختیار بگیرد.
			settleTools();
			if ( ! streamEl ) {
				streamEl = addMessage( 'assistant', '' );
				streamEl._raw = '';
			}
			streamEl._raw += ev.text;
			streamEl.dataset.raw = streamEl._raw;
			streamEl.innerHTML = markdown( streamEl._raw );
			wireCodeCopy( streamEl );
			if ( atBottom() ) {
				scrollToEnd();
			}
			break;
		}

		case 'assistant_end':
			if ( streamEl && ! streamEl.textContent.trim() ) {
				streamEl.closest( '.msg' )?.remove();
			}
			streamEl = null;
			// اگر نوبت بدون متن تمام شد (مثلاً فقط ابزار)، باز هم چیزی نمی‌ماند.
			dropThinking();
			break;

		case 'system':
			addMessage( 'system', ev.text );
			break;

		case 'notice':
			addNotice( ev.text );
			break;

		case 'error':
			addError( ev.error, ev.hint );
			break;

		case 'permission_request':
			hideWorking();
			// کارت اجازه باید تنها چیز روی صفحه باشد؛ استدلالِ پیش از آن دیگر کاربردی ندارد.
			dropThinking();
			askCard( ev );
			break;

		case 'ask_user':
			hideWorking();
			if ( ev.kind === 'plan' ) {
				planCard( ev );
			} else {
				questionCard( ev );
			}
			break;

		case 'tool_start':
			/*
			 * سوراخ واقعیِ «باکس استدلال حذف نمی‌شود»: `dropThinking` فقط در `text` و
			 * `assistant_end` بود. اگر مدل استدلال می‌کرد و بعد به‌جای متن یک ابزار صدا
			 * می‌زد، باکس سر جایش می‌ماند و روی کارت ابزار سوار می‌شد — و اگر آن نوبت
			 * هیچ‌وقت به `text` نمی‌رسید، تا پایان گفتگو می‌ماند.
			 */
			dropThinking();
			toolCard( ev.id, ev.name, ev.summary, ev.sub );
			showWorking( workingLabelFor( ev.name ) );
			break;

		case 'tool_result':
			finishTool( ev.id, { output: ev.output } );
			showWorking( 'در حال بررسی نتیجه' );
			break;

		case 'tool_error':
			finishTool( ev.id, { error: ev.error } );
			showWorking( 'در حال بررسی خطا' );
			break;

		case 'tool_denied':
			if ( ! toolEls.has( ev.id ) ) {
				toolCard( ev.id, ev.name, ev.summary, ev.sub );
			}
			finishTool( ev.id, { denied: true, reason: ev.reason } );
			break;

		case 'subagent_start':
			append( h( 'div', { class: 'subagent open' }, [ h( 'span', { html: iconSvg( 'subagents', 14 ) } ), h( 'b', { text: ev.label } ), h( 'span', { class: 'note', text: 'زیرعامل شروع شد' } ) ] ) );
			break;

		case 'subagent_end':
			append( h( 'div', { class: 'subagent done' }, [ h( 'span', { html: iconSvg( 'subagents', 14 ) } ), h( 'b', { text: ev.label } ), h( 'span', { class: 'note', text: 'زیرعامل تمام شد' } ) ] ) );
			break;

		case 'tool_log':
			break;

		case 'compacted':
			addNotice( `گفتگو فشرده شد: ${ ev.before } → ${ ev.after } پیام.` );
			break;

		case 'rewound':
			addNotice(
				`بازگشت انجام شد. ${ ev.restored?.length || 0 } فایل برگشت${ ev.deleted?.length ? ` و ${ ev.deleted.length } فایل حذف شد` : '' }.`
			);
			break;

		default:
			break;
	}
}

/** بازسازی کل صفحه از روی نوار رویدادهای ذخیره‌شده (بازخوانی نشست). */
export function renderTranscript( list ) {
	clearThread();
	for ( const ev of list || [] ) {
		if ( ev.type === 'assistant_end' ) {
			if ( String( ev.text || '' ).trim() ) {
				addMessage( 'assistant', ev.text );
			}
			continue;
		}
		if ( ev.type === 'assistant_start' || ev.type === 'text' ) {
			continue;
		}
		handleEvent( ev );
	}
	scrollToEnd();
}
