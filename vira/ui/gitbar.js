/**
 * نوار گیت زیر کامپوزر.
 *
 * خواستهٔ کارفرما (`DESIGN-PADO.md` §۱۶): مدیری که وسط خرابی نشسته باید بدون کلیک
 * بداند **روی کدام مخزن، کدام شاخه، و چقدر عوض شده** — و یک راه برای بستن کار داشته باشد.
 *
 * سه نشان و یک دکمه:
 *   مخزن  → تعویض یا اتصال مخزن تازه
 *   شاخه  → تعویض یا ساخت شاخه
 *   +n −m → دیدن دیف کامل
 *   دکمه  → ثبت، فرستادن، درخواست ادغام
 *
 * هیچ‌کدام خودکار نیست: کامیت، پوش و ادغام هر سه از مدیر تأیید می‌گیرند.
 */

import { $, h, toast, promptDialog, confirmDialog } from './lib/dom.js';
import { api, post, getState, refreshState } from './lib/api.js';
import { iconSvg } from './lib/icons.js';

/** @type {(view:string)=>void} */
let openView = () => {};

/** @param {{onView:(v:string)=>void}} deps */
export function initGitBar( deps ) {
	openView = deps.onView;

	$( '#git-repo' ).onclick = ( e ) => {
		e.stopPropagation();
		repoMenu();
	};
	$( '#git-branch' ).onclick = ( e ) => {
		e.stopPropagation();
		branchMenu();
	};
	$( '#git-stat' ).onclick = () => openView( 'changes' );
	$( '#git-action' ).onclick = ( e ) => {
		e.stopPropagation();
		actionMenu();
	};

	document.addEventListener( 'click', ( e ) => {
		const box = $( '#git-menu' );
		if ( box && ! box.hidden && ! box.contains( e.target ) && ! e.target.closest( '.git-bar' ) ) {
			box.hidden = true;
		}
	} );
}

/**
 * قفل انتخاب مخزن/شاخه.
 *
 * پیش از اولین پیامِ هر گفتگو باز است و بعد از آن بسته. مقدارش را سرور می‌گوید (چون
 * او می‌داند پیامی رد و بدل شده یا نه)؛ اینجا فقط نگهش می‌داریم تا نوار بتواند
 * بی‌درنگ خودش را ببندد.
 */
let locked = false;

/** @param {boolean} value */
export function setGitLock( value ) {
	locked = Boolean( value );
	paintGitBar( getState() );
}

/** @param {any} s */
export function paintGitBar( s ) {
	const bar = $( '#git-bar' );
	const git = s?.git;
	const hasChat = ( s?.transcript || [] ).length > 0;
	locked = locked || hasChat;

	if ( ! git ) {
		// مخزن نیست: به‌جای پنهان‌کردن، راه وصل‌شدن را نشان بده.
		bar.hidden = false;
		$( '#git-repo-name' ).textContent = 'مخزنی وصل نیست';
		$( '#git-branch' ).hidden = true;
		$( '#git-stat' ).hidden = true;
		$( '#git-action' ).textContent = 'اتصال مخزن';
		$( '#git-action' ).className = 'btn outline sm push-end';
		return;
	}

	bar.hidden = false;
	$( '#git-branch' ).hidden = false;
	$( '#git-stat' ).hidden = false;

	$( '#git-repo-name' ).textContent = git.name;
	$( '#git-repo' ).title = git.remote || git.name;

	$( '#git-branch-name' ).textContent = git.branch;
	$( '#git-branch' ).classList.toggle( 'protected', git.protected );
	$( '#git-branch' ).title = git.protected
		? `${ git.branch } — شاخهٔ محافظت‌شده؛ ویرا رویش نمی‌نویسد`
		: git.branch;

	/*
	 * بعد از اولین پیام، مخزن و شاخه دیگر عوض نمی‌شوند.
	 *
	 * دکمه‌ها را پنهان نمی‌کنیم — کاربر باید ببیند روی چه چیزی کار می‌کند — فقط
	 * غیرفعال می‌شوند و دلیلش در تیتر می‌آید.
	 */
	for ( const id of [ '#git-repo', '#git-branch' ] ) {
		const node = $( id );
		node.disabled = locked;
		node.classList.toggle( 'locked', locked );
		if ( locked ) {
			node.title = `${ node.title } — تا گفتگوی تازه قفل است`;
		}
	}

	$( '#git-plus' ).textContent = `+${ git.added }`;
	$( '#git-minus' ).textContent = `−${ git.removed }`;
	$( '#git-stat' ).title = `${ git.files.length } فایل تغییرکرده`;

	const action = $( '#git-action' );
	if ( git.dirty ) {
		action.textContent = `ثبت ${ git.files.length } تغییر`;
		action.className = 'btn solid sm push-end';
	} else if ( git.ahead > 0 ) {
		action.textContent = `فرستادن ${ git.ahead } کامیت`;
		action.className = 'btn solid sm push-end';
	} else {
		action.textContent = 'درخواست ادغام';
		action.className = 'btn outline sm push-end';
	}
}

