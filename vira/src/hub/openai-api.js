/**
 * خروجی سازگار با OpenAI از هاب — تصمیم شمارهٔ ۸.
 *
 * دلیلش در سند این بود: منوس، مترجم و STT هم از همین هاب بخورند، تا **یک جای کلید،
 * یک جای هزینه، و یک جای سلامت** داشته باشیم. اگر هرکدام کلید خودشان را داشته باشند،
 * سه‌تا صورت‌حساب داریم که هیچ‌کدام با هم جمع نمی‌شوند.
 *
 * فقط روی لوکال‌هاست معنا دارد: طبق تصمیم استقرار، دسترسی از بیرون کاملاً ممنوع است و
 * پنل، خودش پراکسی می‌کند.
 */

/**
 * پاسخ `GET /v1/models` به شکل استاندارد.
 * @param {import('./index.js').Hub} hub
 */
export function modelsResponse( hub ) {
	const data = Object.values( hub.data.models || {} )
		.filter( ( m ) => m.enabled && ! m.missing )
		.map( ( m ) => ( {
			id: m.key,
			object: 'model',
			created: 0,
			owned_by: hub.data.connections?.[ m.connectionId ]?.label || 'vira',
			context_window: m.context || null,
			vira: { tags: m.tags, caps: m.caps },
		} ) );

	// نام‌های مجازی هم برگردانده می‌شوند تا مصرف‌کننده بتواند بدون دانستن مدل‌ها بنویسد
	// `model: "auto"` و مسیریابی را به هاب بسپارد.
	data.unshift( { id: 'auto', object: 'model', created: 0, owned_by: 'vira', vira: { virtual: true } } );
	for ( const combo of Object.values( hub.data.combos || {} ) ) {
		data.push( { id: `combo:${ combo.id }`, object: 'model', created: 0, owned_by: 'vira', vira: { virtual: true, label: combo.label } } );
	}

	return { object: 'list', data };
}

/**
 * تبدیل بدنهٔ OpenAI به درخواست داخلی.
 * @param {any} body
 */
export function toInternalRequest( body ) {
	/** @type {any[]} */
	const messages = [];
	let system = '';

	for ( const m of body?.messages || [] ) {
		if ( m.role === 'system' || m.role === 'developer' ) {
			system = system ? `${ system }\n${ contentText( m.content ) }` : contentText( m.content );
			continue;
		}
		if ( m.role === 'tool' ) {
			messages.push( { role: 'tool', toolCallId: m.tool_call_id, content: contentText( m.content ) } );
			continue;
		}
		if ( m.role === 'assistant' && Array.isArray( m.tool_calls ) && m.tool_calls.length ) {
			messages.push( {
				role: 'assistant',
				content: contentText( m.content ),
				toolCalls: m.tool_calls.map( ( c ) => ( {
					id: c.id,
					name: c.function?.name || '',
					input: safeJson( c.function?.arguments ),
				} ) ),
			} );
			continue;
		}
		messages.push( { role: m.role === 'assistant' ? 'assistant' : 'user', content: toContent( m.content ) } );
	}

	return {
		model: String( body?.model || 'auto' ),
		system,
		messages,
		tools: ( body?.tools || [] )
			.filter( ( t ) => t.type === 'function' && t.function?.name )
			.map( ( t ) => ( { name: t.function.name, description: t.function.description || '', parameters: t.function.parameters || { type: 'object' } } ) ),
		maxTokens: body?.max_tokens ?? body?.max_completion_tokens ?? undefined,
		temperature: body?.temperature,
	};
}

/**
 * اجرای یک درخواست سازگار با OpenAI روی هاب و نوشتن پاسخ.
 *
 * @param {import('./index.js').Hub} hub
 * @param {any} body
 * @param {import('node:http').ServerResponse} res
 */
