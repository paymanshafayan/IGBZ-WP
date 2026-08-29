# ماتریس ردیابی نیازمندی‌های IGBZ-WP

> نسخه: ۰.۲ — خروجی فاز ۰۱ با تطبیق تصمیم معماری
>
> تاریخ: ۱۴۰۵/۰۶/۰۶ — ۲۸ اوت ۲۰۲۶
>
> وضعیت: snapshot ماشینی و ماتریس اولیهٔ دوطرفه؛ تصمیم پادو/اینستاگرام در ADR-0004 ثبت
> شده است. اتصال سطح مسیر/جدول/تنظیم به آزمون و کنترل واگرایی در فاز ۰۴ اجباری می‌شود.
>
> معماری هدف: پادوی Playbookمحور، DeepInfra مستقل هر فروشگاه و Zernio مرکزی با profile
> جدا به‌عنوان تنها provider اجتماعی. پیشنهاد کانال Instagram مبتنی بر session در Agent Reach رد شده و کد Manus/ChatPlace/ManyChat بدهی migration است.

---

## ۱. قاعدهٔ استفاده

این سند منبع ردیابی میان خواستهٔ کارفرما، اسناد طراحی، کد، آزمون و مدرک زنده است. وضعیت
`پیاده‌شده` فقط وجود کد را نشان می‌دهد؛ تا وقتی آزمون متناسب، اجرای زنده و سند عملیاتی
وجود نداشته باشد، نیاز `تأییدشده` نیست.

وضعیت‌ها:

- **پیشنهادی:** ایده‌ای در اسناد منبع است و هنوز تصمیم قطعی کارفرما نیست؛
- **پذیرفته‌شده:** تصمیم قطعی است ولی ممکن است کد نداشته باشد؛
- **پیاده‌شده:** کد دارد، اما شواهد پذیرش کامل نیست؛
- **تأییدشده:** کد، آزمون، اجرای زنده و سند هم‌راستا هستند؛
- **شکاف:** نیاز پذیرفته‌شده ناقص یا کد با سند ناسازگار است؛
- **معلق/خارج دامنه:** عمداً اجرا نمی‌شود و شرط بازگشت دارد.

هیچ عبارت «کامل» در سند دیگری نباید وضعیت این ماتریس را بدون شواهد تغییر دهد.

---

## ۲. مبنای امنیتی اجباری

طبق دستور کارفرما، فایل زیر مبنای امنیتی اجرای همهٔ فازهاست:

```text
امنیت و مراقبت/README.md
```

قواعد استخراج‌شده از آن:

۱. کارفرما باید بتواند هر تصمیم امنیتی را با منبعش مقایسه کند؛
۲. منبع فقط وقتی در پوشهٔ امنیت ثبت می‌شود که واقعاً در تصمیم/کد استفاده شده باشد؛
۳. هر خلاصهٔ منبع باید محل استفاده در پروژه را مشخص کند؛
۴. اختلاف توصیهٔ منبع با طراحی یا کد، نقص قابل گزارش است؛
۵. خلاصهٔ قدیمی منبع جای تحقیق تازه و سند رسمی روز را نمی‌گیرد؛
۶. کنترل ویژگی شیء، مصرف منابع، جریان حساس، نرخ، زمان‌بندی و تأیید انسانی باید در ردیابی
   نیازها و آزمون‌ها حضور صریح داشته باشند.

منابع محلی خوانده‌شده در این فاز:

```text
امنیت و مراقبت/منابع/OWASP/API3-2023-Broken-Object-Property-Level-Authorization.md
امنیت و مراقبت/منابع/OWASP/API4-2023-Unrestricted-Resource-Consumption.md
امنیت و مراقبت/منابع/OWASP/API6-2023-Sensitive-Business-Flows.md
امنیت و مراقبت/منابع/IETF/RateLimit-Headers-and-429.md
امنیت و مراقبت/منابع/WordPress/WP-Cron-System-Task-Scheduler.md
امنیت و مراقبت/منابع/AI-Commerce/e-commerce-agents-MIT.md
```

---

## ۳. خط پایهٔ ماشینی کد

artefact کامل و قابل بازتولید این بخش در فایل زیر است:

```text
PHASE-01-INVENTORY.json
```

بازسازی و کنترل واگرایی:

```bash
python3 _devenv/phase01_inventory.py
python3 _devenv/phase01_inventory.py --check
```

استخراج‌گر فقط `igbz-suite/`، اسناد ریشه و `ِDoc/` را می‌خواند و عمداً هرگز وارد `vira/`
نمی‌شود. خروجی شامل فهرست ۲۱۵ فایل منبع، ۷۲ جدول، فایل‌به‌فایل ثبت REST، کلیدهای تنظیم و
راز، نقش/اختیار، shortcode، listenerهای cron، نام تست‌ها و candidateهای provider است.
فهرست candidate به معنی فعال‌بودن provider نیست؛ تصمیم آن در فاز ۰۲ گرفته می‌شود.

