/**
 * حلقهٔ عامل ویرا.
 *
 * الگو همان چیزی است که در عمل جواب داده: مدل حرف می‌زند و ابزار می‌خواهد → هوک و دروازهٔ
 * مجوز → اجرای ابزار → نتیجه برمی‌گردد به مدل → تکرار تا وقتی مدل دیگر ابزاری نخواهد.
 *
 * چند تصمیم که عمدی‌اند:
 *   ۱) نتیجهٔ ابزار **همیشه** به مدل برمی‌گردد، حتی وقتی رد شده — وگرنه مدل نمی‌فهمد چرا
 *      کارش پیش نرفت و همان درخواست را تکرار می‌کند.
 *   ۲) سقف گام دارد. یک عامل بی‌سقف، یک قبض بی‌سقف است.
 *   ۳) رجیستری ابزار **پویاست** (تابع است، نه فهرست ثابت)، چون MCP و پلاگین‌ها وسط کار
 *      ابزار اضافه و کم می‌کنند.
 */

import { decide, describeCall, suggestRules } from './permissions.js';
import { buildContent, textOf } from './content.js';
import { shouldCompact, compact } from './subagent.js';
import { explain } from './errors.js';

const DEFAULT_MAX_STEPS = 24;

export class Agent {
	/**
	 * @param {{
	 *   provider: any,
	 *   model: string,
	 *   workspace: string,
	 *   rules: {mode:string, allow?:string[], ask?:string[], deny?:string[]},
	 *   getTools: () => Record<string, any>,
	 *   systemPrompt?: string,
	 *   extraPrompt?: string,
	 *   maxSteps?: number,
	 *   hooks?: import('./hooks.js').HookRunner,
	 *   autoCompact?: boolean,
	 *   emit: (ev: any) => void,
	 * }} opts
	 */
	constructor( opts ) {
		this.provider = opts.provider;
		this.model = opts.model;
		this.workspace = opts.workspace;
		this.rules = opts.rules;
		this.getTools = opts.getTools;
		this.baseSystemPrompt = opts.systemPrompt || defaultSystemPrompt( opts.workspace );
		this.extraPrompt = opts.extraPrompt || '';
		this.maxSteps = opts.maxSteps || DEFAULT_MAX_STEPS;
		this.hooks = opts.hooks || null;
		this.baseUrl = opts.baseUrl || '';
		this.autoCompact = opts.autoCompact !== false;
		this.emit = opts.emit;
		this.checkpoints = opts.checkpoints || null;
		this.sandbox = opts.sandbox || null;
		this.onTurnEnd = opts.onTurnEnd || null;

		/** @type {import('./providers/types.js').Message[]} */
		this.messages = [];
		/** @type {Map<string,(d:'allow'|'deny')=>void>} */
		this.pending = new Map();
		/** @type {Map<string,(value:any)=>void>} */
		this.questions = new Map();
		this.busy = false;
		/** @type {AbortController|null} */
		this.controller = null;
		this.usage = { inputTokens: 0, outputTokens: 0 };
	}

	get systemPrompt() {
		return [ this.baseSystemPrompt, this.extraPrompt ].filter( Boolean ).join( '\n' );
	}

	/** پاسخ کاربر به یک دروازهٔ تأیید. */
	resolvePermission( id, decision ) {
		const fn = this.pending.get( id );
		if ( fn ) {
			this.pending.delete( id );
			fn( decision === 'allow' ? 'allow' : 'deny' );
			return true;
		}
		return false;
	}

	/** پاسخ کاربر به یک پرسش ابزار (سؤال چندگزینه‌ای یا تأیید نقشه). */
	resolveQuestion( id, value ) {
		const fn = this.questions.get( id );
		if ( fn ) {
			this.questions.delete( id );
			fn( value );
			return true;
		}
		return false;
	}

	stop() {
		this.controller?.abort();
		for ( const [ id, fn ] of this.pending ) {
			this.pending.delete( id );
			fn( 'deny' );
		}
		for ( const [ id, fn ] of this.questions ) {
			this.questions.delete( id );
			fn( null );
		}
	}

	/** فشرده‌سازی دستی (دستور /compact). */
	async compactNow() {
		const before = this.messages.length;
		this.messages = await compact( {
			provider: this.provider,
			model: this.model,
			messages: this.messages,
		} );
		this.emit( { type: 'compacted', before, after: this.messages.length } );
		return { before, after: this.messages.length };
	}

