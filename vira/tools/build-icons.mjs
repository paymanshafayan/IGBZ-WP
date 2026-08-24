#!/usr/bin/env node
/**
 * ساخت `ui/lib/icons.js` از روی Font Awesome Pro 5.15.4.
 *
 *   node tools/build-icons.mjs [مسیر پوشهٔ FontAwesome]
 *
 * چرا این شکل و نه فونت آیکونی:
 *
 *   - فونت کامل (`all.min.css` + پنج فایل woff2) نزدیک نیم مگابایت است برای رابطی که
 *     شصت آیکون لازم دارد. اینجا فقط همان شصت‌تا در یک فایل جاوااسکریپت درمی‌آیند.
 *   - `<use href="icons.svg#x">` هم امتحان شد و کنار گذاشته شد: ارجاع بیرونی با
 *     `currentColor` در همهٔ مرورگرها یکسان رفتار نمی‌کند.
 *   - نتیجه SVG درون‌خطی است: با `currentColor` رنگ می‌گیرد، در چاپ و تم تیره درست
 *     می‌ماند، و هیچ درخواست شبکه‌ای اضافه نمی‌کند.
 *
 * آرشیو FA در مخزن نیست (۲۰ مگابایت، و پروانه‌اش تجاری است). این اسکریپت را وقتی لازم
 * شد آیکونی اضافه یا عوض شود دستی اجرا می‌کنیم؛ خروجی‌اش — که فقط همان چند مسیر است —
 * در مخزن می‌ماند.
 */

import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.argv[ 2 ] || '/tmp/fa/FontAwesome.Pro.5.15.4.Web';
const OUT = path.resolve( 'ui/lib/icons.js' );

/**
 * آیکون‌های رابط: نام داخلی → «سبک/نام فایل در FA».
 *
 * سبک `light` پیش‌فرض است چون با وزن خطوط بقیهٔ رابط (۱٫۴ پیکسل) جور درمی‌آید؛ جاهایی
 * که آیکون باید پر و توپر دیده شود `solid` است.
 */
const ICONS = {
	// ناوبری نوار کناری
	'chats': 'light/comments',
	'projects': 'light/layer-group',
	'tools': 'light/tools',
	'changes': 'light/code',
	'customize': 'light/briefcase',
	'workspace': 'light/laptop-code',
	'new-chat': 'light/plus',
	'search': 'light/search',
	'collapse': 'light/columns',
	'list': 'light/list-ul',
	'export': 'light/download',
	'chevron-up': 'light/chevron-up',
	'chevron-down': 'light/chevron-down',
	'chevron-left': 'light/chevron-left',
	'chevron-right': 'light/chevron-right',
	'back': 'light/arrow-right',
	'more': 'light/ellipsis-h',

	// کامپوزر
	'plus': 'light/plus',
	'mic': 'light/microphone',
	'waveform': 'light/waveform',
	'send': 'solid/arrow-up',
	'stop': 'solid/square',
	'paperclip': 'light/paperclip',
	'camera': 'light/camera',
	'at': 'light/at',
	'terminal': 'light/terminal',
	'jump-down': 'light/arrow-down',

	// گیت
	'repo': 'light/book',
	'branch': 'light/code-branch',
	'diff': 'light/file-code',
	'commit': 'light/check',
	'push': 'light/arrow-up',
	'pull-request': 'light/code-merge',
	'shield': 'light/shield-check',

	// تنظیمات و هاب
	'settings': 'light/cog',
	'provider': 'light/plug',
	'plug-alt': 'light/server',
	'model': 'light/microchip',
	'hub': 'light/project-diagram',
	'health': 'light/heartbeat',
	'profile': 'light/user',
	'permissions': 'light/user-shield',
	'sandbox': 'light/box',
	'skills': 'light/graduation-cap',
	'connectors': 'light/exchange',
	'plugins': 'light/puzzle-piece',
	'subagents': 'light/users',
	'commands': 'light/slash',
	'hooks': 'light/flag',
	'memory': 'light/brain',
	'usage': 'light/chart-line',
	'status': 'light/stethoscope',
	'appearance': 'light/palette',
	'language': 'light/globe',
	'help': 'light/question-circle',
	'reload': 'light/redo',
	'theme': 'light/adjust',

	// گفتگو و ابزارها
	'copy': 'light/copy',
	'retry': 'light/redo-alt',
	'speak': 'light/volume-up',
	'edit': 'light/pen',
	'trash': 'light/trash-alt',
	'pin': 'light/thumbtack',
	'open-external': 'light/external-link',
	'folder-plus': 'light/folder-plus',
	'check': 'light/check',
	'times': 'light/times',
	'circle-dot': 'light/dot-circle',
	'clock': 'light/clock',
	'spinner': 'light/circle-notch',
	'thinking': 'light/lightbulb-on',
	'file': 'light/file-alt',
	'rewind': 'light/history',
	'shell': 'light/terminal',
	'todo': 'light/tasks',
	'checkpoint': 'light/save',
	'up': 'light/arrow-up',
	'down': 'light/arrow-down',
	'sample': 'light/vial',
	'install': 'light/download',
	'market': 'light/store',
	'bolt': 'light/bolt',
};

