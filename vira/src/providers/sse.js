/**
 * خواندن یک بدنهٔ SSE و بیرون‌دادن مقدار هر خط `data:`.
 *
 * چند نکته که در عمل به آن‌ها خورده‌ایم:
 *   - یک رویداد ممکن است چند خط `data:` داشته باشد؛ باید به هم چسبانده شوند.
 *   - قطعه‌های شبکه وسط یک خط می‌شکنند؛ پس باید بافر نگه داشت.
 *   - بعضی سرورها `\r\n` می‌فرستند.
 *
 * @param {ReadableStream<Uint8Array>} body
 * @returns {AsyncGenerator<string>}
 */
export async function* sseLines( body ) {
	const decoder = new TextDecoder();
	let buffer = '';

	// @ts-ignore — بدنهٔ fetch در Node قابل پیمایش async است.
	for await ( const chunk of body ) {
		buffer += decoder.decode( chunk, { stream: true } );
		buffer = buffer.replace( /\r\n/g, '\n' );

		let sep;
		while ( ( sep = buffer.indexOf( '\n\n' ) ) !== -1 ) {
			const raw = buffer.slice( 0, sep );
			buffer = buffer.slice( sep + 2 );

			const data = raw
				.split( '\n' )
				.filter( ( l ) => l.startsWith( 'data:' ) )
				.map( ( l ) => l.slice( 5 ).trim() )
				.join( '\n' );

			if ( data ) {
				yield data;
			}
		}
	}

	const rest = buffer.trim();
	if ( rest.startsWith( 'data:' ) ) {
		yield rest.slice( 5 ).trim();
	}
}
