# ماتریس ردیابی نیازمندی‌های IGBZ-WP

> نسخه: ۰.۱ — خروجی فاز ۰۱
>
> تاریخ: ۱۴۰۵/۰۶/۰۵ — ۲۷ اوت ۲۰۲۶
>
> وضعیت: snapshot ماشینی و ماتریس اولیهٔ دوطرفه؛ اتصال سطح مسیر/جدول/تنظیم به آزمون و
> کنترل واگرایی در فاز ۰۴ اجباری می‌شود.

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
- Manus، ChatPlace، ManyChat و AI Studio؛
- ترجمه، گفتار به متن و SEO/تبلیغات؛
- نرخ و payout ارزی؛
- ارائه‌دهندهٔ دامنه؛
- پیامک و شاهکار؛
- درگاه بیرونی پادو.

فاز `PV` برای هر provider فعال جداست و وجود کلاس به معنی تأیید provider نیست.

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
| SEC-006 | SSRF و دانلود محدود | OWASP SSRF؛ PADO §۱۹ | `Support/Http`, `PadoGateway` | شکاف بحرانی در ZIP/redirect/Bearer/اندازه |
| SEC-007 | فایل و artefact غیرقابل‌اجرا | PADO §۱۹ | `ThemeValidator`, `ThemeService` | شکاف: PHP پذیرفته می‌شود؛ blacklist sandbox نیست |
| SEC-008 | JWT، refresh، device و replay | LEGAL §۷.۶؛ API | `Jwt`, `RefreshTokenService`, device routes | پیاده‌شده؛ آزمون نقش، ابطال و race کامل نیست |
| SEC-009 | خروج حساس با حضور انسان/بیومتریک | OWASP API6؛ LEGAL §۷.۶ | طراحی و device data | شکاف؛ قرارداد کامل سرور و آزمون زنده ندارد |
| SEC-010 | audit بدون secret/PII و retention | طراحی امنیت/ویرا | `Logger`, جدول logs و رخدادهای پراکنده | شکاف؛ سیاست یکپارچه و پوشش تمام عملیات حساس ندارد |

### ۶.۳ چندمستأجری و داده

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| TEN-001 | resolver مستأجر از هویت معتبر | MODULES-REFERENCE؛ Hub | `TenantResolver`, `BaseController` | پیاده‌شده؛ ممیزی تزریق tenant و همهٔ مسیرها باقی است |
| TEN-002 | مالکیت در repository و query | OWASP Multi-Tenant | repositoryهای متعدد | پیاده‌شدهٔ ناهمگون؛ الگوی اجباری واحد ندارد |
| TEN-003 | provision کامل و idempotent | هندآف؛ Hub | signup، tenant/member/domain creation | پیاده‌شده؛ rollback جزئی و آزمون شکست کامل نیست |
| TEN-004 | cache/file/job/rate tenant-scoped | OWASP Multi-Tenant؛ §۵.۵ | الگوهای پراکنده | شکاف؛ ماتریس cache/فایل/صف وجود ندارد |
| TEN-005 | قالب مستقل هر مستأجر | DESIGN-PADO §۱۹ | `switch_theme()` سراسری | شکاف بحرانی؛ معماری باید در فاز ۰۲ قطعی شود |
| DAT-001 | schema و migration نسخه‌دار | Schema/Activator | DB v19، ۷۲ جدول | پیاده‌شده؛ مسیر v18 متد مستقل ندارد و آزمون upgrade حجیم باقی است |
| DAT-002 | query رشدپذیر محدود و ایندکس‌شده | §۵.۵؛ OWASP API4 | limit در بخش‌ها | شکاف: فهرست/DELETE نامحدود و batchهای ثابت |
| DAT-003 | HPOS واقعی | WooCommerce | declaration موجود | شکاف: محیط آزمون HPOS خاموش و migration تأیید نشده |

