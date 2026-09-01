سند جامع معماری اتوماسیون هوشمند فروشگاه WordPress / WooCommerce

نسخه: 1.3 (تطبیق با تصمیم‌های نهایی — انتخاب مدل پادو با ما + حذف المنتور)
تاریخ اولیه: ۲۲ اوت ۲۰۲۶ (۱۴۰۵/۰۶/۰۱)
تاریخ تطبیق: ۲۲ اوت ۲۰۲۶ (۱۴۰۶/۰۵/۳۱)
وضعیت: سند طراحی مبنا؛ در تعارض‌ها، ADR-0004 و بخش ۴۶ مقدم‌اند

⚠️ تصمیم‌های جاری (۱۴۰۵/۰۶/۰۶):
- **پادو Playbookمحور و مستقل هر فروشگاه است.** DeepInfra provider هدف inference بود؛ از ۱۴۰۵/۰۶/۰۹ طبق `ADR-0005` ارائه‌دهنده «رکورد تنظیمات» (`api_provider` با گویش `protocol`) است و پیش‌فرض‌های جاری `groq`/`openrouter` هستند؛ حساب، کلید، بودجه و هزینه به همان فروشگاه تعلق دارد و انتخاب مدل هر Playbook را تیم IGBZ پس از benchmark انجام می‌دهد.
- **Zernio تنها provider اجتماعی است.** پرداخت مرکزی IGBZ و profile جدا برای هر فروشگاه دارد. Manus، ChatPlace، ManyChat و Ayrshare در معماری هدف نیستند.
- **المنتور و Hello Elementor از نصب پیش‌فرض حذف شده‌اند.** خروجی طراحی می‌تواند مطابق ADR-0003 یکی از سه نوع مجاز باشد، ولی مسیر بلوکی گزینهٔ امن پیش‌فرض است.

⚠️ توضیح مهم (قاعده ۹ §۰ HANDOFF): متن اولیه (v1.0) عیناً از طرف کارفرما ثبت شد (کامیت `66a5924`). در v1.1 بخش‌هایی که با تصمیم‌های بعدی پروژه تعارض داشتند **به‌روزرسانی و در جا اصلاح** شدند و یک بند جدید (بند ۴۲) تحت عنوان «تطبیق با تصمیم‌های پروژه» به انتها اضافه گردید. مواردی که در نسخهٔ اولیه با تصمیم‌های فعلی در تعارض بودند (مثل اتکا به Meta Graph API مستقیم، یا عدم تفکیک بین هستهٔ اتوماسیون داخلی و پادو) در متن اصلی اصلاح شده‌اند و نسخه ۱.۰ صرفاً در بکاپ `DESIGN-AUTOMATION.md.bak` قابل دسترسی است.


==================================================
1. هدف پروژه
==================================================

هدف، ساخت یک سیستم یکپارچه برای اتوماسیون و مدیریت هوشمند فروشگاه آنلاین مبتنی بر WordPress و WooCommerce است.

حوزه‌های اصلی سیستم:

1. SEO و رشد ارگانیک
2. بازاریابی و کمپین
3. CRM و مدیریت ارتباط با مشتری
4. پشتیبانی مشتری
5. بازاریابی و فعالیت Instagram
6. تحلیل عملکرد و یادگیری از نتایج

هسته اجرایی سیستم n8n خواهد بود و WooCommerce/WordPress منابع اصلی داده‌های فروشگاه هستند.

اصل کلیدی:

هوش مصنوعی تصمیم‌یار و عامل اجرایی سیستم است، اما منبع حقیقت داده‌های تجاری، خود فروشگاه و سرویس‌های رسمی متصل به آن هستند.


==================================================
2. منابع و مبانی فنی
==================================================

مبنای طراحی فقط دانش عمومی نیست و بر اساس مستندات رسمی و منابع معتبر فنی تهیه شده است.

منابع اصلی:

- Google Search Central
- Google Search Console API
- WooCommerce REST API
- WooCommerce Webhooks
- n8n Documentation
- n8n Human-in-the-loop
- مستندات رسمی Zernio برای اتصال، انتشار، Inbox، analytics، profile و webhook اینستاگرام
- مستندات رسمی DeepInfra برای API، مدل‌ها، billing و محدودیت‌های حساب مستقل
- Meta Instagram API فقط به‌عنوان مرجع قابلیت رسمی؛ IGBZ آن را مستقیم پیاده نمی‌کند
- راهنماهای معماری عامل و امنیت Playbook/ابزار محدود
- `nextlevelbuilder/ui-ux-pro-max-skill` (هفت اسکیل نصب‌شده در `.vira/skills/` به‌عنوان الگوی قواعد UX و ساختار SKILL.md)
- `21st.dev` (مرجع بصری طراحی قالب — WebGL hero، liquid/metal، gradient bar، glass card)

اصول مهم استخراج‌شده:

- محتوای AI باید برای کاربر ارزش واقعی داشته باشد.
- تولید انبوه محتوای کم‌ارزش برای SEO قابل قبول نیست.
- داده‌های تجاری باید از منبع اصلی خوانده شوند (WooCommerce = Source of Truth برای قیمت/موجودی/سفارش/مشتری).
- Agent نباید اطلاعاتی مانند قیمت، موجودی، وضعیت سفارش، هزینه ارسال، سیاست بازگشت یا مشخصات محصول را حدس بزند.
- عملیات حساس باید Human-in-the-loop داشته باشند (هیچ قیمت/Refund/انتشار/حذف/تغییر سیاست بدون تأیید مدیر اجرا نمی‌شود).
- Agentها نباید دسترسی کامل به سیستم داشته باشند؛ ابزار و منابع هر Agent به حداقل لازم محدود می‌شود (اصل least privilege).
- **همهٔ محدودیت‌ها در بک‌اند اعمال می‌شوند** — هرگز در فرانت‌اند/JS، که با رفرش صفحه قابل دور زدن است (اصل دائمی پروژه).
- **کدهای یک‌بارمصرف و رمزهای تصادفی** فقط از `Crypto::numeric_code()` / `random_int()` تولید می‌شوند — `rand()`/`mt_rand()` مطلقاً ممنوع است.
- **کرون‌های سنگین** (مثل بولت‌اوت در صدها/هزاران فروشگاه) باید **دسته‌ای و ادامه‌پذیر** باشند (idempotent و نشانه‌دار) و هرگز در یک درخواست/یک اجرای کرون همهٔ فروشگاه‌ها را پردازش نکنند. از **Action Scheduler** (که با ووکامرس روی همین سرور هست) برای صف‌های بادوام استفاده می‌شود، نه `wp_cron` عمومی و نه حلقهٔ دستی.
- **پادو runtime مستقل و Playbookمحور هر فروشگاه است.** ما پروسهٔ عامل عمومی به‌ازای فروشگاه روی سرور IGBZ اجرا نمی‌کنیم. IGBZ Playbook نسخه‌دار و API ابزار محدود را فراهم می‌کند؛ connector همان فروشگاه با حساب مستقل DeepInfra inference را انجام می‌دهد. n8n فقط orchestration قطعی داخلی است و ویرا gateway مدل نیست.
- **هیچ صفحه‌ساز ثالثی (از جمله Elementor) و هیچ قالب باندل‌شده‌ای (از جمله Hello Elementor) به‌صورت پیش‌فرض نصب نمی‌شود** (تصمیم نهایی ۱۴۰۶/۰۵/۳۱ به‌دلیل حجم بالا ~۱۰۸ مگابایت). هر فروشگاه با هسته وردپرس + ووکامرس + IGBZ-Suite + قالب پیش‌فرض هسته بالا می‌آید. مرحلهٔ ۱ پادو یک قالب فرزند FSE روی قالب هسته تولید می‌کند و به صفحه‌ساز ثالث وابسته نیست. ترتیب دقیق بوت‌استرپ (نسخه v6) در `HANDOFF-PROMPT.md §۷.۵` مستند است.
- قوانین بازار ایران: واحد پول IRR با نماد «تومان»، بدون اعشار، جداکننده هزارتایی `,`، کشور پیش‌فرض IR، وزن kg، COD فعال، بسته زبان `fa_IR` در تولید (نه mu-plugin موقت)، RTL و فونت محلی (بدون Google Fonts/CDN خارجی).
- **MCP/Abilities داخلی ووکامرس** (احراز هویت با کلید REST از طریق `X-MCP-API-Key`) تا حل مسئله دور زدن `CoreSurfaceGuard` **خاموش** می‌ماند و هیچ Agent/اسکیل به آن دسترسی ندارد.
- **تمام مسیرهای REST (اعم از ما و هسته وردپرس/ووکامرس)** از طریق `CoreSurfaceGuard` روی هوک `rest_pre_dispatch` محافظت می‌شوند؛ فهرست سفید مسیر، نه فهرست سیاه.
- **DeepInfra provider هدف نسخهٔ اول و انتخاب مدل هر Playbook با تیم IGBZ بود؛** از ۱۴۰۵/۰۶/۰۹ طبق `ADR-0005` هر ارائه‌دهنده یک رکورد `api_provider` است (پیش‌فرض `groq`/`openrouter`). هر فروشگاه حساب/کلید/هزینهٔ مستقل دارد و credential آن را در IGBZ یا ویرا ثبت نمی‌کند. connector مستقل فروشگاه inference را اجرا می‌کند و فقط از API ابزار محدود و schemaدار IGBZ استفاده می‌کند.


