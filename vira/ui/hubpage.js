/**
 * صفحهٔ تمام‌قد «پرووایدرها و هاب» — جایگزین شش تبِ تنظیمات.
 *
 * معماری (طرح DESIGN-PROVIDER-UI.md، تأیید کارفرما ۱۴۰۵/۰۵/۲۸): چهار نما —
 * «نگاه کلی (توپولوژی)» · «اتصال‌ها (کاتالوگ + جزئیات هر سرویس)» · «ترکیب‌ها» ·
 * «سلامت و مصرف». الگوی مرجع: داشبورد OmniRoute (کاتالوگ کارتی همیشه‌نمایان، صفحهٔ
 * جزئیات برای هر پرووایدر، ویزارد برای ساختن، داشبورد برای سلامت).
 *
 * هیچ دادهٔ تازه‌ای ساخته نمی‌شود؛ همان API قبلی + دو کنش تازه:
 * `reset-provider` (ریست و رفع خطای یک اتصال) و `reset-health` (ریست کل).
 */

import { el, h, toast, confirmDialog } from './lib/dom.js';
import { api, post } from './lib/api.js';
import { iconSvg } from './lib/icons.js';

/** @type {any} */
let snap = null;
/** @type {{ view: string, provider: string|null }} */
let state = { view: 'overview', provider: null };

async function refresh() {
	snap = await api( '/api/hub' );
}

const CUSTOM = new Set( [ 'openai-compatible', 'anthropic-compatible' ] );
/* رنگ برندها — حسِ لوگو بدون بستهٔ آیکون (الگوی OmniRoute). */
const BRANDS = {
	anthropic: '#d97757', openai: '#10a37f', 'google-gemini': '#4285f4', gemini: '#4285f4',
	deepseek: '#4d6bfe', groq: '#f55036', mistral: '#ff7000', xai: '#17171b', 'xai (grok)': '#17171b',
	'together ai': '#0f6fff', 'fireworks ai': '#e8713a', cerebras: '#f5841f', 'azure openai': '#0078d4',
	ollama: '#0f0f0f', 'lm studio': '#17b26a', vllm: '#13c1c8', openrouter: '#17171b',
	'openai-compatible': '#13c1c8', 'anthropic-compatible': '#d97757',
};
function brandColor( providerId, label ) {
	const k = String( providerId || '' ).toLowerCase();
	if ( BRANDS[ k ] ) { return BRANDS[ k ]; }
	const l = String( label || '' ).toLowerCase();
	for ( const [ key, c ] of Object.entries( BRANDS ) ) {
		if ( l.includes( key ) ) { return c; }
	}
	let h = 0;
	for ( const ch of l ) { h = ( h * 31 + ch.codePointAt( 0 ) ) % 360; }
	return `hsl(${ h } 45% 45%)`;
}

const isLocal = ( p ) => /localhost|127\.|0\.0\.0\.0|سرور خودت|محلی/i.test( `${ p.baseUrl || '' } ${ p.label || '' }` );

/** دوباره‌سازی همین نما — کاربر نتیجهٔ کارش را ببیند، نه فرم قدیمی را. */
async function again( box ) {
	await refresh();
	box.replaceChildren();
	draw( box );
}

function section( title, hint ) {
	return h( 'div', { class: 'sec-head' }, [
		h( 'h3', { text: title } ),
		hint ? h( 'p', { class: 'note', text: hint } ) : null,
	] );
}
function field( label, control, hint ) {
	return h( 'div', { class: 'field' }, [
		h( 'b', { text: label } ),
		control,
		hint ? h( 'p', { class: 'note', text: hint } ) : null,
	] );
}
const row = ( ...children ) => h( 'div', { class: 'row wrap' }, children );
const emptyBox = ( text ) => h( 'div', { class: 'empty', text } );
const action = ( body ) => post( '/api/hub', body );

/* ═══════════════════════════════════════════════ ورود به صفحه ═══════════════════════════════════════════════ */

/**
 * @param {HTMLElement} box
 * @param {{ view?: string, provider?: string|null }} [opts]
 */
export async function mountHubPage( box, opts = {} ) {
	if ( opts.view ) {
		state.view = opts.view;
	}
	if ( opts.provider !== undefined ) {
		state.provider = opts.provider;
	}
	box.replaceChildren( el( 'div', 'loading', 'در حال خواندن هاب…' ) );
	await refresh();
	box.replaceChildren();
	draw( box );
}

function draw( box ) {
	const views = [
		[ 'overview', 'نگاه کلی', 'hub' ],
		[ 'connections', 'اتصال‌ها', 'provider' ],
		[ 'combos', 'ترکیب‌ها', 'layers' ],
		[ 'health', 'سلامت و مصرف', 'health' ],
	];
	box.appendChild( statusBar( box ) );

	/*
	 * ناوبری: یک نوار تب افقی، نه ستون کناری.
	 *
	 * ستون قبلی یک «سایدبار داخل سایدبار» می‌ساخت — کاربر دو لایه ناوبری موازی
	 * می‌دید — و ۱۶۸px از عرض محتوا را هم می‌گرفت. مرجع (Snap5) برای همین کار نوار
	 * تب افقی دارد. شمارنده‌ها هم مثل مرجع کنار عنوان می‌آیند.
	 */
	const hub = snap?.hub || {};
	const counts = {
		connections: Object.keys( hub.connections || {} ).length,
		combos: Object.keys( hub.combos || {} ).length,
	};
	const host = el( 'div', 'hub-view' );
	const tabs = h( 'div', { class: 'hub-tabs' },
		views.map( ( [ id, label, ico ] ) =>
			h( 'button', {
				class: `btn quiet hub-tab ${ state.view === id ? 'active' : '' }`,
				onClick: () => { state.view = id; state.provider = null; again( box ); },
			}, [
				h( 'span', { class: 'si-ico', html: iconSvg( ico, 15 ) } ),
				h( 'span', { text: label } ),
				counts[ id ] ? h( 'span', { class: 'tab-count', text: String( counts[ id ] ) } ) : null,
			] ) )
	);
	box.appendChild( tabs );
	box.appendChild( host );
	if ( state.view === 'overview' ) {
		renderOverview( host, box );
	} else if ( state.view === 'connections' && state.provider ) {
		renderDetail( host, box, state.provider );
	} else if ( state.view === 'connections' ) {
		renderCatalog( host, box );
	} else if ( state.view === 'combos' ) {
		renderCombos( host, box );
	} else {
		renderHealth( host, box );
	}
}

/* ═══════════════════════════════════════════════ نوار وضعیت ═══════════════════════════════════════════════ */

/**
 * سه حالت با «گام بعدیِ درست» — نه نصیحت. طرح §۲: رفع جریان دایره‌ایِ
 * «روشن کن / آماده نیست / فعلاً پروفایل قدیمی».
 */
function statusBar( box ) {
	const hub = snap?.hub || {};
	const ready = snap?.ready || {};
	const on = Boolean( hub.enabled );
	const conns = Object.values( hub.connections || {} );
	const live = conns.filter( ( c ) => c.enabled !== false );
	const models = Object.values( hub.models || {} ).filter( ( m ) => m.enabled );

	let title = 'هاب خاموش است';
	let note = 'حالت سادهٔ تک‌پروفایدر فعال است؛ ویرا فقط از پروفایل قدیمی استفاده می‌کند.';
	let cta = null;

	if ( ! on && ! live.length ) {
		title = 'هاب خاموش است';
		note = 'حالت ساده فعال است. برای مسیریابی چندمدلی، اول یک اتصال بساز.';
		cta = { text: 'اولین اتصال را بساز', run: () => { state.view = 'connections'; state.provider = null; again( box ); } };
	} else if ( ! on ) {
		title = 'هاب خاموش است';
		note = `${ live.length } اتصال آمادهٔ روشن‌کردن هاب هست.`;
		cta = { text: 'روشن کن', run: async () => {
			const out = await action( { action: 'toggle', enabled: true } );
			toast( out.active ? 'هاب فرمان را گرفت.' : 'هاب روشن نشد.' );
			await again( box );
		} };
	} else if ( ! ready.ok ) {
		title = 'هاب روشن است ولی آماده نیست';
		note = `${ ready.reason || '' } — تا آن موقع پروفایل قدیمی کار می‌کند.`;
		const r = String( ready.reason || '' );
		if ( r.includes( 'اتصال' ) ) {
			cta = { text: 'ساختن اتصال', run: () => { state.view = 'connections'; state.provider = null; again( box ); } };
		} else if ( r.includes( 'مدل' ) ) {
			cta = { text: 'رفتن به اتصال‌ها و کشف مدل', run: () => { state.view = 'connections'; state.provider = null; again( box ); } };
		}
	} else {
		title = 'هاب فعال است';
		note = `مسیریابی با هاب انجام می‌شود — ${ live.length } اتصال · ${ models.length } مدل روشن`;
		cta = { text: 'خاموش کن', run: async () => {
			await action( { action: 'toggle', enabled: false } );
			toast( 'هاب خاموش شد؛ پروفایل تک‌نفره فعال است.' );
			await again( box );
		} };
	}

	return h( 'div', { class: `form-card hub-status ${ on ? ( ready.ok ? 'ok' : 'warn' ) : '' }` }, [
		h( 'div', { class: 'item-main' }, [
			h( 'b', { text: title } ),
			h( 'p', { class: 'note', text: note } ),
		] ),
		h( 'span', { class: 'grow' } ),
		snap?.active ? h( 'span', { class: 'tag ok', text: 'فرمان با هاب' } ) : null,
		cta ? h( 'button', { class: `btn ${ on && ready.ok ? 'outline' : 'solid' }`, text: cta.text, onClick: cta.run } ) : null,
	] );
}

/* ═══════════════════════════════════════════════ نگاه کلی — توپولوژی ═════════════════════════════════════ */

/**
 * نقشهٔ هاب: مرکز = هاب، حلقه = اتصال‌ها با رنگ وضعیت، ضخامت یال = ترافیک ثبت‌شده.
 * DOM/CSS خالص — بدون وابستگی گرافیکی.
 */
