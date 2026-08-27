# IGBZ-WP — مرجع ماژول‌ها (شرح کارکرد و مستندات فنی)

**آخرین به‌روزرسانی:** ۱۴۰۵/۰۶/۰۵ (2026-08-27) · **نسخهٔ افزونه:** 1.0.0 · **دیتابیس:** v19 — ۷۲ جدول
· **ویرا:** ۰.۹.۷ (`vira/`، بخش ۷.۵ در `PROJECT-STATE.md`)

این سند مرجع کامل «چه ماژول/زیرسیستمی وجود دارد، چه می‌کند و مستندات فنی‌اش چیست» است.
مرجع وضعیت کلی پروژه `PROJECT-STATE.md` است و این سند هم‌عرض آن — ساختار و جزئیات فنی.
هر جا این سند با کد نخواند، کد ملاک است و این سند باید اصلاح شود.

---

## ۱. معماری کلی

محصول IGBZ از nopCommerce (چهار افزونهٔ جدا) به **یک افزونهٔ وردپرس با شش ماژول
قابل‌خاموش‌وروشن** پورت شده است. شناسهٔ ماژول‌ها در `src/Support/Modules.php` و وضعیت روشن/خاموش
در آپشن `igbz_enabled_modules` ذخیره می‌شود. پیش‌فرض `multitenant` و `pado` روشن هستند؛ پادو مرکز مجوز و طراحی قالب را فراهم می‌کند.

**چندمستأجری:** تک‌سایت با ستون `tenant_id` (نه WordPress Multisite). تقریباً همهٔ جدول‌ها
`tenant_id` دارند؛ فهرست استثناهای عمدی در `tests/SchemaTest.php` ثبت شده. محصولات و سفارش‌های
ووکامرس با متای `_igbz_tenant_id` در کاتالوگ، کوئری سفارش، فهرست‌های پیشخوان و مسیرهای REST محدوده‌بندی می‌شوند (۰/نبود = مشترک پلتفرم و برای مالک مستأجر قابل‌نمایش نیست).

| شناسه | نام | خلاصه |
|---|---|---|
| `multitenant` | فروشگاه‌های چندمستأجری | مستأجر، کیف پول، پلن، BNPL، همکاری فروش، LMS، OTP، درگاه‌ها، درگاه مرکزی، لجستیک، مارکت‌پلیس، سئو، ترجمه، گیمیفیکیشن، دامنه، احراز قانونی |
| `instagram` | اتوماسیون اینستاگرام | Manus، ManyChat/ChatPlace، قیف کامنت→دایرکت، ثبت محصول، کانال VIP، استودیوی AI، قرعه‌کشی |
| `hub` | سایت مادر (دایرکتوری) | دایرکتوری فروشگاه‌ها، ثبت‌نام مستأجر، بلوک محتوا، تأیید دامنه، لینک VIP |
| `rest_api` | API اپ موبایل | JWT، کاتالوگ، حساب، ادمین فروشگاه، پوش FCM، دستگاه‌ها + کنترلرهای جدید (FX/AI/پیک/دامنه) |
| `fx` | واسط پرداخت ارزی | شارژ ریالی کیف پول دلاری، متراژ منوس، قبض ماهانه، payout، صرافی USDT، گزارش اپراتور |

ترتیب بایندینگ ماژول‌ها در `Plugin::module_map()`: `multitenant → instagram → hub → fx → rest_api`
(FX قبل از rest_api تا کنترلر FX سرویس‌ها را ببیند).

**الگوهای فنی مشترک (در همهٔ ماژول‌ها):**

- **آداپتور پشت اینترفیس:** هر سرویس خارجی پشت یک اینترفیس است (`GatewayInterface`،
  `BnplProviderInterface`، `FxPayoutAdapterInterface`، `ShippingAdapterInterface`،
  `MarketplaceAdapterInterface`، `DmClientInterface`، `AiProviderInterface`،
  `SpeechToTextInterface`، `HttpTranslationAdapter`…) و از طریق هوک‌های `igbz_register_*`
  ثبت می‌شود — افزودن سرویس‌دهندهٔ جدید بدون تغییر کد.
- **لجر به‌جای موجودی ذخیره‌شده:** تمام پول‌ها (کیف پول، BNPL، درگاه مرکزی، AI credits، FX)
  روی دفترکل با کلید یکتای idempotency ثبت می‌شوند؛ موجودی همیشه از جمع لجر محاسبه می‌شود.
- **Idempotency مالی:** رویدادهای تکراری (وبوک، کرون، retry) هرگز دوبار اعتبار/برداشت نمی‌کنند
  (کلید یکتا روی `(tenant, user, reason, reference)` و ادعاکردن ردیف قبل از تسویه).
- **امنیت:** PIN/قرعه‌کشی با `random_int`، مقایسه با `hash_equals`، لینک‌های مدیا/ویدیو با
  HMAC و انقضا، رمزنگاری کلیدها با AES-256-GCM (`IGBZ_ENCRYPTION_KEY`).
- **کرون متمرکز:** سه قلاب `igbz_cron_five_minutes` / `igbz_cron_hourly` / `igbz_cron_daily`
  در `Support/Cron.php`؛ هر ماژول کار خود را روی همان‌ها صدا می‌زند.

---

## ۲. ماژول `multitenant` — فروشگاه‌های چندمستأجری

**شرح کارکرد:** هستهٔ محصول: هر مستأجر یک فروشگاه ووکامرس مجازی با کیف پول، پلن اشتراک،
خرید اقساطی، سیستم همکاری در فروش، آموزش (LMS)، درگاه‌های پرداخت (ریالی + بانکی + ارزی +
رمز)، درگاه مرکزی (escrow)، لجستیک و اپ پیک، همگام‌سازی مارکت‌پلیس‌ها، سئو، ترجمهٔ خودکار،
گیمیفیکیشن، دامنهٔ اختصاصی و احراز هویت قانونی.

زیرسیستم‌ها (هر کدام بخش ۲.x دارد):

