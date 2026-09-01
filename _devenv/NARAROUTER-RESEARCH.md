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



## نتیجهٔ نخستین اجرای زنده — ۱۴۰۵/۰۶/۱۱

خروجی ارائه‌شده از GitHub Actions نشان داد:

- OpenRouter به endpoint رسید، اما مدل‌های production/pinned فعلی پولی بودند و حساب credit نداشت؛
  نتیجه `HTTP 402` برای Claude/GPT/Gemini. این key-rejection نیست؛ یعنی برای benchmark تولیدی
  باید credit خریداری شود یا برای smoke test از مدل `:free` استفاده شود.
- مدل قدیمی `meta-llama/llama-3.1-405b-instruct` روی OpenRouter دیگر endpoint نداشت و `HTTP 404`
  داد؛ فهرست production باید قبل از گیت نهایی با catalog همان روز تازه شود.
- Groq در workflow مقدار نگرفت: سکرت باید دقیقاً با نام `GROQ_API_KEY` در GitHub Actions ثبت شود.
- NaraRouter با `auto/bynara` پاسخ `HTTP 403` داد؛ مطابق `/api/plans` مدل‌های free-plan مستقیم
  موجودند. برای اجرای بعدی، smoke model پیش‌فرض به `glm-5.3-flash-free` تغییر کرد.

اقدام اصلاحی: workflow و اسکریپت مستقل به no-credit smoke defaults تغییر کردند:

```text
OPENROUTER_MODELS default = openrouter/free
NARAROUTER_MODELS default = agnes-2.5-flash
```

اگر هدف benchmark تولیدی باشد، مقدار `openrouter_models` باید صریحاً با مدل‌های paid و بعد از
شارژ credit وارد شود.

## وضعیت فعلی مخزن

- اسکریپت مستقل `_devenv/provider-verify.mjs` با `RUN_NARAROUTER` و سکرت
  `NARAROUTER_API_KEY` به‌روز شد.
- ورک‌فلوی مرجع `_devenv/github-workflow-provider-verify.yml` با ورودی‌های `nararouter` و
  `nararouter_models` به‌روز شد.
- صفحهٔ مرکزی provider که پیش‌تر ساخته شد، برای ثبت دستی NaraRouter کافی است و به تغییر کد
  جدید برای تولید نیاز ندارد.


## نتیجهٔ دومین اجرای زنده — ۱۴۰۵/۰۶/۱۱

خروجی ارائه‌شده از GitHub Actions:

```text
PASS  openrouter:inclusionai/ling-3.0-flash-fin:free  HTTP 200 estimated_cost=n/a reply=
FAIL  groq  API key secret missing
FAIL  nararouter:glm-5.3-flash-free  HTTP 403 — telegram_required: Join the required Telegram group/channel and relink at /settings to continue.
```

برداشت:

- OpenRouter از نظر کلید و قرارداد HTTP سبز است، اما پاسخ خالی برای benchmark فارسی کافی نیست.
  پیش‌فرض smoke به مدل رایگان رسمی‌تر `mistralai/mistral-nemo:free` تغییر کرد و workflow از این
  پس پاسخ خالی را failure می‌داند.
- Groq هنوز سکرت را با نام مورد انتظار دریافت نکرده؛ نام لازم دقیقاً `GROQ_API_KEY` است.
- NaraRouter تا انجام شرط بیرونی خودش (`telegram_required` و relink در `/settings`) قابل سنجش
  نیست. بنابراین در workflow پیش‌فرض خاموش شد؛ پس از انجام آن شرط باید اجرای دستی با
  `nararouter=true` انجام شود.


## نتیجهٔ سومین اجرای زنده — ۱۴۰۵/۰۶/۱۱

خروجی ارائه‌شده از GitHub Actions نشان داد:

- `mistralai/mistral-nemo:free` در OpenRouter دیگر رایگان/در دسترس نبود و `HTTP 404` داد.
- فهرست قبلی Groq نامعتبر شده بود: چند مدل `HTTP 404` گرفتند و `mixtral-8x7b-32768` و
  `gemma2-9b-it` رسماً decommissioned گزارش شدند.
- طبق دستور کارفرما، مدل‌های تست به این‌ها تغییر کردند:

```text
OpenRouter: openrouter/free
Groq: qwen/qwen3.6-27b
```

این اصلاح فقط برای smoke test workflow است؛ تصمیم تغییر seed تولیدی providerها جداگانه و پس از
عبور مدل‌ها از پروب و benchmark فارسی انجام می‌شود.


## نتیجهٔ اجرای سبز OpenRouter/Groq — 2026-09-01

پس از اعمال مدل‌های اعلام‌شده توسط کارفرما، اجرای دستی GitHub Actions روی `main` سبز شد:

```text
run_id = 33488428249
job_id = 99793905903
workflow = Provider key verification (Zernio + OpenRouter + Groq + NaraRouter + Anthropic)
branch = main
conclusion = success
created_at = 2026-09-01T08:42:38Z
url = https://github.com/paymanshafayan/IGBZ-WP/actions/runs/33488428249
```

خلاصهٔ رسمی GitHub نشان داد مرحلهٔ `Probe providers` موفق بوده است. دانلود لاگ خام از sandbox
با `EOF` قطع شد، بنابراین متن replyها در مخزن ثبت نشد؛ معیار ثبت‌شده، نتیجهٔ رسمی run و
خروجی سبز اعلام‌شده توسط کارفرماست.

