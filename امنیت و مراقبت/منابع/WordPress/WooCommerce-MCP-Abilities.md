# MCP و Abilities API در ووکامرس — راهی برای سبک‌تر کردن پادو

**منابع رسمی:**
- https://developer.woocommerce.com/docs/features/mcp/
- بررسی مستقیم `_devenv/woocommerce-11.0.1.zip` (همان نسخه‌ای که روی سرور ماست)

**بازیابی:** ۱۴۰۵/۰۵/۳۱
**چرا این سند:** کارفرما پرسید «یه تحقیق کن ببین یه پروژه‌ای پیدا می‌کنی که یه مقدار
سبک‌تر بکنه». پاسخ از پروژهٔ بیرونی نیامد — **از داخل خودِ ووکامرسی آمد که از قبل داریم.**

---

## 🔴 یافتهٔ اصلی: این قابلیت همین حالا روی سرور ماست

`grep` روی زیپ ووکامرس ۱۱.۰.۱:

```
۵۷ فایل حاوی MCP
woocommerce/src/Internal/MCP/MCPAdapterProvider.php
woocommerce/src/Internal/MCP/Transport/WooCommerceRestTransport.php
woocommerce/vendor/wordpress/mcp-adapter/
woocommerce/src/Internal/Abilities/  ← ۸ قابلیت آماده
```

**نه نصب می‌خواهد، نه دانلود، نه هزینه.** فقط یک پرچم:

```php
add_filter( 'woocommerce_features', function( $f ) {
    $f['mcp_integration'] = true;
    return $f;
});
```

یا:

```bash
wp option update woocommerce_feature_mcp_integration_enabled yes
```

---

## هشت قابلیت آماده در `src/Internal/Abilities/Domain/`

| فایل | چه می‌کند |
|---|---|
| `ProductsQuery.php` | جستجو و فهرست محصولات |
| `ProductCreate.php` | ساخت محصول |
| `ProductUpdate.php` | ویرایش محصول |
| `ProductDelete.php` | حذف محصول |
| `OrdersQuery.php` | جستجوی سفارش‌ها |
| `OrderUpdateStatus.php` | تغییر وضعیت سفارش |
| `OrderAddNote.php` | افزودن یادداشت به سفارش |
| `AbstractDomainAbility.php` | کلاس پایه برای قابلیت‌های ما |

مستندات رسمی تأکید می‌کند این‌ها **purpose-built** برای جریان کاری عامل‌اند، نه صرفاً
انعکاس خام REST:

> «WooCommerce's preferred implementation path is purpose-built domain abilities. These
> abilities use schemas and response shapes designed for agent workflows instead of
> automatically projecting every REST-shaped operation into MCP.»

---

## چرا این «سبک‌تر می‌کند»

فاز ۱ سند پادو (کاتالوگ) و بخشی از فاز ۵ (سفارش) قرار بود ابزار دامنه‌ای بسازیم.
**نصفش از قبل نوشته شده.**

| کاری که قرار بود بکنیم | حالا |
|---|---|
| نوشتن ابزار CRUD محصول برای عامل | ✅ `ProductCreate/Update/Delete/Query` آماده |
| نوشتن ابزار سفارش | ✅ `OrdersQuery`, `OrderUpdateStatus`, `OrderAddNote` |
| تعریف قرارداد ابزار از صفر | ✅ `AbstractDomainAbility` + متادیتای استاندارد |
| اختراع پروتکل اتصال عامل | ✅ MCP، استاندارد باز |

**آنچه باید بنویسیم:** فقط قابلیت‌های **خاص خودمان** — اینستاگرام، قیف، VIP، صرافی،
پیک، LMS — با ارث‌بری از `AbstractDomainAbility`.

ثبت یک قابلیت تازه:

```php
'meta' => array(
    'show_in_rest' => true,
    'mcp'          => array( 'public' => true, 'type' => 'tool' ),
),
```

---

## ⚠️ چهار هشدار جدی

**۱. وضعیتش «Developer Preview» است.** خودِ مستندات رسمی می‌گوید:

> «The MCP implementation in WooCommerce is currently in developer preview.
> Implementation details, APIs, and integration patterns may change.»

یعنی ممکن است در نسخهٔ بعدی ووکامرس تغییر کند. **نباید هستهٔ محصول رویش بنا شود** بدون
لایهٔ واسط.

**۲. مسیر `/wp-json/woocommerce/mcp` عملاً منسوخ اعلام شده.** مستندات آن را
«deprecated WooCommerce MCP endpoint» می‌نامد و مسیر توصیه‌شده، آداپتور مشترک وردپرس است.
پس نباید مستقیم به آن مسیر چسبید.

**۳. 🔴 تعارض مستقیم با کنترل امنیتی ما.** احراز هویتش با کلید REST ووکامرس است:

```
X-MCP-API-Key: ck_your_consumer_key:cs_your_consumer_secret
```

این یک **مسیر احراز هویت موازی** است — دقیقاً همان چیزی که `CoreSurfaceGuard` برای بستنش
ساخته شد. اگر MCP روشن شود، `OrdersQuery` می‌تواند داده‌های مشتری را بیرون بدهد **بدون
عبور از گیت ما**.

> **الزام:** پیش از روشن‌کردن MCP، باید تصمیم گرفته شود که گیت `CoreSurfaceGuard` چطور بر
> مسیر MCP هم اعمال می‌شود. این **مانع پیاده‌سازی** است، نه یک نکتهٔ جانبی.

**۴. پروکسی محلی Node.js لازم دارد** (`@automattic/mcp-wordpress-remote`) برای
کلاینت‌های stdio. برای معماری ابری ما باید بررسی شود که آیا مسیر HTTP مستقیم کافی است.

---

## گزینه‌های اکوسیستم (بررسی‌شده، برای ثبت)

| گزینه | مجوز | نکته |
|---|---|---|
| **ووکامرس داخلی** | همراه افزونه | ✅ رایگان، از قبل نصب، ولی preview |
| `iOSDevSK/mcp-for-woocommerce` | افزونهٔ جامعه | فقط خواندنی، JWT دارد، بر پایهٔ MCP رسمی Automattic |
| `wppoland/woocommerce-mcp` | MIT | فقط خواندنی، ۵ ابزار، سرور Node جدا |
| W7S (بازار ووکامرس) | **۳۹ دلار تجاری** | خواندن+نوشتن، رابط آماده — ولی پولی و بستهٔ ما نیست |

**توصیه:** گزینهٔ داخلی، چون چیزی اضافه نمی‌کند و وابستگی تازه نمی‌آورد.

---

## عامل‌های متن‌باز (برای فاز ۰ سند پادو)

اگر روزی فاز ۰ («کلونِ کامل Claude Code») زنده شود، اکوسیستم بالغ شده و **ساختنش از صفر
بی‌معناست**:

| ابزار | ستاره | مجوز | نکته |
|---|---|---|---|
| **OpenCode** | ~۱۹۸K | **MIT** | نزدیک‌ترین جایگزین ترمینالی، ۷۵+ ارائه‌دهنده |
| **Goose** | ~۵۳K | Apache-2.0 | Rust، **MCP-first**، ۷۰+ افزونه |
| **Cline** | ~۶۶K | Apache-2.0 | داخل ویرایشگر، Plan/Act |
| **OpenHands** | ~۸۴K | MIT | خودمختار |

**نکته برای ما:** `Goose` با معماری MCP-first و مجوز Apache، از نظر مفهومی به آنچه
می‌خواهیم نزدیک‌تر است تا بازنویسی از صفر. ولی این فقط ثبت است — تصمیمش با کارفراست.

---

## جمع‌بندی

پاسخ به «چیزی هست که سبک‌تر کند؟» **بله** — ولی نه یک پروژهٔ بیرونی؛ **قابلیتی که از
قبل داریم و نمی‌دانستیم.**

بخش قابل‌توجهی از فاز ۱ و ۵ سند پادو را حذف می‌کند، **به شرط اینکه** تعارض امنیتی
بند ۳ بالا اول حل شود.