export async function handleChatCompletions( hub, body, res ) {
	const req = toInternalRequest( body );
	const id = `chatcmpl-${ Math.random().toString( 36 ).slice( 2, 12 ) }`;
	const created = Math.floor( Date.now() / 1000 );
	const wantStream = Boolean( body?.stream );

	if ( ! wantStream ) {
		let text = '';
		/** @type {any[]} */
		const toolCalls = [];
		let usage = { inputTokens: 0, outputTokens: 0 };
		let error = null;

		for await ( const ev of hub.stream( req ) ) {
			if ( ev.type === 'text' ) {
				text += ev.text;
			} else if ( ev.type === 'tool_call' ) {
				toolCalls.push( ev );
			} else if ( ev.type === 'usage' ) {
				usage = { inputTokens: ev.inputTokens || 0, outputTokens: ev.outputTokens || 0 };
			} else if ( ev.type === 'error' ) {
				error = ev.error;
			}
		}

		if ( error ) {
			res.writeHead( 502, { 'Content-Type': 'application/json; charset=utf-8' } );
			res.end( JSON.stringify( { error: { message: error, type: 'hub_error' } } ) );
			return;
		}

		res.writeHead( 200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' } );
		res.end(
			JSON.stringify( {
				id,
				object: 'chat.completion',
				created,
				model: req.model,
				choices: [
					{
						index: 0,
						message: {
							role: 'assistant',
							content: text,
							...( toolCalls.length
								? {
										tool_calls: toolCalls.map( ( c ) => ( {
											id: c.id,
											type: 'function',
											function: { name: c.name, arguments: JSON.stringify( c.input ?? {} ) },
										} ) ),
								  }
								: {} ),
						},
						finish_reason: toolCalls.length ? 'tool_calls' : 'stop',
					},
				],
				usage: { prompt_tokens: usage.inputTokens, completion_tokens: usage.outputTokens, total_tokens: usage.inputTokens + usage.outputTokens },
			} )
		);
		return;
	}

	res.writeHead( 200, {
		'Content-Type': 'text/event-stream; charset=utf-8',
		'Cache-Control': 'no-cache',
		Connection: 'keep-alive',
		'X-Accel-Buffering': 'no',
	} );

	const chunk = ( delta, finish = null ) =>
		res.write( `data: ${ JSON.stringify( { id, object: 'chat.completion.chunk', created, model: req.model, choices: [ { index: 0, delta, finish_reason: finish } ] } ) }\n\n` );

	chunk( { role: 'assistant', content: '' } );

	let index = 0;
	for await ( const ev of hub.stream( req ) ) {
		if ( ev.type === 'text' ) {
			chunk( { content: ev.text } );
		} else if ( ev.type === 'thinking' ) {
			chunk( { reasoning_content: ev.text } );
		} else if ( ev.type === 'tool_call' ) {
			chunk( {
				tool_calls: [ { index: index++, id: ev.id, type: 'function', function: { name: ev.name, arguments: JSON.stringify( ev.input ?? {} ) } } ],
			} );
		} else if ( ev.type === 'error' ) {
			res.write( `data: ${ JSON.stringify( { error: { message: ev.error, type: 'hub_error' } } ) }\n\n` );
		}
	}

	chunk( {}, 'stop' );
	res.write( 'data: [DONE]\n\n' );
	res.end();
}

function contentText( content ) {
	if ( typeof content === 'string' ) {
		return content;
	}
	if ( Array.isArray( content ) ) {
		return content.map( ( p ) => ( typeof p === 'string' ? p : p.text || '' ) ).join( '' );
	}
	return '';
}

function toContent( content ) {
	if ( ! Array.isArray( content ) ) {
		return typeof content === 'string' ? content : contentText( content );
	}
	return content.map( ( part ) => {
		if ( part?.type === 'image_url' ) {
			const url = String( part.image_url?.url || '' );
			const m = /^data:([^;]+);base64,(.*)$/.exec( url );
			return m ? { type: 'image', mediaType: m[ 1 ], data: m[ 2 ] } : { type: 'text', text: url };
		}
		return { type: 'text', text: part?.text || '' };
	} );
}

function safeJson( text ) {
	try {
		return text ? JSON.parse( text ) : {};
	} catch {
		return { __raw: text };
	}
}
