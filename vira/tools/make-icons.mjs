/**
 * ساخت فایل‌های نشان از روی هندسهٔ مشترک.
 *
 *   node tools/make-icons.mjs
 *
 * خروجی: `ui/assets/logo.svg`، `ui/assets/logo-live.svg` و آیکون‌های PNG برای PWA.
 *
 * چرا رستر را خودمان می‌نویسیم: در این محیط نه ImageMagick هست نه rsvg نه sharp (باینری‌شان
 * از ریلیز گیت‌هاب می‌آید و بسته است). ولی نقشِ ما چند چندضلعی ساده است، و PNG هم چیزی جز
 * چند بلوک با zlib نیست — که در خود Node هست. پس بدون هیچ وابستگی‌ای رستر می‌کنیم.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

import { VIEW, CENTER, CORE, COLORS, markPath, innerStarPath, starPoints, polygonPoints } from '../ui/lib/mark.js';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const ASSETS = path.join( __dirname, '..', 'ui', 'assets' );

// ─────────────────────────────────────────────────────────────── SVG

const GRADIENT = ( id ) => `	<defs>
		<linearGradient id="${ id }" x1="4" y1="3" x2="28" y2="29" gradientUnits="userSpaceOnUse">
			<stop offset="0" stop-color="${ COLORS.from }" />
			<stop offset="1" stop-color="${ COLORS.to }" />
		</linearGradient>
	</defs>`;

function staticSvg() {
	return `<!--
	نشان هوشا — شمسهٔ هشت‌پر.

	نقش هندسی معماری ایرانی: دو مربع چرخیده روی هم، هشت‌ضلعیِ خالی در میان، و نقطهٔ مرکزی.
	این فایل با \`node tools/make-icons.mjs\` ساخته می‌شود؛ دستی ویرایشش نکن.
-->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ VIEW } ${ VIEW }" width="${ VIEW }" height="${ VIEW }" role="img" aria-label="هوشا">
${ GRADIENT( 'hoosha-mark' ) }
	<path d="${ markPath() }" fill="url(#hoosha-mark)" fill-rule="evenodd" />
	<path d="${ innerStarPath() }" fill="url(#hoosha-mark)" />
</svg>
`;
}

function liveSvg() {
	return `<!--
	نشان متحرک هوشا — همان شمسه، در حال کار.

	شمسهٔ بیرونی آرام می‌چرخد، شمسهٔ درونی برعکس، و نقطهٔ مرکزی نبض می‌زند. حرکت با SMIL
	نوشته شده تا اگر این فایل به‌عنوان تصویر هم استفاده شد باز جان داشته باشد.
-->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ VIEW } ${ VIEW }" width="${ VIEW }" height="${ VIEW }" role="img" aria-label="در حال کار">
${ GRADIENT( 'hoosha-live' ) }
	<g fill="url(#hoosha-live)">
		<path d="${ markPath() }" fill-rule="evenodd" opacity="0.9">
			<animateTransform attributeName="transform" type="rotate" from="0 ${ CENTER } ${ CENTER }" to="360 ${ CENTER } ${ CENTER }" dur="9s" repeatCount="indefinite" />
			<animate attributeName="opacity" values="0.9;0.45;0.9" dur="2s" repeatCount="indefinite" />
		</path>
		<path d="${ innerStarPath() }" opacity="0.95">
			<animateTransform attributeName="transform" type="rotate" from="360 ${ CENTER } ${ CENTER }" to="0 ${ CENTER } ${ CENTER }" dur="5s" repeatCount="indefinite" />
			<animate attributeName="opacity" values="0.95;0.5;0.95" dur="1.4s" repeatCount="indefinite" />
		</path>

	</g>
</svg>
`;
}

// ────────────────────────────────────────────────────── رستر و PNG

/** @param {[number,number][]} poly @param {number} x @param {number} y */
function inside( poly, x, y ) {
	let hit = false;
	for ( let i = 0, j = poly.length - 1; i < poly.length; j = i++ ) {
		const [ xi, yi ] = poly[ i ];
		const [ xj, yj ] = poly[ j ];
		if ( yi > y !== yj > y && x < ( ( xj - xi ) * ( y - yi ) ) / ( yj - yi ) + xi ) {
			hit = ! hit;
		}
	}
	return hit;
}

/**
 * پوششِ یک پیکسل با نمونه‌گیری ۴×۴ — لبه‌ها بدون این، دندانه‌دار می‌شوند.
 *
 * @param {number} px
 * @param {number} py
 * @param {number} scale
 */
function coverage( px, py, scale ) {
	const star = starPoints();
	const ring = polygonPoints();
	const core = starPoints( 8, CORE, CORE * 0.7654, -90 + 22.5 );
	let hits = 0;
	const S = 4;
	for ( let sy = 0; sy < S; sy++ ) {
		for ( let sx = 0; sx < S; sx++ ) {
			const x = ( px + ( sx + 0.5 ) / S ) / scale;
			const y = ( py + ( sy + 0.5 ) / S ) / scale;
			if ( ( inside( star, x, y ) && ! inside( ring, x, y ) ) || inside( core, x, y ) ) {
				hits++;
			}
		}
	}
	return hits / ( S * S );
}

