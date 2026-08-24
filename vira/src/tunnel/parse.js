/**
 * پارس لینک‌های کانفیگ VPN — vmess/vless/trojan/ss → outbound آمادهٔ xray.
 * بخشی از «تونل ویرا» (۰.۹.۶): موتور داخلیِ شبیه v2ray با جمع‌آوری کانفیگ رایگان.
 */

/**
 * دیکد base64url به **متن خام**.
 *
 * @param {string} s
 * @returns {string} رشتهٔ خالی یعنی دیکد نشد
 */
function b64Text( s ) {
	const t = String( s || '' ).replace( /-/g, '+' ).replace( /_/g, '/' );
	try {
		return Buffer.from( t + '='.repeat( ( 4 - ( t.length % 4 ) ) % 4 ), 'base64' ).toString( 'utf8' );
	} catch {
		return '';
	}
}

/**
 * دیکد base64url و سپس `JSON.parse` — فقط برای vmess که محتوایش JSON است.
 *
 * ⚠️ تا ۰.۹.۶ یک تابع `b64()` وجود داشت که **همیشه** `JSON.parse` می‌کرد و همه‌جا از آن
 * استفاده می‌شد. برای `ss://` که محتوایش `method:password` است (نه JSON) همیشه `null`
 * برمی‌گرداند، یعنی **هر کانفیگ Shadowsocks بی‌صدا دور ریخته می‌شد**. جداکردن این دو،
 * همان باگ است. (DESIGN-HUB-UI-FIX §۲.۷ باگ ۳)
 *
 * @param {string} s
 */
function b64Json( s ) {
	const text = b64Text( s );
	if ( ! text ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		return null;
	}
}
const num = ( v, d = 443 ) => Number( v ) || d;

/**
 * یک لینک → { proto, name, host, port, outbound } یا null.
 * @param {string} raw
 */
