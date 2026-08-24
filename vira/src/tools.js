/**
 * ابزارهای هستهٔ ویرا — همان مجموعه‌ای که یک عامل کدنویس لازم دارد.
 *
 * هیچ‌کدام حذف نشده‌اند: خواندن، نوشتن، ویرایش، شل، جستجو، وب. محدودسازی کارِ لایهٔ
 * «مجوز» است، نه کارِ اینجا (تصمیم کارفرما: توانایی کامل بماند، دسترسی سیاست‌گذاری شود).
 *
 * دو قاعدهٔ ثابت:
 *   ۱) هر ابزار یک JSON Schema دقیق دارد — مدل هرچه دقیق‌تر بداند، کمتر اشتباه می‌کند.
 *   ۲) مسیرها همیشه داخل «پوشهٔ کاری» محدود می‌شوند مگر اینکه صریحاً مسیر دیگری اضافه شود.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';

import { unifiedDiff } from './diff.js';
import { shells } from './background.js';
import { spawnShell } from './sandbox.js';
import { render as renderNotebook, apply as applyNotebookEdit, readNotebook, serialize as serializeNotebook } from './notebook.js';
import * as vcs from './git.js';

const MAX_READ_BYTES = 400 * 1024;
const MAX_OUTPUT_CHARS = 30_000;

/**
 * @typedef {Object} ToolContext
 * @property {string} workspace
 * @property {(text:string)=>void} [log]
 * @property {(relPath:string)=>Promise<any>} [snapshot] پشتیبان‌گیری قبل از تغییر (چک‌پوینت)
 * @property {(payload:any)=>Promise<any>} [ask] پرسیدن از کاربر و منتظر ماندن برای جواب
 * @property {any} [sandbox] تنظیمات سندباکس اجرای فرمان
 */

/**
 * مسیر را داخل پوشهٔ کاری نگه می‌دارد.
 * @param {ToolContext} ctx
 * @param {string} p
 */
function resolveInside( ctx, p ) {
	const target = path.resolve( ctx.workspace, p || '.' );
	const root = path.resolve( ctx.workspace );
	if ( target !== root && ! target.startsWith( root + path.sep ) ) {
		throw new Error( `مسیر بیرون از پوشهٔ کاری است: ${ p }` );
	}
	return target;
}

/** @param {string} s */
function clip( s ) {
	if ( s.length <= MAX_OUTPUT_CHARS ) {
		return s;
	}
	return s.slice( 0, MAX_OUTPUT_CHARS ) + `\n… (${ s.length - MAX_OUTPUT_CHARS } نویسهٔ دیگر بریده شد)`;
}

/** تبدیل الگوی glob به RegExp — پشتیبانی از **، * و ? */
function globToRegExp( pattern ) {
	let re = '';
	for ( let i = 0; i < pattern.length; i++ ) {
		const c = pattern[ i ];
		if ( c === '*' ) {
			if ( pattern[ i + 1 ] === '*' ) {
				re += '.*';
				i++;
				if ( pattern[ i + 1 ] === '/' ) {
					i++;
				}
			} else {
				re += '[^/]*';
			}
		} else if ( c === '?' ) {
			re += '[^/]';
		} else if ( '\\^$.|+()[]{}'.includes( c ) ) {
			re += '\\' + c;
		} else {
			re += c;
		}
	}
	return new RegExp( `^${ re }$` );
}

const SKIP_DIRS = new Set( [ 'node_modules', '.git', 'dist', 'build', '.next', 'vendor', '__pycache__', '.venv' ] );

/**
 * @param {string} dir
 * @param {string} root
 * @param {number} depth
 * @param {string[]} out
 */
