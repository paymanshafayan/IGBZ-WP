/**
 * محتوای پیام: متن ساده یا چند «تکه» (متن + تصویر).
 *
 * تا دیروز `content` همیشه رشته بود. حالا می‌تواند آرایه‌ای از تکه‌ها باشد تا بشود تصویر
 * فرستاد. برای اینکه این تغییر، ده جای دیگر را نشکند (شمارش کانتکست، خلاصه‌سازی، عنوان
 * نشست، پرووایدر آزمایشی)، هر جایی که «متنِ پیام» می‌خواهد از `textOf` استفاده می‌کند.
 *
 * @typedef {{type:'text', text:string}} TextPart
 * @typedef {{type:'image', mediaType:string, data:string, name?:string}} ImagePart
 * @typedef {TextPart|ImagePart} ContentPart
 */

/**
 * متنِ خوانای یک محتوا — تصویرها به یک برچسب کوتاه تبدیل می‌شوند.
 * @param {string|ContentPart[]|any} content
 */
export function textOf( content ) {
	if ( typeof content === 'string' ) {
		return content;
	}
	if ( ! Array.isArray( content ) ) {
		return String( content ?? '' );
	}
	return content
		.map( ( p ) => {
			if ( p?.type === 'text' ) {
				return p.text || '';
			}
			if ( p?.type === 'image' ) {
				return `[تصویر${ p.name ? `: ${ p.name }` : '' }]`;
			}
			return '';
		} )
		.filter( Boolean )
		.join( '\n' );
}

/** @param {string|ContentPart[]|any} content */
export function hasImages( content ) {
	return Array.isArray( content ) && content.some( ( p ) => p?.type === 'image' );
}

/** @param {string|ContentPart[]|any} content */
export function imageParts( content ) {
	return Array.isArray( content ) ? content.filter( ( p ) => p?.type === 'image' ) : [];
}

/**
 * ساخت محتوا از متن و پیوست‌ها.
 *
 * @param {string} text
 * @param {{name?:string, mediaType:string, data:string}[]} [images]
 * @returns {string|ContentPart[]}
 */
export function buildContent( text, images ) {
	if ( ! images?.length ) {
		return text;
	}
	/** @type {ContentPart[]} */
	const parts = [];
	if ( text?.trim() ) {
		parts.push( { type: 'text', text } );
	}
	for ( const img of images ) {
		parts.push( {
			type: 'image',
			mediaType: normalizeMediaType( img.mediaType ),
			data: stripDataUrl( img.data ),
			name: img.name,
		} );
	}
	return parts;
}

/** @param {string} value */
export function stripDataUrl( value ) {
	const s = String( value || '' );
	const m = /^data:([^;]+);base64,(.*)$/s.exec( s );
	return m ? m[ 2 ] : s;
}

/** @param {string} value */
export function normalizeMediaType( value ) {
	const s = String( value || '' ).toLowerCase();
	if ( /^image\/(png|jpeg|gif|webp)$/.test( s ) ) {
		return s;
	}
	if ( s === 'image/jpg' ) {
		return 'image/jpeg';
	}
	return 'image/png';
}
