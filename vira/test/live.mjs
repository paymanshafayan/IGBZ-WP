/**
 * آزمون زنده — همان کاری که آدم با ماوس می‌کند، ولی خودکار.
 *
 *   node test/live.mjs
 *
 * چرا جدا از سوئیت واحد: اینجا یک سرور واقعی بالا می‌آید، SSE وصل می‌شود، و همان مسیرهایی
 * صدا زده می‌شوند که رابط کاربری صدا می‌زند. تجربهٔ این پروژه می‌گوید بیشتر باگ‌های واقعی
 * دقیقاً همین‌جا پیدا می‌شوند، نه در تست تابع‌به‌تابع.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const HOME = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-home-' ) );
const WORK = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-work-' ) );
process.env.HOOSHA_HOME = HOME;

const PORT = 7900 + Math.floor( Math.random() * 80 );
const BASE = `http://127.0.0.1:${ PORT }`;

let passed = 0;
const failures = [];

async function step( name, fn ) {
	try {
		await fn();
		passed++;
		process.stdout.write( `  ✓ ${ name }\n` );
	} catch ( e ) {
		failures.push( { name, error: e?.message || String( e ) } );
		process.stdout.write( `  ✗ ${ name }\n      ${ e?.message || e }\n` );
	}
}

/*
 * سندباکس در پیکربندی این آزمون **خاموش** است.
 *
 * پیش‌فرض برنامه از این نسخه روشن است (خواستهٔ کارفرما: بدون اجازه، دست به پروژهٔ واقعی
 * نزن) و چون این ماشین داکر ندارد، هر فرمانی «بسته» رد می‌شود. آزمون زنده باید مسیر
 * واقعی اجرای فرمان را بسنجد، پس همان تصمیمی را می‌گیرد که یک مدیر می‌گیرد: صریح
 * خاموشش می‌کند. خودِ پیش‌فرض، در تست واحد سنجیده می‌شود.
 */
await fs.mkdir( HOME, { recursive: true } );
await fs.writeFile(
	path.join( HOME, 'config.json' ),
	JSON.stringify( { sandbox: { enabled: false } }, null, 2 ),
	'utf8'
);

const { startServer } = await import( '../src/server.js' );
const { server } = await startServer( { port: PORT, host: '127.0.0.1', workspace: WORK } );

// ------------------------------------------------------------ ابزار کمکی

const events = [];
const waiters = [];

function onEvent( ev ) {
	events.push( ev );
	for ( const w of [ ...waiters ] ) {
		if ( w.match( ev ) ) {
			waiters.splice( waiters.indexOf( w ), 1 );
			w.resolve( ev );
		}
	}
}

/** @param {(ev:any)=>boolean} match */
function waitFor( match, ms = 12_000, label = 'رویداد' ) {
	const existing = events.find( match );
	if ( existing ) {
		return Promise.resolve( existing );
	}
	return new Promise( ( resolve, reject ) => {
		const w = { match, resolve };
		waiters.push( w );
		setTimeout( () => {
			if ( waiters.includes( w ) ) {
				waiters.splice( waiters.indexOf( w ), 1 );
				reject( new Error( `${ label } نیامد (${ ms }ms)` ) );
			}
		}, ms ).unref?.();
	} );
}

// جریان رویدادها
const sse = await fetch( `${ BASE }/api/events` );
const reader = sse.body.getReader();
( async () => {
	const decoder = new TextDecoder();
	let buffer = '';
	while ( true ) {
		const { value, done } = await reader.read().catch( () => ( { done: true } ) );
		if ( done ) {
			break;
		}
		buffer += decoder.decode( value, { stream: true } );
		const parts = buffer.split( '\n\n' );
		buffer = parts.pop() || '';
		for ( const part of parts ) {
			const line = part.split( '\n' ).find( ( l ) => l.startsWith( 'data: ' ) );
			if ( line ) {
				try {
					onEvent( JSON.parse( line.slice( 6 ) ) );
				} catch {
					// ping
				}
			}
		}
	}
} )();

const get = ( p ) => fetch( BASE + p ).then( ( r ) => r.json() );
const post = ( p, body ) =>
	fetch( BASE + p, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body || {} ) } ).then( ( r ) =>
		r.json()
	);

