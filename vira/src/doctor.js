/**
 * تشخیص وضعیت (`/doctor` و `/status`).
 *
 * وقتی چیزی کار نمی‌کند، اولین سؤال همیشه یکی است: «کجا خراب است؟». این فایل جواب را در
 * یک نگاه می‌دهد: نسخهٔ نود، جای تنظیمات، قابل‌نوشتن بودنش، دسترسی به پرووایدر، گیت، و
 * تعداد اسکیل/پلاگین/کانکتور.
 */

import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { execFile } from 'node:child_process';

/**
 * @param {{home:string, workspace:string, config:any, runtime:any, providerInfo:any}} opts
 */
export async function diagnose( { home, workspace, config, runtime, providerInfo } ) {
	/** @type {{name:string, ok:boolean, detail:string, hint?:string}[]} */
	const checks = [];

	checks.push( {
		name: 'نسخهٔ Node',
		ok: Number( process.versions.node.split( '.' )[ 0 ] ) >= 18,
		detail: `v${ process.versions.node } روی ${ os.platform() } ${ os.arch() }`,
		hint: 'حداقل نسخهٔ لازم ۱۸ است.',
	} );

	const homeWritable = await canWrite( home );
	checks.push( {
		name: 'پوشهٔ تنظیمات',
		ok: homeWritable,
		detail: home,
		hint: homeWritable ? undefined : 'اجازهٔ نوشتن نیست؛ با VIRA_HOME مسیر دیگری بده.',
	} );

	const wsStat = await fs.stat( workspace ).catch( () => null );
	checks.push( {
		name: 'پوشهٔ کاری',
		ok: Boolean( wsStat?.isDirectory() ),
		detail: workspace,
	} );

	const profile = config?.profiles?.[ config.activeProfile ] || {};
	checks.push( {
		name: 'پرووایدر',
		ok: Boolean( profile.provider && profile.model ),
		detail: `${ profile.provider || '—' } · ${ profile.model || 'بدون مدل' }${
			providerInfo?.needsKey ? ( profile.apiKey ? ' · کلید ثبت شده' : ' · بدون کلید' ) : ''
		}`,
		hint: profile.apiKey || ! providerInfo?.needsKey ? undefined : 'در تنظیمات، کلید API را وارد کن.',
	} );

	const keyFile = path.join( home, 'config.json' );
	const mode = await fs
		.stat( keyFile )
		.then( ( s ) => ( s.mode & 0o777 ).toString( 8 ) )
		.catch( () => null );
	checks.push( {
		name: 'دسترسی فایل تنظیمات',
		ok: mode === null || os.platform() === 'win32' || mode === '600',
		detail: mode ? `chmod ${ mode }` : 'هنوز ساخته نشده',
		hint: 'کلید API داخل همین فایل است؛ روی لینوکس باید ۶۰۰ باشد.',
	} );

	const gitVersion = await run( 'git', [ '--version' ] );
	checks.push( { name: 'گیت', ok: Boolean( gitVersion ), detail: gitVersion || 'پیدا نشد', hint: 'برای نصب پلاگین از گیت‌هاب لازم است.' } );

	const { sandboxStatus } = await import( './sandbox.js' );
	const sb = await sandboxStatus( config );
	checks.push( {
		name: 'سندباکس',
		ok: ! sb.enabled || sb.available,
		detail: sb.enabled ? `روشن · ${ sb.runtimeName || 'موتوری پیدا نشد' } · ${ sb.image }` : 'خاموش (فرمان‌ها روی سیستم اجرا می‌شوند)',
		hint: sb.enabled && ! sb.available ? 'Docker یا Podman نصب و اجرا نیست؛ با این وضع هیچ فرمانی اجرا نمی‌شود.' : undefined,
	} );

	const mcpFailed = ( runtime?.mcp?.status || [] ).filter( ( s ) => s.status === 'failed' );
	checks.push( {
		name: 'کانکتورهای MCP',
		ok: mcpFailed.length === 0,
		detail: `${ ( runtime?.mcp?.status || [] ).length } تعریف‌شده، ${ mcpFailed.length } ناموفق`,
		hint: mcpFailed.length ? mcpFailed.map( ( s ) => `${ s.name }: ${ s.error }` ).join( ' · ' ) : undefined,
	} );

	checks.push( {
		name: 'افزونه‌ها',
		ok: true,
		detail: `${ runtime?.skills?.length || 0 } اسکیل · ${ runtime?.plugins?.length || 0 } پلاگین · ${
			runtime?.commands?.length || 0
		} دستور · ${ runtime?.agents?.length || 0 } عامل`,
	} );

	checks.push( {
		name: 'حافظهٔ پروژه',
		ok: true,
		detail: runtime?.projectMemory ? 'VIRA.md خوانده شد' : 'VIRA.md ندارد',
		hint: runtime?.projectMemory ? undefined : 'با /init یک VIRA.md بساز تا عامل قواعد پروژه را بداند.',
	} );

	return { checks, ok: checks.every( ( c ) => c.ok ) };
}

async function canWrite( dir ) {
	try {
		await fs.mkdir( dir, { recursive: true } );
		const probe = path.join( dir, `.probe-${ Date.now() }` );
		await fs.writeFile( probe, 'x' );
		await fs.rm( probe, { force: true } );
		return true;
	} catch {
		return false;
	}
}

function run( cmd, args ) {
	return new Promise( ( resolve ) => {
		execFile( cmd, args, ( err, stdout ) => resolve( err ? '' : String( stdout ).trim() ) );
	} );
}
