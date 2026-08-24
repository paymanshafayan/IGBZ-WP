/**
 * اسکیل‌ها — «دانش رویه‌ای» به فرمت SKILL.md.
 *
 * چرا همین فرمت: خواستهٔ کارفرما این بود که **اسکیل آماده نصب شود**، نه اینکه ما اسکیل
 * بنویسیم. فرمت SKILL.md (فرانت‌متر YAML + بدنهٔ مارک‌داون) امروز فرمت مشترک چند ابزار
 * است، پس هر اسکیلی که برای آن‌ها نوشته شده اینجا هم کار می‌کند.
 *
 * جای اسکیل‌ها:
 *   ~/.vira/skills/<name>/SKILL.md          سراسری (همهٔ پروژه‌ها)
 *   <workspace>/.vira/skills/<name>/SKILL.md   مخصوص همین پروژه
 *   ~/.vira/plugins/<plugin>/skills/<name>/SKILL.md   از راه پلاگین
 *
 * نکتهٔ طراحی: بدنهٔ اسکیل **به‌صورت پیش‌فرض داخل کانتکست نمی‌رود**؛ فقط نام و توضیح
 * کوتاهش به مدل نشان داده می‌شود و اگر مدل خواست، با ابزار `skill` بازش می‌کند. این همان
 * الگوی «بارگذاری تنبل» است و تنها راهی است که بشود ده‌ها اسکیل داشت بدون اینکه کانتکست
 * پر شود.
 */

import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * @typedef {Object} Skill
 * @property {string} name
 * @property {string} description
 * @property {string} body
 * @property {string} dir
 * @property {string} source  'user' | 'project' | نام پلاگین
 * @property {string[]} [allowedTools]
 */

/**
 * فرانت‌متر YAML سبک — فقط چیزی که SKILL.md لازم دارد: کلید: مقدار، و فهرست ساده.
 * وابسته‌کردن کل ابزار به یک کتابخانهٔ YAML برای این چند خط، معامله‌ی بدی است.
 *
 * @param {string} text
 * @returns {{data:Record<string,any>, body:string}}
 */
export function parseFrontmatter( text ) {
	const m = /^---\r?\n([\s\S]*?)\r?\n---\r?\n?/.exec( text );
	if ( ! m ) {
		return { data: {}, body: text };
	}

	/** @type {Record<string,any>} */
	const data = {};
	let currentKey = null;

	for ( const rawLine of m[ 1 ].split( /\r?\n/ ) ) {
		const line = rawLine.replace( /\s+$/, '' );
		if ( ! line.trim() || line.trim().startsWith( '#' ) ) {
			continue;
		}

		const listItem = /^\s*-\s+(.*)$/.exec( line );
		if ( listItem && currentKey ) {
			if ( ! Array.isArray( data[ currentKey ] ) ) {
				data[ currentKey ] = [];
			}
			data[ currentKey ].push( unquote( listItem[ 1 ] ) );
			continue;
		}

		const pair = /^([A-Za-z0-9_-]+)\s*:\s*(.*)$/.exec( line );
		if ( ! pair ) {
			continue;
		}
		currentKey = pair[ 1 ];
		const value = pair[ 2 ].trim();
		if ( value === '' ) {
			data[ currentKey ] = [];
		} else if ( value.startsWith( '[' ) && value.endsWith( ']' ) ) {
			data[ currentKey ] = value
				.slice( 1, -1 )
				.split( ',' )
				.map( ( s ) => unquote( s.trim() ) )
				.filter( Boolean );
		} else {
			data[ currentKey ] = unquote( value );
		}
	}

	return { data, body: text.slice( m[ 0 ].length ) };
}