==================================================
3. معماری کلان
==================================================

در این نسخه سه لایه کاملاً از هم جدا شده‌اند (تصمیم ۱۴۰۵/۰۵/۳۱ و ۱۴۰۶/۰۵/۳۱):

┌──────────────────────────────────────────────────────────────┐
│  لایهٔ ۱: فروشگاه (روی سرور ما) — چندمستأجری                   │
│  WordPress + WooCommerce + قالب پیش‌فرض هسته                  │
│  + افزونه IGBZ-WP (۷۲ جدول، ۶ ماژول، CoreSurfaceGuard، صف‌ها)  │
│  + n8n (هسته اورکسترشن وردفلوهای قطعی/غیرLLM)                 │
│  + ویرا (هسته بومی Node.js — Skills/MCP/HITL/Model Router)    │
│  (بدون صفحه‌ساز پیش‌فرض؛ Elementor از پشته حذف شده — ۱۴۰۶/۰۵/۳۱)│
│  منبع حقیقت داده‌های تجاری: WooCommerce                        │
└──────────────────────────────────────────────────────────────┘
                          │  REST (محافظت‌شده با CoreSurfaceGuard)
                          │  Webhooks
                          ▼
┌──────────────────────────────────────────────────────────────┐
│  لایهٔ ۲: اتوماسیون داخلی (روی سرور ما — فقط برای مدیر ما)    │
│  n8n Orchestrator                                             │
│    ─ وردفلوهای قطعی (WF-001..WF-010 به‌علاوه وردفلوهای جدید) │
│    ─ Approval Center، Retry، Audit Log، Action Scheduler     │
│  ویرا (vira/)                                               │
│    ─ Model Router (OpenAI-compatible / ایرانی / محلی)        │
│    ─ Skill Registry، MCP Client، Permission gates            │
│    ─ سرویس توزیع/به‌روزرسانی اسکیل‌ها برای پادو             │
│    ─ گزارش ماهیانه از فعالیت فروشگاه‌ها                        │
│    ⚠️  ویرا روی سرور ما مدیران ما را سرویس می‌کند، نه مشتریان  │
│    فروشگاه. هیچ «عامل فروشگاه» روی سرور ما اجرا نمی‌شود.      │
└──────────────────────────────────────────────────────────────┘
                          │  فایل ZIP قالب فرزند FSE + تمپلیت‌های بلوکی
                          │  (خروجی اسکیل طراحی قالب — بدون صفحه‌ساز ثالث)
                          │  اسکیل‌ها (.zip / پوشه SKILL.md)
                          ▼
┌──────────────────────────────────────────────────────────────┐
│  لایهٔ ۳: پادو — سرویس/مدل انتخاب‌شده توسط ما              │
│  (مدل/سرویس توسط ما بر اساس بهترین عملکرد در اتوماسیون فروشگاه│
│   انتخاب می‌شود؛ ادمین فقط کلید API مربوطه را تهیه و در پنل ثبت│
│   می‌کند. مثال: Claude / GPT / Gemini / مدل خودمیزبان — تصمیم│
│   نهایی پس از ارزیابی عملکرد گرفته می‌شود).                  │
│  ما اسکیل‌های استاندارد `SKILL.md` را می‌نویسیم و به‌طور خودکار│
│  در اختیار پادو قرار می‌دهیم.                             │
│  پادو در محیط خودش یا از طریق API ما کار می‌کند و خروجی    │
│  را به دروازه بک‌اند ما تحویل می‌دهد؛ پس از اعتبارسنجی و preview│
│  با تأیید ادمین Live می‌شود.                                  │
└──────────────────────────────────────────────────────────────┘

جریان کلی داده از دیدگاه مشتری (فرانت فروشگاه):
Customer → WordPress/WooCommerce (RTL/فارسی/تومان)
    │  REST/Webhook
    ▼
n8n Orchestrator (workflow قطعی)
    │  (فقط در زمان نیاز به تصمیم/تولید)
    ├──► ویرا (Model Router) → مدل/ارائه‌دهنده مناسب
    │      │
    │      └── (در زمان نصب فروشگاه/مرحله ۱ طراحی قالب) خروجی
    │          اسکیل `igbz-store-theme` + اسکیل‌های مرجع
    │          (ui-ux-pro-max-skill) در اختیار پادو قرار
    │          می‌گیرد تا قالب را بسازد.
    └──► Approval Center → (HITL) → اجرا
Customer 360 + Knowledge Base ↔ Analytics Engine ↔ Experiments

⚠️ تمایز کلیدی: «Agent» در این سند دو معنا دارد که باید جدا شوند:
(الف) Agent به‌معنای «گردش‌کار هوشمند با LLM» که در ویرا/n8n اجرا می‌شود و داده‌های
     فروشگاه را می‌خواند ولی هرگز مستقیماً بدون HITL عملیات حساس پول/مشتری را
     اجرا نمی‌کند.
(ب) پادو به‌عنوان سرویس بیرونی (لایه ۳) که از اسکیل‌های ما استفاده می‌کند
     ولی داده‌های حساس فقط از طریق APIهای از پیش تعریف‌شده/گارددار به آن می‌رسد.



==================================================
4. اجزای اصلی سیستم
==================================================

4.1 WordPress

مسئول:

- صفحات سایت
- مقالات
- رسانه
- دسته‌بندی‌ها
- taxonomy
- محتوای سایت
- metadata

WordPress محل اجرای منطق پیچیده Agentها نخواهد بود.


4.2 WooCommerce

منبع اصلی داده‌های تجاری:

- محصولات
- قیمت
- موجودی
- سفارش‌ها
- مشتری‌ها
- کوپن‌ها
- وضعیت سفارش
- اطلاعات فروش

REST API v3 مبنای Integration خواهد بود.


4.3 n8n

n8n موتور **اورکسترشن وردفلوهای قطعی** سیستم است. تماس مستقیم با مدل‌ها از درون n8n
فقط از طریق هاب مدل ویرا انجام می‌شود (هیچ API Key مدل به‌صورت مستقیم در n8n ذخیره
نمی‌شود).

وظایف:

- Trigger / Webhook
- API calls (شامل REST ووکامرس/وردپرس فقط از طریق گیت CoreSurfaceGuard)
- Workflow execution (قطعی و قابل‌تکرار)
- شرط‌ها، Retry، Error handling
- صف (ترجیحاً Action Scheduler در سمت وردپرس برای کارهای چندمستأجری)
- اتصال به Approval Center برای عملیات حساس
- Audit/Logging
- اجرای Actionهای تأییدشده
- فراخوانی ویرا برای هر گام که نیاز به تصمیم‌گیری/تولید محتوای LLM دارد.

4.4 ویرا (vira/ — ابزار عامل داخلی ما)

