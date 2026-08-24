/**
 * دو زبانه — فارسی و انگلیسی.
 *
 * قاعده‌ای که این ماژول را شکل داد: **زبان پیش‌فرض فارسی است و هیچ رشته‌ای بی‌ترجمه
 * نمی‌ماند.** اگر کلیدی در فرهنگ انگلیسی نباشد، همان فارسی برمی‌گردد — نه یک کلید خام
 * مثل `sidebar.chats` که در رابط زشت است و کاربر را گیج می‌کند.
 *
 * سه چیز با هم عوض می‌شوند و نه یکی‌شان جدا:
 *   زبان  ·  جهت صفحه (rtl/ltr)  ·  خانوادهٔ فونت
 *
 * فارسی با وزیرمتن نوشته می‌شود (فایلش کنار برنامه است)، انگلیسی با فونت سیستم. اگر
 * فقط زبان عوض شود و جهت نه، رابط به‌هم می‌ریزد — این را جدا نگه‌نداشتن، خودش یک باگ
 * است که با یک تابع بسته می‌شود.
 */

const STORE_KEY = 'vira-lang';

/** @type {Record<string, Record<string,string>>} */
const EN = {
	// صفحهٔ هاب پرووایدر (۰.۹.۴)
	'نگاه کلی هاب': 'Hub overview',
	'نگاه کلی': 'Overview',
	'اتصال‌ها': 'Connections',
	'ترکیب‌ها': 'Combos',
	'سلامت و مصرف': 'Health & spend',
	'هاب فعال است': 'The hub is active',
	'هاب فعال': 'Hub active',
	'هاب': 'Hub',
	'فرمان با هاب است': 'The hub is in command',
	'مدار باز': 'Circuit open',
	'اعتبار/کلید': 'Credits/key',
	'ریست و رفع خطا': 'Reset & clear errors',
	'آخرین مسیرها': 'Recent routes',
	'۲۰ تصمیم مسیریابی آخر، تازه‌ترین اول.': 'The 20 latest routing decisions, newest first.',
	'موفق': 'ok',
	'— ضخامت یال = ترافیک ثبت‌شده': '— edge thickness = recorded traffic',
	'— ضخامت یال = ترافیک ثبت‌شده. برای جزئیات، روی هر سرویس کلیک کن.': '— edge thickness = recorded traffic. Click any service for details.',
	'اخیر (یال)': 'Recent',
	'خطا (یال)': 'Error',
	'لغو': 'Cancel',
	'در حال دانلود…': 'Downloading…',
	'در حال جمع‌آوری…': 'Collecting…',
	'در حال آزمودن…': 'Testing…',
	'فقط اینترنت': 'Internet only',
	'خراب': 'Broken',
	'آزموده نشده': 'Not tested',
	'سالم': 'Healthy',
	'پرووایدرها و هاب…': 'Providers & hub…',
	'پراکسی': 'Proxy',
	'لاگ‌ها': 'Logs',
	'پراکسی ویرا': 'Vira proxy',
	'حالت': 'Mode',
	'خاموش (اتصال مستقیم)': 'Off (direct connection)',
	'پراکسی دستی — مثل Hiddify روی این سیستم': 'Manual proxy — e.g. Hiddify on this system',
	'موتور تونل داخلی ویرا': "Vira's built-in tunnel engine",
	'نشانی': 'Address',
	'درگاه': 'Port',
	'استثناها': 'Exceptions',
	'نشانی‌هایی که با این‌ها شروع می‌شوند از پراکسی عبور نمی‌کنند. با «؛» جدا کن. * یعنی هر چیز بعد از پیشوند.': 'Addresses starting with these bypass the proxy. Separate with ; — * matches anything after the prefix.',
	'برای نشانی‌های محلی (شبکهٔ داخلی) از پراکسی استفاده نشود': "Don't use the proxy for local (intranet) addresses",
	'تنظیمات پراکسی ذخیره شد.': 'Proxy settings saved.',
	'تست اتصال': 'Test connection',
	'موتور تونل داخلی': 'Built-in tunnel engine',
	'مثل یک v2ray داخل ویرا: کانفیگ‌های رایگان را از مخازن عمومی جمع می‌کند، می‌آزماید، سالم‌ها را نگه می‌دارد و درگاه محلی پایداری می‌سازد که خودکار روی بهترین می‌چرخد. هشدار: کانفیگ رایگان یعنی اپراتور ناشناس — برای کار حساس، سرور خودت را به منابع اضافه کن.': 'Like a v2ray inside Vira: collects free configs from public repos, tests them, keeps the working ones, and runs a stable local port that auto-rotates to the best. Warning: free configs mean unknown operators — for sensitive work add your own server to the sources.',
	'دانلود هستهٔ xray': 'Download xray core',
	'روشن‌کردن تونل': 'Start tunnel',
	'توقف تونل': 'Stop tunnel',
	'چرخش به کانفیگ بعدی': 'Rotate to next config',
	'به‌روزرسانی منابع': 'Update sources',
	'تست همهٔ کانفیگ‌ها': 'Test all configs',
	'منابع کانفیگ': 'Config sources',
	'هر خط یک نشانی (اشتراک base64 یا فهرست لینک). این‌ها پیش‌فرض‌های راستی‌آزمایی‌شده‌اند؛ می‌توانی کم و زیاد کنی.': 'One URL per line (base64 subscription or link list). These are verified defaults; add or remove freely.',
	'ذخیرهٔ منابع': 'Save sources',
	'منابع ذخیره شد.': 'Sources saved.',
	'کانفیگ‌ها': 'Configs',
	'سنجاق': 'Pin',
	'برداشتن سنجاق': 'Unpin',
	'هنوز کانفیگی نیست — «به‌روزرسانی منابع» و بعد «تست همه» را بزن.': "No configs yet — hit 'Update sources' then 'Test all configs'.",
	'لاگ‌های ویرا': 'Vira logs',
	'همهٔ سطح‌ها': 'All levels',
	'فقط خطا': 'Errors only',
	'هشدار و خطا': 'Warnings & errors',
	'همهٔ کانال‌ها': 'All channels',
	'جستجو در متن لاگ…': 'Search log text…',
	'جستجو': 'Search',
	'بازآوری': 'Refresh',
	'خروجی JSON': 'Export JSON',
	'پاک‌کردن': 'Clear',
	'لاگ‌ها پاک شد.': 'Logs cleared.',
	'خروجی گرفته شد.': 'Exported.',
	'لاگی با این فیلتر نیست.': 'No logs match this filter.',
	'زمینه': 'Context',
	'همهٔ رخدادها و خطاها با کانال و زمان. «زمینه» جزئیات JSON هر ردیف را باز می‌کند. نسخهٔ کامل روی دیسک: logs/vira.log در پوشهٔ خانگی ویرا.': 'All events and errors with channel and time. "Context" expands each row\'s JSON. Full copy on disk: logs/vira.log under the Vira home.',
	'کانال': 'Channel',
	'سطح': 'Level',
	'شروع دانلود هستهٔ xray…': 'Downloading xray core…',
	'در حال جمع‌آوری کانفیگ از منابع…': 'Collecting configs from sources…',
	'در حال آزمودن کانفیگ‌ها — چند دقیقه ممکن است بشود…': 'Testing configs — this may take a few minutes…',
	'هاب پرووایدر': 'Provider hub',
	'مسیریابی با هاب انجام می‌شود': 'Routing is done by the hub',
	'پراکسی ویرا': 'Vira proxy',
	'تست پراکسی': 'Test proxy',
	'ذخیره پراکسی': 'Save proxy',
	'پراکسی ذخیره شد.': 'Proxy saved.',
	'در حال آزمودن…': 'Testing…',
	'پراکسی این اتصال (اختیاری)': 'Proxy for this connection (optional)',
	'خالی = پراکسی سراسری هاب. مثال: http://127.0.0.1:7890': 'Empty = the hub-wide proxy. Example: http://127.0.0.1:7890',
	'تماس‌های پرووایدر از این مسیر می‌گذرند (Hiddify: http://127.0.0.1:7890). مقصدهای محلی همیشه مستقیم‌اند. هر اتصال می‌تواند پراکسی خودش را هم در ویزارد بگیرد.': 'Provider traffic goes through this route (Hiddify: http://127.0.0.1:7890). Local targets always stay direct. Each connection can also set its own proxy in the wizard.',
	'وضعیت زندهٔ اتصال‌ها از نگاه مسیریاب: رنگ هر گره وضعیت واقعی آن است و ضخامت هر یال، ترافیک ثبت‌شده.': "Live connection state from the router's view: each node's color is its real status, each edge's thickness its recorded traffic.",
	// نوار کناری
	'گفتگوی تازه': 'New chat',
	'گفتگوها': 'Chats',
	'پروژه‌ها': 'Projects',
	'ابزارها': 'Tools',
	'تغییرات': 'Changes',
	'سفارشی‌سازی': 'Customize',
	'امکانات': 'Features',
	'فضای کار': 'Workspace',
	'اخیر': 'Recents',
	'همهٔ گفتگوها': 'All chats',
	'جستجو (Ctrl+K)': 'Search (Ctrl+K)',
	'بستن نوار کناری': 'Collapse sidebar',
	'حساب و تنظیمات': 'Account and settings',
	'پروفایل': 'Profile',
	'مسیریابی خودکار': 'Automatic routing',

	// منوی حساب
	'تنظیمات': 'Settings',
	'ظاهر': 'Appearance',
	'راهنما و میان‌برها': 'Help and shortcuts',
	'مصرف و هزینه': 'Usage and cost',
	'وضعیت و تشخیص': 'Status and diagnostics',
	'بارگذاری دوباره': 'Reload',

	// گفتگو
	'امروز چه کمکی از من برمی‌آید؟': 'How can I help you today?',
	'ویرا هم اشتباه می‌کند. کارهای مهم را خودت بازبینی کن.': 'Vira can make mistakes. Double-check anything that matters.',
	'ارسال': 'Send',
	'توقف (Esc)': 'Stop (Esc)',
	'گفتن به‌جای نوشتن (Ctrl+M)': 'Speak instead of typing (Ctrl+M)',
	'بلندخوانی پاسخ': 'Read the reply aloud',
	'افزودن و ابزارها': 'Attach and tools',
	'بازگشت به گفتگو': 'Back to chat',
	'بیشتر': 'More',
	'اشتراک': 'Share',
	'بدون پروژه': 'No project',
	'گفتگوی تازه است': 'New chat',
	'مصرف کانتکست': 'Context used',

	// صفحه‌ها
	'جستجو در گفتگوها…': 'Search chats…',
	'هنوز گفتگویی نیست': 'No chats yet',
	'از «گفتگوی تازه» شروع کن؛ هر گفتگو خودش ذخیره می‌شود.': 'Start with “New chat” — every conversation saves itself.',
	'پروژهٔ تازه': 'New project',
	'پروژهٔ فعلی': 'Current project',
	'باز کن': 'Open',
	'پیام': 'messages',
	'باز': 'open',
	'امروز': 'Today',
	'هفت روز گذشته': 'Previous 7 days',
	'سی روز گذشته': 'Previous 30 days',
	'قدیمی‌تر': 'Older',
	'بدون عنوان': 'Untitled',

	// تغییرات
	'مخزن': 'Repository',
	'شاخه': 'Branch',
	'تغییر': 'Changes',
	'جلوتر از ریموت': 'Ahead of remote',
	'ثبت تغییرات': 'Commit',
	'فرستادن': 'Push',
	'درخواست ادغام': 'Pull request',
	'چیزی تغییر نکرده.': 'Nothing has changed.',
	'کامیت‌های اخیر': 'Recent commits',
	'این پوشه مخزن گیت نیست.': 'This folder is not a git repository.',
	'اتصال مخزن': 'Connect a repository',

	// تنظیمات
	'پرووایدر و مدل': 'Provider and model',
	'پرووایدرهای استاندارد': 'Standard providers',
	'پرووایدرهای سازگار': 'Compatible providers',
	'مدل‌ها': 'Models',
	'هاب و مسیریابی': 'Hub and routing',
	'سلامت و عیب‌یاب': 'Health and diagnoser',
	'پروفایل تک‌نفره': 'Single profile',
	'مجوزها': 'Permissions',
	'سندباکس': 'Sandbox',
	'اسکیل‌ها': 'Skills',
	'کانکتورها': 'Connectors',
	'پلاگین‌ها': 'Plugins',
	'زیرعامل‌ها': 'Subagents',
	'دستورها': 'Commands',
	'هوک‌ها': 'Hooks',
	'حافظهٔ پروژه': 'Project memory',
	'جستجو…': 'Search…',
	'بستن': 'Close',
	'مرور': 'Browse',
	'افزودن': 'Add',
	'ذخیره': 'Save',
	'انصراف': 'Cancel',
	'ویرایش': 'Edit',
	'حذف': 'Delete',
	'زبان': 'Language',
	'فارسی': 'Persian',
	'انگلیسی': 'English',

	"تعریف شده": "defined",
	"نصب شده": "installed",
	"نقطه": "points",
	"کامیت آماده": "commits ready",
	"در دسترس": "available",
	"Ctrl+U — یا فقط بچسبان": "Ctrl+U — or just paste",
	"اتصال مخزن تازه": "Connect a new repository",
	"اشاره به فایل پروژه": "Point to a project file",
	"افزودن فایل یا تصویر": "Add a file or image",
	"این پرووایدر فهرست مدل نمی‌دهد.": "This provider does not list models.",
	"با gh ساخته می‌شود": "Created with gh",
	"بازگشت به چک‌پوینت": "Rewind to a checkpoint",
	"بدون تأیید، جز آنچه ممنوع کرده‌ای": "No confirmation, except what you have denied",
	"بدون کلون": "Without cloning",
	"بستن کار": "Close the task",
	"تغییر نام گفتگو": "Rename the chat",
	"تغییر پوشهٔ کاری": "Change the workspace folder",
	"تنظیمات پرووایدر": "Provider settings",
	"جستجو: گفتگو، دستور، فایل، تنظیمات…": "Search: chats, commands, files, settings…",
	"جستجوی فازی": "Fuzzy search",
	"خروجی JSON": "Export JSON",
	"خروجی ذخیره شد.": "Export saved.",
	"خروجی مارک‌داون": "Export Markdown",
	"خودکار": "Automatic",
	"در حال خواندن شاخه‌ها…": "Reading branches…",
	"دستور": "Command",
	"دیدن تغییرات": "See the changes",
	"دیدن همهٔ تغییرات": "See all changes",
	"فرستادن به ریموت": "Push to the remote",
	"فقط بررسی و خواندن — چیزی تغییر نمی‌کند": "Read and review only — nothing changes",
	"فهرست نیامد": "No list came back",
	"میان‌برها": "Shortcuts",
	"نوشتن و اجرا با تأیید تو": "Writes and commands with your confirmation",
	"پاک‌کردن گفتگو": "Delete the chat",
	"پلن": "Plan",
	"کلون از آدرس گیت": "Clone from a git URL",
	"کلید، آدرس، پروفایل‌ها": "Keys, endpoints, profiles",
	"گفتگو": "Chat",
	"حالت": "Mode",

	"نصب‌شده": "installed",
	"تعریف‌شده": "defined",
	"کانکتورها (MCP)": "Connectors (MCP)",

	"سنجاق": "Pin",
	"برداشتن سنجاق": "Unpin",
	"سنجاق‌شده": "Pinned",
	"تغییر نام": "Rename",
	"باز کردن در تب تازه": "Open in new tab",
	"نام تازهٔ گفتگو": "New chat name",
	"این گفتگو حذف شود؟": "Delete this chat?",

	"افزودن به پروژه": "Add to project",
	"برداشتن از پروژه": "Remove from project",
	"هنوز پروژه‌ای نیست": "No projects yet",
	"افزوده شد به": "Added to",
	"از پروژه برداشته شد": "Removed from the project",
	"بازگشت": "Back",

	"شاخهٔ تازه": "New branch",
	"از همین‌جا منشعب می‌شود": "Branches from here",
	"نام شاخهٔ تازه:": "Name of the new branch:",

	"به ویرا خوش آمدی": "Welcome to Vira",
	"چه کاری برایت انجام بدهم؟": "What can I do for you?",

	"اتصال تازه": "New connection",
	"کانکتور تازه": "New connector",
	"زیرعامل تازه": "New subagent",
	"دستور تازه": "New command",
	"پروفایل تازه": "New profile",
	"متغیر محیطی": "Environment variable",
	"هدر": "Header",
	"شاخهٔ تازه ساخته می‌شود": "A new branch will be created",
	"آدرس": "URL",
	"آدرس اینترنتی (HTTP)": "A web address (HTTP)",
	"آدرس پایه": "Base URL",
	"اجرای فرمان محلی (stdio)": "A local command (stdio)",
	"ابزارهای این سرور با پیشوند mcp__<نام>__ ظاهر می‌شوند.": "This server’s tools appear with the mcp__<name>__ prefix.",
	"ابزارهای مجاز": "Allowed tools",
	"اجباری — همان چیزی که سرویس‌دهنده می‌دهد.": "Required — exactly what the provider gives you.",
	"ارسال پیام": "Send the message",
	"از کاتالوگ می‌آید؛ لازم نیست چیزی وارد کنی.": "Comes from the catalog; you do not need to type anything.",
	"الان هاب فرمان را در دست دارد؛ این پروفایل استفاده نمی‌شود.": "The hub is in charge right now; this profile is not used.",
	"اولویت": "Priority",
	"اگر سرویس مسیر غیراستاندارد دارد.": "If the service uses a non-standard path.",
	"این اتصال روشن باشد": "Keep this connection on",
	"با فاصله جدا کن.": "Separate with spaces.",
	"باز کردن بازگشت (rewind)": "Open rewind",
	"باز/بستن ریل کناری": "Collapse or open the sidebar",
	"برای سرویس‌های سازگار با OpenAI/Anthropic (مثل OpenRouter، Ollama، LM Studio) اینجا را پر کن.": "Fill this in for OpenAI/Anthropic-compatible services (OpenRouter, Ollama, LM Studio).",
	"تست": "Test",
	"تست اتصال": "Test the connection",
	"توضیح": "Description",
	"توقف کار در حال اجرا": "Stop the running task",
	"جستجو در مدل‌ها…": "Search models…",
	"خالی = مدل پیش‌فرض": "Empty = the default model",
	"خاموش کن": "Turn off",
	"خط تازه": "New line",
	"در فایل تنظیمات محلی و با دسترسی ۶۰۰ ذخیره می‌شود و هیچ‌وقت به رابط برنمی‌گردد.": "Stored in the local config file with 600 permissions, and never sent back to the interface.",
	"ذخیره شد.": "Saved.",
	"قواعد ذخیره شد.": "Rules saved.",
	"ذخیره و کشف مدل‌ها": "Save and discover models",
	"رفتن به هاب": "Go to the hub",
	"سالم": "Healthy",
	"سبک احراز": "Auth style",
	"سراسری (همهٔ پروژه‌ها)": "Global (every project)",
	"سرویس": "Service",
	"سرویس‌دهنده": "Provider",
	"سقف روزانه (تعداد تماس)": "Daily cap (calls)",
	"سقف هم‌زمانی": "Concurrency cap",
	"فراموش کن": "Forget",
	"فرمان": "Command",
	"فرمان با هاب": "The hub is in charge",
	"فقط این پروژه": "This project only",
	"فقط برای سبک «هدر دلخواه» و «پارامتر آدرس».": "Only for the “custom header” and “query parameter” styles.",
	"ماندگار کن": "Make permanent",
	"موقت": "Temporary",
	"متغیرهای محیطی": "Environment variables",
	"متن دستور (پرامپت)": "Command body (prompt)",
	"مثلاً Authorization: Bearer …": "For example Authorization: Bearer …",
	"مثلاً files": "For example files",
	"محدوده": "Scope",
	"مسیر فهرست مدل": "Models path",
	"مسیریابی با هاب انجام می‌شود؛ پروفایل تک‌نفره کنار گذاشته شده.": "The hub does the routing; the single profile is set aside.",
	"میکروفن: گفتن به‌جای نوشتن": "Microphone: speak instead of typing",
	"نام": "Name",
	"نام (انگلیسی)": "Name (English)",
	"نام هدر یا پارامتر احراز": "Auth header or parameter name",
	"نام پروفایل": "Profile name",
	"نوع اتصال": "Connection type",
	"هاب روشن است": "The hub is on",
	"هدرها": "Headers",
	"هدرهای سفارشی": "Custom headers",
	"هر خط یک هدر: نام: مقدار": "One header per line: name: value",
	"هرچه که در فهرست‌ها می‌خواهی ببینی — مثلاً «OpenRouter حساب اصلی».": "Whatever you want to see in the lists — e.g. “OpenRouter main account”.",
	"همهٔ مدل‌های روشن": "Every enabled model",
	"همین راهنما (وقتی کادر خالی است)": "This help (when the box is empty)",
	"همین متن به مدل نشان داده می‌شود تا بداند کِی صدایش بزند.": "This text is shown to the model so it knows when to call it.",
	"هنوز چک‌پوینتی ساخته نشده.": "No checkpoint has been made yet.",
	"هیچ‌کدام انتخاب نشود یعنی همهٔ ابزارها.": "Selecting none means every tool.",
	"وصله روی خود اتصال می‌نشیند و دفعهٔ بعد پیش از اولین تلاش اعمال می‌شود.": "The patch lands on the connection itself and is applied before the next first attempt.",
	"وصلهٔ ثبت‌شده": "Recorded patch",
	"ویرایش آخرین پیام (وقتی کادر خالی است)": "Edit the last message (when the box is empty)",
	"پارامترها": "Parameters",
	"پالت فرمان": "Command palette",
	"پرامپت سیستمی": "System prompt",
	"چرخش حالت: پلن → عادی → خودکار": "Cycle mode: plan → normal → automatic",
	"کد را مرور می‌کند و ایراد می‌گیرد": "Reviews the code and points out problems",
	"کشف مدل‌ها": "Discover models",
	"کلید API": "API key",
	"کلید در فایل تنظیمات محلی ذخیره می‌شود؛ به جایی فرستاده نمی‌شود.": "The key is stored in the local config file; it goes nowhere else.",
	"گرفتن فهرست مدل‌ها": "Fetch the model list",
	"— پیش‌فرض —": "— default —",
	"اشاره به فایل": "Mention a file",
	"هنوز کاری ثبت نشده.": "No task recorded yet.",
	"شل پس‌زمینه‌ای در کار نیست.": "No background shell is running.",
	"در حال اجرا": "Running",
	"تمام شده": "Finished",
	"خروجی": "Output",
	"(خروجی خالی)": "(no output)",
	"بازگشت به این نقطه": "Rewind to this point",
	"انجام شد": "Done",
	"در حال انجام": "In progress",
	"در نوبت": "Queued",
	"فهرست کاری که عامل برای خودش می‌نویسد؛ با پیشرفت کار به‌روز می‌شود.": "The task list the agent writes for itself; it updates as the work moves.",
	"فرمان‌هایی که در پس‌زمینه اجرا شده‌اند. خروجی هرکدام را می‌شود خواند و اجرای در حال کار را بست.": "Commands that ran in the background. You can read each one’s output and stop what is still running.",
	"پیش از هر تغییر فایل، یک نقطهٔ بازگشت ساخته می‌شود. از همین‌جا می‌شود به هرکدام برگشت.": "Before every file change a rewind point is made. You can return to any of them from here.",

	"اسکیل": "Skill",
	"هوک": "Hook",
	"مدل": "Model",
	"روشن": "on",
	"منبع": "Source",
	"قاعده": "rule",
	"بدون ابزار": "no tools",
	"نوبت": "rounds",
	"هم‌زمانی": "Concurrency",

	// برچسب‌هایی که سرور می‌فرستد (راهبردها و دسته‌های هاب)
	"ارزان‌ترین": "Cheapest",
	"استدلال بلند": "Long reasoning",
	"بدون احراز": "No auth",
	"بینایی": "Vision",
	"تحلیل داده": "Data analysis",
	"ترجمه": "Translation",
	"خلاصه‌سازی ارزان": "Cheap summarising",
	"خودکار (امتیازدهی زنده)": "Automatic (live scoring)",
	"دو انتخاب تصادفی": "Two random picks",
	"سریع‌ترین": "Fastest",
	"عمومی": "General",
	"عیب‌یابی": "Debugging",
	"متن فارسی": "Persian text",
	"نوبتی": "Round-robin",
	"هدر دلخواه": "Custom header",
	"وزنی": "Weighted",
	"پارامتر آدرس": "Query parameter",
	"پاسخ به مشتری": "Customer reply",
	"پرکردن اولی": "Fill the first",
	"کدنویسی": "Coding",
	"کم‌کارترین": "Least busy",
	"اولین سالم": "First healthy",

	"مخزن‌های مجاز": "Repositories you allowed",
	"فهرست مخزن‌ها در دسترس نیست.": "The repository list is unavailable.",
	"مخزنی به ویرا مجوز نداده‌ای.": "You have not granted Vira access to any repository.",
	"در حال خواندن مخزن‌های مجاز…": "Reading the allowed repositories…",
	"گفتگو شروع شده؛ مخزن تا گفتگوی تازه قفل است.": "The chat has started; the repository is locked until a new chat.",
	"گفتگو شروع شده؛ شاخه تا گفتگوی تازه قفل است.": "The chat has started; the branch is locked until a new chat.",
	"خصوصی": "private",
	"تا گفتگوی تازه قفل است": "locked until a new chat",

	"پروژه را بساز؛ گفتگو خودش به آن اضافه می‌شود.": "Create the project — the chat will join it by itself.",

	// ── پنل‌ها و منوهای عمیق (جاروی زنده اینها را می‌گیرد) ──────────────
	'ویرا': 'Vira',
	'برو به آخر': 'Jump to latest',
	'گزینه‌ها': 'Options',
	'تغییر نام یا حذف': 'Rename or delete',
	'مدل': 'Model',
	'حالت کار': 'Mode',
	'حالت کار (Shift+Tab)': 'Mode (Shift+Tab)',
	'خروجی گفتگو': 'Export chat',
	'مخزن — کلیک برای تعویض یا اتصال': 'Repository — click to switch or connect',
	'شاخه — کلیک برای تعویض یا ساخت': 'Branch — click to switch or create',
	'تغییرات — کلیک برای دیدن دیف': 'Changes — click to see the diff',
	'همین حالا': 'just now',
	'عادی': 'Normal',
	'راهنما': 'Help',
	'اجرا': 'Run',
	'نصب': 'Install',
	'روشن': 'On',
	'خاموش': 'Off',
	'فعال': 'Enabled',
	'روشن کن': 'Turn on',
	'سراسری': 'Global',
	'پیش‌فرض': 'Default',
	'وضعیت': 'Status',
	'مسیرها': 'Routes',
	'ایمیج': 'Image',
	'شبکه': 'Network',
	'تم': 'Theme',
	'تراکم': 'Density',
	'راحت': 'Comfortable',
	'فشرده': 'Compact',
	'تاریک': 'Dark',
	'اندازهٔ متن': 'Text size',
	'خواندن': 'Read',
	'با ابزار': 'With tool',
	'همراه تصویر': 'With image',
	'وصل نشد': 'Not connected',
	'هاب خاموش است': 'The hub is off',
	'نمونهٔ آماده': 'Sample',
	'نمونه: فایل‌سیستم': 'Sample: filesystem',
	'نمونه: گیت‌هاب': 'Sample: GitHub',
	'مرور مارکت‌پلیس': 'Browse the marketplace',
	'نصب اسکیل': 'Install a skill',
	'نصب پلاگین': 'Install a plugin',
	'owner/repo یا /path/to/skill': 'owner/repo or /path/to/skill',
	'owner/repo یا مسیر محلی': 'owner/repo or a local path',
	'مثلاً: این تابع خطا می‌دهد، دیباگش کن': 'For example: this function throws, debug it',
	'ترکیب‌ها': 'Combos',
	'دستهٔ کار': 'Task category',
	'راهبرد': 'Strategy',
	'راهبرد پیش‌فرض': 'Default strategy',
	'حداکثر تلاش': 'Max attempts',
	'حداقل شکست هم‌امضا': 'Minimum matching failures',
	'سقف هزینه': 'Cost cap',
	'سقف روزانهٔ کل ($)': 'Total daily cap ($)',
	'سقف هر کار ($)': 'Per-task cap ($)',
	'سقف هر مدیر ($)': 'Per-manager cap ($)',
	'سقف تماس روزانه': 'Daily call cap',
	'سقف تماس هر امضا در ساعت': 'Per-signature calls per hour',
	'سقف CPU': 'CPU limit',
	'سقف حافظه': 'Memory limit',
	'کش پاسخ': 'Response cache',
	'خالی کردن کش': 'Clear the cache',
	'دفتر راه‌حل‌ها': 'Solution ledger',
	'چه یاد گرفته': 'What it has learned',
	'سلامت مسیرها': 'Route health',
	'عیب‌یاب هاب': 'Hub diagnoser',
	'عیب‌یاب روشن باشد': 'Diagnoser enabled',
	'مدل عیب‌یاب': 'Diagnoser model',
	'اتصال عیب‌یاب': 'Diagnoser connection',
	'اتصال دوباره به همه': 'Reconnect everything',
	'این درخواست به کجا می‌رود؟': 'Where does this request go?',
	'ببین کجا می‌رود': 'See where it goes',
	'موتور کانتینر': 'Container engine',
	'خودکار (اول docker، بعد podman)': 'Automatic (docker first, then podman)',
	'تست سندباکس': 'Test the sandbox',
	'سندباکس اجرای فرمان': 'Command sandbox',
	'فرمان‌ها را داخل کانتینر اجرا کن': 'Run commands inside a container',
	'ریشهٔ کانتینر فقط‌خواندنی باشد (به‌جز /tmp)': 'Container root read-only (except /tmp)',
	'اگر کانتینر در دسترس نبود، روی سیستم اجرا کن (پیش‌فرض: نه — اجرا نشود)': 'If no container is available, run on the host (default: no — refuse)',
	'بسته — بدون اینترنت (امن‌ترین)': 'Closed — no internet (safest)',
	'معمولی — اینترنت دارد': 'Normal — has internet',
	'شبکهٔ میزبان (ناامن؛ فقط اگر می‌دانی چرا)': 'Host network (unsafe; only if you know why)',
	'مسیرهای اضافه': 'Extra mounts',
	'+ مسیر اضافه': '+ Add a mount',
	'به شکل host:container — مثلاً /home/me/.composer:/root/.composer': 'As host:container — e.g. /home/me/.composer:/root/.composer',
	'چه چیزی را محافظت می‌کند و چه چیزی را نه': 'What it protects and what it does not',
	'پوشهٔ کاری': 'Workspace',
	'تغییر پوشه': 'Change folder',
	'حافظهٔ پروژه (VIRA.md)': 'Project memory (VIRA.md)',
	'تعریف هوک‌ها (JSON)': 'Hook definitions (JSON)',
	'دستورهای اسلش': 'Slash commands',
	'کانکتورها (سرورهای MCP)': 'Connectors (MCP servers)',
	'شل‌های پس‌زمینه': 'Background shells',
	'چک‌پوینت‌ها': 'Checkpoints',
	'فهرست کار': 'Task list',
	'توکن ورودی این نشست': 'Input tokens this session',
	'توکن خروجی این نشست': 'Output tokens this session',
	'هزینهٔ این نشست': 'Cost of this session',
	'همیشه مجاز': 'Always allowed',
	'همیشه ممنوع': 'Always denied',
	'همیشه بپرس': 'Always ask',
	'+ قاعدهٔ مجاز': '+ Allow rule',
	'+ قاعدهٔ ممنوع': '+ Deny rule',
	'+ قاعدهٔ پرسشی': '+ Ask rule',
	'+ پروفایل تازه': '+ New profile',
	'+ کانکتور تازه': '+ New connector',
	'+ اتصال تازه': '+ New connection',
	'+ ترکیب تازه': '+ New combo',
	'+ دستور تازه': '+ New command',
	'+ زیرعامل تازه': '+ New subagent',
	'خودکار — بدون تأیید (جز فهرست ممنوع)': 'Automatic — no confirmation (except the deny list)',
	'عادی — نوشتن و اجرا با تأیید': 'Normal — writes and commands need confirmation',
	'پلن — فقط بررسی و خواندن': 'Plan — read and review only',
	'اگر مدل اول شکست خورد، بی‌صدا برو سراغ بعدی': 'If the first model fails, quietly move to the next',
	'وصله‌های موفق بدون تأیید من ماندگار شوند': 'Keep successful patches without asking me',
	'اجازهٔ جستجوی اینترنتی — فقط متن خطای پاک‌سازی‌شده بیرون می‌رود': 'Allow web search — only the scrubbed error text leaves',
	'— بدون مدل عیب‌یاب (فقط پله‌های یک و دو) —': '— No diagnoser model (steps one and two only) —',
	'هنوز اتصالی از کاتالوگ نساخته‌ای.': 'You have not created a catalog connection yet.',
	'هنوز اتصال سازگاری نساخته‌ای.': 'You have not created a compatible connection yet.',
	'هنوز ترکیبی نساخته‌ای. بدون ترکیب، همهٔ مدل‌های روشن با راهبرد پیش‌فرض نامزد می‌شوند.': 'No combos yet. Without one, every enabled model is a candidate under the default strategy.',
	'هنوز مدلی کشف نشده. در صفحهٔ پرووایدرها، روی «کشف مدل‌ها» بزن.': 'No models discovered yet. On the providers page, hit “Discover models”.',
	'هنوز تماسی ثبت نشده.': 'No calls recorded yet.',
	'هنوز مصرفی ثبت نشده.': 'No usage recorded yet.',
	'هنوز چیزی یاد نگرفته — چند نوبت کار لازم است.': 'Nothing learned yet — it needs a few rounds of work.',
	'دفتر خالی است — یعنی هنوز خطایی نبوده که راه‌حلش آزموده شده باشد.': 'The ledger is empty — no error has had a tested fix yet.',
	'تنظیمات ظاهری در همین مرورگر ذخیره می‌شود.': 'Appearance settings are stored in this browser.',
	'اگر چیزی کار نمی‌کند، اول اینجا را ببین.': 'If something is broken, look here first.',
	'این همان کد مخزن است؛ هر git pull بلافاصله اثر می‌گذارد.': 'This is the repository code itself; every git pull takes effect immediately.',
	'ابزارها حذف نمی‌شوند؛ آنچه کنترل می‌شود دسترسی است — در تب مجوزها.': 'Tools are never removed; what you control is access — on the Permissions tab.',
	'قاعده می‌تواند نام ابزار باشد (مثل bash) یا پیشوندی (مثل bash:git) یا * برای همه.': 'A rule can be a tool name (like bash), a prefix (like bash:git), or * for everything.',
	'هرچه اینجا بنویسی، در هر گفتگو به مدل داده می‌شود. جای قواعد پروژه، سبک کد، و کارهای ممنوع.': 'Whatever you write here goes to the model in every conversation: project rules, code style, and what is off limits.',
	'هر کانکتور، ابزارهای یک سرویس بیرونی را داخل ویرا می‌آورد. دو نوع: اجرای فرمان محلی (stdio) یا آدرس اینترنتی (HTTP).': 'A connector brings an outside service’s tools into Vira. Two kinds: a local command (stdio) or a URL (HTTP).',
	'یک پلاگین می‌تواند اسکیل، دستور، کانکتور MCP و هوک با خودش بیاورد.': 'A plugin can bring skills, commands, MCP connectors and hooks with it.',
	'اسکیل آماده را از یک مخزن گیت‌هاب یا پوشهٔ محلی نصب کن. فرمت استاندارد SKILL.md پشتیبانی می‌شود.': 'Install a ready-made skill from a GitHub repository or a local folder. The standard SKILL.md format is supported.',
	'هر زیرعامل یک متخصص است با پرامپت، مدل و ابزارهای خودش. عامل اصلی با ابزار task صدایشان می‌زند.': 'Each subagent is a specialist with its own prompt, model and tools. The main agent calls them with the task tool.',
	'دستور خودت را بساز: متن دستور همان پرامپتی است که فرستاده می‌شود. $ARGUMENTS و $1 و $2 جایگزین می‌شوند.': 'Build your own command: its body is the prompt that gets sent. $ARGUMENTS, $1 and $2 are substituted.',
	'فرمان‌هایی که در لحظه‌های مشخص اجرا می‌شوند: PreToolUse، PostToolUse، UserPromptSubmit، SessionStart، SessionEnd، Stop. اگر PreToolUse با کد ۲ خارج شود، جلوی ابزار گرفته می‌شود.': 'Commands that run at defined moments: PreToolUse, PostToolUse, UserPromptSubmit, SessionStart, SessionEnd, Stop. If PreToolUse exits with code 2, the tool is blocked.',
	'وقتی روشن باشد، ابزار bash و شل‌های پس‌زمینه داخل یک کانتینر اجرا می‌شوند. خواندن و نوشتن فایل روی سیستم خودت می‌ماند (همین حالا هم به پوشهٔ کاری محدود است).': 'When on, the bash tool and background shells run inside a container. File reads and writes stay on your machine (already limited to the workspace).',
	'محافظت می‌کند: فرمانی که مدل اجرا می‌کند به بقیهٔ دیسک، به شبکه (اگر بسته باشد) و به دسترسی‌های سیستمی نمی‌رسد.': 'It protects you: a command the model runs cannot reach the rest of the disk, the network (when closed), or system capabilities.',
	'محافظت نمی‌کند: خودِ پوشهٔ کاری داخل کانتینر قابل نوشتن است — چون کار عامل همین است. برای برگرداندنش، چک‌پوینت داری.': 'It does not protect the workspace itself: that stays writable inside the container, because that is the agent’s job. To undo, you have checkpoints.',
	'برای پروژهٔ PHP/وردپرس: php:8.3-cli · برای جاوااسکریپت: node:22-bookworm-slim · ایمیج باید از قبل pull شده باشد یا شبکه باز باشد.': 'For a PHP/WordPress project: php:8.3-cli · for JavaScript: node:22-bookworm-slim · the image must already be pulled, or the network must be open.',
	'روی ویندوز به Docker Desktop با WSL2 نیاز داری.': 'On Windows you need Docker Desktop with WSL2.',
	'با روشن‌کردن هاب، ویرا بین چند اتصال و چند مدل خودش مسیریابی می‌کند.': 'With the hub on, Vira routes between several connections and models by itself.',
	'حالت سادهٔ قدیمی: یک پرووایدر، یک مدل. وقتی هاب روشن و آماده باشد این کنار گذاشته می‌شود و مسیریابی با هاب است.': 'The old simple mode: one provider, one model. Once the hub is on and ready, this is set aside and the hub does the routing.',
	'از کاتالوگ انتخاب کن، کلید بده، تست کن. می‌توانی از یک سرویس چند حساب داشته باشی — هر حساب یک سهمیهٔ جدا.': 'Pick from the catalog, add a key, test it. You can hold several accounts on one service — each with its own quota.',
	'هر سرویسی که مسیر سازگار با OpenAI یا Anthropic دارد: آدرس پایه، سبک احراز، هدر دلخواه و مسیر فهرست مدل، همه دست خودت.': 'Any service with an OpenAI- or Anthropic-compatible endpoint: base URL, auth style, custom headers and the models path are all yours to set.',
	'کشف خودکار یک نقطهٔ شروع است. برچسبی که اینجا می‌زنی بر آن می‌چربد، و آنچه ویرا از نتیجهٔ واقعی یاد می‌گیرد بر هر دو.': 'Auto-discovery is a starting point. A label you set here beats it, and what Vira learns from real results beats both.',
	'ترکیب یعنی یک زنجیرهٔ نام‌دار از مدل‌ها با یک راهبرد. دستهٔ کار می‌گوید کدام ترکیب برای چه جنسی از درخواست.': 'A combo is a named chain of models plus a strategy. The task category says which combo serves which kind of request.',
	'ترتیب مدل‌ها در هر ترکیب مهم است — راهبردهای اولویتی از بالا شروع می‌کنند.': 'Order matters inside a combo — priority strategies start from the top.',
	'ویرا جنس درخواست را خودش تشخیص می‌دهد؛ اینجا فقط می‌گویی هر جنس به کدام ترکیب برود.': 'Vira classifies the request itself; here you only say which combo each kind goes to.',
	'یک متن نمونه بنویس و ببین ویرا آن را چه جنسی می‌فهمد و به کدام مدل می‌فرستد — بدون اینکه تماسی گرفته شود.': 'Write a sample message and see how Vira classifies it and which model it would pick — without making a call.',
	'صدک تأخیر، نرخ موفقیت و وضعیت مدارشکن هر مدل. مدار باز یعنی ویرا فعلاً سراغ آن نمی‌رود.': 'Latency percentile, success rate and circuit state per model. An open circuit means Vira stays away for now.',
	'امتیاز هر مدل در هر دسته، از نتیجهٔ واقعی همین نصب — نه از یک جدول ثابت.': 'Each model’s score per category, from this installation’s real results — not from a fixed table.',
	'هرچه ویرا یاد گرفته، با تاریخ و شمار موفقیت. هر ردیف با یک دکمه پاک می‌شود.': 'Everything Vira has learned, with dates and success counts. Any row clears with one button.',
	'جدا از هاب تنظیم می‌شود — چیزی که قرار است هاب را تعمیر کند نباید از داخل خود هاب مسیر بگیرد.': 'Configured apart from the hub — whatever is meant to repair the hub must not be routed by it.',
	'یک مدل کوچک و ارزان کافی است؛ کارش خواندن متن خطا و پیشنهاد یک وصلهٔ ساختاریافته است.': 'A small, cheap model is enough; its job is to read the error and propose a structured patch.',
	'سقف خالی یعنی بی‌سقف. عبور از سقف، درخواست را رد می‌کند — نه اینکه فقط هشدار بدهد.': 'An empty cap means no cap. Going over rejects the request — it does not just warn.',
	'پاسخی که فراخوانی ابزار دارد کش نمی‌شود — چون اجرای دوبارهٔ ابزار، دنیای بیرون را عوض می‌کند.': 'A reply containing a tool call is never cached — running the tool again changes the outside world.',
	"خواندن فایل": "Read a file",
	"اجرای فرمان": "Run a command",
	"بدون کلید": "no key",
	"بدون مدل": "no model",
	"همهٔ ابزارها": "all tools",
	"مدل پیش‌فرض": "default model",
	"پروژه": "Project",
	"اصابت": "hits",
	"خطا": "errors",
	'هزینه تخمینی است و از جدول قیمت داخلی می‌آید؛ در config.json با کلید pricing قابل تغییر است.': 'Cost is an estimate from the built-in price table; change it in config.json under the pricing key.',
};