### ۶.۴ صف و عملیات پس‌زمینه

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| QUE-001 | صف بادوام با claim و retry | WP-Cron source؛ Action Scheduler | سه WP-Cron تجمیعی | شکاف: Action Scheduler صفر استفاده |
| QUE-002 | idempotency و اجرای اثر دقیقاً یک‌بار | §۵.۵؛ الگوی HITL | در چند سرویس مالی/قیف موجود | پیاده‌شدهٔ موضعی؛ قرارداد job مشترک ندارد |
| QUE-003 | fairness و backpressure مستأجر | §۵.۵؛ OWASP API4 | ContentScheduler تا حدی گردشی | شکاف: sweepهای global و batch بدون requeue |
| QUE-004 | مشاهده‌پذیری، CLI و dead-letter | برنامهٔ جامع | صفحهٔ cron ساده | شکاف؛ backlog/age/replay/DLQ ندارد |

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
| IG-001 | credential حساب، revoke و tenant scope | MODULES | AccountCredentials و tests | پیاده‌شدهٔ پایه؛ rotation/provider واقعی باز است |
| IG-002 | تولید و انتشار محتوا با Manus | AUTOMATION/PADO | ManusClient، scheduler، verify tests | پیاده‌شدهٔ پایه؛ کلید و نتیجهٔ واقعی تأیید نشده |
| IG-003 | comment-to-DM رسمی | AUTOMATION | ChatPlace/ManyChat/Funnel؛ tests | پیاده‌شدهٔ پایه؛ provider منتخب/قرارداد زنده باز است |
| IG-004 | ثبت محصول ۱۳مرحله‌ای | MODULES/Apps | intake services؛ ۱۸ تست | پیاده‌شدهٔ قابل توجه؛ providerها و E2E زنده کامل نیست |
| IG-005 | VIP کامل و امن | DESIGN-VIP/EXPIRY | ۱۰ جدول VIP؛ ۲۶ تست | پیاده‌شدهٔ پایه؛ اپ، provider پرداخت و بار/امنیت زنده باز است |
| IG-006 | Giveaway و Insights واقعی | PHASES/Growth prompt | services؛ test قرعه‌کشی | پیاده‌شدهٔ پایه؛ provenance/تقلب/provider واقعی کامل نیست |

### ۶.۹ پادو

| شناسه | نیاز | سند/منبع | کد/آزمون فعلی | وضعیت و شکاف |
|---|---|---|---|---|
| PAD-001 | مرز سرویس و خروجی ساختاریافته | DESIGN-PADO | PadoGateway | پیاده‌شده فقط برای theme_design؛ قرارداد عمومی ندارد |
| PAD-002 | صف مجوز اتمیک و دقیقاً یک‌بار | PADO؛ منبع HITL | ApprovalRequestService | شکاف بحرانی race؛ فقط مسیر قالب سیم‌کشی واقعی دارد |
| PAD-003 | عملیات حساس همگی از صف عبور کنند | PADO | labelها در PadoPage | شکاف: قیمت/refund/حذف/کمپین/انتشار اجراکننده ندارند |
| PAD-004 | قالب امن، preview و live tenant-scoped | PADO §۱۹ | ThemeValidator/Service | شکاف بحرانی: PHP، preview بی‌مصرف، `switch_theme()` سراسری |
| PAD-005 | حافظه، Playbook و KPI | PADO | طراحی سندی | شکاف کامل کد |
| PAD-006 | دفاع عامل در برابر تزریق/ابزار/هزینه | منبع AI-Commerce؛ OWASP Agentic | کنترل محدود | شکاف؛ آزمون خصمانه و مرز اختیار کامل نیست |

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
۸. نقش‌های پادو، n8n، سرویس مدل و ویرا و نیز تعداد نوع خروجی قالب در اسناد یکدست نیست.
۹. گزارش تاریخی ویژوال ۳۵/۳۵ سبز با نتیجهٔ جاری ۳۴/۳۵ و نقص glyph ناسازگار بود؛ گزارش جاری
   اصلاح شده، ولی نقل‌های تاریخی باید در فاز ۰۳ برچسب بخورند.
۱۰. route registration موجود با schema/permission سطح endpoint در سند ماشینی متصل نیست؛
    شمارش خام نمی‌تواند ادعای امنیت یا پوشش API باشد.

---

## ۸. تصمیم‌های لازم پیش از فاز ۰۲

این موارد باید طبق قانون سؤال تک‌به‌تک از کارفرما گرفته شوند:

۱. معماری نهایی مستأجر و قالب؛
۲. نقش قطعی پادو، n8n، سرویس مدل و ویرا؛
۳. نوع یا انواع خروجی قالب؛
۴. فهرست providerهای واقعاً فعال و providerهای حذف‌شده؛
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
- فاز بعدی، دریافت و ثبت تصمیم‌های معماری است و قبل از پاسخ‌های کارفرما نباید شروع شود.

این سند در فاز ۰۴ به موجودی ماشینی endpoint/table/setting/job/test گسترش می‌یابد و از آن پس
اختلاف با کد باید CI را قرمز کند.