/** @param {string} s */
function unquote( s ) {
	return s.replace( /^['"]|['"]$/g, '' );
}

/**
 * خواندن همهٔ اسکیل‌های یک پوشه.
 *
 * @param {string} root
 * @param {string} source
 * @returns {Promise<Skill[]>}
 */
export async function loadSkillsFrom( root, source ) {
	/** @type {Skill[]} */
	const out = [];
	let entries;
	try {
		entries = await fs.readdir( root, { withFileTypes: true } );
	} catch {
		return out;
	}

	for ( const e of entries ) {
		if ( ! e.isDirectory() ) {
			continue;
		}
		const dir = path.join( root, e.name );
		const file = path.join( dir, 'SKILL.md' );

		// اسکیل خاموش‌شده اصلاً بارگذاری نمی‌شود (فایل نشانهٔ .disabled، مثل پلاگین‌ها).
		const disabled = await fs
			.access( path.join( dir, '.disabled' ) )
			.then( () => true )
			.catch( () => false );
		if ( disabled ) {
			continue;
		}

		let text;
		try {
			text = await fs.readFile( file, 'utf8' );
		} catch {
			continue;
		}
		const { data, body } = parseFrontmatter( text );
		out.push( {
			name: String( data.name || e.name ),
			description: String( data.description || '' ).slice( 0, 400 ),
			body,
			dir,
			source,
			allowedTools: Array.isArray( data['allowed-tools'] )
				? data['allowed-tools']
				: typeof data['allowed-tools'] === 'string'
				? String( data['allowed-tools'] ).split( ',' ).map( ( s ) => s.trim() )
				: undefined,
		} );
	}

	return out;
}

/**
 * جمع‌کردن اسکیل‌ها از هر سه جا. اسکیل پروژه بر اسکیل سراسری اولویت دارد.
 *
 * @param {{home:string, workspace:string, pluginDirs?:{name:string,dir:string}[]}} opts
 * @returns {Promise<Skill[]>}
 */
export async function collectSkills( { home, workspace, pluginDirs = [] } ) {
	/** @type {Skill[]} */
	const all = [];

	all.push( ...( await loadSkillsFrom( path.join( home, 'skills' ), 'user' ) ) );
	for ( const p of pluginDirs ) {
		all.push( ...( await loadSkillsFrom( path.join( p.dir, 'skills' ), p.name ) ) );
	}
	all.push( ...( await loadSkillsFrom( path.join( workspace, '.vira', 'skills' ), 'project' ) ) );

	/** @type {Map<string,Skill>} */
	const byName = new Map();
	for ( const s of all ) {
		byName.set( s.name, s ); // آخرین برنده است، و ترتیب بالا یعنی پروژه آخر است.
	}
	return [ ...byName.values() ];
}

/**
 * ابزار `skill`: مدل با آن، متن کامل یک اسکیل را باز می‌کند.
 *
 * @param {() => Skill[]} getSkills
 */
export function makeSkillTool( getSkills ) {
	return {
		risk: /** @type {const} */ ( 'read' ),
		spec: {
			name: 'skill',
			description:
				'باز کردن یک اسکیل نصب‌شده و خواندن دستورالعمل کامل آن. وقتی کاری با یک اسکیل موجود مطابقت دارد، اول همین را صدا بزن.',
			parameters: {
				type: 'object',
				properties: { name: { type: 'string', description: 'نام اسکیل' } },
				required: [ 'name' ],
			},
		},
		/** @param {{name:string}} input */
		async run( input ) {
			const skill = getSkills().find( ( s ) => s.name === input.name );
			if ( ! skill ) {
				const names = getSkills().map( ( s ) => s.name ).join( '، ' ) || '(هیچ اسکیلی نصب نیست)';
				throw new Error( `اسکیل «${ input.name }» پیدا نشد. اسکیل‌های موجود: ${ names }` );
			}
			return [
				`# اسکیل: ${ skill.name }`,
				`مسیر: ${ skill.dir }`,
				skill.allowedTools?.length ? `ابزارهای مجاز این اسکیل: ${ skill.allowedTools.join( ', ' ) }` : '',
				'',
				skill.body.trim(),
			]
				.filter( Boolean )
				.join( '\n' );
		},
	};
}

/**
 * متن کوتاهی که در پرامپت سیستمی می‌نشیند تا مدل بداند چه اسکیل‌هایی هست.
 * @param {Skill[]} skills
 */
export function skillsPromptSection( skills ) {
	if ( ! skills.length ) {
		return '';
	}
	const lines = skills.map( ( s ) => `- ${ s.name }: ${ s.description || '(بدون توضیح)' }` );
	return [
		'',
		'اسکیل‌های نصب‌شده (با ابزار `skill` بازشان کن):',
		...lines,
	].join( '\n' );
}