function renderOverview( box, page ) {
	box.appendChild( section( 'نگاه کلی هاب', 'وضعیت زندهٔ اتصال‌ها از نگاه مسیریاب: رنگ هر گره وضعیت واقعی آن است و ضخامت هر یال، ترافیک ثبت‌شده.' ) );

	const hub = snap?.hub || {};
	const conns = Object.values( hub.connections || {} );
	const health = snap?.health || {};
	const traffic = snap?.traffic || {};

	const stateOf = ( c ) => {
		if ( c.enabled === false ) {
			return { cls: 'off', label: 'خاموش' };
		}
		const rows = Object.entries( health ).filter( ( [ k ] ) => k.startsWith( `${ c.id }::` ) );
		if ( rows.some( ( [ , v ] ) => v.exhausted ) ) {
			return { cls: 'warn', label: 'اعتبار تمام' };
		}
		if ( rows.some( ( [ , v ] ) => v.circuit === 'open' ) ) {
			return { cls: 'bad', label: 'مدار باز' };
		}
		if ( ! c.hasKey && c.provider !== 'ollama' && ! CUSTOM.has( c.provider ) ) {
			return { cls: 'warn', label: 'بدون کلید' };
		}
		return { cls: 'ok', label: 'فعال' };
	};

	if ( ! conns.length ) {
		box.appendChild( h( 'div', { class: 'form-card topo-empty' }, [
			h( 'p', { class: 'note', text: 'هنوز اتصالی نیست — نقشه با اولین اتصال جان می‌گیرد.' } ),
			h( 'button', { class: 'btn solid', text: 'اولین اتصال را بساز', onClick: () => { state.view = 'connections'; again( page ); } } ),
		] ) );
		return;
	}

	const maxTraffic = Math.max( 1, ...conns.map( ( c ) => traffic[ c.id ] || 0 ) );

	/*
	 * چیدمان: تا هشت گره یک حلقه، بیشتر از آن دو حلقهٔ هم‌مرکز.
	 *
	 * با یک حلقه و گره‌های درشتِ قبلی، ده پرووایدر روی هم می‌افتادند. حالا گره‌ها
	 * مستطیل کوچک‌اند (مثل Snap10) و اگر باز هم زیاد شوند، حلقهٔ دوم باز می‌شود.
	 */
	const W = 900, H = 420, cx = W / 2, cy = H / 2;
	const RING = 8;
	const rOuter = Math.min( W, H ) / 2 - 46;
	const rInner = conns.length > RING ? rOuter - 96 : rOuter;

	const posOf = ( i ) => {
		const inner = conns.length > RING && i < RING;
		const count = conns.length > RING ? ( inner ? RING : conns.length - RING ) : conns.length;
		const idx = inner ? i : i - ( conns.length > RING ? RING : 0 );
		const r = inner ? rInner : rOuter;
		const angle = ( idx / Math.max( 1, count ) ) * Math.PI * 2 - Math.PI / 2;
		return { x: cx + r * Math.cos( angle ), y: cy + r * Math.sin( angle ) };
	};

	/**
	 * حالت یال — سه‌گانهٔ راهنمای Snap10.
	 *
	 * active: همین حالا/تازه کار کرده → نقطه‌چین سبزِ متحرک به سمت پرووایدر.
	 * recent: در پنجرهٔ اخیر کار کرده → خط ممتد کهربایی.
	 * error:  آخرین نتیجه خطا بود، یا مدار باز/اعتبار تمام → خط ممتد قرمز.
	 */
	const ACTIVE_MS = 60_000;
	const RECENT_MS = 15 * 60_000;
	const now = Date.now();
	const edgeState = ( c ) => {
		const rows = Object.entries( health ).filter( ( [ k ] ) => k.startsWith( `${ c.id }::` ) );
		if ( rows.some( ( [ , v ] ) => v.circuit === 'open' || v.exhausted ) ) {
			return 'error';
		}
		const last = Math.max( 0, ...rows.map( ( [ , v ] ) => Number( v.lastUsedAt ) || 0 ) );
		if ( ! last ) {
			return 'idle';
		}
		if ( rows.some( ( [ , v ] ) => v.lastOk === false ) && now - last < RECENT_MS ) {
			return 'error';
		}
		const age = now - last;
		if ( age <= ACTIVE_MS ) { return 'active'; }
		if ( age <= RECENT_MS ) { return 'recent'; }
		return 'idle';
	};

	const map = h( 'div', { class: 'topo' } );

	// یال‌ها در یک لایهٔ SVG — نقطه‌چینِ متحرک با div چرخانده ممکن نبود.
	const NS = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( NS, 'svg' );
	svg.setAttribute( 'class', 'topo-edges' );
	svg.setAttribute( 'viewBox', `0 0 ${ W } ${ H }` );
	svg.setAttribute( 'preserveAspectRatio', 'xMidYMid meet' );
	conns.forEach( ( c, i ) => {
		const { x, y } = posOf( i );
		const st = edgeState( c );
		const line = document.createElementNS( NS, 'line' );
		// همیشه از مرکز به پرووایدر: جهتِ حرکتِ نقطه‌ها از همین ترتیب می‌آید.
		line.setAttribute( 'x1', String( cx ) );
		line.setAttribute( 'y1', String( cy ) );
		line.setAttribute( 'x2', String( x ) );
		line.setAttribute( 'y2', String( y ) );
		line.setAttribute( 'class', `topo-edge ${ st }` );
		const t = ( traffic[ c.id ] || 0 ) / maxTraffic;
		if ( st !== 'active' ) {
			line.style.strokeWidth = String( 1.5 + t * 3 );
		}
		svg.appendChild( line );
	} );
	map.appendChild( svg );

	// مرکز: هاب
	map.appendChild( h( 'div', { class: `topo-center ${ snap?.active ? 'on' : '' }`, title: snap?.active ? 'فرمان با هاب است' : 'هاب فرمان را ندارد' }, [
		h( 'span', { class: 'si-ico', html: iconSvg( 'hub', 22 ) } ),
		h( 'b', { text: snap?.active ? 'هاب فعال' : 'هاب' } ),
	] ) );

	/*
	 * گره‌ها: مستطیل کوچکِ تک‌خطه (خواستهٔ کارفرما، الگوی Snap10).
	 *
	 * قبلاً کارت عمودی سه‌طبقه با ارتفاع ~۷۰px بود — سه برابر مرجع — و با ده پرووایدر
	 * روی هم می‌افتاد. شمار مدل به tooltip رفت و دکمهٔ «ریست» از داخل گره برداشته شد
	 * (کلیک روی گره → صفحهٔ جزئیات، دکمه آنجاست).
	 */
	conns.forEach( ( c, i ) => {
		const { x, y } = posOf( i );
		const st = stateOf( c );
		const models = Object.values( hub.models || {} ).filter( ( m ) => m.connectionId === c.id );
		const node = h( 'div', {
			class: `topo-node ${ st.cls }`,
			title: `${ c.label } — ${ st.label } · ${ models.length } مدل`,
		}, [
			h( 'span', { class: `topo-dot ${ st.cls }` } ),
			h( 'span', { class: 'pav brand xs', style: `background:${ brandColor( c.provider, c.label ) }`, text: ( c.label || '?' ).trim().slice( 0, 1 ).toUpperCase() } ),
			h( 'span', { class: 'topo-name', text: c.label } ),
		] );
		node.style.insetInlineStart = `${ ( x / W ) * 100 }%`;
		node.style.top = `${ ( y / H ) * 100 }%`;
		node.onclick = () => { state.view = 'connections'; state.provider = c.provider; again( page ); };
		map.appendChild( node );
	} );

	box.appendChild( h( 'div', { class: 'form-card topo-card' }, [ map ] ) );
	// راهنما: همان سه‌گانهٔ بالا-راست Snap10، با همان ترتیب.
	box.appendChild( h( 'div', { class: 'topo-legend note' }, [
		h( 'span', { class: 'lg-edge active' } ), h( 'span', { text: 'فعال' } ),
		h( 'span', { class: 'lg-edge recent' } ), h( 'span', { text: 'اخیر (یال)' } ),
		h( 'span', { class: 'lg-edge error' } ), h( 'span', { text: 'خطا (یال)' } ),
		h( 'span', { class: 'note', text: '— ضخامت یال = ترافیک ثبت‌شده. برای جزئیات، روی هر سرویس کلیک کن.' } ),
	] ) );

	// آخرین مسیرها
	const recent = snap?.recent || [];
	if ( recent.length ) {
		box.appendChild( section( 'آخرین مسیرها', '۲۰ تصمیم مسیریابی آخر، تازه‌ترین اول.' ) );
		box.appendChild( h( 'div', { class: 'card-list compact' },
			recent.map( ( r ) => h( 'div', { class: `item ${ r.ok ? '' : 'bad' }` }, [
				h( 'div', { class: 'item-main' }, [
					h( 'p', { class: 'mono note', text: `${ String( r.at ).slice( 11, 19 ) } · ${ r.model || r.key } · ${ r.category || '—' }` } ),
					h( 'p', { class: 'note', text: r.ok ? `موفق · ${ r.ms || 0 }ms${ r.cost ? ` · ~$${ Number( r.cost ).toFixed( 5 ) }` : '' }` : ( r.error || 'ناموفق' ) } ),
				] ),
			] ) )
		) );
	}
}

/* ═══════════════════════════════════════════════ اتصال‌ها — کاتالوگ ═════════════════════════════════════ */

