/**
 * پلهٔ دوم نردبان عیب‌یابی: تعمیر قاعده‌ای، بدون هیچ تماس با مدل.
 *
 * و مهم‌تر از خودِ تعمیر: **تعریف اینکه یک «وصله» چیست**.
 *
 * قید اول سند طراحی: وصله یک شیء ساختاریافته است، نه کد. اگر مدل بتواند هرچه خواست
 * بنویسد، به یک سرویس بیرونی اجازه داده‌ایم خط لولهٔ درخواست‌های ما را بازنویسی کند.
 * پس فهرست عملیات **بسته** است و هر وصله — از هر جا که آمده باشد، قاعده یا مدل — از
 * `validatePatch` رد می‌شود.
 *
 * سخت‌گیرانه‌ترین قاعده اینجا: `set_base_url` فقط وقتی قبول است که **میزبان عوض نشود**.
 * وگرنه یک مدل می‌توانست با یک «وصله» همهٔ درخواست‌ها را به سرور خودش ببرد.
 */

/** فهرست بستهٔ عملیات مجاز. هرچه اینجا نباشد، رد می‌شود. */
export const PATCH_OPS = [
	'set_base_url',
	'drop_param',
	'set_param',
	'add_header',
	'set_auth_style',
	'disable_stream',
	'reshape_messages',
	'backoff_retry',
];

/** پارامترهایی که دست‌زدن به آن‌ها یعنی خراب‌کردن درخواست، نه تعمیرش. */
const PROTECTED_PARAMS = [ 'model', 'messages', 'tools', 'stream' ];

/** هدرهایی که فقط از راه `set_auth_style` عوض می‌شوند. */
const PROTECTED_HEADERS = [ 'authorization', 'x-api-key', 'host', 'content-length' ];

const RESHAPE_MODES = [ 'system_as_user', 'merge_system', 'no_tool_role' ];

/**
 * @param {any} patch
 * @param {{baseUrl?:string}} [ctx]
 * @returns {{ok:boolean, reason?:string, patch?:any}}
 */
export function validatePatch( patch, ctx = {} ) {
	if ( ! patch || typeof patch !== 'object' ) {
		return { ok: false, reason: 'وصله باید یک شیء باشد.' };
	}
	const op = String( patch.op || '' );
	if ( ! PATCH_OPS.includes( op ) ) {
		return { ok: false, reason: `عملیات «${ op || '؟' }» در فهرست مجاز نیست.` };
	}

	switch ( op ) {
		case 'set_base_url': {
			const value = String( patch.value || '' ).trim().replace( /\/+$/, '' );
			if ( ! /^https?:\/\//i.test( value ) ) {
				return { ok: false, reason: 'آدرس پایه باید http یا https باشد.' };
			}
			// میزبان نباید عوض شود — این مرز بین «تعمیر» و «ربودن ترافیک» است.
			if ( ctx.baseUrl ) {
				try {
					if ( new URL( value ).host !== new URL( ctx.baseUrl ).host ) {
						return { ok: false, reason: 'وصله اجازه ندارد میزبان آدرس پایه را عوض کند.' };
					}
				} catch {
					return { ok: false, reason: 'آدرس پایه معتبر نیست.' };
				}
			}
			return { ok: true, patch: { op, value } };
		}
		case 'drop_param': {
			const name = String( patch.name || '' );
			if ( ! /^[A-Za-z0-9_.]{1,40}$/.test( name ) ) {
				return { ok: false, reason: 'نام پارامتر معتبر نیست.' };
			}
			if ( PROTECTED_PARAMS.includes( name ) ) {
				return { ok: false, reason: `پارامتر «${ name }» حذف‌شدنی نیست.` };
			}
			return { ok: true, patch: { op, name } };
		}
		case 'set_param': {
			const name = String( patch.name || '' );
			if ( ! /^[A-Za-z0-9_.]{1,40}$/.test( name ) ) {
				return { ok: false, reason: 'نام پارامتر معتبر نیست.' };
			}
			if ( PROTECTED_PARAMS.includes( name ) ) {
				return { ok: false, reason: `پارامتر «${ name }» از راه وصله تنظیم نمی‌شود.` };
			}
			const value = patch.value;
			const scalar = value === null || [ 'string', 'number', 'boolean' ].includes( typeof value );
			if ( ! scalar || ( typeof value === 'string' && value.length > 200 ) ) {
				return { ok: false, reason: 'مقدار پارامتر باید یک مقدار ساده و کوتاه باشد.' };
			}
			return { ok: true, patch: { op, name, value } };
		}
		case 'add_header': {
			const name = String( patch.name || '' );
			if ( ! /^[A-Za-z0-9-]{1,60}$/.test( name ) ) {
				return { ok: false, reason: 'نام هدر معتبر نیست.' };
			}
			if ( PROTECTED_HEADERS.includes( name.toLowerCase() ) ) {
				return { ok: false, reason: `هدر «${ name }» از راه وصله عوض نمی‌شود.` };
			}
			const value = String( patch.value ?? '' );
			if ( value.length > 200 ) {
				return { ok: false, reason: 'مقدار هدر بیش از حد بلند است.' };
			}
			return { ok: true, patch: { op, name, value } };
		}
		case 'set_auth_style': {
			const value = String( patch.value || '' );
			if ( ! [ 'bearer', 'x-api-key', 'header', 'query', 'none' ].includes( value ) ) {
				return { ok: false, reason: 'سبک احراز ناشناخته است.' };
			}
			const header = String( patch.header || '' );
			if ( value === 'header' && ! /^[A-Za-z0-9-]{1,60}$/.test( header ) ) {
				return { ok: false, reason: 'برای سبک «هدر دلخواه» نام هدر لازم است.' };
			}
			return { ok: true, patch: header ? { op, value, header } : { op, value } };
		}
		case 'disable_stream':
			return { ok: true, patch: { op } };
		case 'reshape_messages': {
			const mode = String( patch.mode || '' );
			if ( ! RESHAPE_MODES.includes( mode ) ) {
				return { ok: false, reason: 'حالت بازچینش پیام ناشناخته است.' };
			}
			return { ok: true, patch: { op, mode } };
		}
		case 'backoff_retry': {
			const ms = Math.min( 30_000, Math.max( 100, Number( patch.ms ) || 1000 ) );
			return { ok: true, patch: { op, ms } };
		}
		default:
			return { ok: false, reason: 'عملیات ناشناخته.' };
	}
}