async function say( text ) {
	// اگر نوبت قبلی هنوز تمام نشده، پیام تازه با ۴۰۹ رد می‌شود و باعث می‌شود آزمونِ بعدی
	// به‌خاطر گناهِ آزمونِ قبلی قرمز شود. پس اول صبر می‌کنیم بیکار شود.
	for ( let i = 0; i < 100; i++ ) {
		const s = await get( '/api/state' );
		if ( ! s.busy ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	events.length = 0;
	const out = await post( '/api/message', { text } );
	assert.ok( ! out.error, `ارسال پیام شکست خورد: ${ out.error }` );
	return out;
}

async function waitIdle() {
	await waitFor( ( e ) => e.type === 'idle', 15_000, 'پایان نوبت' );
}

// ---------------------------------------------------------------- آزمون‌ها

process.stdout.write( '\nآزمون زنده\n' );

await step( 'وضعیت اولیه: ابزارها، دستورها و پرووایدر آزمایشی حاضرند', async () => {
	const s = await get( '/api/state' );
	assert.ok( s.tools.length >= 15, `تعداد ابزار کم است: ${ s.tools.length }` );
	assert.ok( s.commands.length >= 20 );
	assert.equal( s.config.workspace, WORK );
	assert.ok( s.ready.ok, 'پرووایدر آزمایشی باید آماده باشد' );
} );

await step( 'فهرست کار زنده می‌شود و در وضعیت می‌نشیند', async () => {
	await say( 'کارها خواندن کد، زدن تغییر، اجرای تست' );
	await waitIdle();
	const s = await get( '/api/state' );
	assert.equal( s.todos.length, 3 );
	assert.equal( s.todos[ 0 ].status, 'in_progress' );
} );

await step( 'نوشتن فایل: تأیید می‌خواهد، بعد از اجازه فایل ساخته می‌شود و دیف برمی‌گردد', async () => {
	await say( 'بنویس note.txt سلام' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	assert.match( ask.summary, /note\.txt/ );

	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /ساخته شد/ );
	await waitIdle();

	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'سلام' );
} );

await step( 'ویرایش چندگانه، دیف واقعی با شمار +/− می‌دهد', async () => {
	await say( 'جایگزین note.txt سلام درود' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /\+1 −1/ );
	assert.match( result.output, /^\+\s+1\s+درود$/m );
	await waitIdle();
	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'درود' );
} );

await step( 'چک‌پوینت ساخته می‌شود و بازگشت، فایل را به حالت قبل برمی‌گرداند', async () => {
	const before = await get( '/api/checkpoints' );
	assert.ok( before.checkpoints.length >= 3, 'برای هر پیام باید یک چک‌پوینت باشد' );

	// چک‌پوینتِ نوبتِ «جایگزین» — یعنی آخری.
	const target = before.checkpoints[ before.checkpoints.length - 1 ];
	assert.ok( target.files.includes( 'note.txt' ), 'فایل تغییریافته باید در چک‌پوینت ثبت شده باشد' );

	const out = await post( '/api/rewind', { id: target.id } );
	assert.ok( out.ok );
	assert.deepEqual( out.restored, [ 'note.txt' ] );
	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'سلام' );
} );

await step( 'بازگشت به چک‌پوینتِ قبل از ساخت فایل، فایل را حذف می‌کند', async () => {
	const list = ( await get( '/api/checkpoints' ) ).checkpoints;
	const target = list.find( ( c ) => c.files.includes( 'note.txt' ) ) || list[ list.length - 1 ];
	await post( '/api/rewind', { id: target.id } );
	const exists = await fs
		.stat( path.join( WORK, 'note.txt' ) )
		.then( () => true )
		.catch( () => false );
	assert.equal( exists, false, 'فایلی که آن لحظه نبود، باید حذف شود' );
} );

