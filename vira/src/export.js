/**
 * خروجی‌گرفتن از گفتگو — مارک‌داون برای آدم، JSON برای ماشین.
 * معادل `/export` در Claude Code.
 */

/**
 * @param {{sessionId:string, transcript:any[], messages:any[], model?:string, workspace?:string}} data
 */
export function toMarkdown( data ) {
	const lines = [
		`# گفتگوی ویرا — ${ data.sessionId }`,
		'',
		`- تاریخ خروجی: ${ new Date().toLocaleString( 'fa-IR' ) }`,
		...( data.model ? [ `- مدل: ${ data.model }` ] : [] ),
		...( data.workspace ? [ `- پوشهٔ کاری: ${ data.workspace }` ] : [] ),
		'',
		'---',
		'',
	];

	for ( const ev of data.transcript || [] ) {
		switch ( ev.type ) {
			case 'user':
				lines.push( `## 🧑 کاربر`, '', ev.text || '', '' );
				break;
			case 'assistant_end':
				if ( String( ev.text || '' ).trim() ) {
					lines.push( `## 🤖 ویرا`, '', ev.text, '' );
				}
				break;
			case 'system':
				lines.push( `> ${ String( ev.text || '' ).replace( /\n/g, '\n> ' ) }`, '' );
				break;
			case 'tool_start':
				lines.push( `### ⚒ ${ ev.name }`, '', '```', String( ev.summary || '' ), '```', '' );
				break;
			case 'tool_result':
				lines.push( '<details><summary>خروجی ابزار</summary>', '', '```', clip( ev.output ), '```', '', '</details>', '' );
				break;
			case 'tool_error':
				lines.push( `**خطای ابزار:** ${ ev.error }`, '' );
				break;
			case 'tool_denied':
				lines.push( `**رد شد:** ${ ev.summary || ev.name } — ${ ev.reason || '' }`, '' );
				break;
			default:
				break;
		}
	}

	return lines.join( '\n' );
}

/** @param {any} data */
export function toJson( data ) {
	return JSON.stringify(
		{
			sessionId: data.sessionId,
			exportedAt: new Date().toISOString(),
			model: data.model,
			workspace: data.workspace,
			messages: data.messages,
			transcript: data.transcript,
		},
		null,
		2
	);
}

function clip( text ) {
	const s = String( text ?? '' );
	return s.length > 4000 ? `${ s.slice( 0, 4000 ) }\n… (بریده شد)` : s;
}
