/**
 * پنجرهٔ تنظیمات — همان جایی که در Claude Code به آن Customize می‌گویند.
 *
 * قاعده: هر چیزی که تا دیروز فقط با ویرایش دستی JSON ممکن بود، اینجا یک فرم دارد —
 * پرووایدرها، کانکتورهای MCP، اسکیل‌ها، پلاگین‌ها، زیرعامل‌ها، دستورها، هوک‌ها، مجوزها،
 * حافظهٔ پروژه، مصرف و هزینه، و تشخیص خرابی.
 */

import { $, el, h, toast, timeAgo, confirmDialog } from './lib/dom.js';
import { api, post, refreshState, getState } from './lib/api.js';
import { iconSvg } from './lib/icons.js';
import { renderProxySettings } from './proxy.js';
import { renderLogsSettings } from './logs.js';

/**
 * دو گروه، دقیقاً مثل ناوبری داخل مودال تنظیمات Claude: «Settings» و «Customize».
 * نوزده بخش بدون گروه‌بندی، همان دیوار متنی بود که کارفرما در تصویر دید.
 */
const GROUPS = [
	{
		// از ۰.۹.۴ شش تبِ هاب به یک صفحهٔ تمام‌قد رفت (DESIGN-PROVIDER-UI). اینجا فقط
		// لینک می‌مانَد + پروفایل تک‌نفره که تنظیمات سادهٔ همان حالت قدیمی است.
		label: 'پرووایدر و مدل',
		items: [
			{ id: 'hub-open', label: 'پرووایدرها و هاب…', ico: 'hub' },
			{ id: 'provider', label: 'پروفایل تک‌نفره', ico: 'profile' },
		],
	},
	{
		label: 'تنظیمات',
		items: [
			{ id: 'permissions', label: 'مجوزها', ico: 'permissions' },
			{ id: 'sandbox', label: 'سندباکس', ico: 'sandbox' },
			{ id: 'usage', label: 'مصرف و هزینه', ico: 'usage' },
			{ id: 'status', label: 'وضعیت و تشخیص', ico: 'health' },
			{ id: 'proxy', label: 'پراکسی', ico: 'plug-alt' },
			{ id: 'logs', label: 'لاگ‌ها', ico: 'list' },
			{ id: 'appearance', label: 'ظاهر', ico: 'appearance' },
		],
	},
	{
		label: 'سفارشی‌سازی',
		items: [
			{ id: 'skills', label: 'اسکیل‌ها', ico: 'skills' },
			{ id: 'connectors', label: 'کانکتورها', ico: 'connectors' },
			{ id: 'plugins', label: 'پلاگین‌ها', ico: 'plugins' },
			{ id: 'agents', label: 'زیرعامل‌ها', ico: 'subagents' },
			{ id: 'commands', label: 'دستورها', ico: 'commands' },
			{ id: 'hooks', label: 'هوک‌ها', ico: 'hooks' },
			{ id: 'memory', label: 'حافظهٔ پروژه', ico: 'memory' },
			{ id: 'tools', label: 'ابزارها', ico: 'tools' },
		],
	},
];

const TABS = GROUPS.flatMap( ( g ) => g.items );

let currentTab = 'hub';
let navFilter = '';

export const SETTINGS_TABS = TABS;
export const SETTINGS_GROUPS = GROUPS;

/**
 * تنظیمات یک **مودال بزرگ** است.
 *
 * تاریخچه‌اش ارزش نوشتن دارد: اول دیالوگ‌های ریز بود و کارفرما درست شکایت کرد. بعد
 * صفحهٔ تمام‌قد شد. بعد تصاویر واقعی Claude رسید و معلوم شد آنجا یک مودالِ بزرگ است با
 * ناوبری دوگروهی و یک کادر جستجو. شکایت اصلی از **کوچکی** آن دیالوگ‌ها بود، نه از
 * مودال‌بودنشان.
 *
 * @param {string} [tab]
 */
export async function openSettingsModal( tab ) {
	currentTab = tab && TABS.some( ( t ) => t.id === tab ) ? tab : currentTab;
	// جستجوی دفعهٔ قبل نباید بماند؛ وگرنه کاربر تنظیمات را باز می‌کند و نیمی از فهرست
	// غیب است بی‌آنکه بداند چرا.
	navFilter = '';

	const dlg = $( '#settings' );
	if ( ! dlg.open ) {
		dlg.showModal();
	}
	paintSettingsNav();
	await paintSettingsBody();
}

function paintSettingsNav() {
	const nav = $( '#set-nav' );
	nav.replaceChildren();

	const search = h( 'input', {
		class: 'set-search',
		placeholder: 'جستجو…',
		value: navFilter,
		onInput: ( e ) => {
			navFilter = e.target.value;
			paintSettingsNav();
		},
	} );
	nav.appendChild( search );

	const q = navFilter.trim().toLowerCase();
	for ( const group of GROUPS ) {
		const items = group.items.filter( ( t ) => ! q || t.label.toLowerCase().includes( q ) );
		if ( ! items.length ) {
			continue;
		}
		nav.appendChild( h( 'div', { class: 'set-group', text: group.label } ) );
		for ( const t of items ) {
			if ( t.id === 'hub-open' ) {
				nav.appendChild(
					h( 'button', {
						class: 'btn quiet row set-item',
						onClick: () => openHubPage(),
					}, [ h( 'span', { class: 'si-ico', html: iconSvg( t.ico, 16 ) } ), h( 'span', { text: t.label } ) ] )
				);
				continue;
			}
			nav.appendChild(
				h( 'button', {
					class: `btn quiet row set-item ${ t.id === currentTab ? 'active' : '' }`,
					dataset: { tab: t.id },
					onClick: async () => {
						currentTab = t.id;
						paintSettingsNav();
						await paintSettingsBody();
					},
				}, [ h( 'span', { class: 'si-ico', html: iconSvg( t.ico, 16 ) } ), h( 'span', { text: t.label } ) ] )
			);
		}
	}
}

/**
 * دکمه‌های سربرگ هر تب.
 *
 * در تصاویر، کنار عنوانِ «Skills» دکمه‌های «Browse» و «Add ⌄» هستند و کنار «Connectors»
 * فقط «Add ⌄». پس سربرگ ثابت نیست؛ به تب بستگی دارد.
 */
const HEAD_ACTIONS = {
	skills: [ [ 'مرور', 'skills-browse' ], [ 'افزودن', 'skills-add' ] ],
	connectors: [ [ 'افزودن', 'connectors-add' ] ],
	plugins: [ [ 'مرور', 'plugins-browse' ], [ 'افزودن', 'plugins-add' ] ],
	agents: [ [ 'افزودن', 'agents-add' ] ],
	commands: [ [ 'افزودن', 'commands-add' ] ],
};

function paintHeadActions() {
	const box = $( '#set-head-actions' );
	box.replaceChildren(
		...( HEAD_ACTIONS[ currentTab ] || [] ).map( ( [ label, action ] ) =>
			h( 'button', {
				class: 'btn outline',
				text: label,
				onClick: () => document.dispatchEvent( new CustomEvent( 'vira:set-action', { detail: action } ) ),
			} )
		)
	);
}

async function paintSettingsBody() {
	const body = $( '#set-body' );
	$( '#set-title' ).textContent = TABS.find( ( t ) => t.id === currentTab )?.label || 'تنظیمات';
	paintHeadActions();
	body.replaceChildren( el( 'div', 'loading', 'در حال بارگذاری…' ) );
	await refreshState();
	body.replaceChildren();
	await RENDER[ currentTab ]( body );
	body.scrollTop = 0;
}

/**
 * میان‌بر: از هرجای برنامه، تنظیمات را روی یک تب باز کن.
 * شناسه‌های hub* مال شش‌تبِ قدیمی بودند؛ حالا همه به صفحهٔ تمام‌قد می‌روند.
 */
