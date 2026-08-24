/**
 * صفحهٔ «لاگ‌ها» در تنظیمات — درخواست کارفرما (۱۴۰۵/۰۵/۲۹): «بخشی که بشود خطاها را
 * کامل بررسی کرد.» سطح/کانال/جستجو، بازکردن زمینهٔ JSON هر ردیف، خروجی و پاک‌کردن.
 */

import { el, h, toast } from './lib/dom.js';
import { api, post } from './lib/api.js';

export async function renderLogsSettings( box ) {
	box.replaceChildren( el( 'div', 'loading', 'در حال خواندن لاگ‌ها…' ) );
	const load = async ( q = {} ) => api( `/api/logs?${ new URLSearchParams( q ).toString() }` );

	const level = h( 'select', { class: 'field' } );
	for ( const [ v, label ] of [ [ 'all', 'همهٔ سطح‌ها' ], [ 'error', 'فقط خطا' ], [ 'warn', 'هشدار و خطا' ] ] ) {
		level.appendChild( h( 'option', { value: v, text: label } ) );
	}
	level.value = 'all';
	const channel = h( 'select', { class: 'field' } );
	channel.appendChild( h( 'option', { value: 'all', text: 'همهٔ کانال‌ها' } ) );
	const search = h( 'input', { class: 'field', placeholder: 'جستجو در متن لاگ…' } );

	const list = el( 'div', 'card-list compact' );
	const draw = async () => {
		list.replaceChildren( el( 'div', 'loading', '…' ) );
		const q = { q: search.value.trim() };
		if ( level.value === 'error' ) { q.level = 'error'; }
		if ( level.value === 'warn' ) { q.level = 'warn'; }
		if ( channel.value !== 'all' ) { q.channel = channel.value; }
		const out = await load( q );
		channel.replaceChildren( h( 'option', { value: 'all', text: 'همهٔ کانال‌ها' } ) );
		for ( const c of out.channels || [] ) {
			channel.appendChild( h( 'option', { value: c, text: c } ) );
		}
		channel.value = q.channel || 'all';
		list.replaceChildren();
		const entries = out.entries || [];
		if ( ! entries.length ) {
			list.appendChild( el( 'div', 'empty', 'لاگی با این فیلتر نیست.' ) );
		}
		for ( const e of entries ) {
			const detail = el( 'pre', 'mono log-detail' );
			detail.hidden = true;
			detail.textContent = e.data ? JSON.stringify( e.data, null, 2 ) : '—';
			list.appendChild( h( 'div', { class: `item log-row lv-${ e.level }` }, [
				h( 'span', { class: `tag ${ e.level === 'error' ? 'err' : e.level === 'warn' ? '' : 'ok' }`, text: e.level } ),
				h( 'div', { class: 'item-main' }, [
					h( 'p', { class: 'mono note', text: `${ String( e.at ).slice( 0, 19 ).replace( 'T', ' ' ) } · ${ e.channel }` } ),
					h( 'p', { text: e.message } ),
				] ),
				h( 'button', {
					class: 'btn outline sm', text: 'زمینه',
					onClick: () => { detail.hidden = ! detail.hidden; },
				} ),
				detail,
			] ) );
		}
	};
	level.onchange = draw;
	channel.onchange = draw;
	const searchBtn = h( 'button', { class: 'btn solid', text: 'جستجو', onClick: () => draw() } );
	const refreshBtn = h( 'button', { class: 'btn outline', text: 'بازآوری', onClick: () => draw() } );
	const exportBtn = h( 'button', {
		class: 'btn outline', text: 'خروجی JSON',
		onClick: async () => {
			const out = await load( { limit: 300 } );
			const blob = JSON.stringify( out.entries, null, 2 );
			const a = h( 'a', { href: URL.createObjectURL( new Blob( [ blob ], { type: 'application/json' } ) ), download: 'vira-logs.json' } );
			document.body.appendChild( a );
			a.click();
			a.remove();
			toast( 'خروجی گرفته شد.' );
		},
	} );
	const clearBtn = h( 'button', {
		class: 'btn quiet danger', text: 'پاک‌کردن',
		onClick: async () => { await post( '/api/logs', { action: 'clear' } ); toast( 'لاگ‌ها پاک شد.' ); draw(); },
	} );

	box.replaceChildren();
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'لاگ‌های ویرا' } ),
		h( 'p', { class: 'note', text: 'همهٔ رخدادها و خطاها با کانال و زمان. «زمینه» جزئیات JSON هر ردیف را باز می‌کند. نسخهٔ کامل روی دیسک: logs/vira.log در پوشهٔ خانگی ویرا.' } ),
		h( 'div', { class: 'row wrap' }, [
			h( 'div', { class: 'field' }, [ h( 'b', { text: 'سطح' } ), level ] ),
			h( 'div', { class: 'field' }, [ h( 'b', { text: 'کانال' } ), channel ] ),
			h( 'div', { class: 'field grow' }, [ h( 'b', { text: 'جستجو' } ), search ] ),
		] ),
		h( 'div', { class: 'modal-actions' }, [ refreshBtn, exportBtn, clearBtn, h( 'span', { class: 'grow' } ), searchBtn ] ),
		list,
	] ) );
	await draw();
}