مدل‌های smoke تأییدشده برای این run:

```text
OpenRouter = openrouter/free
Groq = qwen/qwen3.6-27b
```

NaraRouter در این run طبق تنظیم قبلی خاموش بوده است. پس از رفع شرط `telegram_required`، اجرای
بعدی باید با `nararouter=true` و در صورت نیاز `openrouter=false` و `groq=false` برای تست متمرکز
NaraRouter انجام شود.


## نتیجه‌های اجرای NaraRouter و اجرای کامل — ۱۴۰۵/۰۶/۱۱

خروجی‌های ارائه‌شده توسط کارفرما:

```text
FAIL  nararouter:true  HTTP 404 — The requested model does not exist.
PASS  nararouter:glm-5.3-flash-free  HTTP 200 estimated_cost=n/a tokens=36+217 reply=...
```

برداشت: خط `nararouter:true` ناشی از قرار گرفتن مقدار `true` در فیلد متنی مدل بوده، نه مشکل
endpoint. اجرای بعدی با مدل مستقیم `glm-5.3-flash-free` سبز شد و قرارداد OpenAI-compatible
ناراروتر تأیید شد.

اجرای کامل بعدی نشان داد:

```text
FAIL  openrouter:openrouter/free  HTTP 200 — empty reply finish=length tokens=79+128
PASS  groq:qwen/qwen3.6-27b  HTTP 200 ...
PASS  nararouter:glm-5.3-flash-free  HTTP 200 ...
FAIL  zernio:cleanup:6a9488fe77555aae011b7a18  HTTP 404
PASS  zernio:create-profile  HTTP 201 ...
PASS  zernio:delete-profile (cleanup)  HTTP 200
```

اقدام اصلاحی workflow:

- پاسخ خالی همراه با `HTTP 200` در smoke test دیگر کل workflow را قرمز نمی‌کند و به‌صورت `WARN`
  ثبت می‌شود؛ این فقط اتصال/کلید را تأیید می‌کند و benchmark فارسی محسوب نمی‌شود.
- مسیر نمایش reply دیگر با `xargs` trim نمی‌شود تا متن‌های دارای نقل‌قول، مثل خروجی مدل‌های
  reasoning، workflow را نشکنند.
- خروجی‌های `<think>` در preview لاگ خلاصه‌سازی/حذف می‌شوند تا لاگ smoke test تمیزتر باشد.
- cleanup زرنیو اگر `HTTP 404` بدهد، به‌عنوان «از قبل حذف شده» پذیرفته می‌شود؛ create/delete
  واقعی همچنان gate اصلی زرنیو است.
- طبق دستور کارفرما، مدل پیش‌فرض تست ناراروتر برای اجرای بعدی به این تغییر کرد:

```text
agnes-2.5-flash
```


## نتیجهٔ اجرای کامل سبز همهٔ providerها — 2026-09-01

پس از جایگزینی workflow اصلاح‌شده، اجرای دستی کامل روی `main` سبز شد:

```text
run_id = 33540909998
job_id = 99966649314
workflow = Provider key verification (Zernio + OpenRouter + Groq + NaraRouter + Anthropic)
branch = main
conclusion = success
created_at = 2026-09-01T17:58:34Z
url = https://github.com/paymanshafayan/IGBZ-WP/actions/runs/33540909998
```

خلاصهٔ لاگ ارائه‌شده توسط کارفرما:

```text
WARN  openrouter:openrouter/free  HTTP 200 — empty reply finish=length tokens=483+128 (smoke connectivity passed; benchmark not satisfied)
PASS  groq:qwen/qwen3.6-27b  HTTP 200 estimated_cost=n/a tokens=31+128 reply=[reasoning omitted]
PASS  nararouter:agnes-2.5-flash  HTTP 200 estimated_cost=n/a tokens=63+97 reply=«دسترسی مستقیم و کم‌هزینه به مخاطبان گسترده بدون نیاز به هزینه اجاره مکان فیزیکی.»
PASS  anthropic  skipped (RUN_ANTHROPIC=false)
PASS  zernio:cleanup:6a9488fe77555aae011b7a18  HTTP 404 already absent
PASS  zernio:create-profile  HTTP 201 profile_id=6a97125a94b4c1ad7cd016ed
PASS  zernio:delete-profile (cleanup)  HTTP 200
Failures: 0
```

برداشت گیت:

- اتصال/کلید OpenRouter برقرار است، اما `openrouter/free` در این اجرا پاسخ قابل benchmark نداد؛
  بنابراین برای benchmark فارسی یا باید مدل خروجی‌دار دیگری انتخاب شود یا مدل paid پس از شارژ credit
  آزموده شود.
- Groq با `qwen/qwen3.6-27b` از نظر اتصال و قرارداد `openai` سبز است؛ preview لاگ به‌درستی بخش
  reasoning را حذف کرده است.
- NaraRouter با `agnes-2.5-flash` از نظر اتصال، قرارداد `openai` و پاسخ فارسی smoke سبز شد.
- Zernio create/delete profile واقعی سبز شد؛ cleanup قدیمی ۴۰۴ بود و طبق اصلاح idempotent به‌درستی
  «already absent» پذیرفته شد.
- نتیجهٔ رسمی GitHub نیز job `verify` و step `Probe providers` را successful گزارش کرد.