### ۲.۱ مستأجرها، دامنه‌ها و پلن‌ها (Tenancy & Plans)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `tenants`، `plans` |
| جداول | `tenants`، `tenant_domains`، `tenant_members`، `plans` |
| صفحات ادمین | Tenants (`igbz-tenants`)، Plans (`igbz-plans`) |
| تنظیمات | `general.default_tenant_id`، `general.tenant_resolution` (domain/path/single)، `general.allow_self_signup`، `general.auto_approve_tenants` |
| مستندات | `REVIEW-IGBZ-NopCommerce.md`، `README.md` (جریان ثبت‌نام ۱۳ مرحله‌ای) |

مستأجر از طریق دامنه، مسیر یا حالت تک‌فروشگاهی شناسایی می‌شود (`igbz_pre_resolve_tenant`).
تأیید دامنه (DNS/CNAME) در `hub.domains` انجام و در `tenant_domains` ثبت می‌شود — پیش‌نیاز
باز شدن درگاه‌های بانکی.

### ۲.۲ کیف پول (Wallet)

| مورد | جزئیات |
|---|---|
| سرویس | `wallet` |
| جداول | `wallet_ledger`، `wallet_balances` |
| صفحهٔ ادمین | Wallet (`igbz-wallet`) |
| REST | `GET /igbz/v1/account/wallet`، `POST /igbz/v1/account/wallet/topup` |
| تنظیمات | `wallet.*` (درصد کشبک سفارش، سقف شارژ، درگاه پرداخت با کیف پول در checkout) |
| هوک‌ها | `igbz_wallet_entry_created` |

دفترکل با کلید یکتای `(tenant, user, reason, reference)` — retry هرگز دوباره اعتبار نمی‌دهد.
شارژ کیف پول با درگاه‌ها (`purpose` متمایز) و تسویه روی `igbz_payment_verified` انجام می‌شود.

### ۲.۳ اشتراک‌ها و BNPL (Subscriptions & Buy-Now-Pay-Later)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `plans` (تمدید)، `bnpl.providers`، `bnpl` |
| جداول | `subscriptions`، `bnpl_credit`، `bnpl_contracts`، `bnpl_installments` |
| صفحهٔ ادمین | Instalments (`igbz-bnpl`) |
| شورت‌کد | `[igbz_plans]`، `[igbz_bnpl_calculator]` |
| تنظیمات | `bnpl.fee_percent` (فقط روی باقی‌ماندهٔ مبلغ)، `bnpl.cash_discount_percent` (تخفیف نقدی — کارمزد سبد برای پرداخت غیراقساطی) |
| کرون | ساعتی: `process_overdue()` + `send_reminders()` |
| هوک‌ها | `igbz_register_bnpl_providers`، `igbz_bnpl_contract_*`، `igbz_bnpl_installment_paid`، `igbz_bnpl_reminder_due` |

ارائه‌دهنده‌های داخلی + آداپتورهای HTTP: اسنپ‌پی (`SnappPayBnplProvider`)، تارا
(`TaraBnplProvider`)، دیجی‌پی (`HttpBnplProvider`). اقساط طوری گرد می‌شوند که مجموعشان دقیقاً
برابر کل باشد.

### ۲.۴ همکاری در فروش (Affiliate)

| مورد | جزئیات |
|---|---|
| سرویس | `affiliate` |
| جداول | `affiliates`، `affiliate_commissions`، `referral_clicks` |
| صفحهٔ ادمین | Affiliates (`igbz-affiliate`) |
| کرون | روزانه: `process_pending_commissions()` |
| هوک‌ها | `igbz_affiliate_enrolled`، `igbz_referral_converted`، `igbz_affiliate_commission_recorded`، `igbz_affiliate_commission_base` |

پورسانت‌ها با دلیل مجزای `affiliate_commission` ثبت می‌شوند (جدا از `instagram_reward`
مربوط به قیف‌ها).

### ۲.۵ آموزش (LMS)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `lms`، `lms.vod` |
| جداول | `courses`، `lessons`، `enrollments`، `lesson_progress`، `quizzes`، `quiz_attempts` |
| صفحات ادمین | Courses (`igbz-courses`) |
| شورت‌کد | `[igbz_courses]`، `[igbz_course]` |
| REST | `GET /igbz/v1/account/courses`، `POST /igbz/v1/account/courses/progress`، آزمون‌ها، گواهی‌ها |
| تنظیمات | `lms.pass_score`، `lms.max_quiz_attempts`، `lms.certificate_enabled`، `lms.revoke_on_refund`، `lms.video_hmac_secret`، `lms.video_link_ttl`، `lms.vod_*` (آروان) |
| هوک‌ها | `igbz_lms_enrolled`، `igbz_lms_course_completed`، `igbz_lms_quiz_submitted`، `igbz_lms_certificate_issued`، `igbz_lms_unenrolled` |

**سطح امنیت سنگین** (مخصوص LMS): آزمون فقط از `questions_for_client()` می‌رسد (پاسخ‌های درست
حذف می‌شوند)، گواهی فقط با توافق کلید سراسری + دوره، بازگشت وجه ووکامرس → لغو ثبت‌نام
(تطبیق `enrollments.order_id`)، ویدیو با لینک HMAC مقید به زمان/IP و واترمارک/FLAG_SECURE سمت
اپ. این سطح عمداً برای VIP تکرار نمی‌شود.

### ۲.۶ پرداخت‌ها (Payments) — ریالی، بانکی، رمز

| مورد | جزئیات |
|---|---|
| سرویس | `payments` |
| جدول | `payments` |
| صفحهٔ ادمین | Payments (`igbz-payments`) — با دکمهٔ **Test connection** (پرداخت آزمایشی ۱ ریالی) |
| REST | `GET /igbz/v1/account/payments` |
| تنظیمات | `payments.default_gateway`، `payments.currency_multiplier` (تومان→ریال، پیش‌فرض ۱۰) و کلیدهای هر درگاه |
| هوک‌ها | `igbz_register_payment_gateways`، `igbz_payment_verified`، `igbz_payment_failed`، `igbz_payment_callback_redirect`، `igbz_psp_gateway_icon` |

**فهرست درگاه‌ها** (روی `GatewayInterface`، همه با تأیید مبلغ + idempotency callback):

