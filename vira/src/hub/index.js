/**
 * هاب پرووایدر — نقطهٔ اتصال همهٔ قطعه‌ها.
 *
 * از بیرون، هاب **دقیقاً شکل یک پرووایدر** است: `listModels()` و `stream(req)`. این
 * تصمیم عمدی است و بزرگ‌ترین صرفه‌جویی این کار بود — عامل، زیرعامل، فشرده‌ساز و همهٔ
 * مسیرهای موجود بدون یک خط تغییر با هاب کار می‌کنند.
 *
 * داخلش این اتفاق‌ها می‌افتد:
 *
 *   تشخیص جنس درخواست → انتخاب ترکیب و راهبرد → فهرست نامزدها
 *     → سقف هزینه → کش → وصله‌های شناخته‌شده → تماس
 *       → موفق؟ ثبت سلامت، هزینه، و امتیاز یادگیری
 *       → ناموفق؟ امضای خطا → نردبان عیب‌یاب → تکرار → نامزد بعدی
 *
 * یک قید سخت در جریان استریم: تکرار فقط تا وقتی مجاز است که **هیچ نویسه‌ای به کاربر
 * نرفته باشد**. بعد از اولین کاراکتر، عوض‌کردن مدل یعنی چسباندن دو نیمه‌جملهٔ متفاوت
 * به هم؛ آنجا خطا را صادقانه نشان می‌دهیم.
 */

import { HOME } from '../config.js';
import { createConnectionProvider } from '../providers/index.js';
import { estimateCost, recordUsage } from '../usage.js';
import { textOf } from '../content.js';
import { explain } from '../errors.js';

import { defaultHub, normalizeConnection, normalizeModel, normalizeCombo, publicHub, validateConnection, modelKey } from './schema.js';
import { loadHub, saveHub, loadHubState, saveHubState, loadLedger, saveLedger } from './store.js';
import { classify } from './classify.js';
import { Health } from './health.js';
import { Learning } from './learning.js';
import { Budget } from './budget.js';
import { ResponseCache } from './cache.js';
import { route } from './router.js';
import { hubReady, mergeDiscovered, modelsOf } from './registry.js';
import { signatureOf, statusOf, sanitize } from './signature.js';
import { applyPatches, validatePatch } from './repair.js';
import { Ledger } from './ledger.js';
import { Diagnoser } from './diagnoser.js';

const MAX_PATCH_ROUNDS = 3;

export class Hub {
	/**
	 * @param {{home?:string, emit?:(ev:any)=>void, now?:()=>number, fetchDocs?:(q:string)=>Promise<string>}} [opts]
	 */
	constructor( opts = {} ) {
		this.home = opts.home || HOME;
		this.emit = opts.emit || ( () => {} );
		this.now = opts.now || ( () => Date.now() );
		this.fetchDocs = opts.fetchDocs || null;

		this.data = defaultHub();
		this.health = new Health( { now: this.now } );
		this.learning = new Learning();
		this.budget = new Budget( { now: this.now } );
		this.cache = new ResponseCache( { now: this.now } );
		this.ledger = new Ledger( { now: this.now } );
		this.diagnoser = new Diagnoser( { ledger: this.ledger, now: this.now, log: ( row ) => this.emit( { type: 'hub-diagnose', ...row } ) } );
		/** @type {any[]} */
		this.recent = [];
		this.loaded = false;
	}

	async load() {
		this.data = await loadHub( this.home );
		const state = await loadHubState( this.home );
		this.health = new Health( { now: this.now, state: state.health } );
		this.learning = new Learning( { state: state.learning } );
		this.budget = new Budget( { now: this.now, limits: this.data.budget, state: state.spend } );
		this.cache = new ResponseCache( { ...this.data.cache, now: this.now } );
		this.ledger = new Ledger( { data: await loadLedger( this.home ), now: this.now } );
		this.#rebuildDiagnoser();
		this.loaded = true;
		return this;
	}

