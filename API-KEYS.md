# API-KEYS — کلیدهای موردنیاز ادمین فروشگاه

**آخرین به‌روزرسانی:** ۱۴۰۵/۰۶/۱۱ (2026-09-01)

مرجع کلیدها و توکن‌های راه‌اندازی IGBZ Suite است. تصمیم جاری ADR-0004 سه اصل مهم دارد:

- کلید مرکزی Zernio راز عملیاتی IGBZ است، فقط در secret store بک‌اند نگهداری می‌شود و هرگز
  در پنل فروشگاه، پادو، لاگ یا مرورگر قرار نمی‌گیرد؛
- کلیدهای استنتاج پادو (Groq، OpenRouter، NaraRouter و هر ارائه‌دهندهٔ دیگر) طبق `ADR-0005` در
  **مخزن کلید پنل** (`pado.ai.key_vault`) رمزشده ذخیره می‌شوند و لایهٔ ارائه‌دهنده فقط
  `keyRef` می‌گیرد؛ مقدار واقعی فقط لبهٔ connector در لحظهٔ فراخوانی و یک‌بار حل می‌شود.
  کلیدِ حساب مستقل فروشگاه همچنان runtime می‌آید و در IGBZ ذخیره نمی‌شود؛
- فیلدهای فعلی Manus، ManyChat و ChatPlace legacy هستند و تا migration نباید با کلید واقعی
  پر شوند. کانال Instagram مبتنی بر session در Agent Reach نیز از معماری هدف حذف شده است.

سایر کلیدهای پلتفرم در پنل تنظیمات یا secret store عملیاتی وارد می‌شوند. صفحهٔ وضعیت باید
پس از migration تفاوت «راز مرکزی»، «credential مستقل بیرونی فروشگاه» و «کلید legacy ممنوع»
را صریح نشان دهد؛ کد فعلی هنوز این معماری را پیاده نکرده است.

> **قبل از وارد کردن هر کلید:** `IGBZ_ENCRYPTION_KEY` را در `wp-config.php` بگذارید و از آن
> پشتیبان بگیرید. ⚠️ ممیزی ۱۴۰۵/۰۶/۰۵ نشان داد ادعای قبلی «همهٔ کلیدها رمز می‌شوند» درست
> نیست: تنها کلیدهای حاضر در `Settings::SECRETS` با AES-256-GCM محافظت می‌شوند و ۲۲ فیلد
> رمز پنل از این فهرست جا افتاده‌اند. تا رفع و مهاجرت این شکاف، کلید واقعی در آن ۲۲ فیلد
> وارد نشود. چرخش salts یا `IGBZ_ENCRYPTION_KEY` نیز بدون برنامهٔ مهاجرت می‌تواند کلیدهای
> رمز‌شدهٔ موجود را ناخوانا کند.

---

## ۱. کلیدهایی که باید از بیرون تهیه کنید