/** فرهنگ‌ها بر اساس زبان. فارسی کلیدِ خودش است، پس فرهنگ لازم ندارد. */
const DICT = { fa: null, en: EN };

let current = 'fa';

/** @returns {'fa'|'en'} */
export function lang() {
	return current;
}

/**
 * ترجمهٔ یک رشته.
 *
 * کلیدها **خودِ متن فارسی** هستند، نه شناسه‌های مصنوعی. دلیلش این است که کد بدون
 * فرهنگ هم خوانا بماند و اگر ترجمه‌ای جا افتاد، کاربر متن فارسی ببیند نه `nav.chats`.
 *
 * @param {string} fa
 */
export function t( fa ) {
	const dict = DICT[ current ];
	if ( ! dict ) {
		return fa;
	}
	return dict[ fa ] ?? fa;
}

/** آیا این زبان راست‌به‌چپ است؟ */
export function isRtl( code = current ) {
	return code === 'fa';
}

/**
 * زبان را عوض می‌کند و **هر سه چیز وابسته** را با هم به‌روز می‌کند.
 *
 * @param {'fa'|'en'} code
 */
export function setLang( code ) {
	current = DICT[ code ] !== undefined ? code : 'fa';
	localStorage.setItem( STORE_KEY, current );

	const root = document.documentElement;
	root.lang = current;
	root.dir = isRtl() ? 'rtl' : 'ltr';
	root.dataset.lang = current;
	if ( typeof document !== 'undefined' && document.body ) {
		sweep( document.body );
	}
	return current;
}

