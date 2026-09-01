# فاز ۷۰ — برگهٔ اجرای بستن گیت Railway

تاریخ شروع اجرای بستن گیت: 2026-09-01


## پیش‌نیاز GitHub/PR — انجام‌شده 2026-09-01

شاخهٔ `arena/01a057be-igbz-wp` با `main` هم‌ریشه شد و PR از حالت conflict خارج شد.

```text
PR = https://github.com/paymanshafayan/IGBZ-WP/pull/16
mergeable = MERGEABLE
mergeStateStatus = CLEAN
preview_run_pre_final_handoff = 33551049368
preview_job_pre_final_handoff = 100000280505
preview_conclusion = SUCCESS
branch_head_pre_final_handoff = c350271
```

قدم بعدی برای بستن فاز ۷۰: merge PR شمارهٔ ۱۶ به `main`، سپس شروع چت تازه از وضعیت به‌روز `main` و ساخت/به‌روزرسانی staging Railway از `main`.


## نقطهٔ تحویل به چت تازه

در پایان این نوبت، کار کدنویسی/مستندسازی روی شاخهٔ جلسه تمام است. کارفرما باید PR شمارهٔ ۱۶ را
در GitHub به `main` merge کند و سپس چت تازه را از وضعیت به‌روز `main` شروع کند.

در چت تازه، اولین کار این است:

```text
۱. خواندن HANDOFF-PROMPT.md و همین فایل.
۲. تأیید اینکه main شامل PR شمارهٔ ۱۶ است.
۳. اجرای staging Railway از main.
۴. پرکردن قالب شواهد پایین بدون ارسال هیچ راز، رمز، توکن یا آدرس دیتابیس.
```

## وضعیت گیت‌ها

| گیت | وضعیت |
|---|---|
| provider / Zernio live verification | بسته‌شده ✅ — run `33540909998` |
| آماده‌بودن کد Railway | آماده ✅ — `railway/Dockerfile` + `railway/entrypoint.sh` + `railway.json` |
| استقرار staging روی Railway | در انتظار اجرا با حساب کارفرما |
| دیتابیس MySQL 8.4 LTS | اگر دیتابیس قدیمی نداریم: از ابتدا 8.4 بسازید؛ اگر 8.0 موجود داریم: تمرین مهاجرت لازم است |
| سه health سبز پیاپی | در انتظار اجرای staging |
| smoke سفارش + API موبایل | در انتظار اجرای staging |

## تصمیم مسیر دیتابیس

برای این پروژه اگر هنوز دیتابیس تولیدی/قدیمی نداریم، مسیر رسمی فاز ۷۰ این است:

```text
ساخت staging از ابتدا با MySQL 8.4 LTS
```

در این حالت مهاجرت 8.0 → 8.4 انجام نمی‌شود، چون چیزی برای مهاجرت وجود ندارد؛ فقط نسخهٔ مقصد،
سلامت، سفارش تستی و سه health سبز ثبت می‌شود.

اگر دیتابیس قبلی واقعی روی 8.0 وجود دارد، مسیر امن این است:

```text
export از 8.0 → import در دیتابیس 8.4 جدا → اتصال staging به 8.4 → health/smoke → rollback با DATABASE_URL در صورت مشکل
```

## متغیرهای لازم Railway

مقادیر واقعی را در چت ننویسید.

```text
WORDPRESS_DB_HOST
WORDPRESS_DB_NAME
WORDPRESS_DB_USER
WORDPRESS_DB_PASSWORD
WP_ADMIN_USER
WP_ADMIN_PASSWORD
WP_ADMIN_EMAIL
IGBZ_DB_TLS=0
IGBZ_SERVER_CRON=1
```

اگر دیتابیس بیرونی/عمومی است، `IGBZ_DB_TLS=1` شود. برای دیتابیس داخلی Railway همان `0` کافی است.

## اجرای staging از داشبورد/CLI

۱. سرویس MySQL 8.4 LTS بسازید.

۲. سرویس وب را از GitHub Repo بسازید و مطمئن شوید Railway از این فایل می‌خواند:

```text
railway.json
```

۳. ولوم دائمی را روی مسیر زیر وصل کنید:

```text
/var/www/html/wp-content
```

۴. دامنه عمومی staging بسازید.

۵. بعد از deploy، لاگ باید این را نشان دهد:

```text
igbz: ready (health 200, attempt N)
```

## health اول

```bash
STAGING_URL='https://دامنه-staging'

curl -sS -o phase70-health-1.json -w 'HTTP=%{http_code}\n' "$STAGING_URL/?igbz_health=1"
cat phase70-health-1.json
```

قبولی:

```text
HTTP=200
ok=true
degraded=false
```

## نسخهٔ دیتابیس

```bash
railway run --service igbz-wp bash -lc \
  'wp db query "SELECT VERSION();" --path=/var/www/html --skip-column-names'
```

قبولی برای مسیر تازه:

```text
8.4.x
```

## API موبایل

```bash
curl -fsS "$STAGING_URL/wp-json/igbz/v1/contract"
```

قبولی مورد انتظار:

```text
namespace = igbz/v1
version = 1.1.0
```

## سفارش تستی

از فروشگاه staging یک سفارش نمونه بسازید و فقط این شواهد غیرحساس را ثبت کنید:

```text
order_id =
order_status =
created_at =
```

## سه health سبز پیاپی

```bash
STAGING_URL='https://دامنه-staging'

for n in 1 2 3; do
  echo "=== phase70 health $n ==="
  date -Is
  curl -sS -o "phase70-health-$n.json" -w "HTTP=%{http_code}\n" "$STAGING_URL/?igbz_health=1"
  cat "phase70-health-$n.json"
  echo
  if [ "$n" != "3" ]; then
    sleep 3600
  fi
done
```

قبولی:

```text
هر سه بار HTTP=200
هر سه بار ok=true
هر سه بار degraded=false
```

## قالب شواهدی که باید برای ثبت نهایی برگردد

رازها، رمزها، توکن‌ها و آدرس دیتابیس را نفرستید.

```text
Railway project:
Web service:
Database service:
Staging URL:
Deploy id یا لینک deploy:
DB version:
Health 1 time:
Health 1 HTTP:
Health 1 ok/degraded:
Health 2 time:
Health 2 HTTP:
Health 2 ok/degraded:
Health 3 time:
Health 3 HTTP:
Health 3 ok/degraded:
Mobile contract result/version:
Test order id:
Test order status:
Notes:
```

## معیار بسته‌شدن فاز ۷۰

فاز ۷۰ فقط وقتی بسته می‌شود که این سند با شواهد واقعی پر شود و در مخزن ثبت گردد:

```text
Railway staging deploy ✅
MySQL 8.4 LTS confirmed ✅
health endpoint 200/ok/degraded=false ✅ × 3
mobile contract smoke ✅
test order smoke ✅
```