| دسته | شناسه | سازوکار |
|---|---|---|
| PSP های اولیه | `zarinpal`, `idpay`, `nextpay`, `payir` | REST |
| درگاه عمومی | `httppsp` | Endpoint های تنظیم‌پذیر + نگاشت فیلدها |
| بانک‌های مستقیم | `sadad` | REST + امضای RSA-SHA1 (کلیدها: merchant_id/terminal_id/private_key) |
| | `asanpardakht` | REST |
| | `parsian` | SOAP |
| | `irankish` | REST v3 |
| | `mellat` | SOAP bpPay/bpVerify/bpSettle |
| | `saman` | RSA سه‌کلید |
| | `pasargad` | امضای RSA |
| | `sepehr` | REST |
| پیام‌رسان | `balepay` | REST |
| رمزارز | `nowpayments` | ساخت Invoice + وبهوک IPN (مشتری خارجی) |

> **گیت قانونی:** درگاه‌های PSP/بانکی فقط وقتی در checkout ظاهر می‌شوند که دامنهٔ مستقل با
> DNS تأیید شده **و** `legal.enamad_active` روشن باشد. کیف پول، BNPL، FX، BalePay و رمزارز
> گیت نمی‌شوند. فهرست گیت‌شده در `PaymentService::bank_gateway_allowed()`.
>
> **تله:** هر آداپتور بانکی باید `use IGBZ\Suite\Support\Http;` داشته باشد (پایهٔ
> `AbstractIpgGateway` type-hint می‌کند) — فراموشی = TypeError موقع پرداخت.

### ۲.۷ درگاه مرکزی (Master Payment — escrow)

| مورد | جزئیات |
|---|---|
| سرویس | `master.payment` |
| جداول | `ig_master_payments`، `ig_master_disputes`، `ig_master_withdrawals`، `ig_master_agreements` |
| صفحهٔ ادمین | Master payment (`igbz-master-payment`) |
| REST | `GET /igbz/v1/master-payment`، `POST /igbz/v1/master-payment/agreement`، `POST /igbz/v1/master-payment/withdraw` |
| کرون | روزانه: `release_due()` — آزادسازی وجه بعد از تأیید تحویل |
| تنظیمات | `master_payment.enabled` |

وجه خریدها به‌صورت امانی نزد پلتفرم می‌ماند؛ آزادسازی بعد از تحویل (کرون)، اختلاف (dispute)
آزادسازی را مسدود می‌کند، و برای هر تراکنش توافقنامهٔ دیجیتال ثبت می‌شود. برداشت ابتدا از
کیف پول ذخیره (reserve) می‌کند.

### ۲.۸ لجستیک و اپ پیک (Logistics & Courier)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `logistics`، `logistics.courier`، `logistics.labels` |
| جداول | `ig_shipments`، `ig_couriers`، `ig_label_groups`، `ig_label_group_items`، `ig_cod_payments`، `ig_courier_routes`، `ig_courier_tracking`، `ig_courier_chat` |
| صفحهٔ ادمین | Logistics (`igbz-logistics`) |
| REST | `GET/POST /igbz/v1/courier/*` (me, shipments, routes/plan, arrived, deliver, cod, tracking, chat) + `POST /igbz/v1/shipments/{id}/status|tracking` + `POST /igbz/v1/checkout/cod-pay` |
| تنظیمات | `logistics.tapin_api_key`/`logistics.postex_api_key` (آداپتورهای ارسال)، `logistics.express_cost_irt`، `logistics.national_cost_irt`، `logistics.heavy_cost_irt`، `logistics.weight_threshold_kg`، `logistics.delivery_pin_digits` |
| مستندات | `DESIGN-PHASE7-COURIER.md` |

ویژگی‌ها: مسیریابی ترتیبی (دکمهٔ «arrived» مرسولهٔ بعدی را باز می‌کند)، جستجوی مرسوله با
بارکد، تحویل با **PIN مشتری** (`random_int` + `hash_equals` — روی برچسب هم چاپ می‌شود)،
COD چهارشکل (نقد/درگاه/کارتخوان/اپ)، رصد زندهٔ موقعیت و چت پیک↔مشتری (لاگ‌های append-only)،
چاپ برچسب استاندارد بارکددار (`LabelPrintingService`).

### ۲.۹ مارکت‌پلیس‌ها (Marketplaces)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `marketplace`، `marketplace.sync`، `marketplace.mappings`، `marketplace.basalam` |
| جداول | `marketplace_links`، `ig_marketplace_sync` (صف بادوام)، `ig_category_mapping` |
| صفحهٔ ادمین | Marketplaces (`igbz-marketplaces`) |
| کرون | هر ۵ دقیقه: `process_pending()` |
| تنظیمات | `marketplace.digikala_api_key`، `marketplace.divar_token`، `basalam.api_key`/`basalam.gharhe_id`/`basalam.enabled`، `marketplace.feed_limit`، `marketplace.sync_retries` |
| هوک‌ها | `igbz_marketplace_channels` (فهرست کانال‌ها)، `igbz_marketplace_feed_item`، `igbz_marketplace_cache_flushed` |
| مستندات | `DESIGN-PHASE9-MARKETPLACES.md` |

آداپتورها: دیجی‌کالا، دیوار، **باسلام** (با انتشار خودکار محتوای تولیدی اینستاگرام وقتی
فعال باشد) + فیدهای ترب/امالز/گوگل‌مرچنت (از کاتالوگ واقعی). همگام‌سازی با هوک تغییر محصول و
کرون worker.

### ۲.۱۰ سئو و تبلیغات (SEO & Ads)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `seo` |
| صفحهٔ ادمین | SEO & ads (`igbz-seo`) — انتخابگر محصول: متای تولیدی روی **محصول واقعی** ذخیره می‌شود |
| خروجی‌ها | متای خودکار + هشتگ (قالب قطعی یا AI)؛ فید یکتانت/تپسل (`?igbz_feed=`)؛ آداپتور تریبون (`seo.triboon_api_key`) |
| تنظیمات | `seo.enabled`، `seo.use_ai`، `seo.feed_page_size`، `seo.triboon_base_url` |
| مستندات | `DESIGN-PHASE10-SEO.md`، `PROMPTS-SEO-PADO.md` (پرامپت‌های پادو) |

