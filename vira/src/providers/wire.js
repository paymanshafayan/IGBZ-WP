/**
 * چیزهای مشترک بین آداپتورها: ساخت هدر احراز، اعمال وصله روی بدنه، و عقب‌نشینی.
 *
 * تا دیروز هدرها داخل هر آداپتور ثابت بودند. با آمدن «اتصال سازگار» (بند ۴.۱) دیگر
 * نمی‌شود ثابت بمانند: یک سرویس ایرانی ممکن است کلید را در `x-api-key` بخواهد، دیگری در
 * یک هدر دلخواه، و سومی در پارامتر آدرس. و وصله‌های عیب‌یاب هم دقیقاً از همین در وارد
 * می‌شوند — نه با دست‌زدن به کد آداپتور.
 */

/**
 * @typedef {Object} Overrides
 * @property {string[]} [dropParams]
 * @property {Record<string, any>} [setParams]
 * @property {boolean} [noStream]
 * @property {''|'system_as_user'|'merge_system'|'no_tool_role'} [reshape]
 * @property {number} [backoffMs]
 */

/**
 * هدرهای درخواست.
 * @param {any} cfg
 * @param {Record<string,string>} [base]
 */
export function buildHeaders( cfg, base = {} ) {
	/** @type {Record<string,string>} */
	const headers = { 'Content-Type': 'application/json', ...base, ...( cfg.headers || {} ) };
	const key = cfg.apiKey || '';
	const style = cfg.authStyle || ( cfg.kind === 'anthropic' ? 'x-api-key' : 'bearer' );

	if ( ! key || style === 'none' || style === 'query' ) {
		return headers;
	}
	if ( style === 'x-api-key' ) {
		headers[ 'x-api-key' ] = key;
	} else if ( style === 'header' && cfg.authHeader ) {
		headers[ cfg.authHeader ] = `${ cfg.authPrefix || '' }${ key }`;
	} else {
		headers.Authorization = `Bearer ${ key }`;
	}
	return headers;
}

/**
 * وقتی سرویس کلید را در آدرس می‌خواهد.
 * @param {string} url
 * @param {any} cfg
 */
export function authedUrl( url, cfg ) {
	if ( cfg.authStyle !== 'query' || ! cfg.apiKey ) {
		return url;
	}
	const name = cfg.authHeader || 'key';
	return url + ( url.includes( '?' ) ? '&' : '?' ) + `${ encodeURIComponent( name ) }=${ encodeURIComponent( cfg.apiKey ) }`;
}

/**
 * اعمال وصله‌های پارامتری روی بدنهٔ درخواست.
 *
 * ترتیب مهم است: اول حذف، بعد تنظیم. اگر برعکس بود، یک وصلهٔ `set_param` می‌توانست با یک
 * `drop_param` قدیمی بی‌صدا خنثی شود و ساعت‌ها دنبال دلیلش می‌گشتیم.
 *
 * @param {any} payload
 * @param {Overrides} [overrides]
 */
export function finalizePayload( payload, overrides = {} ) {
	const out = { ...payload };
	for ( const name of overrides.dropParams || [] ) {
		delete out[ name ];
	}
	for ( const [ name, value ] of Object.entries( overrides.setParams || {} ) ) {
		if ( value === null ) {
			delete out[ name ];
		} else {
			out[ name ] = value;
		}
	}
	if ( overrides.noStream ) {
		out.stream = false;
	}
	return out;
}

/**
 * @param {Overrides} [overrides]
 * @param {AbortSignal} [signal]
 */
export async function backoff( overrides = {}, signal ) {
	const ms = Number( overrides.backoffMs ) || 0;
	if ( ms <= 0 ) {
		return;
	}
	await new Promise( ( resolve, reject ) => {
		const timer = setTimeout( resolve, ms );
		signal?.addEventListener?.(
			'abort',
			() => {
				clearTimeout( timer );
				reject( new Error( 'لغو شد' ) );
			},
			{ once: true }
		);
	} );
}

/**
 * بازچینش پیام‌ها وقتی سرویس نقشی را قبول نمی‌کند.
 *
 * @param {{role:string, content:any, toolCallId?:string, toolCalls?:any[]}[]} messages
 * @param {string} system
 * @param {string} [reshape]
 * @returns {{messages:any[], system:string}}
 */
export function reshapeMessages( messages, system, reshape ) {
	if ( ! reshape ) {
		return { messages, system };
	}

	let list = messages;
	let sys = system;

	if ( reshape === 'no_tool_role' ) {
		list = list.map( ( m ) =>
			m.role === 'tool'
				? {
						role: 'user',
						content: `[نتیجهٔ ابزار ${ m.toolCallId || '' }]\n${ typeof m.content === 'string' ? m.content : JSON.stringify( m.content ) }`,
				  }
				: m
		);
	}

	if ( reshape === 'system_as_user' && sys ) {
		list = [ { role: 'user', content: sys }, ...list ];
		sys = '';
	}

	if ( reshape === 'merge_system' && sys ) {
		const first = list.findIndex( ( m ) => m.role === 'user' );
		if ( first >= 0 && typeof list[ first ].content === 'string' ) {
			list = list.map( ( m, i ) => ( i === first ? { ...m, content: `${ sys }\n\n${ m.content }` } : m ) );
		} else {
			list = [ { role: 'user', content: sys }, ...list ];
		}
		sys = '';
	}

	return { messages: list, system: sys };
}