await step( 'کارت نقشه: تأیید کاربر، حالت را عوض می‌کند و به مدل هم گفته می‌شود', async () => {
	await post( '/api/mode', { mode: 'plan' } );
	await say( 'نقشه بازطراحی صفحهٔ سبد' );
	const ask = await waitFor( ( e ) => e.type === 'ask_user' && e.kind === 'plan', 10_000, 'کارت نقشه' );
	assert.match( ask.plan, /نقشهٔ کار/ );

	await post( '/api/answer', { id: ask.id, value: { approved: true, mode: 'default' }, mode: 'default' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /تأیید کرد/ );
	await waitIdle();

	const s = await get( '/api/state' );
	assert.equal( s.config.permissions.mode, 'default' );
} );

await step( 'کارت پرسش: جواب کاربر به مدل برمی‌گردد', async () => {
	await say( 'بپرس کدام راه؟' );
	const ask = await waitFor( ( e ) => e.type === 'ask_user' && e.kind === 'question', 10_000, 'کارت پرسش' );
	assert.equal( ask.options.length, 2 );

	await post( '/api/answer', { id: ask.id, value: 'راه تمیز' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /راه تمیز/ );
	await waitIdle();
} );

await step( 'شل پس‌زمینه اجرا می‌شود، در فهرست می‌آید و کشته می‌شود', async () => {
	await say( 'پس‌زمینه sleep 30' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	await waitIdle();

	const list = ( await get( '/api/shells' ) ).shells;
	assert.equal( list.length, 1 );
	assert.equal( list[ 0 ].status, 'running' );

	await post( '/api/shells', { action: 'kill', id: list[ 0 ].id } );
	const after = ( await get( '/api/shells' ) ).shells;
	assert.equal( after[ 0 ].status, 'killed' );
} );

await step( 'کانکتور MCP از راه API اضافه، آزموده و حذف می‌شود', async () => {
	const fixture = path.resolve( 'test/fixtures/mcp-server.mjs' );

	const test = await post( '/api/connectors', { action: 'test', name: 'probe', kind: 'stdio', command: 'node', args: fixture } );
	assert.ok( test.ok, `تست کانکتور شکست خورد: ${ test.message }` );
	assert.deepEqual( test.tools, [ 'add', 'boom' ] );

	await post( '/api/connectors', { action: 'save', name: 'probe', kind: 'stdio', command: 'node', args: fixture } );
	let s = await get( '/api/state' );
	assert.ok( s.tools.some( ( t ) => t.name === 'mcp__probe__add' ), 'ابزار MCP باید در رجیستری بیاید' );
	assert.equal( s.connectors.length, 1 );

	await post( '/api/connectors', { action: 'toggle', name: 'probe', enabled: false } );
	s = await get( '/api/state' );
	assert.equal( s.mcp[ 0 ].status, 'disabled' );

	await post( '/api/connectors', { action: 'remove', name: 'probe' } );
	s = await get( '/api/state' );
	assert.equal( s.connectors.length, 0 );
	assert.equal( s.tools.some( ( t ) => t.name.startsWith( 'mcp__' ) ), false );
} );

await step( 'زیرعامل ساخته و حذف می‌شود و در ابزار task دیده می‌شود', async () => {
	const out = await post( '/api/agents', {
		action: 'save',
		name: 'reviewer',
		description: 'مرور کد',
		prompt: 'تو یک مرورگر کد سخت‌گیری.',
		tools: [ 'read_file', 'grep' ],
	} );
	assert.ok( out.ok, out.error );

	let s = await get( '/api/state' );
	assert.equal( s.agents.length, 1 );
	assert.deepEqual( s.agents[ 0 ].tools, [ 'read_file', 'grep' ] );
	const task = s.tools.find( ( t ) => t.name === 'task' );
	assert.match( task.description, /reviewer/ );

	await post( '/api/agents', { action: 'remove', name: 'reviewer' } );
	s = await get( '/api/state' );
	assert.equal( s.agents.length, 0 );
} );

await step( 'دستور کاربر ساخته می‌شود و واقعاً به پرامپت باز می‌شود', async () => {
	await post( '/api/commands', { action: 'save', name: 'salam', description: 'سلام کن', body: 'بگو سلام به $1' } );
	let s = await get( '/api/state' );
	assert.ok( s.commands.some( ( c ) => c.name === 'salam' ) );

	await say( '/salam دنیا' );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.text, 'بگو سلام به دنیا' );
	await waitIdle();

	await post( '/api/commands', { action: 'remove', name: 'salam' } );
	s = await get( '/api/state' );
	assert.equal( s.commands.some( ( c ) => c.name === 'salam' ), false );
} );

await step( 'قواعد مجوز از API ذخیره می‌شود و دروازه را باز می‌کند', async () => {
	await post( '/api/permissions', { mode: 'default', allow: [ 'bash:echo' ], ask: [], deny: [] } );
	await say( '!echo سلام' );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'اجرای بدون پرسش' );
	assert.match( result.output, /سلام/ );
	await waitIdle();

	const denied = events.find( ( e ) => e.type === 'permission_request' );
	assert.equal( denied, undefined, 'با قاعدهٔ allow نباید پرسشی می‌آمد' );

	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );
} );

await step( 'حافظهٔ پروژه نوشته و خوانده می‌شود', async () => {
	await post( '/api/memory', { text: '# قواعد\n- تست' } );
	const out = await get( '/api/memory' );
	assert.match( out.text, /قواعد/ );
	const s = await get( '/api/state' );
	assert.equal( s.memory, true );
} );

await step( 'جستجوی فایل برای منوی «@» کار می‌کند', async () => {
	await fs.writeFile( path.join( WORK, 'readme-hoosha.md' ), '# سلام' );
	const out = await get( '/api/files?q=hoosha' );
	assert.ok( out.files.includes( 'readme-hoosha.md' ), JSON.stringify( out.files ) );
} );