### ۲.۱۱ ترجمه و چندزبانه (Translation & i18n)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `translation`، `translation.adapter`، `i18n` |
| صفحهٔ ادمین | Translator (`igbz-translator`) |
| REST | `GET /igbz/v1/i18n/config` |
| تنظیمات | `translation.provider`، `translation.api_key`، `translation.base_url`، `translation.path`، `translation.result_json_path` |
| مستندات | `DESIGN-PHASE12-INTERNATIONAL.md` |

`HttpTranslationAdapter` — هر سرویس مترجم ایرانی/خارجی با endpoint تنظیم‌پذیر. i18n زبان‌های
انتخابی ادمین را در اختیار اپ می‌گذارد.

### ۲.۱۲ گیمیفیکیشن (Gamification)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `gamification`، `gamification.carts` |
| جدول | `ig_abandoned_carts` |
| صفحهٔ ادمین | Gamification (`igbz-gamification`) |
| کرون | ساعتی: `sweep()` — یادآوری سبد رهاشده با OTP + کد تخفیف |
| تنظیمات | `gamification.*`، `abandoned_cart.enabled` |

چرخ‌وفلک با سردی ۲۴ ساعت و RNG امن (`random_int`)؛ کوپن‌های جایزه، کوپن **واقعی ووکامرس** هستند.

### ۲.۱۳ دامنه و وب‌پرزنس (Domain & Web Presence)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `domain`، `webpresence` |
| جداول | `ig_domains`، `ig_domain_orders`، `ig_web_presence` |
| صفحهٔ ادمین | Domain (`igbz-domains`) |
| REST | `GET /igbz/v1/domains`، `/domains/search`، `POST /domains/register|transfer|subdomain`، `/domains/{id}/verify-dns`، `GET/POST /domains/web-presence` |
| تنظیمات | `domain.provider`، `domain.provider_api_key`، `domain.provider_base_url`، `domain.mother_subdomain` |
| مستندات | `DESIGN-DOMAIN.md` |

جستجو/ثبت/زیردامنه/تأیید DNS/انتقال دامنه پشت آداپتور رجیسترار (هنوز رجیسترار واقعی وصل
نشده). ثبت خودکار وب‌پرزنس در گوگل/بینگ برای دامنه‌های تأییدشده + صفحات قانونی پیش‌فرض هر
فروشگاه.

### ۲.۱۴ احراز هویت قانونی (Legal Auth)

| مورد | جزئیات |
|---|---|
| سرویس | `legal.nid` |
| جداول | `ig_nid_verifications`، `ig_legal_agreements` |
| تنظیمات | `legal.shahkar_api_key`، `legal.shahkar_base_url`، `legal.national_id_check`، `legal.enamad_active`، `legal.nid` (قفل مدیر ارشد) |
| مستندات | `DESIGN-LEGAL-AUTH.md` |

`NationalIdVerifier` (شاهکار) کد ملی را قبل از خریدهای پرارزش چک می‌کند و **تا وقتی مدیر ارشد
کلید را ثبت نکند قفل می‌ماند** (bypass با پیش‌فرض «قبول» ممنوع). سلب مسئولیت دیجیتال در
`ig_legal_agreements`. `legal.enamad_active` گیت درگاه‌های بانکی است.

### ۲.۱۵ OTP

| مورد | جزئیات |
|---|---|
| سرویس | `otp` |
| جدول | `otp_codes` |
| شورت‌کد | `[igbz_otp_login]` |
| تنظیمات | `otp.kavenegar.api_key`/`otp.smsir.api_key` (+ `otp.sms_provider`، طول/انقضا/سقف تلاش) |
| هوک‌ها | `igbz_otp_send_sms`، `igbz_otp_verified`، `igbz_otp_user_registered` |

---

## ۳. ماژول `instagram` — اتوماسیون اینستاگرام

**شرح کارکرد:** تولید و انتشار خودکار محتوا (Manus)، قیف کامنت→دایرکت (ManyChat/ChatPlace)،
ثبت محصول از طریق اپ، کانال VIP (فید خصوصی پولی)، استودیوی AI و قرعه‌کشی.

> **قانون اینستاگرام (تصحیح‌شده):** Graph API استفاده نمی‌شود چون در ایران به‌عنوان
> سرویس‌دهنده در دسترس نیست — قید موقت، نه ممنوعیت. اگر سرویسی آن را مهیا کند (مثل ChatPlace
> و MCP آینده‌اش) استقبال می‌کنیم. اتوماسیون مرورگر (Windsor) طبق ToS متا رد شده است.

### ۳.۱ تولید و انتشار محتوا (Manus)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `ig.prompts`، `ig.manus_client`، `ig.manus`، `ig.scheduler`، `ig.insights` |
| جداول | `ig_accounts`، `ig_content`، `ig_insights` |
| صفحات ادمین | IG Accounts، IG Content، IG Insights |
| REST | `POST /igbz/v1/manus/task` (وبهوک) |
| کرون | ۵ دقیقه: `scheduler->tick()` + `intake_worker->tick()`؛ ساعتی: `insights->reconcile()`؛ روزانه: `insights->collect_all()` |
| تنظیمات | `manus.api_key`، `manus.agent_profile`، `manus.locale`، `manus.auto_generate`، `manus.auto_schedule`، `manus.collect_insights`، `manus.default_peak_hours`، `manus.webhook_token`، `manus.project_id` |
| هوک‌ها | `igbz_manus_prompt_*` (بازنویسی هر پرامپت)، `igbz_ig_content_*` (scheduled/ready/published/failed)، `igbz_manus_webhook` |

تحقیق نیچ/ترند، طراحی گرافیک، ساخت ریلز، کپشن و هشتگ، انتشار/زمان‌بندی در ساعت‌های اوج —
کل اتوماسیون از `PromptBuilder` (قالب‌های قطعی، بدون رشتهٔ ثابت) می‌آید. تسک‌ها ناهمگام‌اند:
وبهوک مسیر سریع، پولینگ ۵ دقیقه‌ای تضمین. **متراژ اعتبار منوس** در لحظهٔ dispatch (بدون صف):
کافی → ارسال؛ ناکافی → رد با پیام شارژ؛ تسک مردود → بازگشت مبلغ.

