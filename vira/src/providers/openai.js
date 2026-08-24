/**
 * آداپتور سازگار با OpenAI: POST {baseUrl}/chat/completions با stream=true.
 *
 * همان قراردادی که تقریباً همهٔ سرویس‌دهنده‌ها (OpenRouter، DeepSeek، Groq، Ollama،
 * LM Studio، سرویس‌های ایرانی و…) پیاده کرده‌اند. هیچ SDK ای لازم نیست؛ fetch خود Node کافی است.
 */

import { sseLines } from './sse.js';
import { buildHeaders, authedUrl, finalizePayload, backoff, reshapeMessages } from './wire.js';
import { proxyFetch } from '../net.js';

/** @param {import('./types.js').ProviderConfig} cfg */
export function createOpenAiProvider( cfg ) {
	const base = ( cfg.baseUrl || '' ).replace( /\/+$/, '' );
	const overrides = cfg.overrides || {};

	// OpenRouter این دو را برای شناسایی برنامه می‌خواهد (اختیاری ولی مؤدبانه).
	const headers = buildHeaders( cfg, {
		'HTTP-Referer': 'https://github.com/paymanshafayan/IGBZ-WP',
		'X-Title': 'Vira',
	} );

	return {
		id: cfg.providerId,
		kind: /** @type {const} */ ( 'openai' ),

		async listModels() {
			const path = cfg.modelsPath || '/models';
			const res = await proxyFetch( authedUrl( `${ base }${ path.startsWith( '/' ) ? path : `/${ path }` }`, cfg ), { headers }, cfg.proxy );
			if ( ! res.ok ) {
				throw new Error( `فهرست مدل‌ها گرفته نشد (${ res.status })` );
			}
			const body = await res.json();
			const rows = Array.isArray( body?.data ) ? body.data : Array.isArray( body ) ? body : [];
			return rows.map( ( m ) => String( m.id || m.name || '' ) ).filter( Boolean ).sort();
		},

		/**
		 * @param {import('./types.js').ChatRequest} req
		 * @returns {AsyncGenerator<import('./types.js').StreamEvent>}
		 */
		async *stream( req ) {
			// وصلهٔ «عقب‌نشینی و تکرار» اینجا اثر می‌کند، قبل از اینکه دست به شبکه ببریم.
			await backoff( overrides, req.signal );

			const shaped = reshapeMessages( req.messages, req.system || '', overrides.reshape );

			/** @type {any[]} */
			const messages = [];
			if ( shaped.system ) {
				messages.push( { role: 'system', content: shaped.system } );
			}
			for ( const m of shaped.messages ) {
				if ( m.role === 'tool' ) {
					messages.push( {
						role: 'tool',
						tool_call_id: m.toolCallId,
						content: typeof m.content === 'string' ? m.content : JSON.stringify( m.content ),
					} );
					continue;
				}
				if ( m.role === 'assistant' && m.toolCalls?.length ) {
					messages.push( {
						role: 'assistant',
						content: m.content || null,
						tool_calls: m.toolCalls.map( ( c ) => ( {
							id: c.id,
							type: 'function',
							function: { name: c.name, arguments: JSON.stringify( c.input ?? {} ) },
						} ) ),
					} );
					continue;
				}
				messages.push( { role: m.role, content: toOpenAiContent( m.content ) } );
			}

			const payload = finalizePayload( {
				model: req.model,
				messages,
				stream: ! overrides.noStream,
				...( req.temperature !== undefined ? { temperature: req.temperature } : {} ),
				...( req.maxTokens ? { max_tokens: req.maxTokens } : {} ),
				...( req.tools?.length
					? {
							tools: req.tools.map( ( t ) => ( {
								type: 'function',
								function: {
									name: t.name,
									description: t.description,
									parameters: t.parameters,
								},
							} ) ),
							tool_choice: 'auto',
					  }
					: {} ),
			}, overrides );

			const res = await proxyFetch( authedUrl( `${ base }/chat/completions`, cfg ), {
				method: 'POST',
				headers,
				body: JSON.stringify( payload ),
				signal: req.signal,
			}, cfg.proxy );

			if ( ! res.ok || ! res.body ) {
				const text = await res.text().catch( () => '' );
				yield { type: 'error', error: `پاسخ ${ res.status } از پرووایدر: ${ text.slice( 0, 500 ) }` };
				return;
			}

			// سرویسی که استریم ندارد، کل پاسخ را یک‌جا می‌دهد. وصلهٔ `disable_stream`
			// همین مسیر را روشن می‌کند تا لازم نباشد پرووایدر را کنار بگذاریم.
			if ( overrides.noStream ) {
				const body = await res.json().catch( () => null );
				const choice = body?.choices?.[ 0 ]?.message;
				if ( choice?.content ) {
					yield { type: 'text', text: String( choice.content ) };
				}
				for ( const call of choice?.tool_calls || [] ) {
					let input = {};
					try {
						input = call.function?.arguments ? JSON.parse( call.function.arguments ) : {};
					} catch {
						input = { __raw: call.function?.arguments };
					}
					yield { type: 'tool_call', id: call.id || `call_${ Math.random().toString( 36 ).slice( 2, 10 ) }`, name: call.function?.name || '', input };
				}
				if ( body?.usage ) {
					yield { type: 'usage', inputTokens: body.usage.prompt_tokens ?? 0, outputTokens: body.usage.completion_tokens ?? 0 };
				}
				return;
			}

			/** @type {Map<number,{id:string,name:string,args:string}>} */
			const pending = new Map();
			let usage = null;

			for await ( const data of sseLines( res.body ) ) {
				if ( data === '[DONE]' ) {
					break;
				}
				let chunk;
				try {
					chunk = JSON.parse( data );
				} catch {
					continue;
				}
				if ( chunk.usage ) {
					usage = chunk.usage;
				}
				const delta = chunk.choices?.[ 0 ]?.delta;
				if ( ! delta ) {
					continue;
				}
				if ( delta.content ) {
					yield { type: 'text', text: delta.content };
				}
				// مدل‌های استدلالی (o-series، DeepSeek-R1، …) فکرشان را جدا می‌فرستند.
				const thought = delta.reasoning_content ?? delta.reasoning;
				if ( typeof thought === 'string' && thought ) {
					yield { type: 'thinking', text: thought };
				}
				for ( const call of delta.tool_calls || [] ) {
					const idx = call.index ?? 0;
					const cur = pending.get( idx ) || { id: '', name: '', args: '' };
					if ( call.id ) {
						cur.id = call.id;
					}
					if ( call.function?.name ) {
						cur.name = call.function.name;
					}
					if ( call.function?.arguments ) {
						cur.args += call.function.arguments;
					}
					pending.set( idx, cur );
				}
			}

			for ( const [ , call ] of [ ...pending.entries() ].sort( ( a, b ) => a[ 0 ] - b[ 0 ] ) ) {
				if ( ! call.name ) {
					continue;
				}
				let input = {};
				try {
					input = call.args ? JSON.parse( call.args ) : {};
				} catch {
					input = { __raw: call.args };
				}
				yield {
					type: 'tool_call',
					id: call.id || `call_${ Math.random().toString( 36 ).slice( 2, 10 ) }`,
					name: call.name,
					input,
				};
			}

			if ( usage ) {
				yield {
					type: 'usage',
					inputTokens: usage.prompt_tokens ?? 0,
					outputTokens: usage.completion_tokens ?? 0,
				};
			}
		},
	};
}

/**
 * محتوای پیام کاربر به شکل OpenAI. تصویر به data-URL تبدیل می‌شود.
 * @param {any} content
 */
function toOpenAiContent( content ) {
	if ( ! Array.isArray( content ) ) {
		return content;
	}
	return content.map( ( part ) =>
		part.type === 'image'
			? { type: 'image_url', image_url: { url: `data:${ part.mediaType };base64,${ part.data }` } }
			: { type: 'text', text: part.text || '' }
	);
}
