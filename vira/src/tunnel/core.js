/**
 * مدیریت هستهٔ xray — دانلود رسمی از گیت‌هاب (مجوز کارفرما ۱۴۰۵/۰۵/۲۹)، نصب در
 * `~/.vira/tunnel/core/`. بخشی از تونل ویرا (۰.۹.۶).
 */

import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { logInfo, logError } from '../logs.js';
import { HOME } from '../config.js';

const REPO = 'XTLS/Xray-core';

export function coreDir() {
	return path.join( String( HOME ), 'tunnel', 'core' );
}
export function coreBin() {
	return path.join( coreDir(), process.platform === 'win32' ? 'xray.exe' : 'xray' );
}
export function corePresent() {
	try {
		return fs.statSync( coreBin() ).size > 1_000_000;
	} catch {
		return false;
	}
}
export function coreVersion() {
	return new Promise( ( resolve ) => {
		if ( ! corePresent() ) { resolve( '' ); return; }
		const p = spawn( coreBin(), [ 'version' ] );
		let out = '';
		p.stdout.on( 'data', ( d ) => ( out += d ) );
		p.on( 'error', () => resolve( '' ) );
		p.on( 'close', () => resolve( ( out.match( /Xray \S+/ ) || [ '' ] )[ 0 ] ) );
	} );
}

function assetName() {
	const plat = process.platform === 'win32' ? 'windows' : process.platform === 'darwin' ? 'macos' : 'linux';
	const arch = process.arch === 'arm64' ? 'arm64-v8a' : '64';
	return `Xray-${ plat }-${ arch }.zip`;
}

/** دانلود و نصب هسته — باینری رسمی، از releases خود پروژهٔ Xray. */
export async function downloadCore( onProgress = () => {} ) {
	if ( corePresent() ) {
		return { ok: true, already: true, version: await coreVersion() };
	}
	try {
		onProgress( 'گرفتن آخرین نسخه…' );
		const rel = await fetch( `https://api.github.com/repos/${ REPO }/releases/latest`, {
			headers: { 'user-agent': 'vira-tunnel' }, signal: AbortSignal.timeout( 20_000 ),
		} ).then( ( r ) => r.json() );
		const asset = ( rel.assets || [] ).find( ( a ) => a.name === assetName() );
		if ( ! asset ) {
			return { ok: false, error: `بستهٔ هسته برای این سیستم پیدا نشد (${ assetName() }).` };
		}
		onProgress( `دانلود ${ ( asset.size / 1048576 ).toFixed( 1 ) }MB…` );
		const zipBuf = Buffer.from( await ( await fetch( asset.browser_download_url, {
			headers: { 'user-agent': 'vira-tunnel' }, signal: AbortSignal.timeout( 300_000 ),
		} ) ).arrayBuffer() );
		fs.mkdirSync( coreDir(), { recursive: true } );
		const zipPath = path.join( coreDir(), 'xray.zip' );
		fs.writeFileSync( zipPath, zipBuf );
		onProgress( 'باز کردن بسته…' );
		const AdmZip = ( await import( 'adm-zip' ) ).default;
		const zip = new AdmZip( zipPath );
		zip.extractEntryTo( `xray${ process.platform === 'win32' ? '.exe' : '' }`, coreDir(), false, true );
		fs.rmSync( zipPath, { force: true } );
		if ( process.platform !== 'win32' ) {
			fs.chmodSync( coreBin(), 0o755 );
		}
		const version = await coreVersion();
		logInfo( 'tunnel', 'هستهٔ xray نصب شد.', { version } );
		return { ok: true, version };
	} catch ( e ) {
		logError( 'tunnel', 'دانلود هستهٔ xray شکست خورد.', { error: String( e?.message || e ).slice( 0, 200 ) } );
		return { ok: false, error: `دانلود هسته نشد: ${ String( e?.message || e ).slice( 0, 160 ) } — می‌توانی xray را دستی در ${ coreDir() } بگذاری.` };
	}
}
