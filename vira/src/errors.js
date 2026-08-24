/**
 * ترجمهٔ خطاهای خام به چیزی که کاربر بتواند کاری با آن بکند.
 *
 * دلیل وجودش از یک تجربهٔ واقعی می‌آید: کاربر کلید درست را وارد کرده بود و برنامه فقط
 * می‌گفت «fetch failed». علتش این بود که آن محیط اصلاً به اینترنت پرووایدر دسترسی نداشت —
 * ولی از پیام خطا هیچ‌کس این را نمی‌فهمید.
 */

/**
 * @param {any} error
 * @param {{baseUrl?:string, provider?:string, model?:string}} [ctx]
 * @returns {{message:string, hint?:string, kind:string}}
 */
export function explain( error, ctx = {} ) {
	const raw = String( error?.message || error || '' );
	const host = safeHost( ctx.baseUrl );

	// خطاهای شبکه — پیام خام Node اینجا بی‌فایده است.
	if ( /fetch failed|ENOTFOUND|EAI_AGAIN|ECONNREFUSED|ETIMEDOUT|ECONNRESET|network|socket hang up/i.test( raw ) ) {
		return {
			kind: 'network',
			message: `به ${ host || 'سرویس‌دهنده' } وصل نشدم.`,
			hint: 'اینترنت یا فیلترشکن را بررسی کن. اگر آدرس پایه را دستی وارد کرده‌ای، درستی‌اش را هم چک کن.',
		};
	}

	if ( /certificate|self signed|SSL|TLS/i.test( raw ) ) {
		return {
			kind: 'tls',
			message: `اتصال امن به ${ host || 'سرویس‌دهنده' } برقرار نشد.`,
			hint: 'اگر از یک سرویس داخلی با گواهی خودامضا استفاده می‌کنی، آدرس http بده یا گواهی را روی سیستم نصب کن.',
		};
	}

	// خطاهای HTTP که آداپتور با متن پاسخ برمی‌گرداند.
	const status = Number( /پاسخ (\d{3})/.exec( raw )?.[ 1 ] || /\b(4\d\d|5\d\d)\b/.exec( raw )?.[ 1 ] || 0 );

	if ( status === 401 || status === 403 || /invalid[_ ]api[_ ]key|unauthorized|authentication/i.test( raw ) ) {
		return {
			kind: 'auth',
			message: 'کلید API پذیرفته نشد.',
			hint: 'کلید را دوباره از پنل سرویس‌دهنده کپی کن. دقت کن کلید همان سرویسی باشد که در آدرس پایه نوشته‌ای.'
				+ ( status === 403 && ctx.proxy === '' ? ' اگر کلید درست است، احتمال تحریم جغرافیایی هست — در «هاب پرووایدر ← اتصال‌ها ← پراکسی» پراکسی‌ات را بگذار.' : '' ),
		};
	}

	if ( status === 404 || /model.*(not found|does not exist)|unknown model/i.test( raw ) ) {
		return {
			kind: 'model',
			message: `مدل «${ ctx.model || '؟' }» روی این سرویس‌دهنده پیدا نشد.`,
			hint: 'در تنظیمات، دکمهٔ «گرفتن فهرست» را بزن و یکی از مدل‌های واقعی را انتخاب کن.',
		};
	}

	if ( status === 429 || /rate.?limit|too many requests/i.test( raw ) ) {
		return {
			kind: 'rate',
			message: 'سرویس‌دهنده می‌گوید درخواست‌ها زیاد است.',
			hint: ctx.proxy === ''
				// ۴۲۹ از IP ایرانِ بدون پراکسی، پاسخ کلاسیکِ «تحریم/محدودیت جغرافیایی» هم هست؛
				// Node برخلاف مرورگر پراکسی سیستم را نمی‌بیند (۰.۹.۵، گزارش کارفرما).
				? 'اگر همین حالا هم پیاپی می‌آید: ویرا پراکسی سیستم را نمی‌بیند — در «هاب پرووایدر ← اتصال‌ها ← پراکسی» آدرس پراکسی‌ات را بگذار (مثلاً http://127.0.0.1:7890).'
				: 'چند لحظه صبر کن، یا اگر اعتبارت تمام شده حسابت را شارژ کن.',
		};
	}

	if ( status === 451 || /unavailable.*(country|region)|geo|not available in your country/i.test( raw ) ) {
		return {
			kind: 'geo',
			message: `سرویس‌دهنده دسترسی از این مسیر را نپذیرفت (${ status }).`,
			hint: ctx.proxy === ''
				? 'چنین چیزی برای IP ایران معمول است — در «هاب پرووایدر ← اتصال‌ها ← پراکسی» پراکسی‌ات را بگذار و «تست پراکسی» را بزن.'
				: 'پراکسی فعلی از نظر سرویس‌دهنده قابل قبول نیست؛ یک خروجی دیگر امتحان کن.',
		};
	}

	if ( status === 402 || /insufficient|quota|credit|billing/i.test( raw ) ) {
		return {
			kind: 'credit',
			message: 'اعتبار حساب کافی نیست.',
			hint: 'حساب سرویس‌دهنده را شارژ کن یا مدل ارزان‌تری انتخاب کن.',
		};
	}

	if ( status >= 500 ) {
		return {
			kind: 'server',
			message: `سرویس‌دهنده خطای ${ status } داد.`,
			hint: 'مشکل از سمت آن‌هاست؛ کمی بعد دوباره امتحان کن.',
		};
	}

	return { kind: 'unknown', message: raw.slice( 0, 400 ) || 'خطای نامشخص' };
}

/** @param {string} [url] */
function safeHost( url ) {
	try {
		return new URL( url ).host;
	} catch {
		return '';
	}
}