function renderCatalog( box, page ) {
	box.appendChild( section( 'اتصال‌ها', 'از کاتالوگ انتخاب کن، کلید بده، تست کن. از یک سرویس می‌توانی چند حساب جدا داشته باشی.' ) );



	const hub = snap?.hub || {};
	const catalog = ( snap?.catalog || [] ).filter( ( p ) => p.id !== 'mock' );
	const conns = Object.values( hub.connections || {} );
	const health = snap?.health || {};

	const search = h( 'input', { class: 'field hub-search', placeholder: 'جستجوی سرویس…' } );
	const chip = ( id, label ) => h( 'button', {
		class: `btn outline sm hub-filter-${ id }`,
		text: label,
		onClick: () => { filters.mode = filters.mode === id ? 'all' : id; sync(); },
	} );
	const filters = { mode: 'all', q: '' };
	const toolbar = row( search, chip( 'linked', 'متصل' ), chip( 'local', 'محلی' ), h( 'span', { class: 'grow' } ),
		h( 'button', { class: 'btn solid', text: '+ اتصال تازه', onClick: () => connWizard( null, null, page ) } ) );
	box.appendChild( toolbar );

	const grid = el( 'div', 'hub-catalog' );
	box.appendChild( grid );

	const statusOf = ( c ) => {
		const rows = Object.entries( health ).filter( ( [ k ] ) => k.startsWith( `${ c.id }::` ) );
		if ( c.enabled === false ) { return 'خاموش'; }
		if ( rows.some( ( [ , v ] ) => v.exhausted ) ) { return 'اعتبار تمام'; }
		if ( rows.some( ( [ , v ] ) => v.circuit === 'open' ) ) { return 'مدار باز'; }
		return 'فعال';
	};

	const drawCards = () => {
		grid.replaceChildren();
		const q = filters.q.trim().toLowerCase();
		const list = [ ...catalog, ...[ ...CUSTOM ].map( ( id ) => catalog.find( ( p ) => p.id === id ) || { id, label: id === 'openai-compatible' ? 'سرویس دلخواه — سازگار با OpenAI' : 'سرویس دلخواه — سازگار با Anthropic', kind: id.includes( 'anthropic' ) ? 'anthropic' : 'openai', baseUrl: '', needsKey: true, editableBaseUrl: true, note: 'هر سرویس‌دهنده‌ای که مسیر سازگار دارد — از جمله سرویس‌های ایرانی.' } ) ];
		for ( const p of list ) {
			const mine = conns.filter( ( c ) => c.provider === p.id );
			if ( filters.mode === 'linked' && ! mine.length ) { continue; }
			if ( filters.mode === 'local' && ! isLocal( p ) ) { continue; }
			if ( q && ! `${ p.label } ${ p.id }`.toLowerCase().includes( q ) ) { continue; }
			const bad = mine.some( ( c ) => [ 'مدار باز', 'اعتبار تمام' ].includes( statusOf( c ) ) );
			const live = mine.find( ( c ) => c.enabled !== false ) || mine[ 0 ] || null;
			const on = Boolean( live && live.enabled !== false );
			const dot = ! mine.length ? 'none' : bad ? 'bad' : 'ok';

			/*
			 * کارت به سبک Snap4: نقطهٔ وضعیت گوشهٔ بالا، لوگو + نام، چیپ قابلیت، و
			 * پاورقیِ سبک با کلید روشن/خاموش و «تست» آیکونی.
			 *
			 * کارفرما صریح گفت «نه این دکمه‌های زمخت»: دو دکمهٔ هم‌وزنِ پر، جای
			 * پاورقی مرجع را گرفته بودند. حالا کل کارت کلیک‌پذیر است و کنش‌ها
					 * سبک‌اند — دقیقاً مثل مرجع.
			 */
			const card = h( 'div', {
				class: `hub-card ${ mine.length ? 'linked' : '' } ${ bad ? 'bad' : '' }`,
				title: p.baseUrl || p.note || '',
				onClick: () => {
					if ( mine.length ) {
						state.provider = p.id;
						again( page );
					} else {
						connWizard( null, p.id, page );
					}
				},
			}, [
				h( 'span', { class: `card-dot ${ dot }` } ),
				h( 'div', { class: 'hub-card-top' }, [
					h( 'span', { class: 'pav brand', style: `background:${ brandColor( p.id, p.label ) }`, text: ( p.label || '?' ).trim().slice( 0, 1 ).toUpperCase() } ),
					h( 'b', { class: 'card-name', text: p.label } ),
				] ),
				h( 'span', { class: 'cap-chip', text: p.kind === 'anthropic' ? 'Messages' : 'Chat' } ),
				h( 'div', { class: 'hub-card-foot' }, [
					h( 'span', { class: `card-state ${ mine.length ? ( bad ? 'bad' : 'ok' ) : '' }`,
						text: mine.length ? `${ mine.length } اتصال` : 'متصل نیست' } ),
					h( 'span', { class: 'grow' } ),
					mine.length ? h( 'button', {
						class: `btn sw ${ on ? 'on' : '' }`,
						title: on ? 'روشن — برای خاموش‌کردن کلیک کن' : 'خاموش',
						onClick: async ( e ) => {
							e.stopPropagation();
							await action( { action: 'save-connection', connection: { ...live, apiKey: undefined, enabled: ! on } } );
							await again( page );
						},
					}, [ h( 'span', { class: 'sw-knob' } ) ] ) : null,
					mine.length ? h( 'button', {
						class: 'btn icon-act',
						title: 'تست اتصال',
						onClick: async ( e ) => {
							e.stopPropagation();
							toast( `در حال آزمودن «${ live.label }»…` );
							const out = await action( { action: 'test-connection', id: live.id } );
							toast( out.ok ? out.message : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
						},
					}, [ h( 'span', { html: iconSvg( 'bolt', 11 ) } ), h( 'span', { text: 'تست' } ) ] ) : h( 'span', { class: 'card-add', text: '+ افزودن' } ),
				] ),
			] );
			grid.appendChild( card );
		}
		if ( ! grid.children.length ) {
			grid.appendChild( emptyBox( 'سرویسی با این فیلتر پیدا نشد.' ) );
		}
	};
	const sync = () => { filters.q = search.value; drawCards(); };
	search.oninput = sync;
	drawCards();

	// پانوشت حالت ساده — دیگر رقیب هم‌عرض نیست (طرح §۲).
	box.appendChild( h( 'div', { class: 'form-card simple-mode' }, [
		h( 'div', { class: 'item-main' }, [
			h( 'b', { text: 'حالت ساده (پروفایل تک‌نفرهٔ قدیمی)' } ),
			h( 'p', { class: 'note', text: snap?.active ? 'هاب فرمان را دارد؛ این حالت کنار گذاشته شده.' : 'همین حالا فعال است — هاب که روشن و آماده شود، کنار می‌رود.' } ),
		] ),
	] ) );
}

/* ═══════════════════════════════════════════════ جزئیات یک سرویس ═════════════════════════════════════ */

function renderDetail( box, page, providerId ) {
	const hub = snap?.hub || {};
	const catalog = snap?.catalog || [];
	const info = catalog.find( ( p ) => p.id === providerId ) || { id: providerId, label: providerId };
	const conns = Object.values( hub.connections || {} ).filter( ( c ) => c.provider === providerId );
	const health = snap?.health || {};

	box.appendChild( h( 'button', { class: 'btn quiet sm hub-back', text: '→ همهٔ سرویس‌ها', onClick: () => { state.provider = null; again( page ); } } ) );
	box.appendChild( section( info.label, info.note || ( info.baseUrl ? `آدرس رسمی: ${ info.baseUrl }` : '' ) ) );

	if ( ! conns.length ) {
		box.appendChild( emptyBox( 'از این سرویس هنوز اتصالی نساخته‌ای.' ) );
		box.appendChild( row( h( 'button', { class: 'btn solid', text: 'افزودن اتصال', onClick: () => connWizard( null, providerId, page ) } ) ) );
		return;
	}

	const allModels = conns.flatMap( ( c ) => Object.values( hub.models || {} ).filter( ( m ) => m.connectionId === c.id ) );
	const broken = conns.filter( ( c ) => Object.entries( health ).some( ( [ k, v ] ) => k.startsWith( `${ c.id }::` ) && ( v.circuit === 'open' || v.exhausted ) ) );
	box.appendChild( row(
		h( 'span', { class: 'tag', text: `${ conns.length } اتصال` } ),
		h( 'span', { class: 'tag', text: `${ allModels.length } مدل` } ),
		broken.length ? h( 'span', { class: 'tag err', text: `${ broken.length } اتصال دارای خطا` } ) : h( 'span', { class: 'tag ok', text: 'بدون خطا' } ),
		h( 'span', { class: 'grow' } ),
		h( 'button', { class: 'btn solid', text: '+ اتصال تازه', onClick: () => connWizard( null, providerId, page ) } ),
		broken.length ? h( 'button', {
			class: 'btn outline',
			text: 'ریست همهٔ خطاهای این سرویس',
			onClick: async () => {
				if ( ! ( await confirmDialog( `وضعیت خطای هر ${ broken.length } اتصال این سرویس ریست شود؟` ) ) ) { return; }
				for ( const c of broken ) {
					await action( { action: 'reset-provider', id: c.id } );
				}
				toast( 'ریست شد.' );
				await again( page );
			},
		} ) : null
	) );

	// ——— اتصال‌ها
	const list = el( 'div', 'card-list' );
	box.appendChild( list );
	for ( const c of conns ) {
		const models = Object.values( hub.models || {} ).filter( ( m ) => m.connectionId === c.id );
		const hrows = Object.entries( health ).filter( ( [ k ] ) => k.startsWith( `${ c.id }::` ) );
		const isBad = hrows.some( ( [ , v ] ) => v.circuit === 'open' || v.exhausted );
		list.appendChild(
			h( 'div', { class: `item ${ c.enabled === false ? 'off' : '' } ${ isBad ? 'bad' : '' }` }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: c.label } ),
					h( 'p', { class: 'mono', text: `${ c.provider } · ${ c.baseUrl || '—' }` } ),
					h( 'p', { class: 'note', text: `${ models.length } مدل · ${ models.filter( ( m ) => m.enabled ).length } روشن · اولویت ${ c.priority } · هم‌زمانی ${ c.maxConcurrent }${ c.dailyCap ? ` · سقف روزانه ${ c.dailyCap }` : '' }` } ),
					hrows.some( ( [ , v ] ) => v.exhausted ) ? h( 'p', { class: 'note error', text: 'اعتبار این اتصال تمام علامت خورده — با شارژ، ریستش کن.' } ) : null,
				c.proxy ? h( 'p', { class: 'note', text: `پراکسی مخصوص: ${ c.proxy }` } ) : null,
				] ),
				h( 'span', { class: `tag ${ c.hasKey || CUSTOM.has( c.provider ) ? 'ok' : '' }`, text: c.hasKey ? 'کلید ✓' : CUSTOM.has( c.provider ) ? 'سازگار' : 'بدون کلید' } ),
				h( 'button', {
					class: 'btn outline', text: 'کشف مدل‌ها',
					onClick: async () => {
						toast( 'در حال گرفتن فهرست مدل‌ها…' );
						const out = await action( { action: 'discover', id: c.id } );
						toast( out.ok ? `${ out.added } مدل تازه، ${ out.kept } قبلی، ${ out.missing } ناپیدا.` : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
						await again( page );
					},
				} ),
				h( 'button', {
					class: 'btn outline', text: 'تست',
					onClick: async () => {
						toast( 'در حال آزمودن…' );
						const out = await action( { action: 'test-connection', id: c.id } );
						toast( out.ok ? out.message : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
					},
				} ),
				h( 'button', {
					class: 'btn outline', text: 'ریست و رفع خطا', title: 'مدارشکن‌ها، خطاها و آمار مدل‌های این اتصال پاک می‌شود.',
					onClick: async () => {
						if ( ! ( await confirmDialog( `وضعیت «${ c.label }» ریست شود؟` ) ) ) { return; }
						const out = await action( { action: 'reset-provider', id: c.id } );
						toast( out.ok ? `ریست شد — ${ out.cleared } مدل پاک شد.` : out.error, out.ok ? 'ok' : 'error' );
						await again( page );
					},
				} ),
				h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => connWizard( c, providerId, page ) } ),
				h( 'button', {
					class: 'btn quiet danger', text: 'حذف',
					onClick: async () => {
						if ( ! ( await confirmDialog( `اتصال «${ c.label }» و همهٔ مدل‌هایش حذف شود؟`, { danger: true } ) ) ) { return; }
						await action( { action: 'remove-connection', id: c.id } );
						await again( page );
					},
				} ),
			] )
		);
	}

	// ——— مدل‌های همین سرویس (الگوی Snap12)
	renderProviderModels( box, page, conns, allModels );
}