/** خواندن زبان ذخیره‌شده در شروع برنامه. */
export function initLang() {
	const saved = localStorage.getItem( STORE_KEY );
	return setLang( saved === 'en' ? 'en' : 'fa' );
}

/**
 * ترجمهٔ متن‌های ثابتِ داخل HTML.
 *
 * هر المانی که `data-t` داشته باشد، متنش ترجمه می‌شود؛ `data-t-title` و
 * `data-t-ph` هم برای تیتر و placeholder. این‌طور لازم نیست کل `index.html` را در
 * جاوااسکریپت بازتولید کنیم.
 */
export function translateDom( root = document ) {
	for ( const el of root.querySelectorAll( '[data-t]' ) ) {
		el.textContent = t( el.dataset.t );
	}
	for ( const el of root.querySelectorAll( '[data-t-title]' ) ) {
		el.title = t( el.dataset.tTitle );
	}
	for ( const el of root.querySelectorAll( '[data-t-ph]' ) ) {
		el.placeholder = t( el.dataset.tPh );
	}
}

/*
 * ═════════════════════════════════════════ جاروی زندهٔ صفحه
 *
 * شکایت کارفرما: «وقتی زبان روی انگلیسی است نباید هیچ متن فارسی دیده بشه.»
 *
 * پوشاندن ~۷۰۰ رشتهٔ فارسی با `t()` در ۹ فایل، هم پرخطاست هم هر رشتهٔ تازه‌ای که فردا
 * اضافه شود دوباره از قلم می‌افتد. پس ترجمه را به **خروجی** می‌بریم نه به صدا‌زدن‌ها:
 * یک جارو که متنِ خودِ DOM را ترجمه می‌کند و یک MutationObserver که هر چیز تازه‌ای را
 * هم می‌گیرد. هر رشته‌ای که ترجمه نداشته باشد، در تست فهرست می‌شود — نه اینکه بی‌صدا
 * فارسی بماند.
 */

