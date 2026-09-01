# راستی‌آزمایی زندهٔ کلیدهای Zernio و ارائه‌دهنده‌های استنتاج روی گیت‌هاب

هدف: بررسی اینکه کلیدهای واقعی کار می‌کنند و قرارداد endpointها همان است که در کد افزونه
پیاده شده — بدون استقرار و بدون عبور کلید از چت یا مخزن. طبق `ADR-0005` ارائه‌دهنده‌های
استنتاج «رکورد تنظیمات» (`api_provider` با فیلد `protocol`) هستند؛ این ورک‌فلو هر دو گویش
سیم را می‌سنجد: `openai` (OpenRouter، Groq و NaraRouter) و `anthropic` (در صورت وجود کلید).

## چرا گیت‌هاب، نه سندباکس؟

سندباکس agent فقط به `registry.npmjs.org` دسترسی دارد؛ اتصال به `zernio.com` و
`openrouter.ai` و `api.groq.com` و `router.bynara.id` و `api.anthropic.com` از آن قطع است (تست‌شده: پاسخ
`http=000`). رانر گیت‌هاب اینترنت کامل دارد و همان‌جا سکرت‌ها فقط به‌صورت متغیر محیطی به
ورک‌فلو تزریق می‌شوند — مقدارشان هرگز در لاگ یا مخزن دیده نمی‌شود.

## گام‌ها (یک‌بار)

۱. **ثبت سکرت‌ها در گیت‌هاب:** در مخزن، مسیر `Settings → Secrets and variables → Actions → New repository secret` و این سکرت‌ها را بسازید:
   - نام `ZERNIO_API_KEY` ← کلید مرکزی Zernio
   - نام `OPENROUTER_API_KEY` ← کلید OpenRouter
   - نام `GROQ_API_KEY` ← کلید Groq
   - نام `NARAROUTER_API_KEY` ← کلید NaraRouter / byNara Router (فقط برای تست provider تا عبور از گیت‌های ADR-0005)
   - نام `ANTHROPIC_API_KEY` ← کلید Anthropic (اختیاری؛ فقط اگر قصد سنجش گویش `anthropic` را دارید)
   ⚠️ کلیدها را فقط همین‌جا بچسبانید؛ هرگز در چت، کامیت یا فایل.

۲. **ساخت ورک‌فلو روی شاخهٔ پیش‌فرض (`main`):** دکمهٔ «Run workflow» فقط برای ورک‌فلوهایی
   ظاهر می‌شود که روی شاخهٔ پیش‌فرض باشند. پس:
   - در صفحهٔ مخزن، شاخه را روی `main` بگذارید؛
   - `Add file → Create new file`؛ نام فایل را `.github/workflows/provider-verify.yml` بگذارید و محتوای `_devenv/github-workflow-provider-verify.yml` را کپی و کامیت کنید.
   - اگر این فایل را قبلاً روی شاخهٔ arena ساخته‌اید، همان محتوا را یک‌بار روی `main` هم بسازید (نسخهٔ تازه خودکفاست و به هیچ فایل دیگری وابسته نیست).

۳. **اجرا:** تب `Actions → Provider key verification (Zernio + OpenRouter + Groq + NaraRouter + Anthropic)`
   → دکمهٔ سبز `Run workflow` → شاخهٔ `main` → دوباره `Run workflow`. برای اجرای فعلی اگر فقط
   OpenRouter/Groq/NaraRouter مدنظر است، `zernio=false` و `anthropic=false` بماند. سپس روی اجرای تازه کلیک
   کنید، شغل `verify` را باز کنید و گام `Probe providers` را باز کنید تا خط‌های `PASS/FAIL` را ببینید.

## آنچه اجرا می‌شود

- **OpenRouter (گویش openai):** با هر مدل پین‌شده (یا فهرست دلخواه شما) یک درخواست کوچک
  فارسی (~۱۲۸ توکن) به `https://openrouter.ai/api/v1/chat/completions` با `Authorization: Bearer`
  می‌زند و وضعیت HTTP، `estimated_cost` و نخستین جملهٔ پاسخ را گزارش می‌کند. رد کلید یعنی
  `HTTP 401/403`.
- **Groq (گویش openai):** همان پروب روی `https://api.groq.com/openai/v1/chat/completions`.
- **NaraRouter (گویش openai؛ فقط تستی):** smoke test روی `https://router.bynara.id/v1/chat/completions`
  با مدل پیش‌فرض `auto/bynara`. اگر حساب مدل‌های مستقیم بیشتری نشان داد، با ورودی
  `nararouter_models` می‌توان آن‌ها را جداگانه آزمود؛ تا قبل از benchmark فارسی و گیت‌های
  `enabled`/`benchmark_passed`/`geo_eligible` مسیر تولیدی به آن داده نمی‌شود.
- **Anthropic (گویش anthropic):** در صورت فعال‌کردن و وجود کلید، پروب روی
  `https://api.anthropic.com/v1/messages` با هدرهای `x-api-key` و `anthropic-version` و
  بدنهٔ `{model, max_tokens, messages}`؛ پاسخ از بلوک‌های `content[].text` و مصرف از
  `usage.input_tokens/output_tokens` خوانده می‌شود — همان قراردادی که
  `AnthropicProtocolAdapter` پیاده می‌کند.
