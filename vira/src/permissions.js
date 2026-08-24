/**
 * موتور مجوز — قلب «پلن بده، تأیید بگیر، اجرا کن».
 *
 * تصمیم کارفرما: توانایی ابزار حذف نمی‌شود؛ آنچه کنترل می‌شود دسترسی است. پس همهٔ
 * ابزارها همیشه وجود دارند و این لایه فقط می‌گوید: اجرا کن / بپرس / رد کن.
 *
 * سه حالت (مثل Claude Code):
 *   plan    — فقط خواندن و تحلیل؛ هر ابزار نویسنده/اجراکننده رد می‌شود.
 *   default — خواندنی‌ها آزاد، نویسنده/اجراکننده تأیید می‌خواهند.
 *   auto    — همه‌چیز آزاد جز آنچه صراحتاً در deny آمده.
 */

import { TOOLS } from './tools.js';

export const MODES = /** @type {const} */ ( [ 'plan', 'default', 'auto' ] );

/** جداکننده‌های فرمان مرکب در پوسته. */
const SEPARATORS = /\s*(?:&&|\|\||;|\||\n)\s*/;

/**
 * فرمان‌هایی که تنها نامشان چیزی نمی‌گوید: `git` هم `status` دارد هم `push --force`.
 * برای این‌ها، «فرمان پایه» دو کلمه است.
 */
const MULTI_WORD = [ 'git', 'npm', 'yarn', 'pnpm', 'bun', 'cargo', 'docker', 'kubectl', 'composer', 'php', 'wp', 'gh' ];

/**
 * الگوهایی که فرمان را پنهان می‌کنند: جانشینی فرمان و جایگزینی پروسه.
 * وقتی این‌ها در فرمان باشند، قاعدهٔ پیشوندی بی‌معنی است — چون آنچه اجرا می‌شود در متن
 * پیدا نیست.
 */