ویرا نسخه ۰.۹.۹ در پوشه `vira/` مخزن موجود است (فاز ۰ کلون کامل Claude Code
ساخته شده). هسته بومی Node.js است با:

- Model Router (چند اتصال هم‌زمان، مسیریابی هوشمند، مدارشکن، بودجه/سقف هزینه)
- Skill Registry (نصب اسکیل آماده از مخزن/مسیر محلی، با فرمت `SKILL.md`)
- MCP Client (برای اتصال به MCP سرورهای تأییدشده؛ MCP داخلی ووکامرس فعلاً
  **خاموش** است)
- Hooks / Permission Gates / Checkpoint / تأیید انسانی
- SDK برای تعبیه (`query()` و `createVira()`) — دروازهٔ تماس n8n و پنل با مدل
- پوسته/رابط (درگاه اصلی مدیران برای نظارت و نه عملیات روزمره فروشگاه)

نقش ویرا در معماری جاری:
- runtime یا دروازهٔ مدل پادو نیست و ترافیک DeepInfra/Zernio از آن عبور نمی‌کند؛
- در صورت طراحی بعدی، فقط مدیریت نسخهٔ Playbook و گزارش تجمیعی حداقل‌داده برای مدیران IGBZ؛
- هیچ credential فروشگاه، کلید مرکزی Zernio یا گفت‌وگوی خام پادو را نگهداری نمی‌کند؛
- هیچ دسترسی مستقیم از بیرون ندارد و نقش داخلی آن از معماری پادو جداست.

4.5 پادو

پادو دستیار کامل ولی Playbookمحور هر فروشگاه است. تیم IGBZ مدل مناسب هر Playbook را پس
از benchmark تعیین می‌کند، اما حساب، credential، اعتبار و هزینهٔ DeepInfra متعلق به همان
فروشگاه و بیرون از IGBZ است. connector فروشگاه inference را اجرا می‌کند؛ بک‌اند IGBZ فقط
API ابزار حداقل‌اختیار، schema، policy، صف، تأیید و audit را در اختیارش می‌گذارد. هیچ
پروسهٔ عامل عمومی به‌ازای فروشگاه در ویرا یا IGBZ میزبانی نمی‌شود.


ارزیابی و انتخاب مدل بر اساس این معیارها خواهد بود (به‌ترتیب وزن تقریبی):
- کیفیت و قابلیت اطمینان در **ابزارفراخوانی (tool use / function calling)**
  و اجرای اسکیل‌های چندمرحله‌ای (مهم‌ترین برای اتوماسیون).
- پشتیبانی از **Structured Output** و رعایت قرارداد خروجی (مثلاً JSON معتبر/Zip).
- کیفیت تولید/ویرایش کد (CSS/HTML/JSON تمپلیت‌های بلوکی و `theme.json`) برای کار طراحی قالب FSE.
- پشتیبانی از متن فارسی و راست‌چین بودن خروجی.
- پایداری، نرخ خطا و زمان پاسخ.
- هزینه و سازگاری با بودجه عملیاتی پلتفرم.
- پشتیبانی از MCP/استاندارد `SKILL.md` (یا معادل آن).

آنچه ما برای/روی پادو فراهم می‌کنیم:
- **اسکیل‌های استاندارد** (فرمت `SKILL.md` + `references/`)، شامل:
  - اسکیل `igbz-store-theme` (طراحی قالب فروشگاه) — مرحلهٔ ۱.
  - اسکیل SEO، Marketing، CRM، Support، Instagram بر اساس وردفلوهای همین سند.
  - اسکیل‌های مرجع `ui-ux-pro-max-skill` (هفت اسکیل UX) به‌صورت پیش‌فرض نصب.
- **قواعد سراسری ایران/RTL/فونت/تومان/بدون CDN خارجی** در `references/` اسکیل‌ها
  (با الهام بصری از `21st.dev` برای الگوهای عمق/سه‌بعدی).
- **قرارداد خروجی** (zip قالب فرزند FSE روی قالب هسته، `theme.json` + تمپلیت‌های بلوکی HTML + CSS، Markdown محتوا، … — **بدون وابستگی به صفحه‌ساز ثالث**).
- **adapter و قرارداد اتصال مستقل** که اجرای Playbook، budget/timeout، usage report و خروجی
  schemaدار را ثبت می‌کند، بدون اینکه ویرا/IGBZ کلید DeepInfra را نگهداری یا ترافیک مدل را
  proxy کند.

⚠️ نصب/اعمال خروجی پادو **هرگز مستقیم نیست**:
خروجی پادو → اعتبارسنجی بک‌اند (فهرست سفید پسوند، عدم وجود کد مخرب،
مطابقت با قرارداد خروجی) → preview (محیط ایزوله/نسخه پیش‌نویس) → تأیید ادمین
→ Live → بازگشت‌پذیری یک‌کلیک. تمام این مراحل در Audit Log ثبت می‌شوند.


==================================================
5. Data Layer
==================================================

پیشنهاد اصلی:

PostgreSQL

در صورت نیاز:

pgvector

برای داده‌های ساختاریافته:

customers
orders
products
categories
campaigns
content
support_tickets
events
agent_actions
approvals
experiments

برای داده‌های غیرساختاریافته:

product_docs
faq
brand_guidelines
support_policies
seo_guidelines
marketing_guidelines
approved_content
internal_docs

اصل مهم:

قیمت، موجودی، سفارش و اطلاعات مشتری نباید فقط در Vector Database نگهداری شوند.

داده‌های ساختاریافته باید از منبع معتبر خود خوانده شوند.

الزامات چندمستأجری (مقیاس صدها تا هزاران فروشگاه × صدها هزار کاربر):

- تمام جدول‌های چندمستأجری ما با ستون `tenant_id` فیلتر می‌شوند (هیچ `SELECT` بدون
  شرط `tenant_id` و `LIMIT` در کوئری‌های حلقه‌ای/کرون مجاز نیست).
- **صف‌های بادوام** از **Action Scheduler** (همراه با ووکامرس) استفاده می‌کنند، نه
  `wp_cron` عمومی. کرون‌های WP-Cron فقط برای کارهای سبک و قطعی مجازند و در هر اجرا
  **دسته‌ای (batch)** و **ادامه‌پذیر** عمل می‌کنند (با نشانه‌گذاری در آپشن/جدول).
- **انبوه کارهای طولانی** (مثل بولت‌اوت سئو، کمپین‌ها، گزارش‌ها) باید در صف پشت‌صحنه
  بروند و در پاسخ HTTP هرگز انتظار پایانشان نیست.
- **محدودیت نرخ (Rate limiting)** در سرویس بک‌اند اعمال می‌شود (نه فرانت‌اند)؛ پاسخ‌های
  ۴۲۹ با `Retry-After` و برای کارهای قابل‌صف، ۲۰۲ به‌همراه شناسهٔ کار.
- فایل‌های آپلود/مدیای پادو قبل از ورود به کتابخانه وردپرس، اعتبارسنجی نوع
  (فهرست سفید پسوند/مایم) و اندازه می‌شوند. هر کد PHP اجرایی در zip/محتوا به‌طور
  قطعی رد می‌شود.


==================================================
6. Event Architecture
==================================================

سیستم Event-driven خواهد بود.

رویدادهای اصلی:

order.created
order.updated

customer.created
customer.updated

product.created
product.updated
product.deleted

content.created
content.updated
content.published

campaign.created
campaign.started
campaign.completed

support.ticket_created

instagram.content_published
instagram.comment_received


WooCommerce Webhooks و n8n WooCommerce Trigger برای اتصال این رویدادها استفاده خواهند شد.


==================================================
7. SEO Agent
==================================================

هدف SEO Agent تولید انبوه مقاله نیست.

هدف:

پیدا کردن بهترین اقدام بعدی برای رشد ارگانیک و فروش.

اجزای SEO:

- SEO Auditor
- Keyword & Intent Agent
- Product SEO Agent
- Content Planner
- On-page Optimizer
- Technical SEO Monitor
- SEO Performance Monitor


داده‌های مورد استفاده:

