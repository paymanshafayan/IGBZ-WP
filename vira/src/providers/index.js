import { providerInfo } from './catalog.js';
import { createOpenAiProvider } from './openai.js';
import { createAnthropicProvider } from './anthropic.js';
import { createMockProvider } from './mock.js';

export { PROVIDERS, providerInfo } from './catalog.js';

/**
 * ساخت آداپتور از روی پروفایل ذخیره‌شده (حالت تک‌واحد).
 *
 * `proxy` از ۰.۹.۷ اضافه شد. تا پیش از آن این سازنده اصلاً فیلد پراکسی نداشت، یعنی
 * حالت تک‌واحد از صفحهٔ پراکسیِ خود برنامه هیچ خبری نداشت و تماس‌هایش همیشه مستقیم
 * می‌رفت — رفتاری متفاوت با حالت هاب، که سرِ همین تفاوت کارفرما فکر می‌کرد «پراکسی
 * مشکل نیست، چون تک‌واحد کار می‌کند». حالا هر دو مسیر یک زنجیره دارند.
 *
 * @param {{provider:string,baseUrl?:string,apiKey?:string,model?:string,proxy?:string}} profile
 * @param {{proxy?:string}} [opts] پراکسی سراسری (صفحهٔ پراکسی/تونل)
 */
export function createProvider( profile, opts = {} ) {
	const info = providerInfo( profile.provider );
	if ( ! info ) {
		throw new Error( `پرووایدر ناشناخته: ${ profile.provider }` );
	}

	/** @type {import('./types.js').ProviderConfig} */
	const cfg = {
		providerId: info.id,
		kind: info.kind,
		baseUrl: profile.baseUrl || info.baseUrl,
		apiKey: profile.apiKey || '',
		model: profile.model || info.defaultModel || '',
		proxy: profile.proxy || opts.proxy || '',
	};

	if ( info.kind === 'mock' ) {
		return createMockProvider( cfg );
	}
	if ( info.kind === 'anthropic' ) {
		return createAnthropicProvider( cfg );
	}
	return createOpenAiProvider( cfg );
}

/**
 * آیا این پروفایل برای کار آماده است؟
 * @param {{provider:string,baseUrl?:string,apiKey?:string,model?:string}} profile
 * @returns {{ok:boolean,missing:string[]}}
 */
export function validateProfile( profile ) {
	const info = providerInfo( profile.provider );
	/** @type {string[]} */
	const missing = [];

	if ( ! info ) {
		return { ok: false, missing: [ 'پرووایدر' ] };
	}
	if ( info.kind !== 'mock' ) {
		if ( ! ( profile.baseUrl || info.baseUrl ) ) {
			missing.push( 'آدرس پایه (baseURL)' );
		}
		if ( info.needsKey && ! profile.apiKey ) {
			missing.push( 'کلید API' );
		}
		if ( ! ( profile.model || info.defaultModel ) ) {
			missing.push( 'نام مدل' );
		}
	}

	return { ok: missing.length === 0, missing };
}

/**
 * ساخت آداپتور از روی یک **اتصال هاب** (نه پروفایل تک‌نفره).
 *
 * فرقش با `createProvider` این است که همه‌چیز صریح می‌آید — سبک احراز، هدرهای سفارشی،
 * مسیر فهرست مدل — و یک لایهٔ `overrides` هم می‌گیرد که وصله‌های عیب‌یاب از آنجا وارد
 * می‌شوند. هیچ‌کدام از این‌ها در کد آداپتور hard-code نیست، چون اتصال سازگارِ دلخواه
 * ذاتاً هر شکلی می‌تواند داشته باشد.
 *
 * @param {any} conn
 * @param {{modelId?:string, overrides?:any}} [opts]
 */
export function createConnectionProvider( conn, opts = {} ) {
	/** @type {import('./types.js').ProviderConfig} */
	const cfg = {
		providerId: conn.id,
		kind: conn.kind === 'anthropic' ? 'anthropic' : conn.kind === 'mock' ? 'mock' : 'openai',
		baseUrl: conn.baseUrl || '',
		apiKey: conn.apiKey || '',
		model: opts.modelId || '',
		authStyle: conn.authStyle,
		authHeader: conn.authHeader,
		authPrefix: conn.authPrefix,
		headers: conn.headers || {},
		modelsPath: conn.modelsPath || '',
		overrides: opts.overrides || {},
		// پراکسی: اول خودِ اتصال، بعد سراسری هاب که فراخواننده در opts می‌دهد.
		proxy: conn.proxy || opts.proxy || '',
	};

	if ( cfg.kind === 'mock' ) {
		return createMockProvider( cfg );
	}
	if ( cfg.kind === 'anthropic' ) {
		return createAnthropicProvider( cfg );
	}
	return createOpenAiProvider( cfg );
}

