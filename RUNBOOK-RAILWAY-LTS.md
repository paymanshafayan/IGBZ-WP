# RUNBOOK — استقرار Railway و مهاجرت پایگاه‌داده به LTS (فاز ۷۰)

تاریخ: ۱۴۰۶/۰۶/۱۰ · مخاطب: کارفرما (اجرای دستی با توکن خودتان) · وضعیت سندباکس: فقط npm باز است؛ `railway.app` از سندباکس در دسترس نیست (آزموده: همهٔ دامنه‌ها `000`) — به همین دلیل اجرای ابری با توکنِ ایجنت ممکن نیست و **توکن هرگز در چت گذاشته نشود**.

## بخش الف — استقرار staging (۱۰ دقیقه، کپی-پیست)

پیش‌نیازها: مخزن روی گیت‌هاب (شاخهٔ `main` پس از merge)، توکن پروژهٔ ریل‌وی (`RAILWAY_TOKEN` در Secrets مخزن برای ولوم‌گارد؛ برای CLI از `railway login` استفاده کنید).

```bash
# ۱) ورود و اتصال پروژه (یک‌بار)
npm i -g @railway/cli
railway login
railway init --name igbz-wp          # یا link به پروژهٔ موجود: railway link

# ۲) سرویس دیتابیس MySQL 8.0 (فعلی) — افزونهٔ رسمی
railway add --database mysql         # DATABASE_URL خودکار ست می‌شود

# ۳) متغیرهای لازم سرویس وب (نمونه)
railway variables set \
  WP_ADMIN_PASSWORD='<قوی و یکتا>' \
  WP_ADMIN_EMAIL='admin@example.com' \
  IGBZ_DB_TLS=0                      # network خصوصی ریل‌وی رمزنگاری‌شده است؛ دیتابیس بیرونی → 1

# ۴) ولوم دائمی (مسیر باید دقیقاً همین باشد — نگهبان ولوم هم همان را می‌سازد)
railway volume add --mount-path /var/www/html/wp-content --service igbz-wp

# ۵) استقرار از مخزن
railway up                           # build از railway/Dockerfile (rsync افزونه از مخزن)

# ۶) دامنهٔ عمومی و گیت سلامت
railway domain                       # خروجی: <slug>.up.railway.app
curl -fsS https://<slug>.up.railway.app/?igbz_health=1 | jq .     # انتظار: HTTP 200 و "ok": true
```

- TLS عمومی: خودکار روی دامنهٔ ریل‌وی و دامنهٔ سفارشی (گواه لبه).
- TLS دیتابیس: شبکهٔ خصوصی ریل‌وی به‌صورت پیش‌فرض رمزنگاری‌شده است؛ برای دیتابیس خارجی `IGBZ_DB_TLS=1` (ثابت `MYSQL_CLIENT_FLAGS=MYSQLI_CLIENT_SSL` در wp-config نوشته می‌شود).
- healthcheck: `railway.json` → `deploy.healthcheckPath: /?igbz_health=1` (مهلت ۳۰۰ث برای نصب اولیه). اگر کنسول پیکربندی قدیمی بود، همان مسیر را دستی در Settings ← Healthcheck بگذارید.
- معنای پاسخ: `200 ok:true` = آمادهٔ ترافیک؛ `503` = دیتابیس/افزونه پایین (کانتینر نباید سبز باشد)؛ `200 degraded:true` = می‌فروشد اما هشدار دارد (ناهمخوانی اسکیما/dbv) — زنگ هشدار، نه گیت استقرار.
- خروجی راه‌انداز در لاگ: `igbz: ready (health 200, attempt N)`.

## بخش ب — مهاجرت آزموده MySQL 8.0 → 8.4 LTS

> **گیت تصمیم:** نسخهٔ مقصد «مصوب کارفرما» لازم دارد. پیشنهاد ایجنت: **MySQL 8.4 LTS**
> (تا آوریل ۲۰۳۲ پشتیبانی؛ WordPress/WooCommerce سازگارند). MariaDB 11.4 LTS جایگزین
> ممکن است اما سازگاری افزونه‌های بانکی ایرانی معمولاً با MySQL اثبات شده است.