const FA_CHAR = /[\u0600-\u06FF]/;
const FA_DIGITS = /[\u06F0-\u06F9\u0660-\u0669]/g;

/** الگوهای پارامتری — رشته‌هایی که عدد یا نام داخلشان است. */
const PATTERNS = [
	// — خاص صفحهٔ پراکسی (۰.۹.۵): باید قبل از الگوهای عمومیِ «از/در» بیایند —
	[ /^از پراکسی: (.+)$/, 'Via proxy: $1' ],
	[ /^مستقیم: (.+)$/, 'Direct: $1' ],
	[ /^IP (.+)$/, 'IP $1' ],
	[ /^در (\d+)ms$/, 'in $1ms' ],
	[ /^پراکسی مخصوص: (.+)$/, 'Dedicated proxy: $1' ],
	[ /^خطا: (.+)$/, 'Error: $1' ],
	[ /^نشانی مؤثر: مستقیم \(بدون پراکسی\)$/, 'Effective address: direct (no proxy)' ],
	[ /^نشانی مؤثر: (.+)$/, 'Effective address: $1' ],
	[ /^کانفیگ‌ها \((\d+) سالم از (\d+)\)$/, 'Configs ($1 working of $2)' ],
	[ /^(\d+) پیام$/, '$1 messages' ],
	[ /^(\d+) گفتگو$/, '$1 chats' ],
	[ /^(\d+) فایل$/, '$1 files' ],
	[ /^(\d+) فایل تغییرکرده$/, '$1 files changed' ],
	[ /^(\d+) تغییر$/, '$1 changes' ],
	[ /^ثبت (\d+) تغییر$/, 'Commit $1 changes' ],
	[ /^فرستادن (\d+) کامیت$/, 'Push $1 commits' ],
	[ /^(\d+) دقیقه پیش$/, '$1 minutes ago' ],
	[ /^(\d+) ساعت پیش$/, '$1 hours ago' ],
	[ /^(\d+) روز پیش$/, '$1 days ago' ],
	[ /^نسخه: ویرا (.+)$/, 'Version: Vira $1' ],
	[ /^ساخت: (.*)$/, 'Build: $1' ],
	[ /^کد از: (.+)$/, 'Code from: $1' ],
	[ /^پوشهٔ کاری: (.+)$/, 'Workspace: $1' ],
	[ /^ابزارها \((\d+)\)$/, 'Tools ($1)' ],
	[ /^امروز \((.*)\): (.+)$/, 'Today ($1): $2' ],
	[ /^اسکیل: (\d+) · دستور: (\d+)$/, 'Skills: $1 · Commands: $2' ],
	[ /^اسکیل: (\d+) · دستور: (\d+) · MCP$/, 'Skills: $1 · Commands: $2 · MCP' ],
	[ /^مارکت‌پلیس: (.+)$/, 'Marketplace: $1' ],
	[ /^مثلاً: (.+)$/, 'For example: $1' ],
	[ /^ورودی (.+)$/, 'input $1' ],
	[ /^خروجی (.+)$/, 'output $1' ],
	[ /^اولویت (.+)$/, 'priority $1' ],
	[ /^هم‌زمانی (.+)$/, 'concurrency $1' ],
	[ /^(\d+) موفق$/, '$1 ok' ],
	[ /^(\d+) ناموفق$/, '$1 failed' ],
	[ /^نرخ (.+)$/, 'rate $1' ],
	[ /^امروز (.+)$/, 'today $1' ],
	[ /^از (.+)$/, 'from $1' ],
	[ /^(.+) بار جواب داد$/, 'answered $1 times' ],
	[ /^«(.*)» نصب شد\.$/, '“$1” installed.' ],
	[ /^«(.*)» حذف شد\.$/, '“$1” removed.' ],

	[ /^(\d+) از (\d+) روشن$/, '$1 of $2 on' ],
	[ /^(\d+) مدل$/, '$1 models' ],
	[ /^(\d+) اتصال$/, '$1 connections' ],
	[ /^(\d+) مدل روشن\.?$/, '$1 models on' ],
	[ /^مسیریابی با هاب انجام می‌شود — (.+)$/, 'Routing is done by the hub — $1' ],
	[ /^ناموفق$/, 'failed' ],
	[ /^(\d+) روشن$/, '$1 on' ],
	[ /^(\d+) نوبت$/, '$1 rounds' ],
	[ /^\((\d+) نوبت\)$/, '($1 rounds)' ],

	[ /^(\d+) پاسخ در کش$/, '$1 cached replies' ],
	[ /^(\d+) اصابت$/, '$1 hits' ],
	[ /^(\d+) خطا$/, '$1 errors' ],
	[ /^امروز (\d+) تماس عیب‌یابی$/, 'Today $1 diagnoser calls' ],
	[ /^ابزارها: (.+)$/, 'Tools: $1' ],
];

