@echo off
rem ---------------------------------------------------------------------------
rem  اجرای ویرا از هر جایی داخل مخزن.
rem
rem  چرا این فایل هست: راهنما می‌گفت «cd vira» و بعد «node src/cli.js». اگر کسی فقط
rem  خط دوم را کپی کند و در ریشهٔ مخزن بزند، Node می‌گوید
rem
rem      Error: Cannot find module '...\IGBZ-WP\src\cli.js'
rem      code: 'MODULE_NOT_FOUND', requireStack: []
rem
rem  که هیچ سرنخی نمی‌دهد ماجرا سرِ پوشه است. این اسکریپت خودش به پوشهٔ درست می‌رود،
rem  پس دیگر فرقی نمی‌کند از کجا صدایش بزنی.
rem
rem  کاربرد:  .\vira.cmd  [همان گزینه‌های همیشگی]
rem  مثال:    .\vira.cmd --port 7788 --no-open
rem ---------------------------------------------------------------------------

setlocal

where node >nul 2>nul
if errorlevel 1 (
	echo.
	echo   Node.js پیدا نشد. نسخهٔ ۲۰ یا بالاتر را از nodejs.org نصب کن.
	echo.
	exit /b 1
)

if not exist "%~dp0vira\src\cli.js" (
	echo.
	echo   پوشهٔ vira کنار این فایل نیست: %~dp0vira
	echo   این اسکریپت باید در ریشهٔ مخزن IGBZ-WP بماند.
	echo.
	exit /b 1
)

cd /d "%~dp0vira"

rem وابستگی‌ها را خودمان نصب نمی‌کنیم — npm ci هر بار وقت کاربر را می‌گرفت.
rem فقط اگر واقعاً غایب باشند، سریع و روشن می‌گوییم چه بزند و بیرون می‌آییم.
if not exist "node_modules" (
	echo.
	echo   وابستگی‌ها نصب نیستند. یک بار این را بزن:
	echo.
	echo       cd /d "%~dp0vira" ^&^& npm ci
	echo.
	exit /b 1
)

node src\cli.js %*