async function walk( dir, root, depth, out, limit = 5000 ) {
	if ( out.length >= limit || depth > 12 ) {
		return;
	}
	let entries;
	try {
		entries = await fs.readdir( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const e of entries ) {
		if ( out.length >= limit ) {
			return;
		}
		if ( e.name.startsWith( '.' ) && e.name !== '.env.example' && depth === 0 ) {
			// پوشه‌های مخفی ریشه را رد می‌کنیم مگر خواسته شود
		}
		const full = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			if ( SKIP_DIRS.has( e.name ) ) {
				continue;
			}
			await walk( full, root, depth + 1, out, limit );
		} else if ( e.isFile() ) {
			out.push( path.relative( root, full ) );
		}
	}
}

/** @type {Record<string, {spec: import('./providers/types.js').ToolSpec, risk:'read'|'write'|'exec'|'network', run:(input:any,ctx:ToolContext)=>Promise<string>}>} */
export const TOOLS = {
	list_dir: {
		risk: 'read',
		spec: {
			name: 'list_dir',
			description: 'فهرست فایل‌ها و پوشه‌های یک مسیر را برمی‌گرداند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'مسیر نسبی؛ پیش‌فرض ریشهٔ پوشهٔ کاری' },
				},
			},
		},
		async run( input, ctx ) {
			const dir = resolveInside( ctx, input.path || '.' );
			const entries = await fs.readdir( dir, { withFileTypes: true } );
			if ( ! entries.length ) {
				return '(خالی)';
			}
			const lines = entries
				.sort( ( a, b ) => Number( b.isDirectory() ) - Number( a.isDirectory() ) || a.name.localeCompare( b.name ) )
				.map( ( e ) => ( e.isDirectory() ? `${ e.name }/` : e.name ) );
			return clip( lines.join( '\n' ) );
		},
	},

	read_file: {
		risk: 'read',
		spec: {
			name: 'read_file',
			description: 'محتوای یک فایل متنی را می‌خواند. خروجی شماره‌گذاری‌شده است.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'مسیر فایل' },
					offset: { type: 'integer', description: 'از کدام خط شروع شود (۱ به بالا)' },
					limit: { type: 'integer', description: 'حداکثر چند خط' },
				},
				required: [ 'path' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			const stat = await fs.stat( file );
			if ( stat.size > MAX_READ_BYTES ) {
				return `فایل بزرگ‌تر از حد مجاز است (${ stat.size } بایت). با offset و limit بخوان.`;
			}

			// نوت‌بوک را به‌شکل JSON خام نشان‌دادن، هم کانتکست را می‌سوزاند هم مدل را گمراه می‌کند.
			if ( file.endsWith( '.ipynb' ) ) {
				const { nb } = await readNotebook( file );
				return clip( renderNotebook( nb ) );
			}

			const text = await fs.readFile( file, 'utf8' );
			const lines = text.split( '\n' );
			const start = Math.max( 0, ( input.offset ? input.offset - 1 : 0 ) );
			const end = input.limit ? start + input.limit : lines.length;
			const body = lines
				.slice( start, end )
				.map( ( l, i ) => `${ String( start + i + 1 ).padStart( 5 ) }→${ l }` )
				.join( '\n' );
			return clip( body || '(فایل خالی است)' );
		},
	},

	write_file: {
		risk: 'write',
		spec: {
			name: 'write_file',
			description: 'یک فایل را می‌سازد یا کاملاً بازنویسی می‌کند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string' },
					content: { type: 'string' },
				},
				required: [ 'path', 'content' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			await fs.mkdir( path.dirname( file ), { recursive: true } );
			const before = await fs.readFile( file, 'utf8' ).catch( () => null );
			await ctx.snapshot?.( input.path );
			const content = String( input.content ?? '' );
			await fs.writeFile( file, content, 'utf8' );

			const rel = path.relative( ctx.workspace, file );
			const bytes = Buffer.byteLength( content );
			if ( before === null ) {
				const preview = content.split( '\n' ).slice( 0, 40 );
				return clip(
					`ساخته شد: ${ rel } (${ bytes } بایت)\n` +
						preview.map( ( l, i ) => `+${ String( i + 1 ).padStart( 5 ) }  ${ l }` ).join( '\n' ) +
						( content.split( '\n' ).length > 40 ? '\n@@ …' : '' )
				);
			}
			const d = unifiedDiff( before, content, { path: rel } );
			return clip( `بازنویسی شد: ${ rel } (+${ d.added } −${ d.removed })\n${ d.text }` );
		},
	},

	edit_file: {
		risk: 'write',
		spec: {
			name: 'edit_file',
			description:
				'جایگزینی دقیق یک رشته در یک فایل. رشتهٔ قدیمی باید دقیقاً یکتا باشد مگر replace_all بدهی.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string' },
					old_string: { type: 'string' },
					new_string: { type: 'string' },
					replace_all: { type: 'boolean' },
				},
				required: [ 'path', 'old_string', 'new_string' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			const text = await fs.readFile( file, 'utf8' );
			const count = text.split( input.old_string ).length - 1;
			if ( count === 0 ) {
				throw new Error( 'رشتهٔ موردنظر در فایل پیدا نشد.' );
			}
			if ( count > 1 && ! input.replace_all ) {
				throw new Error( `رشته ${ count } بار تکرار شده؛ یا متن بیشتری بده یا replace_all را true کن.` );
			}
			await ctx.snapshot?.( input.path );
			const out = input.replace_all
				? text.split( input.old_string ).join( input.new_string )
				: text.replace( input.old_string, input.new_string );
			await fs.writeFile( file, out, 'utf8' );

			const rel = path.relative( ctx.workspace, file );
			const d = unifiedDiff( text, out, { path: rel } );
			return clip( `ویرایش شد: ${ rel } (${ input.replace_all ? count : 1 } جایگزینی · +${ d.added } −${ d.removed })\n${ d.text }` );
		},
	},

	multi_edit: {
		risk: 'write',
		spec: {
			name: 'multi_edit',
			description:
				'چند ویرایش پشت‌سرهم روی یک فایل، به‌صورت اتمی: یا همه انجام می‌شوند یا هیچ‌کدام. برای بازنویسی‌های چندنقطه‌ای از این استفاده کن تا فایل نیمه‌کاره نماند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string' },
					edits: {
						type: 'array',
						items: {
							type: 'object',
							properties: {
								old_string: { type: 'string' },
								new_string: { type: 'string' },
								replace_all: { type: 'boolean' },
							},
							required: [ 'old_string', 'new_string' ],
						},
					},
				},
				required: [ 'path', 'edits' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			const before = await fs.readFile( file, 'utf8' );
			let text = before;

			const edits = Array.isArray( input.edits ) ? input.edits : [];
			if ( ! edits.length ) {
				throw new Error( 'فهرست ویرایش‌ها خالی است.' );
			}

			edits.forEach( ( e, i ) => {
				const count = text.split( e.old_string ).length - 1;
				if ( count === 0 ) {
					throw new Error( `ویرایش ${ i + 1 }: رشتهٔ موردنظر پیدا نشد.` );
				}
				if ( count > 1 && ! e.replace_all ) {
					throw new Error( `ویرایش ${ i + 1 }: رشته ${ count } بار تکرار شده؛ replace_all بده یا متن بیشتری.` );
				}
				text = e.replace_all ? text.split( e.old_string ).join( e.new_string ) : text.replace( e.old_string, e.new_string );
			} );

			await ctx.snapshot?.( input.path );
			await fs.writeFile( file, text, 'utf8' );

			const rel = path.relative( ctx.workspace, file );
			const d = unifiedDiff( before, text, { path: rel } );
			return clip( `${ edits.length } ویرایش روی ${ rel } (+${ d.added } −${ d.removed })\n${ d.text }` );
		},
	},

	glob: {
		risk: 'read',
		spec: {
			name: 'glob',
			description: 'پیداکردن فایل‌ها با الگو، مثل src/**/*.js',
			parameters: {
				type: 'object',
				properties: {
					pattern: { type: 'string' },
					path: { type: 'string', description: 'پوشهٔ شروع' },
				},
				required: [ 'pattern' ],
			},
		},
		async run( input, ctx ) {
			const root = resolveInside( ctx, input.path || '.' );
			/** @type {string[]} */
			const files = [];
			await walk( root, root, 0, files );
			const re = globToRegExp( input.pattern );
			const hits = files.filter( ( f ) => re.test( f ) || re.test( path.basename( f ) ) ).slice( 0, 300 );
			return hits.length ? clip( hits.join( '\n' ) ) : '(چیزی پیدا نشد)';
		},
	},

	grep: {
		risk: 'read',
		spec: {
			name: 'grep',
			description: 'جستجوی یک عبارت (regex) در محتوای فایل‌ها.',
			parameters: {
				type: 'object',
				properties: {
					pattern: { type: 'string' },
					path: { type: 'string' },
					glob: { type: 'string', description: 'فیلتر نام فایل، مثل *.php' },
					max_results: { type: 'integer' },
				},
				required: [ 'pattern' ],
			},
		},
		async run( input, ctx ) {
			const root = resolveInside( ctx, input.path || '.' );
			/** @type {string[]} */
			const files = [];
			await walk( root, root, 0, files );
			const filter = input.glob ? globToRegExp( input.glob ) : null;
			let re;
			try {
				re = new RegExp( input.pattern, 'i' );
			} catch {
				throw new Error( 'الگوی regex معتبر نیست.' );
			}
			const max = Math.min( input.max_results || 100, 500 );
			/** @type {string[]} */
			const out = [];
			for ( const rel of files ) {
				if ( filter && ! filter.test( rel ) && ! filter.test( path.basename( rel ) ) ) {
					continue;
				}
				if ( out.length >= max ) {
					break;
				}
				let text;
				try {
					const st = await fs.stat( path.join( root, rel ) );
					if ( st.size > MAX_READ_BYTES ) {
						continue;
					}
					text = await fs.readFile( path.join( root, rel ), 'utf8' );
				} catch {
					continue;
				}
				const lines = text.split( '\n' );
				for ( let i = 0; i < lines.length && out.length < max; i++ ) {
					if ( re.test( lines[ i ] ) ) {
						out.push( `${ rel }:${ i + 1 }: ${ lines[ i ].trim().slice( 0, 200 ) }` );
					}
				}
			}
			return out.length ? clip( out.join( '\n' ) ) : '(چیزی پیدا نشد)';
		},
	},

	bash: {
		risk: 'exec',
		spec: {
			name: 'bash',
			description:
				'اجرای یک فرمان در پوستهٔ سیستم، داخل پوشهٔ کاری. برای فرمان‌های طولانی (سرور توسعه، watcher) پارامتر background را true بده تا گفتگو قفل نشود.',
			parameters: {
				type: 'object',
				properties: {
					command: { type: 'string' },
					description: { type: 'string', description: 'یک جمله دربارهٔ اینکه این فرمان چه می‌کند' },
					timeout_ms: { type: 'integer', description: 'پیش‌فرض ۶۰۰۰۰' },
					background: { type: 'boolean', description: 'اجرا در پس‌زمینه و برگرداندن شناسهٔ شل' },
				},
				required: [ 'command' ],
			},
		},
		async run( input, ctx ) {
			if ( input.background ) {
				const shell = await shells.start( input.command, ctx.workspace, ctx.sandbox );
				return `در پس‌زمینه اجرا شد. شناسهٔ شل: ${ shell.id }${
					shell.mode === 'container' ? ' (داخل کانتینر)' : ''
				}\nبا bash_output و همین شناسه خروجی را بخوان، با kill_shell متوقفش کن.`;
			}

			const started = await spawnShell( { command: input.command, workspace: ctx.workspace, sandbox: ctx.sandbox } );
			if ( started.mode === 'container' ) {
				ctx.log?.( `[سندباکس: ${ started.runtime }]\n` );
			}

			return new Promise( ( resolve, reject ) => {
				const timeout = Math.min( input.timeout_ms || 60_000, 600_000 );
				const child = started.child;

				let out = '';
				let err = '';
				const timer = setTimeout( () => {
					child.kill( 'SIGKILL' );
					reject( new Error( `فرمان بعد از ${ timeout } میلی‌ثانیه متوقف شد.` ) );
				}, timeout );

				child.stdout.on( 'data', ( d ) => {
					out += d.toString();
					ctx.log?.( d.toString() );
				} );
				child.stderr.on( 'data', ( d ) => {
					err += d.toString();
					ctx.log?.( d.toString() );
				} );
				child.on( 'error', ( e ) => {
					clearTimeout( timer );
					reject( e );
				} );
				child.on( 'close', ( code ) => {
					clearTimeout( timer );
					const body = [ out.trim(), err.trim() ].filter( Boolean ).join( '\n--- stderr ---\n' );
					const where = started.mode === 'container' ? ` (سندباکس: ${ started.runtime })` : '';
					resolve( clip( `exit=${ code }${ where }\n${ body || '(بدون خروجی)' }` ) );
				} );
			} );
		},
	},

	git_status: {
		risk: 'read',
		spec: {
			name: 'git_status',
			description: 'وضعیت مخزن: شاخهٔ جاری، فایل‌های تغییرکرده، و شمار خط‌های اضافه/کم‌شده.',
			parameters: { type: 'object', properties: {} },
		},
		async run( _input, ctx ) {
			const st = await vcs.status( ctx.workspace );
			if ( ! st ) {
				return 'این پوشه مخزن گیت نیست.';
			}
			const files = st.files.map( ( f ) => `  ${ f.state.padEnd( 2 ) } ${ f.path }` ).join( '\n' );
			return clip(
				[
					`مخزن: ${ st.name }`,
					`شاخه: ${ st.branch }${ st.protected ? ' (محافظت‌شده — روی این شاخه نمی‌نویسیم)' : '' }`,
					`جلوتر: ${ st.ahead } · عقب‌تر: ${ st.behind }`,
					`تغییر: +${ st.added } −${ st.removed } در ${ st.files.length } فایل`,
					files || '  (چیزی تغییر نکرده)',
				].join( '\n' )
			);
		},
	},

	git_diff: {
		risk: 'read',
		spec: {
			name: 'git_diff',
			description: 'دیف تغییرات. بدون پارامتر یعنی تغییرات ذخیره‌نشده؛ با base یعنی نسبت به آن شاخه.',
			parameters: {
				type: 'object',
				properties: {
					base: { type: 'string', description: 'مثلاً origin/main' },
					file: { type: 'string' },
				},
			},
		},
		async run( input, ctx ) {
			const out = await vcs.diff( ctx.workspace, { base: input.base, file: input.file } );
			return clip( out || '(بدون تغییر)' );
		},
	},

	git_branch: {
		risk: 'write',
		spec: {
			name: 'git_branch',
			description: 'ساخت یا تعویض شاخه. بدون پارامتر، فهرست شاخه‌ها را می‌دهد.',
			parameters: {
				type: 'object',
				properties: {
					name: { type: 'string' },
					create: { type: 'boolean', description: 'شاخهٔ تازه بساز' },
				},
			},
		},
		async run( input, ctx ) {
			if ( ! input.name ) {
				const list = await vcs.branches( ctx.workspace );
				return list.map( ( b ) => `${ b.protected ? '⛨' : ' ' } ${ b.name }  (${ b.when })` ).join( '\n' );
			}
			const out = await vcs.branch( ctx.workspace, input.name, { create: Boolean( input.create ) } );
			return `روی شاخهٔ «${ out.branch }» هستیم.`;
		},
	},

	git_commit: {
		risk: 'write',
		spec: {
			name: 'git_commit',
			description:
				'ثبت تغییرات. اگر روی شاخهٔ محافظت‌شده باشیم، اول یک شاخهٔ کاری ساخته می‌شود — هیچ‌وقت مستقیم روی main کامیت نمی‌کنیم.',
			parameters: {
				type: 'object',
				properties: {
					message: { type: 'string' },
					paths: { type: 'array', items: { type: 'string' }, description: 'خالی یعنی همه' },
					branch: { type: 'string', description: 'اگر می‌خواهی روی شاخهٔ مشخصی برود' },
				},
				required: [ 'message' ],
			},
		},
		async run( input, ctx ) {
			const out = await vcs.commit( ctx.workspace, {
				message: input.message,
				paths: input.paths,
				branch: input.branch,
			} );
			return `${ out.sha } روی «${ out.branch }»${ out.movedTo ? ' (شاخهٔ تازه ساخته شد)' : '' }: ${ out.message }`;
		},
	},

	git_push: {
		risk: 'network',
		spec: {
			name: 'git_push',
			description: 'فرستادن شاخهٔ جاری به ریموت. روی شاخهٔ محافظت‌شده کار نمی‌کند.',
			parameters: { type: 'object', properties: { branch: { type: 'string' } } },
		},
		async run( input, ctx ) {
			const out = await vcs.push( ctx.workspace, { branch: input.branch, token: await ctx.secret?.( 'git.token' ) } );
			return `شاخهٔ «${ out.branch }» فرستاده شد.\n${ out.output || '' }`.trim();
		},
	},

	git_log: {
		risk: 'read',
		spec: {
			name: 'git_log',
			description: 'آخرین کامیت‌ها.',
			parameters: { type: 'object', properties: { limit: { type: 'integer' } } },
		},
		async run( input, ctx ) {
			const list = await vcs.log( ctx.workspace, Math.min( input.limit || 20, 100 ) );
			return list.map( ( c ) => `${ c.sha }  ${ c.when.padEnd( 16 ) } ${ c.author }  ${ c.subject }` ).join( '\n' ) || '(بدون کامیت)';
		},
	},

	notebook_edit: {
		risk: 'write',
		spec: {
			name: 'notebook_edit',
			description:
				'ویرایش سلول‌های یک دفترچهٔ Jupyter (.ipynb): جایگزینی، افزودن یا حذف سلول. برای خواندنش از read_file استفاده کن تا سلول‌ها با شناسه‌شان نمایش داده شوند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'مسیر فایل .ipynb' },
					mode: { type: 'string', enum: [ 'replace', 'insert', 'delete' ], description: 'پیش‌فرض replace' },
					cell: { type: 'string', description: 'شناسهٔ سلول یا شمارهٔ آن؛ برای insert یعنی «قبل از این سلول»' },
					cell_type: { type: 'string', enum: [ 'code', 'markdown' ] },
					source: { type: 'string', description: 'متن تازهٔ سلول' },
				},
				required: [ 'path' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			if ( ! file.endsWith( '.ipynb' ) ) {
				throw new Error( 'این ابزار فقط برای فایل‌های .ipynb است.' );
			}

			const { nb } = await readNotebook( file );
			const before = renderNotebook( nb );

			const { notebook, message } = applyNotebookEdit( nb, {
				mode: input.mode || 'replace',
				cell: input.cell,
				cellType: input.cell_type,
				source: input.source,
			} );

			await ctx.snapshot?.( input.path );
			await fs.writeFile( file, serializeNotebook( notebook ), 'utf8' );

			const d = unifiedDiff( before, renderNotebook( notebook ), { path: path.relative( ctx.workspace, file ) } );
			return clip( `${ message } — ${ path.relative( ctx.workspace, file ) } (+${ d.added } −${ d.removed })\n${ d.text }` );
		},
	},

	bash_output: {
		risk: 'read',
		spec: {
			name: 'bash_output',
			description: 'خواندن خروجی تازهٔ یک شل پس‌زمینه (از آخرین باری که خواندی به بعد).',
			parameters: {
				type: 'object',
				properties: {
					shell_id: { type: 'string' },
					filter: { type: 'string', description: 'فقط خط‌هایی که با این regex می‌خوانند' },
				},
				required: [ 'shell_id' ],
			},
		},
		async run( input ) {
			const r = shells.read( input.shell_id, { filter: input.filter } );
			return clip(
				`وضعیت: ${ r.status }${ r.exitCode !== null && r.exitCode !== undefined ? ` (exit=${ r.exitCode })` : '' }\n${
					r.text || '(خروجی تازه‌ای نیست)'
				}`
			);
		},
	},

	kill_shell: {
		risk: 'exec',
		spec: {
			name: 'kill_shell',
			description: 'متوقف‌کردن یک شل پس‌زمینه.',
			parameters: {
				type: 'object',
				properties: { shell_id: { type: 'string' } },
				required: [ 'shell_id' ],
			},
		},
		async run( input ) {
			const s = shells.kill( input.shell_id );
			return `شل ${ s.id } متوقف شد.`;
		},
	},

	web_fetch: {
		risk: 'network',
		spec: {
			name: 'web_fetch',
			description: 'گرفتن محتوای یک آدرس اینترنتی به‌صورت متن.',
			parameters: {
				type: 'object',
				properties: { url: { type: 'string' } },
				required: [ 'url' ],
			},
		},
		async run( input ) {
			const res = await fetch( input.url, { headers: { 'User-Agent': 'Vira/0.1' } } );
			const text = await res.text();
			const stripped = text
				.replace( /<script[\s\S]*?<\/script>/gi, '' )
				.replace( /<style[\s\S]*?<\/style>/gi, '' )
				.replace( /<[^>]+>/g, ' ' )
				.replace( /\s+/g, ' ' )
				.trim();
			return clip( `HTTP ${ res.status }\n\n${ stripped }` );
		},
	},

	web_search: {
		risk: 'network',
		spec: {
			name: 'web_search',
			description: 'جستجوی وب و برگرداندن چند نتیجهٔ اول با عنوان، آدرس و خلاصه.',
			parameters: {
				type: 'object',
				properties: {
					query: { type: 'string' },
					max_results: { type: 'integer', description: 'پیش‌فرض ۵' },
				},
				required: [ 'query' ],
			},
		},
		async run( input ) {
			const max = Math.min( input.max_results || 5, 10 );
			const url = `https://duckduckgo.com/html/?q=${ encodeURIComponent( input.query ) }`;
			const res = await fetch( url, { headers: { 'User-Agent': 'Mozilla/5.0 Vira/0.3' } } );
			if ( ! res.ok ) {
				throw new Error( `موتور جستجو پاسخ ${ res.status } داد.` );
			}
			const html = await res.text();

			/** @type {string[]} */
			const out = [];
			const re = /<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/g;
			let m;
			while ( ( m = re.exec( html ) ) && out.length < max ) {
				const href = decodeDuck( m[ 1 ] );
				const title = stripTags( m[ 2 ] );
				if ( title ) {
					out.push( `${ out.length + 1 }. ${ title }\n   ${ href }` );
				}
			}
			return out.length ? out.join( '\n' ) : '(نتیجه‌ای پیدا نشد یا ساختار صفحه عوض شده است)';
		},
	},

	exit_plan_mode: {
		risk: 'read',
		spec: {
			name: 'exit_plan_mode',
			description:
				'وقتی در حالت «پلن» هستی و نقشهٔ کار آماده شد، با این ابزار نقشه را به کاربر نشان بده و اجازهٔ اجرا بگیر. تا تأیید نگیری هیچ تغییری نده.',
			parameters: {
				type: 'object',
				properties: { plan: { type: 'string', description: 'نقشهٔ کار به مارک‌داون' } },
				required: [ 'plan' ],
			},
		},
		async run( input, ctx ) {
			if ( ! ctx.ask ) {
				return 'این محیط تأیید تعاملی ندارد؛ نقشه را در پیام بنویس.';
			}
			const answer = await ctx.ask( { kind: 'plan', plan: String( input.plan || '' ) } );
			if ( answer === true || answer?.approved ) {
				return `کاربر نقشه را تأیید کرد و حالت به «${ answer.mode || 'default' }» تغییر کرد. حالا اجرا کن.`;
			}
			return `کاربر نقشه را تأیید نکرد.${ answer?.feedback ? ` بازخوردش: ${ answer.feedback }` : '' } نقشه را اصلاح کن.`;
		},
	},

	ask_user_question: {
		risk: 'read',
		spec: {
			name: 'ask_user_question',
			description:
				'پرسیدن یک سؤال چندگزینه‌ای از کاربر وقتی تصمیم به او مربوط است (نه به تو). به‌جای حدس‌زدن، بپرس.',
			parameters: {
				type: 'object',
				properties: {
					question: { type: 'string' },
					options: {
						type: 'array',
						items: {
							type: 'object',
							properties: {
								label: { type: 'string' },
								description: { type: 'string' },
							},
							required: [ 'label' ],
						},
					},
					allow_other: { type: 'boolean', description: 'اجازهٔ جواب آزاد' },
				},
				required: [ 'question', 'options' ],
			},
		},
		async run( input, ctx ) {
			if ( ! ctx.ask ) {
				return 'این محیط تعاملی نیست؛ خودت بهترین گزینه را انتخاب کن و دلیلش را بنویس.';
			}
			const answer = await ctx.ask( {
				kind: 'question',
				question: String( input.question || '' ),
				options: Array.isArray( input.options ) ? input.options.slice( 0, 6 ) : [],
				allowOther: input.allow_other !== false,
			} );
			// جواب می‌تواند رشته باشد (کلیک روی گزینه) یا شیء (فرم آزاد) — هر دو را بپذیر.
			const value = typeof answer === 'string' ? answer : answer?.value;
			return value ? `پاسخ کاربر: ${ value }` : 'کاربر جوابی نداد.';
		},
	},

	todo_write: {
		risk: 'read',
		spec: {
			name: 'todo_write',
			description: 'ثبت یا به‌روزرسانی فهرست کارهای این نشست، تا کار چندمرحله‌ای گم نشود.',
			parameters: {
				type: 'object',
				properties: {
					todos: {
						type: 'array',
						items: {
							type: 'object',
							properties: {
								content: { type: 'string' },
								status: { type: 'string', enum: [ 'pending', 'in_progress', 'completed' ] },
							},
							required: [ 'content', 'status' ],
						},
					},
				},
				required: [ 'todos' ],
			},
		},
		async run( input, ctx ) {
			const todos = Array.isArray( input.todos ) ? input.todos : [];
			ctx.log?.( JSON.stringify( { todos } ) );
			const icon = { pending: '☐', in_progress: '▸', completed: '☑' };
			return todos.map( ( t ) => `${ icon[ t.status ] || '☐' } ${ t.content }` ).join( '\n' ) || '(خالی)';
		},
	},
};

/** @returns {import('./providers/types.js').ToolSpec[]} */
export function toolSpecs() {
	return Object.values( TOOLS ).map( ( t ) => t.spec );
}

/** @param {string} s */
function stripTags( s ) {
	return String( s )
		.replace( /<[^>]+>/g, '' )
		.replace( /&amp;/g, '&' )
		.replace( /&quot;/g, '"' )
		.replace( /&#x27;|&#39;/g, "'" )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/** داک‌داک‌گو لینک‌ها را دور یک ریدایرکت می‌پیچد؛ بازش می‌کنیم. */
function decodeDuck( href ) {
	try {
		const u = new URL( href.startsWith( '//' ) ? `https:${ href }` : href, 'https://duckduckgo.com' );
		const real = u.searchParams.get( 'uddg' );
		return real ? decodeURIComponent( real ) : u.toString();
	} catch {
		return href;
	}
}