### ۳.۲ قیف کامنت→دایرکت (ManyChat / ChatPlace)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `ig.manychat`، `ig.subscribers`، `ig.funnels`، `ig.credentials` |
| جداول | `ig_funnels`، `ig_subscribers`، `ig_funnel_hits` |
| صفحات ادمین | IG Funnels، IG Subscribers |
| REST | `POST /igbz/v1/manychat/comment|event|subscriber`، `GET /igbz/v1/manychat/ping` |
| تنظیمات | `dm.provider` (`manychat|chatplace`)، `manychat.*`، `chatplace.api_key`، `chatplace.base_url` |
| هوک‌ها | `igbz_dm_gateways` (فهرست درگاه‌های DM)، `igbz_manychat_event`، `igbz_funnel_hit`، `igbz_funnel_delivered`، `igbz_ig_funnel_followup_done` |

سوئیچ `dm.provider` توسط مدیر ارشد انجام می‌شود؛ ManyChat پیاده و **غیرفعال** به‌عنوان
fallback می‌ماند، **ChatPlace انتخاب فعلی است** (قیمت ثابت، AI Agent داخلی، VIRALE، شریک رسمی
متا، آمادهٔ MCP). قیف‌ها: کلمهٔ کلیدی + حالت تطبیق + پاداش کیف پول با دلیل `instagram_reward`
+ محدودیت per-user. `PostIdentity` هر املای پست را به شورت‌کد تقلیل می‌دهد؛ `''` هرگز
wildcard نیست.

### ۳.۳ ثبت محصول از اپ (Product Intake)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `ig.intake`، `ig.intake_worker`، `ig.skus`، `ig.stt_http`، `ig.stt_manus`، `ig.translations` |
| جدول | `ig_intake` |
| صفحهٔ ادمین | Registrations (`igbz-ig-intake`) |
| REST | `GET /igbz/v1/intake`، `/intake/form`، `POST /intake/photo`، `/intake/{id}/…` |
| کرون | ۵ دقیقه: `intake_worker->tick()` |
| هوک‌ها | `igbz_intake_*` (created، transcribed، image_ready، product_created، published، …) |

فروشنده عکس/ویدیو/صدا می‌فرستد؛ مسیر خودکار: گفتار→متن (آداپتور STT یا fallback منوس)،
آماده‌سازی عکس، نوشتن توضیحات، ساخت ویدیو، ساخت SKU، ساخت محصول ووکامرس و انتشار (دستی یا
خودکار).

### ۳.۴ کانال VIP

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `vip.access`، `vip.posts`، `vip.media`، `vip.social`، `vip.messages`، `vip.billing` |
| جداول | `vip_plans`، `vip_memberships`، `vip_posts`، `vip_post_likes`، `vip_post_saves`، `vip_post_comments`، `vip_post_views`، `vip_entitlements`، `vip_threads`، `vip_messages` |
| صفحهٔ ادمین | VIP channel (`igbz-vip`) — ۵ تب: Posts / Comments / Inbox / Members / Plans |
| REST | ۳۲ مسیر `/igbz/v1/vip/*` (شامل `save`، `offline`، `saved`) + صفحهٔ اشتراک‌گذاری `/vip/p/{shortcode}` |
| کرون | ۵ دقیقه: `publish_due()` + `expire_due()`؛ ساعتی: `expire_memberships()` |
| هوک‌ها | `igbz_vip_post_published`، `igbz_vip_post_liked`، `igbz_vip_comment_added`، `igbz_vip_message_sent`، `igbz_vip_tip_received`، `igbz_vip_membership_*` |
| مستندات | `DESIGN-VIP.md` |

فید خصوصی شبیه اینستاگرام داخل اپ خودمان: پست عمومی فقط تیزر؛ محتوای واقعی روی استوریج
خودمان با بررسی استحقاق در لحظهٔ سرو (لینک HMAC کوتاه‌عمر). لایک/کامنت/ریپلای/سیو/بازدید/
دایرکت داخل پست + اشتراک/خرید تک‌پست/حمایت مالی. **سطح امنیت سبک** (معادل Close Friends —
عمداً؛ امنیت سنگین فقط LMS). سیاست انقضا تصویب شد: ۷ روز به تعیین مدیر ارشد، سپس حذف از
سرور، با اطلاع‌رسانی به ادمین و مشتری و امکان نگهداری نسخهٔ آفلاین در اپ — `DESIGN-VIP-EXPIRY.md`.

### ۳.۵ استودیوی AI و اعتبار (پل موقت پادو)

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `ai.studio`، `ai.credits` |
| جداول | `ig_ai_credit_ledger` |
| صفحهٔ ادمین | AI studio (`igbz-ai-studio`) |
| REST | `GET /igbz/v1/ai/credits`، `POST /igbz/v1/ai/studio/generate` |
| تنظیمات | `ai.studio.*`، `ai.credits.*` |
| مستندات | `PROMPTS-SEO-PADO.md`، `PROMPT-IG-GROWTH-PADO.md` |

`AiProviderInterface` + `HttpAiStudioProvider` (تصویر/حذف پس‌زمینه/ویدیو/TTS/عکس مدل با
Endpoint تنظیم‌پذیر)، خروجی در مدیالایبری ذخیره می‌شود. اعتبار مشتری با لجر (`ig_ai_credit_ledger`)
— شارژ با درصدی از خرید + خرید نقدی (`purpose=ai_credit_topup`). **این «پل موقت» است؛ هستهٔ
پادو هنوز طراحی نشده** (منتظر دستور کارفرما) و AI Studio بعداً یکی از Skill های آن می‌شود.

### ۳.۶ قرعه‌کشی (Giveaways)

| مورد | جزئیات |
|---|---|
| سرویس | `giveaways` |
| جدول | `ig_giveaways` |
| صفحهٔ ادمین | Giveaways (`igbz-giveaways`) |
| تنظیمات | `giveaway.enabled` |