| قلم | مقدار تأییدشده |
|---|---:|
| فایل PHP زیر `src` | ۲۱۵ |
| ماژول‌ها | ۶ |
| فراخوانی `register_rest_route` | ۱۴۳ در ۱۵ فایل |
| جدول‌های schema | ۷۲ |
| نسخهٔ دیتابیس | ۱۹ |
| کلید تنظیم در صفحهٔ Settings | ۳۰۴ |
| فیلد password | ۳۶ |
| اعضای رجیستری secret | ۱۷ |
| فیلد password مشترک با رجیستری | ۱۴ |
| فیلد password خارج رجیستری | ۲۲ |
| capability مدیریتی اختصاصی | ۱۱ |
| نقش اختصاصی | ۳ |
| shortcode | ۱۰ |
| رویداد تجمیعی cron | ۳ |
| فایل تست PHP | ۲۸ |
| کلاس اصلی تست | ۲۴ |
| نتیجهٔ آخرین خط پایهٔ ثبت‌شده | ۱۲۵۱/۱۲۵۱ اظهارنظر؛ ۲۴ کیس؛ ۲۴۵ فایل بدون خطای نحو |
| صفحهٔ مدیریت آزموده‌شده | ۲۹ |
| حالت مرورگر آزموده‌شده | ۳۵؛ یک شکست ماشینی checkout و یک نقص چشمی glyph |

تقسیم ۲۱۵ فایل منبع:

```text
MultiTenant   ۸۴
Instagram     ۵۶
RestApi       ۲۲
Fx            ۱۷
Hub           ۱۱
Pado           ۶
Support       ۱۹
```

تقسیم ۱۴۳ فراخوانی ثبت مسیر:

```text
RestApi controllers   ۱۲۴
Hub controller         ۱۴
Instagram webhooks      ۵
```

این شمارش تعداد فراخوانی ثبت مسیر است، نه تضمین تعداد endpoint یکتای مستند یا امن.

---

## ۴. موجودی سطح کد

### ۴.۱ نقش‌ها و اختیارها

نقش‌ها:

```text
igbz_tenant_owner
igbz_tenant_staff
igbz_instructor
```

capabilityهای مدیریتی:

```text
igbz_manage_suite
igbz_manage_tenants
igbz_manage_own_tenant
igbz_manage_wallet
igbz_manage_plans
igbz_manage_bnpl
igbz_manage_lms
igbz_manage_affiliate
igbz_manage_instagram
igbz_manage_api
igbz_manage_pado
```

وجود این فهرست، مجوز شیء یا جداسازی tenant را ثابت نمی‌کند؛ فازهای ۰۶ تا ۰۹ مالکیت و
حداقل اختیار را مسیر‌به‌مسیر می‌آزمایند.

### ۴.۲ زمان‌بندی

سه هوک مرکزی حاضرند:

```text
igbz_cron_five_minutes
igbz_cron_hourly
igbz_cron_daily
```

ماژول‌های MultiTenant، Instagram، Hub، Fx، RestApi و housekeeping به این هوک‌ها متصل‌اند.
Action Scheduler در افزونه استفاده نشده است. بنابراین وجود cron به معنی صف بادوام، retry،
fairness یا مشاهده‌پذیری نیست.

### ۴.۳ رابط عمومی

ده shortcode ثبت شده است:

```text
igbz_store_directory
igbz_hub_grid
igbz_hub_stats
igbz_hub_blocks
igbz_courses
igbz_course
igbz_plans
igbz_bnpl_calculator
igbz_wallet_balance
igbz_otp_login
```

### ۴.۴ مرزهای بیرونی حاضر در کد

کد برای این خانواده‌ها آداپتور یا client دارد، ولی بیشتر آن‌ها مدرک sandbox واقعی ندارند:

- درگاه‌های ریالی مستقیم/واسط؛
- BNPL؛
- NOWPayments، Stripe و PayPal در تنظیمات/طراحی؛
- Tapin و Postex؛
- Digikala، Divar و Basalam؛
- کد legacy Manus، ChatPlace، ManyChat و AI Studio؛ پیشنهاد تاریخی کانال Instagram مبتنی بر session در Agent Reach رد شده است؛
- ترجمه، گفتار به متن و SEO/تبلیغات؛
- نرخ و payout ارزی؛
- ارائه‌دهندهٔ دامنه؛
- پیامک و شاهکار؛
- درگاه بیرونی پادو.

هدف پذیرفته‌شده برای اینستاگرام فقط Zernio و برای inference نسخهٔ اول فقط DeepInfra است؛
آداپتورهای هدف هنوز در کد وجود ندارند. فاز `PV` برای هر provider فعال جداست و وجود کلاس
به معنی تأیید provider نیست.

---

## ۵. ثبت اسناد و جایگاه آن‌ها

### ۵.۱ طراحی‌های فعال

۱۶ سند ریشه با الگوی `DESIGN-*.md` در snapshot فعلی حاضرند:

| سند | حوزه | جایگاه در ردیابی |
|---|---|---|
| `DESIGN-DOMAIN.md` | دامنه | نیازهای DOM |
| `DESIGN-FX.md` | واسط ارزی | نیازهای COM/EXT |
| `DESIGN-GAPS-FIX.md` | شکاف‌های امنیتی تاریخی | SEC؛ وضعیت جاری باید از ممیزی تازه بیاید |
| `DESIGN-LEGAL-AUTH.md` | OTP، قرارداد، شاهکار و خروج حساس | SEC/COM |
| `DESIGN-PADO.md` | پادو، قالب، مجوز و حافظه | PAD |
| `DESIGN-PHASE6-PAYMENTS.md` | درگاه، BNPL و پرداخت مرکزی | COM |
| `DESIGN-PHASE7-COURIER.md` | لجستیک، پیک و COD | EXT/API |
| `DESIGN-PHASE9-MARKETPLACES.md` | مارکت‌پلیس‌ها | EXT |
| `DESIGN-PHASE10-SEO.md` | SEO و تبلیغات | EXT |
| `DESIGN-PHASE12-INTERNATIONAL.md` | ترجمه و پرداخت بین‌المللی | EXT/UX |
| `DESIGN-PHASES-6-14.md` | نقشهٔ تاریخی فازها | پوشش کلان؛ ادعای تکمیل مستقل معتبر نیست |
| `DESIGN-VIP.md` | کانال VIP | IG |
| `DESIGN-VIP-EXPIRY.md` | انقضا و ذخیرهٔ VIP | IG |
| `DESIGN-AUTOMATION.md` | n8n و عامل‌ها | GOV/PAD؛ مرز نقش‌ها نیازمند ADR |
| `DESIGN-DEPLOY-VIRA.md` | قرارداد استقرار ویرا | وابستگی بیرونی؛ پوشهٔ `vira/` خارج دامنه |
| `DESIGN-APPS-ROADMAP.md` | اپ‌ها | معلق؛ فقط قرارداد API در این مخزن |

