/**
 * مارک‌داون کوچک + رنگ‌آمیزی سبک کد.
 *
 * چرا کتابخانه نیاوردیم: کل چیزی که یک مدل می‌نویسد، همین چند ساختار است، و آوردن یک
 * وابستگی ۵۰ کیلوبایتی برای این، با «بدون build» جور درنمی‌آید.
 */

import { esc } from './dom.js';

const KEYWORDS =
	/\b(const|let|var|function|return|if|else|for|while|class|new|await|async|import|export|from|try|catch|finally|throw|switch|case|break|continue|typeof|instanceof|of|in|this|null|undefined|true|false|public|private|protected|static|void|def|elif|lambda|None|True|False|end|echo|require|use|namespace|fn|struct|impl|match|pub|mod)\b/g;

/**
 * @param {string} code
 * @param {string} [lang]
 */
export function highlight( code, lang ) {
	let out = esc( code );

	// رشته‌ها و کامنت‌ها اول، تا کلیدواژه داخلشان رنگ نگیرد.
	/** @type {string[]} */
	const stash = [];
	const park = ( html ) => {
		stash.push( html );
		return `\u0000${ stash.length - 1 }\u0000`;
	};

	out = out.replace( /(&quot;|&#39;|`)(?:\\.|(?!\1)[\s\S])*?\1/g, ( m ) => park( `<span class="s">${ m }</span>` ) );
	out = out.replace( /(\/\/|#)[^\n]*/g, ( m ) => park( `<span class="c">${ m }</span>` ) );
	out = out.replace( /\/\*[\s\S]*?\*\//g, ( m ) => park( `<span class="c">${ m }</span>` ) );

	out = out.replace( KEYWORDS, '<span class="k">$1</span>' );
	out = out.replace( /\b(\d+(?:\.\d+)?)\b/g, '<span class="n">$1</span>' );
	out = out.replace( /([A-Za-z_$][\w$]*)\s*\(/g, '<span class="f">$1</span>(' );

	out = out.replace( /\u0000(\d+)\u0000/g, ( _, i ) => stash[ Number( i ) ] );

	return `<code class="lang-${ esc( lang || '' ) }">${ out }</code>`;
}

/** @param {string} src */
export function markdown( src ) {
	const blocks = String( src ?? '' ).split( /```/ );
	let out = '';

	blocks.forEach( ( block, i ) => {
		if ( i % 2 === 1 ) {
			const nl = block.indexOf( '\n' );
			const lang = nl === -1 ? block.trim() : block.slice( 0, nl ).trim();
			const body = nl === -1 ? '' : block.slice( nl + 1 );
			out += `<div class="codeblock"><div class="code-head"><span>${ esc( lang || 'متن' ) }</span><button class="btn quiet sm" type="button" data-copy>کپی</button></div><pre>${ highlight(
				body.replace( /\n$/, '' ),
				lang
			) }</pre></div>`;
			return;
		}

		for ( const chunk of block.split( /\n{2,}/ ) ) {
			const text = chunk.replace( /\s+$/, '' );
			if ( ! text.trim() ) {
				continue;
			}

			const lines = text.split( '\n' );

			// جدول
			if ( lines.length > 1 && /^\s*\|.*\|\s*$/.test( lines[ 0 ] ) && /^\s*\|[\s:|-]+\|\s*$/.test( lines[ 1 ] || '' ) ) {
				const rows = lines.filter( ( l ) => /\|/.test( l ) );
				const head = cells( rows[ 0 ] );
				const body = rows.slice( 2 ).map( cells );
				out +=
					`<table><thead><tr>${ head.map( ( c ) => `<th>${ inline( c ) }</th>` ).join( '' ) }</tr></thead><tbody>` +
					body.map( ( r ) => `<tr>${ r.map( ( c ) => `<td>${ inline( c ) }</td>` ).join( '' ) }</tr>` ).join( '' ) +
					'</tbody></table>';
				continue;
			}

			const isList = lines.every( ( l ) => /^\s*([-*•]|\d+[.)])\s+/.test( l ) );
			if ( isList ) {
				const ordered = /^\s*\d+[.)]/.test( lines[ 0 ] );
				const items = lines
					.map( ( l ) => {
						const body = l.replace( /^\s*([-*•]|\d+[.)])\s+/, '' );
						const task = /^\[( |x|X)\]\s+/.exec( body );
						if ( task ) {
							return `<li class="task">${ task[ 1 ] === ' ' ? '☐' : '☑' } ${ inline( body.replace( /^\[( |x|X)\]\s+/, '' ) ) }</li>`;
						}
						return `<li>${ inline( body ) }</li>`;
					} )
					.join( '' );
				out += ordered ? `<ol>${ items }</ol>` : `<ul>${ items }</ul>`;
				continue;
			}

			const heading = /^(#{1,4})\s+(.*)$/.exec( text );
			if ( heading ) {
				const level = heading[ 1 ].length;
				out += `<h${ level }>${ inline( heading[ 2 ] ) }</h${ level }>`;
				continue;
			}

			if ( /^\s*(---+|\*\*\*+)\s*$/.test( text ) ) {
				out += '<hr />';
				continue;
			}

			if ( text.startsWith( '> ' ) ) {
				out += `<blockquote>${ inline( text.replace( /^> ?/gm, '' ) ) }</blockquote>`;
				continue;
			}

			out += `<p>${ inline( text ) }</p>`;
		}
	} );

	return out;
}

/** @param {string} row */
function cells( row ) {
	return row
		.trim()
		.replace( /^\||\|$/g, '' )
		.split( '|' )
		.map( ( c ) => c.trim() );
}

/** @param {string} s */
export function inline( s ) {
	return esc( s )
		.replace( /`([^`]+)`/g, '<code>$1</code>' )
		.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>' )
		.replace( /\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>' )
		.replace( /(^|\s)(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noreferrer">$2</a>' )
		.replace( /\n/g, '<br />' );
}

/** دکمهٔ «کپی» بلوک‌های کد را زنده می‌کند. */
export function wireCodeCopy( root ) {
	for ( const btn of root.querySelectorAll( '[data-copy]' ) ) {
		if ( btn.dataset.wired ) {
			continue;
		}
		btn.dataset.wired = '1';
		btn.addEventListener( 'click', async () => {
			const pre = btn.closest( '.codeblock' )?.querySelector( 'pre' );
			if ( ! pre ) {
				return;
			}
			await navigator.clipboard.writeText( pre.textContent || '' ).catch( () => {} );
			btn.textContent = 'کپی شد';
			setTimeout( () => ( btn.textContent = 'کپی' ), 1500 );
		} );
	}
}
