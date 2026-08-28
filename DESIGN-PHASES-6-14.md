# پیاده‌سازی فازهای ۶ تا ۱۴ (نقشهٔ راه nop) روی IGBZ-WP — طراحی و وضعیت

**آخرین به‌روزرسانی:** ۱۴۰۵/۰۵/۲۵ · **مبنای طراحی:** `ARCHITECTURE_AND_ROADMAP.md` (مخزن nop)
و تطبیق آن با معماری فعلی وردپرس/ووکامرس و قواعد پروژه.

> **تصمیم جاری ۱۴۰۵/۰۶/۰۶:** Zernio تنها provider اجتماعی با حساب مرکزی و profile جدا برای
> هر فروشگاه است. پادو/DeepInfra مستقل تحلیل و تولید را انجام می‌دهد. IGBZ مستقیماً Graph
> API را پیاده نمی‌کند و scraper/session ممنوع است. ChatPlace، ManyChat، Manus و کانال Instagram مبتنی بر session در Agent Reach
> در این سند فقط تاریخچهٔ کد فازهای قدیمی و بدهی migration هستند. مرجع: ADR-0004.

> **الگوی مشترک همهٔ فازها:** سرویس پشت یک اینترفیس آداپتور، با Endpoint های تنظیم‌پذیر
> (مثل `HttpRampAdapter` و `HttpSpeechToText`) تا هر سرویس‌دهندهٔ ایرانی با کلید ریالی وصل شود؛
> و همهٔ خروجی‌ها از دادهٔ واقعی، نه رشتهٔ ثابت.

---

## فاز ۶ — درگاه‌های پرداخت چندگانه و BNPL خارجی
- **وضعیت پایه:** ۴ درگاه (زرین‌پال/آیدی‌پی/نکست‌پی/پی.آی.آر) + BNPL داخلی.
- **کار:** `HttpPspGateway` (درگاه عمومی تنظیم‌پذیر برای بانک‌های بیشتر) + `SnappPayBnplProvider`
  و `TaraBnplProvider` (آداپتورهای HTTP تنظیم‌پذیر) ثبت‌شده با `igbz_register_bnpl_providers`.
- **فایل‌ها:** `Payments/HttpPspGateway.php`, `Bnpl/HttpBnplProvider.php`.

## فاز ۷ — لجستیک و ارسال
- جدول `ig_shipments`؛ سرویس `LogisticsService` (دسته‌بندی مسیر با قواعد تنظیم‌پذیر، تولید PIN
  تحویل با `random_int` رمزنگارانه)؛ `ShippingAdapterInterface` + `HttpShippingAdapter`
  (تاپین/پستکس)؛ صفحهٔ ادمین `igbz-logistics`؛ REST ارسال.

## فاز ۸ — استودیوی هوش مصنوعی محتوا
- `AiProviderInterface` + `HttpAiStudioProvider` (تصویر/حذف پس‌زمینه/ویدیو/TTS/عکس مدل)؛
  `AiStudioService` (ذخیرهٔ خروجی واقعی در مدیالایبری)؛ صفحهٔ ادمین `igbz-ai-studio`.

## فاز ۹ — مارکت‌پلیس‌ها (دیجی‌کالا/دیوار)
- جدول‌های `ig_marketplace_sync` (صف بادوام) و `ig_category_mapping`؛
  `MarketplaceAdapterInterface` + `HttpMarketplaceAdapter`؛ `MarketplaceSyncService` (هوک
  تغییر محصول + کرون worker)؛ صفحهٔ ادمین `igbz-marketplaces`. (فید ترب از قبل موجود است.)

## فاز ۱۰ — سئو و شبکه‌های تبلیغاتی
- `SeoService` (متای خودکار + هشتگ با قالب قطعی یا AI)؛ `ProductFeedService` (XML/JSON
  یکتانت/تپسل از کاتالوگ واقعی)؛ `AdNetworkService` (تریبون HTTP)؛ صفحهٔ ادمین `igbz-seo`.

## فاز ۱۱ — گیمیفیکیشن و تخفیف رفتارمحور
- `GamificationService` (چرخ‌وفلک با سردی ۲۴h و RNG امن؛ ساخت کوپن واقعی ووکامرس)؛
  `AbandonedCartService` (جدول `ig_abandoned_carts` + کرون یادآوری با کد تخفیف)؛ صفحهٔ ادمین.