/** @param {string} hex */
function rgb( hex ) {
	return [ parseInt( hex.slice( 1, 3 ), 16 ), parseInt( hex.slice( 3, 5 ), 16 ), parseInt( hex.slice( 5, 7 ), 16 ) ];
}

/** @param {number} size */
function renderRgba( size ) {
	const scale = size / VIEW;
	const from = rgb( COLORS.from );
	const to = rgb( COLORS.to );
	const data = Buffer.alloc( size * size * 4 );

	for ( let y = 0; y < size; y++ ) {
		for ( let x = 0; x < size; x++ ) {
			const a = coverage( x, y, scale );
			const t = Math.min( 1, Math.max( 0, ( x / size + y / size ) / 2 ) );
			const i = ( y * size + x ) * 4;
			data[ i ] = Math.round( from[ 0 ] + ( to[ 0 ] - from[ 0 ] ) * t );
			data[ i + 1 ] = Math.round( from[ 1 ] + ( to[ 1 ] - from[ 1 ] ) * t );
			data[ i + 2 ] = Math.round( from[ 2 ] + ( to[ 2 ] - from[ 2 ] ) * t );
			data[ i + 3 ] = Math.round( a * 255 );
		}
	}
	return data;
}

/** @param {string} type @param {Buffer} body */
function chunk( type, body ) {
	const len = Buffer.alloc( 4 );
	len.writeUInt32BE( body.length );
	const head = Buffer.concat( [ Buffer.from( type, 'ascii' ), body ] );
	const crc = Buffer.alloc( 4 );
	crc.writeUInt32BE( crc32( head ) >>> 0 );
	return Buffer.concat( [ len, head, crc ] );
}

const CRC_TABLE = ( () => {
	const t = new Int32Array( 256 );
	for ( let n = 0; n < 256; n++ ) {
		let c = n;
		for ( let k = 0; k < 8; k++ ) {
			c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
		}
		t[ n ] = c;
	}
	return t;
} )();

/** @param {Buffer} buf */
function crc32( buf ) {
	let c = -1;
	for ( const b of buf ) {
		c = CRC_TABLE[ ( c ^ b ) & 0xff ] ^ ( c >>> 8 );
	}
	return c ^ -1;
}

/**
 * PNG کمینه: امضا + IHDR + IDAT + IEND. رنگ RGBA هشت‌بیتی، بدون درهم‌بافت.
 * @param {number} size
 * @param {Buffer} rgba
 */
function encodePng( size, rgba ) {
	const signature = Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] );

	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( size, 0 );
	ihdr.writeUInt32BE( size, 4 );
	ihdr[ 8 ] = 8; // عمق بیت
	ihdr[ 9 ] = 6; // RGBA
	ihdr[ 10 ] = 0;
	ihdr[ 11 ] = 0;
	ihdr[ 12 ] = 0;

	// هر سطر یک بایت «فیلتر» جلو دارد؛ صفر یعنی بدون فیلتر.
	const raw = Buffer.alloc( size * ( size * 4 + 1 ) );
	for ( let y = 0; y < size; y++ ) {
		raw[ y * ( size * 4 + 1 ) ] = 0;
		rgba.copy( raw, y * ( size * 4 + 1 ) + 1, y * size * 4, ( y + 1 ) * size * 4 );
	}

	return Buffer.concat( [
		signature,
		chunk( 'IHDR', ihdr ),
		chunk( 'IDAT', zlib.deflateSync( raw, { level: 9 } ) ),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

// ─────────────────────────────────────────────────────────────── اجرا

await fs.mkdir( path.join( ASSETS, 'icons' ), { recursive: true } );
await fs.writeFile( path.join( ASSETS, 'logo.svg' ), staticSvg(), 'utf8' );
await fs.writeFile( path.join( ASSETS, 'logo-live.svg' ), liveSvg(), 'utf8' );

const SIZES = [ 16, 32, 48, 96, 192, 512 ];
for ( const size of SIZES ) {
	const png = encodePng( size, renderRgba( size ) );
	await fs.writeFile( path.join( ASSETS, 'icons', `icon-${ size }.png` ), png );
}

const manifest = {
	name: 'هوشا',
	short_name: 'هوشا',
	description: 'دستیار عامل بومی — روی دستگاه خودت، با هر پرووایدری',
	lang: 'fa',
	dir: 'rtl',
	start_url: '.',
	scope: '.',
	display: 'standalone',
	background_color: '#1a1918',
	theme_color: '#262624',
	icons: [
		{ src: 'assets/logo.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
		...SIZES.map( ( s ) => ( { src: `assets/icons/icon-${ s }.png`, sizes: `${ s }x${ s }`, type: 'image/png' } ) ),
		{ src: 'assets/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
	],
};
await fs.writeFile( path.join( ASSETS, '..', 'manifest.webmanifest' ), `${ JSON.stringify( manifest, null, '\t' ) }\n`, 'utf8' );

process.stdout.write( `نشان ساخته شد: logo.svg، logo-live.svg، ${ SIZES.length } آیکون PNG، و manifest\n` );