export function openSettings( tab ) {
	if ( String( tab || '' ).startsWith( 'hub' ) ) {
		openHubPage();
		return;
	}
	document.dispatchEvent( new CustomEvent( 'vira:settings', { detail: tab } ) );
}

/** صفحهٔ تمام‌قد «پرووایدرها و هاب» — مودال را می‌بندد و نما را عوض می‌کند. */
export function openHubPage() {
	const dlg = document.querySelector( '#settings' );
	if ( dlg?.open ) {
		dlg.close();
	}
	document.dispatchEvent( new CustomEvent( 'vira:view', { detail: 'hub' } ) );
}

export function initSettings() {
	const dlg = $( '#settings' );
	$( '#set-close' ).onclick = () => dlg.close();
	// کلیک روی پس‌زمینهٔ مودال، مثل Claude، می‌بنددش.
	dlg.addEventListener( 'click', ( e ) => {
		if ( e.target === dlg ) {
			dlg.close();
		}
	} );
}

/** یک ردیف تنظیم: برچسب و توضیح در ابتدا، کنترل در انتها — چیدمان Claude. */
export function setRow( label, control, desc, opts = {} ) {
	return h( 'div', { class: `set-row ${ opts.stack ? 'stack' : '' }` }, [
		h( 'div', { class: 'set-row-label' }, [
			h( 'b', { text: label } ),
			desc ? h( 'p', { class: 'set-row-desc', text: desc } ) : null,
		] ),
		h( 'div', { class: 'set-row-control' }, Array.isArray( control ) ? control : [ control ] ),
	] );
}

/** کلید سوییچ — Claude برای بولین‌ها چک‌باکس خام نشان نمی‌دهد. */
export function toggle( checked, onChange ) {
	const input = h( 'input', { type: 'checkbox', checked: Boolean( checked ), onChange: ( e ) => onChange?.( e.target.checked ) } );
	return h( 'label', { class: 'switch' }, [ input, h( 'i', {} ) ] );
}

function section( title, hint ) {

	return h( 'div', { class: 'sec-head' }, [ h( 'h3', { text: title } ), hint ? h( 'p', { class: 'note', text: hint } ) : null ] );
}

function field( label, control, hint ) {
	return h( 'label', { class: 'field-label' }, [ h( 'span', { text: label } ), control, hint ? h( 'small', { class: 'note', text: hint } ) : null ] );
}

function row( ...children ) {
	return h( 'div', { class: 'row' }, children );
}

function emptyBox( text ) {
	return h( 'div', { class: 'empty', text } );
}

// ═══════════════════════════════════════════════════════════ پرووایدر