// ─────────────────────────────────────────────────────────── منوها

function menu( children ) {
	const box = $( '#git-menu' );
	box.replaceChildren( ...children.filter( Boolean ) );
	box.hidden = false;
	return box;
}

function close() {
	$( '#git-menu' ).hidden = true;
}

function row( ico, label, desc, onClick, checked ) {
	return h( 'div', { class: `btn quiet row menu-item ${ checked ? 'active' : '' }`, onClick }, [
		h( 'span', { class: 'm-ico', html: iconSvg( ico, 16 ) } ),
		h( 'b', { text: label } ),
		desc ? h( 'span', { class: 'm-desc', text: desc } ) : null,
		checked ? h( 'span', { class: 'm-check', html: iconSvg( 'check', 13 ) } ) : null,
	] );
}

async function repoMenu() {
	const s = getState();
	const git = s?.git;

	if ( locked ) {
		menu( [ h( 'div', { class: 'btn quiet row menu-item', text: 'گفتگو شروع شده؛ مخزن تا گفتگوی تازه قفل است.' } ) ] );
		return;
	}

	menu( [ h( 'div', { class: 'btn quiet row menu-item', text: 'در حال خواندن مخزن‌های مجاز…' } ) ] );

	/*
	 * فهرست از گیت‌هاب می‌آید، نه از حافظهٔ مرورگر: «مخزن‌هایی که به ویرا مجوز داده
	 * شده». هر کدام شاخهٔ پیش‌فرض خودش را همراه دارد و انتخابش همان شاخه را می‌آورد.
	 */
	const out = await api( '/api/git?repos' );
	const known = out.known || { ok: false, repos: [] };

	menu( [
		h( 'div', { class: 'menu-label', text: 'مخزن‌های مجاز' } ),
		...( known.repos || [] ).slice( 0, 15 ).map( ( r ) =>
			row(
				'repo',
				r.nameWithOwner,
				`${ r.defaultBranch }${ r.private ? ' · خصوصی' : '' }`,
				async () => {
					close();
					toast( `${ r.nameWithOwner } آماده می‌شود…` );
					const res = await post( '/api/git', { action: 'use-repo', repo: r.nameWithOwner, branch: r.defaultBranch } );
					toast( res.error || `روی ${ r.nameWithOwner } هستیم.`, res.error ? 'error' : '' );
					await refreshState();
				},
				git?.name === r.nameWithOwner.split( '/' ).pop() || git?.remote?.includes( r.nameWithOwner )
			)
		),
		! known.ok
			? h( 'div', { class: 'btn quiet row menu-item', text: known.message || 'فهرست مخزن‌ها در دسترس نیست.' } )
			: null,
		known.ok && ! ( known.repos || [] ).length
			? h( 'div', { class: 'btn quiet row menu-item', text: 'مخزنی به ویرا مجوز نداده‌ای.' } )
			: null,
		h( 'div', { class: 'menu-sep' } ),
		row( 'plus', 'اتصال مخزن تازه', 'کلون از آدرس گیت', async () => {
			close();
			await connectRepo();
		} ),
		row( 'folder-plus', 'تغییر پوشهٔ کاری', 'بدون کلون', async () => {
			close();
			const next = await promptDialog( 'مسیر پوشه:', s?.config?.workspace || '' );
			if ( ! next ) {
				return;
			}
			const res = await post( '/api/workspace', { path: next } );
			toast( res.error || 'پوشهٔ کاری عوض شد.', res.error ? 'error' : '' );
			await refreshState();
		} ),
		git ? row( 'diff', 'دیدن همهٔ تغییرات', '', () => {
			close();
			openView( 'changes' );
		} ) : null,
	] );
}