WooCommerce:
- Product
- Price
- Stock
- Category
- SKU
- Attributes

WordPress:
- Pages
- Posts
- Metadata

Search Console:
- Query
- Clicks
- Impressions
- CTR
- Position
- Page
- Device
- Country


==================================================
8. SEO Opportunity Score
==================================================

برای هر URL یک Opportunity Score محاسبه می‌شود.

عوامل:

Commercial Value
+ Search Opportunity
+ CTR Opportunity
+ Ranking Opportunity
+ Content Weakness
+ Technical Priority
+ Product Importance

قسمت‌های عددی deterministic هستند.

AI برای تحلیل مواردی مانند:

- Search Intent
- ضعف محتوا
- فرصت رشد
- پیشنهاد اقدام

استفاده می‌شود.


==================================================
9. SEO Workflow
==================================================

Schedule
   |
   v
WooCommerce Data
   |
   v
WordPress Data
   |
   v
Search Console
   |
   v
Technical Checks
   |
   v
SEO Agent
   |
   v
Opportunity Score
   |
   v
Action Recommendation
   |
   v
Validator
   |
   v
Human Approval
   |
   v
WordPress / WooCommerce
   |
   v
Measurement


==================================================
10. Search Console Automation
==================================================

سیستم باید بتواند:

- Queryهای مهم را شناسایی کند.
- صفحات با Impression زیاد و CTR پایین را پیدا کند.
- صفحات دارای فرصت رشد Ranking را پیدا کند.
- Queryهایی را که صفحه مناسب ندارند تشخیص دهد.
- Sitemap را پایش کند.
- در موارد لازم URL Inspection انجام دهد.


==================================================
11. Product SEO
==================================================

برای هر محصول بررسی می‌شود:

- Title
- Description
- Images
- Alt Text
- Category
- Attributes
- Price
- Availability
- Reviews
- Structured Data
- Internal Links

برای محصولات دارای Variant نیز اطلاعات Variantها باید صحیح و قابل تشخیص باشد.


==================================================
12. قانون محتوای AI
==================================================

AI می‌تواند:

- Content Brief بسازد.
- Outline تولید کند.
- Draft بنویسد.
- محتوا را بهینه کند.
- Internal Link پیشنهاد دهد.
- FAQ پیشنهاد دهد.

اما:

تولید انبوه صفحات کم‌ارزش ممنوع است.

فرآیند انتشار:

AI Draft
   |
   v
Fact Check
   |
   v
SEO Validation
   |
   v
Human Review
   |
   v
Publish


==================================================
13. Marketing Agent
==================================================

هدف:

تبدیل داده محصول و مشتری به کمپین قابل اندازه‌گیری.

اجزا:

- Campaign Planner
- Audience Agent
- Offer Agent
- Copy Agent
- Campaign Analyst


Workflow:

Trigger
   |
Customer/Product Data
   |
Audience Selection
   |
Campaign Planner
   |
Offer Decision
   |
Copy Generation
   |
Business Rules
   |
Approval
   |
Publish
   |
Track
   |
Revenue / Conversion
   |
Learning


==================================================
14. Customer Segmentation
==================================================

گروه‌های پایه:

- New Customer
- First Purchase
- Repeat Customer
- High Value
- Inactive
- At Risk
- Cart Abandoner
- Product-specific Customer

معیارهای عددی مانند تعداد سفارش و مبلغ خرید باید deterministic باشند.

AI برای تحلیل رفتار و پیشنهاد اقدام استفاده می‌شود.


==================================================
15. CRM Agent / Customer 360
==================================================

Customer 360 شامل:

Customer Profile
Orders
Purchased Products
Campaign History
Support History
Engagement
Customer Value
Next Best Action


Lifecycle:

New
  |
Welcome
  |
First Purchase
  |
Post Purchase
  |
Repeat
  |
Cross Sell
  |
Inactive
  |
Win Back
  |
Repeat / Churn


هدف CRM Agent:

پیشنهاد بهترین اقدام بعدی برای هر مشتری.


==================================================
16. Support Agent
==================================================

اصل اصلی:

Agent قبل از پاسخ باید منبع معتبر پیدا کند.

انواع درخواست:

1. Order Support
2. Product Support
3. Pre-Sales
4. Complaint
5. Refund / Payment


Workflow:

Customer Message
   |
Intent Classification
   |
Customer Resolution
   |
Knowledge / WooCommerce Lookup
   |
Evidence Check
   |
Response
   |
Confidence Check
   |
   +---- High ----> Auto Reply
   |
   +---- Low -----> Human Handoff


Agent نباید وضعیت سفارش، قیمت، موجودی، هزینه ارسال یا شرایط بازگشت را حدس بزند.


==================================================
17. Knowledge Base / RAG
==================================================

Knowledge Base شامل:

- FAQ
- سیاست ارسال
- سیاست بازگشت
- شرایط گارانتی
- راهنمای محصولات
- اطلاعات برند
- دستورالعمل پشتیبانی
- مقالات تأییدشده

فرآیند:

Question
   |
Retrieval
   |
Evidence
   |
Answer
   |
Validation

اگر Evidence کافی وجود نداشت:

Human Handoff


==================================================
18. Instagram Agent
==================================================

معماری جاری طبق ADR-0004:

1. گردآوری دادهٔ حداقل لازم از WooCommerce، Zernio Insight و ورودی رسمی/دستی رقبا؛
2. اجرای Playbook تحلیل و راهبرد با حساب مستقل DeepInfra همان فروشگاه؛
3. اجرای Playbook تولید برای Caption / Hook / CTA / media brief با fact check ووکامرس؛
4. validation، policy و در عملیات حساس تأیید مدیر در بک‌اند IGBZ؛
5. انتشار/زمان‌بندی فقط از Zernio و ثبت شناسهٔ رسانه، profile و نتیجه؛
6. دریافت webhook/analytics Zernio و بستن حلقهٔ KPI فروش و engagement.

Workflow:

WooCommerce + Zernio Insight + Manager Input
   |
Collect Playbook → Analyze Playbook → Strategy Playbook → Generate Playbook
   |
Schema / Brand / Product Fact Check
   |
Policy + Manager Approval when required
   |
Durable IGBZ Queue → Zernio Publish
   |
Signed Webhook / Polling Reconcile
   |
Sales + Engagement KPI Learning

Zernio profile از هویت احرازشدهٔ فروشگاه تعیین می‌شود، نه ورودی آزاد کلاینت. تماس مستقیم
Graph API، scraper، cookie و session ممنوع است. Business Discovery و Hashtag Search تا endpoint
رسمی Zernio گیت‌اند. صدای Instagram باید از کاتالوگ Zernio و با شناسه/provenance انتخاب شود.

محدودیت: تا قبولی آزمون دو profile، callback امن، benchmark فارسی و گیت‌های ADR-0004 این
مسیر production-ready نیست.


==================================================
19. Instagram Engagement
==================================================

Comment / DM
   |
Intent Classification
   |
   +---- Product Question
   |
   +---- Order Question
   |
   +---- Complaint
   |
   +---- Spam
   |
   +---- Collaboration

سؤال ساده محصول می‌تواند خودکار پاسخ بگیرد.

موارد حساس مانند شکایت جدی، پرداخت، Refund و مسائل امنیتی باید به مسیر انسانی منتقل شوند.


==================================================
20. عملیات حساس (گیت تأیید انسانی)
==================================================

طبق تصمیم پروژه، عملیات زیر **قابل انجام هستند اما اجرای نهایی آن‌ها فقط با تأیید
مدیر مجاز** از طریق Approval Center ممکن است. سه مورد اولیه از ابتدا در سند بود؛
چهار مورد دیگر بر اساس تصمیم متأخر پروژه (۱۴۰۶/۰۵/۳۱) اضافه شدند:


20.1 تغییر قیمت

AI Analysis
   |
Price Proposal
   |
Business Rules
   |
Manager Approval
   |
WooCommerce Update
   |
Audit Log


بدون تأیید مدیر هیچ تغییر قیمتی اجرا نمی‌شود.


20.2 Refund

Customer Request
   |
