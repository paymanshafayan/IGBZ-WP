/**
 * نشان ویرا به‌شکل تابع — از همان هندسه‌ای که فایل‌های SVG و آیکون‌های PNG از آن ساخته
 * شده‌اند. یک منبع، سه خروجی.
 */

import { VIEW, CENTER, COLORS, markPath, innerStarPath } from './mark.js';

let seq = 0;

const GRAD = ( id ) => `
	<defs>
		<linearGradient id="${ id }" x1="4" y1="3" x2="28" y2="29" gradientUnits="userSpaceOnUse">
			<stop offset="0" stop-color="${ COLORS.from }" />
			<stop offset="1" stop-color="${ COLORS.to }" />
		</linearGradient>
	</defs>`;

/**
 * شمسهٔ ساکن.
 * @param {number} size
 * @param {string} [cls]
 */
export function logoSvg( size = 22, cls = 'logo' ) {
	const id = `hg${ ++seq }`;
	return `<svg class="${ cls }" viewBox="0 0 ${ VIEW } ${ VIEW }" width="${ size }" height="${ size }" aria-hidden="true">${ GRAD( id ) }
		<path d="${ markPath() }" fill="url(#${ id })" fill-rule="evenodd" />
		<path d="${ innerStarPath() }" fill="url(#${ id })" />
	</svg>`;
}

/**
 * شمسهٔ متحرک — بیرونی و درونی خلاف هم می‌چرخند و نور می‌گیرند.
 * @param {number} size
 */
export function logoLiveSvg( size = 20 ) {
	const id = `hl${ ++seq }`;
	return `<svg class="logo live" viewBox="0 0 ${ VIEW } ${ VIEW }" width="${ size }" height="${ size }" aria-hidden="true">${ GRAD( id ) }
		<g fill="url(#${ id })">
			<path d="${ markPath() }" fill-rule="evenodd" opacity="0.9">
				<animateTransform attributeName="transform" type="rotate" from="0 ${ CENTER } ${ CENTER }" to="360 ${ CENTER } ${ CENTER }" dur="9s" repeatCount="indefinite" />
				<animate attributeName="opacity" values="0.9;0.45;0.9" dur="2s" repeatCount="indefinite" />
			</path>
			<path d="${ innerStarPath() }" opacity="0.95">
				<animateTransform attributeName="transform" type="rotate" from="360 ${ CENTER } ${ CENTER }" to="0 ${ CENTER } ${ CENTER }" dur="5s" repeatCount="indefinite" />
				<animate attributeName="opacity" values="0.95;0.5;0.95" dur="1.4s" repeatCount="indefinite" />
			</path>
		</g>
	</svg>`;
}