const HIDDEN = /\$\(|`|<\(|>\(/;

/**
 * شکستن فرمان مرکب به تکه‌های واقعی.
 *
 * چرا لازم است: قاعدهٔ `bash:git` روی رشتهٔ کامل، به `git status && rm -rf /` هم اجازه
 * می‌داد، چون رشته با «git» شروع می‌شود. این یک حفرهٔ واقعی بود و در بازبینی
 * sugyan/claude-code-webui دیدیم که آن‌ها همین را جدی گرفته‌اند.
 *
 * @param {string} command
 */
export function splitCommand( command ) {
	return String( command || '' )
		.split( SEPARATORS )
		.map( ( s ) => s.trim() )
		.filter( Boolean );
}

/**
 * «فرمان پایه»ی یک تکه — یک کلمه، یا دو کلمه برای فرمان‌های چندبخشی.
 * @param {string} part
 */
export function baseCommand( part ) {
	const words = String( part || '' ).trim().split( /\s+/ ).filter( Boolean );
	if ( ! words.length ) {
		return '';
	}
	if ( words.length >= 2 && MULTI_WORD.includes( words[ 0 ] ) ) {
		return `${ words[ 0 ] } ${ words[ 1 ] }`;
	}
	return words[ 0 ];
}

/**
 * قاعده‌هایی که دکمهٔ «همیشه اجازه بده» باید بسازد.
 *
 * برای فرمان مرکب، **یک قاعده به‌ازای هر تکه** — نه یک قاعده برای کل رشته. وگرنه کاربر
 * فکر می‌کند به `git` اجازه داده و در عمل به `rm` هم اجازه داده است.
 *
 * @param {string} toolName
 * @param {any} input
 * @returns {string[]}
 */
export function suggestRules( toolName, input ) {
	if ( toolName !== 'bash' ) {
		return [ toolName ];
	}
	const parts = splitCommand( input?.command );
	const bases = [ ...new Set( parts.map( baseCommand ).filter( Boolean ) ) ];
	return bases.length ? bases.map( ( b ) => `bash:${ b }` ) : [ 'bash' ];
}

/**
 * @param {string} toolName
 * @param {any} input
 * @param {{mode:string, allow?:string[], ask?:string[], deny?:string[]}} rules
 * @param {Record<string,any>} [registry] رجیستری کامل (ابزار داخلی + MCP + پلاگین)
 * @returns {{decision:'allow'|'ask'|'deny', reason?:string}}
 */
export function decide( toolName, input, rules, registry ) {
	const tool = ( registry || TOOLS )[ toolName ];
	if ( ! tool ) {
		return { decision: 'deny', reason: `ابزار ناشناخته: ${ toolName }` };
	}

	const deny = rules.deny || [];
	const allow = rules.allow || [];
	const ask = rules.ask || [];

	if ( matches( deny, toolName, input, 'any' ) ) {
		return { decision: 'deny', reason: 'در فهرست ممنوع است.' };
	}

	if ( rules.mode === 'plan' && tool.risk !== 'read' ) {
		return {
			decision: 'deny',
			reason: 'حالت «پلن» فعال است: در این حالت فقط بررسی و خواندن مجاز است.',
		};
	}

	if ( matches( allow, toolName, input ) ) {
		return { decision: 'allow' };
	}
	if ( matches( ask, toolName, input ) ) {
		return { decision: 'ask' };
	}

	if ( rules.mode === 'auto' ) {
		return { decision: 'allow' };
	}

	// پیش‌فرض: خواندن آزاد، بقیه با تأیید.
	return tool.risk === 'read' ? { decision: 'allow' } : { decision: 'ask' };
}

/**
 * قاعده‌ها می‌توانند نام ابزار باشند یا `tool:prefix`.
 * مثال: `bash:git ` یعنی هر فرمان bash که با «git » شروع شود.
 *
 * برای `bash` یک قاعدهٔ اضافه هست که در نگاه اول به چشم نمی‌آید ولی مهم است:
 * **هر تکهٔ فرمان مرکب باید جداگانه مجاز باشد.** وگرنه `git status && rm -rf /` با
 * قاعدهٔ `bash:git` رد می‌شود از دروازه.
 *
 * @param {string[]} list
 * @param {string} toolName
 * @param {any} input
 * @param {'all'|'any'} [mode] برای فرمان مرکب: همه باید بخورند، یا یکی کافی است
 */
function matches( list, toolName, input, mode = 'all' ) {
	if ( ! list?.length ) {
		return false;
	}

	// قاعدهٔ سراسری روی خود ابزار — تصمیم صریح کاربر است، دست‌نخورده می‌ماند.
	if ( list.includes( '*' ) || list.includes( toolName ) ) {
		return true;
	}

	if ( toolName === 'bash' ) {
		const command = String( input?.command ?? '' );

		// فرمانی که داخلش فرمان پنهان دارد، با قاعدهٔ پیشوندی مجاز نمی‌شود.
		if ( mode === 'all' && HIDDEN.test( command ) ) {
			return false;
		}

		const parts = splitCommand( command );
		if ( ! parts.length ) {
			return false;
		}
		const hit = ( part ) => list.some( ( rule ) => prefixHit( rule, toolName, part ) );
		return mode === 'all' ? parts.every( hit ) : parts.some( hit );
	}

	const subject = String( input?.command ?? input?.path ?? input?.url ?? '' );
	return list.some( ( rule ) => prefixHit( rule, toolName, subject ) );
}

/**
 * @param {string} rule
 * @param {string} toolName
 * @param {string} subject
 */
function prefixHit( rule, toolName, subject ) {
	const sep = rule.indexOf( ':' );
	if ( sep <= 0 || rule.slice( 0, sep ) !== toolName ) {
		return false;
	}
	return subject.startsWith( rule.slice( sep + 1 ) );
}

/** خلاصهٔ خوانا از یک فراخوانی ابزار، برای نمایش در دروازهٔ تأیید. */
export function describeCall( toolName, input ) {
	switch ( toolName ) {
		case 'bash':
			return input?.command || '';
		case 'write_file':
			return `نوشتن در ${ input?.path } (${ String( input?.content ?? '' ).length } نویسه)`;
		case 'edit_file':
			return `ویرایش ${ input?.path }`;
		case 'multi_edit':
			return `${ ( input?.edits || [] ).length } ویرایش روی ${ input?.path }`;
		case 'bash_output':
			return `خواندن خروجی شل ${ input?.shell_id }`;
		case 'kill_shell':
			return `توقف شل ${ input?.shell_id }`;
		case 'web_search':
			return `جستجوی وب: ${ input?.query || '' }`;
		case 'exit_plan_mode':
			return 'ارائهٔ نقشهٔ کار برای تأیید';
		case 'ask_user_question':
			return input?.question || 'پرسش از کاربر';
		case 'read_file':
			return `خواندن ${ input?.path }`;
		case 'list_dir':
			return `فهرست ${ input?.path || '.' }`;
		case 'glob':
			return `جستجوی فایل ${ input?.pattern }`;
		case 'grep':
			return `جستجوی متن «${ input?.pattern }»`;
		case 'web_fetch':
			return input?.url || '';
		case 'skill':
			return `باز کردن اسکیل «${ input?.name }»`;
		case 'task':
			return input?.description || String( input?.prompt || '' ).slice( 0, 80 );
		default:
			if ( toolName.startsWith( 'mcp__' ) ) {
				const [ , server, tool ] = toolName.split( '__' );
				return `${ server } → ${ tool }: ${ JSON.stringify( input ?? {} ).slice( 0, 160 ) }`;
			}
			return JSON.stringify( input ?? {} ).slice( 0, 200 );
	}
}