function renderProviderModels( box, page, conns, allModels ) {
	box.appendChild( section( 'مدل‌های این سرویس', 'کشف خودکار نقطهٔ شروع است؛ برچسب و قیمت را خودت درست کن و ویرا از نتیجهٔ واقعی یاد می‌گیرد.' ) );

	if ( ! allModels.length ) {
		box.appendChild( emptyBox( 'هنوز مدلی کشف نشده — روی «کشف مدل‌ها»ی یک اتصال بزن.' ) );
		return;
	}

	const filters = { q: '', free: false, hideFailed: false };
	const search = h( 'input', { class: 'field hub-search', placeholder: 'جستجو در مدل‌ها…' } );
	const freeBtn = h( 'button', { class: 'btn outline sm', text: 'فقط رایگان', onClick: () => { filters.free = ! filters.free; sync(); } } );
	const hideBtn = h( 'button', { class: 'btn outline sm', text: 'مخفی‌کردن شکست‌خورده‌ها', onClick: () => { filters.hideFailed = ! filters.hideFailed; sync(); } } );
	const counter = h( 'span', { class: 'note' } );
	const testAll = h( 'button', {
		class: 'btn outline',
		text: 'آزمون همهٔ مدل‌ها',
		onClick: async () => {
			testAll.disabled = true;
			let ok = 0, fail = 0;
			for ( const m of allModels.filter( ( x ) => x.enabled ) ) {
				const out = await action( { action: 'test-connection', id: m.connectionId, model: m.modelId } );
				out.ok ? ok++ : fail++;
			}
			testAll.disabled = false;
			toast( `آزمون همه: ${ ok } سالم، ${ fail } خطا.`, fail ? 'error' : 'ok' );
			await again( page );
		},
	} );
	box.appendChild( row( search, freeBtn, hideBtn, counter, h( 'span', { class: 'grow' } ), testAll ) );

	const list = el( 'div', 'card-list' );
	box.appendChild( list );
	const categories = snap?.categories || [];

	const draw = () => {
		list.replaceChildren();
		const health = snap?.health || {};
		const hub = snap?.hub || {};
		const q = filters.q.trim().toLowerCase();
		let shown = 0;
		for ( const m of allModels ) {
			if ( q && ! m.key.toLowerCase().includes( q ) ) { continue; }
			if ( filters.free && ! ( m.priceIn === 0 && m.priceOut === 0 ) ) { continue; }
			const stat = health[ m.key ];
			if ( filters.hideFailed && ( m.missing || ( stat && ( stat.circuit === 'open' || stat.exhausted ) ) ) ) { continue; }
			shown++;
			const conn = hub.connections?.[ m.connectionId ];
			list.appendChild(
				h( 'div', { class: `item ${ m.enabled ? '' : 'off' } ${ m.missing ? 'missing' : '' }` }, [
					h( 'div', { class: 'item-main' }, [
						h( 'b', { text: m.label || m.modelId } ),
						h( 'p', { class: 'mono', text: `${ conn?.label || m.connectionId } · ${ m.modelId }` } ),
						h( 'p', { class: 'note', text: [
							m.context ? `کانتکست ${ Intl.NumberFormat( 'fa' ).format( m.context ) }` : null,
							m.priceIn !== null ? `ورودی $${ m.priceIn }/M` : null,
							m.priceOut !== null ? `خروجی $${ m.priceOut }/M` : null,
							m.caps?.vision ? 'بینا' : null,
							m.caps?.reasoning ? 'استدلالی' : null,
							stat ? `نرخ ${ Math.round( stat.successRate * 100 ) }٪` : null,
							stat?.p95 ? `p95 ${ stat.p95 }ms` : null,
						].filter( Boolean ).join( ' · ' ) } ),
						h( 'div', { class: 'tag-row' }, ( m.tags || [] ).map( ( tg ) => h( 'span', { class: 'tag', text: categories.find( ( cc ) => cc.id === tg )?.label || tg } ) ) ),
						m.missing ? h( 'p', { class: 'note error', text: 'در آخرین کشف برنگشت.' } ) : null,
					] ),
					stat?.exhausted ? h( 'span', { class: 'tag err', text: 'اعتبار تمام' } ) : null,
					stat && stat.circuit !== 'closed' ? h( 'span', { class: 'tag err', text: 'مدار باز' } ) : null,
					h( 'button', {
						class: 'btn outline', text: 'تست', title: 'یک درخواست آزمایشی با همین مدل',
						onClick: async () => {
							toast( `در حال آزمودن ${ m.label || m.modelId }…` );
							const out = await action( { action: 'test-connection', id: m.connectionId, model: m.modelId } );
							toast( out.ok ? out.message : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
						},
					} ),
					h( 'button', {
						class: 'btn outline', text: m.enabled ? 'خاموش' : 'روشن',
						onClick: async () => { await action( { action: 'toggle-model', key: m.key, enabled: ! m.enabled } ); await again( page ); },
					} ),
					h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => modelForm( box, page, m ) } ),
				] )
			);
		}
		counter.textContent = `${ shown } از ${ allModels.length } مدل · ${ allModels.filter( ( m ) => m.enabled ).length } روشن`;
		if ( ! shown ) {
			list.appendChild( emptyBox( 'مدلی با این فیلتر نیست.' ) );
		}
	};
	const sync = () => { filters.q = search.value; draw(); };
	search.oninput = sync;
	draw();
}

/** فرم ویرایش یک مدل — همان فرم قبلی، در کارت زیر فهرست. */
function modelForm( box, page, m ) {
	const host = el( 'div', 'form-host' );
	box.appendChild( host );
	const categories = snap?.categories || [];
	const label = h( 'input', { class: 'field', value: m.label || '' } );
	const context = h( 'input', { class: 'field', type: 'number', min: 0, value: m.context || 0 } );
	const priceIn = h( 'input', { class: 'field', type: 'number', step: '0.01', value: m.priceIn ?? '' } );
	const priceOut = h( 'input', { class: 'field', type: 'number', step: '0.01', value: m.priceOut ?? '' } );
	const priority = h( 'input', { class: 'field', type: 'number', min: 1, value: m.priority ?? 100 } );
	const weight = h( 'input', { class: 'field', type: 'number', min: 0, value: m.weight ?? 1 } );
	const caps = {};
	const capRow = h( 'div', { class: 'row wrap' } );
	for ( const [ id, name ] of [ [ 'tools', 'ابزار' ], [ 'vision', 'بینایی' ], [ 'reasoning', 'استدلال' ], [ 'stream', 'استریم' ], [ 'json', 'JSON' ] ] ) {
		caps[ id ] = h( 'input', { type: 'checkbox', checked: Boolean( m.caps?.[ id ] ) } );
		capRow.appendChild( h( 'label', { class: 'check' }, [ caps[ id ], h( 'span', { text: name } ) ] ) );
	}
	const tags = {};
	const tagRow = h( 'div', { class: 'row wrap' } );
	for ( const c of categories ) {
		tags[ c.id ] = h( 'input', { type: 'checkbox', checked: ( m.tags || [] ).includes( c.id ) } );
		tagRow.appendChild( h( 'label', { class: 'check' }, [ tags[ c.id ], h( 'span', { text: c.label } ) ] ) );
	}
	host.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: `ویرایش «${ m.modelId }»` } ),
		field( 'نام نمایشی', label ),
		row( field( 'پنجرهٔ کانتکست', context ), field( 'قیمت ورودی ($/M)', priceIn ), field( 'قیمت خروجی ($/M)', priceOut ) ),
		row( field( 'اولویت', priority ), field( 'وزن', weight ) ),
		field( 'توانایی‌ها', capRow ),
		field( 'برچسب زمینه', tagRow, 'مسیریاب از این برچسب‌ها شروع می‌کند و با نتیجهٔ واقعی اصلاحشان می‌کند.' ),
		h( 'div', { class: 'modal-actions' }, [
			h( 'span', { class: 'grow' } ),
			h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => host.replaceChildren() } ),
			h( 'button', {
				class: 'btn solid', text: 'ذخیره',
				onClick: async () => {
					await action( { action: 'save-model', model: {
						key: m.key,
						label: label.value.trim(),
						context: Number( context.value ) || 0,
						priceIn: priceIn.value === '' ? null : Number( priceIn.value ),
						priceOut: priceOut.value === '' ? null : Number( priceOut.value ),
						priority: Number( priority.value ) || 100,
						weight: Number( weight.value ) || 1,
						caps: Object.fromEntries( Object.entries( caps ).map( ( [ k, v ] ) => [ k, v.checked ] ) ),
						tags: Object.entries( tags ).filter( ( [ , v ] ) => v.checked ).map( ( [ k ] ) => k ),
					} } );
					toast( 'ذخیره شد.' );
					await again( page );
				},
			} ),
		] ),
	] ) );
	host.scrollIntoView?.( { block: 'nearest' } );
}