await step( 'خروجی مارک‌داون گفتگو ساخته می‌شود', async () => {
	const res = await fetch( `${ BASE }/api/export?format=md` );
	const text = await res.text();
	assert.match( text, /# گفتگوی هوشا/ );
	assert.match( text, /🧑 کاربر/ );
} );

await step( 'تشخیص وضعیت، همهٔ بررسی‌ها را برمی‌گرداند', async () => {
	const out = await get( '/api/doctor' );
	assert.ok( out.checks.length >= 6 );
	assert.ok( out.checks.some( ( c ) => c.name === 'پرووایدر' ) );
} );

await step( 'نشست تغییر نام و حذف می‌شود', async () => {
	const s = await get( '/api/state' );
	await post( '/api/new', {} );
	let list = ( await get( '/api/sessions' ) ).sessions;
	assert.ok( list.length >= 1 );

	await post( '/api/sessions', { action: 'rename', id: s.sessionId, title: 'نام تازه' } );
	list = ( await get( '/api/sessions' ) ).sessions;
	assert.ok( list.some( ( x ) => x.title === 'نام تازه' ) );

	await post( '/api/sessions', { action: 'delete', id: s.sessionId } );
	list = ( await get( '/api/sessions' ) ).sessions;
	assert.equal( list.some( ( x ) => x.id === s.sessionId ), false );
} );

await step( 'مسیر ناشناخته، ۴۰۴ می‌دهد نه صفحهٔ HTML', async () => {
	const res = await fetch( `${ BASE }/api/nope` );
	assert.equal( res.status, 404 );
	const body = await res.json();
	assert.match( body.error, /ناشناخته/ );
} );


await step( 'پیام با تصویر: پیوست به مدل می‌رسد و در رویداد کاربر دیده می‌شود', async () => {
	// یک PNG یک‌پیکسلی واقعی.
	const png =
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	events.length = 0;
	const out = await post( '/api/message', {
		text: 'این را ببین',
		images: [ { name: 'dot.png', mediaType: 'image/png', data: png } ],
	} );
	assert.ok( ! out.error, out.error );

	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.images.length, 1 );
	assert.equal( user.images[ 0 ].name, 'dot.png' );
	await waitIdle();

	// در حافظهٔ مدل، محتوا باید تکه‌تکه شده باشد نه یک رشته.
	const session = await get( `/api/sessions/${ ( await get( '/api/state' ) ).sessionId }` );
	const stored = ( session.messages || [] ).find( ( m ) => Array.isArray( m.content ) );
	assert.ok( stored, 'پیام چندتکه باید در نشست ذخیره شده باشد' );
	assert.equal( stored.content[ 1 ].type, 'image' );
} );

await step( 'فایل غیرتصویری به‌عنوان پیوست پذیرفته نمی‌شود', async () => {
	events.length = 0;
	await post( '/api/message', {
		text: 'این فایل',
		images: [ { name: 'x.pdf', mediaType: 'application/pdf', data: 'AAAA' } ],
	} );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.images.length, 0 );
	await waitIdle();
} );

await step( 'پرامپت MCP مثل دستور اسلش کار می‌کند و منبعش خوانده می‌شود', async () => {
	const fixture = path.resolve( 'test/fixtures/mcp-server.mjs' );
	await post( '/api/connectors', { action: 'save', name: 'demo', kind: 'stdio', command: process.execPath, args: fixture } );

	const s = await get( '/api/state' );
	assert.ok( s.commands.some( ( c ) => c.name === 'mcp__demo__greet' && c.source === 'MCP' ), 'پرامپت باید در فهرست دستورها بیاید' );
	assert.ok( s.tools.some( ( t ) => t.name === 'read_mcp_resource' ), 'ابزار خواندن منبع باید ساخته شود' );
	assert.deepEqual( s.resources.map( ( r ) => r.uri ), [ 'demo://note' ] );

	await say( '/mcp__demo__greet پیمان' );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.match( user.text, /به پیمان سلام رسمی بگو/ );
	await waitIdle();

	await post( '/api/connectors', { action: 'remove', name: 'demo' } );
} );