### تله‌های شناخته‌شدهٔ 8.4 (پیش از تمرین بدانید)

1. `mysql_native_password` حذف شده — کاربر DB باید `caching_sha2_password` باشد (افزونهٔ ریل‌وی به‌طور پیش‌فرض همین است).
2. `utf8mb3` پیش‌فرض نیست (ما همه‌جا `utf8mb4` هستیم — اسکیمای v48 با dbDelta ساخته شده).
3. `GRANT ... IDENTIFIED BY` حذف شده — فقط در اسکریپت‌های قدیمی مشکل می‌سازد.

### گام‌های تمرین روی staging (هرگز اول روی تولید نه)

```bash
# ۰) نزدیکی staging به تولید: سرویس تکراری + دیتابیس تازه
railway duplicate --name igbz-wp-lts
railway add --database mysql-84        # یا MySQL template با تگ 8.4

# ۱) پشتیبان از دیتابیس فعلی (خروجی روی ولوم یا S3)
railway run --service igbz-wp bash -lc \
  'wp db export /tmp/prod.sql --path=/var/www/html && gzip /tmp/prod.sql'

# ۲) درون‌ریزی در 8.4 (سرویس LTS)
gunzip -c /tmp/prod.sql | mysql "$LTS_DATABASE_URL"     # یا wp db import در سرویس تکراری

# ۳) پذیرش — همه باید سبز باشند:
curl -fsS https://<lts-domain>/?igbz_health=1 | jq      # ok:true، degraded:false، igbz_tables 100/100، igbz_dbv 48
# سفارش نمونه + پرداخت آزمایشی + یک خروجی API موبایل (GET /igbz/v1/contract → 1.1.0)

# ۴) سوئیچ ترافیک فقط پس از سه سبز پیاپی در سه ساعت
railway variables set --service igbz-wp DATABASE_URL="$LTS_DATABASE_URL"
railway redeploy --service igbz-wp

# ۵) بازگشت (ROLLBACK): فقط متغیر را به دیتابیس قبلی برگردانید و redeploy کنید؛
#    دیتابیس 8.0 دست‌نخورده مانده (رویکرد دیتابیس موازی، بدون درجا-ارتقا).
```

### پذیرش کارفرما برای تولید

- [ ] تأیید نسخهٔ مقصد (پیشنهاد: MySQL 8.4 LTS)
- [ ] سه سبز پیاپی سلامت روی staging در ۳ ساعت
- [ ] یک خرید آزمایشی کامل + یک فراخوانی API موبایل روی staging
- [ ] تأیید پنجرهٔ زمانی و مالک اجرا

## آنچه در این فاز به کد/پیکربندی اضافه شد

| فایل | تغییر |
|---|---|
| `igbz-suite/src/Support/HealthEndpoint.php` | نقطهٔ سلامت/آمادگی محصول: `/?igbz_health=1` با 200/503 و پرچم `degraded` |
| `igbz-suite/src/Support/Plugin.php` | ثبت نقطهٔ سلامت |
| `igbz-suite/tests/HealthEndpointTest.php` | ۳ سناریو: سالم 200/بدون سیکرت، دیتابیس مرده 503، دریفت=degraded نه قرمز |
| `railway/entrypoint.sh` | `IGBZ_DB_TLS=1` → `MYSQLI_CLIENT_SSL`؛ حلقهٔ آمادگی `igbz: ready (health 200)` |
| `railway.json` | `healthcheckPath: /?igbz_health=1` + timeout 300 |
| `railway/mu-plugins/020-healthcheck.php` + `_devenv/setup.sh` | ناظر مستقل: اگر خود افزونه بالا نیامد پاسخ صادقانه 503 می‌دهد (نه سکوت) |