Order Lookup
   |
Policy Check
   |
Refund Recommendation
   |
Manager Approval
   |
Refund Action
   |
Customer Notification
   |
Audit Log


بدون تأیید مدیر Refund اجرا نمی‌شود.


20.3 Instagram Publishing

Content Generation
   |
Brand / Fact Check
   |
Manager Approval
   |
Instagram Publish
   |
Record Media ID
   |
Analytics


بدون تأیید مدیر انتشار انجام نمی‌شود.


20.4 حذف انبوه محصول/صفحه/داده

تحلیل / پیشنهاد
   |
Impact Report (تعداد رکورد، تأثیر روی سئو/سفارش‌ها)
   |
Manager Approval (با تایپ عبارت تأیید برای عملیات بزرگ)
   |
WooCommerce/WP Action
   |
Soft Delete اولیه (قابل بازگشت در پنجره زمانی)
   |
Audit Log


20.5 تغییر گسترده URLها / ساختار سایت

تحلیل ریدایرکت
   |
تأثیر روی سئو (Search Console data)
   |
Preview ریدایرکت‌ها
   |
Manager Approval
   |
اجرای دسته‌ای
   |
بازنشانی sitemap / اطلاع به Search Console
   |
Audit Log


20.6 ارسال کمپین بزرگ (ایمیل/پیام/اینستاگرام)

Audience Selection
   |
Segment Size + Cost Estimate
   |
Content/Copy
   |
Send to small test bucket
   |
Check metrics (unsubscribe، spam rate)
   |
Manager Approval
   |
ارسال
   |
Audit Log


20.7 تغییر سیاست فروشگاه / شرایط بازگشت / قیمت‌گذاری کلی

Policy Draft
   |
Legal Check (چک‌لیست قوانین ایران/درگاه‌ها)
   |
Manager Approval
   |
Publish
   |
Audit Log


==================================================
21. Approval Center
==================================================

Approval Center مرکزی برای همه عملیات حساس.

اطلاعات هر Approval:

approval_id
action_type
agent
resource
proposed_action
reason
risk_level
created_at
expires_at
approved_by
approved_at
result


Action Typeهای اصلی:

PRICE_CHANGE
REFUND
INSTAGRAM_PUBLISH
CAMPAIGN_SEND
PRODUCT_UPDATE
CONTENT_PUBLISH


==================================================
22. Risk Levels
==================================================

LOW:

- Analysis
- Reporting
- Data Collection
- Segmentation
- Suggestions

MEDIUM:

- Content Publishing
- Metadata Changes
- Campaign Draft
- Product Changes

HIGH:

- Price Change
- Refund
- Data Deletion
- Important Order Changes
- Paid Advertising
- Sensitive Customer Operations

تمام عملیات HIGH نیازمند Approval هستند.


==================================================
23. Agent Permissions
==================================================

SEO Agent:

Read:
Search Console / WordPress / WooCommerce

Write:
Content / Metadata با Approval

Delete:
ممنوع


Marketing Agent:

Read:
Customer / Product / Campaign

Write:
Draft Campaign

Send:
Approval


CRM Agent:

Read:
Customer / Order

Write:
Segments / Events

Delete:
ممنوع


Support Agent:

Read:
Customer / Order / Product / Knowledge

Write:
Ticket / Draft Response

Sensitive:
Approval


Instagram Agent:

Read:
دادهٔ حداقل لازم WooCommerce و Insight/Inbox همان profile از API اختصاصی بک‌اند

Write:
خروجی schemaدار Playbook شامل پیش‌نویس کپشن/رسانه/برنامه و پیشنهاد پاسخ

Publish:
فقط پس از policy/approval لازم، از صف بادوام بک‌اند و Zernio؛ هرگز مستقیم

همهٔ عامل‌ها:
- DELETE به‌صورت پیش‌فرض ممنوع مگر با مجوز صریح و Soft Delete اولیه.
- هرگونه تماس با شبکه خارج از Workflow ثبت می‌شود.
- کلید مرکزی Zernio فقط در secret store بک‌اند است؛ credential DeepInfra فقط در حساب مستقل
  پادو/connector فروشگاه و بیرون از IGBZ می‌ماند؛ ویرا و n8n هیچ‌یک مالک این رازها نیستند.
- ابزار و هر MCP احتمالی فقط با allowlist، scope همان فروشگاه و قرارداد نسخه‌دار قابل استفاده است.


==================================================
24. Audit Log
==================================================

هر Action باید ثبت شود.

فیلدهای اصلی:

timestamp
agent
workflow
user/customer
resource
old_value
new_value
reason
model
approval_id
approved_by
result
error


این Log برای:

- امنیت
- Debugging
- بررسی تصمیم Agent
- Audit
- بازگردانی
- تحلیل عملکرد

استفاده می‌شود.


==================================================
25. Guardrails
==================================================

هر Agent باید محدودیت داشته باشد:

- Tool محدود
- Resource محدود
- Timeout
- Retry Limit
- Validation
- Schema Validation
- Confidence Threshold
- Human Escalation

Agent نباید بتواند خارج از محدوده تعریف‌شده عمل کند.


==================================================
26. Fact Checking
==================================================

AI نباید موارد زیر را حدس بزند:

- Price
- Stock
- Order Status
- Shipping Cost
- Discount
- Return Policy
- Product Specifications
- Delivery Time

این اطلاعات باید از Source of Truth خوانده شوند.


==================================================
27. جلوگیری از Hallucination
==================================================

الگوی استاندارد:

Question
   |
Retrieve
   |
Evidence
   |
Answer
   |
Validation


اگر Evidence کافی نبود:

"I don't have enough verified information."

سپس Human Handoff.


==================================================
28. اتصال SEO + Marketing + CRM
==================================================

Search Console
   |
SEO Opportunity
   |
Product
   |
Marketing Opportunity
   |
Customer Segment
   |
Campaign
   |
Instagram
   |
Traffic / Sales
   |
WooCommerce
   |
CRM
   |
Learning


این حلقه باعث می‌شود داده SEO فقط برای SEO استفاده نشود.


==================================================
29. Customer Journey Engine
==================================================

Visitor
   |
Product View
   |
Purchase
   |
order.created
   |
CRM Update
   |
Customer Segment
   |
Post Purchase
   |
Cross Sell
   |
Marketing / Instagram
   |
Purchase
   |
Customer Value Update


==================================================
30. Analytics
==================================================

SEO:

- Organic Clicks
- Impressions
- CTR
- Position
- Indexed Pages
- Organic Revenue


Marketing:

- Conversion Rate
- Revenue
- ROI
- ROAS
- CAC


CRM:

- Repeat Purchase
- Customer Value
- Reactivation
- Churn


Support:

- First Response Time
- Resolution Rate
- Escalation Rate
- Customer Satisfaction


Instagram:

- Reach
- Engagement
- Profile Actions
- Website Clicks
- Assisted Conversions
- Revenue


==================================================
31. Experiment Engine
==================================================

هر تغییر مهم باید به‌صورت Experiment ثبت شود.

اطلاعات:

experiment_id
URL
change_type
before
after
start_date
measurement_window
clicks_before
clicks_after
CTR_before
CTR_after
conversion_before
conversion_after
conclusion


هدف:

سیستم به مرور یاد بگیرد چه تغییراتی برای همین فروشگاه مؤثر هستند.


==================================================
32. Workflowهای اصلی n8n
==================================================

WF-001 — WooCommerce Event Router

WooCommerce Trigger
   |
Validate Event
   |
Event Normalizer
   |
PostgreSQL
   |
Route Event


WF-002 — Customer 360 Sync

Customer / Order Event
   |
Resolve Customer
   |
Update Customer 360
   |
Recalculate Segment


WF-003 — Daily SEO Audit

Schedule
   |
WooCommerce
   |
WordPress
   |
Search Console
   |
SEO Agent
   |
Opportunity Score
   |
Database


WF-004 — Product SEO

Product Updated
   |
Product SEO Agent
   |
Structured Data Check
   |
Recommendation
   |
Approval
   |
WooCommerce


WF-005 — Content Brief