قرعه‌کشی از **کامنت‌های واقعی** (`ig_funnel_hits`) با `random_int`. «تخفیف در ازای منشن»
به دادهٔ خودمان محدود است (بدون Graph API).

---

## ۴. ماژول `hub` — سایت مادر / دایرکتوری

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `hub.stats`، `hub.directory`، `hub.vip`، `hub.domains`، `hub.blocks`، `hub.signup` |
| جدول‌ها | جدول اختصاصی ندارد — از `tenants`/`tenant_domains`/`tenant_members`/`plans` استفاده می‌کند |
| صفحهٔ ادمین | Master hub (`igbz-hub`) |
| REST | فضای نام `igbz-hub/v1`: `GET /stores`، `/stores/{slug}`، `/plans`، `/landing`، `/blocks`، `/check-slug`، `POST /signup`، `/signup/verify-payment` + ادمین‌ها (`/admin/summary`، `/admin/domains`، `/admin/vip-link`، …) |
| شورت‌کد | `[igbz_store_directory]`، `[igbz_hub_grid]`، `[igbz_hub_stats]`، `[igbz_hub_blocks]` |
| کرون | ساعتی: بازسازی کش آمار/فروشگاه‌های ویژه (بازهٔ `hub.sync_interval`) + `recheck_pending()` تأیید دامنه‌ها |
| تنظیمات | `hub.subdomain_base`، `hub.cname_target`، `hub.vip_link_secret`/`hub.vip_link_ttl`، `hub.mother_origin` (CORS)، `hub.featured_limit`، `hub.hero_title` |
| هوک‌ها | `igbz_hub_allowed_origins`، `igbz_hub_signup_completed` |

**شرح کارکرد:** دایرکتوری عمومی فروشگاه‌ها، ثبت‌نام مستأجر جدید (با تأیید پرداخت)، بلوک‌های
محتوا برای صفحهٔ اصلی سایت مادر، تأیید دامنه و لینک‌های موقت VIP (`/vip/p/{shortcode}` را با
`hub.vip_link_secret` امضا می‌کند). CORS برخلاف nop به `hub.mother_origin` محدود است.

---

## ۵. ماژول `rest_api` — API اپ موبایل

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `api.tokens`، `api.auth`، `api.devices`، `api.google_auth`، `api.push`، `api.notifications` |
| جداول | `api_tokens`، `devices` |
| صفحهٔ ادمین | Mobile API (`igbz-mobile-api`) |
| کرون | روزانه: `prune_expired()` توکن‌ها + `prune_stale()` دستگاه‌ها |
| تنظیمات | `api.jwt_secret`/`api.jwt_ttl`/`api.refresh_ttl`، `api.fcm_project_id`/`api.fcm_service_account`، `api.latest_app_version`/`api.min_app_version`، `api.android_package`/`api.ios_bundle_id`/`api.app_scheme`/`api.universal_link`، `api.device_retention_days` |

**شرح کارکرد:** احراز هویت JWT (HS256) با refresh چرخان و revoke در reset رمز — اصلاح باگ
nop (توکن ۳۰ روزهٔ بدون تازه‌سازی). کاتالوگ، حساب (پروفایل/سفارش‌ها/کیف پول/اقساط/دوره‌ها)،
مدیریت دستگاه و پوش FCM v1.

**کنترلرها (در `src/Modules/RestApi/Controllers/`):**

| کنترلر | مسیرهای اصلی |
|---|---|
| `AuthController` | `/auth/*` (otp، password، refresh، sessions، me) |
| `AccountController` | `/account/*` (profile، orders، wallet، instalments، courses، affiliate، payments) |
| `CatalogController` | `/catalog/*` (categories، products، search-suggest) |
| `DeviceController` | `/devices/*` + `/notifications/send` |
| `ProductIntakeController` | `/intake/*` (فرم، عکس، وضعیت) |
| `StoreAdminController` | `/admin/*` (categories، customers، orders، summary) |
| `VipController` / `VipAdminController` | `/vip/*` (۳۲ مسیر) |
| `FxController` | `/fx/*` (balance، topup، ledger، prices، bills) + وبهوک payout |
| `AiStudioController` | `/ai/*` (credits، studio/generate) |
| `CourierController` | `/courier/*` + `/shipments/*` + `/checkout/cod-pay` |
| `DomainController` | `/domains/*` + `/i18n/config` + `/master-payment/*` |

مجموع: **۱۳۶ فراخوانی `register_rest_route`** — **۱۴۵ مسیر ثبت‌شده** در دو فضای نام
(`igbz/v1` = ۱۳۰ و `igbz-hub/v1` = ۱۵؛ شمارش از سایت زنده با `?rest_route=`).

---

## ۶. ماژول `fx` — واسط پرداخت ارزی

| مورد | جزئیات |
|---|---|
| سرویس‌ها | `fx.wallet`، `fx.accounts`، `fx.rates`، `fx.meter`، `fx.payouts`، `fx.ramp`، `fx.reports` |
| جداول | `fx_wallets`، `fx_ledger`، `fx_rates`، `fx_prices`، `fx_accounts`، `fx_bills` |
| صفحهٔ ادمین | FX payments (`igbz-fx`) — شارژ، نرخ، قیمت‌ها، لجر، قبض‌ها، Buy USDT، گزارش اپراتور |
| REST | `/fx/balance|topup|ledger|prices|bills` + `POST /fx/payout-webhook/{provider}` |
| کرون | روزانه: `billing->run_daily()` (تسویهٔ قبض‌ها) + `ramp->ensure_card_funded()` (اولویت ۲۰) |
| تنظیمات | `fx.fee_percent` (۱۰٪)، `fx.rate_source`/`fx.rate_url`/`fx.rate_manual`، `fx.payout_provider`، `fx.pstnet_*`، `fx.redotpay_*`، `fx.ramp_*` (پیش‌فرض خاموش)، `fx.webhook_token` |
| هوک‌ها | `igbz_register_fx_payout_providers`، `igbz_payment_verified` (شارژ) |
| مستندات | `DESIGN-FX.md` |

