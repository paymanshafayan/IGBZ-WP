/**
 * زیرعامل‌ها و فشرده‌سازی کانتکست — دو چیزی که یک عامل را از «اسباب‌بازی» جدا می‌کنند.
 *
 * زیرعامل: یک نشست تازه و جدا، با پرامپت و بودجهٔ خودش، که فقط **خلاصهٔ نتیجه** را
 * برمی‌گرداند. فایده‌اش این است که کاوش‌های طولانی (خواندن ده فایل برای یک جواب کوتاه)
 * کانتکست نشست اصلی را خراب نکنند.
 *
 * فشرده‌سازی: وقتی گفتگو بلند شد، پیام‌های قدیمی جایشان را به یک خلاصه می‌دهند. بدون این،
 * هر نشست طولانی یا می‌ترکد یا گران تمام می‌شود.
 */

import { textOf } from './content.js';

/** رویدادهایی که فقط به عاملِ اصلی تعلق دارند و نباید از زیرعامل بیرون بروند. */
const LIFECYCLE = new Set( [ 'idle', 'user', 'assistant_start', 'assistant_end' ] );

/**
 * ابزار `task` — اجرای یک زیرعامل.
 *
 * زیرعامل می‌تواند «نوع» داشته باشد: یکی از عامل‌هایی که کاربر در `~/.vira/agents/*.md`
 * تعریف کرده. آن وقت پرامپت سیستمی و مدل و ابزارهای مجاز از همان تعریف می‌آید.
 *
 * @param {{
 *   makeAgent: (opts:{systemPrompt:string, maxSteps:number, emit:(ev:any)=>void, model?:string, allowedTools?:string[]}) => any,
 *   emit: (ev:any) => void,
 *   getAgents?: () => import('./agents.js').AgentDef[],
 * }} deps
 */
export function makeTaskTool( deps ) {
	const defs = () => ( deps.getAgents ? deps.getAgents() : [] );
	const known = defs()
		.map( ( a ) => `${ a.name }: ${ a.description }` )
		.join( ' · ' );

	return {
		risk: /** @type {const} */ ( 'read' ),
		spec: {
			name: 'task',
			description:
				'اجرای یک کار جداگانه توسط یک زیرعامل با کانتکست تازه. برای کاوش‌های طولانی که فقط جوابِ کوتاهش لازم است. زیرعامل همان ابزارها را دارد و همان قواعد مجوز رویش اعمال می‌شود.' +
				( known ? ` عامل‌های تعریف‌شده: ${ known }` : '' ),
			parameters: {
				type: 'object',
				properties: {
					description: { type: 'string', description: 'یک خط توضیح کار، برای نمایش به کاربر' },
					prompt: { type: 'string', description: 'شرح کامل کار برای زیرعامل' },
					subagent_type: { type: 'string', description: 'نام یکی از عامل‌های تعریف‌شده؛ اختیاری' },
					max_steps: { type: 'integer', description: 'سقف گام؛ پیش‌فرض ۱۲' },
				},
				required: [ 'prompt' ],
			},
		},

		/** @param {{description?:string, prompt:string, subagent_type?:string, max_steps?:number}} input */
		async run( input ) {
			const def = input.subagent_type ? defs().find( ( a ) => a.name === input.subagent_type ) : null;
			if ( input.subagent_type && ! def ) {
				const names = defs().map( ( a ) => a.name ).join( '، ' ) || '(هیچ عاملی تعریف نشده)';
				throw new Error( `عاملی به نام «${ input.subagent_type }» تعریف نشده. عامل‌های موجود: ${ names }` );
			}

			const label = input.description || def?.name || input.prompt.slice( 0, 60 );
			deps.emit( { type: 'subagent_start', label, agent: def?.name } );

			const sub = deps.makeAgent( {
				systemPrompt:
					def?.prompt ||
					'تو یک زیرعامل هستی. کار خواسته‌شده را انجام بده و در پایان **فقط نتیجه** را کوتاه و دقیق گزارش کن. ' +
						'از پرسیدن سؤال بپرهیز؛ اگر چیزی مبهم بود، فرض معقول بگیر و فرضت را بنویس.',
				model: def?.model,
				allowedTools: def?.tools,
				maxSteps: Math.min( input.max_steps || 12, 20 ),
				emit: ( ev ) => {
					// رویدادهای چرخهٔ عمرِ زیرعامل بیرون نمی‌روند. اگر `idle` زیرعامل پخش شود،
					// رابط کاربری فکر می‌کند کل کار تمام شده و دکمهٔ توقف را برمی‌دارد — این
					// را در آزمایش زنده دیدیم.
					if ( LIFECYCLE.has( ev.type ) ) {
						return;
					}
					deps.emit( { ...ev, sub: label } );
				},
			} );

			await sub.run( input.prompt );

			const last = [ ...sub.messages ].reverse().find( ( m ) => m.role === 'assistant' && textOf( m.content ).trim() );
			deps.emit( { type: 'subagent_end', label, agent: def?.name } );

			return textOf( last?.content || '' ).trim() || '(زیرعامل خروجی متنی نداد)';
		},
	};
}