await step( 'حالت بدون‌رابط: اجرا می‌شود، JSON می‌دهد، و بدون اجازه چیزی را اجرا نمی‌کند', async () => {
	const { execFile } = await import( 'node:child_process' );
	const run = ( args ) =>
		new Promise( ( resolve ) => {
			execFile(
				process.execPath,
				[ path.resolve( 'src/cli.js' ), ...args ],
				{ env: { ...process.env, HOOSHA_HOME: HOME }, timeout: 30_000 },
				( err, stdout ) => resolve( { code: err?.code ?? 0, stdout } )
			);
		} );

	const denied = await run( [ '-p', '!echo سلام', '--dir', WORK, '--output-format', 'json' ] );
	const a = JSON.parse( denied.stdout );
	assert.equal( a.tools.length, 0, 'در حالت پیش‌فرض نباید ابزاری اجرا شود' );
	assert.equal( a.denied.length, 1 );

	const allowed = await run( [ '-p', '!echo سلام‌بی‌رابط', '--dir', WORK, '--mode', 'auto', '--output-format', 'json' ] );
	const b = JSON.parse( allowed.stdout );
	assert.deepEqual( b.tools, [ 'bash' ] );
	assert.match( b.text, /سلام‌بی‌رابط/ );
	assert.equal( b.ok, true );
} );



await step( 'سندباکس: روشن‌کردن از API، شکستِ بسته، و اجرای واقعی با موتور جعلی', async () => {
	const bin = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-bin-' ) );
	const fake = path.join( bin, 'docker' );
	await fs.writeFile(
		fake,
		[ '#!/bin/sh', 'for a in "$@"; do last="$a"; done', 'eval "$last"', '' ].join( '\n' ),
		{ mode: 0o755 }
	);

	// ۱) روشن، بدون موتور کانتینر → فرمان باید رد شود، نه اینکه ساکت روی میزبان برود.
	const realPath = process.env.PATH;
	process.env.PATH = '/nonexistent-hoosha';

	let out = await post( '/api/sandbox', { action: 'save', sandbox: { enabled: true, image: 'demo:1' } } );
	assert.ok( out.ok, out.error );
	assert.equal( out.sandbox.enabled, true );
	assert.equal( out.sandbox.available, false );

	await post( '/api/permissions', { mode: 'auto', allow: [], ask: [], deny: [] } );
	await say( '!echo نباید_اجرا_شود' );
	const failed = await waitFor( ( e ) => e.type === 'tool_result' || e.type === 'tool_error', 10_000, 'نتیجهٔ ابزار' );
	assert.match( String( failed.output || failed.error ), /موتور کانتینر پیدا نشد/ );
	await waitIdle();

	// ۲) همان تنظیمات، این بار با موتور جعلی در PATH → باید واقعاً از راه docker برود.
	process.env.PATH = `${ bin }:${ realPath }`;
	const status = await get( '/api/sandbox' );
	assert.equal( status.available, true );
	assert.equal( status.runtimeName, 'docker' );

	await say( '!echo از_داخل_کانتینر' );
	const ok = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( ok.output, /از_داخل_کانتینر/ );
	assert.match( ok.output, /سندباکس: docker/ );
	await waitIdle();

	// ۳) خاموش‌کردن، همه‌چیز را به حالت قبل برمی‌گرداند.
	out = await post( '/api/sandbox', { action: 'save', sandbox: { enabled: false } } );
	assert.equal( out.sandbox.enabled, false );
	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );

	process.env.PATH = realPath;
	await fs.rm( bin, { recursive: true, force: true } );
} );

await step( 'ابزار notebook_edit در فهرست ابزارها هست و روی فایل واقعی کار می‌کند', async () => {
	const s = await get( '/api/state' );
	assert.ok( s.tools.some( ( t ) => t.name === 'notebook_edit' ), 'ابزار نوت‌بوک باید ثبت شده باشد' );

	const nb = {
		nbformat: 4,
		nbformat_minor: 5,
		metadata: {},
		cells: [ { cell_type: 'code', id: 'a1', metadata: {}, execution_count: 3, source: [ 'print(1)' ], outputs: [ { output_type: 'stream', text: [ '1\n' ] } ] } ],
	};
	await fs.writeFile( path.join( WORK, 'live.ipynb' ), JSON.stringify( nb ) );

	const { TOOLS } = await import( '../src/tools.js' );
	const out = await TOOLS.notebook_edit.run(
		{ path: 'live.ipynb', mode: 'insert', cell_type: 'markdown', source: 'یادداشت' },
		{ workspace: WORK }
	);
	assert.match( out, /افزوده شد/ );

	const after = JSON.parse( await fs.readFile( path.join( WORK, 'live.ipynb' ), 'utf8' ) );
	assert.equal( after.cells.length, 2 );
	assert.equal( after.cells[ 1 ].cell_type, 'markdown' );
} );



await step( 'دروازهٔ تأیید، قاعده‌های درست را می‌فرستد و «همیشه» چند قاعده می‌سازد', async () => {
	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );

	await say( '!git status && npm test' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	assert.deepEqual( ask.rules, [ 'bash:git status', 'bash:npm test' ], 'باید برای هر تکه یک قاعده بدهد' );

	await post( '/api/permission', { id: ask.id, decision: 'deny', remember: true, rules: ask.rules } );
	await waitIdle();

	const s = await get( '/api/state' );
	assert.deepEqual( s.config.permissions.deny, [ 'bash:git status', 'bash:npm test' ] );

	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );
} );