export function parseLink( raw ) {
	const link = String( raw ).trim();
	try {
		if ( link.startsWith( 'vmess://' ) ) {
			const j = b64Json( link.slice( 8 ) );
			if ( ! j?.add ) { return null; }
			const stream = streamSettings( j.net, j.host, j.path, j.tls === 'tls' ? 'tls' : 'none', j.sni || j.host, j.fp );
			return {
				proto: 'vmess', name: String( j.ps || j.add ).slice( 0, 60 ), host: String( j.add ), port: num( j.port ),
				outbound: { protocol: 'vmess', settings: { vnext: [ { address: String( j.add ), port: num( j.port ), users: [ { id: String( j.id || '' ), alterId: Number( j.aid ) || 0, security: j.scy || 'auto' } ] } ] }, streamSettings: stream },
			};
		}
		if ( link.startsWith( 'vless://' ) || link.startsWith( 'trojan://' ) ) {
			const u = new URL( link );
			const proto = link.startsWith( 'vless:' ) ? 'vless' : 'trojan';
			const q = Object.fromEntries( u.searchParams.entries() );
			const security = q.security === 'reality' ? 'reality' : q.security === 'tls' || proto === 'trojan' ? 'tls' : 'none';
			const stream = streamSettings( q.type || 'tcp', q.host, q.path, security, q.sni || u.hostname, q.fp, q );
			const user = proto === 'vless'
				? { id: decodeURIComponent( u.username ), encryption: 'none', flow: q.flow || '' }
				: { password: decodeURIComponent( u.username ) };
			const key = proto === 'vless' ? 'vnext' : 'servers';
			const wrap = proto === 'vless'
				? { address: u.hostname, port: num( u.port ), users: [ user ] }
				: { address: u.hostname, port: num( u.port ), users: [ user ] }; // trojan: servers[].users با password
			return { proto, name: decodeURIComponent( u.hash || '' ).replace( /^#/, '' ).slice( 0, 60 ) || u.hostname, host: u.hostname, port: num( u.port ),
				outbound: { protocol: proto, settings: { [ key ]: [ wrap ] }, streamSettings: stream } };
		}
		if ( link.startsWith( 'ss://' ) ) {
			const body = link.slice( 5 ).split( '#' )[ 0 ];
			const name = decodeURIComponent( link.split( '#' )[ 1 ] || '' ).slice( 0, 60 );
			// دو شکل: ss://BASE64(method:pass)@host:port  یا  ss://BASE64(method:pass@host:port)
			const at = body.lastIndexOf( '@' );
			let method = '', password = '', hostPart = body;
			if ( at > 0 ) {
				// `method:password` است، نه JSON — پس متن خام می‌خواهیم.
				const cred = b64Text( body.slice( 0, at ) );
				if ( cred && cred.includes( ':' ) ) {
					const i = cred.indexOf( ':' );
					method = cred.slice( 0, i ); password = cred.slice( i + 1 ); hostPart = body.slice( at + 1 );
				} else {
					// شکل بدون base64: ss://method:pass@host:port
					const i = body.slice( 0, at ).indexOf( ':' );
					if ( i < 0 ) { return null; }
					method = body.slice( 0, i ); password = body.slice( i + 1, at ); hostPart = body.slice( at + 1 );
				}
			} else {
				const whole = b64Text( body );
				if ( ! whole || ! whole.includes( '@' ) ) { return null; }
				const i = whole.lastIndexOf( '@' );
				const cred = whole.slice( 0, i );
				const ci = cred.indexOf( ':' );
				method = cred.slice( 0, ci ); password = cred.slice( ci + 1 );
				hostPart = whole.slice( i + 1 );
			}
			const [ host, port ] = hostPart.split( ':' );
			if ( ! host ) { return null; }
			return { proto: 'ss', name: name || host, host, port: num( port ),
				outbound: { protocol: 'shadowsocks', settings: { servers: [ { address: host, port: num( port ), method, password } ] } } };
		}
	} catch {
		return null;
	}
	return null;
}

/** streamSettings مشترک — tcp/ws/grpc + tls/reality. */
function streamSettings( net = 'tcp', host = '', path = '', security = 'none', sni = '', fp = '', q = {} ) {
	const s = { network: net, security };
	if ( security === 'tls' ) {
		s.tlsSettings = { serverName: sni || host, allowInsecure: false, fingerprint: fp || undefined };
	}
	if ( security === 'reality' ) {
		s.realitySettings = { serverName: sni || host, fingerprint: fp || 'chrome', publicKey: q.pbk || '', shortId: q.sid || '', spiderX: q.spx || '' };
	}
	if ( net === 'ws' ) {
		s.wsSettings = { path: path || '/', headers: { Host: host || sni } };
	}
	if ( net === 'grpc' ) {
		s.grpcSettings = { serviceName: ( path || '' ).replace( /^\/?/, '' ) };
	}
	if ( net === 'tcp' && path && path.startsWith( '/' ) ) {
		// برخی کانفیگ‌های tcp مسNetwork headerType دارند؛ path ساده نادیده گرفته می‌شود.
	}
	return s;
}

/**
 * همهٔ لینک‌های یک متن/اشتراک — base64 کامل را هم باز می‌کند.
 * @param {string} text
 * @returns {{ proto:string, name:string, host:string, port:number, outbound:any, link:string }[]}
 */
export function parseAll( text ) {
	let t = String( text || '' ).trim();
	if ( ! t.includes( '://' ) ) {
		// اشتراک base64 — بازکردن تا دو لایه (بعضی منبع‌ها دوبار می‌پیچند).
		for ( let i = 0; i < 2 && ! t.includes( '://' ); i += 1 ) {
			const compact = t.replace( /\s+/g, '' );
			try {
				const buf = Buffer.from( compact, 'base64' );
				// اعتبارسنجی: خروجیِ واقعاً base64 باید دوباره همان شود و قابل خواندن باشد.
				if ( Buffer.from( buf.toString( 'base64' ) ).equals( Buffer.from( compact ) ) ) {
					t = buf.toString( 'utf8' );
				} else { break; }
			} catch { break; }
		}
	}
	const out = [];
	const seen = new Set();
	for ( const line of t.split( /\r?\n/ ) ) {
		const link = line.trim();
		if ( ! /^(vmess|vless|trojan|ss):\/\//.test( link ) ) { continue; }
		const parsed = parseLink( link );
		if ( ! parsed ) { continue; }
		const key = `${ parsed.proto }|${ parsed.host }:${ parsed.port }`;
		if ( seen.has( key ) ) { continue; }
		seen.add( key );
		out.push( { ...parsed, link } );
	}
	return out;
}
