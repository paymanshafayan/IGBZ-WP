/**
 * تنظیمات ویرا — یک فایل JSON در پوشهٔ خانگی کاربر.
 *
 * کلید API در همین فایل ذخیره می‌شود (مثل بقیهٔ ابزارهای این خانواده) و دسترسی فایل
 * روی 600 بسته می‌شود. هشدارش هم در README آمده.
 */

import fs from 'node:fs/promises';
import fssync from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * محل تنظیمات.
 *
 * پیش‌فرض `~/.vira` است، ولی با متغیر محیطی `VIRA_HOME` قابل تغییر است. این فقط
 * یک قابلیت تزئینی نیست: در محیط‌هایی که پوشهٔ خانگی بین اجراها پاک می‌شود (مثل همین
 * سندباکس توسعه)، کلید API کاربر ناپدید می‌شد و به‌نظر می‌رسید «ذخیره نمی‌شود».
 */
export const HOME = process.env.VIRA_HOME
	? path.resolve( process.env.VIRA_HOME )
	: path.join( os.homedir(), '.vira' );
export const CONFIG_PATH = path.join( HOME, 'config.json' );
export const SESSIONS_DIR = path.join( HOME, 'sessions' );

/** @returns {any} */
export function defaultConfig() {
	return {
		activeProfile: 'default',
		profiles: {
			default: {
				label: 'پیش‌فرض',
				provider: 'mock',
				baseUrl: '',
				apiKey: '',
				model: 'vira-mock-1',
			},
		},
		permissions: {
			mode: 'default',
			allow: [],
			ask: [],
			deny: [],
		},
		workspace: process.cwd(),
		/*
		 * سندباکس پیش‌فرض **روشن** است.
		 *
		 * خواستهٔ صریح کارفرما: «حالت پیش‌فرض را روی سندباکس بگذار، نه روی پروژهٔ اصلی؛
		 * بدون مجوز مدیر، ویرا حق تغییر پروژهٔ گیت را ندارد.» پس فرمان‌ها داخل کانتینر
		 * اجرا می‌شوند و `allowHostFallback` خاموش است: اگر کانتینری نباشد، فرمان
		 * **اجرا نمی‌شود** — نه اینکه بی‌صدا روی خودِ پروژه بیفتد.
		 */
		sandbox: {
			enabled: true,
			runtime: 'auto',
			image: 'node:22-bookworm-slim',
			network: 'none',
			memory: '2g',
			cpus: '2',
			readOnlyRoot: false,
			mounts: [],
			allowHostFallback: false,
			user: true,
		},
		ui: { theme: 'dark' },
		/*
		 * پراکسی ویرا (۰.۹.۶) — صفحهٔ تنظیمات به سبک صفحهٔ پراکسی ویندوز (تصویر
		 * Snap15 کارفرما): حالت + آدرس/پورت + استثناها (با ؛ جدا) + تیک «شبکهٔ محلی
		 * بدون پراکسی». حالت engine یعنی موتور تونل داخلی درگاه محلی را فراهم می‌کند.
		 */
		proxy: {
			mode: 'off', // off | manual | engine
			address: '127.0.0.1',
			port: 7890,
			exceptions: 'localhost;127.*;10.*;172.16.*;172.17.*;172.18.*;172.19.*;172.2?.*;172.30.*;172.31.*;192.168.*',
			bypassLocal: true,
		},
	};
}

export async function ensureHome() {
	await fs.mkdir( HOME, { recursive: true } );
	await fs.mkdir( SESSIONS_DIR, { recursive: true } );
}

export async function loadConfig() {
	await ensureHome();
	try {
		const raw = await fs.readFile( CONFIG_PATH, 'utf8' );
		const parsed = JSON.parse( raw );
		return { ...defaultConfig(), ...parsed };
	} catch {
		const cfg = defaultConfig();
		await saveConfig( cfg );
		return cfg;
	}
}

/** @param {any} cfg */
export async function saveConfig( cfg ) {
	await ensureHome();
	await fs.writeFile( CONFIG_PATH, JSON.stringify( cfg, null, 2 ), 'utf8' );
	try {
		fssync.chmodSync( CONFIG_PATH, 0o600 );
	} catch {
		// روی ویندوز اهمیتی ندارد.
	}
	return cfg;
}

/** نسخهٔ امن برای فرستادن به رابط کاربری: کلیدها ماسک می‌شوند. */
export function publicConfig( cfg ) {
	const profiles = {};
	for ( const [ id, p ] of Object.entries( cfg.profiles || {} ) ) {
		profiles[ id ] = { ...p, apiKey: p.apiKey ? '••••••••' + String( p.apiKey ).slice( -4 ) : '' };
	}
	return { ...cfg, profiles };
}

/** @param {any} cfg */
export function activeProfile( cfg ) {
	return cfg.profiles?.[ cfg.activeProfile ] || Object.values( cfg.profiles || {} )[ 0 ] || null;
}