/**
 * اعمال وصله روی پیکربندی آداپتور.
 *
 * ورودی دست‌نخورده می‌ماند و یک نسخهٔ تازه برمی‌گردد — چون همان پیکربندی ممکن است
 * هم‌زمان در یک درخواست دیگر هم استفاده شود.
 *
 * @param {any} cfg
 * @param {any} patch
 */
export function applyPatch( cfg, patch ) {
	const next = {
		...cfg,
		headers: { ...( cfg.headers || {} ) },
		overrides: {
			dropParams: [ ...( cfg.overrides?.dropParams || [] ) ],
			setParams: { ...( cfg.overrides?.setParams || {} ) },
			noStream: Boolean( cfg.overrides?.noStream ),
			reshape: cfg.overrides?.reshape || '',
			backoffMs: cfg.overrides?.backoffMs || 0,
		},
	};

	switch ( patch?.op ) {
		case 'set_base_url':
			next.baseUrl = patch.value;
			break;
		case 'drop_param':
			if ( ! next.overrides.dropParams.includes( patch.name ) ) {
				next.overrides.dropParams.push( patch.name );
			}
			delete next.overrides.setParams[ patch.name ];
			break;
		case 'set_param':
			next.overrides.setParams[ patch.name ] = patch.value;
			next.overrides.dropParams = next.overrides.dropParams.filter( ( p ) => p !== patch.name );
			break;
		case 'add_header':
			next.headers[ patch.name ] = patch.value;
			break;
		case 'set_auth_style':
			next.authStyle = patch.value;
			if ( patch.header ) {
				next.authHeader = patch.header;
			}
			break;
		case 'disable_stream':
			next.overrides.noStream = true;
			break;
		case 'reshape_messages':
			next.overrides.reshape = patch.mode;
			break;
		case 'backoff_retry':
			next.overrides.backoffMs = patch.ms;
			break;
		default:
			break;
	}

	return next;
}

/**
 * چند وصله پشت سر هم.
 * @param {any} cfg
 * @param {any[]} patches
 */
export function applyPatches( cfg, patches ) {
	return ( patches || [] ).reduce( ( acc, p ) => applyPatch( acc, p ), cfg );
}

/**
 * پلهٔ ۲: از روی متن خطا، وصلهٔ قطعی حدس بزن.
 *
 * برمی‌گرداند `null` وقتی خطا از آن دسته نیست که با تغییر شکل درخواست حل شود —
 * مثل «مدل وجود ندارد» یا «اعتبار تمام شد». آن‌ها کار مسیریاب‌اند، نه عیب‌یاب.
 *
 * @param {{status?:number, message?:string, kind?:string}} error
 * @param {{baseUrl?:string, kind?:string, authStyle?:string, applied?:any[]}} cfg
 * @returns {{patch:any, why:string}|null}
 */