await step( 'قاعدهٔ یک فرمان، به فرمان مرکب سرایت نمی‌کند', async () => {
	await post( '/api/permissions', { mode: 'default', allow: [ 'bash:echo' ], ask: [], deny: [] } );

	// این باید بدون پرسش اجرا شود
	await say( '!echo تک' );
	const ok = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'اجرای بدون پرسش' );
	assert.match( ok.output, /تک/ );
	await waitIdle();

	// ولی این باید بپرسد، چون «rm» مجاز نیست
	await say( '!echo دو && rm -rf /tmp/hoosha-should-not-exist' );
	const gate = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	assert.match( gate.summary, /rm/ );
	await post( '/api/permission', { id: gate.id, decision: 'deny' } );
	await waitIdle();

	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );
} );



await step( 'وضعیت گیت در state هست تا نوار کامپوزر بتواند نشانش دهد', async () => {
	// فضای کاری آزمون مخزن نیست؛ باید null بدهد نه اینکه بترکد.
	let s = await get( '/api/state' );
	assert.equal( s.git, null );

	// حالا یک مخزن واقعی بساز و فضای کاری را رویش ببر.
	const { execFile } = await import( 'node:child_process' );
	const run = ( args, cwd ) =>
		new Promise( ( r ) => execFile( 'git', args, { cwd }, () => r() ) );

	const repo = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-repo-' ) );
	await run( [ 'init', '-b', 'main' ], repo );
	await run( [ 'config', 'user.email', 't@t.local' ], repo );
	await run( [ 'config', 'user.name', 'T' ], repo );
	await fs.writeFile( path.join( repo, 'x.txt' ), 'یک\n' );
	await run( [ 'add', '-A' ], repo );
	await run( [ 'commit', '-m', 'first' ], repo );

	await post( '/api/workspace', { path: repo } );
	s = await get( '/api/state' );
	assert.equal( s.git.branch, 'main' );
	assert.equal( s.git.protected, true );
	assert.equal( s.git.dirty, false );

	// تغییر بده و ببین شمار به‌روز می‌شود
	await fs.writeFile( path.join( repo, 'x.txt' ), 'یک\nدو\n' );
	s = await get( '/api/state' );
	assert.equal( s.git.added, 1 );
	assert.equal( s.git.files.length, 1 );

	// کامیت از راه API — باید شاخهٔ تازه بسازد چون main محافظت‌شده است
	const out = await post( '/api/git', { action: 'commit', message: 'change from api' } );
	assert.ok( out.ok, out.error );
	assert.ok( out.movedTo, 'باید از main منشعب شده باشد' );

	s = await get( '/api/state' );
	assert.equal( s.git.dirty, false );
	assert.notEqual( s.git.branch, 'main' );

	// پوش روی شاخهٔ کاری بدون ریموت شکست می‌خورد، ولی با پیام روشن نه با ترکیدن
	const pushed = await post( '/api/git', { action: 'push' } );
	assert.ok( pushed.error, 'باید خطا بدهد چون ریموتی نیست' );

	await post( '/api/workspace', { path: WORK } );
	await fs.rm( repo, { recursive: true, force: true } );
} );

await step( 'ابزار install از راه گفتگو، یک اسکیل محلی را نصب می‌کند', async () => {
	// همان چیزی که کارفرما خواست: آدرس را بده و بگو نصبش کن.
	const src = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-skill-src-' ) );
	const dir = path.join( src, 'fa-seo' );
	await fs.mkdir( dir, { recursive: true } );
	await fs.writeFile(
		path.join( dir, 'SKILL.md' ),
		'---\nname: fa-seo\ndescription: بهینه‌سازی عنوان فارسی\n---\nیک: کلیدواژه در ۶۰ نویسهٔ اول.\n'
	);

	const before = ( await get( '/api/state' ) ).skills.length;

	const { TOOLS } = await import( '../src/tools.js' );
	assert.equal( TOOLS.install, undefined, 'install ابزار ثابت نیست، از runtime می‌آید' );

	const out = await post( '/api/message', { text: `/install ${ dir }` } );
	assert.ok( ! out.error, out.error );

	const after = await get( '/api/state' );
	assert.equal( after.skills.length, before + 1 );
	assert.ok( after.skills.some( ( s ) => s.name === 'fa-seo' ) );

	await post( '/api/skills', { action: 'remove', name: 'fa-seo' } );
	await fs.rm( src, { recursive: true, force: true } );
} );


