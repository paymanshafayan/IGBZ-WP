/**
 * صدا — گفتن به‌جای نوشتن، و شنیدن به‌جای خواندن.
 *
 * این دو قابلیت را از `@sunpix/claude-code-web` برداشتیم، ولی با یک تفاوت مهم: آن‌ها صدا
 * را به Whisper اوپن‌ای‌آی می‌فرستند و کلید می‌خواهند. ما اول از **موتور خود مرورگر**
 * استفاده می‌کنیم که روی ویندوز فارسی را می‌شناسد، رایگان است، و صدا را جایی نمی‌فرستد.
 *
 * اگر مرورگر پشتیبانی نکند، دکمه با پیام روشن غیرفعال می‌شود — نه اینکه بی‌صدا هیچ کاری نکند.
 */

const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

export function speechSupported() {
	return Boolean( SR );
}

export function ttsSupported() {
	return typeof window.speechSynthesis !== 'undefined';
}

/**
 * دیکتهٔ پیوسته. متن موقت را همان لحظه برمی‌گرداند تا کاربر ببیند دارد شنیده می‌شود.
 *
 * @param {{lang?:string, onText:(text:string, final:boolean)=>void, onEnd?:()=>void, onError?:(m:string)=>void}} opts
 */
export function startDictation( { lang = 'fa-IR', onText, onEnd, onError } ) {
	if ( ! SR ) {
		onError?.( 'این مرورگر تشخیص گفتار ندارد. Chrome یا Edge را امتحان کن.' );
		return null;
	}

	const rec = new SR();
	rec.lang = lang;
	rec.continuous = true;
	rec.interimResults = true;

	let settled = '';

	rec.onresult = ( e ) => {
		let interim = '';
		for ( let i = e.resultIndex; i < e.results.length; i++ ) {
			const chunk = e.results[ i ][ 0 ].transcript;
			if ( e.results[ i ].isFinal ) {
				settled += chunk;
			} else {
				interim += chunk;
			}
		}
		onText( ( settled + interim ).trim(), false );
	};

	rec.onerror = ( e ) => {
		const map = {
			'not-allowed': 'اجازهٔ میکروفن داده نشد.',
			'service-not-allowed': 'اجازهٔ میکروفن داده نشد.',
			'no-speech': 'صدایی شنیده نشد.',
			network: 'تشخیص گفتار به اینترنت نیاز دارد و وصل نشد.',
			'audio-capture': 'میکروفنی پیدا نشد.',
		};
		onError?.( map[ e.error ] || `خطای میکروفن: ${ e.error }` );
	};

	rec.onend = () => {
		onText( settled.trim(), true );
		onEnd?.();
	};

	try {
		rec.start();
	} catch ( e ) {
		onError?.( e?.message || String( e ) );
		return null;
	}
	return rec;
}

/**
 * بلندخوانی یک متن.
 *
 * مارک‌داون و بلوک کد را حذف می‌کنیم؛ خواندن «ستاره ستاره پرانتز» با صدای بلند، آزار است.
 *
 * @param {string} text
 * @param {string} [lang]
 */
export function speak( text, lang = 'fa-IR' ) {
	if ( ! ttsSupported() ) {
		return false;
	}
	window.speechSynthesis.cancel();

	const clean = String( text || '' )
		.replace( /```[\s\S]*?```/g, ' (بلوک کد) ' )
		.replace( /`([^`]+)`/g, '$1' )
		.replace( /[*_#>|]/g, ' ' )
		.replace( /\[([^\]]+)\]\([^)]+\)/g, '$1' )
		.replace( /\s+/g, ' ' )
		.trim();

	if ( ! clean ) {
		return false;
	}

	const u = new SpeechSynthesisUtterance( clean.slice( 0, 4000 ) );
	u.lang = lang;
	const voice = window.speechSynthesis.getVoices().find( ( v ) => v.lang?.toLowerCase().startsWith( 'fa' ) );
	if ( voice ) {
		u.voice = voice;
	}
	u.rate = 1;
	window.speechSynthesis.speak( u );
	return true;
}

export function stopSpeaking() {
	if ( ttsSupported() ) {
		window.speechSynthesis.cancel();
	}
}

export function isSpeaking() {
	return ttsSupported() && window.speechSynthesis.speaking;
}
