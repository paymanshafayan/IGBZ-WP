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