/**
 * آیا وقت فشرده‌سازی است؟ معیار، حجم تقریبی کاراکترهای گفتگوست — ساده و کافی.
 *
 * @param {import('./providers/types.js').Message[]} messages
 * @param {number} threshold
 */
export function shouldCompact( messages, threshold = 120_000 ) {
	let size = 0;
	for ( const m of messages ) {
		size += textOf( m.content || '' ).length;
		for ( const c of m.toolCalls || [] ) {
			size += JSON.stringify( c.input || {} ).length;
		}
	}
	return size > threshold;
}

/**
 * فشرده‌سازی: چند پیام آخر دست‌نخورده می‌مانند، بقیه به یک خلاصه تبدیل می‌شوند.
 *
 * @param {{provider:any, model:string, messages:import('./providers/types.js').Message[], keep?:number}} opts
 * @returns {Promise<import('./providers/types.js').Message[]>}
 */
export async function compact( { provider, model, messages, keep = 6 } ) {
	if ( messages.length <= keep + 2 ) {
		return messages;
	}

	const older = messages.slice( 0, -keep );
	const recent = messages.slice( -keep );

	const transcript = older
		.map( ( m ) => {
			if ( m.role === 'tool' ) {
				return `[نتیجهٔ ابزار] ${ textOf( m.content || '' ).slice( 0, 600 ) }`;
			}
			const calls = ( m.toolCalls || [] ).map( ( c ) => `${ c.name }(${ JSON.stringify( c.input ).slice( 0, 200 ) })` );
			return `[${ m.role }] ${ textOf( m.content || '' ).slice( 0, 1200 ) }${ calls.length ? `\n  ابزارها: ${ calls.join( ', ' ) }` : '' }`;
		} )
		.join( '\n' );

	let summary = '';
	const stream = provider.stream( {
		model,
		system:
			'گفتگوی زیر را برای ادامهٔ کار خلاصه کن. آنچه باید بماند: هدف کاربر، تصمیم‌های گرفته‌شده، ' +
			'فایل‌ها و مسیرهای مهم، کارهای انجام‌شده، و کارهای باقی‌مانده. کوتاه ولی بدون حذف چیزی که برای ادامه لازم است.',
		messages: [ { role: 'user', content: transcript } ],
		maxTokens: 2000,
	} );

	for await ( const ev of stream ) {
		if ( ev.type === 'text' ) {
			summary += ev.text;
		} else if ( ev.type === 'error' ) {
			// اگر خلاصه‌سازی شکست خورد، گفتگو را دست‌نخورده نگه می‌داریم — بدتر از گفتگوی
			// بلند، گفتگوی نصفه‌ونیمه است.
			return messages;
		}
	}

	if ( ! summary.trim() ) {
		return messages;
	}

	/** @type {import('./providers/types.js').Message} */
	const digest = {
		role: 'user',
		content: `خلاصهٔ بخش‌های قبلی این گفتگو:\n\n${ summary.trim() }`,
	};

	// پیام اول بعد از خلاصه نباید «نتیجهٔ ابزار» بی‌صاحب باشد.
	const trimmed = [ ...recent ];
	while ( trimmed.length && trimmed[ 0 ].role === 'tool' ) {
		trimmed.shift();
	}

	return [ digest, ...trimmed ];
}