| # | کلید تنظیمات | سرویس | الزامی؟ |
|---|---|---|---|
| هدف-۱ | نام تنظیم نهایی نشده؛ secret store مرکزی | Zernio | ✅ برای social plane؛ یک کلید مرکزی محدود با profile جدا، نه کلید پنل فروشگاه |
| هدف-۲ | `pado.ai.key_vault` (رمزشده در پنل، نه گزینهٔ ساده) | Groq / OpenRouter (و هر ارائه‌دهندهٔ `api_provider`) | ✅ برای inference پادو؛ کلید پنل فقط `keyRef`؛ کلید حساب مستقل فروشگاه runtime و بیرون از IGBZ |
| ۱ | `manus.api_key` | Manus | ❌ legacy؛ ورود کلید تازه ممنوع و حذف در migration |
| ۲ | `manychat.api_key` | ManyChat | ❌ legacy؛ ورود کلید تازه ممنوع و حذف در migration |
| ۳ | `payments.zarinpal.merchant_id` | زرین‌پال (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۴ | `payments.idpay.api_key` | آیدی‌پی (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۵ | `payments.nextpay.api_key` | نکست‌پی (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۶ | `payments.payir.api_key` | پی.آی.آر (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۷ | `otp.kavenegar.api_key` | کاوه‌نگار (ایرانی) | اختیاری — ورود با پیامک |
| ۸ | `otp.smsir.api_key` | اس‌ام‌اس.آی‌آر (ایرانی) | اختیاری — ورود با پیامک |
| ۹ | `api.fcm_project_id` + `api.fcm_service_account` | Firebase (آمریکا) | اختیاری — پوش اپ (رایگان) |
| ۱۰ | `stt.api_key` | بستگی به فروشنده | اختیاری |
| ۱۱ | `dm.custom.api_key` | ارائه‌دهندهٔ DM سفارشی | اختیاری |
| ۱۲ | `chatplace.api_key` | ChatPlace | ❌ legacy؛ ورود کلید تازه ممنوع و حذف در migration |
| ۱۳ | `payments.sadad.merchant_id` + `.terminal_id` + `.private_key` | سداد (بانک ملی) | ⚠️ حداقل یکی از درگاه‌های مستقیم بانکی (پس از دامنهٔ تأییدشده + انماد) |
| ۱۴ | `payments.asanpardakht.api_key` + `.merchant_config` | آسان‌پرداخت | ⚠️ همان‌طور بالا |
| ۱۵ | `payments.parsian.login_account` | پارسیان | ⚠️ همان‌طور بالا |
| ۱۶ | `payments.irankish.api_key` + `.terminal_id` | ایران‌کیش | ⚠️ همان‌طور بالا |
| ۱۷ | `payments.mellat.username` + `.password` + `.terminal_id` | ملت | ⚠️ همان‌طور بالا |
| ۱۸ | `payments.saman.terminal_id` + `.public_key` + `.private_key` | سامان | ⚠️ همان‌طور بالا |
| ۱۹ | `payments.pasargad.merchant_code` + `.terminal_code` + `.private_key` | پاسارگاد | ⚠️ همان‌طور بالا |
| ۲۰ | `payments.sepehr.api_key` + `.terminal_id` | سپهر (سامانهٔ بانک‌ها) | ⚠️ همان‌طور بالا |
| ۲۱ | `legal.shahkar_api_key` | شاهکار (همراه اول) | ⚠️ احراز کد ملی — بدون آن سرویس قفل می‌ماند |
| ۲۲ | `logistics.tapin_api_key` / `logistics.postex_api_key` | تاپین / پستکس | اختیاری — اتصال لجستیک |
| ۲۳ | `marketplace.digikala_api_key` / `marketplace.divar_token` / `basalam.api_key` | دیجی‌کالا / دیوار / باسلام | اختیاری — همگام‌سازی مارکت‌پلیس |
| ۲۴ | `seo.triboon_api_key` | تریبون | اختیاری — شبکهٔ تبلیغاتی |
| ۲۵ | `translation.api_key` | سرویس مترجم انتخابی | اختیاری — ترجمهٔ خودکار |
| ۲۶ | `domain.provider_api_key` | رجیسترار دامنه | اختیاری — ثبت/انتقال دامنه |
| ۲۷ | `fx.pstnet_api_key` + `.card_id` | PST.NET (کارت قبرس شمالی) | ⚠️ آداپتور اصلی تسویهٔ ارزی |
| ۲۸ | `fx.redotpay_api_key` + `.card_id` | RedotPay | ⚠️ آداپتور دوم (پایلوت) |
| ۲۹ | `fx.ramp_api_key` | نوبیتکس (یا صرافی دیگر) | اختیاری — خرید خودکار USDT (`fx.ramp_enabled` پیش‌فرض خاموش) |

---

## ۲. سرویس‌های بیرونی پولی و مالک هزینه

> قیمت و دسترس‌پذیری باید پیش از خرید از مرجع رسمی همان روز دوباره کنترل شود. این سند عدد
> ثابت یا تضمین صلاحیت جغرافیایی ارائه نمی‌کند.

| سرویس | مالک حساب/هزینه | محل credential | کاربرد و وضعیت |
|---|---|---|---|
| Zernio | حساب مرکزی IGBZ؛ سهم هر profile در اشتراک فروشگاه | secret store بک‌اند، با دسترسی محدود به profile | تنها provider اجتماعی هدف؛ تا آزمون دو profile و callback امن production نیست |
| Groq / OpenRouter | همان مدیر/فروشگاه | `pado.ai.key_vault` (رمزشده، keyRef) + سکرت‌های تست `GROQ_API_KEY` / `OPENROUTER_API_KEY` | providerهای هدف inference طبق `ADR-0005`؛ فعال‌سازی مشروط به benchmark فارسی و گیت‌های `enabled`/`benchmark_passed`/`geo_eligible` |
| Smoke test فعلی OpenRouter/Groq | فقط تست زندهٔ workflow | همان سکرت‌های بالا | طبق خروجی اجرای سوم: OpenRouter با `openrouter/free` و Groq با `qwen/qwen3.6-27b` سنجیده شود؛ تغییر seed تولیدی جداگانه و پس از benchmark فارسی است |
| NaraRouter / byNara Router | فعلاً فقط تست provider، نه تولید | سکرت GitHub Actions با نام `NARAROUTER_API_KEY`؛ در صورت ثبت دستی تولیدی: `pado.ai.key_vault` | OpenAI-compatible روی `https://router.bynara.id/v1`؛ smoke test ناراروتر با `glm-5.3-flash-free` و سپس `agnes-2.5-flash` سبز شده است؛ تا قبل از benchmark فارسی، ZDR/حریم خصوصی و گیت‌های `enabled`/`benchmark_passed`/`geo_eligible` نباید مسیر تولیدی بگیرد |
| PST.NET | عملیات مالی IGBZ مطابق قرارداد | secret store پلتفرم | آداپتور تسویهٔ ارزی موجود؛ آزمون قرارداد زنده لازم است |
| RedotPay | عملیات مالی IGBZ مطابق قرارداد | secret store پلتفرم | گزینهٔ پایلوت تسویه؛ آزمون قرارداد زنده لازم است |
| مترجم/دامنه/STT | طبق provider منتخب هر حوزه | تنظیم امن متناسب با مالک | هنوز به آزمون قرارداد و تصمیم همان حوزه وابسته است |

### ۲.۱ کلیدهای legacy اجتماعی

`manus.api_key`، `manus.webhook_token`، `manychat.api_key`،
`manychat.webhook_token` و `chatplace.api_key` فقط نشان‌دهندهٔ کد موجود پیش از ADR-0004
هستند. Manus، ManyChat و ChatPlace از معماری هدف حذف شده‌اند؛ کلید تازه وارد نشود، کلید
قدیمی پس از برنامهٔ migration ابطال شود و فیلد/endpoint آن‌ها در فاز کد مصوب حذف گردد.
Ayrshare و کانال Instagram مبتنی بر session در Agent Reach نیز نباید credential جدید بگیرند.

**نکته:** Firebase (پوش اپ) هم غیر ایرانی است ولی رایگان — به همین دلیل در این جدول نیست.
نوبیتکس (`fx.ramp_api_key`) ایرانی است (خرید USDT) و در بخش ۱ آمده؛ فقط `fx.ramp_enabled`
پیش‌فرض خاموش است چون برداشت در صرافی‌های ایرانی تأیید جدا می‌خواهد.

---

## ۳. کلیدهایی که نمی‌خرید — خودتان تولید و امن نگه می‌دارید

| کلید | کجا | چرا |
|---|---|---|
| `IGBZ_ENCRYPTION_KEY` | `wp-config.php` — قبل از وارد کردن هر کلید | رمزنگاری همهٔ کلیدهای بالا با AES-256-GCM؛ بدون آن، چرخش salts همهٔ کلیدها را ناخوانا می‌کند |
| `manus.webhook_token` | تنظیمات legacy Manus | منسوخ؛ پس از migration ابطال و حذف شود |
| `manychat.webhook_token` | تنظیمات legacy ManyChat | منسوخ؛ پس از migration ابطال و حذف شود |
| `fx.webhook_token` | تنظیمات FX | shared secret وبهوک تسویهٔ ارزی (`/igbz/v1/fx/payout-webhook/{provider}`) |
| `api.jwt_secret` | تنظیمات Mobile API | امضای توکن‌های JWT |
| `lms.video_hmac_secret` | تنظیمات LMS | امضای لینک‌های ویدیوی مقید به زمان |
| `lms.vod_secure_key` | تنظیمات LMS | امضای لینک‌های VOD (الگوی آروان) |
| `hub.vip_link_secret` | تنظیمات Hub | امضای لینک‌های موقت VIP |
| `legal.nid` | تنظیمات Legal | کلید داخلی قفل احراز کد ملی — فقط مدیر ارشد ثبت می‌کند |

---

## ۴. اتصال هر فروشگاه به Instagram در Zernio

مدیر فروشگاه از جریان اتصال رسمی Zernio استفاده می‌کند، اما کلید API جداگانه در پنل وارد
نمی‌کند. بک‌اند IGBZ فقط نگاشت کنترل‌شدهٔ زیر را نگه می‌دارد:

```text
blog_id / tenant_id ↔ zernio_profile_id ↔ instagram_account_reference
```

هر درخواست بک‌اند باید profile را از هویت احرازشده استخراج کند؛ پذیرش profile دلخواه از
کلاینت ممنوع است. کلید مرکزی Zernio باید محدود، قابل‌چرخش و خارج از دیتابیس عمومی/وردپرس
باشد. مقدار token اتصال Instagram نیز نباید به پادو، مدیر فروشگاه یا مرورگر بازگردانده شود.
طراحی حذف اتصال و حذف داده در `DESIGN-INSTAGRAM-PADO-ZERNIO.md` ثبت شده است.

## ۴.۵ credential مستقل inference پادو

طبق `ADR-0005` (جانشین `ADR-0004 §۴`) هر فروشگاه حساب و صورتحساب مستقل با ارائه‌دهندهٔ
انتخابی خود (پیش‌فرض Groq/OpenRouter؛ NaraRouter فعلاً فقط گزینهٔ تستی) دارد. دو مسیر کلید از هم جدا هستند:

- **کلید پنل** (ورودی ادمین در صفحهٔ «ثبت کلیدها») فقط رمزشده در مخزن `pado.ai.key_vault`
  می‌نشیند؛ لایهٔ ارائه‌دهنده فقط `keyRef` می‌گیرد و مقدار واقعی را لبهٔ connector در لحظهٔ
  فراخوانی و یک‌بار حل می‌کند — هرگز در لاگ، DOM یا گزینهٔ ساده.
- **کلید حساب مستقل فروشگاه** runtime در `AiRequest` می‌آید و در IGBZ ذخیره یا proxy نمی‌شود.

بک‌اند IGBZ فقط شناسهٔ اجرای Playbook، بودجهٔ مجاز، usage/cost report بدون secret و نتیجهٔ
schemaدار را ثبت می‌کند. افزودن/حذف/جابه‌جایی ارائه‌دهنده فقط یک رکورد `api_provider`
(فیلد `protocol`) است، نه کد جدید.

`pado.api_key` موجود در کد، قرارداد legacy اتصال PadoGateway است و credential هیچ
ارائه‌دهندهٔ استنتاجی نیست. تعیین migration/حذف آن و جایگزینی با احراز connector کوتاه‌عمر
نیازمند فاز کد مصوب است. n8n و ویرا هیچ‌یک این credential را مصرف نمی‌کنند.

---

## ۵. ممیزی نگهداشت رازها — ۱۴۰۵/۰۶/۰۵

مقایسهٔ ماشینی ۳۶ فیلد `type=password` در `SettingsPage` با ۱۷ کلید موجود در
`Settings::SECRETS` نشان داد فقط ۱۴ فیلد میان دو مجموعه مشترک‌اند و **۲۲ فیلد رمز** در
فهرست رمزنگاری نیستند. سه عضو دیگر رجیستری (`manus.webhook_token`،
`manychat.webhook_token` و `pado.api_key`) در این شمارش فیلدهای password نیستند. مقادیر
۲۲ فیلد جاافتاده اکنون
مانند گزینهٔ عادی در `igbz_settings` ذخیره می‌شوند و صفحهٔ تنظیمات نیز به‌جای مقدار ماسک‌شده،
مقدار واقعی را در `value` ورودی password قرار می‌دهد.

فهرست دقیقِ جاافتاده:

```text
bnpl.snapppay.password
bnpl.tara.api_key
domain.provider_api_key
fx.pstnet_api_key
fx.ramp_api_key
fx.redotpay_api_key
fx.webhook_token
legal.shahkar_api_key
logistics.postex_api_key
logistics.tapin_api_key
marketplace.digikala_api_key
marketplace.divar_token
nowpayments.api_key
payments.asanpardakht.api_key
payments.httppsp.api_key
payments.irankish.api_key
payments.mellat.password
payments.sepehr.api_key
paypal.client_id
seo.triboon_api_key
stripe.secret_key
translation.api_key
```

### اقدام اجباری پیش از ورود کلید واقعی

۱. یک رجیستری واحد برای تمام رازها ساخته شود و تعریف فیلد password بدون عضویت در آن با
   تست خودکار شکست بخورد.
۲. داده‌های plaintext موجود با مهاجرت idempotent رمز شوند؛ مهاجرت باید payloadهای قدیمی
   رمز‌شده و مقدار خالی/ماسک را درست تشخیص دهد و قابلیت بازگشت امن داشته باشد.
۳. هیچ راز موجود دوباره به HTML برنگردد؛ فرم فقط placeholder ماسک‌شده نشان دهد و خالی
   فرستادن فرم مقدار قبلی را نگه دارد.
۴. کلید رمزنگاری تولید از WordPress salts جدا، نسخه‌دار و قابل چرخش باشد؛ پشتیبان‌گیری،
   بازیابی و rotation آزموده شود. در تولید، استفاده از secret manager و کلیدهای کوتاه‌عمر
   بر ذخیرهٔ همهٔ رازها در یک option ترجیح دارد.
۵. همهٔ لاگ‌ها، URLها، بدنه‌های خطا و export/backup برای نشت این کلیدها اسکن شوند؛ کلیدی
   که احتمال می‌رود قبلاً ذخیره یا نمایش داده شده است باید پس از اصلاح rotate شود.

### وضعیت اجرا — ۱۴۰۵/۰۶/۰۶ (فاز ۰۵ ✅)

هر پنج اقدام بالا انجام شد: (۱) رجیستری واحد ۴۰ عضوی و تست `DriftGuardTest` که فیلد password
خارج رجیستری را قرمز می‌کند؛ (۲) مهاجرت idempotent نسخهٔ دیتابیس ۲۰ (`Settings::encrypt_legacy_secrets`)
که payload رمز‌شده، خالی و ماسک را درست تشخیص می‌دهد و مسیر خواندن هرگز نشکست؛ (۳) فرم فقط ماسک
نشان می‌دهد (`Settings::masked`) و ارسال ماسک/خالی مقدار قبلی را نگه می‌دارد؛ (۴) چرخش با
`Settings::rotate_secret()` و payload نسخه‌دار `igbz1:`؛ در تولید همچنان secret manager ترجیح دارد؛
(۵) اسکن نشت با تست‌های `SecretsTest` و تست ویژوال ۱۶ صفحه (`visual-testing/PHASE-05-REPORT.md`)،
۰ نشت. کلیدهایی که پیش از این اصلاح در محیط زنده وارد شده باشند باید پس از ارتقا rotate شوند.

مبنای امنیتی: OWASP برای رازها رمزنگاری در حالت سکون، دسترسی حداقلی، قابلیت ابطال/چرخش و
ممنوعیت ثبت plaintext را توصیه می‌کند:
<https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html>.
وردپرس نیز اصل «هیچ داده‌ای قابل اعتماد نیست» و اعتبارسنجی/خروجی امن را مبنا می‌داند:
<https://developer.wordpress.org/apis/security/>.