**شرح کارکرد:** هزینهٔ ابزارها (منوس/منی‌چت) به‌صورت ارزی — جدا از درآمد ارزی فروشگاه از
مشتری خارجی (آن مال NowPayments است):

1. **شارژ:** ادمین مبلغ دلاری می‌دهد؛ ۱۰٪ کارمزد روی ارز؛ نرخ در `fx_rates` قفل می‌شود؛ با
   درگاه‌های ریالی (`purpose=fx_topup`) پرداخت؛ فقط مبلغ خالص در کیف پول دلاری.
2. **متراژ:** مصرف منوس/منی‌چت به‌ازای هر تسک/hit با ادعای ردیف (idempotent) از اعتبار همان
   مستأجر کسر می‌شود.
3. **قبض ماهانه:** `FxBillingService` قبض می‌سازد؛ کرون روزانه با آداپتور payout تسویه می‌کند؛
   شکست → بازگشت وجه و ماندن `due`.
4. **Payout:** `PstNetPayoutAdapter` (اصلی — کارت شرکت قبرس شمالی، لایهٔ قانونی) +
   `RedotPayPayoutAdapter` (پایلوت) + تسویهٔ دستی + وبهوک (توکن مشترک).
5. **Ramp:** خرید خودکار USDT از صرافی (پیش‌فرض نوبیتکس) وقتی موجودی کارت کم است؛ همه در لجر
   اپراتور (tenant 0).
6. **گزارش اپراتور:** جمع شارژها/کارمزدها/مصرف/بازگشت‌ها/ramp/قبض‌ها در بازهٔ دلخواه.

---

## ۷. جداول دیتابیس (۷۲ جدول — v17) به‌تفکیک زیرسیستم

> شمارش مرجع: `grep -c '$sql[] = "CREATE TABLE' src/Support/Schema.php` و health check.

| زیرسیستم | جدول‌ها |
|---|---|
| هسته/مستأجر | `tenants`، `tenant_domains`، `tenant_members`، `plans`، `logs` |
| کیف پول | `wallet_ledger`، `wallet_balances` |
| اشتراک | `subscriptions` |
| BNPL | `bnpl_credit`، `bnpl_contracts`، `bnpl_installments` |
| همکاری فروش | `affiliates`، `affiliate_commissions`، `referral_clicks` |
| LMS | `courses`، `lessons`، `enrollments`، `lesson_progress`، `quizzes`، `quiz_attempts` |
| پرداخت/OTP | `payments`، `otp_codes` |
| مارکت‌پلیس | `marketplace_links`، `ig_marketplace_sync`، `ig_category_mapping` |
| لجستیک/پیک | `ig_shipments`، `ig_couriers`، `ig_label_groups`، `ig_label_group_items`، `ig_cod_payments`، `ig_courier_routes`، `ig_courier_tracking`، `ig_courier_chat` |
| درگاه مرکزی | `ig_master_payments`، `ig_master_disputes`، `ig_master_withdrawals`، `ig_master_agreements` |
| دامنه/وب‌پرزنس | `ig_domains`، `ig_domain_orders`، `ig_web_presence` |
| قانونی | `ig_nid_verifications`، `ig_legal_agreements` |
| گیمیفیکیشن | `ig_abandoned_carts` |
| اینستاگرام | `ig_accounts`، `ig_content`، `ig_insights`، `ig_funnels`، `ig_subscribers`، `ig_funnel_hits`، `ig_intake` |
| VIP | `vip_plans`، `vip_memberships`، `vip_posts`، `vip_post_likes`، `vip_post_saves`، `vip_post_comments`، `vip_post_views`، `vip_entitlements`، `vip_threads`، `vip_messages` |
| AI/قرعه‌کشی | `ig_ai_credit_ledger`، `ig_giveaways` |
| اپ | `api_tokens`، `devices` |
| FX | `fx_wallets`، `fx_ledger`، `fx_rates`، `fx_prices`، `fx_accounts`، `fx_bills` |

**بدون `tenant_id` (عمداً):** `tenants`، `tenant_domains`، `tenant_members`، `plans`، `logs`،
`lesson_progress`، `vip_post_likes`، `vip_post_saves`، `vip_post_views`، `fx_rates`، `fx_prices`،
`ig_label_group_items`، `ig_courier_tracking`، `ig_courier_chat` (whitelist در
`tests/SchemaTest.php` — بدون تأیید آگاهانه چیزی از آن خارج نشود).

---

## ۸. کرون‌ها (خلاصه)

| قلاب | هر چند وقت | کارها |
|---|---|---|
| `igbz_cron_five_minutes` | ۵ دقیقه | زمان‌بندی و انتشار محتوا، intake worker، انتشار/انقضای پست‌های VIP، worker مارکت‌پلیس |
| `igbz_cron_hourly` | ساعتی | مرور BNPL (معوق + یادآوری)، reconcile اینسایت‌ها، انقضای عضویت VIP، کش هاب + تأیید دامنه، جاروی سبد رهاشده |
| `igbz_cron_daily` | روزانه | تمدید پلن‌ها، پورسانت‌های معلق، جمع‌آوری اینسایت‌ها، آزادسازی escrow، تسویهٔ قبض‌های FX + تأمین کارت (ramp)، پاک‌سازی توکن‌ها/دستگاه‌ها |

---

## ۹. صفحات ادمین (۲۹ صفحه — منوی یکپارچهٔ IGBZ)

همهٔ صفحات capability-gated هستند و از `Menu::add()` ثبت می‌شوند (رفع باگ nop که ~۲۶
کنترلر فقط با تایپ URL باز می‌شدند):

**همیشه:** Status (`igbz`)، Settings (`igbz-settings`)
**multitenant:** Tenants، Wallet، Plans، Instalments، Affiliates، Courses، Payments، Master
payment، Logistics، Marketplaces، SEO & ads، Translator، Gamification، Domain
**fx:** FX payments
**instagram:** IG Accounts، IG Content، DM Funnels، IG Subscribers، IG Insights، Registrations،
VIP channel، AI studio، Giveaways
**hub:** Master hub
**rest_api:** Mobile API