export function rulePatch( error, cfg = {} ) {
	const raw = String( error.message || '' );
	const text = raw.toLowerCase();
	const status = Number( error.status ) || 0;
	const applied = cfg.applied || [];
	const already = ( op, extra ) => applied.some( ( p ) => p.op === op && ( ! extra || p.name === extra || p.mode === extra || p.value === extra ) );

	// پایان اعتبار — نه خطا، یک واقعیت. عیب‌یاب اینجا کاری ندارد.
	if ( error.kind === 'credit' || /insufficient_quota|insufficient balance|billing|credit balance/i.test( text ) ) {
		return null;
	}

	// آدرس پایهٔ بدون /v1 — رایج‌ترین اشتباه در اتصال سازگار.
	if (
		( status === 404 || /not found|no route|cannot post/i.test( text ) ) &&
		cfg.kind !== 'anthropic' &&
		cfg.baseUrl &&
		! /\/v\d+$/.test( cfg.baseUrl ) &&
		! already( 'set_base_url' )
	) {
		return { patch: { op: 'set_base_url', value: `${ cfg.baseUrl }/v1` }, why: 'آدرس پایه بخش نسخه (/v1) نداشت.' };
	}

	// پارامتری که این سرویس نمی‌شناسد.
	const unknown =
		/unrecognized request argument[s]?:?\s*\[?["']?([a-z0-9_.]+)/i.exec( raw ) ||
		/unsupported (?:request )?parameter:?\s*["']?([a-z0-9_.]+)/i.exec( raw ) ||
		/unknown (?:field|parameter|argument):?\s*["']?([a-z0-9_.]+)/i.exec( raw ) ||
		/(?:parameter|property) ["']([a-z0-9_.]+)["'] is not supported/i.exec( raw ) ||
		/["']([a-z0-9_.]+)["'] is not (?:a )?(?:permitted|allowed|supported)/i.exec( raw );
	if ( unknown && ! PROTECTED_PARAMS.includes( unknown[ 1 ] ) && ! already( 'drop_param', unknown[ 1 ] ) ) {
		return { patch: { op: 'drop_param', name: unknown[ 1 ] }, why: `سرویس پارامتر «${ unknown[ 1 ] }» را نمی‌شناسد.` };
	}

	// max_tokens اجباری است (رفتار Anthropic و چند سازگار).
	if ( /max_tokens.*(required|missing)|missing.*max_tokens|field required.*max_tokens/i.test( text ) && ! already( 'set_param', 'max_tokens' ) ) {
		return { patch: { op: 'set_param', name: 'max_tokens', value: 4096 }, why: 'این سرویس max_tokens را اجباری می‌داند.' };
	}

	// نقش system پشتیبانی نمی‌شود.
	if ( /system (role|message).*(not supported|unsupported|not allowed)|role ["']?system["']? is not/i.test( text ) && ! already( 'reshape_messages', 'system_as_user' ) ) {
		return { patch: { op: 'reshape_messages', mode: 'system_as_user' }, why: 'سرویس نقش system را قبول نمی‌کند.' };
	}

	// نقش tool پشتیبانی نمی‌شود.
	if ( /role ["']?tool["']? is not|tool (role|messages?).*(not supported|unsupported)/i.test( text ) && ! already( 'reshape_messages', 'no_tool_role' ) ) {
		return { patch: { op: 'reshape_messages', mode: 'no_tool_role' }, why: 'سرویس پیام با نقش tool را قبول نمی‌کند.' };
	}

	// استریم پشتیبانی نمی‌شود.
	if ( /stream(ing)? (is )?(not supported|unsupported|not available|disabled)|does not support stream/i.test( text ) && ! already( 'disable_stream' ) ) {
		return { patch: { op: 'disable_stream' }, why: 'سرویس استریم ندارد؛ پاسخ یک‌جا گرفته می‌شود.' };
	}

	// هدر نسخهٔ Anthropic جا مانده.
	if ( /anthropic-version/i.test( raw ) && ! already( 'add_header', 'anthropic-version' ) ) {
		return { patch: { op: 'add_header', name: 'anthropic-version', value: '2023-06-01' }, why: 'هدر نسخهٔ Anthropic لازم بود.' };
	}

	// سبک احراز اشتباه: کلید درست است ولی جای اشتباهی فرستاده می‌شود.
	if ( status === 401 || /x-api-key/i.test( raw ) ) {
		if ( /x-api-key/i.test( raw ) && cfg.authStyle !== 'x-api-key' && ! already( 'set_auth_style', 'x-api-key' ) ) {
			return { patch: { op: 'set_auth_style', value: 'x-api-key' }, why: 'سرویس کلید را در هدر x-api-key می‌خواهد.' };
		}
		if ( /bearer/i.test( raw ) && cfg.authStyle !== 'bearer' && ! already( 'set_auth_style', 'bearer' ) ) {
			return { patch: { op: 'set_auth_style', value: 'bearer' }, why: 'سرویس کلید را به شکل Bearer می‌خواهد.' };
		}
	}

	// فشار زیاد: عقب‌نشینی و تکرار. هر بار دو برابر قبل.
	if ( status === 429 || /rate.?limit|too many requests|overloaded/i.test( text ) ) {
		const previous = applied.filter( ( p ) => p.op === 'backoff_retry' ).length;
		if ( previous < 3 ) {
			return { patch: { op: 'backoff_retry', ms: 1000 * 2 ** previous }, why: 'سرویس می‌گوید درخواست‌ها زیاد است؛ کمی صبر و تکرار.' };
		}
	}

	return null;
}