	/**
	 * @param {string} text
	 * @param {{images?:{name?:string,mediaType:string,data:string}[]}} [opts]
	 */
	async run( text, opts = {} ) {
		if ( this.busy ) {
			throw new Error( 'یک درخواست در حال اجراست.' );
		}
		this.busy = true;
		this.controller = new AbortController();

		try {
			if ( this.hooks ) {
				const res = await this.hooks.run( 'UserPromptSubmit', { prompt: text } );
				if ( res.blocked ) {
					this.emit( { type: 'notice', text: `هوک جلوی این پیام را گرفت: ${ res.reason }` } );
					return;
				}
				if ( res.context.length ) {
					text = `${ text }\n\n[کانتکست از هوک]\n${ res.context.join( '\n' ) }`;
				}
			}

			const content = buildContent( text, opts.images );
			this.messages.push( { role: 'user', content } );
			this.emit( { type: 'user', text, images: ( opts.images || [] ).map( ( i ) => ( { name: i.name, mediaType: i.mediaType } ) ) } );

			if ( this.autoCompact && shouldCompact( this.messages ) ) {
				this.emit( { type: 'notice', text: 'گفتگو طولانی شد؛ خلاصه‌اش می‌کنم.' } );
				await this.compactNow();
			}

			for ( let step = 0; step < this.maxSteps; step++ ) {
				const turn = await this.#oneTurn();
				if ( ! turn.toolCalls.length ) {
					break;
				}
				if ( step === this.maxSteps - 1 ) {
					this.emit( { type: 'notice', text: `به سقف ${ this.maxSteps } گام رسیدیم و متوقف شدم.` } );
				}
			}

			await this.hooks?.run( 'Stop', {} );
			await this.onTurnEnd?.( { usage: this.usage, model: this.model } );
		} catch ( e ) {
			// خطای خام مدل به‌درد کاربر نمی‌خورد؛ ترجمه‌اش می‌کنیم.
			const info = explain( e, { baseUrl: this.baseUrl, model: this.model } );
			this.emit( { type: 'error', error: info.message, hint: info.hint, kind: info.kind } );
		} finally {
			this.busy = false;
			this.controller = null;
			this.emit( { type: 'idle', usage: this.usage } );
		}
	}

	async #oneTurn() {
		this.emit( { type: 'assistant_start' } );

		let text = '';
		/** @type {import('./providers/types.js').ToolCall[]} */
		const toolCalls = [];

		const tools = this.getTools();
		const specs = Object.values( tools ).map( ( t ) => t.spec );

		const stream = this.provider.stream( {
			model: this.model,
			system: this.systemPrompt,
			messages: this.messages,
			tools: specs,
			signal: this.controller?.signal,
		} );

		for await ( const ev of stream ) {
			if ( ev.type === 'text' ) {
				text += ev.text;
				this.emit( { type: 'text', text: ev.text } );
			} else if ( ev.type === 'thinking' ) {
				this.emit( { type: 'thinking', text: ev.text } );
			} else if ( ev.type === 'tool_call' ) {
				toolCalls.push( { id: ev.id, name: ev.name, input: ev.input } );
			} else if ( ev.type === 'usage' ) {
				this.usage.inputTokens += ev.inputTokens || 0;
				this.usage.outputTokens += ev.outputTokens || 0;
			} else if ( ev.type === 'error' ) {
				throw new Error( ev.error );
			}
		}

		this.messages.push( {
			role: 'assistant',
			content: text,
			...( toolCalls.length ? { toolCalls } : {} ),
		} );
		this.emit( { type: 'assistant_end', text, toolCalls } );