### ۵.۲ اسناد منبع و ایده‌ها

فایل‌های پوشهٔ `ِDoc/` از پروژهٔ قدیمی nopCommerce و پاسخ‌های تحقیقاتی اولیه آمده‌اند.
آن‌ها **به‌تنهایی تصمیم قطعی یا شاهد پیاده‌سازی WordPress نیستند**. هر قابلیت فقط وقتی نیاز
پذیرفته‌شده محسوب می‌شود که در سند طراحی جاری یا تصمیم صریح کارفرما ثبت شده باشد.

حوزه‌های استخراج‌شده از این اسناد:

- معماری چندمستأجری، هاب، موبایل، اینستاگرام و AI Studio؛
- پرداخت ریالی/ارزی، BNPL و کیف پول؛
- لجستیک، پیک، COD و برچسب؛
- ترب، دیجی‌کالا، دیوار، باسلام و نگاشت دسته؛
- SEO، فید تبلیغاتی و رپورتاژ؛
- ترجمه و چندزبانگی؛
- LMS، VOD، لینک امضاشده و امنیت اپ؛
- Affiliate، Gamification، حسابداری و مالیات؛
- رشد قانونی اینستاگرام و Giveaway.

ادعاهای فنی و تجاری آن‌ها، از جمله سازگاری provider با ایران، درصد امنیت ویدیو، وجود API
عمومی و نبود ریسک تحریم، بدون تحقیق رسمی تازه قابل استفاده در اجرا نیستند.

### ۵.۳ مخزن مرجع قدیمی

`REVIEW-IGBZ-NopCommerce.md` منبع انتقال تجربه است، نه وضعیت کد WordPress. مشکلات و الگوهای
آن باید فقط پس از تطبیق با کد فعلی به نیاز تبدیل شوند؛ مانند idempotency مالی، رمزنگاری
secret، منوی قابل دسترس، تست واقعی و پرهیز از endpoint نمادین.

---

## ۶. ماتریس اولیهٔ نیازها

### ۶.۱ حاکمیت و اسناد

| شناسه | نیاز | سند/منبع | کد/شاهد فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| GOV-001 | ماتریس دوطرفهٔ نیاز، کد و آزمون | برنامهٔ جامع §۳ | همین سند | پیاده‌شدهٔ اولیه؛ خودکارسازی فاز ۰۴ باقی است |
| GOV-002 | وضعیت جاری یکتا و تاریخچهٔ جدا | هندآف و برنامه §۳.۳ | چند بخش تاریخی متعارض | شکاف؛ فاز ۰۳ |
| GOV-003 | هر تصمیم امنیتی دارای منبع و محل استفاده | `امنیت و مراقبت/README.md` | پوشهٔ منابع موجود است | پیاده‌شده؛ چند خلاصه باید با وضعیت فعلی به‌روز شود |
| GOV-004 | تحقیق تازه پیش از هر فاز فنی | قانون ۱۰ هندآف | روند کاری | پذیرفته‌شده؛ مدرک منبع در هر فاز ثبت شود |
| GOV-005 | تغییر کد فقط پس از برنامه، تأیید و «شروع کن» | قانون ۱ | روند کاری | تأییدشده برای این برنامه |
| GOV-006 | گزارش کوتاه پس از هر فاز | تصمیم ۱۴۰۵/۰۶/۰۵ کارفرما | هندآف/برنامه | پذیرفته‌شده؛ ۲ تا ۳ خط، بدون گزارش میانی |