// ------------------------------------------------------------------- هاب

/**
 * یک سرویس‌دهندهٔ ساختگی سازگار با OpenAI، برای اینکه مسیر واقعیِ «مدیر در پنل چه
 * می‌کند» را از اول تا آخر برویم: اتصال بساز، مدل کشف کن، هاب را روشن کن، پیام بده.
 */
const http = await import( 'node:http' );

let fakeHits = 0;
let fakeMode = 'ok';
const fake = http.createServer( ( req, res ) => {
	let raw = '';
	req.on( 'data', ( c ) => ( raw += c ) );
	req.on( 'end', () => {
		if ( req.url.endsWith( '/models' ) ) {
			res.writeHead( 200, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( { data: [ { id: 'live-model' }, { id: 'live-mini' } ] } ) );
			return;
		}
		fakeHits++;
		if ( fakeMode === 'down' ) {
			res.writeHead( 500, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( { error: 'boom' } ) );
			return;
		}
		res.writeHead( 200, { 'Content-Type': 'text/event-stream' } );
		res.write( `data: ${ JSON.stringify( { choices: [ { delta: { content: 'پاسخ از هاب' } } ] } ) }\n\n` );
		res.write( `data: ${ JSON.stringify( { usage: { prompt_tokens: 8, completion_tokens: 4 } } ) }\n\n` );
		res.write( 'data: [DONE]\n\n' );
		res.end();
	} );
} );
await new Promise( ( r ) => fake.listen( 0, '127.0.0.1', r ) );
const FAKE = `http://127.0.0.1:${ fake.address().port }`;

let connId = '';

await step( 'صفحهٔ هاب خالی ولی سرپا بالا می‌آید', async () => {
	const out = await get( '/api/hub' );
	assert.equal( out.active, false );
	assert.ok( Array.isArray( out.catalog ) && out.catalog.length > 5 );
	assert.ok( out.strategies.some( ( s ) => s.id === 'auto' ) );
	assert.ok( out.categories.some( ( c ) => c.id === 'coding' ) );
} );

await step( 'اتصال تازه ساخته می‌شود و کلیدش خام برنمی‌گردد', async () => {
	const out = await post( '/api/hub', {
		action: 'save-connection',
		connection: { label: 'ساختگی', provider: 'openai-compatible', kind: 'openai', baseUrl: FAKE, apiKey: 'live-secret-key' },
	} );
	assert.ok( out.ok, out.error );
	connId = out.connection.id;

	const snap = await get( '/api/hub' );
	assert.equal( JSON.stringify( snap ).includes( 'live-secret-key' ), false, 'کلید نباید در پاسخ باشد' );
	assert.equal( snap.hub.connections[ connId ].hasKey, true );
} );

await step( 'کشف مدل‌ها رجیستری را پر می‌کند', async () => {
	const out = await post( '/api/hub', { action: 'discover', id: connId } );
	assert.ok( out.ok, out.error );
	assert.equal( out.added, 2 );
	const snap = await get( '/api/hub' );
	assert.ok( snap.hub.models[ `${ connId }::live-model` ] );
} );

await step( 'تست اتصال، پاسخ واقعی می‌گیرد', async () => {
	const out = await post( '/api/hub', { action: 'test-connection', id: connId } );
	assert.ok( out.ok, out.error );
	assert.match( out.message, /پاسخ گرفتم/ );
} );

await step( 'روشن‌کردن هاب، فرمان را از پروفایل تک‌نفره می‌گیرد', async () => {
	const out = await post( '/api/hub', { action: 'toggle', enabled: true } );
	assert.equal( out.active, true, JSON.stringify( out.ready ) );
	const state = await get( '/api/state' );
	assert.equal( state.hub.active, true );
	assert.equal( state.ready.ok, true );
} );

await step( 'پیام واقعی کاربر از راه هاب مسیریابی و جواب داده می‌شود', async () => {
	const before = fakeHits;
	await say( 'یک جملهٔ کوتاه بگو' );
	await waitFor( ( e ) => e.type === 'idle', 15_000, 'پایان نوبت' );
	assert.ok( fakeHits > before, 'هیچ تماسی به سرویس‌دهنده نرفت' );
	const text = events.filter( ( e ) => e.type === 'text' ).map( ( e ) => e.text ).join( '' );
	assert.match( text, /پاسخ از هاب/ );
	assert.ok( events.some( ( e ) => e.type === 'hub-route' ), 'رویداد مسیریابی به رابط نرسید' );
} );