SEO Opportunity
   |
Knowledge Retrieval
   |
Content Agent
   |
Brief
   |
Approval


WF-006 — Campaign Planner

Customer Segments
   |
Products
   |
SEO Opportunities
   |
Marketing Agent
   |
Campaign Draft
   |
Approval


WF-007 — Support

Customer Message
   |
Intent
   |
Customer Lookup
   |
Knowledge Retrieval
   |
WooCommerce Lookup
   |
Evidence Check
   |
Response / Human


WF-008 — Instagram

Campaign
   |
Content Agent
   |
Brand Check
   |
Manager Approval
   |
Instagram API
   |
Analytics


WF-009 — Price Change

Price Analysis
   |
Proposal
   |
Manager Approval
   |
WooCommerce
   |
Audit


WF-010 — Refund

Request
   |
Order
   |
Policy
   |
Recommendation
   |
Manager Approval
   |
Refund
   |
Audit


==================================================
33. ترتیب پیاده‌سازی
==================================================

PHASE 1 — FOUNDATION

- Server / محیط توسعه آفلاین (مطابق `_devenv/` — WordPress 7.x، WooCommerce، PHP-WASM)
- n8n + PostgreSQL + pgvector
- WordPress + WooCommerce API
- قالب پیش‌فرض هسته وردپرس (مثلاً Twenty Twenty-Five) — **بدون صفحه‌ساز ثالث پیش‌فرض**
  (المنتور و Hello Elementor در v1.3 به‌دلیل حجم ~۱۰۸ مگابایت حذف شدند)
- بوت‌استرپ استاندارد فروشگاه جدید v6 (مطابق ترتیب هوک‌ها در `HANDOFF-PROMPT.md §۷.۵`):
  فعال‌سازی پلاگین‌ها به‌ترتیب WC→igbz-suite · `switch_theme` در `setup_theme` اولویت ۰
  (به قالب هسته) · تنظیمات IR/IRR/تومان/بدون اعشار/kg · صفحات فارسی · صفحه اصلی=فروشگاه ·
  `woocommerce_coming_soon=no` · پرمالینک `/%postname%/` · WPLANG fa_IR
- Credentials: رازهای پلتفرمی در secret store بک‌اند؛ کلید مرکزی Zernio فقط بک‌اند؛ کلید
  DeepInfra مستقل فروشگاه بیرون از IGBZ؛ هیچ راز پادو/اجتماعی در ویرا یا n8n
- CoreSurfaceGuard (مسیرهای REST هسته/ووکامرس هم محافظت شوند)
- Logging (Audit Log در جدول `igbz_audit` + لاگ‌های عمل‌گرا)
- Webhooks / Event Router (WF-001)
- Action Scheduler برای صف‌های بادوام (به‌جای wp_cron برای کارهای سنگین)
- بسته زبان رسمی fa_IR (برای تولید)


PHASE 2 — CUSTOMER & PRODUCT DATA

- Product Sync
- Customer Sync
- Order Sync
- Customer 360
- Event Store


PHASE 3 — SEO

- Search Console
- SEO Audit
- Product SEO
- Content Opportunities
- Knowledge Base


PHASE 4 — MARKETING

- Segmentation
- Campaign Planner
- Offer Engine
- Copy Agent
- Campaign Analytics


PHASE 5 — CRM

- Lifecycle
- Next Best Action
- Reactivation
- Cross Sell


PHASE 6 — SUPPORT

- Knowledge Base
- RAG
- Order Lookup
- Ticketing
- Human Handoff


PHASE 7 — INSTAGRAM

- Meta App
- Authentication
- Content
- Approval
- Publishing
- Comments
- Analytics


PHASE 8 — ADVANCED AUTOMATION

- Price Optimization
- Refund Workflow
- Advanced Analytics
- Experiments
- Advanced Automation


==================================================
34. MVP پیشنهادی
==================================================

برای شروع واقعی، MVP شامل:

WooCommerce
+
n8n
+
PostgreSQL
+
Customer 360
+
SEO Audit
+
Search Console
+
Basic Marketing
+
Support
+
Approval Center

Instagram بعد از پایدار شدن هسته اصلی اضافه می‌شود.


==================================================
35. مواردی که از ابتدا نباید بدون کنترل اجرا شوند
==================================================

- حذف انبوه محصولات/صفحات/داده (بدون تأیید و بدون Soft-Delete اولیه)
- تغییر گسترده URL (بدون ریدایرکت و تأیید)
- Refund بدون Approval
- تغییر قیمت بدون Approval
- ارسال کمپین بزرگ بدون Approval
- انتشار Instagram بدون Approval
- تغییر سیاست فروشگاه بدون Approval
- تغییر اطلاعات حساس مشتری (تلفن، آدرس، کد ملی، تراکنش‌ها) بدون مجوز
- غیرفعال‌کردن امنیتی (CoreSurfaceGuard، OTP، rate limit، بیومتریک) تحت هر عنوان
- استفاده از `rand()`/`mt_rand()` برای توکن/OTP/رمز یک‌بارمصرف
- اعمال محدودیت‌های امنیتی فقط در فرانت‌اند (JS) که با رفرش قابل دور زدن است
- استفاده از CDN/فونت خارجی (Google Fonts و …) در خروجی/قالب
- نصب/فعال‌سازی MCP داخلی ووکامرس (`mcp_integration=yes`) تا حل مسئله
  CoreSurfaceGuard
- نصب/اجرای مستقیم خروجی پادو (zip قالب/افزونه) بدون: اعتبارسنجی پسوند/محتوا
  (ممنوعیت PHP/eval/base64/فراخوانی شبکه)، پیش‌نمایش در محیط ایزوله، و تأیید
  نهایی ادمین
- هرگونه عملیات روی فروشگاه بدون ثبت در Audit Log
- تماس مستقیم کد/افزونه/وردفلو با API مدل‌ها بیرون از دروازهٔ یکپارچه ویرا (همهٔ درخواست‌ها باید از ویرا عبور کنند تا بودجه، محدودیت نرخ، لاگ، و فیلتر محتوا یک‌جا اعمال شود).
- اجازهٔ انتخاب آزادانه مدل/سرویس پادو به ادمین فروشگاه (مدل توسط ما بر اساس معیارهای عملکرد انتخاب و اجباری می‌شود).


==================================================
36. Single Source of Truth
==================================================

Product:
WooCommerce

Price:
WooCommerce

Stock:
WooCommerce

Order:
WooCommerce

Customer:
WooCommerce + CRM DB

SEO Performance:
Search Console

Website Content:
WordPress

Knowledge:
Knowledge Base

Campaign:
Marketing DB

Approval:
Approval DB

Event:
Event Store

Instagram Performance:
Meta API


==================================================
37. اصل تصمیم‌گیری Agent
==================================================

الگوی استاندارد:

Observe
   |
Retrieve
   |
Reason
   |
Validate
   |
Propose
   |
Approve if Required
   |
Execute
   |
Verify
   |
Log
   |
Learn


الگوی ممنوع:

Prompt
   |
AI
   |
Execute


==================================================
38. معماری نهایی
==================================================

                    WORDPRESS
                         |
                    WOOCOMMERCE
                         |
                  API + WEBHOOKS
                         |
                         v
                  n8n ORCHESTRATOR
                         |
        +----------------+----------------+
        |                |                |
        v                v                v
    SEO Agent      Marketing Agent     CRM Agent
        |                |                |
        +----------------+----------------+
                         |
                         v
                   Support Agent
                         |
                         v
                  Instagram Agent
                         |
                         v
                  Customer 360
                         |
                         v
               Knowledge Base / RAG
                         |
                         v
                     Analytics
                         |
                         v
                 Experiment Engine


==================================================
39. تصمیم نهایی درباره عملیات حساس
==================================================

تغییر قیمت:
مجاز + تأیید مدیر

Refund:
مجاز + تأیید مدیر

Instagram Publishing:
مجاز + تأیید مدیر


هیچ‌کدام بدون Approval اجرا نمی‌شوند.


==================================================
40. نتیجه نهایی
==================================================

