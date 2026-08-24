/**
 * شل‌های پس‌زمینه.
 *
 * در Claude Code وقتی یک فرمان طولانی است (سرور توسعه، watcher، بیلد)، به‌جای اینکه
 * گفتگو را قفل کند، در پس‌زمینه می‌رود و بعداً خروجی‌اش خوانده می‌شود. اینجا هم همان:
 *   bash با background=true  → شناسه برمی‌گرداند
 *   bash_output               → خروجی تازه از آخرین باری که خواندی
 *   kill_shell                → کشتنش
 *
 * خروجی در حافظه نگه داشته می‌شود با سقف، چون یک watcher پرحرف می‌تواند چند صد مگابایت
 * لاگ تولید کند و ما نباید حافظهٔ کاربر را بخوریم.
 */

import { spawnShell } from './sandbox.js';

const MAX_BUFFER = 200_000;

/**
 * @typedef {Object} Shell
 * @property {string} id
 * @property {string} command
 * @property {'running'|'exited'|'killed'} status
 * @property {number|null} exitCode
 * @property {number} startedAt
 * @property {string} buffer
 * @property {number} cursor  تا کجا خوانده شده
 * @property {import('node:child_process').ChildProcess} child
 * @property {'host'|'container'} [mode]
 */

export class ShellManager {
	/** @param {(ev:any)=>void} [emit] */
	constructor( emit ) {
		/** @type {Map<string, Shell>} */
		this.shells = new Map();
		this.emit = emit || ( () => {} );
		this.seq = 0;
	}

	/**
	 * @param {string} command
	 * @param {string} cwd
	 * @param {any} [sandbox]
	 */
	async start( command, cwd, sandbox ) {
		const id = `sh_${ ++this.seq }`;
		const started = await spawnShell( { command, workspace: cwd, sandbox } );
		const child = started.child;

		/** @type {Shell} */
		const shell = {
			id,
			command,
			status: 'running',
			exitCode: null,
			startedAt: Date.now(),
			buffer: '',
			cursor: 0,
			child,
			mode: started.mode,
		};

		const push = ( chunk ) => {
			shell.buffer += chunk.toString();
			if ( shell.buffer.length > MAX_BUFFER ) {
				const cut = shell.buffer.length - MAX_BUFFER;
				shell.buffer = shell.buffer.slice( cut );
				shell.cursor = Math.max( 0, shell.cursor - cut );
			}
			this.emit( { type: 'shell_output', id, status: shell.status } );
		};

		child.stdout?.on( 'data', push );
		child.stderr?.on( 'data', push );
		child.on( 'error', ( e ) => {
			shell.buffer += `\n[خطا] ${ e?.message || e }\n`;
			shell.status = 'exited';
			shell.exitCode = -1;
			this.emit( { type: 'shell_exit', id, exitCode: -1 } );
		} );
		child.on( 'close', ( code ) => {
			if ( shell.status === 'running' ) {
				shell.status = 'exited';
			}
			shell.exitCode = code;
			this.emit( { type: 'shell_exit', id, exitCode: code } );
		} );

		this.shells.set( id, shell );
		this.emit( { type: 'shell_start', id, command, mode: started.mode } );
		return shell;
	}

	/**
	 * خروجی تازه (از آخرین خواندن به بعد).
	 * @param {string} id
	 * @param {{peek?:boolean, filter?:string}} [opts]
	 */
	read( id, opts = {} ) {
		const shell = this.shells.get( id );
		if ( ! shell ) {
			throw new Error( `شل «${ id }» پیدا نشد.` );
		}
		let text = shell.buffer.slice( shell.cursor );
		if ( ! opts.peek ) {
			shell.cursor = shell.buffer.length;
		}
		if ( opts.filter ) {
			let re;
			try {
				re = new RegExp( opts.filter );
			} catch {
				throw new Error( 'الگوی فیلتر معتبر نیست.' );
			}
			text = text
				.split( '\n' )
				.filter( ( l ) => re.test( l ) )
				.join( '\n' );
		}
		return { text, status: shell.status, exitCode: shell.exitCode, command: shell.command };
	}

	/** @param {string} id */
	kill( id ) {
		const shell = this.shells.get( id );
		if ( ! shell ) {
			throw new Error( `شل «${ id }» پیدا نشد.` );
		}
		if ( shell.status === 'running' ) {
			shell.child.kill( 'SIGKILL' );
			shell.status = 'killed';
			this.emit( { type: 'shell_exit', id, exitCode: null } );
		}
		return shell;
	}

	list() {
		return [ ...this.shells.values() ].map( ( s ) => ( {
			id: s.id,
			command: s.command,
			status: s.status,
			exitCode: s.exitCode,
			startedAt: s.startedAt,
			mode: s.mode || 'host',
			pending: s.buffer.length - s.cursor,
		} ) );
	}

	killAll() {
		for ( const s of this.shells.values() ) {
			if ( s.status === 'running' ) {
				try {
					s.child.kill( 'SIGKILL' );
				} catch {
					// مهم نیست؛ داریم می‌بندیم.
				}
				s.status = 'killed';
			}
		}
	}
}

/** یک نمونهٔ مشترک برای کل برنامه — ابزارها به همین دسترسی دارند. */
export const shells = new ShellManager();
