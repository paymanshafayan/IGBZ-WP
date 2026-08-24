#!/usr/bin/env node
/**
 * استخراج یک آرشیو RAR — بدون هیچ ابزار سیستمی.
 *
 * روی این ماشین نه `unrar` هست نه `7z` نه `bsdtar`، و `apt` هم بسته است. این اسکریپت از
 * پیاده‌سازی WASM خود UnRAR استفاده می‌کند که از npm می‌آید و کار می‌کند (با فایل‌های
 * واقعی rar تست شده: پوشهٔ تودرتو، نام غیرلاتین، فایل مگابایتی).
 *
 *   node tools/unrar.mjs <archive.rar> <destination>
 *
 * محدودیت: آرشیو **چندجلدی** (part1.rar / part2.rar) پشتیبانی نمی‌شود. برای فایل بزرگ،
 * زیپ اصلی را با `split` تکه کنید — `setup.sh` تکه‌ها را با `cat` سرهم می‌کند.
 */

import fs from 'node:fs';
import path from 'node:path';

const [ archive, dest ] = process.argv.slice( 2 );

if ( ! archive || ! dest ) {
	console.error( 'کاربرد: node tools/unrar.mjs <archive.rar> <destination>' );
	process.exit( 2 );
}

let createExtractorFromData;
try {
	( { createExtractorFromData } = await import( 'node-unrar-js' ) );
} catch {
	console.error(
		'بستهٔ node-unrar-js نصب نیست.\n' +
			'اجرا کن:  cd hoosha && npm install node-unrar-js\n' +
			'(یا فایل را به‌جای rar، به‌صورت zip بگذار — unzip روی سیستم هست.)'
	);
	process.exit( 1 );
}

const data = fs.readFileSync( archive );
const extractor = await createExtractorFromData( { data: new Uint8Array( data ).buffer } );

const { files } = extractor.extract();
let count = 0;

for ( const file of files ) {
	const header = file.fileHeader;

	// نام‌های حاوی `..` را دور می‌ریزیم؛ یک آرشیو دستکاری‌شده نباید بیرون از مقصد بنویسد.
	const target = path.resolve( dest, header.name );
	if ( ! target.startsWith( path.resolve( dest ) + path.sep ) ) {
		console.error( `  رد شد (مسیر مشکوک): ${ header.name }` );
		continue;
	}

	if ( header.flags.directory ) {
		fs.mkdirSync( target, { recursive: true } );
		continue;
	}

	if ( ! file.extraction ) {
		continue;
	}

	fs.mkdirSync( path.dirname( target ), { recursive: true } );
	fs.writeFileSync( target, Buffer.from( file.extraction ) );
	count++;
}

console.log( `  ${ count } فایل از ${ path.basename( archive ) } استخراج شد` );