این پروژه یک Chatbot ساده نیست.

این پروژه یک:

BUSINESS AUTOMATION PLATFORM

برای فروشگاه WordPress/WooCommerce است.

اجزای اصلی:

WooCommerce
= حقیقت تجاری

WordPress
= محتوای سایت

Search Console
= حقیقت عملکرد SEO

PostgreSQL
= داده عملیاتی و تاریخچه

Knowledge Base
= دانش غیرساختاریافته

n8n
= موتور Orchestration

AI Agents
= تحلیل، تصمیم و اجرای کنترل‌شده

Approval Center
= کنترل انسانی

Analytics
= اندازه‌گیری

Experiment Engine
= یادگیری


پنج Agent اصلی:

SEO
Marketing
CRM
Support
Instagram


چرخه اصلی سیستم:

داده
↓
تحلیل
↓
تصمیم
↓
تأیید
↓
اجرا
↓
اندازه‌گیری
↓
یادگیری


==================================================
41. منابع کلیدی
==================================================

Google Search Central
https://developers.google.com/search/

Google Helpful Content
https://developers.google.com/search/docs/fundamentals/creating-helpful-content

Google Product Structured Data
https://developers.google.com/search/docs/appearance/structured-data/product

Google Search Console API
https://developers.google.com/webmaster-tools/v1/api_reference_index

WooCommerce REST API
https://developer.woocommerce.com/docs/apis/rest-api/v3/

WooCommerce Webhooks
https://developer.woocommerce.com/docs/apis/rest-api/v3/webhooks/

n8n Documentation
https://docs.n8n.io/

n8n Human-in-the-loop
https://docs.n8n.io/advanced-ai/human-in-the-loop-tools/

Meta Instagram API
https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api

OpenAI — A Practical Guide to Building Agents
https://cdn.openai.com/business-guides-and-resources/a-practical-guide-to-building-agents.pdf




==================================================
42. تطبیق با تصمیم‌های قطعی پروژه — ۱۴۰۶/۰۵/۳۱
==================================================

این بند خلاصه‌ای از اصول و تصمیم‌های قطعی را که در طی جلسات ۱۴۰۵/۰۵/۲۶ الی
۱۴۰۶/۰۵/۳۱ گرفته شده، در یک جا کنار سند اتوماسیون قرار می‌دهد تا در زمان پیاده‌سازی
هر وردفلو/هر عامل، سازگاری با آن‌ها چک شود. این بند مکمل بند «اصول مهم» در §۲ است.

42.1 لایه‌ها (قاعده — به‌روز شده ۱۴۰۵/۰۶/۰۶)
- n8n و ویرا در صورت استفاده فقط اتوماسیون قطعی/داخلی IGBZ هستند؛ runtime یا gateway پادو
  و نگه‌دارندهٔ credential فروشگاه نیستند.
- **DeepInfra provider هدف و مدل هر Playbook منتخب تیم IGBZ است،** ولی ادمین فروشگاه
  حساب/credential/هزینهٔ مستقل را بیرون از IGBZ در اختیار connector خودش می‌گذارد.
- IGBZ Playbookهای محدود را می‌نویسد و نسخه‌گذاری می‌کند؛ connector فقط usage/cost report
  بدون secret و خروجی schemaدار را تحویل می‌دهد.
- Zernio تنها provider اجتماعی با کلید مرکزی در secret store و profile جدا برای هر فروشگاه است.
- هر عملیاتی که به پول/داده حساس مشتری می‌رسد، در بک‌اند ما (n8n/WordPress/igbz-suite)
  و با هویت مدیر از طریق Approval Center اجرا می‌شود، نه مستقیم از سوی پادو.

42.2 منابع پادو (طراحی قالب — مرحله ۱)
- اسکیل `nextlevelbuilder/ui-ux-pro-max-skill` (کامیت `bc826e2`) به‌عنوان اسکیل مرجع
  نصب شده است (`.vira/skills/`) و به‌عنوان الگوی ساختار `SKILL.md` و قواعد UX
  (کنتراست 4.5:1، alt، ناوبری کیبورد، لمس حداقل 44×44px، WebP/lazy، CLS<0.1)
  استفاده می‌شود.
- وب‌سایت `21st.dev` به‌عنوان **مرجع بصری** (نه کد/نه اسکیل) برای الگوهای عمق/سه‌بعدی
  در CSS استفاده می‌شود.
- MCP/Abilities داخلی ووکامرس **استفاده نمی‌شود** تا رفع مشکل دور زدن
  `CoreSurfaceGuard`.

42.3 قالب پیش‌فرض هسته (بدون صفحه‌ساز ثالث)
- هر فروشگاه تازه (مستأجر) در بوت‌استرپ v6 با **قالب پیش‌فرض هسته وردپرس** بالا می‌آید؛
  هیچ صفحه‌ساز ثالثی (از جمله Elementor) و هیچ قالب باندل‌شده‌ای (از جمله Hello Elementor)
  پیش‌فرض نصب نمی‌شود (تصمیم ۱۴۰۶/۰۵/۳۱، حجم ~۱۰۸ مگابایت).
- خروجی مرحله ۱ پادو (طراحی قالب) یک **قالب فرزند FSE** روی قالب هسته است، شامل
  `theme.json` + تمپلیت‌های HTML بلوکی + CSS + محتوا/تصاویر. ویرایش درگ‌اند‌دراپ ادمین از
  طریق ویرایشگر بلوک خود هسته (Site Editor) انجام می‌شود، نه از طریق صفحه‌ساز ثالث.

42.4 قواعد بک‌اند (غیرقابل دور زدن)
- همهٔ بررسی‌های امنیتی/محدودیت نرخ/اعتبارسنجی در سرویس بک‌اند.
- کدهای یک‌بارمصرف و هر رمز تصادفی از `Crypto`/`random_int` (هرگز `rand/mt_rand`).
- کرون‌ها دسته‌ای و ادامه‌پذیر، با Action Scheduler.
- فهرست سفید مسیرهای REST (نه فهرست سیاه).

42.5 قالب/زبان/ارز/فونت
- راست‌چین RTL، زبان فارسی fa_IR، تومان به‌عنوان نماد، بدون اعشار پول، کیلوگرم،
  ایران به‌عنوان کشور پیش‌فرض، COD فعال به‌صورت پیش‌فرض.
- فونت محلی (وزیرمتن/استعداد/شبنم/ساحل/مورا) — بدون Google Fonts/CDN خارجی.
- بستهٔ رسمی ترجمه `fa_IR` در تولید استفاده می‌شود (نه mu-plugin ترجمه موقت).

42.6 نصب خروجی پادو
- خروجی پادو (برای طراحی قالب، محتوا، متا، کمپین، …) **از طریق دروازهٔ یکپارچه
  ما (ویرا)** گرفته می‌شود و قبل از هر چیز در بک‌اند ما اعتبارسنجی می‌گردد:
  * فهرست سفید پسوندها (php در قالب فرزند فقط با اسکلت استاندارد و بدون
    `eval`/`base64_decode`/فایل‌های خارجی/فراخوانی شبکه).
  * حداکثر حجم فایل.
  * وجود تمپلیت‌های اجباری ووکامرس.
  * وجود RTL.
- نصب اولیه در preview؛ پس از تأیید ادمین live.
- بازگشت با یک کلیک (نسخه‌بندی قالب در options/customization جدول).

42.7 مغایرت‌های شناخته‌شدهٔ نسخه ۱.۰ که در نسخه‌های بعدی اصلاح شد
- اتکای مستقیم به Meta Instagram API → تغییر به مسیر Manus + ChatPlace (v1.1).
- عدم تفکیک n8n / ویرا / پادو → سه لایهٔ مجزا در §۳ (v1.1).
- عدم اشاره به المنتور و Hello Elementor به‌عنوان پیش‌نیاز و بخشی از بوت‌استرپ →
  اضافه‌شده به PHASE 1 و §۴۲ (v1.1).
- عدم ذکر قوانین ایران، رمزنگاری امن، بررسی‌های بک‌اند، CoreSurfaceGuard و
  Action Scheduler → به اصول و ممنوعات (§۲ و §۳۵) اضافه شد (v1.1).