/** @param {string} rel */
function read( rel ) {
	const file = path.join( ROOT, 'svgs', `${ rel }.svg` );
	const raw = fs.readFileSync( file, 'utf8' );
	const box = raw.match( /viewBox="([^"]+)"/ )?.[ 1 ];
	const paths = [ ...raw.matchAll( /<path[^>]*\sd="([^"]+)"/g ) ].map( ( m ) => m[ 1 ] );
	if ( ! box || ! paths.length ) {
		throw new Error( `آیکون ${ rel } خوانده نشد` );
	}
	return { box, paths };
}

const entries = [];
for ( const [ name, rel ] of Object.entries( ICONS ) ) {
	const { box, paths } = read( rel );
	entries.push( `\t'${ name }': [ '${ box }', ${ paths.map( ( d ) => `'${ d }'` ).join( ', ' ) } ],` );
}

const out = `/**
 * آیکون‌های رابط — از Font Awesome Pro 5.15.4، فقط همان‌هایی که استفاده می‌شوند.
 *
 * این فایل **ساخته می‌شود**؛ دستی ویرایشش نکن:
 *
 *     node tools/build-icons.mjs <مسیر FontAwesome.Pro.5.15.4.Web>
 *
 * هر ورودی: [ viewBox, ...مسیرها ]. خروجی SVG درون‌خطی با \`currentColor\` است، پس آیکون
 * همان رنگ متنِ کنارش را می‌گیرد و در تم تیره هم درست می‌ماند.
 *
 * Font Awesome Pro 5.15.4 by @fontawesome — https://fontawesome.com
 * پروانهٔ تجاری؛ نسخهٔ کارفرما. (\`_bin/FontAwesome.Pro.5.15.4.Web.rar\`)
 */

/** @type {Record<string, string[]>} */
export const ICONS = {
${ entries.join( '\n' ) }
};

/**
 * یک آیکون به شکل رشتهٔ SVG.
 *
 * @param {string} name
 * @param {number} [size]
 * @param {string} [cls]
 */
export function iconSvg( name, size = 16, cls = '' ) {
	const def = ICONS[ name ];
	if ( ! def ) {
		return '';
	}
	const [ box, ...paths ] = def;
	return (
		\`<svg class="ic \${ cls }" viewBox="\${ box }" width="\${ size }" height="\${ size }" ` +
		`fill="currentColor" aria-hidden="true" focusable="false">\` +
		paths.map( ( d ) => \`<path d="\${ d }" />\` ).join( '' ) +
		'</svg>'
	);
}

/**
 * همان آیکون، ولی به شکل یک المان آمادهٔ appendChild.
 *
 * @param {string} name
 * @param {number} [size]
 * @param {string} [cls]
 */
export function icon( name, size = 16, cls = '' ) {
	const span = document.createElement( 'span' );
	span.className = \`ico \${ cls }\`.trim();
	span.innerHTML = iconSvg( name, size );
	return span;
}
`;

fs.mkdirSync( path.dirname( OUT ), { recursive: true } );
fs.writeFileSync( OUT, out, 'utf8' );
console.log( `${ entries.length } آیکون نوشته شد در ${ path.relative( process.cwd(), OUT ) }` );