		/**
		 * اجرای ابزارها.
		 *
		 * ابزارهای «فقط خواندنی» در یک نوبت با هم اجرا می‌شوند — وقتی مدل پنج فایل را
		 * هم‌زمان می‌خواهد، صف‌کردنشان فقط وقت تلف‌کردن است. هر چیزی که می‌نویسد یا اجرا
		 * می‌کند، ترتیبی می‌ماند: هم چون ممکن است روی هم اثر بگذارند، هم چون دروازهٔ تأیید
		 * نباید چند پنجره را با هم جلوی کاربر بگذارد.
		 *
		 * نتیجه‌ها به همان ترتیبِ درخواستِ مدل برمی‌گردند، وگرنه مدل گیج می‌شود.
		 */
		const results = new Array( toolCalls.length );
		let i = 0;
		while ( i < toolCalls.length ) {
			const call = toolCalls[ i ];
			const safe = tools[ call.name ]?.risk === 'read';

			if ( ! safe ) {
				results[ i ] = await this.#runTool( call, tools );
				i++;
				continue;
			}

			let j = i;
			while ( j < toolCalls.length && tools[ toolCalls[ j ].name ]?.risk === 'read' ) {
				j++;
			}
			const batch = toolCalls.slice( i, j );
			if ( batch.length > 1 ) {
				this.emit( { type: 'parallel', count: batch.length, names: batch.map( ( c ) => c.name ) } );
			}
			const out = await Promise.all( batch.map( ( c ) => this.#runTool( c, tools ) ) );
			out.forEach( ( value, k ) => {
				results[ i + k ] = value;
			} );
			i = j;
		}

		toolCalls.forEach( ( call, index ) => {
			this.messages.push( { role: 'tool', toolCallId: call.id, content: results[ index ] } );
		} );

		return { text, toolCalls };
	}

	/**
	 * @param {import('./providers/types.js').ToolCall} call
	 * @param {Record<string,any>} tools
	 */
	async #runTool( call, tools ) {
		const tool = tools[ call.name ];
		const summary = describeCall( call.name, call.input );

		if ( ! tool ) {
			this.emit( { type: 'tool_error', id: call.id, name: call.name, error: 'ابزار ناشناخته' } );
			return `ابزار «${ call.name }» وجود ندارد. ابزارهای موجود: ${ Object.keys( tools ).join( ', ' ) }`;
		}

		const verdict = decide( call.name, call.input, this.rules, tools );

		if ( verdict.decision === 'deny' ) {
			this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: verdict.reason } );
			return `اجرا نشد. ${ verdict.reason || 'اجازه داده نشد.' }`;
		}

		// هوک PreToolUse حتی جلوی ابزار «مجاز» را هم می‌تواند بگیرد — این نقطه، جای
		// سیاست‌های سازمانی است.
		if ( this.hooks ) {
			const res = await this.hooks.run( 'PreToolUse', { tool: call.name, input: call.input, summary } );
			if ( res.blocked ) {
				this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: res.reason } );
				return `اجرا نشد. هوک جلویش را گرفت: ${ res.reason }`;
			}
		}

		if ( verdict.decision === 'ask' ) {
			this.emit( {
				type: 'permission_request',
				id: call.id,
				name: call.name,
				summary,
				input: call.input,
				// قاعده‌ها را همین‌جا می‌سازیم تا رابط کاربری مجبور نباشد منطق پوسته را
				// دوباره پیاده کند — و دو جا از هم جدا نیفتند.
				rules: suggestRules( call.name, call.input ),
			} );
			const answer = await new Promise( ( resolve ) => this.pending.set( call.id, resolve ) );
			if ( answer !== 'allow' ) {
				this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: 'کاربر رد کرد.' } );
				return 'کاربر اجازهٔ این کار را نداد. کار دیگری پیشنهاد بده یا دلیل بخواه.';
			}
		}

		this.emit( { type: 'tool_start', id: call.id, name: call.name, summary, input: call.input } );

		try {
			const out = await tool.run( call.input || {}, {
				workspace: this.workspace,
				log: ( t ) => this.emit( { type: 'tool_log', id: call.id, text: t } ),
				snapshot: ( p ) => this.checkpoints?.recordFile( p ),
				ask: ( payload ) => this.#askUser( payload ),
				sandbox: this.sandbox,
			} );
			this.emit( { type: 'tool_result', id: call.id, name: call.name, output: out } );
			await this.hooks?.run( 'PostToolUse', { tool: call.name, input: call.input, output: out } );
			return out;
		} catch ( e ) {
			const msg = e?.message || String( e );
			this.emit( { type: 'tool_error', id: call.id, name: call.name, error: msg } );
			return `خطا در اجرای ابزار: ${ msg }`;
		}
	}

	/**
	 * پرسیدن از کاربر از داخل یک ابزار، و منتظر ماندن.
	 * @param {any} payload
	 */
	#askUser( payload ) {
		const id = `q_${ Date.now().toString( 36 ) }_${ this.questions.size }`;
		this.emit( { type: 'ask_user', id, ...payload } );
		return new Promise( ( resolve ) => this.questions.set( id, resolve ) );
	}
}

/** @param {string} workspace */
export function defaultSystemPrompt( workspace ) {
	return [
		'تو «ویرا» هستی: یک دستیار عامل که روی دستگاه خود کاربر اجرا می‌شود و ابزار واقعی در اختیار دارد.',
		'',
		`پوشهٔ کاری: ${ workspace }`,
		'',
		'قواعد کار:',
		'- به فارسی جواب بده مگر کاربر زبان دیگری بخواهد.',
		'- قبل از حدس‌زدن، با ابزارها واقعیت را ببین (list_dir، read_file، grep).',
		'- کار چندمرحله‌ای را با todo_write ثبت کن تا چیزی گم نشود.',
		'- برای کاوش‌های طولانی که فقط جوابِ کوتاهش لازم است، از ابزار task استفاده کن.',
		'- برای تغییر فایل از edit_file استفاده کن، نه بازنویسی کامل، مگر فایل تازه باشد.',
		'- چند تغییر روی یک فایل را با multi_edit یک‌جا بزن تا فایل نیمه‌کاره نماند.',
		'- فرمان طولانی (سرور، watcher) را با background=true اجرا کن و بعد با bash_output دنبالش کن.',
		'- در حالت «پلن» هیچ چیزی را تغییر نده؛ نقشه را بنویس و با exit_plan_mode تأیید بگیر.',
		'- وقتی تصمیم به سلیقه یا اطلاعات کاربر بستگی دارد، با ask_user_question بپرس؛ حدس نزن.',
		'- هر فرمان مخرب یا پرریسک را اول توضیح بده؛ کاربر باید بفهمد چه چیزی را تأیید می‌کند.',
		'- اگر کاربر اجازه نداد، اصرار نکن؛ راه دیگری پیشنهاد بده.',
		'- کوتاه و دقیق بنویس. چیزی را که ندیده‌ای، ادعا نکن.',
	].join( '\n' );
}
