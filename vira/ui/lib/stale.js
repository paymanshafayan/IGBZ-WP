/**
 * نوار «کد مخزن نیستم».
 *
 * چرا یک ماژول جدا برای پنج خط کد: چون شکایت واقعی کاربر — «ویرا نسخهٔ ۰.۵.۰ بالا
 * می‌آید هنوز» — دقیقاً همین‌جا باید دیده می‌شد و نشد. اگر این منطق داخل `app.js`
 * بماند، تنها راه آزمودنش grep کردن است، و grep ثابت می‌کند رشته‌ای در فایل هست، نه
 * اینکه نوار واقعاً ظاهر می‌شود. اینجا که باشد، تست می‌تواند صدایش بزند و ببیند.
 */

import { h } from './dom.js';

/**
 * @param {HTMLElement|null} bar
 * @param {{version?:string, install?:{frozen?:boolean, root?:string, hint?:string}}} state
 * @returns {boolean} آیا نوار نشان داده شد
 */
export function paintStaleBar( bar, state ) {
	if ( ! bar ) {
		return false;
	}
	if ( ! state?.install?.frozen ) {
		bar.hidden = true;
		bar.replaceChildren();
		return false;
	}

	bar.hidden = false;
	bar.replaceChildren(
		h( 'b', { text: `ویرا ${ state.version } — این کد مخزن نیست` } ),
		h( 'span', { text: `اجرا از ${ state.install.root || '؟' }` } ),
		h( 'code', { text: 'npm rm -g vira' } ),
		h( 'span', { text: 'و بعد در پوشهٔ مخزن:' } ),
		h( 'code', { text: 'npm link' } )
	);
	return true;
}