### ۶.۲ امنیت

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| SEC-001 | همهٔ رازها رمز، ماسک و قابل rotation | API-KEYS §۵؛ OWASP Secrets | `Settings`, `Crypto`, `SettingsTest` | شکاف بحرانی: ۲۲ password خارج رجیستری |
| SEC-002 | مجوز مالکیت شیء و ویژگی | OWASP API3؛ طراحی حقوقی | `BaseController`, repositoryها | شکاف قطعی در `verify_dns(id)`؛ ممیزی ۱۴۳ ثبت مسیر باقی است |
| SEC-003 | حداقل اختیار نقش و مسیرهای هسته | DESIGN-GAPS-FIX؛ LEGAL §۷.۷ | `Capabilities`, `CoreSurfaceGuard`, `SecurityGapsTest` | پیاده‌شدهٔ جزئی؛ آزمون زندهٔ همهٔ درها/نقش‌ها باقی است |
| SEC-004 | محدودیت مصرف و جریان حساس | OWASP API4/API6 | OTP phone+IP؛ rate limitهای پراکنده | پیاده‌شدهٔ جزئی؛ شمارندهٔ اتمیک، quota هزینه و جریان‌های دیگر باقی‌اند |
| SEC-005 | پاسخ نرخ استاندارد | IETF/RFC 6585/9110 | `retry_after` در بخشی از سرویس‌ها | شکاف؛ نگاشت یکنواخت ۴۲۹ و `Retry-After` تأیید نشده |
| SEC-006 | SSRF و دانلود محدود | OWASP SSRF؛ PADO §۱۹ | `Support/Http`, `UrlGuard`, `PadoGateway` | ✅ فاز ۱۰: دروازهٔ متمرکز `UrlGuard` (اسکیم/میزبان/بازه‌های خصوصی و متادیتا)، خاموشی redirect، سقف اندازهٔ پاسخ، سیاست ارسال حامل فقط به میزبان پیکربندی‌شده؛ تست `UrlGuardTest` |
| SEC-007 | فایل و artefact غیرقابل‌اجرا | PADO §۱۹ | `ThemeValidator`, `ThemeService` | شکاف: PHP پذیرفته می‌شود؛ blacklist sandbox نیست |
| SEC-008 | JWT، refresh، device و replay | LEGAL §۷.۶؛ API | `Jwt`, `RefreshTokenService`, device routes | پیاده‌شده؛ آزمون نقش، ابطال و race کامل نیست |
| SEC-009 | خروج حساس با حضور انسان/بیومتریک | OWASP API6؛ LEGAL §۷.۶ | `Support/BiometricGate`, `DeviceRepository` | ✅ فاز ۱۲: قرارداد سمت سرور (پنجرهٔ زمانی/نانس یک‌بارمصرف/زمان ثابت) + کلید رمزگذاری‌شدهٔ دستگاه (دیتابیس v22) و آزمون؛ آزمون زندهٔ اپ مدیریت پس از ساخت اپ باقی است |
| SEC-010 | audit بدون secret/PII و retention | طراحی امنیت/ویرا | `Logger`, `TenantOffboarding` | ✅ فاز ۱۳: ماسک راز+دادهٔ شخصی در لحظهٔ ورود (تودرتو هم)، ماندگاری خودکار رویداد/توکن، جاروی خروج مستأجر با ثبت امنیتی؛ `OffboardingTest` |

### ۶.۳ چندمستأجری و داده

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| TEN-001 | resolver مستأجر از هویت معتبر | ADR-0001؛ Hub | `TenantScope::page_tenant_id`, `BaseController::scoped_tenant_id` | ✅ فاز ۱۴: مسیر واحد تعیین مستأجر؛ `tenant_id` ارسالی کلاینت فقط برای `MANAGE_TENANTS` اعتبار دارد، وگرنه مستأجر از هویت حل‌شده می‌آید (بستن BOLA کیف‌پول/افیلیت/BNPL و مشتق‌کردن مستأجر رشتهٔ گفت‌وگو از پست)؛ آزمون منفی `TenantResolutionTest`؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۴` |
| TEN-002 | مالکیت در repository و query | ADR-0001؛ OWASP Multi-Tenant | repositoryهای متعدد | ✅ فاز ۰۷ تا ۰۹: خواندن‌های «شیء با شناسه» در همهٔ ماژول‌های تجاری/جانبی/اینستاگرام/اف‌ایکس/پادو/موبایل‌ای‌پی‌آی شرط `tenant_id` گرفتند؛ استثناهای تعمدی (کرون/قلاب ناهمگام/کنترل‌پلین) در `DESIGN-LEGAL-AUTH.md §۷.۷.۷–§۷.۷.۹`؛ آزمون منفی `TenantScopeTest` |
| TEN-003 | provision کامل و idempotent | ADR-0001؛ Hub | `SignupService::signup` | ✅ فاز ۱۶: جریان کامل کاربر + مستأجر + عضویت مالک + نقش + زیردامنه + اشتراک + پرداخت؛ اجرای مجدد همان ثبت‌نام فروشگاه موجود را برمی‌گرداند بدون ردیف تکراری؛ خرابی هر گام پس از ساخت مستأجر، مستأجر و عضویت را rollback می‌کند؛ `SignupTest`؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۶` |
| TEN-004 | cache/file/job/rate tenant-scoped | ADR-0001؛ OWASP Multi-Tenant؛ §۵.۵ | `TenantScope::cache_key`، سقف نرخ مستأجر در `Authenticator` | ✅ فاز ۱۵: مسیر واحد پیشوند مستأجر برای کلیدهای کش/ترنزینت روی شش نقطهٔ دادهٔ مستأجر + کلید جریان منی‌چت بر اساس حساب؛ سقف تجمیعی دقیقه‌ای هر مستأجر (`api.tenant_rate_limit_per_minute`) جدا از سقف هر توکن؛ زیرشاخهٔ مستأجر برای فایل قالب پادو؛ آزمون برخورد کلید و هم‌زمانی `TenantIsolationTest`؛ استثناهای سراسری (کاتالوگ مستأجر، توکن گوگل، دایرکتوری هاب) ممیزی و ثبت شد؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۵` |
| TEN-005 | قالب مستقل هر مستأجر | ADR-0001؛ DESIGN-PADO §۱۹ | `ThemeService::activate_live/rollback`، `TenantThemeRouter` | ✅ فاز ۱۸: حذف `switch_theme()` سراسری؛ فعال‌سازی/بازگشت وضعیت ستون `theme` مستأجر؛ رندر زمان درخواست فقط برای فروشگاه خود با فیلترهای `template`/`stylesheet`؛ بایگانی قالب زنده با شرط مستأجر؛ پیش‌نمایش با گیت عضویت/ادمین و فقط روی مستأجر خود؛ `ThemeRoutingTest`؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۸` |
| TEN-006 | مسیریابی دامنهٔ مستأجر امن | ADR-0001؛ OWASP Multi-Tenant | `TenantContext::current`, `TenantRepository::add_domain`, `DomainVerifier` | ✅ فاز ۱۷: شاخه‌های سرویس‌دهی رزولور فقط مستأجر قابل‌مسیریابی (فعال/آزمایشی نامنقضی) حل می‌کنند؛ نگاشت دامنه یک‌به‌یک و ورودی نرمال‌شده/ردشده؛ تأیید واقعی TXT/CNAME/A و الزام `verified_at` از قبل؛ `DomainRoutingTest`؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۷` |
| DAT-001 | schema و migration نسخه‌دار | Schema/Activator | DB v22، ۷۲ جدول؛ `Migrator` | ✅ فاز ۱۹: چارچوب مهاجرت با قفل تک‌اجراکننده، نقطهٔ بررسی پس از هر گام، ادامه‌دادن به‌جای اجرای مجدد، گزارش پیشرفت و نشانگر نسخهٔ پیش از ارتقا برای راه بازگشت؛ ارتقای مستقیم از نسخه‌های قدیمی در `MigratorTest` آزموده شد؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۱۹` |
| DAT-002 | query رشدپذیر محدود و ایندکس‌شده | §۵.۵؛ OWASP API4 | `Db::delete_batches`، سقف/کی‌ست در فهرست‌ها، ایندکس‌های v23 | ✅ فاز ۲۰: حذف‌های انبوه (هرس توکن/کد/دستگاه/لاگ، پاک‌سازی ادمین و جاروی خروج مستأجر) دسته‌بندی و مرتب بر `id` شدند با سقف تکرار؛ فهرست‌های بی‌کران سقف گرفتند یا به پیمایش کی‌ست تبدیل شدند (حساب‌های اینستاگرام، دستگاه‌های کاربر، پیوندهای مارکت‌پلیس، قیف‌ها، کاتالوگ‌های اف‌ایکس/وی‌آی‌پی)؛ ایندکس‌های مرکب تازه برای مسیرهای هرس و نشست (دیتابیس 23)؛ `BatchTest`؛ اعتبارسنجی `EXPLAIN` روی دادهٔ حجیم تولید وظیفهٔ ثبت‌شدهٔ عملیاتی است؛ `DESIGN-LEGAL-AUTH.md §۷.۷.۲۰` |
| DAT-003 | HPOS واقعی | WooCommerce | `WooCommerceCompat`، `HposOrderFlowTest` | ✅ فاز ۲۱: اعلام رسمی سازگاری (`custom_order_tables` + `cart_checkout_blocks`)؛ ممیزی کامل — همهٔ دسترسی‌های سفارش CRUD هستند؛ تنها دو نقطهٔ وابسته به ذخیره‌سازی (لینک ویرایش ادمین، جدول درآمد) به مسیر واحد `WooCommerceCompat` منتقل شدند؛ آزمون جریان‌های سفارش در هر دو حالت قدیمی و HPOS سبز؛ راستی‌آزمایی زندهٔ فعال‌سازی واقعی روی فروشگاه سندباکس (باگ واقعی `get_order_edit_link` ناموجود → `get_order_admin_edit_url` یافت و اصلاح شد)؛ فاز ۲۲ ران‌بوک مهاجرت/راستی‌آزمایی با واقعیت‌های تأییدشدهٔ زنده و آزمون مرجوعی دو حالته را کامل کرد؛ اجرای مهاجرت روی دادهٔ واقعی وظیفهٔ عملیاتی است (`RUNBOOK-HPOS.md`) |