async function renderProvider( box ) {
	const s = getState();
	const cfg = s.config;
	const profiles = Object.entries( cfg.profiles || {} );

	box.appendChild(
		section(
			'پروفایل تک‌نفره',
			'حالت سادهٔ قدیمی: یک پرووایدر، یک مدل. وقتی هاب روشن و آماده باشد این کنار گذاشته می‌شود و مسیریابی با هاب است.'
		)
	);
	if ( s.hub?.active ) {
		box.appendChild(
			h( 'div', { class: 'empty' }, [
				h( 'p', { text: 'الان هاب فرمان را در دست دارد؛ این پروفایل استفاده نمی‌شود.' } ),
				h( 'button', { class: 'btn outline', text: 'رفتن به هاب', onClick: () => openSettings( 'hub' ) } ),
			] )
		);
	}

	// فهرست پروفایل‌ها
	const list = el( 'div', 'card-list' );
	for ( const [ id, p ] of profiles ) {
		const active = id === cfg.activeProfile;
		const card = h( 'div', { class: `item ${ active ? 'active' : '' }` }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: p.label || id } ),
				h( 'p', { class: 'mono', text: `${ p.provider } · ${ p.model || 'بدون مدل' }${ p.apiKey ? ' · کلید ✓' : ' · بدون کلید' }` } ),
			] ),
			active ? h( 'span', { class: 'tag ok', text: 'فعال' } ) : null,
			! active
				? h( 'button', {
						class: 'btn outline',
						text: 'فعال کن',
						onClick: async () => {
							await post( '/api/profiles', { action: 'activate', id } );
							await openSettings( 'provider' );
							toast( 'پروفایل عوض شد.' );
						},
				  } )
				: null,
			h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => editProfile( id, p ) } ),
			profiles.length > 1
				? h( 'button', {
						class: 'btn quiet danger',
						text: 'حذف',
						onClick: async () => {
							if ( ! ( await confirmDialog( `پروفایل «${ p.label || id }» حذف شود؟`, { danger: true } ) ) ) {
								return;
							}
							await post( '/api/profiles', { action: 'remove', id } );
							await openSettings( 'provider' );
						},
				  } )
				: null,
		] );
		list.appendChild( card );
	}
	box.appendChild( list );

	box.appendChild(
		row(
			h( 'button', { class: 'btn solid', text: '+ پروفایل تازه', onClick: () => editProfile( `p${ Date.now().toString( 36 ) }`, null ) } )
		)
	);

	// فرم ویرایش
	const formHost = el( 'div', 'form-host' );
	box.appendChild( formHost );

	function editProfile( id, p ) {
		formHost.replaceChildren();
		const providers = s.providers || [];
		const current = p || { provider: 'openai', model: '', baseUrl: '', apiKey: '', label: 'پروفایل تازه' };

		const label = h( 'input', { class: 'field', value: current.label || '' } );
		const select = h( 'select', { class: 'field' } );
		for ( const pr of providers ) {
			select.appendChild( h( 'option', { value: pr.id, text: `${ pr.label } ${ pr.needsKey ? '' : '(بدون کلید)' }` } ) );
		}
		select.value = current.provider;

		const baseUrl = h( 'input', { class: 'field', dir: 'ltr', value: current.baseUrl || '', placeholder: 'https://…' } );
		const apiKey = h( 'input', { class: 'field', dir: 'ltr', type: 'password', placeholder: current.apiKey ? '••••••• (تغییر نده تا بماند)' : '' } );
		const model = h( 'input', { class: 'field', dir: 'ltr', value: current.model || '', list: 'model-list' } );
		const datalist = h( 'datalist', { id: 'model-list' } );
		const note = h( 'p', { class: 'note' } );
		const testNote = h( 'p', { class: 'note' } );

		const info = () => providers.find( ( x ) => x.id === select.value );
		const sync = () => {
			const i = info();
			note.textContent = i?.note || '';
			baseUrl.placeholder = i?.baseUrl || 'https://…';
			baseUrl.disabled = ! i?.editableBaseUrl;
			apiKey.disabled = ! i?.needsKey;
			if ( ! model.value && i?.defaultModel ) {
				model.value = i.defaultModel;
			}
		};
		select.onchange = sync;
		sync();

		const save = async ( activate = true ) => {
			const out = await post( '/api/profiles', {
				action: 'save',
				id,
				label: label.value.trim() || id,
				provider: select.value,
				baseUrl: baseUrl.value.trim(),
				apiKey: apiKey.value.trim(),
				model: model.value.trim(),
				activate,
			} );
			if ( out.error ) {
				toast( out.error, 'error' );
				return false;
			}
			return true;
		};

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: p ? `ویرایش «${ current.label || id }»` : 'پروفایل تازه' } ),
				field( 'نام پروفایل', label ),
				field( 'سرویس‌دهنده', select ),
				note,
				field( 'آدرس پایه', baseUrl, 'برای سرویس‌های سازگار با OpenAI/Anthropic (مثل OpenRouter، Ollama، LM Studio) اینجا را پر کن.' ),
				field( 'کلید API', apiKey, 'کلید در فایل تنظیمات محلی ذخیره می‌شود؛ به جایی فرستاده نمی‌شود.' ),
				field( 'مدل', row( model, h( 'button', {
					class: 'btn outline',
					text: 'گرفتن فهرست مدل‌ها',
					onClick: async () => {
						if ( ! ( await save() ) ) {
							return;
						}
						const out = await api( '/api/models' );
						if ( out.error ) {
							toast( `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, 'error' );
							return;
						}
						datalist.replaceChildren();
						for ( const m of out.models || [] ) {
							datalist.appendChild( h( 'option', { value: m } ) );
						}
						toast( `${ ( out.models || [] ).length } مدل پیدا شد — روی کادر مدل کلیک کن.` );
					},
				} ) ) ),
				datalist,
				h( 'div', { class: 'modal-actions' }, [
					h( 'button', {
						class: 'btn outline',
						text: 'تست اتصال',
						onClick: async () => {
							testNote.className = 'note';
							testNote.textContent = 'در حال آزمودن…';
							if ( ! ( await save() ) ) {
								return;
							}
							const out = await post( '/api/test-connection', { id } );
							testNote.className = `note ${ out.ok ? 'ok' : 'error' }`;
							testNote.textContent = out.ok ? out.message : `${ out.message }${ out.hint ? '\n' + out.hint : '' }`;
						},
					} ),
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => formHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							if ( await save() ) {
								toast( 'ذخیره شد.' );
								await openSettings( 'provider' );
							}
						},
					} ),
				] ),
				testNote,
			] )
		);
		formHost.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}
}

// ═══════════════════════════════════════════════════════ کانکتورها (MCP)

async function renderConnectors( box ) {
	const s = getState();

	box.appendChild(
		section(
			'کانکتورها (سرورهای MCP)',
			'هر کانکتور، ابزارهای یک سرویس بیرونی را داخل ویرا می‌آورد. دو نوع: اجرای فرمان محلی (stdio) یا آدرس اینترنتی (HTTP).'
		)
	);

	const statusOf = ( name ) => ( s.mcp || [] ).find( ( m ) => m.name === name );

	const list = el( 'div', 'card-list' );
	if ( ! ( s.connectors || [] ).length ) {
		list.appendChild( emptyBox( 'هنوز کانکتوری اضافه نکرده‌ای. با دکمهٔ زیر یکی بساز.' ) );
	}
	for ( const c of s.connectors || [] ) {
		const st = statusOf( c.name );
		const badge = c.disabled
			? h( 'span', { class: 'tag', text: 'خاموش' } )
			: st?.status === 'connected'
			? h( 'span', { class: 'tag ok', text: `وصل · ${ st.tools.length } ابزار` } )
			: h( 'span', { class: 'tag err', text: st?.error ? 'خطا' : 'وصل نشد' } );

		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { 'data-no-t': '', text: c.name } ),
					h( 'p', { class: 'mono', text: c.kind === 'http' ? c.config.url : `${ c.config.command } ${ ( c.config.args || [] ).join( ' ' ) }` } ),
					st?.error ? h( 'p', { class: 'note error', text: st.error } ) : null,
					st?.tools?.length ? h( 'p', { class: 'note', text: `ابزارها: ${ st.tools.join( '، ' ) }` } ) : null,
				] ),
				h( 'span', { class: 'tag', text: c.scope === 'project' ? 'پروژه' : 'سراسری' } ),
				badge,
				h( 'button', {
					class: 'btn outline',
					text: c.disabled ? 'روشن' : 'خاموش',
					onClick: async () => {
						await post( '/api/connectors', { action: 'toggle', name: c.name, scope: c.scope, enabled: c.disabled } );
						await openSettings( 'connectors' );
					},
				} ),
				h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => form( c ) } ),
				h( 'button', {
					class: 'btn quiet danger',
					text: 'حذف',
					onClick: async () => {
						if ( ! ( await confirmDialog( `کانکتور «${ c.name }» حذف شود؟`, { danger: true } ) ) ) {
							return;
						}
						const out = await post( '/api/connectors', { action: 'remove', name: c.name, scope: c.scope } );
						if ( out.error ) {
							toast( out.error, 'error' );
						}
						await openSettings( 'connectors' );
					},
				} ),
			] )
		);
	}
	box.appendChild( list );

	box.appendChild(
		row(
			h( 'button', { class: 'btn solid', text: '+ کانکتور تازه', onClick: () => form( null ) } ),
			h( 'button', { class: 'btn outline', text: 'نمونه: فایل‌سیستم', onClick: () => form( sample( 'files' ) ) } ),
			h( 'button', { class: 'btn outline', text: 'نمونه: گیت‌هاب', onClick: () => form( sample( 'github' ) ) } ),
			h( 'button', {
				class: 'btn outline',
				text: 'اتصال دوباره به همه',
				onClick: async () => {
					await post( '/api/reload', {} );
					await openSettings( 'connectors' );
					toast( 'دوباره وصل شد.' );
				},
			} )
		)
	);

	const formHost = el( 'div', 'form-host' );
	box.appendChild( formHost );

	function sample( kind ) {
		if ( kind === 'files' ) {
			return {
				name: 'files',
				scope: 'user',
				kind: 'stdio',
				config: { command: 'npx', args: [ '-y', '@modelcontextprotocol/server-filesystem', s.config.workspace ] },
			};
		}
		return {
			name: 'github',
			scope: 'user',
			kind: 'http',
			config: { url: 'https://api.githubcopilot.com/mcp/', headers: { Authorization: 'Bearer ' } },
		};
	}

	function form( c ) {
		formHost.replaceChildren();
		const editing = Boolean( c?.config );
		const cfg = c?.config || {};
		const kind = c?.kind || ( cfg.url ? 'http' : 'stdio' );

		const name = h( 'input', { class: 'field', dir: 'ltr', value: c?.name || '', placeholder: 'مثلاً files' } );
		const scope = h( 'select', { class: 'field' }, [
			h( 'option', { value: 'user', text: 'سراسری (همهٔ پروژه‌ها)' } ),
			h( 'option', { value: 'project', text: 'فقط این پروژه' } ),
		] );
		scope.value = c?.scope || 'user';

		const kindSel = h( 'select', { class: 'field' }, [
			h( 'option', { value: 'stdio', text: 'اجرای فرمان محلی (stdio)' } ),
			h( 'option', { value: 'http', text: 'آدرس اینترنتی (HTTP)' } ),
		] );
		kindSel.value = kind;

		const command = h( 'input', { class: 'field', dir: 'ltr', value: cfg.command || '', placeholder: 'npx' } );
		const args = h( 'input', { class: 'field', dir: 'ltr', value: ( cfg.args || [] ).join( ' ' ), placeholder: '-y @modelcontextprotocol/server-filesystem /path' } );
		const url = h( 'input', { class: 'field', dir: 'ltr', value: cfg.url || '', placeholder: 'https://example.com/mcp' } );
		const envBox = kvEditor( cfg.env || {}, 'متغیر محیطی' );
		const headBox = kvEditor( cfg.headers || {}, 'هدر' );
		const note = h( 'p', { class: 'note' } );

		const stdioRow = h( 'div', {}, [ field( 'فرمان', command ), field( 'پارامترها', args, 'با فاصله جدا کن.' ), field( 'متغیرهای محیطی', envBox.node ) ] );
		const httpRow = h( 'div', {}, [ field( 'آدرس', url ), field( 'هدرها', headBox.node, 'مثلاً Authorization: Bearer …' ) ] );

		const sync = () => {
			stdioRow.hidden = kindSel.value !== 'stdio';
			httpRow.hidden = kindSel.value !== 'http';
		};
		kindSel.onchange = sync;
		sync();

		const payload = () => ( {
			name: name.value.trim(),
			scope: scope.value,
			previousScope: c?.scope,
			kind: kindSel.value,
			command: command.value.trim(),
			args: args.value.trim(),
			url: url.value.trim(),
			env: envBox.value(),
			headers: headBox.value(),
		} );

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: editing ? `ویرایش «${ c.name }»` : 'کانکتور تازه' } ),
				field( 'نام', name, 'ابزارهای این سرور با پیشوند mcp__<نام>__ ظاهر می‌شوند.' ),
				field( 'محدوده', scope ),
				field( 'نوع اتصال', kindSel ),
				stdioRow,
				httpRow,
				h( 'div', { class: 'modal-actions' }, [
					h( 'button', {
						class: 'btn outline',
						text: 'تست اتصال',
						onClick: async () => {
							note.className = 'note';
							note.textContent = 'در حال آزمودن…';
							const out = await post( '/api/connectors', { action: 'test', ...payload() } );
							note.className = `note ${ out.ok ? 'ok' : 'error' }`;
							note.textContent = out.message || out.error || '';
						},
					} ),
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => formHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							const out = await post( '/api/connectors', { action: 'save', ...payload() } );
							if ( out.error ) {
								note.className = 'note error';
								note.textContent = out.error;
								return;
							}
							toast( 'کانکتور ذخیره شد.' );
							await openSettings( 'connectors' );
						},
					} ),
				] ),
				note,
			] )
		);
		formHost.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}
}

/** ویرایشگر کلید/مقدار — برای env و headers. */
function kvEditor( initial, label ) {
	const host = el( 'div', 'kv' );

	const addRow = ( k = '', v = '' ) => {
		const key = h( 'input', { class: 'field small', dir: 'ltr', value: k, placeholder: 'کلید' } );
		const val = h( 'input', { class: 'field small', dir: 'ltr', value: v, placeholder: 'مقدار' } );
		const del = h( 'button', { class: 'btn quiet', html: iconSvg( 'times', 13 ), title: 'حذف', onClick: () => line.remove() } );
		const line = h( 'div', { class: 'kv-row' }, [ key, val, del ] );
		host.insertBefore( line, adder );
		return line;
	};

	const adder = h( 'button', { class: 'btn outline', text: `+ ${ label }`, onClick: () => addRow() } );
	host.appendChild( adder );
	for ( const [ k, v ] of Object.entries( initial || {} ) ) {
		addRow( k, String( v ) );
	}

	return {
		node: host,
		value() {
			/** @type {Record<string,string>} */
			const out = {};
			for ( const line of host.querySelectorAll( '.kv-row' ) ) {
				const [ k, v ] = line.querySelectorAll( 'input' );
				if ( k.value.trim() ) {
					out[ k.value.trim() ] = v.value;
				}
			}
			return out;
		},
	};
}

// ═══════════════════════════════════════════════════════════ اسکیل‌ها

async function renderSkills( box ) {
	const s = getState();
	box.appendChild(
		section( 'اسکیل‌ها', 'اسکیل آماده را از یک مخزن گیت‌هاب یا پوشهٔ محلی نصب کن. فرمت استاندارد SKILL.md پشتیبانی می‌شود.' )
	);

	const source = h( 'input', { class: 'field', dir: 'ltr', placeholder: 'owner/repo یا /path/to/skill' } );
	const note = h( 'p', { class: 'note' } );
	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			field( 'نصب اسکیل', row( source, h( 'button', {
				class: 'btn solid',
				text: 'نصب',
				onClick: async () => {
					if ( ! source.value.trim() ) {
						return;
					}
					note.className = 'note';
					note.textContent = 'در حال نصب…';
					const out = await post( '/api/skills', { action: 'install', source: source.value.trim() } );
					if ( out.error ) {
						note.className = 'note error';
						note.textContent = out.error;
						return;
					}
					toast( `نصب شد: ${ ( out.installed || [] ).join( '، ' ) }` );
					await openSettings( 'skills' );
				},
			} ) ) ),
			note,
		] )
	);

	const list = el( 'div', 'card-list' );
	if ( ! ( s.skills || [] ).length ) {
		list.appendChild( emptyBox( 'هیچ اسکیلی نصب نیست.' ) );
	}
	for ( const sk of s.skills || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main', 'data-no-t': '' }, [ h( 'b', { text: sk.name } ), h( 'p', { text: sk.description || '' } ) ] ),
				h( 'span', { class: 'tag', text: sk.source } ),
				sk.source === 'user'
					? h( 'button', {
							class: 'btn quiet danger',
							text: 'حذف',
							onClick: async () => {
								if ( ! ( await confirmDialog( `اسکیل «${ sk.name }» حذف شود؟`, { danger: true } ) ) ) {
									return;
								}
								const out = await post( '/api/skills', { action: 'remove', name: sk.name } );
								if ( out.error ) {
									toast( out.error, 'error' );
								}
								await openSettings( 'skills' );
							},
					  } )
					: null,
			] )
		);
	}
	box.appendChild( list );
}

// ═══════════════════════════════════════════════════════════ پلاگین‌ها

async function renderPlugins( box ) {
	const s = getState();
	box.appendChild( section( 'پلاگین‌ها', 'یک پلاگین می‌تواند اسکیل، دستور، کانکتور MCP و هوک با خودش بیاورد.' ) );

	const source = h( 'input', { class: 'field', dir: 'ltr', placeholder: 'owner/repo یا مسیر محلی' } );
	const market = h( 'input', { class: 'field', dir: 'ltr', placeholder: 'مارکت‌پلیس: owner/repo' } );
	const note = h( 'p', { class: 'note' } );
	const marketList = el( 'div', 'card-list' );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			field( 'نصب پلاگین', row( source, h( 'button', {
				class: 'btn solid',
				text: 'نصب',
				onClick: async () => {
					note.className = 'note';
					note.textContent = 'در حال نصب…';
					const out = await post( '/api/plugins', { action: 'install', source: source.value.trim() } );
					if ( out.error ) {
						note.className = 'note error';
						note.textContent = out.error;
						return;
					}
					// پاسخ ناقص نباید رابط را بترکاند؛ اسم اگر نبود، خود ورودی را می‌گوییم.
					toast( `«${ out.plugin?.name || source.value.trim() }» نصب شد.` );
					await openSettings( 'plugins' );
				},
			} ) ) ),
			field( 'مرور مارکت‌پلیس', row( market, h( 'button', {
				class: 'btn outline',
				text: 'باز کن',
				onClick: async () => {
					marketList.replaceChildren( el( 'div', 'loading', 'در حال گرفتن فهرست…' ) );
					const out = await post( '/api/plugins', { action: 'marketplace', source: market.value.trim() } );
					marketList.replaceChildren();
					if ( out.error ) {
						marketList.appendChild( h( 'p', { class: 'note error', text: out.error } ) );
						return;
					}
					for ( const p of out.marketplace?.plugins || [] ) {
						marketList.appendChild(
							h( 'div', { class: 'item' }, [
								h( 'div', { class: 'item-main', 'data-no-t': '' }, [ h( 'b', { text: p.name } ), h( 'p', { text: p.description || '' } ) ] ),
								h( 'button', {
									class: 'btn solid',
									text: 'نصب',
									onClick: async () => {
										const r = await post( '/api/plugins', { action: 'install', source: p.source, name: p.name } );
										toast( r.error || `«${ p.name }» نصب شد.`, r.error ? 'error' : '' );
										await openSettings( 'plugins' );
									},
								} ),
							] )
						);
					}
				},
			} ) ) ),
			note,
			marketList,
		] )
	);

	const list = el( 'div', 'card-list' );
	if ( ! ( s.plugins || [] ).length ) {
		list.appendChild( emptyBox( 'پلاگینی نصب نیست.' ) );
	}
	for ( const p of s.plugins || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { 'data-no-t': '', text: p.name } ),
					h( 'p', { text: `اسکیل: ${ p.has.skills } · دستور: ${ p.has.commands }${ p.has.mcp ? ' · MCP' : '' }${ p.has.hooks ? ' · هوک' : '' }` } ),
				] ),
				h( 'span', { class: `tag ${ p.enabled ? 'ok' : '' }`, text: p.enabled ? 'فعال' : 'خاموش' } ),
				h( 'button', {
					class: 'btn outline',
					text: p.enabled ? 'خاموش' : 'روشن',
					onClick: async () => {
						await post( '/api/plugins', { action: 'toggle', name: p.name, enabled: ! p.enabled } );
						await openSettings( 'plugins' );
					},
				} ),
				h( 'button', {
					class: 'btn quiet danger',
					text: 'حذف',
					onClick: async () => {
						if ( ! ( await confirmDialog( `پلاگین «${ p.name }» حذف شود؟`, { danger: true } ) ) ) {
							return;
						}
						await post( '/api/plugins', { action: 'remove', name: p.name } );
						await openSettings( 'plugins' );
					},
				} ),
			] )
		);
	}
	box.appendChild( list );
}

// ═══════════════════════════════════════════════════════════ زیرعامل‌ها

async function renderAgents( box ) {
	const s = getState();
	box.appendChild(
		section( 'زیرعامل‌ها', 'هر زیرعامل یک متخصص است با پرامپت، مدل و ابزارهای خودش. عامل اصلی با ابزار task صدایشان می‌زند.' )
	);

	const list = el( 'div', 'card-list' );
	if ( ! ( s.agents || [] ).length ) {
		list.appendChild( emptyBox( 'هنوز زیرعاملی تعریف نکرده‌ای.' ) );
	}
	for ( const a of s.agents || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { 'data-no-t': '', text: a.name } ),
					h( 'p', { 'data-no-t': '', text: a.description || '' } ),
					h( 'p', { class: 'note mono', text: `${ a.model || 'مدل پیش‌فرض' } · ${ a.tools?.length ? a.tools.join( '، ' ) : 'همهٔ ابزارها' }` } ),
				] ),
				h( 'span', { class: 'tag', text: a.source } ),
				h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => form( a ) } ),
				h( 'button', {
					class: 'btn quiet danger',
					text: 'حذف',
					onClick: async () => {
						if ( ! ( await confirmDialog( `زیرعامل «${ a.name }» حذف شود؟`, { danger: true } ) ) ) {
							return;
						}
						const out = await post( '/api/agents', { action: 'remove', name: a.name } );
						if ( out.error ) {
							toast( out.error, 'error' );
						}
						await openSettings( 'agents' );
					},
				} ),
			] )
		);
	}
	box.appendChild( list );
	box.appendChild( row( h( 'button', { class: 'btn solid', text: '+ زیرعامل تازه', onClick: () => form( null ) } ) ) );

	const formHost = el( 'div', 'form-host' );
	box.appendChild( formHost );

	function form( a ) {
		formHost.replaceChildren();
		const name = h( 'input', { class: 'field', dir: 'ltr', value: a?.name || '', placeholder: 'reviewer' } );
		const desc = h( 'input', { class: 'field', value: a?.description || '', placeholder: 'کد را مرور می‌کند و ایراد می‌گیرد' } );
		const model = h( 'input', { class: 'field', dir: 'ltr', value: a?.model || '', placeholder: 'خالی = مدل پیش‌فرض' } );
		const prompt = h( 'textarea', { class: 'field tall', text: a?.prompt || '' } );
		const scope = h( 'select', { class: 'field' }, [
			h( 'option', { value: 'user', text: 'سراسری' } ),
			h( 'option', { value: 'project', text: 'فقط این پروژه' } ),
		] );
		scope.value = a?.source === 'project' ? 'project' : 'user';

		const toolsBox = el( 'div', 'chips' );
		const chosen = new Set( a?.tools || [] );
		for ( const t of s.tools || [] ) {
			const chip = h( 'button', {
				class: `btn outline sm mono ${ chosen.has( t.name ) ? 'on' : '' }`,
				text: t.name,
				onClick: () => {
					if ( chosen.has( t.name ) ) {
						chosen.delete( t.name );
						chip.classList.remove( 'on' );
					} else {
						chosen.add( t.name );
						chip.classList.add( 'on' );
					}
				},
			} );
			toolsBox.appendChild( chip );
		}

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: a ? `ویرایش «${ a.name }»` : 'زیرعامل تازه' } ),
				field( 'نام (انگلیسی)', name ),
				field( 'توضیح', desc, 'همین متن به مدل نشان داده می‌شود تا بداند کِی صدایش بزند.' ),
				field( 'مدل', model ),
				field( 'محدوده', scope ),
				field( 'ابزارهای مجاز', toolsBox, 'هیچ‌کدام انتخاب نشود یعنی همهٔ ابزارها.' ),
				field( 'پرامپت سیستمی', prompt ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => formHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							const out = await post( '/api/agents', {
								action: 'save',
								name: name.value.trim(),
								description: desc.value.trim(),
								model: model.value.trim(),
								prompt: prompt.value,
								tools: [ ...chosen ],
								scope: scope.value,
							} );
							if ( out.error ) {
								toast( out.error, 'error' );
								return;
							}
							toast( 'ذخیره شد.' );
							await openSettings( 'agents' );
						},
					} ),
				] ),
			] )
		);
		formHost.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}
}

// ═══════════════════════════════════════════════════════════ دستورها

async function renderCommands( box ) {
	const s = getState();
	box.appendChild(
		section( 'دستورهای اسلش', 'دستور خودت را بساز: متن دستور همان پرامپتی است که فرستاده می‌شود. $ARGUMENTS و $1 و $2 جایگزین می‌شوند.' )
	);

	const list = el( 'div', 'card-list' );
	for ( const c of s.commands || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main', 'data-no-t': '' }, [ h( 'b', { class: 'mono', text: `/${ c.name }` } ), h( 'p', { text: c.description || '' } ) ] ),
				h( 'span', { class: 'tag', text: c.source } ),
				c.source !== 'builtin' ? h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => form( c ) } ) : null,
				c.source !== 'builtin'
					? h( 'button', {
							class: 'btn quiet danger',
							text: 'حذف',
							onClick: async () => {
								if ( ! ( await confirmDialog( `دستور /${ c.name } حذف شود؟`, { danger: true } ) ) ) {
									return;
								}
								const out = await post( '/api/commands', { action: 'remove', name: c.name } );
								if ( out.error ) {
									toast( out.error, 'error' );
								}
								await openSettings( 'commands' );
							},
					  } )
					: null,
			] )
		);
	}
	box.appendChild( list );
	box.appendChild( row( h( 'button', { class: 'btn solid', text: '+ دستور تازه', onClick: () => form( null ) } ) ) );

	const formHost = el( 'div', 'form-host' );
	box.appendChild( formHost );

	function form( c ) {
		formHost.replaceChildren();
		const name = h( 'input', { class: 'field', dir: 'ltr', value: c?.name || '', placeholder: 'review' } );
		const desc = h( 'input', { class: 'field', value: c?.description || '' } );
		const body = h( 'textarea', { class: 'field tall', text: c?.body || '' } );
		const scope = h( 'select', { class: 'field' }, [
			h( 'option', { value: 'user', text: 'سراسری' } ),
			h( 'option', { value: 'project', text: 'فقط این پروژه' } ),
		] );
		scope.value = c?.source === 'project' ? 'project' : 'user';

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: c ? `ویرایش /${ c.name }` : 'دستور تازه' } ),
				field( 'نام', name ),
				field( 'توضیح', desc ),
				field( 'محدوده', scope ),
				field( 'متن دستور (پرامپت)', body ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => formHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							const out = await post( '/api/commands', {
								action: 'save',
								name: name.value.trim(),
								description: desc.value.trim(),
								body: body.value,
								scope: scope.value,
							} );
							if ( out.error ) {
								toast( out.error, 'error' );
								return;
							}
							toast( 'ذخیره شد.' );
							await openSettings( 'commands' );
						},
					} ),
				] ),
			] )
		);
	}
}

// ═══════════════════════════════════════════════════════════════ هوک‌ها

async function renderHooks( box ) {
	box.appendChild(
		section(
			'هوک‌ها',
			'فرمان‌هایی که در لحظه‌های مشخص اجرا می‌شوند: PreToolUse، PostToolUse، UserPromptSubmit، SessionStart، SessionEnd، Stop. اگر PreToolUse با کد ۲ خارج شود، جلوی ابزار گرفته می‌شود.'
		)
	);

	const out = await api( '/api/hooks' );
	const editor = h( 'textarea', { class: 'field code tall', text: JSON.stringify( out.hooks || {}, null, 2 ) } );
	const note = h( 'p', { class: 'note' } );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			field( 'تعریف هوک‌ها (JSON)', editor ),
			h( 'pre', { class: 'sample mono', text: `{\n  "PreToolUse": [\n    { "matcher": "bash", "command": "echo $VIRA_TOOL >> ~/vira-audit.log" }\n  ]\n}` } ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						let parsed;
						try {
							parsed = JSON.parse( editor.value || '{}' );
						} catch ( e ) {
							note.className = 'note error';
							note.textContent = `JSON نامعتبر: ${ e.message }`;
							return;
						}
						const res = await post( '/api/hooks', { hooks: parsed } );
						note.className = `note ${ res.error ? 'error' : 'ok' }`;
						note.textContent = res.error || 'ذخیره شد.';
					},
				} ),
			] ),
			note,
		] )
	);
}

// ═══════════════════════════════════════════════════════════════ مجوزها

async function renderPermissions( box ) {
	const s = getState();
	const p = s.config.permissions || { mode: 'default', allow: [], ask: [], deny: [] };

	box.appendChild(
		section( 'مجوزها', 'قاعده می‌تواند نام ابزار باشد (مثل bash) یا پیشوندی (مثل bash:git) یا * برای همه.' )
	);

	const mode = h( 'select', { class: 'field' }, [
		h( 'option', { value: 'plan', text: 'پلن — فقط بررسی و خواندن' } ),
		h( 'option', { value: 'default', text: 'عادی — نوشتن و اجرا با تأیید' } ),
		h( 'option', { value: 'auto', text: 'خودکار — بدون تأیید (جز فهرست ممنوع)' } ),
	] );
	mode.value = p.mode;

	const allow = listEditor( p.allow || [], 'قاعدهٔ مجاز' );
	const ask = listEditor( p.ask || [], 'قاعدهٔ پرسشی' );
	const deny = listEditor( p.deny || [], 'قاعدهٔ ممنوع' );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			field( 'حالت کار', mode ),
			field( 'همیشه مجاز', allow.node ),
			field( 'همیشه بپرس', ask.node ),
			field( 'همیشه ممنوع', deny.node ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						const res = await post( '/api/permissions', {
							mode: mode.value,
							allow: allow.value(),
							ask: ask.value(),
							deny: deny.value(),
						} );
						toast( res.error || 'قواعد ذخیره شد.', res.error ? 'error' : '' );
						await refreshState();
					},
				} ),
			] ),
		] )
	);
}

function listEditor( initial, label ) {
	const host = el( 'div', 'kv' );
	const addRow = ( v = '' ) => {
		const input = h( 'input', { class: 'field small', dir: 'ltr', value: v, placeholder: 'bash:git' } );
		const del = h( 'button', { class: 'btn quiet', html: iconSvg( 'times', 13 ), title: 'حذف', onClick: () => line.remove() } );
		const line = h( 'div', { class: 'kv-row' }, [ input, del ] );
		host.insertBefore( line, adder );
	};
	const adder = h( 'button', { class: 'btn outline', text: `+ ${ label }`, onClick: () => addRow() } );
	host.appendChild( adder );
	for ( const v of initial ) {
		addRow( v );
	}
	return {
		node: host,
		value: () => [ ...host.querySelectorAll( 'input' ) ].map( ( i ) => i.value.trim() ).filter( Boolean ),
	};
}

// ═══════════════════════════════════════════════════════════ سندباکس

async function renderSandbox( box ) {
	const s = getState();
	const sb = s.sandbox || {};

	box.appendChild(
		section(
			'سندباکس اجرای فرمان',
			'وقتی روشن باشد، ابزار bash و شل‌های پس‌زمینه داخل یک کانتینر اجرا می‌شوند. خواندن و نوشتن فایل روی سیستم خودت می‌ماند (همین حالا هم به پوشهٔ کاری محدود است).'
		)
	);

	box.appendChild(
		h( 'div', { class: `banner ${ sb.enabled && ! sb.available ? 'danger' : '' }` }, [
			h( 'b', { text: sb.enabled ? ( sb.available ? 'روشن و آماده' : 'روشن ولی موتور کانتینر نیست' ) : 'خاموش' } ),
			h( 'span', { text: sb.message || '' } ),
		] )
	);

	const enabled = h( 'input', { type: 'checkbox', checked: sb.enabled } );
	const runtime = h( 'select', { class: 'field' }, [
		h( 'option', { value: 'auto', text: 'خودکار (اول docker، بعد podman)' } ),
		h( 'option', { value: 'docker', text: 'Docker' } ),
		h( 'option', { value: 'podman', text: 'Podman' } ),
	] );
	runtime.value = sb.runtime || 'auto';

	const image = h( 'input', { class: 'field', dir: 'ltr', value: sb.image || 'node:22-bookworm-slim' } );
	const network = h( 'select', { class: 'field' }, [
		h( 'option', { value: 'none', text: 'بسته — بدون اینترنت (امن‌ترین)' } ),
		h( 'option', { value: 'bridge', text: 'معمولی — اینترنت دارد' } ),
		h( 'option', { value: 'host', text: 'شبکهٔ میزبان (ناامن؛ فقط اگر می‌دانی چرا)' } ),
	] );
	network.value = sb.network || 'none';

	const memory = h( 'input', { class: 'field', dir: 'ltr', value: sb.memory || '2g' } );
	const cpus = h( 'input', { class: 'field', dir: 'ltr', value: String( sb.cpus ?? '2' ) } );
	const readOnly = h( 'input', { type: 'checkbox', checked: Boolean( sb.readOnlyRoot ) } );
	const fallback = h( 'input', { type: 'checkbox', checked: Boolean( sb.allowHostFallback ) } );
	const mounts = listEditor( sb.mounts || [], 'مسیر اضافه' );
	const note = h( 'p', { class: 'note' } );

	const payload = () => ( {
		enabled: enabled.checked,
		runtime: runtime.value,
		image: image.value.trim(),
		network: network.value,
		memory: memory.value.trim(),
		cpus: cpus.value.trim(),
		readOnlyRoot: readOnly.checked,
		allowHostFallback: fallback.checked,
		mounts: mounts.value(),
	} );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'label', { class: 'check' }, [ enabled, h( 'span', { text: 'فرمان‌ها را داخل کانتینر اجرا کن' } ) ] ),
			field( 'موتور کانتینر', runtime ),
			field( 'ایمیج', image, 'برای پروژهٔ PHP/وردپرس: php:8.3-cli · برای جاوااسکریپت: node:22-bookworm-slim · ایمیج باید از قبل pull شده باشد یا شبکه باز باشد.' ),
			field( 'شبکه', network ),
			row( field( 'سقف حافظه', memory ), field( 'سقف CPU', cpus ) ),
			h( 'label', { class: 'check' }, [ readOnly, h( 'span', { text: 'ریشهٔ کانتینر فقط‌خواندنی باشد (به‌جز /tmp)' } ) ] ),
			h( 'label', { class: 'check' }, [
				fallback,
				h( 'span', { text: 'اگر کانتینر در دسترس نبود، روی سیستم اجرا کن (پیش‌فرض: نه — اجرا نشود)' } ),
			] ),
			field( 'مسیرهای اضافه', mounts.node, 'به شکل host:container — مثلاً /home/me/.composer:/root/.composer' ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'button', {
					class: 'btn outline',
					text: 'تست سندباکس',
					onClick: async () => {
						note.className = 'note';
						note.textContent = 'در حال آزمودن… (بار اول ممکن است ایمیج دانلود شود و طول بکشد)';
						const out = await post( '/api/sandbox', { action: 'test', sandbox: payload() } );
						note.className = `note ${ out.ok ? 'ok' : 'error' }`;
						note.textContent = out.message || out.error || '';
					},
				} ),
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						const out = await post( '/api/sandbox', { action: 'save', sandbox: payload() } );
						toast( out.error || 'ذخیره شد.', out.error ? 'error' : '' );
						await openSettings( 'sandbox' );
					},
				} ),
			] ),
			note,
		] )
	);

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'h4', { text: 'چه چیزی را محافظت می‌کند و چه چیزی را نه' } ),
			h( 'p', { class: 'note', text: 'محافظت می‌کند: فرمانی که مدل اجرا می‌کند به بقیهٔ دیسک، به شبکه (اگر بسته باشد) و به دسترسی‌های سیستمی نمی‌رسد.' } ),
			h( 'p', { class: 'note', text: 'محافظت نمی‌کند: خودِ پوشهٔ کاری داخل کانتینر قابل نوشتن است — چون کار عامل همین است. برای برگرداندنش، چک‌پوینت داری.' } ),
			h( 'p', { class: 'note', text: 'روی ویندوز به Docker Desktop با WSL2 نیاز داری.' } ),
		] )
	);
}

// ═══════════════════════════════════════════════════════ حافظهٔ پروژه

async function renderMemory( box ) {
	const out = await api( '/api/memory' );
	box.appendChild(
		section( 'حافظهٔ پروژه (VIRA.md)', 'هرچه اینجا بنویسی، در هر گفتگو به مدل داده می‌شود. جای قواعد پروژه، سبک کد، و کارهای ممنوع.' )
	);

	const editor = h( 'textarea', { class: 'field code tall', text: out.text || '' } );
	const note = h( 'p', { class: 'note mono', text: out.path } );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			editor,
			note,
			h( 'div', { class: 'modal-actions' }, [
				h( 'button', {
					class: 'btn outline',
					text: 'نمونهٔ آماده',
					onClick: () => {
						editor.value = [
							'# دستورالعمل این پروژه',
							'',
							'## سبک کد',
							'- ',
							'',
							'## فرمان‌های مهم',
							'- تست: ',
							'- اجرا: ',
							'',
							'## کارهای ممنوع',
							'- ',
						].join( '\n' );
					},
				} ),
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						const res = await post( '/api/memory', { text: editor.value } );
						toast( res.error || 'ذخیره شد.', res.error ? 'error' : '' );
					},
				} ),
			] ),
		] )
	);
}

// ═══════════════════════════════════════════════════════════════ ابزارها

async function renderTools( box ) {
	const s = getState();
	box.appendChild( section( `ابزارها (${ ( s.tools || [] ).length })`, 'ابزارها حذف نمی‌شوند؛ آنچه کنترل می‌شود دسترسی است — در تب مجوزها.' ) );

	const RISK = { read: 'خواندن', write: 'نوشتن', exec: 'اجرا', network: 'شبکه' };
	const list = el( 'div', 'card-list' );
	for ( const t of s.tools || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [ h( 'b', { class: 'mono', text: t.name } ), h( 'p', { text: t.description } ) ] ),
				h( 'span', { class: `tag risk-${ t.risk }`, text: RISK[ t.risk ] || t.risk } ),
				t.name.startsWith( 'mcp__' ) ? h( 'span', { class: 'tag mcp', text: 'MCP' } ) : null,
			] )
		);
	}
	box.appendChild( list );
}

// ═══════════════════════════════════════════════════════════ مصرف

async function renderUsage( box ) {
	const out = await api( '/api/usage' );
	box.appendChild( section( 'مصرف و هزینه', 'هزینه تخمینی است و از جدول قیمت داخلی می‌آید؛ در config.json با کلید pricing قابل تغییر است.' ) );

	const s = out.session || {};
	box.appendChild(
		h( 'div', { class: 'stat-row' }, [
			stat( 'توکن ورودی این نشست', String( s.inputTokens || 0 ) ),
			stat( 'توکن خروجی این نشست', String( s.outputTokens || 0 ) ),
			stat( 'هزینهٔ این نشست', s.cost ? `$${ Number( s.cost ).toFixed( 4 ) }` : '—' ),
			stat( 'مدل', out.model || '—' ),
		] )
	);

	const days = out.history?.days || [];
	if ( ! days.length ) {
		box.appendChild( emptyBox( 'هنوز مصرفی ثبت نشده.' ) );
		return;
	}

	const table = h( 'table', { class: 'table' }, [
		h( 'thead', {}, [ h( 'tr', {}, [ h( 'th', { text: 'روز' } ), h( 'th', { text: 'ورودی' } ), h( 'th', { text: 'خروجی' } ), h( 'th', { text: 'هزینه' } ) ] ) ] ),
		h(
			'tbody',
			{},
			days.map( ( d ) =>
				h( 'tr', {}, [
					h( 'td', { class: 'mono', text: d.date } ),
					h( 'td', { text: String( d.inputTokens ) } ),
					h( 'td', { text: String( d.outputTokens ) } ),
					h( 'td', { text: `$${ Number( d.cost || 0 ).toFixed( 4 ) }` } ),
				] )
			)
		),
	] );
	box.appendChild( table );
	box.appendChild(
		h( 'p', { class: 'note', text: `جمع ۳۰ روز: $${ Number( out.history?.total?.cost || 0 ).toFixed( 4 ) }` } )
	);
}

function stat( label, value ) {
	return h( 'div', { class: 'stat' }, [ h( 'span', { class: 'stat-label', text: label } ), h( 'b', { class: 'stat-value', text: value } ) ] );
}

// ═══════════════════════════════════════════════════════ وضعیت/تشخیص

async function renderStatus( box ) {
	const s = getState();
	const out = await api( '/api/doctor' );

	box.appendChild( section( 'وضعیت و تشخیص', 'اگر چیزی کار نمی‌کند، اول اینجا را ببین.' ) );

	const list = el( 'div', 'card-list' );
	for ( const c of out.checks || [] ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'span', { class: `dot ${ c.ok ? 'ok' : 'err' }`, text: c.ok ? '✓' : '✗' } ),
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: c.name } ),
					h( 'p', { class: 'mono', text: c.detail } ),
					c.hint && ! c.ok ? h( 'p', { class: 'note error', text: c.hint } ) : null,
				] ),
			] )
		);
	}
	box.appendChild( list );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'h4', { text: 'مسیرها' } ),
			h( 'p', { class: 'mono', text: `تنظیمات: ${ s.home }` } ),
			h( 'p', { class: 'mono', text: `پوشهٔ کاری: ${ s.config.workspace }` } ),
			h( 'p', { class: 'mono', text: `نسخه: ویرا ${ s.version }` } ),
			h( 'p', { class: 'mono', text: `کد از: ${ s.install?.root || '—' }` } ),
			h( 'p', {
				class: 'mono',
				text: `ساخت: ${ s.install?.buildLine || '—' }${ s.install?.build?.branch ? ` · شاخهٔ ${ s.install.build.branch }` : '' }`,
			} ),
			s.install?.frozen
				? h( 'p', { class: 'note error', text: s.install.hint } )
				: h( 'p', { class: 'note ok', text: 'این همان کد مخزن است؛ هر git pull بلافاصله اثر می‌گذارد.' } ),
		] )
	);
}

// ═══════════════════════════════════════════════════════════════ ظاهر

async function renderAppearance( box ) {
	box.appendChild( section( 'ظاهر', 'تنظیمات ظاهری در همین مرورگر ذخیره می‌شود.' ) );

	const theme = h( 'select', { class: 'field' }, [
		h( 'option', { value: 'dark', text: 'تاریک' } ),
		h( 'option', { value: 'light', text: 'روشن' } ),
	] );
	theme.value = document.documentElement.dataset.theme || 'dark';
	theme.onchange = () => {
		document.documentElement.dataset.theme = theme.value;
		localStorage.setItem( 'vira-theme', theme.value );
	};

	const density = h( 'select', { class: 'field' }, [
		h( 'option', { value: 'comfy', text: 'راحت' } ),
		h( 'option', { value: 'compact', text: 'فشرده' } ),
	] );
	density.value = localStorage.getItem( 'vira-density' ) || 'comfy';
	density.onchange = () => {
		document.documentElement.dataset.density = density.value;
		localStorage.setItem( 'vira-density', density.value );
	};

	const size = h( 'input', { class: 'field', type: 'range', min: '13', max: '19', value: localStorage.getItem( 'vira-fontsize' ) || '15' } );
	size.oninput = () => {
		document.documentElement.style.setProperty( '--fs', `${ size.value }px` );
		localStorage.setItem( 'vira-fontsize', size.value );
	};

	box.appendChild( h( 'div', { class: 'form-card' }, [ field( 'تم', theme ), field( 'تراکم', density ), field( 'اندازهٔ متن', size ) ] ) );
}

/**
 * رندر یک بخش از تنظیمات، داخل هر ظرفی که بدهی.
 *
 * دلیل وجودش: کارفرما درست می‌گفت که «برای هر چیزی باید تنظیمات را باز کنی» ایراد است.
 * حالا همین بخش‌ها از نوار کناری، مستقیم در ناحیهٔ اصلی باز می‌شوند — بدون دیالوگ.
 *
 * @param {string} tab
 * @param {HTMLElement} box
 */
export async function renderSection( tab, box ) {
	const fn = RENDER[ tab ];
	if ( ! fn ) {
		box.replaceChildren( el( 'div', 'empty', `بخش ناشناخته: ${ tab }` ) );
		return;
	}
	box.replaceChildren( el( 'div', 'loading', 'در حال بارگذاری…' ) );
	await refreshState();
	box.replaceChildren();
	await fn( box );
}

/*
 * سه بخشِ فضای کار که تا امروز رندرکننده نداشتند.
 *
 * در فهرست تب‌ها بودند و کلیک روی هرکدام «بخش ناشناخته: todos» می‌داد — یعنی سه دکمهٔ
 * مرده در رابط. داده‌شان از اول در `/api/state` بود؛ فقط کسی نمایششان نمی‌داد.
 */

/** @param {HTMLElement} box */
async function renderTodos( box ) {
	const s = getState() || {};
	const list = s.todos || [];
	box.replaceChildren( h( 'p', { class: 'set-row-desc', text: 'فهرست کاری که عامل برای خودش می‌نویسد؛ با پیشرفت کار به‌روز می‌شود.' } ) );
	if ( ! list.length ) {
		box.appendChild( el( 'div', 'empty', 'هنوز کاری ثبت نشده.' ) );
		return;
	}
	const card = h( 'div', { class: 'card-list' } );
	for ( const todo of list ) {
		const state = todo.state === 'done' ? 'انجام شد' : todo.state === 'doing' ? 'در حال انجام' : 'در نوبت';
		card.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'span', { class: 'm-ico', html: iconSvg( todo.state === 'done' ? 'check' : todo.state === 'doing' ? 'spinner' : 'circle-dot', 14 ) } ),
				h( 'div', { class: 'item-main' }, [ h( 'b', { 'data-no-t': '', text: todo.text || todo.title || '—' } ) ] ),
				h( 'span', { class: `tag ${ todo.state === 'done' ? 'ok' : '' }`, text: state } ),
			] )
		);
	}
	box.appendChild( card );
}

/** @param {HTMLElement} box */
async function renderShells( box ) {
	const out = await api( '/api/shells' );
	const list = out.shells || [];
	box.replaceChildren( h( 'p', { class: 'set-row-desc', text: 'فرمان‌هایی که در پس‌زمینه اجرا شده‌اند. خروجی هرکدام را می‌شود خواند و اجرای در حال کار را بست.' } ) );
	if ( ! list.length ) {
		box.appendChild( el( 'div', 'empty', 'شل پس‌زمینه‌ای در کار نیست.' ) );
		return;
	}
	const card = h( 'div', { class: 'card-list' } );
	for ( const sh of list ) {
		const output = h( 'pre', { class: 'tool-body mono', hidden: true } );
		card.appendChild(
			h( 'div', { class: 'item', style: 'flex-direction:column;align-items:stretch' }, [
				h( 'div', { class: 'row' }, [
					h( 'div', { class: 'item-main' }, [ h( 'b', { class: 'mono', 'data-no-t': '', text: sh.command || sh.id } ) ] ),
					h( 'span', { class: `tag ${ sh.running ? '' : 'ok' }`, text: sh.running ? 'در حال اجرا' : 'تمام شده' } ),
					h( 'button', {
						class: 'btn outline sm',
						text: 'خروجی',
						onClick: async () => {
							const res = await post( '/api/shells', { action: 'read', id: sh.id } );
							output.textContent = res.output || res.stdout || '(خروجی خالی)';
							output.hidden = ! output.hidden;
						},
					} ),
					sh.running
						? h( 'button', {
								class: 'btn outline sm danger',
								text: 'بستن کار',
								onClick: async () => {
									await post( '/api/shells', { action: 'kill', id: sh.id } );
									await renderShells( box );
								},
						  } )
						: null,
				] ),
				output,
			] )
		);
	}
	box.appendChild( card );
}

/** @param {HTMLElement} box */
async function renderCheckpoints( box ) {
	const out = await api( '/api/checkpoints' );
	const list = out.checkpoints || [];
	box.replaceChildren( h( 'p', { class: 'set-row-desc', text: 'پیش از هر تغییر فایل، یک نقطهٔ بازگشت ساخته می‌شود. از همین‌جا می‌شود به هرکدام برگشت.' } ) );
	if ( ! list.length ) {
		box.appendChild( el( 'div', 'empty', 'هنوز چک‌پوینتی ساخته نشده.' ) );
		return;
	}
	const card = h( 'div', { class: 'card-list' } );
	for ( const cp of [ ...list ].reverse() ) {
		card.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'span', { class: 'm-ico', html: iconSvg( 'checkpoint', 14 ) } ),
				h( 'div', { class: 'item-main' }, [
					h( 'b', { 'data-no-t': '', text: cp.label || cp.id } ),
					h( 'p', { class: 'note', text: timeAgo( cp.at || cp.createdAt || Date.now() ) } ),
				] ),
				h( 'button', {
					class: 'btn outline sm',
					text: 'بازگشت به این نقطه',
					onClick: () => document.dispatchEvent( new CustomEvent( 'vira:rewind', { detail: { id: cp.id } } ) ),
				} ),
			] )
		);
	}
	box.appendChild( card );
}

const RENDER = {
	provider: renderProvider,
	connectors: renderConnectors,
	skills: renderSkills,
	plugins: renderPlugins,
	agents: renderAgents,
	commands: renderCommands,
	hooks: renderHooks,
	permissions: renderPermissions,
	sandbox: renderSandbox,
	memory: renderMemory,
	tools: renderTools,
	usage: renderUsage,
	proxy: renderProxySettings,
	logs: renderLogsSettings,
	status: renderStatus,
	appearance: renderAppearance,
	todos: renderTodos,
	shells: renderShells,
	checkpoints: renderCheckpoints,
};