- **Zernio (کلید مرکزی):** یک پروفایل آزمایشی با نام `igbz-ci-probe-…` می‌سازد
  (`POST /profiles`)، شناسهٔ آن را می‌خواند و بلافاصله حذف می‌کند (`DELETE /profiles/{id}`) —
  رفت‌وبرگشت همانی که `ZernioConnectionService::create_profile/delete_profile` انجام می‌دهد.
  پاک‌سازی پروفایل‌های یتیم (پیش‌فرض `6a9488fe77555aae011b7a18`) هم پیش از ساخت انجام می‌شود.
  هیچ اتصال اینستاگرام واقعی یا انتشار انجام نمی‌شود.

هر چک به‌صورت `PASS` یا `FAIL` در لاگ چاپ می‌شود؛ اگر چکی رد شود، ورک‌فلو با خروجی غیرصفر
قرمز می‌شود.

نکته: نسخهٔ اسکریپت مستقل `_devenv/provider-verify.mjs` (Node) نیز موجود است و همین بررسی‌ها
را با خروجی ساخت‌یافته انجام می‌دهد؛ اما ورک‌فلوی مرجع عمداً خودکفا نوشته شده تا فقط با کپی
یک فایل روی `main` کار کند. **دستور دائمی کارفرما:** هرگاه این فایل workflow باید روی گیت‌هاب
جایگزین شود، متن کامل workflow در پاسخ چت داخل یک باکس قابل کپی ارائه شود.

## توجه

- این فقط راستی‌آزمایی کلید و قرارداد است؛ تست انتها‌به‌انتها (پروفایل واقعی فروشگاه + انتشار)
  مربوط به استیجینگ ریل‌وی در فاز ۷۰ است.
- ناحیهٔ اجتماعی Zernio (انتشار، اینباکس، تحلیل، صدای پرطرفدار) با کلید پروفایلی فروشگاه کار
  می‌کند که باید پس از ساخت پروفایل صادر شود؛ آن بخش در فازهای `PV-ZERNIO-*` و پس از اتصال
  حساب واقعی انجام می‌شود، نه این‌جا.


## نتیجهٔ اجرای زندهٔ ۱۴۰۵/۰۶/۱۱ و تنظیم اجرای بعدی

- OpenRouter با مدل‌های paid/pinned فعلی `HTTP 402` داد؛ یعنی حساب credit ندارد. برای smoke
  test بدون credit، مقدار پیش‌فرض workflow به مدل رایگان زیر تغییر کرد:

```text
openrouter/free
```

- Groq مقدار سکرت نگرفت. نام سکرت باید دقیقاً این باشد:

```text
GROQ_API_KEY
```

- NaraRouter با `auto/bynara` پاسخ `HTTP 403` داد. از endpoint عمومی `/api/plans` مشخص شد مدل‌های
  free-plan مستقیم وجود دارند؛ مقدار پیش‌فرض اجرای بعدی:

```text
glm-5.3-flash-free
```

برای اجرای بعدی، اگر هدف فقط smoke test است، ورودی‌های model را خالی بگذارید تا همین مقادیر
پیش‌فرض استفاده شوند. اگر هدف benchmark تولیدی OpenRouter است، باید ابتدا credit شارژ شود و سپس
`openrouter_models` با مدل‌های paid/pinned همان روز پر شود.


### نتیجهٔ اجرای دوم و تنظیم جدید

- OpenRouter با مدل رایگان `inclusionai/ling-3.0-flash-fin:free` از نظر HTTP سبز شد، اما پاسخ
  خالی داد؛ از این پس workflow پاسخ خالی را failure می‌داند و مدل پیش‌فرض smoke به
  `openrouter/free` تغییر کرد.
- Groq هنوز مقدار نگرفت؛ secret باید دقیقاً `GROQ_API_KEY` باشد.
- NaraRouter پاسخ `telegram_required` داد. تا وقتی در داشبورد NaraRouter عضویت تلگرام و relink
  در `/settings` انجام نشود، `nararouter=false` بماند؛ بعد از رفع شرط بیرونی، با
  `nararouter=true` اجرا شود.


### نتیجهٔ اجرای سوم و مدل‌های اعلام‌شده توسط کارفرما

خروجی اجرای سوم نشان داد:

```text
FAIL openrouter:mistralai/mistral-nemo:free HTTP 404 — This model is unavailable for free. The paid version is available now - use this slug instead: mistralai/mistral-nemo
FAIL groq:llama-3.3-70b-versatile HTTP 404
FAIL groq:llama-3.1-8b-instant HTTP 404
FAIL groq:meta-llama/llama-4-scout-17b-16e-instruct HTTP 404
FAIL groq:mixtral-8x7b-32768 HTTP 400 — decommissioned
FAIL groq:gemma2-9b-it HTTP 400 — decommissioned
```

طبق دستور کارفرما، defaults تست زنده از این پس چنین است:

```text
OPENROUTER_MODELS default = openrouter/free
GROQ_MODELS default = qwen/qwen3.6-27b
```

این تغییر فعلاً فقط برای تست زندهٔ provider است و seed تولیدی providerها را تغییر نمی‌دهد.


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