/** ترجمهٔ یک رشتهٔ کامل، با تکیه بر فرهنگ و بعد الگوها. */
export function translate( text ) {
	const dict = DICT[ current ];
	if ( ! dict ) {
		return text;
	}
	const raw = String( text );
	const trimmed = raw.trim();
	if ( ! trimmed || ! FA_CHAR.test( trimmed ) ) {
		return raw.replace( FA_DIGITS, toAsciiDigit );
	}
	const lead = raw.slice( 0, raw.length - raw.trimStart().length );
	const tail = raw.slice( raw.trimEnd().length );
	return lead + core( trimmed, dict ) + tail;
}

/** جداکننده‌هایی که یک رشته را به چند جملهٔ مستقل می‌شکنند. */
const SPLITS = [ ' · ', ' — ', ' | ', ' ، ', '، ', ' / ' ];

/**
 * هستهٔ ترجمه: اول فرهنگ، بعد الگو، بعد شکستن به تکه‌ها، بعد عدد.
 * @param {string} text
 * @param {Record<string,string>} dict
 */
function core( text, dict ) {
	if ( ! FA_CHAR.test( text ) ) {
		return punct( text );
	}
	const hit = dict[ text ];
	if ( hit ) {
		return hit;
	}

	/*
	 * اول شکستن، بعد الگو.
	 *
	 * برعکسش یک بار دردسر شد: الگوی `^ورودی (.+)$` کل رشتهٔ «ورودی … · خروجی … » را
	 * می‌بلعید و بقیهٔ تکه‌ها ترجمه‌نشده می‌ماندند.
	 */
	for ( const sep of SPLITS ) {
		if ( ! text.includes( sep ) ) {
			continue;
		}
		const parts = text.split( sep ).map( ( piece ) => core( piece.trim(), dict ) );
		if ( ! parts.some( ( piece ) => FA_CHAR.test( piece ) ) ) {
			return parts.join( sep );
		}
	}

	/*
	 * برای الگوها، رقم و نشانه‌گذاری را لاتین می‌کنیم ولی نیم‌فاصله را دست نمی‌زنیم —
	 * خودِ الگوها نیم‌فاصله دارند. (`نرخ NaN٪` یک بار همین‌جا از تور در رفت: خروجی الگو
	 * هنوز `٪` داشت و «فارسی» شمرده می‌شد.)
	 */
	const ascii = text.replace( FA_DIGITS, toAsciiDigit ).replace( /٪/g, '%' ).replace( /٫/g, '.' );
	for ( const [ re, into ] of PATTERNS ) {
		if ( ! re.test( ascii ) ) {
			continue;
		}
		const out = ascii.replace( re, into );
		// اگر باز هم فارسی ماند، این الگو جواب نبوده — سراغ بعدی.
		if ( ! FA_CHAR.test( out ) ) {
			return out;
		}
	}

	// «+ چیزی» — دکمه‌های افزودن. علامت می‌ماند، بقیه ترجمه می‌شود.
	const plus = text.match( /^\+\s*(.+)$/ );
	if ( plus ) {
		const rest = core( plus[ 1 ], dict );
		if ( ! FA_CHAR.test( rest ) ) {
			return `+ ${ rest }`;
		}
	}

	// «برچسب: مقدار» — فقط برچسب ترجمه می‌شود، مقدار دست‌نخورده می‌ماند.
	const colon = text.match( /^([^:]{1,40}):\s*(.+)$/ );
	if ( colon ) {
		const head = colon[ 1 ].trim();
		// برچسب یا ترجمه دارد، یا اصلاً فارسی نیست (مثل نام مدل) — در هر دو حالت می‌ماند.
		const label = dict[ head ] || ( ! FA_CHAR.test( head ) ? head : '' );
		if ( label ) {
			const value = core( colon[ 2 ].trim(), dict );
			if ( ! FA_CHAR.test( value ) ) {
				return `${ label }: ${ value }`;
			}
		}
	}

	// «۱۲ فایل» یا «فایل ۱۲» — عدد سرِ جایش می‌ماند، متن ترجمه می‌شود.
	const head = text.match( /^([\d\u06F0-\u06F9.,%$+−-]+)\s+(.+)$/ );
	if ( head ) {
		const rest = core( head[ 2 ], dict );
		if ( ! FA_CHAR.test( rest ) ) {
			return `${ head[ 1 ].replace( FA_DIGITS, toAsciiDigit ) } ${ rest }`;
		}
	}
	const foot = text.match( /^(.+?)\s+([\d\u06F0-\u06F9.,%$+−-]+)$/ );
	if ( foot ) {
		const rest = core( foot[ 1 ], dict );
		if ( ! FA_CHAR.test( rest ) ) {
			return `${ rest } ${ foot[ 2 ].replace( FA_DIGITS, toAsciiDigit ) }`;
		}
	}

	return punct( text );
}

