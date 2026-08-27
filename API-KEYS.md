# API-KEYS — کلیدهای موردنیاز ادمین فروشگاه

**آخرین به‌روزرسانی:** ۱۴۰۵/۰۶/۰۵ (2026-08-27)

مرجع کلیدها و توکن‌هایی که برای راه‌اندازی IGBZ Suite لازم است. جای ورود همهٔ کلیدها:
پنل → **IGBZ → Settings** (تب‌های Payments / Manus / ManyChat / OTP / Mobile API / LMS / Hub /
FX / Logistics / Marketplaces / SEO / Translator / Legal / Domain) و **IGBZ → Instagram →
Accounts**. صفحهٔ **IGBZ → Status** دقیقاً نشان می‌دهد کدام کلید گم است.

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
| ۱ | `manus.api_key` | Manus | ✅ ماژول اینستاگرام |
| ۲ | `manychat.api_key` | ManyChat | ⚠️ فقط مسیر API؛ وبهوک بدون آن کار می‌کند |
| ۳ | `payments.zarinpal.merchant_id` | زرین‌پال (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۴ | `payments.idpay.api_key` | آیدی‌پی (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۵ | `payments.nextpay.api_key` | نکست‌پی (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۶ | `payments.payir.api_key` | پی.آی.آر (ایرانی) | ⚠️ حداقل یکی از درگاه‌ها |
| ۷ | `otp.kavenegar.api_key` | کاوه‌نگار (ایرانی) | اختیاری — ورود با پیامک |
| ۸ | `otp.smsir.api_key` | اس‌ام‌اس.آی‌آر (ایرانی) | اختیاری — ورود با پیامک |
| ۹ | `api.fcm_project_id` + `api.fcm_service_account` | Firebase (آمریکا) | اختیاری — پوش اپ (رایگان) |
| ۱۰ | `stt.api_key` | بستگی به فروشنده | اختیاری |
| ۱۱ | `dm.custom.api_key` | ارائه‌دهندهٔ DM سفارشی | اختیاری |
| ۱۲ | `chatplace.api_key` | ChatPlace (بین‌المللی) | ⚠️ برای `dm.provider = chatplace` |
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

## ۲. کلیدهای غیر ایرانی و غیر رایگان

این جدول فقط کلیدهایی است که سرویس‌شان ایرانی نیست و رایگان هم نیست (یا رایگان بودن‌شان
شرطی است). در جدول بخش ۱، این کلیدها هستند: `manus.api_key`، `manychat.api_key`،
`stt.api_key`، `chatplace.api_key`، `fx.pstnet_*`، `fx.redotpay_*` و (بسته به انتخاب)
`translation.api_key` و `domain.provider_api_key`. بقیه یا ایرانی‌اند (زرین‌پال، آیدی‌پی،
نکست‌پی، پی.آی.آر، درگاه‌های مستقیم بانکی، شاهکار، نوبیتکس، کاوه‌نگار، اس‌ام‌اس.آی‌آر) یا
رایگان (Firebase) یا خودتولید (بخش ۳).

> قیمت‌ها تقریبی‌اند (اوت ۲۰۲۶) و ممکن است تغییر کنند؛ قبل از خرید از وب‌سایت خود سرویس چک کنید.

| کلید | سرویس (کشور) | هزینه | بخش استفاده | توضیح |
|---|---|---|---|---|
| `manus.api_key` | Manus (آمریکا — متا؛ پایگاه سنگاپور) | پولی — پلن رایگان محدود دارد؛ پلن‌های پولی از حدود ۲۰ دلار/ماه | ماژول اینستاگرام — `ManusService` | تقریباً تمام اتوماسیون اینستاگرام روی Manus می‌چرخد: تحقیق نیچ و ترند، طراحی گرافیک، ساخت ریلز و ویدیو، نوشتن کپشن و انتخاب هشتگ، و انتشار/زمان‌بندی خودکار پست در ساعت‌های اوج. در مسیر ثبت محصول از اپ هم همین کلید است: آماده‌سازی عکس فروشنده، نوشتن توضیحات، ساخت ویدیو از روی متن، و fallback تبدیل گفتار به متن. بدون آن ماژول اینستاگرام عملاً کار نمی‌کند. می‌تواند سراسری باشد یا به‌ازای هر اکانت (حالت `own`). |
| `manychat.api_key` | ManyChat (آمریکا) | پولی — دسترسی API روی پلن‌های پولی (از حدود ۱۷ تا ۳۹ دلار/ماه بسته به پلن و تعداد مخاطب) | قیف کامنت→دایرکت — `ManyChatClient` | مسیر API منیچت برای قیف‌های «کامنت بگذار تا لینک بدهم»: خواندن پروفایل مشترک، ارسال پیام و فلو، تنظیم فیلد سفارشی و تگ. مسیر وبهوک (External Request) بدون این کلید هم کار می‌کند؛ این کلید وقتی لازم است که تحویل پاسخ از طریق API انجام و تأیید شود. چون کلید منیچت به یک پیج اینستاگرام گره می‌خورد، هر اکانت جداگانه کلید خودش را می‌خواهد (یا حالت trial با سهمیهٔ محدود). |
| `stt.api_key` | وابسته به انتخاب فروشنده (مثلاً OpenAI Whisper — آمریکا؛ یا سرویس خودمیزبان/ایرانی) | شرطی — بعضی فروشنده‌ها پولی | ثبت محصول — تبدیل گفتار→متن | فقط وقتی لازم است که فروشندهٔ گفتاربه‌متنِ دلخواهتان غیر از fallback پیش‌فرض (Manus) باشد. اگر OpenAI Whisper API یا سرویس پولی خارجی دیگری را انتخاب کنید، پولی و غیر ایرانی است؛ با مدل خودمیزبان یا یک سرویس ایرانی، رایگان/داخلی می‌شود. اینترفیس `SpeechToTextInterface` با تغییر endpoint/کلید در تنظیمات، فروشنده را عوض می‌کند و کد تغییر نمی‌کند. |
| `chatplace.api_key` | ChatPlace (بین‌المللی — شریک رسمی متا) | پولی — ثابت حدود ۲۰ دلار/ماه در هر حجم | قیف کامنت→دایرکت — `ChatPlaceClient` | انتخاب فعلی برای قیف‌های اینستاگرام (جایگزین منی‌چت که غیرفعال به‌عنوان fallback می‌ماند): پاسخ هوشمند با AI Agent داخلی، CRM، تحقیق ترند (VIRALE)، اسکریپت ریلز و مسیر MCP برای اتصال به پادو در آینده. سوئیچ با `dm.provider = manychat|chatplace`. |
| `fx.pstnet_api_key` + `fx.pstnet_card_id` | PST.NET (کارت مجازی — شرکت ثبت‌شدهٔ قبرس شمالی) | پولی — کارمزد کارت و تراکنش | تسویهٔ ارزی — `PstNetPayoutAdapter` | آداپتور اصلی پرداخت خودکار قبض‌های ارزی (منوس/منی‌چت): صدور/شارژ/CVV/موجودی کارت. لایهٔ قانونی (شرکت قبرسی) حل‌شده است؛ `fx.payout_provider = pstnet` پیش‌فرض. |
| `fx.redotpay_api_key` + `fx.redotpay_card_id` | RedotPay | پولی | تسویهٔ ارزی — `RedotPayPayoutAdapter` | گزینهٔ دوم (پایلوت)؛ تعویض فقط با تغییر `fx.payout_provider`. اگر هر دو API خوابیدند، دکمهٔ تسویهٔ دستی و وبهوک `fx.payout-webhook` ترمز ایمنی همیشگی‌اند. |

**نکته:** Firebase (پوش اپ) هم غیر ایرانی است ولی رایگان — به همین دلیل در این جدول نیست.
نوبیتکس (`fx.ramp_api_key`) ایرانی است (خرید USDT) و در بخش ۱ آمده؛ فقط `fx.ramp_enabled`
پیش‌فرض خاموش است چون برداشت در صرافی‌های ایرانی تأیید جدا می‌خواهد.

---

## ۳. کلیدهایی که نمی‌خرید — خودتان تولید و امن نگه می‌دارید

| کلید | کجا | چرا |
|---|---|---|
| `IGBZ_ENCRYPTION_KEY` | `wp-config.php` — قبل از وارد کردن هر کلید | رمزنگاری همهٔ کلیدهای بالا با AES-256-GCM؛ بدون آن، چرخش salts همهٔ کلیدها را ناخوانا می‌کند |
| `manus.webhook_token` | تنظیمات Manus | shared secret وبهوک منوس |
| `manychat.webhook_token` | تنظیمات ManyChat | shared secret وبهوک منیچت (قبول: `?token=`، `X-IGBZ-Token`، `Bearer`) |
| `fx.webhook_token` | تنظیمات FX | shared secret وبهوک تسویهٔ ارزی (`/igbz/v1/fx/payout-webhook/{provider}`) |
| `api.jwt_secret` | تنظیمات Mobile API | امضای توکن‌های JWT |
| `lms.video_hmac_secret` | تنظیمات LMS | امضای لینک‌های ویدیوی مقید به زمان |
| `lms.vod_secure_key` | تنظیمات LMS | امضای لینک‌های VOD (الگوی آروان) |
| `hub.vip_link_secret` | تنظیمات Hub | امضای لینک‌های موقت VIP |
| `legal.nid` | تنظیمات Legal | کلید داخلی قفل احراز کد ملی — فقط مدیر ارشد ثبت می‌کند |

---

## ۴. کلیدهای هر اکانت اینستاگرام (نه سراسری)

در **IGBZ → Instagram → Accounts** هر اکانت جداگانه تنظیم می‌شود:

- `manus_api_key` / `manychat_api_key` با `credential_mode = own` — کلیدهای خود اکانت، بدون سقف.
- حالت `trial` — به‌جای کلید خودش از کلید سراسری اپراتور استفاده می‌کند؛ پیش‌فرض: **۱ تسک** / ۱۴ روز
  (کلیدهای `trial.task_quota` و `trial.days`).
- توکن‌های وبهوک (`manychat_webhook_token` / `manus_webhook_token`) هم به‌ازای هر اکانت جدا
  ذخیره می‌شوند و هویت درخواست‌های وبهوک از روی همان اکانت خوانده می‌شود.

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

مبنای امنیتی: OWASP برای رازها رمزنگاری در حالت سکون، دسترسی حداقلی، قابلیت ابطال/چرخش و
ممنوعیت ثبت plaintext را توصیه می‌کند:
<https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html>.
وردپرس نیز اصل «هیچ داده‌ای قابل اعتماد نیست» و اعتبارسنجی/خروجی امن را مبنا می‌داند:
<https://developer.wordpress.org/apis/security/>.