- عدم وجود عملیات حساس حذف انبوه/تغییر URL/کمپین بزرگ/تغییر سیاست → به §۲۰ اضافه شد (v1.1).
- عدم ذکر منابع الهام طراحی قالب پادو → در §۲ و همین §۴۲ اضافه شد (v1.1).
- عدم اشاره به تعطیلی MCP داخلی ووکامرس → در §۲ و §۳۵ ذکر شد (v1.1).
- **v1.2:** فرض غلط «ادمین پادو/مدل را انتخاب می‌کند» اصلاح شد. مدل/سرویس
  پادو توسط ما بر اساس بهترین عملکرد در اتوماسیون انتخاب می‌شود؛ ادمین فقط
  کلید API را ثبت می‌کند. پادو یک دروازهٔ یکپارچه از سمت ماست (با API Gateway در ویرا).
- **v1.3 (۱۴۰۶/۰۵/۳۱):** المنتور و Hello Elementor از پیش‌نیازها و بوت‌استرپ حذف شدند
  (حجم ~۱۰۸ مگابایت در ریپو). بوت‌استرپ v6 فقط با قالب پیش‌فرض هسته وردپرس اجرا می‌شود
  و خروجی طراحی قالب پادو یک قالب فرزند FSE است، نه تمپلیت Elementor JSON.

هر بخشی که در آینده به این سند اضافه می‌شود، باید با همین بند ۴۲ سازگار باشد.


==================================================
43. تصمیم معماری مستأجر در فاز ۰۲ — ۱۴۰۵/۰۶/۰۵
==================================================

کارفرما معماری **وردپرس چندسایتی + هستهٔ مدیریتی مشترک** را انتخاب کرد. هر فروشگاه یک
زیرسایت و منبع حقیقت مستقل وردپرس/ووکامرس دارد؛ کد و control plane هاب، provision، پلن،
صورتحساب و مدیریت مرکزی مشترک‌اند. مرجع تصمیم:

`ADR/ADR-0001-MULTISITE-TENANCY.md`

در نتیجه تمام eventها، webhookها، jobها، cacheها، auditها، Approvalها و فراخوانی‌های مدل
باید هویت شبکه و سایت را از زمینهٔ احرازشده حمل کنند. n8n یا عامل نباید با شناسهٔ سایت
ارسالی خام، داده یا ابزار فروشگاه را انتخاب کند. دادهٔ سایت فقط با قرارداد حداقل‌اختیار به
control plane می‌رود و خروجی فقط از مسیر schema، مجوز، تأیید و اجرای همان سایت برمی‌گردد.

مرز دقیق پادو، n8n، سرویس مدل و ویرا در ADR بعدی بسته شد.


==================================================
44. مرز تاریخی پادو، n8n، مدل و ویرا — ۱۴۰۵/۰۶/۰۵
==================================================

این تصمیم با ADR-0004 جانشین شد؛ اصل استقلال حساب/هزینه حفظ شده است. مرجع تاریخی
`ADR/ADR-0002-EXTERNAL-PADO-PER-STORE.md` است:

- پادو حساب/سرویس ابری بیرونی و مستقل هر فروشگاه است؛ پردازش عامل و مدل روی زیرساخت
  IGBZ اجرا نمی‌شود؛
- n8n فقط گردش‌کارهای قطعی، نسخه‌دار و پلتفرم‌مالک را در زیرساخت مشترک اجرا می‌کند؛
- ویرا ابزار داخلی مدیران IGBZ است و در نسبت با پادو فقط مدیریت اسکیل و گزارش دوره‌ای
  دارد؛ مسیر درخواست روزمرهٔ مدل فروشگاه نیست؛
- WordPress/WooCommerce منبع حقیقت و مجری نهایی همان زیرسایت‌اند؛ پادو فقط از API ابزار
  کم‌اختیار و site-scoped استفاده می‌کند و هر خروجی‌اش پیش از اجرا validate و authorize
  می‌شود؛
- credential حساب پادو per-site و مستقل است؛ n8n یا ویرا آن را به‌عنوان کلید مشترک مدل
  مصرف نمی‌کنند.

در نتیجه ادعاهای تاریخی §۲، §۳، §۴.۳ تا §۴.۵ و §۴۲ دربارهٔ «دروازهٔ ویرا برای تمام ترافیک
مدل پادو» یا «میزبانی پادو توسط پلتفرم» منسوخ‌اند و در فاز ۰۳ یک‌دست می‌شوند. این تصمیم
به‌تنهایی provider، خروج داده یا پروتکل اتصال را تعیین نمی‌کند.


==================================================
45. خروجی سه‌گانهٔ طراحی قالب — ۱۴۰۵/۰۶/۰۵
==================================================

کارفرما برای نوع خروجی به پاسخ قطعی قبلی در `DESIGN-PADO.md §۱۸` ارجاع داد. مطابق
`ADR/ADR-0003-THREE-THEME-OUTPUTS.md` ادمین میان قالب فرزند بلوکی، قالب کلاسیک PHP و
تمپلیت صفحه‌ساز الحاقی انتخاب می‌کند.

مسیر بلوکی فاقد کد اجرایی تولیدی و پیش‌فرض است. مسیر PHP فقط خط لولهٔ کد با sandbox،
تحلیل، بازبینی انسانی مدیر شبکه، امضا، staging و آزمون مرورگر دارد و نصب مستقیم مدل ممنوع
می‌ماند. صفحه‌ساز نیز فقط در صورت نصب قبلی و حضور نسخه در ماتریس سازگاری پذیرفته می‌شود؛
Elementor و هر صفحه‌ساز دیگر همچنان از نصب پیش‌فرض خارج‌اند.


==================================================
46. معماری نهایی اتوماسیون پادو و اینستاگرام — ۱۴۰۵/۰۶/۰۶
==================================================

مرجع قطعی `ADR/ADR-0004-PADO-ZERNIO-SOCIAL-ARCHITECTURE.md` و طراحی اجرایی
`DESIGN-INSTAGRAM-PADO-ZERNIO.md` است:

- پادو چهار جریان Playbookمحور «گردآوری»، «تحلیل»، «راهبرد» و «تولید» دارد؛ خروجی هر
  مرحله schemaدار، نسخه‌دار، قابل‌ردیابی و محدود به بودجه و timeout است؛
- هر فروشگاه inference را با حساب مستقل DeepInfra تأمین می‌کند؛ IGBZ هزینه یا fallback
  پولی مشترک نمی‌پردازد و credential فروشگاه را ذخیره نمی‌کند؛
- Zernio تنها اتصال Instagram است: OAuth، انتشار، زمان‌بندی، Inbox، comment-to-DM،
  analytics و صدای کاتالوگ‌شده از profile جداگانهٔ همان فروشگاه؛
- بک‌اند IGBZ profile mapping، RBAC، صف، idempotency، rate limit، approval، webhook و
  audit را اعمال می‌کند و کلید مرکزی Zernio را به پادو یا مدیر فروشگاه نمی‌دهد؛
- WooCommerce منبع حقیقت تجارت است و حلقهٔ یادگیری باید فروش، Insight و نتیجهٔ campaign
  را با window و provenance یکسان مقایسه کند؛
- انتشار، تخفیف، پیام انبوه و اقدام مالی عملیات حساس‌اند و بدون policy/approval در بک‌اند
  اجرا نمی‌شوند؛ n8n در صورت استفاده فقط گردش‌کار قطعی داخلی است؛
- scraper، cookie، session مرورگر Instagram و API مستقیم Meta خارج از معماری‌اند؛
- Business Discovery و Hashtag Search، TTS فارسی، sandbox امن PHP و سایر موارد بدون
  endpoint/آزمون زنده، گیت یا backlog هستند و نباید «قابلیت موجود» معرفی شوند.

این بخش بر همهٔ اشاره‌های قبلی به Manus، ChatPlace، ManyChat، gateway ویرا یا انتخاب آزاد
provider مقدم است. ثبت این قرارداد به‌معنای پیاده‌سازی کد نیست.