	async save() {
		this.budget.setLimits( this.data.budget );
		this.cache.enabled = this.data.cache?.enabled !== false;
		this.cache.ttlMs = this.data.cache?.ttlMs ?? this.cache.ttlMs;
		this.#rebuildDiagnoser();
		await saveHub( this.home, this.data );
		return this.data;
	}

	async saveState() {
		await saveHubState( this.home, {
			health: this.health.toJSON(),
			learning: this.learning.toJSON(),
			spend: this.budget.toJSON(),
		} );
		if ( this.ledger.dirty ) {
			await saveLedger( this.home, this.ledger.toJSON() );
			this.ledger.dirty = false;
		}
	}

	#rebuildDiagnoser() {
		const cfg = this.data.diagnoser || {};
		const conn = this.data.connections?.[ cfg.connectionId ];
		const callModel =
			cfg.enabled && conn && cfg.model
				? async ( prompt ) => {
						const provider = createConnectionProvider( conn, { modelId: cfg.model, proxy: this.data.proxy?.url } );
						let out = '';
						for await ( const ev of provider.stream( {
							model: cfg.model,
							messages: [ { role: 'user', content: prompt } ],
							maxTokens: 700,
							temperature: 0,
						} ) ) {
							if ( ev.type === 'text' ) {
								out += ev.text;
							}
							if ( ev.type === 'error' ) {
								throw new Error( ev.error );
							}
						}
						return out;
				  }
				: null;