### ۶.۴ صف و عملیات پس‌زمینه

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| QUE-001 | صف بادوام با claim و retry | WP-Cron source؛ الگوی لیز/پشت‌بند | جدول `jobs` + `JobQueue` (فاز ۲۳) | ✅ کامل: ادعای لیزدار با بازگشت پس از انقضا، تلاش مجدد با پشت‌بند نمایی و لرزش، بن‌بست برای سمی‌ها؛ هر سه ضربان (پنج‌دقیقه‌ای/ساعتی/روزانه) منتقل شدند (فازهای ۲۴ تا ۲۶)؛ مشاهده‌پذیری در فاز ۲۷ |
| QUE-002 | idempotency و اجرای اثر دقیقاً یک‌بار | §۵.۵؛ الگوی تحویل حداقل‌یک‌بار | `Envelope` + `JobContext` (فاز ۲۳) | ✅ قرارداد مشترک: کلید همسانی پایدار در همهٔ تلاش‌ها، شناسهٔ ردیابی و مستأجر به هندلر می‌رسد؛ کلید پس از پایان آزاد می‌شود |
| QUE-003 | fairness و backpressure مستأجر | §۵.۵؛ OWASP API4 | `JobQueue::claim` نوبتی بین گروه‌ها + `fan_out_tenants` (فاز ۲۵) | ✅ فاز ۲۵: ادعای راند-رابین بین `group_key` مستأجرها، پخش‌بینی یک کار به‌ازای هر مستأجر فعال، بچهٔ سقف‌دار با قرارداد ادامهٔ کران‌دار (۱۰ دور در ساعت)؛ `HourlyJobsTest` |
| QUE-004 | مشاهده‌پذیری، CLI و dead-letter | برنامهٔ جامع | `JobQueue::stats/dead_letters/replay` + `JobsPage` + `wp igbz jobs` (فاز ۲۷) | ✅ داشبورد پشت‌لاگ/سن/بن‌بست‌ها با بازپخش کنترل‌شده (کلید همسانی حفظ می‌شود)، دستورهای عملیاتی فقط در `WP_CLI`، و `QueueLoadTest` برای بار/رقابت/قطع بالادست/عدم گرسنگی |