await step( 'آزمون «این درخواست به کجا می‌رود» از پنل جواب می‌دهد', async () => {
	const out = await post( '/api/hub', { action: 'explain', text: 'این تابع را ریفکتور کن', tools: [ 'edit_file' ] } );
	assert.equal( out.classification.category, 'coding' );
	assert.ok( out.candidates.length >= 1 );
	assert.ok( typeof out.candidates[ 0 ].score === 'number' );
} );

await step( 'خروجی سازگار با OpenAI: فهرست مدل‌ها', async () => {
	const out = await get( '/v1/models' );
	assert.equal( out.object, 'list' );
	assert.equal( out.data[ 0 ].id, 'auto' );
	assert.ok( out.data.some( ( m ) => m.id === `${ connId }::live-model` ) );
} );

await step( 'خروجی سازگار با OpenAI: تکمیل چت بدون استریم', async () => {
	const res = await fetch( `${ BASE }/v1/chat/completions`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { model: 'auto', messages: [ { role: 'user', content: 'سلام' } ] } ),
	} );
	const out = await res.json();
	assert.equal( res.status, 200, JSON.stringify( out ) );
	assert.equal( out.object, 'chat.completion' );
	assert.match( out.choices[ 0 ].message.content, /پاسخ از هاب/ );
	assert.ok( out.usage.total_tokens > 0 );
} );

await step( 'خروجی سازگار با OpenAI: تکمیل چت با استریم', async () => {
	const res = await fetch( `${ BASE }/v1/chat/completions`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { model: 'auto', stream: true, messages: [ { role: 'user', content: 'یک متن دیگر' } ] } ),
	} );
	const body = await res.text();
	assert.match( body, /chat\.completion\.chunk/ );
	assert.match( body, /پاسخ از هاب/ );
	assert.match( body, /\[DONE\]/ );
} );

await step( 'وقتی سرویس می‌خوابد، هاب علامت می‌زند و خطای گویا می‌دهد', async () => {
	fakeMode = 'down';
	await say( 'یک درخواست تازه که قبلاً نپرسیده‌ام' );
	await waitFor( ( e ) => e.type === 'idle', 20_000, 'پایان نوبت' );
	const err = events.find( ( e ) => e.type === 'error' );
	assert.ok( err, 'خطا به رابط نرسید' );
	const snap = await get( '/api/hub' );
	const health = snap.health[ `${ connId }::live-model` ] || snap.health[ `${ connId }::live-mini` ];
	assert.ok( health.fail > 0, 'شکست در دفتر سلامت ثبت نشد' );
	fakeMode = 'ok';
} );

await step( 'مدار باز را می‌شود از پنل دوباره بست', async () => {
	const key = `${ connId }::live-model`;
	await post( '/api/hub', { action: 'reset-breaker', key } );
	const snap = await get( '/api/hub' );
	assert.notEqual( snap.health[ key ]?.circuit, 'open' );
} );

await step( 'خاموش‌کردن هاب، پروفایل تک‌نفره را برمی‌گرداند', async () => {
	const out = await post( '/api/hub', { action: 'toggle', enabled: false } );
	assert.equal( out.active, false );
	const state = await get( '/api/state' );
	assert.equal( state.hub.active, false );
} );

await step( 'وقتی هاب خاموش است، مسیر سازگار با OpenAI بسته است', async () => {
	const res = await fetch( `${ BASE }/v1/chat/completions`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { model: 'auto', messages: [ { role: 'user', content: 'x' } ] } ),
	} );
	assert.equal( res.status, 503 );
} );

await step( 'حذف اتصال، مدل‌های یتیم را هم می‌برد', async () => {
	await post( '/api/hub', { action: 'remove-connection', id: connId } );
	const snap = await get( '/api/hub' );
	assert.equal( Object.keys( snap.hub.connections ).length, 0 );
	assert.equal( Object.keys( snap.hub.models ).length, 0 );
} );

fake.close();

// ------------------------------------------------------------------- پایان

reader.cancel().catch( () => {} );
server.close();

process.stdout.write( `\n${ '-'.repeat( 56 ) }\n` );
if ( failures.length ) {
	process.stdout.write( `${ passed } موفق، ${ failures.length } ناموفق\n` );
	for ( const f of failures ) {
		process.stdout.write( `  ✗ ${ f.name }: ${ f.error }\n` );
	}
	process.exitCode = 1;
} else {
	process.stdout.write( `${ passed } آزمون زنده، همه موفق\n` );
}

await fs.rm( HOME, { recursive: true, force: true } ).catch( () => {} );
await fs.rm( WORK, { recursive: true, force: true } ).catch( () => {} );
setTimeout( () => process.exit( process.exitCode || 0 ), 300 ).unref();