/* ═══════════════════════════════════════════════ ویزارد اتصال (الگوی Snap9) ═════════════════════════════ */

/**
 * چهار گام: سرویس → شناسنامه → تست زنده → نتیجه.
 * @param {any} conn اتصال موجود (ویرایش) یا null
 * @param {string|null} preset شناسهٔ سرویس از قبل انتخاب‌شده
 * @param {HTMLElement} page برای بازکشی
 */
function connWizard( conn, preset, page ) {
	const catalog = ( snap?.catalog || [] ).filter( ( p ) => p.id !== 'mock' );
	const compat = !! conn ? CUSTOM.has( conn.provider ) : CUSTOM.has( preset || '' );
	const dlg = h( 'dialog', { class: 'modal hub-wizard' } );
	const shell = el( 'div', 'modal-body');
	dlg.appendChild( shell );
	document.body.appendChild( dlg );

	let step = conn ? 2 : ( preset ? 2 : 1 );
	/** @type {any} */
	const data = {
		provider: conn?.provider || preset || catalog[ 0 ]?.id || 'openai',
		label: conn?.label || '',
		apiKey: '',
		baseUrl: conn?.baseUrl || '',
		authStyle: conn?.authStyle || 'bearer',
		authHeader: conn?.authHeader || '',
		modelsPath: conn?.modelsPath || '',
		headers: conn?.headers || {},
		priority: conn?.priority ?? 100,
		maxConcurrent: conn?.maxConcurrent ?? 4,
		dailyCap: conn?.dailyCap ?? '',
		enabled: conn ? conn.enabled !== false : true,
		proxy: conn?.proxy || '',
	};
	let savedId = conn?.id || null;

	const info = () => catalog.find( ( p ) => p.id === data.provider ) || {};
	const isCompat = () => CUSTOM.has( data.provider );

	const stepsBar = () => h( 'div', { class: 'steps' }, [ 'سرویس', 'شناسنامه', 'تست زنده', 'نتیجه' ].map( ( s, i ) =>
		h( 'span', { class: `step ${ i + 1 === step ? 'now' : '' } ${ i + 1 < step ? 'done' : '' }`, text: `${ [ '۱', '۲', '۳', '۴' ][ i ] }. ${ s }` } ) ) );

	const draw = () => {
		shell.replaceChildren(
			h( 'h3', { text: conn ? `ویرایش «${ conn.label }»` : 'اتصال تازه' } ),
			stepsBar()
		);

		if ( step === 1 ) {
			const grid = el( 'div', 'hub-catalog mini' );
			for ( const p of [ ...catalog ] ) {
				grid.appendChild( h( 'div', {
					class: `hub-card mini ${ data.provider === p.id ? 'sel' : '' }`,
					onClick: () => { data.provider = p.id; draw(); },
				}, [
					h( 'div', { class: 'hub-card-top' }, [
						h( 'span', { class: 'pav brand', style: `background:${ brandColor( p.id, p.label ) }`, text: ( p.label || '?' ).slice( 0, 1 ).toUpperCase() } ),
						h( 'div', { class: 'item-main' }, [ h( 'b', { text: p.label } ), h( 'p', { class: 'mono note', text: p.baseUrl || p.note || '—' } ) ] ),
					] ),
				] ) );
			}
			shell.appendChild( grid );
		}

		if ( step === 2 ) {
			const label = h( 'input', { class: 'field', value: data.label } );
			const apiKey = h( 'input', { class: 'field', dir: 'ltr', type: 'password', placeholder: conn?.hasKey ? '••••••• (خالی بگذار تا بماند)' : 'کلید را از پنل سرویس‌دهنده کپی کن' } );
			const baseUrl = h( 'input', { class: 'field', dir: 'ltr', value: data.baseUrl, placeholder: 'https://…' } );
			const authStyle = h( 'select', { class: 'field' } );
			for ( const a of snap?.authStyles || [] ) { authStyle.appendChild( h( 'option', { value: a.id, text: a.label } ) ); }
			authStyle.value = data.authStyle;
			const authHeader = h( 'input', { class: 'field', dir: 'ltr', value: data.authHeader, placeholder: 'X-Custom-Key' } );
			const modelsPath = h( 'input', { class: 'field', dir: 'ltr', value: data.modelsPath, placeholder: '/models' } );
			const headers = h( 'textarea', { class: 'field mono', dir: 'ltr', rows: 3, placeholder: 'X-Org: acme\nX-Region: eu', value: Object.entries( data.headers || {} ).map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '\n' ) } );
			const priority = h( 'input', { class: 'field', type: 'number', min: 1, value: data.priority } );
			const maxConcurrent = h( 'input', { class: 'field', type: 'number', min: 1, value: data.maxConcurrent } );
			const dailyCap = h( 'input', { class: 'field', type: 'number', min: 0, value: data.dailyCap } );
			const enabled = h( 'input', { type: 'checkbox', checked: data.enabled } );
			const wizardProxy = h( 'input', { class: 'field', dir: 'ltr', value: data.proxy || '', placeholder: 'http://127.0.0.1:7890' } );
			const note = h( 'p', { class: 'note' } );
			const sync = () => {
				const i2 = info();
				note.textContent = i2.note || '';
				if ( ! isCompat() && ! conn && i2.baseUrl ) { baseUrl.value = i2.baseUrl; }
			};
			const provSel = h( 'select', { class: 'field' } );
			for ( const p of catalog ) { provSel.appendChild( h( 'option', { value: p.id, text: p.label } ) ); }
			provSel.value = data.provider;
			provSel.onchange = () => { data.provider = provSel.value; draw(); };
			if ( ! conn && ! preset ) {
				// در گام ۲ هنگام ساختِ آزاد، سرویس هم عوض‌شدنی بمانَد.
			}
			sync();
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				field( 'سرویس', provSel ),
				note,
				field( 'نام', label, 'هرچه در فهرست‌ها می‌خواهی ببینی — مثلاً «OpenRouter حساب اصلی».' ),
				isCompat() ? field( 'آدرس پایه', baseUrl, 'اجباری — همان چیزی که سرویس‌دهنده می‌دهد.' )
					: field( 'آدرس پایه', h( 'p', { class: 'note mono', text: info().baseUrl || '—' } ), 'از کاتالوگ می‌آید؛ لازم نیست چیزی وارد کنی.' ),
				field( 'کلید API', apiKey, 'در فایل تنظیمات محلی و با دسترسی ۶۰۰ ذخیره می‌شود و هیچ‌وقت به رابط برنمی‌گردد.' + ( info().needsKey === false ? ' این سرویس کلید نمی‌خواهد.' : '' ) ),
				isCompat() ? field( 'سبک احراز', authStyle ) : null,
				isCompat() ? field( 'نام هدر یا پارامتر احراز', authHeader, 'فقط برای سبک «هدر دلخواه» و «پارامتر آدرس».' ) : null,
				isCompat() ? field( 'مسیر فهرست مدل', modelsPath, 'اگر سرویس مسیر غیراستانداردی دارد.' ) : null,
				isCompat() ? field( 'هدرهای سفارشی', headers, 'هر خط یک هدر: نام: مقدار' ) : null,
				row( field( 'اولویت', priority ), field( 'سقف هم‌زمانی', maxConcurrent ), field( 'سقف روزانه (تعداد تماس)', dailyCap ) ),
				field( 'پراکسی این اتصال (اختیاری)', wizardProxy, 'خالی = پراکسی سراسری هاب. مثال: http://127.0.0.1:7890' ),
				h( 'label', { class: 'check' }, [ enabled, h( 'span', { text: 'این اتصال روشن باشد' } ) ] ),
			] ) );
			shell.next2 = async () => {
				data.label = label.value.trim();
				data.apiKey = apiKey.value.trim();
				data.baseUrl = isCompat() ? baseUrl.value.trim() : ( info().baseUrl || baseUrl.value.trim() );
				data.authStyle = authStyle.value;
				data.authHeader = authHeader.value.trim();
				data.modelsPath = modelsPath.value.trim();
				data.headers = parseHeaders( headers.value );
				data.priority = Number( priority.value ) || 100;
				data.maxConcurrent = Number( maxConcurrent.value ) || 4;
				data.dailyCap = dailyCap.value === '' ? null : Number( dailyCap.value );
				data.enabled = enabled.checked;
				data.proxy = wizardProxy.value.trim();
				const out = await action( { action: 'save-connection', connection: {
					id: savedId,
					label: data.label || info().label || 'اتصال',
					provider: data.provider,
					kind: info().kind || ( data.provider.includes( 'anthropic' ) ? 'anthropic' : 'openai' ),
					baseUrl: data.baseUrl,
					apiKey: data.apiKey,
					authStyle: data.authStyle,
					authHeader: data.authHeader,
					modelsPath: data.modelsPath,
					headers: data.headers,
					priority: data.priority,
					maxConcurrent: data.maxConcurrent,
					dailyCap: data.dailyCap,
					enabled: data.enabled,
					proxy: data.proxy,
				} } );
				if ( out.error ) {
					toast( out.error, 'error' );
					return false;
				}
				savedId = out.connection?.id || savedId;
				return true;
			};
		}

		if ( step === 3 ) {
			const res = el( 'div', {}, [ h( 'p', { class: 'note', text: 'ذخیره شد. حالا یک درخواست آزمایشی بفرست — اگر سرویس کلید یا آدرس را نپسندد، همین‌جا می‌فهمی.' } ) ] );
			const testBtn = h( 'button', {
				class: 'btn solid', text: 'تست اتصال',
				onClick: async () => {
					testBtn.disabled = true;
					const out = await action( { action: 'test-connection', id: savedId } );
					testBtn.disabled = false;
					res.replaceChildren(
						h( 'p', { class: `note ${ out.ok ? '' : 'error' }`, text: out.ok ? out.message : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }` } ),
					);
				},
			} );
			const discBtn = h( 'button', {
				class: 'btn outline', text: 'کشف مدل‌ها',
				onClick: async () => {
					discBtn.disabled = true;
					toast( 'در حال گرفتن فهرست مدل‌ها…' );
					const out = await action( { action: 'discover', id: savedId } );
					discBtn.disabled = false;
					res.appendChild( h( 'p', { class: `note ${ out.ok ? '' : 'error' }`, text: out.ok ? `${ out.added } مدل تازه، ${ out.kept } قبلی، ${ out.missing } ناپیدا.` : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }` } ) );
				},
			} );
			shell.appendChild( h( 'div', { class: 'form-card' }, [ row( testBtn, discBtn ), res ] ) );
		}

		if ( step === 4 ) {
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				h( 'p', { class: 'note', text: `اتصال «${ data.label || info().label }» ذخیره شد. اگر مدلی هم کشف کردی، در جزئیات همین سرویس روشن و خاموشش کن.` } ),
				h( 'p', { class: 'note', text: snap?.hub?.enabled ? 'هاب روشن است.' : 'هاب خاموش است — نوار بالای صفحه با یک دکمه روشنش می‌کند.' } ),
			] ) );
		}

		const nav = h( 'div', { class: 'modal-actions' }, [
			h( 'button', {
				class: 'btn outline', text: step > 1 ? '→ قبلی' : 'انصراف',
				onClick: async () => {
					if ( step === 1 ) { dlg.close?.(); dlg.remove?.(); return; }
					step -= 1;
					draw();
				},
			} ),
			h( 'span', { class: 'grow' } ),
			h( 'button', {
				class: 'btn solid', text: step === 4 ? 'تمام' : 'بعدی ←',
				onClick: async () => {
					if ( step === 2 ) {
						if ( ! ( await shell.next2() ) ) { return; }
					}
					if ( step === 4 ) {
						dlg.close?.();
						dlg.remove?.();
						state.view = 'connections';
						state.provider = data.provider;
						await again( page );
						return;
					}
					step += 1;
					draw();
				},
			} ),
		] );
		shell.appendChild( nav );
	};

	draw();
	dlg.showModal?.();
}