### ۶.۵ تجارت، پرداخت و احراز قانونی

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| COM-001 | دفتر کل کیف پول و invariant | PHASE6؛ MODULES | Wallet services؛ Money/Phases tests | پیاده‌شده؛ race/load/reconciliation کامل نیست |
| COM-002 | تراکنش و callback امن/idempotent | PHASE6 | Gatewayها و Payments | پیاده‌شدهٔ آداپتوری؛ sandbox/reconciliation providerها باقی است |
| COM-003 | درگاه مرکزی، escrow، refund و تسویه | PHASE6 | MasterPaymentService؛ Phases2 tests | پیاده‌شدهٔ جزئی؛ provider و چرخهٔ واقعی کامل نیست |
| COM-004 | پلن و اشتراک کامل | MODULES | services و cron | پیاده‌شده؛ renewal batch، retry و sandbox پرداخت باقی است |
| COM-005 | BNPL قانونی و قابل تطبیق | PHASE6 | internal/http provider؛ BnplQuoteTest | پیاده‌شدهٔ جزئی؛ providerهای واقعی تأیید نشده‌اند |
| COM-006 | قرارداد نسخه‌دار و گیت پرداخت | LEGAL | LegalWaiverService؛ SecurityGapsTest | پیاده‌شده و تست‌شدهٔ پایه؛ مدرک زنده/PII کامل نیست |
| COM-007 | شاهکار واقعی و امن | LEGAL | ShahkarService/setting | شکاف: secret ناامن و sandbox واقعی انجام نشده |
| COM-008 | FX با نرخ، quote، payout و تطبیق | DESIGN-FX | ۱۷ فایل؛ FxTest | پیاده‌شدهٔ شبیه‌سازی‌شده؛ provider/کلید واقعی باقی است |

### ۶.۶ دامنه و حضور وب

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| DOM-001 | جستجو/quote/سفارش/پرداخت/ثبت | DESIGN-DOMAIN | DomainService/Controller | شکاف: provider پیش از پرداخت فراخوانی می‌شود؛ quote قراردادی ندارد |
| DOM-002 | DNS و مسیریابی امن | DESIGN-DOMAIN | TXT/CNAME و دو دفتر دامنه | پیاده‌شدهٔ جزئی؛ BOLA و صفحه‌بندی باز است |
| DOM-003 | callback، تمدید و reconciliation | DESIGN-DOMAIN | کد متناظر یافت نشد | شکاف کامل |

### ۶.۷ زیرسیستم‌های کسب‌وکار و providerها

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| EXT-001 | Affiliate قابل تطبیق و ضدتقلب | MODULES؛ اسناد منبع | Affiliate services | پیاده‌شده؛ آزمون تقلب/refund/تسویه کامل نیست |
| EXT-002 | LMS و VOD امن | PHASES؛ منبع LMS | LMS services؛ LmsTest | پیاده‌شدهٔ پایه؛ VOD provider و ادعاهای امنیت اپ تأیید نشده |
| EXT-003 | Logistics/Courier/COD | PHASE7 | services/controllers؛ Phases tests | پیاده‌شدهٔ پایه؛ provider، حریم مکان و app contract کامل نیست |
| EXT-004 | Marketplace sync | PHASE9 | adapters/scheduler | پیاده‌شدهٔ پایه؛ API رسمی و contract test هر پلتفرم باقی است |
| EXT-005 | SEO/Ads/feed | PHASE10 | services/admin | پیاده‌شدهٔ پایه؛ provider/fید/اسکریپت رضایت و آزمون واقعی باقی است |
| EXT-006 | Translation/i18n | PHASE12 | translation adapter/config | پیاده‌شدهٔ پایه؛ provider و زبان/HTML contract کامل نیست |
| EXT-007 | Gamification/abandoned cart | PHASES | services/cron | پیاده‌شدهٔ پایه؛ abuse/race و اثربخشی واقعی تأیید نشده |
| EXT-008 | هر provider فعال آزمون sandbox دارد | API-KEYS؛ برنامه §۲۳.۷ | عمدتاً mock/config | شکاف سراسری؛ یک فاز PV برای هر provider |

### ۶.۸ اینستاگرام و VIP

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| IG-001 | اتصال رسمی Zernio، profile جدا، revoke و tenant scope | ADR-0004؛ طراحی Zernio | AccountCredentials فقط برای معماری قدیمی | شکاف: adapter/profile mapping/rotation/آزمون دو فروشگاه پیاده نشده |
| IG-002 | تولید، زمان‌بندی، انتشار و صدای رسمی با Zernio | ADR-0004؛ طراحی Zernio | ManusClient legacy؛ Agent Reach فقط پیشنهاد تاریخی ردشده | شکاف: migration و endpointهای Zernio کد ندارند؛ audio گیت production است |
| IG-003 | comment-to-DM رسمی با policy بک‌اند | ADR-0004؛ طراحی Zernio | ChatPlace/ManyChat/Funnel legacy | شکاف: webhook امضاشده، dedup، opt-out و delivery واقعی Zernio پیاده نشده |
| IG-004 | ثبت محصول ۱۳مرحله‌ای | MODULES/Apps | intake services؛ ۱۸ تست | پیاده‌شدهٔ قابل توجه؛ providerها و E2E زنده کامل نیست |
| IG-005 | VIP کامل و امن | DESIGN-VIP/EXPIRY | ۱۰ جدول VIP؛ ۲۶ تست | پیاده‌شدهٔ پایه؛ اپ، provider پرداخت و بار/امنیت زنده باز است |
| IG-006 | Giveaway، Insight و تحلیل رسمی/دستی رقبا | ADR-0004؛ Growth prompt؛ DESIGN-LEGAL-AUTH §۷.۷.۵۵ | GiveawayDrawService + InsightService + CompetitorService؛ ۱۸ مسیر REST (GrowthIntelController)؛ GrowthIntelTest ۱۲ سناریو؛ دود زنده | ✅ فاز ۵۵: قرعه‌کشی commit–reveal قابل بازمشیق، provenance (manual/zernio) + retention، رقبا با evidence. باقی‌ماندهٔ PV: Business Discovery و Hashtag Search تا endpoint رسمی Zernio backlog هستند |