/** رقم و نشانه‌گذاری فارسی → لاتین. «۱۲٪» در متن انگلیسی، فارسی به‌نظر می‌رسد. */
function punct( text ) {
	return text
		.replace( FA_DIGITS, toAsciiDigit )
		.replace( /٪/g, '%' )
		.replace( /،/g, ',' )
		.replace( /؛/g, ';' )
		.replace( /؟/g, '?' )
		.replace( /٫/g, '.' )
		.replace( /\u200c/g, ' ' );
}

/** @param {string} d */
function toAsciiDigit( d ) {
	const fa = '۰۱۲۳۴۵۶۷۸۹';
	const ar = '٠١٢٣٤٥٦٧٨٩';
	const i = fa.indexOf( d );
	return String( i === -1 ? ar.indexOf( d ) : i );
}

/** متن اصلی هر گره، تا برگشت به فارسی چیزی گم نشود. */
const ORIGINAL = new WeakMap();
const ATTRS = [ 'title', 'placeholder', 'aria-label', 'alt' ];

/** یک گرهٔ متنی یا صفت را ترجمه می‌کند. */
function apply( node, get, set ) {
	const now = get();
	let seen = ORIGINAL.get( node );
	/*
	 * اگر متن فعلی همانی نیست که خودمان نوشتیم، یعنی برنامه محتوا را عوض کرده — پس
	 * «اصل» تازه می‌شود.
	 *
	 * بدون این شرط، جارو محتوای تازه را با متن قدیمیِ حفظ‌شده بازمی‌گرداند: عنوان گفتگو
	 * بعد از باز کردن یک نشست، دوباره «گفتگوی تازه» می‌شد. یک بار همین اتفاق افتاد و
	 * تستِ نامربوطی قرمز شد که آن را لو داد.
	 */
	if ( ! seen || ( now !== seen.written && now !== seen.source ) ) {
		if ( ! FA_CHAR.test( now || '' ) ) {
			return;
		}
		seen = { source: now, written: now };
		ORIGINAL.set( node, seen );
	}
	const source = seen.source;
	const next = current === 'fa' ? source : translate( source );
	/*
	 * نوشتنِ بی‌تغییر ممنوع.
	 *
	 * ناظرِ DOM با هر نوشتن دوباره صدا زده می‌شود؛ اگر مقدار یکسان را هم بنویسیم، حلقه
	 * بی‌پایان می‌شود. (اولین بار همین‌جا برنامه در تست قفل شد و هیچ خطایی هم نداد.)
	 */
	seen.written = next;
	if ( next !== now ) {
		set( next );
	}
}

