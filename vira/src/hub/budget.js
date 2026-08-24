/**
 * سقف هزینه (بند ۱۲ و ۱۶).
 *
 * سه سقف هم‌زمان: کل روزانه، هر مدیر، هر کار. در ۸۰٪ هشدار، در ۱۰۰٪ **رد**.
 *
 * قید بند ۱۶: مکانیزم ساخته می‌شود، عدد خالی می‌ماند. پس `null` یعنی «بی‌سقف» و هیچ
 * عددی از خودمان درنمی‌آوریم — سقفِ حدسی بدتر از بی‌سقفی است، چون کار را وسط روز
 * می‌خواباند بی‌آنکه کسی خواسته باشد.
 */

/** @param {number} [now] */
export function today( now ) {
	return new Date( now ?? Date.now() ).toISOString().slice( 0, 10 );
}

export class Budget {
	/**
	 * @param {{limits?:any, state?:any, now?:()=>number}} [opts]
	 */
	constructor( opts = {} ) {
		this.now = opts.now || ( () => Date.now() );
		this.limits = { daily: null, perAdmin: null, perTask: null, warnAt: 0.8, ...( opts.limits || {} ) };
		this.state = { day: today( this.now() ), total: 0, admins: {}, tasks: {}, ...( opts.state || {} ) };
		this.#rollover();
	}

	#rollover() {
		const day = today( this.now() );
		if ( this.state.day !== day ) {
			this.state = { day, total: 0, admins: {}, tasks: {} };
		}
	}

	/** @param {any} limits */
	setLimits( limits ) {
		this.limits = { ...this.limits, ...( limits || {} ) };
		return this.limits;
	}

	/**
	 * آیا این درخواست اجازهٔ رفتن دارد؟
	 *
	 * @param {{admin?:string, task?:string, estimate?:number}} scope
	 * @returns {{allowed:boolean, warn:boolean, reason:string, ratio:number}}
	 */
	check( scope = {} ) {
		this.#rollover();
		const estimate = Number( scope.estimate ) || 0;
		/** @type {{name:string, spent:number, limit:number|null}[]} */
		const buckets = [
			{ name: 'سقف روزانهٔ کل', spent: this.state.total, limit: num( this.limits.daily ) },
			{ name: 'سقف این مدیر', spent: this.state.admins[ scope.admin || '' ] || 0, limit: scope.admin ? num( this.limits.perAdmin ) : null },
			{ name: 'سقف این کار', spent: this.state.tasks[ scope.task || '' ] || 0, limit: scope.task ? num( this.limits.perTask ) : null },
		];

		let warn = false;
		let ratio = 0;
		for ( const b of buckets ) {
			if ( b.limit === null ) {
				continue;
			}
			const after = b.spent + estimate;
			ratio = Math.max( ratio, b.limit > 0 ? after / b.limit : 0 );
			// `b.spent >= b.limit` جدا لازم است: وقتی هزینهٔ درخواست بعدی را نمی‌دانیم
			// (مدل بدون قیمت)، تخمین صفر است و شرط `after > limit` هیچ‌وقت نمی‌گیرد —
			// یعنی سقفِ دقیقاً پرشده، بی‌اثر می‌ماند. این را تست پیدا کرد.
			if ( after > b.limit || b.spent >= b.limit ) {
				return {
					allowed: false,
					warn: true,
					ratio,
					reason: `${ b.name } پر شده است (${ round( b.spent ) } از ${ round( b.limit ) } دلار).`,
				};
			}
			if ( after >= b.limit * ( this.limits.warnAt ?? 0.8 ) ) {
				warn = true;
			}
		}

		return { allowed: true, warn, ratio: Math.round( ratio * 100 ) / 100, reason: '' };
	}

	/**
	 * @param {number} cost
	 * @param {{admin?:string, task?:string}} [scope]
	 */
	record( cost, scope = {} ) {
		this.#rollover();
		const value = Number( cost ) || 0;
		this.state.total += value;
		if ( scope.admin ) {
			this.state.admins[ scope.admin ] = ( this.state.admins[ scope.admin ] || 0 ) + value;
		}
		if ( scope.task ) {
			this.state.tasks[ scope.task ] = ( this.state.tasks[ scope.task ] || 0 ) + value;
		}
		return this.state;
	}

	snapshot() {
		this.#rollover();
		return {
			day: this.state.day,
			total: round( this.state.total ),
			admins: this.state.admins,
			tasks: this.state.tasks,
			limits: this.limits,
			// وقتی سقف خالی است، درصد معنا ندارد — و رابط نباید عدد ساختگی نشان بدهد.
			usedRatio: num( this.limits.daily ) ? Math.round( ( this.state.total / this.limits.daily ) * 100 ) / 100 : null,
		};
	}

	toJSON() {
		return this.state;
	}
}

function num( value ) {
	const n = Number( value );
	return Number.isFinite( n ) && n > 0 ? n : null;
}

function round( n ) {
	return Math.round( ( Number( n ) || 0 ) * 10_000 ) / 10_000;
}
