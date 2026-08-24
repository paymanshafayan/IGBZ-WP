/**
 * پرووایدر آزمایشی — بدون شبکه و بدون کلید.
 *
 * دلیل وجودش جدی است: باید بتوان **کل چرخهٔ عامل** (پلن، فراخوانی ابزار، دروازهٔ تأیید،
 * نتیجه، خلاصه) را بدون هیچ کلید و اینترنتی اجرا و تست کرد. یک مدل واقعی برای آزمودنِ
 * خودِ ابزار لازم نیست.
 *
 * رفتارش عمداً قابل‌پیش‌بینی است: از متن کاربر یک نیت ساده درمی‌آورد و همان را
 * به‌صورت tool_call بیرون می‌دهد.
 */

import { textOf } from '../content.js';

/** @param {import('./types.js').ProviderConfig} _cfg */
export function createMockProvider( _cfg ) {
	return {
		id: 'mock',
		kind: /** @type {const} */ ( 'mock' ),

		async listModels() {
			return [ 'vira-mock-1' ];
		},

		/**
		 * @param {import('./types.js').ChatRequest} req
		 * @returns {AsyncGenerator<import('./types.js').StreamEvent>}
		 */
		async *stream( req ) {
			const lastUser = [ ...req.messages ].reverse().find( ( m ) => m.role === 'user' );
			const text = textOf( lastUser?.content || '' ).trim();

			// فقط ابزارهای «همین نوبت» مهم‌اند، نه کل تاریخچه — وگرنه بعد از اولین ابزار،
			// مدل آزمایشی تا آخر عمر نشست فکر می‌کند کارش تمام شده.
			const lastUserIndex = req.messages.map( ( m ) => m.role ).lastIndexOf( 'user' );
			const alreadyRan = req.messages.slice( lastUserIndex + 1 ).some( ( m ) => m.role === 'tool' );

			// دور دوم: نتیجهٔ ابزار آمده، پس فقط جمع‌بندی می‌کنیم.
			if ( alreadyRan ) {
				const lastTool = [ ...req.messages ].reverse().find( ( m ) => m.role === 'tool' );
				const out = textOf( lastTool?.content || '' );
				for ( const piece of chunk( `نتیجه را گرفتم. خلاصه‌اش این است:\n\n${ out.slice( 0, 800 ) }` ) ) {
					yield { type: 'text', text: piece };
					await sleep( 12 );
				}
				yield { type: 'usage', inputTokens: 0, outputTokens: 0 };
				return;
			}

			/** @type {{name:string,input:any}|null} */
			let call = null;

			if ( text.startsWith( '!' ) ) {
				call = { name: 'bash', input: { command: text.slice( 1 ).trim() } };
			} else if ( /^(ls|فهرست|لیست)(?:\s|$)/i.test( text ) ) {
				const path = text.replace( /^(ls|فهرست|لیست)\s*/i, '' ).trim() || '.';
				call = { name: 'list_dir', input: { path } };
			} else if ( /^(cat|بخوان|read)(?:\s|$)/i.test( text ) ) {
				const path = text.replace( /^(cat|بخوان|read)\s*/i, '' ).trim();
				call = { name: 'read_file', input: { path } };
			} else if ( /^(grep|جستجو)(?:\s|$)/i.test( text ) ) {
				const pattern = text.replace( /^(grep|جستجو)\s*/i, '' ).trim();
				call = { name: 'grep', input: { pattern } };
			} else if ( /^(write|بنویس)(?:\s|$)/i.test( text ) ) {
				const rest = text.replace( /^(write|بنویس)\s*/i, '' ).trim();
				const [ path, ...body ] = rest.split( / +/ );
				call = { name: 'write_file', input: { path: path || 'vira-test.txt', content: body.join( ' ' ) || 'سلام از ویرا' } };
			} else if ( /^(plan|نقشه)(?:\s|$)/i.test( text ) ) {
				const subject = text.replace( /^(plan|نقشه)\s*/i, '' ).trim() || 'کار خواسته‌شده';
				call = {
					name: 'exit_plan_mode',
					input: {
						plan: [
							`## نقشهٔ کار برای ${ subject }`,
							'',
							'1. فایل‌های مرتبط را می‌خوانم.',
							'2. تغییر را در یک فایل می‌زنم.',
							'3. تست را اجرا می‌کنم و نتیجه را گزارش می‌دهم.',
						].join( '\n' ),
					},
				};
			} else if ( /^(ask|بپرس)(?:\s|$)/i.test( text ) ) {
				call = {
					name: 'ask_user_question',
					input: {
						question: text.replace( /^(ask|بپرس)\s*/i, '' ).trim() || 'کدام راه را برویم؟',
						options: [
							{ label: 'راه سریع', description: 'کمتر تمیز، سریع‌تر' },
							{ label: 'راه تمیز', description: 'بیشتر طول می‌کشد ولی بدهی فنی نمی‌سازد' },
						],
						allow_other: true,
					},
				};
			} else if ( /^(bg|پس‌زمینه|پس زمینه)(?:\s|$)/i.test( text ) ) {
				call = {
					name: 'bash',
					input: { command: text.replace( /^(bg|پس‌زمینه|پس زمینه)\s*/i, '' ).trim() || 'sleep 30', background: true },
				};
			} else if ( /^(todo|کارها)(?:\s|$)/i.test( text ) ) {
				const items = text
					.replace( /^(todo|کارها)\s*/i, '' )
					.split( /[،,]/ )
					.map( ( s ) => s.trim() )
					.filter( Boolean );
				const list = items.length ? items : [ 'خواندن کد', 'زدن تغییر', 'اجرای تست' ];
				call = {
					name: 'todo_write',
					input: {
						todos: list.map( ( content, i ) => ( {
							content,
							status: i === 0 ? 'in_progress' : 'pending',
						} ) ),
					},
				};
			} else if ( /^(replace|جایگزین)(?:\s|$)/i.test( text ) ) {
				const [ , file, from, to ] = text.split( / +/ );
				call = {
					name: 'multi_edit',
					input: { path: file, edits: [ { old_string: from, new_string: to, replace_all: true } ] },
				};
			}

			if ( ! call ) {
				const reply =
					'سلام! من پرووایدر آزمایشی ویرا هستم و بدون اینترنت کار می‌کنم.\n\n' +
					'برای آزمودن چرخهٔ ابزار یکی از این‌ها را بنویس:\n' +
					'• `لیست .` — فهرست فایل‌ها\n' +
					'• `بخوان package.json` — خواندن فایل\n' +
					'• `جستجو vira` — جستجوی متن\n' +
					'• `!echo سلام` — اجرای فرمان (دروازهٔ تأیید را نشان می‌دهد)\n' +
					'• `بنویس note.txt متن` — نوشتن فایل (این هم تأیید می‌خواهد)\n' +
					'• `نقشه بازطراحی صفحهٔ سبد` — کارت تأیید نقشه\n' +
					'• `بپرس کدام راه؟` — کارت پرسش چندگزینه‌ای\n' +
					'• `کارها خواندن، نوشتن، تست` — فهرست کار زنده\n' +
					'• `پس‌زمینه sleep 60` — شل پس‌زمینه\n' +
					'• `جایگزین note.txt قدیم جدید` — ویرایش چندگانه با دیف\n\n' +
					'برای کار واقعی، از تنظیمات یک پرووایدر واقعی انتخاب کن.';
				for ( const piece of chunk( reply ) ) {
					yield { type: 'text', text: piece };
					await sleep( 10 );
				}
				yield { type: 'usage', inputTokens: 0, outputTokens: 0 };
				return;
			}

			for ( const piece of chunk( `باشد. برای این کار از ابزار \`${ call.name }\` استفاده می‌کنم.\n` ) ) {
				yield { type: 'text', text: piece };
				await sleep( 10 );
			}

			yield {
				type: 'tool_call',
				id: `mock_${ Date.now().toString( 36 ) }`,
				name: call.name,
				input: call.input,
			};
			yield { type: 'usage', inputTokens: 0, outputTokens: 0 };
		},
	};
}

/** @param {string} s */
function* chunk( s ) {
	const words = s.split( / / );
	for ( let i = 0; i < words.length; i += 3 ) {
		yield words.slice( i, i + 3 ).join( ' ' ) + ( i + 3 < words.length ? ' ' : '' );
	}
}

/** @param {number} ms */
function sleep( ms ) {
	return new Promise( ( r ) => setTimeout( r, ms ) );
}
