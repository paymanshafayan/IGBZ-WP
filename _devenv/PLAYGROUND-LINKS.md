# تست آنلاین پروژه از روی گیت‌هاب — WordPress Playground رسمی

> ثبت: ۱۴۰۶/۰۶/۰۲ (۲۴ اوت ۲۰۲۶) — پاسخ به نیاز کارفرما: «ابزار آنلاینی که به گیت وصل شود و بدون سندباکس بتوان پروژه را تست گرفت.»

## مشکل و راه‌حل

پیش‌نمایش سندباکس (`*.e2b.app`) با پایان عمر سندباکس می‌میرد و خطای «Sandbox Not Found» می‌دهد.
راه‌حل پایدار: **playground.wordpress.net** — سرویس رسمی و رایگان وردپرس که کل سایت را داخل
مرورگر کاربر اجرا می‌کند (PHP-WASM؛ همان فناوری `_devenv` خودمان)، هیچ سروری لازم ندارد،
و با **Blueprint** می‌تواند افزونه را **مستقیم از ریپوی گیت‌هاب** (`resource: git:directory`)
نصب کند. چون ریپوی `paymanshafayan/IGBZ-WP` عمومی است، احراز هویت هم لازم نیست.

⚠️ محدودیت‌ها:
- هر تب یک نمونهٔ تازه است؛ با بستن تب، داده‌ها می‌روند (برای تست کد مناسب است، نه نگهداری داده).
- بذر فروشگاه نمونه (۲۵ محصول و…) در این حالت نیست — آن mu-pluginهای `_devenv` است. فقط
  خود افزونه + ووکامرس بالا می‌آید.
- همیشه از **آخرین کد شاخهٔ `main`** نصب می‌کند (هر بار باز کردن لینک = آخرین کامیت).

## لینک اصلی (نصب از گیت‌هاب، شاخهٔ main)

Blueprint این کارها را می‌کند: ووکامرس از مخزن وردپرس نصب و فعال + `igbz-suite` مستقیم از
`github.com/paymanshafayan/IGBZ-WP` (زیرپوشهٔ `igbz-suite`، شاخهٔ `main`) نصب و فعال +
فعال‌سازی هر ۶ ماژول (`igbz_enabled_modules`) + منطقهٔ زمانی تهران + ورود خودکار admin.

```
https://playground.wordpress.net/#eyIkc2NoZW1hIjoiaHR0cHM6Ly9wbGF5Z3JvdW5kLndvcmRwcmVzcy5uZXQvYmx1ZXByaW50LXNjaGVtYS5qc29uIiwicHJlZmVycmVkVmVyc2lvbnMiOnsicGhwIjoiOC4yIiwid3AiOiJsYXRlc3QifSwiZmVhdHVyZXMiOnsibmV0d29ya2luZyI6dHJ1ZX0sImxvZ2luIjp0cnVlLCJsYW5kaW5nUGFnZSI6Ii93cC1hZG1pbi9wbHVnaW5zLnBocCIsInN0ZXBzIjpbeyJzdGVwIjoiaW5zdGFsbFBsdWdpbiIsInBsdWdpbkRhdGEiOnsicmVzb3VyY2UiOiJ3b3JkcHJlc3Mub3JnL3BsdWdpbnMiLCJzbHVnIjoid29vY29tbWVyY2UifSwib3B0aW9ucyI6eyJhY3RpdmF0ZSI6dHJ1ZX19LHsic3RlcCI6Imluc3RhbGxQbHVnaW4iLCJwbHVnaW5EYXRhIjp7InJlc291cmNlIjoiZ2l0OmRpcmVjdG9yeSIsInVybCI6Imh0dHBzOi8vZ2l0aHViLmNvbS9wYXltYW5zaGFmYXlhbi9JR0JaLVdQIiwicmVmIjoibWFpbiIsInJlZlR5cGUiOiJyZWZuYW1lIiwicGF0aCI6ImlnYnotc3VpdGUifSwib3B0aW9ucyI6eyJhY3RpdmF0ZSI6dHJ1ZX19LHsic3RlcCI6InJ1blBIUCIsImNvZGUiOiI8P3BocCByZXF1aXJlX29uY2UgJy93b3JkcHJlc3Mvd3AtbG9hZC5waHAnOyB1cGRhdGVfb3B0aW9uKCdpZ2J6X2VuYWJsZWRfbW9kdWxlcycsIFsnbXVsdGl0ZW5hbnQnLCdpbnN0YWdyYW0nLCdodWInLCdmeCcsJ3Jlc3RfYXBpJywncGFkbyddKTsgdXBkYXRlX29wdGlvbigndGltZXpvbmVfc3RyaW5nJywnQXNpYS9UZWhyYW4nKTsifV19
```

## لینک جایگزین (اگر git:directory خطا داد — از راه github-proxy.com)