---

## ۱۰. شورت‌کدها

| شورت‌کد | ویژگی‌ها |
|---|---|
| `[igbz_courses]` | `limit` (۱۲)، `level`، `columns` (۳) |
| `[igbz_course]` | `slug` — fallback به `?igbz_course=<slug>` |
| `[igbz_plans]` | — |
| `[igbz_bnpl_calculator]` | — |
| `[igbz_wallet_balance]` | — |
| `[igbz_otp_login]` | — |
| `[igbz_store_directory]` / `[igbz_hub_grid]` / `[igbz_hub_stats]` / `[igbz_hub_blocks]` | دایرکتوری هاب |

---

## ۱۱. سرویس‌های ثبت‌شده در کانتینر (service ids)

| ماژول | شناسه‌ها |
|---|---|
| core | `settings`، `logger`، `db`، `http`، `tenancy` |
| multitenant | `tenants`، `wallet`، `plans`، `bnpl.providers`، `bnpl`، `affiliate`، `lms`، `lms.vod`، `payments`، `otp`، `legal.nid`، `marketplace`، `marketplace.sync`، `marketplace.mappings`، `marketplace.basalam`، `logistics`، `logistics.courier`، `logistics.labels`، `master.payment`، `domain`، `webpresence`، `seo`، `translation`، `translation.adapter`، `i18n`، `gamification`، `gamification.carts` |
| instagram | `ig.prompts`، `ig.manus_client`، `ig.manus`، `ig.scheduler`، `ig.insights`، `ig.manychat`، `ig.subscribers`، `ig.funnels`، `ig.intake`، `ig.credentials`، `ig.skus`، `ig.stt_http`، `ig.stt_manus`، `ig.translations`، `ai.studio`، `ai.credits`، `giveaways`، `vip.access`، `vip.media`، `vip.posts`، `vip.social`، `vip.messages`، `vip.billing` |
| hub | `hub.stats`، `hub.directory`، `hub.vip`، `hub.domains`، `hub.blocks`، `hub.signup` |
| rest_api | `api.tokens`، `api.auth`، `api.devices`، `api.google_auth`، `api.push`، `api.notifications` |
| fx | `fx.wallet`، `fx.accounts`، `fx.rates`، `fx.meter`، `fx.payouts`، `fx.ramp`، `fx.reports` |

سرویس‌های یک ماژول فقط وقتی ماژول روشن است وجود دارند — فراخوانی بین‌ماژولی را با
`igbz()->has( '…' )` محافظت کنید.

---

## ۱۲. تست‌ها و لینت

- **۱۲۰۹ اظهارنظر در ۲۳ کیس** (`bash _devenv/test.sh` — بدون نیاز به سایت). کیس‌ها:
  Crypto، Settings، Schema (tenant scoping + dbDelta + whitelist)، Jwt، BnplQuote، Money،
  Gateway، Modules، PromptBuilder، Upsert، CronSchedule، AccountCredentials،
  PublishVerification، FunnelDelivery، ProductIntake، DirectMessage، VipChannel، Lms،
  PostIdentity، **FxTest**، **PhasesTest**، **Phases2Test** (escrow، تحویل پیک/COD، برچسب،
  مسیریابی)، **IpgAdaptersTest** (گیت تنظیمات، RSA، SOAP).
- **۲۳۵ فایل PHP بدون خطای syntax.**
- قالب ترجمه با `bash _devenv/makepot.sh` بازسازی می‌شود؛ `--check` فقط کهنگی را گزارش می‌کند.
- هر کیس جدید باید `igbz_test_reset_settings()` را صدا بزند و در `$cases` در
  `tests/run.php` ثبت شود (کشف خودکار وجود ندارد).

---

## ۱۳. نقشهٔ اسناد مرتبط

| سند | موضوع |
|---|---|
| `PROJECT-STATE.md` | وضعیت کلی، قانون‌ها، تصمیم‌های باز |
| `HANDOFF-PROMPT.md` | پرامپت شروع چت جدید |
| `API-KEYS.md` | کلیدهای موردنیاز (۲۹ کلید) |
| `DESIGN-PHASES-6-14.md` | خلاصهٔ طراحی فازهای ۶–۱۴ |
| `DESIGN-PHASE6-PAYMENTS.md` | درگاه‌ها، BNPL، درگاه مرکزی |
| `DESIGN-PHASE7-COURIER.md` | اپ پیک |
| `DESIGN-PHASE9-MARKETPLACES.md` | مارکت‌پلیس‌ها |
| `DESIGN-PHASE10-SEO.md` | سئو |
| `DESIGN-PHASE12-INTERNATIONAL.md` | رمزارز + ترجمه |
| `DESIGN-DOMAIN.md` | سرویس دامنه |
| `DESIGN-LEGAL-AUTH.md` | احراز قانونی |
| `DESIGN-FX.md` | واسط پرداخت ارزی |
| `DESIGN-VIP.md` | کانال VIP |
| `DESIGN-APPS-ROADMAP.md` | نقشهٔ راه اپ‌های فلاتر |
| `PROMPTS-SEO-PADO.md` / `PROMPT-IG-GROWTH-PADO.md` | پرامپت‌های پادو |
| `_devenv/AGENT-BRIEF.md` | جزئیات فنی عمیق و تله‌ها |
| `igbz-suite/README.md` | مرجع فنی افزونه (نصب/تنظیمات/REST) |
| `vira/README.md` | **ویرا** — ابزار عاملِ خودمان (نسخهٔ ۰.۹.۷): نصب، هاب پرووایدر، تونل، پراکسی، لاگ‌ها، ابزارها، MCP، معماری، تست |
| `vira/DESIGN-PROVIDER-HUB.md` | هاب پرووایدر و عیب‌یاب چهارپله |
| `vira/DESIGN-UI-PARITY.md` | تاریخچهٔ رابط ویرا و باگ‌هایی که سر راه پیدا شد |
| `DESIGN-DEPLOY-VIRA.md` | استقرار ویرا روی سرور (پیشنهاد، پیاده‌نشده) |