function parseHeaders( text ) {
	/** @type {Record<string,string>} */
	const out = {};
	for ( const line of String( text || '' ).split( '\n' ) ) {
		const i = line.indexOf( ':' );
		if ( i > 0 ) {
			out[ line.slice( 0, i ).trim() ] = line.slice( i + 1 ).trim();
		}
	}
	return out;
}

/* ═══════════════════════════════════════════════ ترکیب‌ها (الگوی Snap5) ═════════════════════════════ */

function renderCombos( box, page ) {
	const hub = snap?.hub || {};
	const strategies = snap?.strategies || [];
	const categories = snap?.categories || [];
	const models = Object.values( hub.models || {} ).filter( ( m ) => m.enabled );

	box.appendChild( section( 'ترکیب‌ها', 'ترکیب یعنی یک زنجیرهٔ نام‌دار از مدل‌ها با یک راهبرد. دستهٔ کار می‌گوید کدام ترکیب برای چه جنسی از درخواست.' ) );
	box.appendChild( row( h( 'button', { class: 'btn solid', text: '+ ترکیب تازه', onClick: () => comboWizard( null, page ) } ) ) );

	const combos = Object.values( hub.combos || {} );
	const list = el( 'div', 'card-list' );
	if ( ! combos.length ) {
		list.appendChild( emptyBox( 'هنوز ترکیبی نساخته‌ای. بدون ترکیب، همهٔ مدل‌های روشن با راهبرد پیش‌فرض نامزد می‌شوند.' ) );
	}
	for ( const c of combos ) {
		list.appendChild( h( 'div', { class: 'item' }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: c.label } ),
				h( 'p', { class: 'note', text: `${ strategies.find( ( s ) => s.id === c.strategy )?.label || c.strategy } · ${ c.members?.length || 0 } مدل` } ),
				h( 'p', { class: 'mono note', text: ( c.members || [] ).join( ' → ' ) || 'همهٔ مدل‌های روشن' } ),
			] ),
			h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => comboWizard( c, page ) } ),
			h( 'button', {
				class: 'btn quiet danger', text: 'حذف',
				onClick: async () => { await action( { action: 'remove-combo', id: c.id } ); await again( page ); },
			} ),
		] ) );
	}
	box.appendChild( list );

	// ——— راهبرد پیش‌فرض
	const strategy = h( 'select', { class: 'field' } );
	for ( const s of strategies ) { strategy.appendChild( h( 'option', { value: s.id, text: s.label } ) ); }
	strategy.value = hub.routing?.strategy || 'auto';
	const stratNote = h( 'p', { class: 'note' } );
	const syncStrat = () => { stratNote.textContent = strategies.find( ( s ) => s.id === strategy.value )?.note || ''; };
	strategy.onchange = syncStrat;
	syncStrat();
	const fallback = h( 'input', { type: 'checkbox', checked: hub.routing?.fallback !== false } );
	const maxAttempts = h( 'input', { class: 'field', type: 'number', min: 1, max: 6, value: hub.routing?.maxAttempts ?? 3 } );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'راهبرد پیش‌فرض' } ),
		field( 'راهبرد', strategy ), stratNote,
		row( field( 'حداکثر تلاش', maxAttempts ) ),
		h( 'label', { class: 'check' }, [ fallback, h( 'span', { text: 'اگر مدل اول شکست خورد، بی‌صدا برو سراغ بعدی' } ) ] ),
		h( 'div', { class: 'modal-actions' }, [ h( 'span', { class: 'grow' } ), h( 'button', {
			class: 'btn solid', text: 'ذخیره',
			onClick: async () => {
				await action( { action: 'update', patch: { routing: { strategy: strategy.value, fallback: fallback.checked, maxAttempts: Number( maxAttempts.value ) || 3 } } } );
				toast( 'ذخیره شد.' );
				await again( page );
			},
		} ) ] ),
	] ) );

	// ——— دستهٔ کار → ترکیب
	const mapCard = h( 'div', { class: 'form-card' }, [ h( 'h4', { text: 'دستهٔ کار' } ) ] );
	/** @type {Record<string, any>} */
	const selects = {};
	for ( const cat of categories ) {
		const sel = h( 'select', { class: 'field' } );
		sel.appendChild( h( 'option', { value: '', text: '— پیش‌فرض —' } ) );
		for ( const c of combos ) { sel.appendChild( h( 'option', { value: c.id, text: c.label } ) ); }
		sel.value = hub.categoryCombo?.[ cat.id ] || '';
		selects[ cat.id ] = sel;
		mapCard.appendChild( field( cat.label, sel ) );
	}
	mapCard.appendChild( h( 'div', { class: 'modal-actions' }, [ h( 'span', { class: 'grow' } ), h( 'button', {
		class: 'btn solid', text: 'ذخیره',
		onClick: async () => {
			await action( { action: 'update', patch: { categoryCombo: Object.fromEntries( Object.entries( selects ).map( ( [ k, v ] ) => [ k, v.value ] ) ) } } );
			toast( 'ذخیره شد.' );
			await again( page );
		},
	} ) ] ) );
	box.appendChild( mapCard );

	// ——— آزمون مسیر
	const probe = h( 'textarea', { class: 'field', rows: 3, placeholder: 'مثلاً: این تابع خطا می‌دهد، دیباگش کن' } );
	const withImage = h( 'input', { type: 'checkbox' } );
	const withTools = h( 'input', { type: 'checkbox', checked: true } );
	const result = el( 'div', 'route-result' );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'این درخواست به کجا می‌رود؟' } ),
		h( 'p', { class: 'note', text: 'یک متن نمونه بنویس و ببین ویرا آن را چه جنسی می‌فهمد و به کدام مدل می‌فرستد — بدون تماس واقعی.' } ),
		probe,
		row(
			h( 'label', { class: 'check' }, [ withImage, h( 'span', { text: 'همراه تصویر' } ) ] ),
			h( 'label', { class: 'check' }, [ withTools, h( 'span', { text: 'با ابزار' } ) ] ),
			h( 'span', { class: 'grow' } ),
			h( 'button', {
				class: 'btn solid', text: 'ببین کجا می‌رود',
				onClick: async () => {
					const out = await action( { action: 'explain', text: probe.value, hasImages: withImage.checked, tools: withTools.checked ? [ 'bash', 'edit_file' ] : [] } );
					result.replaceChildren(
						h( 'p', { text: `جنس درخواست: ${ categories.find( ( c ) => c.id === out.classification?.category )?.label || out.classification?.category } (اطمینان ${ Math.round( ( out.classification?.confidence || 0 ) * 100 ) }٪) · راهبرد: ${ strategies.find( ( s ) => s.id === out.strategy )?.label || out.strategy }` } ),
						h( 'p', { class: 'note', text: `دلیل: ${ ( out.classification?.reasons || [] ).join( '، ' ) || '—' }` } ),
						h( 'ol', { class: 'route-list' }, ( out.candidates || [] ).slice( 0, 5 ).map( ( c ) =>
							h( 'li', {}, [ h( 'b', { text: c.label } ), h( 'span', { class: 'note', text: ` امتیاز ${ c.score } · ${ c.connectionLabel }${ c.cost !== null && c.cost !== undefined ? ` · ~$${ c.cost.toFixed( 5 ) }` : '' }` } ) ] )
						) ),
						( out.blocked || [] ).length ? h( 'p', { class: 'note error', text: `کنارگذاشته‌شده: ${ out.blocked.map( ( b ) => `${ b.key } (${ b.reason })` ).join( '، ' ) }` } ) : null,
						! out.budget?.allowed ? h( 'p', { class: 'note error', text: out.budget.reason } ) : null,
					);
				},
			} ),
		),
		result,
	] ) );
}