```
https://playground.wordpress.net/#eyIkc2NoZW1hIjoiaHR0cHM6Ly9wbGF5Z3JvdW5kLndvcmRwcmVzcy5uZXQvYmx1ZXByaW50LXNjaGVtYS5qc29uIiwicHJlZmVycmVkVmVyc2lvbnMiOnsicGhwIjoiOC4yIiwid3AiOiJsYXRlc3QifSwiZmVhdHVyZXMiOnsibmV0d29ya2luZyI6dHJ1ZX0sImxvZ2luIjp0cnVlLCJsYW5kaW5nUGFnZSI6Ii93cC1hZG1pbi9wbHVnaW5zLnBocCIsInN0ZXBzIjpbeyJzdGVwIjoiaW5zdGFsbFBsdWdpbiIsInBsdWdpbkRhdGEiOnsicmVzb3VyY2UiOiJ3b3JkcHJlc3Mub3JnL3BsdWdpbnMiLCJzbHVnIjoid29vY29tbWVyY2UifSwib3B0aW9ucyI6eyJhY3RpdmF0ZSI6dHJ1ZX19LHsic3RlcCI6Imluc3RhbGxQbHVnaW4iLCJwbHVnaW5EYXRhIjp7InJlc291cmNlIjoidXJsIiwidXJsIjoiaHR0cHM6Ly9naXRodWItcHJveHkuY29tL3Byb3h5Lz9yZXBvPXBheW1hbnNoYWZheWFuL0lHQlotV1AmYnJhbmNoPW1haW4mZGlyZWN0b3J5PWlnYnotc3VpdGUifSwib3B0aW9ucyI6eyJhY3RpdmF0ZSI6dHJ1ZX19LHsic3RlcCI6InJ1blBIUCIsImNvZGUiOiI8P3BocCByZXF1aXJlX29uY2UgJy93b3JkcHJlc3Mvd3AtbG9hZC5waHAnOyB1cGRhdGVfb3B0aW9uKCdpZ2J6X2VuYWJsZWRfbW9kdWxlcycsIFsnbXVsdGl0ZW5hbnQnLCdpbnN0YWdyYW0nLCdodWInLCdmeCcsJ3Jlc3RfYXBpJywncGFkbyddKTsgdXBkYXRlX29wdGlvbigndGltZXpvbmVfc3RyaW5nJywnQXNpYS9UZWhyYW4nKTsifV19
```

## Blueprint خام (برای تغییر شاخه یا سفارشی‌سازی)

در `playground.wordpress.net` گزینهٔ «Load Blueprint» را بزنید و این JSON را بدهید
(برای تست شاخهٔ دیگر، `"ref": "main"` را عوض کنید):

```json
{
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "preferredVersions": { "php": "8.2", "wp": "latest" },
  "features": { "networking": true },
  "login": true,
  "landingPage": "/wp-admin/plugins.php",
  "steps": [
    { "step": "installPlugin",
      "pluginData": { "resource": "wordpress.org/plugins", "slug": "woocommerce" },
      "options": { "activate": true } },
    { "step": "installPlugin",
      "pluginData": { "resource": "git:directory",
        "url": "https://github.com/paymanshafayan/IGBZ-WP",
        "ref": "main", "refType": "refname", "path": "igbz-suite" },
      "options": { "activate": true } },
    { "step": "runPHP",
      "code": "<?php require_once '/wordpress/wp-load.php'; update_option('igbz_enabled_modules', ['multitenant','instagram','hub','fx','rest_api','pado']); update_option('timezone_string','Asia/Tehran');" }
  ]
}
```

## لینک سادهٔ دائمی (پیشنهادی — بدون base64)

فایل Blueprint در خود ریپو نگهداری می‌شود (`_devenv/playground-blueprint.json`).
پس از ادغام این شاخه در `main`، این لینک کوتاه همیشه کار می‌کند:

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paymanshafayan/IGBZ-WP/main/_devenv/playground-blueprint.json
```

تا قبل از ادغام، همین لینک با نام شاخهٔ جاری:

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paymanshafayan/IGBZ-WP/arena/01a03227-igbz-wp/_devenv/playground-blueprint.json
```

## دکمهٔ خودکار پیش‌نمایش روی هر PR (workflow — نصب دستی توسط کارفرما)

**اکشن رسمی `WordPress/action-wp-playground-pr-preview@v3`**: روی هر Pull Request
به‌طور خودکار دکمهٔ «Preview in WordPress Playground» اضافه می‌کند.

⚠️ **محدودیت سندباکس:** توکن GitHub این محیط مجوز `workflows` ندارد و پوشِ فایل به
`.github/workflows/` رد می‌شود (خطای «refusing to allow a GitHub App to create or
update workflow»). فایل آماده در `_devenv/github-workflow-pr-preview.yml` گذاشته شده؛
کارفرما باید خودش آن را (از UI گیت‌هاب) در مسیر
`.github/workflows/pr-playground-preview.yml` بسازد — دستورالعمل دقیق در پیام چت
۱۴۰۶/۰۶/۰۲ داده شد.

## نکته دربارهٔ پیش‌نمایش سندباکس

پیش‌نمایش `*.e2b.app` فقط تا وقتی سندباکسِ جلسه زنده است کار می‌کند؛ با خواب/مرگ
سندباکس، «Sandbox Not Found» طبیعی است. راه‌اندازی مجدد: طبق `HANDOFF-PROMPT.md §۷`.
لینک‌های Playground این سند **دائمی**‌اند و به سندباکس وابسته نیستند.
