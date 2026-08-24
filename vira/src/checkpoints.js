/**
 * چک‌پوینت و بازگشت (rewind).
 *
 * یکی از چیزهایی که Claude Code را قابل‌اعتماد می‌کند این است که می‌شود گفت «برگرد به قبل».
 * منطق اینجا عمداً ساده و قابل‌بازرسی است:
 *
 *   • قبل از هر «نوبت کاربر» یک چک‌پوینت باز می‌شود (شمارهٔ پیام یادداشت می‌شود).
 *   • هر بار که ابزاری می‌خواهد فایلی را عوض کند، **قبل از تغییر**، نسخهٔ فعلی آن فایل
 *     یک‌بار در همان چک‌پوینت پشتیبان گرفته می‌شود (فقط بار اول؛ دفعات بعد لازم نیست).
 *   • بازگشت یعنی: از آخرین چک‌پوینت تا چک‌پوینت هدف، پشتیبان‌ها را برعکس برگردان.
 *     نتیجه دقیقاً وضعیت لحظهٔ آن چک‌پوینت است.
 *
 * فایلی که آن موقع «وجود نداشته» هم ثبت می‌شود تا در بازگشت حذف شود — وگرنه فایل‌های
 * ساخته‌شده بعد از بازگشت باقی می‌مانند و کاربر فکر می‌کند بازگشت کار نکرده.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';

export class CheckpointStore {
	/**
	 * @param {{home:string, workspace:string, sessionId:string}} opts
	 */
	constructor( { home, workspace, sessionId } ) {
		this.home = home;
		this.workspace = workspace;
		this.sessionId = sessionId;
		this.dir = path.join( home, 'checkpoints', sessionId );
		/** @type {any[]} */
		this.items = [];
		this.loaded = false;
	}

	get indexFile() {
		return path.join( this.dir, 'index.json' );
	}

	async load() {
		if ( this.loaded ) {
			return this.items;
		}
		try {
			const raw = await fs.readFile( this.indexFile, 'utf8' );
			this.items = JSON.parse( raw );
		} catch {
			this.items = [];
		}
		this.loaded = true;
		return this.items;
	}

	async #save() {
		await fs.mkdir( this.dir, { recursive: true } );
		await fs.writeFile( this.indexFile, JSON.stringify( this.items, null, 2 ), 'utf8' );
	}

	/**
	 * شروع یک چک‌پوینت تازه.
	 * @param {{label:string, messageCount:number}} opts
	 */
	async begin( { label, messageCount } ) {
		await this.load();
		const item = {
			id: `cp_${ Date.now().toString( 36 ) }_${ this.items.length }`,
			label: String( label || '' ).slice( 0, 120 ),
			at: Date.now(),
			messageCount,
			files: {},
		};
		this.items.push( item );
		await this.#save();
		return item;
	}

	get current() {
		return this.items[ this.items.length - 1 ] || null;
	}

	/**
	 * پشتیبان‌گیری از یک فایل، قبل از تغییر.
	 * @param {string} filePath مسیر مطلق یا نسبت به پوشهٔ کاری
	 */
	async recordFile( filePath ) {
		await this.load();
		const cp = this.current;
		if ( ! cp ) {
			return null;
		}
		const abs = path.isAbsolute( filePath ) ? filePath : path.join( this.workspace, filePath );
		const rel = path.relative( this.workspace, abs );
		if ( rel.startsWith( '..' ) ) {
			return null; // بیرون از پوشهٔ کاری را دست نمی‌زنیم.
		}
		if ( cp.files[ rel ] ) {
			return cp.files[ rel ]; // بار اول کافی است.
		}

		let content = null;
		try {
			content = await fs.readFile( abs );
		} catch {
			content = null; // یعنی آن لحظه وجود نداشته.
		}

		let backup = null;
		if ( content ) {
			const hash = crypto.createHash( 'sha1' ).update( content ).digest( 'hex' ).slice( 0, 16 );
			backup = path.join( 'blobs', `${ hash }.bin` );
			const full = path.join( this.dir, backup );
			await fs.mkdir( path.dirname( full ), { recursive: true } );
			await fs.writeFile( full, content );
		}

		cp.files[ rel ] = { existed: Boolean( content ), backup, size: content ? content.length : 0 };
		await this.#save();
		return cp.files[ rel ];
	}

	async list() {
		await this.load();
		return this.items.map( ( c ) => ( {
			id: c.id,
			label: c.label,
			at: c.at,
			messageCount: c.messageCount,
			fileCount: Object.keys( c.files ).length,
			files: Object.keys( c.files ),
		} ) );
	}

	/**
	 * بازگشت به یک چک‌پوینت.
	 *
	 * @param {string} id
	 * @param {{files?:boolean, conversation?:boolean}} [opts]
	 * @returns {Promise<{restored:string[], deleted:string[], messageCount:number}>}
	 */
	async restore( id, opts = {} ) {
		await this.load();
		const target = this.items.findIndex( ( c ) => c.id === id );
		if ( target === -1 ) {
			throw new Error( 'چک‌پوینت پیدا نشد.' );
		}

		/** @type {string[]} */
		const restored = [];
		/** @type {string[]} */
		const deleted = [];

		if ( opts.files !== false ) {
			// از تازه‌ترین به قدیمی‌ترین — تا نسخهٔ نهایی، همان لحظهٔ هدف باشد.
			for ( let i = this.items.length - 1; i >= target; i-- ) {
				const cp = this.items[ i ];
				for ( const [ rel, info ] of Object.entries( cp.files ) ) {
					const abs = path.join( this.workspace, rel );
					if ( info.existed && info.backup ) {
						const data = await fs.readFile( path.join( this.dir, info.backup ) ).catch( () => null );
						if ( data ) {
							await fs.mkdir( path.dirname( abs ), { recursive: true } );
							await fs.writeFile( abs, data );
							if ( ! restored.includes( rel ) ) {
								restored.push( rel );
							}
						}
					} else {
						await fs.rm( abs, { force: true } ).catch( () => {} );
						if ( ! deleted.includes( rel ) ) {
							deleted.push( rel );
						}
					}
				}
			}
		}

		const messageCount = this.items[ target ].messageCount;

		// چک‌پوینت‌های بعد از هدف دیگر معنا ندارند.
		this.items = this.items.slice( 0, target );
		await this.#save();

		return { restored, deleted, messageCount };
	}

	async clear() {
		this.items = [];
		this.loaded = true;
		await fs.rm( this.dir, { recursive: true, force: true } ).catch( () => {} );
	}
}