/** ویزارد چهارگامی ترکیب: نام → مدل‌ها → راهبرد → بازبینی و ذخیره. */
function comboWizard( combo, page ) {
	const strategies = snap?.strategies || [];
	const models = Object.values( snap?.hub?.models || {} ).filter( ( m ) => m.enabled );
	const INTELLIGENT = new Set( [ 'auto', 'cost-optimized', 'fastest', 'p2c' ] );
	const dlg = h( 'dialog', { class: 'modal hub-wizard' } );
	const shell = el( 'div', 'modal-body' );
	dlg.appendChild( shell );
	document.body.appendChild( dlg );
	let step = 1;
	const data = { label: combo?.label || '', members: [ ...( combo?.members || [] ) ], strategy: combo?.strategy || 'auto' };

	const stepsBar = () => h( 'div', { class: 'steps' }, [ 'نام', 'مدل‌ها', 'راهبرد', 'بازبینی' ].map( ( s, i ) =>
		h( 'span', { class: `step ${ i + 1 === step ? 'now' : '' } ${ i + 1 < step ? 'done' : '' }`, text: `${ [ '۱', '۲', '۳', '۴' ][ i ] }. ${ s }` } ) ) );

	const draw = () => {
		shell.replaceChildren( h( 'h3', { text: combo ? `ویرایش «${ combo.label }»` : 'ترکیب تازه' } ), stepsBar() );
		if ( step === 1 ) {
			const label = h( 'input', { class: 'field', value: data.label } );
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				field( 'نام ترکیب', label, 'مثلاً «کد روزمره» یا «نوشتن متن بلند».' ),
			] ) );
			shell.next = () => { data.label = label.value.trim() || 'ترکیب'; };
		}
		if ( step === 2 ) {
			const picked = el( 'div', 'card-list compact' );
			const drawPicked = () => {
				picked.replaceChildren();
				if ( ! data.members.length ) {
					picked.appendChild( emptyBox( 'هیچ مدلی انتخاب نشده — یعنی همهٔ مدل‌های روشن.' ) );
				}
				data.members.forEach( ( key, i ) => {
					picked.appendChild( h( 'div', { class: 'item' }, [
						h( 'span', { class: 'tag', text: String( i + 1 ) } ),
						h( 'div', { class: 'item-main' }, [ h( 'p', { class: 'mono', text: key } ) ] ),
						h( 'button', { class: 'btn outline', html: iconSvg( 'up', 13 ), title: 'بالا', onClick: () => { if ( i > 0 ) { [ data.members[ i - 1 ], data.members[ i ] ] = [ data.members[ i ], data.members[ i - 1 ] ]; drawPicked(); } } } ),
						h( 'button', { class: 'btn outline', html: iconSvg( 'down', 13 ), title: 'پایین', onClick: () => { if ( i < data.members.length - 1 ) { [ data.members[ i + 1 ], data.members[ i ] ] = [ data.members[ i ], data.members[ i + 1 ] ]; drawPicked(); } } } ),
						h( 'button', { class: 'btn quiet danger', html: iconSvg( 'times', 13 ), title: 'حذف', onClick: () => { data.members.splice( i, 1 ); drawPicked(); } } ),
					] ) );
				} );
			};
			drawPicked();
			const add = h( 'select', { class: 'field' } );
			add.appendChild( h( 'option', { value: '', text: '— مدل اضافه کن —' } ) );
			for ( const m of models ) { add.appendChild( h( 'option', { value: m.key, text: m.label || m.modelId } ) ); }
			add.onchange = () => { if ( add.value && ! data.members.includes( add.value ) ) { data.members.push( add.value ); drawPicked(); } add.value = ''; };
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				field( 'مدل‌ها به ترتیب تلاش', picked, 'راهبردهای اولویتی از بالا شروع می‌کنند.' ),
				add,
			] ) );
		}
		if ( step === 3 ) {
			const group = ( title, ids, hint ) => h( 'div', { class: 'field' }, [
				h( 'b', { text: title } ), h( 'p', { class: 'note', text: hint } ),
				h( 'div', { class: 'row wrap' }, strategies.filter( ( s ) => ids.has( s.id ) ).map( ( s ) =>
					h( 'label', { class: 'check' }, [
						h( 'input', { type: 'radio', name: 'combo-strat', checked: data.strategy === s.id, onChange: () => { data.strategy = s.id; } } ),
						h( 'span', { text: `${ s.label } — ${ s.note || '' }` } ),
					] ) ) ),
			] );
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				group( 'هوشمند', INTELLIGENT, 'خودشان از نتیجه و وضعیت لحظه‌ای می‌آموزند و انتخاب می‌کنند.' ),
				group( 'قطعی', new Set( strategies.map( ( s ) => s.id ).filter( ( id ) => ! INTELLIGENT.has( id ) ) ), 'قاعدهٔ ثابت و قابل پیش‌بینی.' ),
			] ) );
		}
		if ( step === 4 ) {
			shell.appendChild( h( 'div', { class: 'form-card' }, [
				h( 'b', { text: data.label } ),
				h( 'p', { class: 'note', text: `${ strategies.find( ( s ) => s.id === data.strategy )?.label || data.strategy } · ${ data.members.length } مدل` } ),
				h( 'p', { class: 'mono note', text: data.members.join( ' → ' ) || 'همهٔ مدل‌های روشن' } ),
			] ) );
		}
		shell.appendChild( h( 'div', { class: 'modal-actions' }, [
			h( 'button', { class: 'btn outline', text: step > 1 ? '→ قبلی' : 'انصراف', onClick: () => { if ( step === 1 ) { dlg.close?.(); dlg.remove?.(); return; } step -= 1; draw(); } } ),
			h( 'span', { class: 'grow' } ),
			h( 'button', {
				class: 'btn solid', text: step === 4 ? 'ذخیره' : 'بعدی ←',
				onClick: async () => {
					if ( step === 4 ) {
						await action( { action: 'save-combo', combo: { id: combo?.id, label: data.label, strategy: data.strategy, members: data.members } } );
						toast( 'ذخیره شد.' );
						dlg.close?.();
						dlg.remove?.();
						await again( page );
						return;
					}
					if ( shell.next ) { shell.next(); }
					step += 1;
					draw();
				},
			} ),
		] ) );
	};
	draw();
	dlg.showModal?.();
}

/* ═══════════════════════════════════════════════ سلامت و مصرف ═════════════════════════════════════ */