## فاز ۱۲ — درگاه رمزارزی (NOWPayments) + ترجمهٔ خودکار
- `NowPaymentsGateway` (اینترفیس `GatewayInterface`؛ ساخت Invoice؛ وبوک IPN با idempotency)؛
  `TranslationService` + `HttpTranslationAdapter`؛ صفحهٔ ادمین `igbz-translator`.
- **تفکیک ثبت‌شده:** این «درآمد ارزی فروشگاه از مشتری خارجی» است — جدا از ماژول FX
  («هزینهٔ ابزارها»).

## فاز ۱۳ — LMS + امنیت ویدیو (VOD)
- LMS کامل است؛ `LmsVodService` (لینک امضاشدهٔ HMAC با انقضا/آی‌پی — الگوی آروان‌کلاد،
  تنظیم‌پذیر) + فیلدهای تنظیمات `lms.vod_*`. واترمارک/FLAG_SECURE سمت اپ مستند می‌شود.

## فاز ۱۴ — کیف پول هوشمند، استودیوی AI مشتری، رشد فالوور
- کشبک کیف پول موجود است. `AiCreditsService` (جدول `ig_ai_credit_ledger`؛ شارژ با خرید از
  درصد تنظیم‌پذیر + خرید نقدی با `purpose=ai_credit_topup`)؛ REST استودیوی مشتری
  (`/igbz/v1/ai/studio/*`)؛ `GiveawayService` (جدول `ig_giveaways`؛ قرعه‌کشی از کامنت‌های واقعی
  `ig_funnel_hits` با `random_int`)؛ صفحهٔ ادمین `igbz-giveaways`.

---

## وضعیت اجرا (پس از هر فاز به‌روز می‌شود)
| فاز | وضعیت |
|---|---|
| ۶ | ✅ |
| ۷ | ✅ |
| ۸ | ✅ |
| ۹ | ✅ |
| ۱۰ | ✅ |
| ۱۱ | ✅ |
| ۱۲ | ✅ |
| ۱۳ | ✅ |
| ۱۴ | ✅ |

## تکمیل نهایی (۱۴۰۵/۰۵/۲۵ — DB v16، همهٔ فازها)

این سند خلاصهٔ طراحی است؛ جزئیات پیاده‌سازی هر فاز در سند اختصاصی خودش است:
`DESIGN-PHASE6-PAYMENTS.md`، `DESIGN-PHASE7-COURIER.md`، `DESIGN-PHASE9-MARKETPLACES.md`،
`DESIGN-PHASE10-SEO.md`، `DESIGN-PHASE12-INTERNATIONAL.md`، `DESIGN-DOMAIN.md` و
`DESIGN-LEGAL-AUTH.md`. کارهای پس از این خلاصه که به v16 رساند:

- **فاز ۶ (تکمیل):** هشت درگاه مستقیم بانکی (`AbstractIpgGateway` + سداد/آسان‌پرداخت/پارسیان/
  ایران‌کیش/ملت/سامان/پاسارگاد/سپهر) + BalePay + درگاه مرکزی escrow + دیجی‌پی + تخفیف نقدی +
  احراز کد ملی شاهکار + دکمهٔ Test connection + گیت «دامنهٔ تأییدشده + انماد فعال».
- **فاز ۷ (تکمیل):** اپ پیک کامل — مسیریابی ترتیبی، تحویل با PIN مشتری، COD چهارشکل، رصد زنده،
  چت، برچسب استاندارد بارکددار.
- **فاز ۹ (تکمیل):** آداپتور باسلام + انتشار خودکار محتوای اینستاگرام در باسلام.
- **فاز ۱۰ (تکمیل):** سرویس دامنه (جستجو/ثبت/زیردامنه/DNS/انتقال) + ثبت وب‌پرزنس گوگل/بینگ +
  ذخیرهٔ متای واقعی روی محصولات.
- **فاز ۱۲ (تکمیل):** سرویس i18n + تنظیمات Stripe/PayPal برای کارت قبرس.
- **فاز ۱۴ (تاریخی، نیازمند migration):** `ChatPlaceClient` و سوئیچ `dm.provider` در کد
  وجود دارند، اما هر دو provider با ADR-0004 حذف شده‌اند؛ مقصد فقط Zernio است.

**وضعیت نهایی:** DB v16 — **۶۹ جدول** · تست **۱۱۷۲ اظهارنظر / ۲۳ کیس** · لینت **۲۳۴ فایل،
صفر خطا** · تأیید زنده: ۶۹/۶۹ جدول، همهٔ صفحات ادمین ۲۰۰. کامیت‌ها: `a6b9523`, `acbc029`,
`ac36835`, `67b9c8c`, `9b30a28`.
