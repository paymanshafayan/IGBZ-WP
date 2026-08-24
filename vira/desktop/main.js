/**
 * پوستهٔ دسکتاپ ویرا (Electron).
 *
 * عمداً نازک است: همان سرور محلی را بالا می‌آورد و در یک پنجره نشانش می‌دهد. یعنی
 * منطق در یک جا می‌ماند و نسخهٔ مرورگر و نسخهٔ دسکتاپ هرگز از هم جدا نمی‌افتند.
 *
 * توجه: این فایل در سندباکس توسعه اجرا نشده — باینری Electron از GitHub می‌آید و آنجا
 * مسدود است. روی دستگاه خودت با `npm run desktop` کار می‌کند.
 */

import { app, BrowserWindow, shell } from 'electron';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { startServer } from '../src/server.js';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const PORT = Number( process.env.VIRA_PORT || 7788 );

/** @type {BrowserWindow|null} */
let win = null;

async function createWindow() {
	await startServer( { port: PORT, host: '127.0.0.1' } );

	win = new BrowserWindow( {
		width: 1180,
		height: 820,
		minWidth: 720,
		minHeight: 560,
		backgroundColor: '#0f1115',
		title: 'ویرا',
		icon: path.join( __dirname, 'icon.png' ),
		autoHideMenuBar: true,
		webPreferences: { nodeIntegration: false, contextIsolation: true },
	} );

	win.loadURL( `http://127.0.0.1:${ PORT }` );

	// لینک بیرونی در مرورگر سیستم باز شود، نه داخل پنجرهٔ برنامه.
	win.webContents.setWindowOpenHandler( ( { url } ) => {
		shell.openExternal( url );
		return { action: 'deny' };
	} );

	win.on( 'closed', () => {
		win = null;
	} );
}

app.whenReady().then( createWindow );

app.on( 'window-all-closed', () => {
	if ( process.platform !== 'darwin' ) {
		app.quit();
	}
} );

app.on( 'activate', () => {
	if ( BrowserWindow.getAllWindows().length === 0 ) {
		createWindow();
	}
} );