function renderHealth( box, page ) {
	const hub = snap?.hub || {};
	const health = snap?.health || {};
	const budget = snap?.budget || {};
	const ledger = snap?.ledger || [];
	const diag = snap?.diagnoser || {};
	const learning = snap?.learning || {};
	const categories = snap?.categories || [];
	const rows = Object.entries( health );

	// ——— کارت‌های آماری
	const totals = rows.reduce( ( a, [ , v ] ) => ( { ok: a.ok + v.ok, fail: a.fail + v.fail, open: a.open + ( v.circuit === 'open' ? 1 : 0 ), exh: a.exh + ( v.exhausted ? 1 : 0 ) } ), { ok: 0, fail: 0, open: 0, exh: 0 } );
	box.appendChild( h( 'div', { class: 'stat-cards' }, [
		h( 'div', { class: 'hub-stat' }, [ h( 'b', { text: `${ totals.ok } / ${ totals.ok + totals.fail }` } ), h( 'span', { text: 'موفق / کل تلاش' } ) ] ),
		h( 'div', { class: `hub-stat ${ totals.open ? 'bad' : 'ok' }` }, [ h( 'b', { text: String( totals.open ) } ), h( 'span', { text: 'مدار باز' } ) ] ),
		h( 'div', { class: `hub-stat ${ totals.exh ? 'bad' : 'ok' }` }, [ h( 'b', { text: String( totals.exh ) } ), h( 'span', { text: 'اعتبار تمام' } ) ] ),
		h( 'div', { class: 'hub-stat' }, [ h( 'b', { text: `$${ budget.total ?? 0 }` } ), h( 'span', { text: budget.usedRatio != null ? `امروز — ${ Math.round( budget.usedRatio * 100 ) }٪ سقف` : 'امروز' } ) ] ),
	] ) );

	// ——— سلامت مسیرها
	box.appendChild( section( 'سلامت مسیرها', 'صدک تأخیر، نرخ موفقیت و وضعیت مدارشکن هر مدل. مدار باز یعنی ویرا فعلاً سراغ آن نمی‌رود.' ) );
	const list = el( 'div', 'card-list' );
	if ( ! rows.length ) {
		list.appendChild( emptyBox( 'هنوز تماسی ثبت نشده.' ) );
	}
	for ( const [ key, v ] of rows ) {
		list.appendChild( h( 'div', { class: `item ${ v.circuit === 'open' ? 'bad' : '' }` }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: hub.models?.[ key ]?.label || key } ),
				h( 'p', { class: 'note', text: `${ v.ok } موفق · ${ v.fail } ناموفق · نرخ ${ Math.round( v.successRate * 100 ) }٪${ v.p50 ? ` · p50 ${ v.p50 }ms` : '' }${ v.p95 ? ` · p95 ${ v.p95 }ms` : '' } · امروز ${ v.usedToday }` } ),
				v.lastError ? h( 'p', { class: 'note error', text: v.lastError } ) : null,
			] ),
			v.exhausted ? h( 'span', { class: 'tag err', text: 'اعتبار تمام' } ) : null,
			h( 'span', { class: `tag ${ v.circuit === 'closed' ? 'ok' : 'err' }`, text: v.circuit === 'closed' ? 'سالم' : v.circuit === 'open' ? 'مدار باز' : 'نیمه‌باز' } ),
			v.circuit !== 'closed' || v.exhausted ? h( 'button', {
				class: 'btn outline', text: 'بازکردن دوباره',
				onClick: async () => { await action( { action: 'reset-breaker', key } ); await again( page ); },
			} ) : null,
		] ) );
	}
	box.appendChild( list );
	box.appendChild( row( h( 'button', {
		class: 'btn outline', text: 'ریست کل سلامت هاب', title: 'همهٔ مدارشکن‌ها، خطاها و آمارها پاک می‌شود.',
		onClick: async () => {
			if ( ! ( await confirmDialog( 'کل سلامت هاب ریست شود؟ همهٔ خطاها، مدارشکن‌ها و آمار مسیرها پاک می‌شود.' ) ) ) { return; }
			await action( { action: 'reset-health' } );
			toast( 'سلامت هاب ریست شد.' );
			await again( page );
		},
	} ) ) );

	// ——— سقف هزینه
	const daily = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.daily ?? '' } );
	const perAdmin = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.perAdmin ?? '' } );
	const perTask = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.perTask ?? '' } );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'سقف هزینه' } ),
		h( 'p', { class: 'note', text: 'سقف خالی یعنی بی‌سقف. عبور از سقف، درخواست را رد می‌کند — نه اینکه فقط هشدار بدهد.' } ),
		row( field( 'سقف روزانهٔ کل ($)', daily ), field( 'سقف هر مدیر ($)', perAdmin ), field( 'سقف هر کار ($)', perTask ) ),
		h( 'div', { class: 'modal-actions' }, [ h( 'span', { class: 'grow' } ), h( 'button', {
			class: 'btn solid', text: 'ذخیره',
			onClick: async () => {
				await action( { action: 'update', patch: { budget: {
					daily: daily.value === '' ? null : Number( daily.value ),
					perAdmin: perAdmin.value === '' ? null : Number( perAdmin.value ),
					perTask: perTask.value === '' ? null : Number( perTask.value ),
				} } } );
				toast( 'ذخیره شد.' );
				await again( page );
			},
		} ) ] ),
	] ) );

	// ——— عیب‌یاب
	const dEnabled = h( 'input', { type: 'checkbox', checked: hub.diagnoser?.enabled !== false } );
	const dConn = h( 'select', { class: 'field' } );
	dConn.appendChild( h( 'option', { value: '', text: '— بدون مدل عیب‌یاب (فقط پله‌های یک و دو) —' } ) );
	for ( const c of Object.values( hub.connections || {} ) ) { dConn.appendChild( h( 'option', { value: c.id, text: c.label } ) ); }
	dConn.value = hub.diagnoser?.connectionId || '';
	const dModel = h( 'input', { class: 'field', dir: 'ltr', value: hub.diagnoser?.model || '', placeholder: 'gpt-4.1-mini' } );
	const dMin = h( 'input', { class: 'field', type: 'number', min: 1, value: hub.diagnoser?.minFailures ?? 2 } );
	const dPerHour = h( 'input', { class: 'field', type: 'number', min: 1, value: hub.diagnoser?.perSignaturePerHour ?? 1 } );
	const dBudget = h( 'input', { class: 'field', type: 'number', min: 0, value: hub.diagnoser?.dailyBudget ?? '' } );
	const dNet = h( 'input', { type: 'checkbox', checked: Boolean( hub.diagnoser?.internet ) } );
	const dPromote = h( 'input', { type: 'checkbox', checked: Boolean( hub.diagnoser?.autoPromote ) } );
	box.appendChild( section( 'عیب‌یاب هاب', 'جدا از هاب تنظیم می‌شود — چیزی که قرار است هاب را تعمیر کند نباید از داخل خود هاب مسیر بگیرد.' ) );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'label', { class: 'check' }, [ dEnabled, h( 'span', { text: 'عیب‌یاب روشن باشد' } ) ] ),
		field( 'اتصال عیب‌یاب', dConn ),
		field( 'مدل عیب‌یاب', dModel, 'یک مدل کوچک و ارزان کافی است؛ کارش خواندن متن خطا و پیشنهاد یک وصلهٔ ساختاریافته است.' ),
		row( field( 'حداقل شکست هم‌امضا', dMin ), field( 'سقف تماس هر امضا در ساعت', dPerHour ), field( 'سقف تماس روزانه', dBudget ) ),
		h( 'label', { class: 'check' }, [ dNet, h( 'span', { text: 'اجازهٔ جستجوی اینترنتی — فقط متن خطای پاک‌سازی‌شده بیرون می‌رود' } ) ] ),
		h( 'label', { class: 'check' }, [ dPromote, h( 'span', { text: 'وصله‌های موفق بدون تأیید من ماندگار شوند' } ) ] ),
		h( 'p', { class: 'note', text: `امروز ${ diag.spentToday || 0 } تماس عیب‌یابی · ${ diag.hasModel ? 'مدل تنظیم شده' : 'بدون مدل' }` } ),
		h( 'div', { class: 'modal-actions' }, [ h( 'span', { class: 'grow' } ), h( 'button', {
			class: 'btn solid', text: 'ذخیره',
			onClick: async () => {
				await action( { action: 'update', patch: { diagnoser: {
					enabled: dEnabled.checked,
					connectionId: dConn.value,
					model: dModel.value.trim(),
					minFailures: Number( dMin.value ) || 2,
					perSignaturePerHour: Number( dPerHour.value ) || 1,
					dailyBudget: dBudget.value === '' ? null : Number( dBudget.value ),
					internet: dNet.checked,
					autoPromote: dPromote.checked,
				} } } );
				toast( 'ذخیره شد.' );
				await again( page );
			},
		} ) ] ),
	] ) );

	// ——— یادگیری
	box.appendChild( section( 'چه یاد گرفته', 'امتیاز هر مدل در هر دسته، از نتیجهٔ واقعی همین نصب — نه از یک جدول ثابت.' ) );
	const learnBox = el( 'div', 'card-list' );
	const learnRows = Object.entries( learning );
	if ( ! learnRows.length ) {
		learnBox.appendChild( emptyBox( 'هنوز چیزی یاد نگرفته — چند نوبت کار لازم است.' ) );
	}
	for ( const [ cat, items ] of learnRows ) {
		learnBox.appendChild( h( 'div', { class: 'item' }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: categories.find( ( c ) => c.id === cat )?.label || cat } ),
				h( 'p', { class: 'note', text: items.slice( 0, 4 ).map( ( i ) => `${ hub.models?.[ i.modelKey ]?.label || i.modelKey }: ${ i.score } (${ i.n } نوبت)` ).join( ' · ' ) } ),
			] ),
		] ) );
	}
	box.appendChild( learnBox );

	// ——— دفتر راه‌حل‌ها
	box.appendChild( section( 'دفتر راه‌حل‌ها', 'هرچه ویرا یاد گرفته، با تاریخ و شمار موفقیت. هر ردیف با یک دکمه پاک می‌شود.' ) );
	const ledgerBox = el( 'div', 'card-list' );
	if ( ! ledger.length ) {
		ledgerBox.appendChild( emptyBox( 'دفتر خالی است — یعنی هنوز خطایی نبوده که راه‌حلش آزموده شده باشد.' ) );
	}
	for ( const e of ledger ) {
		ledgerBox.appendChild( h( 'div', { class: 'item' }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: e.why || 'وصلهٔ ثبت‌شده' } ),
				h( 'p', { class: 'mono note', text: e.signature } ),
				h( 'p', { class: 'mono note', text: ( e.patches || [] ).map( ( p ) => p.op ).join( ' + ' ) } ),
				h( 'p', { class: 'note', text: `از ${ String( e.discovered ).slice( 0, 10 ) } · ${ e.ok } بار جواب داد · منبع: ${ e.origin === 'model' ? 'مدل' : 'قاعده' }` } ),
			] ),
			h( 'span', { class: `tag ${ e.state === 'permanent' ? 'ok' : '' }`, text: e.state === 'permanent' ? 'دائمی' : 'موقت' } ),
			e.state !== 'permanent' ? h( 'button', {
				class: 'btn outline', title: 'وصله روی خود اتصال می‌نشیند و دفعهٔ بعد پیش از اولین تلاش اعمال می‌شود.', text: 'ماندگار کن',
				onClick: async () => { await action( { action: 'promote-patch', signature: e.signature } ); await again( page ); },
			} ) : null,
			h( 'button', {
				class: 'btn quiet danger', text: 'فراموش کن',
				onClick: async () => { await action( { action: 'forget-patch', signature: e.signature } ); await again( page ); },
			} ),
		] ) );
	}
	box.appendChild( ledgerBox );

	// ——— دفتر رویداد عیب‌یاب
	if ( ( diag.journal || [] ).length ) {
		box.appendChild( section( 'آخرین کارهای عیب‌یاب', '' ) );
		box.appendChild( h( 'div', { class: 'card-list compact' }, diag.journal.map( ( j ) =>
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'p', { class: 'mono note', text: `${ String( j.at ).slice( 11, 19 ) } · ${ j.step }` } ),
					j.why ? h( 'p', { class: 'note', text: j.why } ) : null,
				] ),
			] ) )
		) );
	}

	// ——— کش و اندپوینت
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'کش و اندپوینت' } ),
		h( 'p', { class: 'note', text: `${ snap?.cache?.size || 0 } پاسخ در کش · ${ snap?.cache?.hits || 0 } اصابت · ${ snap?.cache?.misses || 0 } خطا` } ),
		h( 'p', { class: 'note', text: 'اندپوینت سازگار با OpenAI روی لوکال‌هاست فعال است — بقیهٔ برنامه‌ها می‌توانند از همان یک جا کلید و هزینه بگیرند.' } ),
		row( h( 'button', { class: 'btn outline', text: 'خالی کردن کش', onClick: async () => { await action( { action: 'clear-cache' } ); toast( 'کش خالی شد.' ); await again( page ); } } ) ),
	] ) );
}
