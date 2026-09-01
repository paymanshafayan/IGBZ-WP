# یادداشت پژوهش — NaraRouter / router.bynara.id

تاریخ بررسی: ۱۴۰۵/۰۶/۱۱ (2026-09-01)

## تصمیم اجرایی فعلی

NaraRouter فعلاً **فقط ارائه‌دهندهٔ آزمایشی/کمکی برای تست‌های زندهٔ provider** است؛ نه
ارائه‌دهندهٔ پیش‌فرض، نه seed تولیدی، و نه چیزی که tenant بتواند تغییر دهد. اتصال آن از
طریق همان هواپیمای ADR-0005 انجام می‌شود: یک رکورد `api_provider` با `protocol=openai` و
`base_url=https://router.bynara.id/v1` در صفحهٔ مرکزی «ارائه‌دهنده‌های هوش مصنوعی» که فقط
مدیر ارشد (`MANAGE_SUITE`) می‌بیند.

نام سکرت گیت‌هاب برای کلید زنده:

```text
NARAROUTER_API_KEY
```

## داده‌های فنی مستندشده

- سایت رسمی: `https://router.bynara.id/`
- مستندات API: `https://router.bynara.id/docs`
- قیمت‌ها: `https://router.bynara.id/pricing`
- Base URL متن/چت: `https://router.bynara.id/v1`
- Endpoint چت OpenAI-compatible: `POST /v1/chat/completions`
- نمونهٔ رسمی مدل عمومی: `auto/bynara`
- نمونهٔ رسمی مدل مستقیم در مستندات: `deepseek-v4-flash`
- کلیدها با پیشوند `sk-nry-` ساخته می‌شوند و فقط یک‌بار در داشبورد نشان داده می‌شوند.
- سطح‌های دیگر مستندشده: `/v1/responses`، `/v1/messages`، `/v1/embeddings`، `/v1/rerank`،
  و endpointهای تصویر/ویدئو روی `api-images.bynara.id`.

## قیمت/سقف مشاهده‌شده

- Free: بدون کارت، نرخ محدودیت حدود `15 req/min` و سهمیهٔ روزانهٔ چند میلیون توکن برای کلاس
  پایه؛ مدل عمومی `auto/bynara` برای smoke test مناسب‌ترین گزینهٔ پیش‌فرض است.
- Pay-as-you-go: قیمت‌ها به روپیهٔ اندونزی و معادل دلاری روی صفحهٔ pricing نمایش داده می‌شود.
- چند پلن/مدل ممکن است `Out of stock` باشد؛ ظرفیت واقعی باید در هر اجرا دوباره دیده شود.

## ریسک‌ها

1. سرویس نسبتاً تازه است و صفحهٔ اصلی هنگام بررسی آمار اعتماد را صفر نشان می‌داد؛ برای ترافیک
   تولیدی بدون soak test مناسب نیست.
2. بخش Publisher اجازهٔ فروش ظرفیت بلااستفادهٔ حساب‌های دیگر را پیشنهاد می‌کند؛ این الگو
   ریسک بسته‌شدن ناگهانی upstream یا نقض شرایط ارائه‌دهندهٔ بالادستی دارد.
3. ZDR/عدم نگه‌داری داده و قرارداد پردازش دادهٔ مشتری در مستندات عمومی دیده نشد؛ دادهٔ مشتری
   واقعی تا روشن‌شدن این موضوع نباید از آن عبور کند.
4. پشتیبانی عمومی عمدتاً تلگرام است؛ برای تعهد سازمانی کافی نیست.
5. پشتیبانی ابزار/function calling در مستندات عمومی چت صریح نبود؛ بنابراین فعلاً قابلیت
   رکورد تستی فقط `chat` است، نه `tools`.
6. دسترس‌پذیری جغرافیایی و پایداری latency باید با پروب زنده و سپس benchmark فارسی سنجیده شود.

## برنامهٔ تست

۱. سکرت `NARAROUTER_API_KEY` در GitHub Actions ثبت شود.
۲. ورک‌فلوی مرجع `_devenv/github-workflow-provider-verify.yml` روی `main` جایگزین شود؛ محتوای
   کامل آن طبق دستور کارفرما باید در چت نیز داخل باکس قابل کپی داده شود.
۳. smoke test با `auto/bynara` اجرا شود (`RUN_NARAROUTER=true`).
۴. اگر smoke test سبز بود، مدل‌های مستقیم مثل `deepseek-v4-flash` فقط با ورودی
   `nararouter_models` و بر اساس خروجی `/v1/models` همان حساب آزمایش شوند.
۵. تا قبل از گذراندن گیت‌های ADR-0005 (`enabled` / `benchmark_passed` / `geo_eligible`) هیچ
   مسیریابی تولیدی به NaraRouter داده نشود.

## وضعیت فعلی مخزن

- اسکریپت مستقل `_devenv/provider-verify.mjs` با `RUN_NARAROUTER` و سکرت
  `NARAROUTER_API_KEY` به‌روز شد.
- ورک‌فلوی مرجع `_devenv/github-workflow-provider-verify.yml` با ورودی‌های `nararouter` و
  `nararouter_models` به‌روز شد.
- صفحهٔ مرکزی provider که پیش‌تر ساخته شد، برای ثبت دستی NaraRouter کافی است و به تغییر کد
  جدید برای تولید نیاز ندارد.