/**
 * ترجمهٔ کل یک زیردرخت: متن‌ها، صفت‌ها، و مقدار دکمه‌ها.
 * @param {Node} root
 */
export function sweep( root = document.body ) {
	if ( ! root || noTranslate( root ) ) {
		return;
	}
	const nodes = root.nodeType === 1 ? [ root, ...root.querySelectorAll( '*' ) ] : [];
	for ( const el of nodes ) {
		// محتوای کاربر — عنوان گفتگو، نام پروژه، متن پیام — ترجمه نمی‌شود.
		if ( noTranslate( el ) ) {
			continue;
		}
		for ( const name of ATTRS ) {
			if ( el.getAttribute?.( name ) ) {
				apply(
					attrKey( el, name ),
					() => el.getAttribute( name ),
					( v ) => el.setAttribute( name, v )
				);
			}
		}
		const kids = el.childNodes || [];
		let sawText = false;
		for ( const child of kids ) {
			if ( child.nodeType === 3 ) {
				sawText = true;
				apply(
					child,
					() => child.nodeValue,
					( v ) => ( child.nodeValue = v )
				);
			}
		}
		// برگِ بی‌گرهِ متنی: متنش را مستقیم ترجمه کن (المانی که فقط textContent دارد).
		if ( ! sawText && ! kids.length && el.textContent ) {
			apply(
				el,
				() => el.textContent,
				( v ) => ( el.textContent = v )
			);
		}
	}
}