async function connectRepo() {
	const url = await promptDialog( 'آدرس مخزن (https یا git@):', '' );
	if ( ! url ) {
		return;
	}
	const token = await promptDialog( 'توکن دسترسی (اگر مخزن خصوصی است؛ وگرنه خالی بگذار):', '' );

	toast( 'در حال کلون…' );
	const out = await post( '/api/git', { action: 'clone', url, token: token || undefined } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	toast( `«${ out.name }» وصل شد.` );
	await refreshState();
}

async function branchMenu() {
	if ( locked ) {
		menu( [ h( 'div', { class: 'btn quiet row menu-item', text: 'گفتگو شروع شده؛ شاخه تا گفتگوی تازه قفل است.' } ) ] );
		return;
	}
	menu( [ h( 'div', { class: 'btn quiet row menu-item', text: 'در حال خواندن شاخه‌ها…' } ) ] );

	const out = await api( '/api/git' );
	const git = out.git;
	if ( ! git ) {
		close();
		return;
	}

	menu( [
		h( 'div', { class: 'menu-label', text: 'شاخه' } ),
		...( out.branches || [] ).slice( 0, 12 ).map( ( b ) =>
			row(
				b.protected ? '⛨' : '⑂',
				b.name,
				b.when,
				async () => {
					close();
					const res = await post( '/api/git', { action: 'branch', name: b.name } );
					toast( res.error || `روی «${ b.name }» هستیم.`, res.error ? 'error' : '' );
					await refreshState();
				},
				b.name === git.branch
			)
		),
		h( 'div', { class: 'menu-sep' } ),
		row( 'plus', 'شاخهٔ تازه', 'از همین‌جا منشعب می‌شود', async () => {
			close();
			const name = await promptDialog( 'نام شاخهٔ تازه:', `vira/${ Date.now().toString( 36 ) }` );
			if ( ! name ) {
				return;
			}
			const res = await post( '/api/git', { action: 'branch', name, create: true } );
			toast( res.error || `شاخهٔ «${ name }» ساخته شد.`, res.error ? 'error' : '' );
			await refreshState();
		} ),
	] );
}

function actionMenu() {
	const git = getState()?.git;

	if ( ! git ) {
		connectRepo();
		return;
	}

	menu( [
		h( 'div', { class: 'menu-label', text: 'بستن کار' } ),
		row( 'commit', `ثبت ${ git.files.length } تغییر`, git.protected ? 'شاخهٔ تازه ساخته می‌شود' : git.branch, async () => {
			close();
			await doCommit();
		} ),
		row( 'push', 'فرستادن به ریموت', git.ahead ? `${ git.ahead } کامیت آماده` : 'چیزی برای فرستادن نیست', async () => {
			close();
			await doPush();
		} ),
		row( 'pull-request', 'درخواست ادغام', 'با gh ساخته می‌شود', async () => {
			close();
			await doPr();
		} ),
		h( 'div', { class: 'menu-sep' } ),
		row( 'diff', 'دیدن تغییرات', '', () => {
			close();
			openView( 'changes' );
		} ),
	] );
}

async function doCommit() {
	const git = getState()?.git;
	if ( ! git?.dirty ) {
		toast( 'چیزی برای ثبت نیست.' );
		return;
	}

	const message = await promptDialog( `پیام کامیت (${ git.files.length } فایل):`, '' );
	if ( ! message ) {
		return;
	}

	const out = await post( '/api/git', { action: 'commit', message } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	toast( `${ out.sha } ثبت شد${ out.movedTo ? ` روی شاخهٔ تازهٔ «${ out.movedTo }»` : '' }.` );
	await refreshState();
}

async function doPush() {
	const git = getState()?.git;
	if ( git?.protected ) {
		toast( 'روی شاخهٔ محافظت‌شده پوش نمی‌کنیم.', 'error' );
		return;
	}
	if ( ! ( await confirmDialog( `شاخهٔ «${ git?.branch }» به ریموت فرستاده شود؟` ) ) ) {
		return;
	}

	const token = await promptDialog( 'توکن (اگر لازم است؛ وگرنه خالی):', '' );
	const out = await post( '/api/git', { action: 'push', token: token || undefined } );
	toast( out.error || `شاخهٔ «${ out.branch }» فرستاده شد.`, out.error ? 'error' : '' );
	await refreshState();
}

async function doPr() {
	const title = await promptDialog( 'عنوان درخواست ادغام:', '' );
	if ( ! title ) {
		return;
	}
	const out = await post( '/api/git', { action: 'pr', title } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	toast( 'درخواست ادغام ساخته شد.' );
	window.open( out.url, '_blank', 'noreferrer' );
}

/**
 * صفحهٔ «تغییرات» — فهرست فایل‌ها با شمار خط و دیف هرکدام.
 * @param {HTMLElement} host
 */
export async function renderChanges( host ) {
	host.replaceChildren( h( 'div', { class: 'loading', text: 'در حال خواندن تغییرات…' } ) );

	const out = await api( '/api/git' );
	host.replaceChildren();

	if ( ! out.git ) {
		host.appendChild(
			h( 'div', { class: 'empty' }, [
				h( 'p', { text: 'این پوشه مخزن گیت نیست.' } ),
				h( 'button', { class: 'btn solid', text: 'اتصال مخزن', onClick: () => connectRepo() } ),
			] )
		);
		return;
	}

	const git = out.git;

	host.appendChild(
		h( 'div', { class: 'stat-row' }, [
			stat( 'مخزن', git.name, true ),
			stat( 'شاخه', git.branch + ( git.protected ? ' ⛨' : '' ), true ),
			stat( 'تغییر', `+${ git.added } −${ git.removed }` ),
			stat( 'جلوتر از ریموت', String( git.ahead ) ),
		] )
	);

	host.appendChild(
		h( 'div', { class: 'row' }, [
			h( 'button', { class: 'btn solid', text: 'ثبت تغییرات', onClick: doCommit } ),
			h( 'button', { class: 'btn outline', text: 'فرستادن', onClick: doPush } ),
			h( 'button', { class: 'btn outline', text: 'درخواست ادغام', onClick: doPr } ),
		] )
	);

	if ( ! git.files.length ) {
		host.appendChild( h( 'div', { class: 'empty', text: 'چیزی تغییر نکرده.' } ) );
	} else {
		const list = h( 'div', { class: 'card-list' } );
		for ( const f of git.files ) {
			const num = ( out.stat || [] ).find( ( x ) => x.path === f.path );
			const body = h( 'pre', { class: 'tool-body diff mono', hidden: true } );

			list.appendChild(
				h( 'div', { class: 'item', style: 'flex-direction:column;align-items:stretch' }, [
					h( 'div', { class: 'git-row', onClick: async () => {
						if ( ! body.hidden ) {
							body.hidden = true;
							return;
						}
						const d = await api( `/api/git?diff=${ encodeURIComponent( f.path ) }` );
						body.replaceChildren();
						for ( const line of String( d.diff || '(بدون دیف)' ).split( '\n' ) ) {
							const cls = line.startsWith( '+' ) ? 'add' : line.startsWith( '-' ) ? 'del' : line.startsWith( '@@' ) ? 'meta' : '';
							body.appendChild( h( 'div', { class: cls, text: line } ) );
						}
						body.hidden = false;
					} }, [
						h( 'span', { class: `git-state s-${ f.state.replace( /[^A-Z]/g, '' ) || 'M' }`, text: f.state } ),
						h( 'span', { class: 'mono small', text: f.path } ),
						num ? h( 'span', { class: 'git-num' }, [
							h( 'span', { class: 'plus', text: `+${ num.added } ` } ),
							h( 'span', { class: 'minus', text: `−${ num.removed }` } ),
						] ) : null,
					] ),
					body,
				] )
			);
		}
		host.appendChild( list );
	}

	if ( ( out.log || [] ).length ) {
		host.appendChild( h( 'h4', { text: 'کامیت‌های اخیر', style: 'margin-top:26px' } ) );
		const table = h( 'table', { class: 'table' }, [
			h( 'tbody', {}, out.log.map( ( c ) =>
				h( 'tr', {}, [
					h( 'td', { class: 'mono', text: c.sha } ),
					h( 'td', { text: c.subject } ),
					h( 'td', { class: 'note', text: `${ c.author } · ${ c.when }` } ),
				] )
			) ),
		] );
		host.appendChild( table );
	}
}

/**
 * یک کارت آمار.
 *
 * `mono` برای مقدارهایی است که شناسه‌اند (نام مخزن، نام شاخه): با فونت تک‌فاصله و
 * کوچک‌تر خوانا‌ترند و از کارت بیرون نمی‌زنند. `title` هم می‌گذاریم چون وقتی مقدار
 * بلند بشکند، دیدن کاملش با نگه‌داشتن ماوس ساده‌تر از بزرگ‌کردن کارت است.
 */
function stat( label, value, mono = false ) {
	return h( 'div', { class: 'stat', title: `${ label }: ${ value }` }, [
		h( 'span', { class: 'stat-label', text: label } ),
		h( 'b', { class: `stat-value ${ mono ? 'mono' : '' }`, text: value } ),
	] );
}