		this.diagnoser = new Diagnoser( {
			ledger: this.ledger,
			config: cfg,
			now: this.now,
			callModel,
			fetchDocs: cfg.internet ? this.fetchDocs : null,
			log: ( row ) => this.emit( { type: 'hub-diagnose', ...row } ),
		} );
	}

	// ---------------------------------------------------------------- تعریف

	/** @param {any} raw */
	async saveConnection( raw ) {
		const previous = raw?.id ? this.data.connections?.[ raw.id ] : null;
		const conn = normalizeConnection( raw, previous );
		const check = validateConnection( conn );
		if ( ! check.ok ) {
			return { ok: false, error: `تنظیمات ناقص است: ${ check.missing.join( '، ' ) }` };
		}
		// آدرس پایه که عوض شود، وصله‌های دائمیِ قبلی بی‌معنا (و بالقوه خطرناک) می‌شوند.
		if ( previous && previous.baseUrl !== conn.baseUrl ) {
			conn.patches = [];
		}
		this.data.connections[ conn.id ] = conn;
		await this.save();
		return { ok: true, connection: conn };
	}

	/** @param {string} id */
	async removeConnection( id ) {
		if ( ! this.data.connections?.[ id ] ) {
			return { ok: false, error: 'اتصال پیدا نشد.' };
		}
		delete this.data.connections[ id ];
		// مدل‌های یتیم بی‌معنا هستند و در مسیریابی فقط سروصدا می‌سازند.
		for ( const [ key, model ] of Object.entries( this.data.models || {} ) ) {
			if ( model.connectionId === id ) {
				delete this.data.models[ key ];
				this.learning.forget( key );
			}
		}
		if ( this.data.diagnoser?.connectionId === id ) {
			this.data.diagnoser.connectionId = '';
		}
		await this.save();
		return { ok: true };
	}

	/** @param {any} raw */
	async saveModel( raw ) {
		const key = raw?.key;
		const previous = key ? this.data.models?.[ key ] : null;
		if ( ! previous && ! ( raw?.connectionId && raw?.modelId ) ) {
			return { ok: false, error: 'مدل پیدا نشد.' };
		}
		const model = normalizeModel( { ...raw, key: key || modelKey( raw.connectionId, raw.modelId ), editedByAdmin: true }, previous );
		this.data.models[ model.key ] = model;
		await this.save();
		return { ok: true, model };
	}

	/**
	 * @param {string} key
	 * @param {boolean} [enabled]
	 */
	async toggleModel( key, enabled ) {
		const model = this.data.models?.[ key ];
		if ( ! model ) {
			return { ok: false, error: 'مدل پیدا نشد.' };
		}
		model.enabled = enabled === undefined ? ! model.enabled : Boolean( enabled );
		await this.save();
		return { ok: true, model };
	}

	/** @param {any} raw */
	async saveCombo( raw ) {
		const combo = normalizeCombo( raw, raw?.id ? this.data.combos?.[ raw.id ] : null );
		this.data.combos[ combo.id ] = combo;
		await this.save();
		return { ok: true, combo };
	}

	/** @param {string} id */
	async removeCombo( id ) {
		delete this.data.combos[ id ];
		for ( const [ cat, comboId ] of Object.entries( this.data.categoryCombo || {} ) ) {
			if ( comboId === id ) {
				delete this.data.categoryCombo[ cat ];
			}
		}
		await this.save();
		return { ok: true };
	}

	/** @param {any} patch */
	async update( patch ) {
		for ( const field of [ 'routing', 'budget', 'cache', 'diagnoser', 'proxy' ] ) {
			if ( patch?.[ field ] ) {
				this.data[ field ] = { ...this.data[ field ], ...patch[ field ] };
			}
		}
		if ( patch?.categoryCombo ) {
			this.data.categoryCombo = { ...this.data.categoryCombo, ...patch.categoryCombo };
		}
		if ( patch?.enabled !== undefined ) {
			this.data.enabled = Boolean( patch.enabled );
		}
		await this.save();
		return { ok: true };
	}

	// ---------------------------------------------------------------- کشف و آزمون

	/** @param {string} id */
	async discover( id ) {
		const conn = this.data.connections?.[ id ];
		if ( ! conn ) {
			return { ok: false, error: 'اتصال پیدا نشد.' };
		}
		try {
			const provider = createConnectionProvider( conn, { proxy: this.data.proxy?.url } );
			const ids = await provider.listModels();
			const merged = mergeDiscovered( this.data, id, ids );
			this.data.models = merged.models;
			await this.save();
			return { ok: true, added: merged.added, kept: merged.kept, missing: merged.missing, total: ids.length };
		} catch ( e ) {
			const info = explain( e, { baseUrl: conn.baseUrl, provider: conn.provider, proxy: conn.proxy || this.data.proxy?.url || '' } );
			return { ok: false, error: info.message, hint: info.hint };
		}
	}

	/**
	 * @param {string} id
	 * @param {string} [model]
	 */
	async testConnection( id, model ) {
		const conn = this.data.connections?.[ id ];
		if ( ! conn ) {
			return { ok: false, error: 'اتصال پیدا نشد.' };
		}
		const target = model || modelsOf( this.data, id ).find( ( m ) => m.enabled )?.modelId || '';
		if ( ! target ) {
			return { ok: false, error: 'هیچ مدلی برای این اتصال نمی‌شناسم. اول «کشف مدل‌ها» را بزن.' };
		}
		const started = this.now();
		try {
			const provider = createConnectionProvider( conn, { modelId: target, proxy: this.data.proxy?.url } );
			let text = '';
			for await ( const ev of provider.stream( {
				model: target,
				messages: [ { role: 'user', content: 'بگو: سلام' } ],
				maxTokens: 16,
			} ) ) {
				if ( ev.type === 'text' ) {
					text += ev.text;
				}
				if ( ev.type === 'error' ) {
					throw new Error( ev.error );
				}
			}
			const ms = this.now() - started;
			this.health.record( modelKey( id, target ), { ok: true, ms } );
			return { ok: true, ms, model: target, message: `پاسخ گرفتم (${ ms } میلی‌ثانیه): «${ text.trim().slice( 0, 60 ) || '(خالی)' }»` };
		} catch ( e ) {
			const info = explain( e, { baseUrl: conn.baseUrl, model: target, proxy: conn.proxy || this.data.proxy?.url || '' } );
			this.health.record( modelKey( id, target ), { ok: false, kind: info.kind, message: info.message } );
			return { ok: false, error: info.message, hint: info.hint, kind: info.kind };
		}
	}

	// ---------------------------------------------------------------- مسیریابی

	/**
	 * «این درخواست به کجا می‌رود؟» — همان صفحهٔ آزمون در بند ۷.
	 * @param {{text?:string, hasImages?:boolean, tools?:string[], comboId?:string}} input
	 */
	explainRoute( input = {} ) {
		const classification = classify( { text: input.text, hasImages: input.hasImages, tools: input.tools } );
		const category = this.#categoryFor( classification );
		const routed = route( {
			hub: this.data,
			health: this.health,
			learning: this.learning,
			category,
			needsTools: ( input.tools || [] ).length > 0,
			needsVision: Boolean( input.hasImages ),
			estimateTokens: Math.max( 500, Math.round( String( input.text || '' ).length / 3.2 ) ),
			comboId: input.comboId,
		} );
		return { classification, ...routed, budget: this.budget.check( { task: category } ) };
	}

	#categoryFor( classification ) {
		const min = Number( this.data.routing?.classifierMinConfidence ?? 0.45 );
		// اطمینان پایین یعنی «نمی‌دانم». در آن حالت دستهٔ عمومی امن‌تر از یک حدس بد است،
		// چون حدس بد درخواست کد را به یک مدل ارزانِ خلاصه‌ساز می‌فرستد.
		return classification.confidence >= min ? classification.category : 'general';
	}

	// ---------------------------------------------------------------- اجرا

	/** آداپتوری که عامل می‌گیرد — از بیرون از یک پرووایدر معمولی قابل تشخیص نیست. */
	adapter() {
		const self = this;
		return {
			id: 'hub',
			kind: /** @type {const} */ ( 'openai' ),
			isHub: true,
			async listModels() {
				return Object.values( self.data.models || {} )
					.filter( ( m ) => m.enabled )
					.map( ( m ) => m.key )
					.sort();
			},
			stream( req ) {
				return self.stream( req );
			},
		};
	}

	/**
	 * @param {any} req
	 * @returns {AsyncGenerator<any>}
	 */
	async *stream( req ) {
		const ready = hubReady( this.data );
		if ( ! ready.ok ) {
			yield { type: 'error', error: `هاب آماده نیست: ${ ready.reason }` };
			return;
		}

		const lastUser = [ ...( req.messages || [] ) ].reverse().find( ( m ) => m.role === 'user' );
		const text = textOf( lastUser?.content || '' );
		const hasImages = Array.isArray( lastUser?.content ) && lastUser.content.some( ( p ) => p.type === 'image' );

		// ابزارهای **درگیر**، نه ابزارهای **در دسترس**.
		//
		// این را تأیید زنده پیدا کرد: عامل در هر نوبت فهرست کامل بیست‌وچند ابزار را
		// می‌فرستد. اگر همان فهرست را نشانهٔ جنس درخواست بگیریم، «سلام، خودت را معرفی کن»
		// هم کدنویسی تشخیص داده می‌شود و کل مسیریابی بی‌معنا می‌شود.
		const { usedTools, files } = recentToolUse( req.messages );
		const classification = classify( { text, hasImages, tools: usedTools, files } );
		const category = req.category || this.#categoryFor( classification );

		// مدل درخواستی می‌تواند یک کلید مشخص باشد («این را با همین مدل بزن») یا `auto`.
		const pinModel = this.data.models?.[ req.model ] ? req.model : '';
		const comboId = /^combo:/.test( String( req.model || '' ) ) ? String( req.model ).slice( 6 ) : req.comboId;

		const routed = route( {
			hub: this.data,
			health: this.health,
			learning: this.learning,
			category,
			needsTools: ( req.tools || [] ).length > 0,
			needsVision: hasImages,
			estimateTokens: Math.max( 500, Math.round( text.length / 3.2 ) ),
			comboId,
			pinModel,
		} );

		if ( ! routed.candidates.length ) {
			/*
			 * پیام باید بگوید «چه کار کنم»، نه فقط «نشد».
			 *
			 * حالت پرتکرار و گیج‌کننده: کاربر تصویر می‌فرستد و **هیچ‌کدام** از مدل‌های
			 * روشن بینا نیستند. `caps.vision` فقط از روی نام مدل حدس زده می‌شود
			 * (registry.js)، پس هر مدلی که الگو نشناسدش «بدون بینایی» می‌ماند و کاربر
			 * پیام مبهم «هیچ مدلی در دسترس نیست» می‌گیرد در حالی که همه‌چیز سالم است.
			 * (DESIGN-HUB-UI-FIX §۲.۵)
			 */
			const reasons = routed.blocked.map( ( b ) => b.reason );
			const allVision = reasons.length > 0 && reasons.every( ( r ) => r === 'بینایی ندارد' );

			if ( allVision ) {
				yield {
					type: 'error',
					error: 'هیچ‌کدام از مدل‌های روشن، بینایی (تصویر) ندارند.',
					hint: 'یک مدل بینا روشن کن، یا در «هاب پرووایدر ← اتصال‌ها ← مدل‌ها» تیک «بینایی» همین مدل را دستی بزن. قابلیت‌ها از روی نام مدل حدس زده می‌شوند و نام‌های تازه شناخته نمی‌شوند.',
					kind: 'capability',
				};
				return;
			}

			// شمردنِ دلایل، خواناتر از فهرست‌کردن چهار کلید تصادفی است.
			const tally = new Map();
			for ( const r of reasons ) {
				tally.set( r, ( tally.get( r ) || 0 ) + 1 );
			}
			const why = [ ...tally.entries() ]
				.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
				.slice( 0, 3 )
				.map( ( [ r, n ] ) => `${ r } (${ n } مدل)` )
				.join( ' · ' );

			yield {
				type: 'error',
				error: `هیچ مدلی برای این درخواست در دسترس نیست.${ why ? ` — ${ why }` : '' }`,
				hint: 'در «هاب پرووایدر ← سلامت و مصرف» ببین کدام اتصال مدارشکنش باز است، یا از «ریست و رفع خطا» استفاده کن.',
				kind: 'routing',
			};
			return;
		}

		this.emit( {
			type: 'hub-route',
			category,
			confidence: classification.confidence,
			strategy: routed.strategy,
			candidates: routed.candidates.slice( 0, 3 ).map( ( c ) => ( { key: c.key, score: c.score } ) ),
			reasons: classification.reasons,
		} );

		const maxAttempts = Math.min( this.data.routing?.maxAttempts || 3, routed.candidates.length );
		const fallback = this.data.routing?.fallback !== false;
		const limit = fallback ? maxAttempts : 1;

		/** @type {any} */
		let lastError = null;
		/** گزارش هر تلاش، برای پیام پایانی — «چه چیزی آزموده شد و هرکدام چه داد». */
		/** @type {{label:string, reason:string}[]} */
		const tried = [];

		for ( let i = 0; i < limit; i++ ) {
			const candidate = routed.candidates[ i ];

			// سقف هزینه: عبور از سقف، درخواست را **رد** می‌کند. این با هشدار فرق دارد و
			// عمداً همین‌جاست، قبل از هر تماس شبکه‌ای.
			const gate = this.budget.check( { task: category, admin: req.admin, estimate: candidate.cost || 0 } );
			if ( ! gate.allowed ) {
				yield { type: 'error', error: gate.reason };
				return;
			}
			if ( gate.warn ) {
				this.emit( { type: 'hub-budget-warn', ratio: gate.ratio } );
			}

			const outcome = yield* this.#attempt( candidate, req, category );
			if ( outcome.ok ) {
				return;
			}
			lastError = outcome.error;
			tried.push( {
				label: this.data.models?.[ candidate.key ]?.label || candidate.modelId || candidate.key,
				reason: outcome.error?.message || 'بدون توضیح',
			} );
			if ( outcome.emitted ) {
				// حرفی از این مدل به کاربر رفته؛ نمی‌شود وسط جمله مدل عوض کرد.
				yield { type: 'error', error: outcome.error?.message || 'ارتباط با مدل قطع شد.' };
				return;
			}
			this.emit( { type: 'hub-failover', from: candidate.key, reason: outcome.error?.message || '' } );
		}

		/*
		 * پیام پایانی باید سه چیز را بگوید: چند مسیر آزموده شد، هرکدام چه داد، و گام
		 * بعدی چیست. تا ۰.۹.۶ فقط «آخرین خطا» می‌آمد و `hint` عملاً دیده نمی‌شد، پس سه
		 * علتِ کاملاً متفاوت (کلید غلط، تحریم، پراکسی خاموش) یک جملهٔ مبهم می‌دادند.
		 */
		const detail = tried.length
			? tried.map( ( t ) => `«${ t.label }»: ${ t.reason }` ).join( ' · ' )
			: '';
		const networkish = /network|fetch|ENOTFOUND|ECONNREFUSED|timeout|وصل نشدم/i.test( String( lastError?.message || '' ) );

		yield {
			type: 'error',
			error: tried.length > 1
				? `هر ${ tried.length } مسیر شکست خورد. ${ detail }`
				: `مسیر شکست خورد. ${ detail || lastError?.message || '' }`.trim(),
			hint: lastError?.hint || ( networkish
				? 'اگر Hiddify یا مشابهش روشن است، آدرسش را در تنظیمات ← پراکسی وارد کن؛ Node پراکسی سیستم را خودش برنمی‌دارد. بعد «تست پراکسی» را بزن. جزئیات کامل در تنظیمات ← لاگ‌ها.'
				: 'جزئیات کامل هر تلاش در تنظیمات ← لاگ‌ها ثبت شده است.' ),
			kind: lastError?.kind,
		};
	}

	/**
	 * یک نامزد، با نردبان عیب‌یابی.
	 *
	 * @param {any} candidate
	 * @param {any} req
	 * @param {string} category
	 */
	async *#attempt( candidate, req, category ) {
		const conn = this.data.connections[ candidate.connectionId ];
		const model = this.data.models[ candidate.key ];
		const cacheKey = ResponseCache.keyOf( req, candidate.key );

		const cached = this.cache.get( cacheKey );
		if ( cached ) {
			this.emit( { type: 'hub-cache-hit', key: candidate.key } );
			for ( const ev of cached ) {
				yield ev;
			}
			return { ok: true, emitted: true };
		}

		// از وصله‌های **دائمیِ** همین اتصال شروع می‌کنیم، نه از صفر.
		//
		// چرا مهم است: در اجرای زنده دیدیم که وقتی راه‌حل فقط در دفتر باشد، هر درخواست
		// اول یک بار شکست می‌خورد و بعد تعمیر می‌شود. یک بار پذیرفتنی است، هزار بار نه.
		/** @type {any[]} */
		let applied = [ ...( conn.patches || [] ) ];
		/** @type {any} */
		let suggestion = null;

		for ( let round = 0; round <= MAX_PATCH_ROUNDS; round++ ) {
			const started = this.now();
			this.health.begin( candidate.key );

			/** @type {any[]} */
			const collected = [];
			let emitted = false;
			/** @type {any} */
			let failure = null;
			let usage = { inputTokens: 0, outputTokens: 0 };

			try {
				// وصله‌ها هم روی خود اتصال می‌نشینند (آدرس پایه، هدر، سبک احراز) و هم روی
				// شکل بدنه (`overrides`). هر دو از یک جا می‌آیند تا نشود یکی را یادت برود.
				const patched = applyPatches( { ...conn, overrides: {} }, applied );
				const wired = createConnectionProvider( patched, {
					modelId: candidate.modelId,
					overrides: patched.overrides,
					proxy: this.data.proxy?.url,
				} );

				for await ( const ev of wired.stream( { ...req, model: candidate.modelId } ) ) {
					if ( ev.type === 'error' ) {
						failure = new Error( ev.error );
						break;
					}
					if ( ev.type === 'usage' ) {
						usage = { inputTokens: ev.inputTokens || 0, outputTokens: ev.outputTokens || 0 };
					}
					collected.push( ev );
					if ( ev.type === 'text' || ev.type === 'thinking' ) {
						emitted = true;
					}
					yield ev;
				}
			} catch ( e ) {
				failure = e;
			} finally {
				this.health.end( candidate.key );
			}

			const ms = this.now() - started;

			if ( ! failure ) {
				const cost = estimateCost( candidate.modelId, usage, this.#pricing() );
				this.health.record( candidate.key, { ok: true, ms } );
				this.learning.record( { modelKey: candidate.key, category, ok: true, ms, cost } );
				this.budget.record( cost || 0, { task: category, admin: req.admin } );
				this.cache.set( cacheKey, collected );
				this.#remember( { key: candidate.key, category, ok: true, ms, cost, model: model?.label || candidate.modelId } );
				if ( cost ) {
					await recordUsage( this.home, { model: candidate.modelId, ...usage, cost } ).catch( () => {} );
				}
				if ( suggestion ) {
					// پلهٔ ۴: وصله واقعاً جواب داد — حالا ارزش ثبت دارد.
					this.diagnoser.report( { ...suggestion, ok: true } );
					if ( this.data.diagnoser?.autoPromote ) {
						await this.promotePatch( suggestion.signature ).catch( () => {} );
					}
				}
				await this.saveState().catch( () => {} );
				return { ok: true, emitted: emitted || collected.length > 0 };
			}

			const info = explain( failure, { baseUrl: conn.baseUrl, model: candidate.modelId, proxy: conn.proxy || this.data.proxy?.url || '' } );
			const signature = signatureOf( {
				kind: info.kind,
				status: statusOf( failure?.message ),
				message: sanitize( String( failure?.message || '' ) ),
				connectionKind: conn.kind,
			} );

			this.health.record( candidate.key, { ok: false, ms, kind: info.kind, message: info.message } );
			this.learning.record( { modelKey: candidate.key, category, ok: false, ms } );
			this.#remember( { key: candidate.key, category, ok: false, ms, error: info.message, signature } );

			if ( suggestion ) {
				this.diagnoser.report( { ...suggestion, ok: false } );
				suggestion = null;
			}

			// از اینجا به بعد فقط وقتی معنا دارد که هنوز چیزی به کاربر نرفته باشد.
			if ( emitted || round === MAX_PATCH_ROUNDS ) {
				await this.saveState().catch( () => {} );
				return { ok: false, emitted, error: info };
			}

			const next = await this.diagnoser.suggest( {
				signature,
				error: { status: statusOf( failure?.message ), message: String( failure?.message || '' ), kind: info.kind },
				cfg: { baseUrl: conn.baseUrl, kind: conn.kind, authStyle: conn.authStyle, applied },
				shape: { model: candidate.modelId, tools: ( req.tools || [] ).length, stream: true },
				domain: 'hub',
			} );

			if ( ! next ) {
				await this.saveState().catch( () => {} );
				return { ok: false, emitted, error: info };
			}

			applied = [ ...applied, ...next.patches ];
			suggestion = { signature, source: next.source, patches: applied, why: next.why, domain: 'hub', connectionId: candidate.connectionId };
			this.emit( { type: 'hub-repair', key: candidate.key, source: next.source, why: next.why, patches: next.patches } );
		}

		return { ok: false, emitted: false, error: { message: 'سقف تعمیرهای پیاپی پر شد.' } };
	}

	/**
	 * تأیید مدیر: این وصله ماندگار شود.
	 *
	 * ماندگاری یعنی دو چیز: در دفتر «دائمی» علامت می‌خورد، و روی خود اتصال می‌نشیند تا
	 * دفعهٔ بعد **قبل** از اولین تلاش اعمال شود، نه بعد از اولین شکست.
	 *
	 * @param {string} signature
	 */
	async promotePatch( signature ) {
		const entry = this.ledger.promote( signature );
		if ( ! entry ) {
			return { ok: false, error: 'این امضا در دفتر نیست.' };
		}
		const conn = this.data.connections?.[ entry.connectionId ];
		if ( conn ) {
			const list = [ ...( conn.patches || [] ) ];
			for ( const patch of entry.patches || [] ) {
				// دوباره اعتبارسنجی می‌شود: وصله‌ای که ماه پیش امن بود، با آدرس پایهٔ
				// امروز ممکن است نباشد.
				const check = validatePatch( patch, { baseUrl: conn.baseUrl } );
				if ( check.ok && ! list.some( ( p ) => JSON.stringify( p ) === JSON.stringify( check.patch ) ) ) {
					list.push( check.patch );
				}
			}
			conn.patches = list;
			await this.save();
		}
		await this.saveState();
		return { ok: true, entry };
	}

	/** مدیر می‌گوید این را یاد نگرفته باش — هم از دفتر، هم از روی اتصال. */
	async forgetPatch( signature ) {
		const entry = this.ledger.entries[ signature ];
		const conn = entry?.connectionId ? this.data.connections?.[ entry.connectionId ] : null;
		if ( conn?.patches?.length ) {
			const drop = new Set( ( entry.patches || [] ).map( ( p ) => JSON.stringify( p ) ) );
			conn.patches = conn.patches.filter( ( p ) => ! drop.has( JSON.stringify( p ) ) );
			await this.save();
		}
		this.ledger.forget( signature );
		await this.saveState();
		return { ok: true };
	}

	#pricing() {
		/** @type {Record<string, {in:number,out:number}>} */
		const table = {};
		for ( const model of Object.values( this.data.models || {} ) ) {
			if ( model.priceIn !== null || model.priceOut !== null ) {
				table[ String( model.modelId ).toLowerCase() ] = { in: model.priceIn || 0, out: model.priceOut || 0 };
			}
		}
		return table;
	}

	#remember( row ) {
		this.recent.unshift( { at: new Date( this.now() ).toISOString(), ...row } );
		if ( this.recent.length > 50 ) {
			this.recent.pop();
		}
	}

	// ---------------------------------------------------------------- گزارش

	/**
	 * ریست یک اتصال — «ریست و رفع خطا»ی مدیر (طرح §۴ DESIGN-PROVIDER-UI).
	 * @param {string} id
	 */
	resetProvider( id ) {
		if ( ! this.data.connections?.[ id ] ) {
			return { ok: false, error: 'اتصال پیدا نشد.' };
		}
		return { ok: true, cleared: this.health.resetPrefix( `${ id }::` ) };
	}

	snapshot() {
		return {
			hub: publicHub( this.data ),
			ready: hubReady( this.data ),
			health: this.health.snapshot(),
			traffic: this.health.traffic(),
			learning: this.learning.table(),
			budget: this.budget.snapshot(),
			cache: this.cache.stats(),
			ledger: this.ledger.list( 'hub' ),
			diagnoser: this.diagnoser.snapshot(),
			recent: this.recent.slice( 0, 20 ),
		};
	}
}

/**
 * ابزارهایی که در همین گفتگو واقعاً صدا زده شده‌اند و فایل‌هایی که لمس کرده‌اند.
 *
 * فقط چند نوبت آخر، چون جنس درخواستِ *الان* مهم است نه کاری که نیم ساعت پیش شد.
 *
 * @param {any[]} messages
 */
export function recentToolUse( messages ) {
	/** @type {string[]} */
	const usedTools = [];
	/** @type {string[]} */
	const files = [];
	for ( const m of ( messages || [] ).slice( -12 ) ) {
		for ( const call of m?.toolCalls || [] ) {
			usedTools.push( call.name );
			for ( const key of [ 'path', 'file_path', 'file', 'notebook_path' ] ) {
				if ( typeof call.input?.[ key ] === 'string' ) {
					files.push( call.input[ key ] );
				}
			}
		}
	}
	return { usedTools, files };
}

export { defaultHub, publicHub };