### ۶.۹ پادو

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| PAD-001 | adapter ساختاریافتهٔ DeepInfra با حساب مستقل فروشگاه | ADR-0004؛ DESIGN-PADO §۲۲؛ DESIGN-LEGAL-AUTH §۷.۷.۵۶ | AiProviderInterface v1 + DeepInfraAdapter + AiToolbox؛ DeepInfraAdapterTest ۱۳ سناریو؛ دود زنده | ✅ فاز ۵۶ (کد/گیت‌ها/بودجه/هزینه). فعال‌سازی واقعی منتظر حساب DeepInfra + تأیید benchmark کارفرما |
| PAD-002 | صف مجوز اتمیک و دقیقاً یک‌بار | ADR-0004؛ منبع HITL؛ DESIGN-LEGAL-AUTH §۷.۷.۵۷ | ApprovalRequestService اتمیک (v44): فلیپ شرطی/idempotency/capability/expiry/claim/audit؛ PermissionQueueTest ۱۲ سناریو؛ دود زنده | ✅ فاز ۵۷ (زیرساخت صف). اتصال عملیات حساس = فازهای ۵۸–۵۹ |
| PAD-003 | عملیات حساس همگی از صف عبور کنند | ADR-0004؛ DESIGN-LEGAL-AUTH §۷.۷.۵۸ | SensitiveOperationsService (بایند pado.ops): قیمت/refund/حذف انبوه با گیت manage_tenant، هش پیش از اجرا، اجرای قابل جبران، فقط trash؛ SensitiveOpsTest ۱۰ سناریو؛ دود زندهٔ ۱۹گامی + ویژوال | ✅ فازهای ۵۸+۵۹ (قیمت/refund/حذف انبوه + انتشار/کمپین/سیاست). گیت‌های تأیید انسانی خودِ محصول دست‌نخورده |
| PAD-004 | قالب امن، preview و live tenant-scoped | ADR-0001/0003/0004؛ PADO §۱۸–۲۲ | ThemeValidator/Service | سه خروجی پذیرفته شد؛ اعتبارسنج/preview/Multisite و خط لولهٔ بازبینی PHP شکاف بحرانی‌اند |
| PAD-005 | چهار Playbook، حافظه، KPI و حلقهٔ یادگیری | ADR-0004؛ Growth prompt | طراحی سندی | شکاف: schema/version/provenance و یادگیری از فروش/Insight/campaign کد ندارد |
| PAD-006 | دفاع در برابر تزریق، ابزار، هزینه و نشت secret | ADR-0004؛ OWASP Agentic | allowlist ابزار + اعتبارسنج آرگومان + بودجه روزانه + کلید فقط زمانِ اجرا + تفکیک داده/دستور + گیت benchmark/جغرافیا (۵۶) | ✅ لایهٔ provider (۵۶)؛ آزمون خصمانهٔ کامل فاز ۶۴ باقی است |

### ۶.۱۰ API، تجربه و عملیات

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| API-001 | قرارداد نسخه‌دار و قابل تولید کلاینت | APPS/MODULES | ۱۲۴ ثبت مسیر کنترلرها | شکاف: OpenAPI مرجع و contract test کامل وجود ندارد |
| API-002 | pagination/idempotency/upload/push | APPS | BaseController/controllers/FCM | پیاده‌شدهٔ ناهمگون؛ ماتریس مسیر کامل نیست |
| UX-001 | فارسی کامل طبق دامنهٔ مصوب | تصمیم کارفرما؛ POT | POT ۲۳۵۱ رشته | شکاف: `fa_IR.po/mo` نهایی ندارد |
| UX-002 | RTL، دسترس‌پذیری و نقش‌ها | قانون ویژوال | ۳۵ تصویر | شکاف: checkout `US/CA`، glyph مربعی و متن انگلیسی |
| OPS-001 | استقرار تکرارپذیر و DB پشتیبانی‌شده | Railway docs | Docker/entrypoint | پیاده‌شدهٔ پایه؛ راهنما MySQL 8.0 منقضی را پیشنهاد می‌دهد |
| OPS-002 | worker، SLO، هشدار و Runbook | برنامهٔ جامع | health/status/log | شکاف؛ SLO/هشدار/صف عملیاتی کامل نیست |
| OPS-003 | backup، restore و rollback آزموده | `_backup`, Railway | اسکریپت/راهنما | شکاف؛ drill و RPO/RTO ثبت‌شده ندارد |
| OPS-004 | آزمون بار، soak، chaos و انتشار مرحله‌ای | برنامهٔ جامع | مدرک ثبت‌شده ندارد | شکاف کامل |

---

## ۷. تناقض‌های فعال کشف‌شده در فاز ۰۱

۱. ابتدای `PROJECT-STATE.md` نسخهٔ دیتابیس ۱۸ می‌گوید، ولی کد و محیط نسخهٔ ۱۹ هستند.
۲. `MODULES-REFERENCE.md` هنوز در بخش‌هایی DB v17 دارد.
۳. بعضی اسناد ۱۴۵ REST نوشته‌اند؛ شمارش کد ۱۴۳ فراخوانی ثبت مسیر است.
۴. `DESIGN-PHASES-6-14.md` «تکمیل نهایی» می‌گوید، ولی providerهای واقعی و چند چرخهٔ کامل
   تأیید نشده‌اند.