/** آیا این گره (یا نیایش) محتوای کاربر است؟ */
function noTranslate( node ) {
	let n = node;
	while ( n && n.nodeType === 1 ) {
		if ( n.hasAttribute ? n.hasAttribute( 'data-no-t' ) : n.getAttribute?.( 'data-no-t' ) !== null && n.getAttribute?.( 'data-no-t' ) !== undefined ) {
			return true;
		}
		n = n.parentNode;
	}
	return false;
}

/** برای هر صفت یک کلید یکتا لازم است، چون WeakMap روی خود المان یکی بیشتر جا ندارد. */
const ATTR_KEYS = new WeakMap();
function attrKey( el, name ) {
	let bag = ATTR_KEYS.get( el );
	if ( ! bag ) {
		bag = {};
		ATTR_KEYS.set( el, bag );
	}
	if ( ! bag[ name ] ) {
		bag[ name ] = { el, name };
	}
	return bag[ name ];
}

let observer = null;
let observed = null;

/**
 * جارو را زنده نگه می‌دارد: هر چیزی که بعداً به صفحه اضافه شود هم ترجمه می‌شود.
 *
 * اگر `document.body` عوض شده باشد (در تست‌ها هر بار یک DOM تازه)، ناظر قبلی روی جسدِ
 * صفحهٔ قبلی نشسته است. یک بار همین باعث شد تست سبز به‌نظر برسد در حالی که هیچ ترجمه‌ای
 * انجام نمی‌شد — پس ناظر با هر بدنهٔ تازه دوباره بسته می‌شود.
 */
export function watchDom() {
	if ( typeof MutationObserver === 'undefined' || ! document?.body ) {
		return;
	}
	if ( observer && observed === document.body ) {
		return;
	}
	observer?.disconnect();
	observer = new MutationObserver( ( records ) => {
		for ( const r of records ) {
			for ( const node of r.addedNodes || [] ) {
				if ( node.nodeType === 1 ) {
					sweep( node );
				} else if ( node.nodeType === 3 ) {
					apply(
						node,
						() => node.nodeValue,
						( v ) => ( node.nodeValue = v )
					);
				}
			}
			if ( r.type === 'characterData' && r.target ) {
				if ( r.target.nodeType === 3 ) {
					apply(
						r.target,
						() => r.target.nodeValue,
						( v ) => ( r.target.nodeValue = v )
					);
				} else {
					// `el.textContent = '…'` — در مرورگر گرهِ متنی تازه می‌سازد، در هارنس نه.
					sweep( r.target );
				}
			}
		}
	} );
	observed = document.body;
	observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
}

/** فهرست زبان‌ها برای منوی انتخاب. */
export const LANGS = [
	{ code: 'fa', label: 'فارسی', english: 'Persian' },
	{ code: 'en', label: 'English', english: 'English' },
];
