/**
 * فروشگاه اسکیل — نصب اسکیل آماده، بدون اینکه کاربر با پوشه‌ها سروکله بزند.
 *
 * خواستهٔ کارفرما صریح بود: «نمی‌خوام اسکیل بسازیم، می‌خوام بتونه اسکیل‌های آماده را نصب
 * کنه». پس سه راه نصب داریم:
 *
 *   ۱) مخزن گیت که خودش یک اسکیل است (SKILL.md در ریشه)
 *   ۲) مخزن گیت که چند اسکیل دارد (پوشهٔ skills/ یا چند پوشهٔ حاوی SKILL.md)
 *   ۳) مسیر محلی
 *
 * هیچ کدی موقع نصب اجرا نمی‌شود؛ فقط فایل کپی می‌شود.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';

/** @param {string} home */
export function skillsDir( home ) {
	return path.join( home, 'skills' );
}

/**
 * @param {string} home
 * @param {string} source  `owner/repo`، آدرس گیت، یا مسیر محلی
 * @param {string} [nameHint]
 */
export async function installSkill( home, source, nameHint ) {
	const root = skillsDir( home );
	await fs.mkdir( root, { recursive: true } );

	const isLocal = source.startsWith( '.' ) || source.startsWith( '/' ) || /^[A-Za-z]:\\/.test( source );
	/** @type {string} */
	let staging;
	/** @type {boolean} */
	let temporary = false;

	if ( isLocal ) {
		staging = path.resolve( source );
	} else {
		const url = source.includes( '://' ) || source.startsWith( 'git@' ) ? source : `https://github.com/${ source }.git`;
		staging = path.join( os.tmpdir(), `vira-skill-${ Date.now() }` );
		temporary = true;
		await git( [ 'clone', '--depth', '1', url, staging ] );
		await fs.rm( path.join( staging, '.git' ), { recursive: true, force: true } );
	}

	try {
		const found = await findSkillDirs( staging );
		if ( ! found.length ) {
			throw new Error( 'هیچ فایل SKILL.md در این منبع پیدا نشد.' );
		}

		/** @type {string[]} */
		const installed = [];
		for ( const dir of found ) {
			const name = ( found.length === 1 && nameHint ? nameHint : path.basename( dir ) ).replace( /[^\w.-]/g, '-' );
			const target = path.join( root, name );
			if ( await exists( target ) ) {
				await fs.rm( target, { recursive: true, force: true } );
			}
			await fs.cp( dir, target, { recursive: true } );
			installed.push( name );
		}
		return { installed };
	} finally {
		if ( temporary ) {
			await fs.rm( staging, { recursive: true, force: true } ).catch( () => {} );
		}
	}
}

/**
 * پیداکردن پوشه‌هایی که SKILL.md دارند (تا عمق ۳).
 * @param {string} root
 * @returns {Promise<string[]>}
 */
export async function findSkillDirs( root ) {
	/** @type {string[]} */
	const out = [];

	/** @param {string} dir @param {number} depth */
	async function walk( dir, depth ) {
		if ( depth > 3 ) {
			return;
		}
		if ( await exists( path.join( dir, 'SKILL.md' ) ) ) {
			out.push( dir );
			return; // اسکیل تودرتو معنا ندارد.
		}
		let entries = [];
		try {
			entries = await fs.readdir( dir, { withFileTypes: true } );
		} catch {
			return;
		}
		for ( const e of entries ) {
			if ( e.isDirectory() && ! e.name.startsWith( '.' ) && e.name !== 'node_modules' ) {
				await walk( path.join( dir, e.name ), depth + 1 );
			}
		}
	}

	await walk( root, 0 );
	return out;
}

/**
 * @param {string} home
 * @param {string} name
 */
export async function removeSkill( home, name ) {
	const dir = path.join( skillsDir( home ), String( name ).replace( /[^\w.-]/g, '-' ) );
	if ( ! ( await exists( dir ) ) ) {
		throw new Error( 'این اسکیل در پوشهٔ سراسری نیست (شاید اسکیل پروژه یا پلاگین باشد).' );
	}
	await fs.rm( dir, { recursive: true, force: true } );
	return { ok: true };
}

/**
 * خاموش/روشن با فایل نشانه — همان الگوی پلاگین‌ها.
 * @param {string} home
 * @param {string} name
 * @param {boolean} enabled
 */
export async function setSkillEnabled( home, name, enabled ) {
	const dir = path.join( skillsDir( home ), String( name ).replace( /[^\w.-]/g, '-' ) );
	if ( ! ( await exists( dir ) ) ) {
		throw new Error( 'اسکیل پیدا نشد.' );
	}
	const flag = path.join( dir, '.disabled' );
	if ( enabled ) {
		await fs.rm( flag, { force: true } );
	} else {
		await fs.writeFile( flag, '', 'utf8' );
	}
	return { ok: true };
}

// ------------------------------------------------------------------ کمکی‌ها

function git( args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( 'git', args, { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
		let err = '';
		child.stderr.on( 'data', ( d ) => {
			err += d.toString();
		} );
		child.on( 'error', () => reject( new Error( 'git روی این سیستم پیدا نشد.' ) ) );
		child.on( 'close', ( code ) =>
			code === 0 ? resolve( true ) : reject( new Error( `git شکست خورد: ${ err.slice( 0, 300 ) }` ) )
		);
	} );
}

async function exists( p ) {
	return fs
		.access( p )
		.then( () => true )
		.catch( () => false );
}