۵. `امنیت و مراقبت/README.md` و منبع API4، شکاف تاریخی OTP «فقط شماره» را به زمان حال
   نوشته‌اند؛ کد فعلی شرط شماره+IP و ایندکس متناظر دارد.
۶. راهنمای Railway هنوز `mysql:8.0` را پیشنهاد می‌دهد، در حالی که پشتیبانی رسمی آن پایان
   یافته و برنامهٔ مهاجرت LTS لازم است.
۷. اسناد منبع nopCommerce در `ِDoc/` چند قابلیت را «کامل» یا provider را مناسب ایران اعلام
   می‌کنند؛ این ادعاها وضعیت کد WordPress یا تأیید حقوقی/تجاری امروز نیستند.
۸. معماری پادو/اینستاگرام با ADR-0004 جای ADR-0002 را گرفت و خروجی سه‌گانهٔ قالب در
   ADR-0003 بسته شد؛ متن تاریخی ناسازگار و کد providerهای حذف‌شده باید جدا و migrate شوند.
۹. گزارش تاریخی ویژوال ۳۵/۳۵ سبز با نتیجهٔ جاری ۳۴/۳۵ و نقص glyph ناسازگار بود؛ گزارش جاری
   اصلاح شده، ولی نقل‌های تاریخی باید در فاز ۰۳ برچسب بخورند.
۱۰. route registration موجود با schema/permission سطح endpoint در سند ماشینی متصل نیست؛
    شمارش خام نمی‌تواند ادعای امنیت یا پوشش API باشد.

---

## ۸. تصمیم‌های فاز ۰۲

این موارد طبق قانون سؤال تک‌به‌تک از کارفرما گرفته می‌شوند.

### بسته‌شده

۱. **معماری مستأجر و قالب:** وردپرس چندسایتی با data plane مستقل هر زیرسایت و control
   plane مشترک IGBZ انتخاب شد. مرجع: `ADR/ADR-0001-MULTISITE-TENANCY.md`.
۲. **معماری پادو و اینستاگرام:** پادوی Playbookمحور؛ DeepInfra با حساب/هزینهٔ مستقل هر
   فروشگاه؛ Zernio به‌عنوان تنها provider اجتماعی با حساب مرکزی و profile جدا؛ حذف Manus،
   ChatPlace، ManyChat و Ayrshare و رد کانال Instagram مبتنی بر session در Agent Reach. مرجع:
   `ADR/ADR-0004-PADO-ZERNIO-SOCIAL-ARCHITECTURE.md` که جانشین ADR-0002 است.
۳. **خروجی قالب:** طبق پاسخ مستقیم پیشین کارفرما هر سه نوع به انتخاب ادمین: قالب فرزند
   بلوکی، قالب کلاسیک PHP و تمپلیت صفحه‌ساز الحاقی. مرجع:
   `ADR/ADR-0003-THREE-THEME-OUTPUTS.md`.

### باز

۴. قرارداد و آزمون واقعی Zernio/DeepInfra و فهرست providerهای سایر حوزه‌ها؛
۵. ظرفیت هدف و SLO؛
۶. پایگاه‌دادهٔ تولید LTS؛
۷. دامنهٔ دقیق ترجمه و استثناها؛
۸. وضعیت ایده‌های صرفاً پیشنهادی پوشهٔ `ِDoc/`؛ به‌ویژه حسابداری، سامانهٔ مؤدیان و
   چندفروشندگی داخل هر tenant؛
۹. زمان و محدودهٔ ورود اپ‌ها و ویرا که فعلاً خارج دامنه‌اند.

---

## ۹. اعتبارسنجی و نتیجهٔ فاز ۰۱

اعتبارسنجی انجام‌شده:

- اجرای تولید و `--check` استخراج‌گر موفق بود؛ تغییر عمدی artefact باعث شکست `--check` شد؛
- شمارش‌های ۲۱۵/۱۴۳/۷۲/۳۰۴/۳۶/۱۷/۱۴/۲۲ و ۶۷ شناسهٔ یکتای نیاز با assertion مستقل کنترل شد؛
- `git diff --check` بدون خطا بود و هیچ مسیر زیر `vira/` در تغییرات دیده نشد؛
- سند با Chromium 149 در صفحهٔ واقعی راست‌به‌چپ رندر شد: ۹ بخش، ۱۲ جدول، عرض ۱۴۴۰، بدون
  سرریز افقی و بدون خطای console؛ اسکرین‌شات تمام‌صفحه بازبینی چشمی شد:

```text
visual-testing/phase-01-requirements-traceability.png
```

نتیجه:

- موجودی اولیهٔ کد، اسناد، امنیت، providerها و آزمون‌ها ثبت شد؛
- ۶۷ نیاز سطح خانواده با شناسهٔ یکتا در ماتریس قرار گرفت؛
- شکاف‌ها و تناقض‌های فعال از ادعاهای تاریخی جدا شدند؛
- تصمیم معماری پادو/اینستاگرام در ADR-0004 ثبت شد؛ فاز بعدی یکسان‌سازی باقی اسناد و سپس
  برنامه‌ریزی migration است و هیچ تغییر کدی بدون مجوز صریح آغاز نمی‌شود.

این سند در فاز ۰۴ به موجودی ماشینی endpoint/table/setting/job/test گسترش می‌یابد و از آن پس
اختلاف با کد باید CI را قرمز کند.
